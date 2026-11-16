import { ChangeEvent, FormEvent, ReactNode, createContext, useContext, useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';
import { api, AdminDashboard, AdminOperation, AdminRate, AdminUser, AppNotification, ApiError, Beneficiary, ExchangeRate, ExchangeRateHistoryPoint, FundingAccount, FundingMethod, PlatformSetting, Quote, Remittance, RemittanceDetail, User, isDevelopmentAuth, paymentMethods, isLoginChallenge, isEmailVerificationRequired, SecurityStatus, TwoFactorSetup } from './api';
import { getInitialLanguage, Language, languageLabels, translate } from './i18n';
import { Icon } from './icons';
import { ToastHost } from './ToastHost';
import { notify, notifyError, notifySuccess } from './toast';
import { getButtonMode, onButtonModeChange, setButtonMode, ButtonMode } from './buttonMode';
import { ThemeId, THEMES, THEME_EVENT, THEME_SETTING_KEY, applyTheme, fetchPlatformTheme, getInitialTheme, getStoredOverride, normalizeTheme, themeById, toggleMode } from './theme';

// -----------------------------------------------------------------------------
// Modo de botones (texto / iconos) y boton de accion reutilizable
// -----------------------------------------------------------------------------
const useButtonMode = (): [ButtonMode, (mode: ButtonMode) => void] => {
  const [mode, setMode] = useState<ButtonMode>(getButtonMode);
  useEffect(() => onButtonModeChange(() => setMode(getButtonMode())), []);
  return [mode, setButtonMode];
};

function ActionButton({ icon, label, className = 'secondary', disabled = false, title, onClick, type = 'button' }: { icon: string; label: string; className?: string; disabled?: boolean; title?: string; onClick?: () => void; type?: 'button' | 'submit' }): JSX.Element {
  const [mode] = useButtonMode();
  const accessibleLabel = title ?? label;
  return <button type={type} className={className} disabled={disabled} title={accessibleLabel} aria-label={accessibleLabel} onClick={onClick}>{mode === 'icons' ? <Icon name={icon} className="action-button-icon" /> : label}</button>;
}

// Traduce los errores tecnicos del backend sobre la subida de documentos KYC
// a mensajes que el cliente entiende.
function kycUploadErrorKey(message: string): string {
  const lower = (message ?? '').toLowerCase();
  if (lower.includes('exceeds the maximum allowed size') || lower.includes('exceeds 5 mb') || lower.includes('5 mb')) return 'kyc.fileTooLarge';
  if (lower.includes('does not match its declared file type')) return 'kyc.fileTypeMismatch';
  if (lower.includes('only pdf, jpg and png')) return 'kyc.fileTypeNotAllowed';
  if (lower.includes('flagged as malware')) return 'kyc.fileRejected';
  if (lower.includes('malware scanning service is unavailable') || lower.includes('malware scan could not be completed')) return 'kyc.scanUnavailable';
  if (lower.includes('valid document upload is required') || lower.includes('invalid document signature')) return 'kyc.uploadFailed';
  return '';
}

// Barra de filtro por columna (estilo GridView de kartik-v): una fila de
// entradas de filtro justo debajo de los encabezados de la tabla.
function ColumnFilterInput({ value, onChange, label }: { value: string; onChange: (value: string) => void; label: string }): JSX.Element {
  return <input type="search" className="table-filter-input" value={value} onChange={(event) => onChange(event.target.value)} placeholder="…" title={label} aria-label={label} />;
}

// Selector global del modo de presentacion de los botones (texto / iconos).
function ButtonModeToggle(): JSX.Element {
  const t = useT();
  const [mode, setMode] = useButtonMode();
  return <div className="button-mode-toggle">
    <label htmlFor="button-mode-select">{t('admin.buttonMode')}</label>
    <select id="button-mode-select" value={mode} onChange={(event) => setMode(event.target.value as ButtonMode)}>
      <option value="text">{t('admin.buttonModeText')}</option>
      <option value="icons">{t('admin.buttonModeIcons')}</option>
    </select>
    <p className="muted">{t('admin.buttonModeHelp')}</p>
  </div>;
}


const LanguageContext = createContext<Language>('es');
const useLanguage = () => useContext(LanguageContext);
const useT = () => { const language = useLanguage(); return (key: string) => translate(language, key); };
const localeFor = (language: Language) => language === 'ru' ? 'ru-RU' : 'es-ES';

type View = 'summary' | 'beneficiaries' | 'quote' | 'remittances' | 'profile' | 'security' | 'kyc' | 'admin' | 'funding' | 'notifications';
const storedUserId = isDevelopmentAuth ? localStorage.getItem('sederg_user') ?? '' : '';

export function App() {
  const [language, setLanguage] = useState<Language>(getInitialLanguage);
  const [theme, setTheme] = useState<ThemeId>(getInitialTheme);
  const changeLanguage = (next: Language) => {
    setLanguage(next);
    localStorage.setItem('sederg_language', next);
    document.documentElement.lang = next;
  };
  // El conmutador de la cabecera guarda una preferencia personal (override).
  const changeTheme = (next: ThemeId) => {
    setTheme(applyTheme(next, { persist: 'user' }));
  };
  useEffect(() => { document.documentElement.lang = language; }, [language]);
  // Sincroniza el estado con cualquier cambio de tema hecho fuera de React
  // (selector de administración, otra pestaña, etc.).
  useEffect(() => {
    const sync = () => setTheme((current) => {
      const applied = document.documentElement.dataset.theme;
      return (applied && applied !== current ? applied as ThemeId : current);
    });
    window.addEventListener(THEME_EVENT, sync);
    return () => window.removeEventListener(THEME_EVENT, sync);
  }, []);
  // Tema global publicado por administración: solo se aplica si el usuario no
  // ha elegido una preferencia personal.
  useEffect(() => {
    if (getStoredOverride()) {
      setTheme(applyTheme(getInitialTheme()));
      return;
    }
    fetchPlatformTheme().then((platformTheme) => {
      if (platformTheme) setTheme(applyTheme(platformTheme, { persist: 'platform' }));
      else setTheme(applyTheme(getInitialTheme()));
    });
  }, []);
  useEffect(() => { document.documentElement.dataset.theme = theme; }, [theme]);
  return <LanguageContext.Provider value={language}><AppContent language={language} onLanguageChange={changeLanguage} theme={theme} onThemeChange={changeTheme} /></LanguageContext.Provider>;
}

function AppContent({ language, onLanguageChange, theme, onThemeChange }: { language: Language; onLanguageChange: (language: Language) => void; theme: ThemeId; onThemeChange: (theme: ThemeId) => void }) {
  const t = useT();
  const [user, setUser] = useState<User | null>(null);
  const [authReady, setAuthReady] = useState(!storedUserId);
  const [publicView, setPublicView] = useState<'landing' | 'auth'>(() => {
    const params = new URLSearchParams(window.location.search);
    return params.has('verify_email') || params.has('token') || params.get('access') ? 'auth' : 'landing';
  });
  const [authView, setAuthView] = useState<'login' | 'register'>(() => new URLSearchParams(window.location.search).get('access') === 'register' ? 'register' : 'login');
  const [view, setView] = useState<View>('summary');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [unreadNotifications, setUnreadNotifications] = useState(0);
  const [menuOpen, setMenuOpen] = useState(false);

  const navigate = (next: View) => { setView(next); setMenuOpen(false); };

  useEffect(() => {
    if (!storedUserId) return;
    api.me(storedUserId).then(setUser).catch(() => localStorage.removeItem('sederg_user')).finally(() => setAuthReady(true));
  }, []);

  useEffect(() => {
    if (!user) { setUnreadNotifications(0); return; }
    let cancelled = false;
    const load = () => api.notificationsUnreadCount(user.public_id).then((r) => { if (!cancelled) setUnreadNotifications(r.unread_count || 0); }).catch(() => undefined);
    void load();
    const id = window.setInterval(load, 30000);
    return () => { cancelled = true; window.clearInterval(id); };
  }, [user?.public_id]);

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    setMessage('');
    try { await action(); } catch (error) { const message=(error as Error).message; const lower=message.toLowerCase(); const field=lower.includes('phone')||lower.includes('telefono') ? 'phone_e164' : lower.includes('card')||lower.includes('tarjeta') ? 'payout_card_number' : lower.includes('account')||lower.includes('cuenta') ? 'payout_account_number' : lower.includes('provider')||lower.includes('banco') ? 'payout_provider' : ''; if(field){window.dispatchEvent(new CustomEvent('sederg:beneficiary-field-error',{detail:{field,message}}));}else{setMessage(message);} notifyError(message); } finally { setBusy(false); }
  };

  const signOut = () => {
    localStorage.removeItem('sederg_user');
    localStorage.removeItem('sederg_access_token');
    setUser(null);
    setView('summary');
    setMenuOpen(false);
  };

  useEffect(() => {
    if (!menuOpen) return;
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setMenuOpen(false); };
    document.addEventListener('keydown', onKey);
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = previous; };
  }, [menuOpen]);

  if (!authReady) return <main className="shell narrow"><Header language={language} onLanguageChange={onLanguageChange} theme={theme} onThemeChange={onThemeChange} /><section className="card"><p className="muted">{t('app.restoringSession')}</p></section></main>;
  if (!user && publicView === 'landing') return <Landing language={language} onLanguageChange={onLanguageChange} theme={theme} onThemeChange={onThemeChange} onLogin={() => { setAuthView('login'); setPublicView('auth'); }} onRegister={() => { setAuthView('register'); setPublicView('auth'); }} />;
  if (!user) return <AuthScreen language={language} onLanguageChange={onLanguageChange} theme={theme} onThemeChange={onThemeChange} mode={authView} setMode={setAuthView} busy={busy} message={message} run={run} onBack={() => setPublicView('landing')} onAuthenticated={(next) => {
    localStorage.setItem('sederg_user', next.public_id);
    setUser(next);
  }} />;

  return <main className="shell">
    <ToastHost />
    <Header language={language} onLanguageChange={onLanguageChange} theme={theme} onThemeChange={onThemeChange} notification={{ userId: user.public_id, unreadCount: unreadNotifications, onUnreadChange: setUnreadNotifications, onViewAll: () => setView('notifications') }} menuControl={{ open: menuOpen, toggle: () => setMenuOpen((v) => !v) }} />
    <RateTicker />
    <section className="hero">
      <div><div className="eyebrow">{t('app.operationsPanel')}</div><h1>{t('app.hello')}, {user.first_name}.</h1><p>{t('app.welcomeDescription')}</p></div>
      <div className="hero-mark" aria-hidden="true">↗</div>
    </section>
    <div className="workspace">
      <div className={`menu-overlay ${menuOpen ? 'is-open' : ''}`} onClick={() => setMenuOpen(false)} aria-hidden="true" />
      <nav id="app-menu" className={`menu ${menuOpen ? 'is-open' : ''}`} aria-label={t("nav.platform")}>
        <div className="menu-head"><strong>{t('nav.platform')}</strong><button type="button" className="menu-close" aria-label={t('nav.closeMenu')} onClick={() => setMenuOpen(false)}>×</button></div>
        <div className="menu-preferences menu-preferences-mobile-only" aria-label={t('nav.preferences')}>
          <ThemeToggle theme={theme} onChange={onThemeChange} />
          <LanguageSwitch language={language} onChange={onLanguageChange} />
        </div>
        <NavButton icon="home" active={view === 'summary'} onClick={() => navigate('summary')}>{translate(language, 'nav.summary')}</NavButton>
        <NavButton icon="users" active={view === 'beneficiaries'} onClick={() => navigate('beneficiaries')}>{translate(language, 'nav.beneficiaries')}</NavButton>
        <NavButton icon="send" active={view === 'quote'} onClick={() => navigate('quote')}>{translate(language, 'nav.quote')}</NavButton>
        {user.role !== 'admin' && <NavButton icon="history" active={view === 'remittances'} onClick={() => navigate('remittances')}>{translate(language, 'nav.remittances')}</NavButton>}<NavButton icon="user" active={view === 'profile'} onClick={() => navigate('profile')}>{t('nav.profile')}</NavButton><NavButton icon="lock" active={view === 'security'} onClick={() => navigate('security')}>{t('nav.security')}</NavButton>{user.role !== 'admin' && <NavButton icon="shield" active={view === 'kyc'} onClick={() => navigate('kyc')}>{t('nav.kyc')}</NavButton>}
        <NavButton icon="bell" active={view === 'notifications'} onClick={() => navigate('notifications')}>{translate(language, 'nav.notifications')}{unreadNotifications > 0 ? <span className="nav-badge">{unreadNotifications > 99 ? '99+' : unreadNotifications}</span> : null}</NavButton>
        {user.role === 'admin' && <><NavButton icon="settings" active={view === 'admin'} onClick={() => navigate('admin')}>{translate(language, 'nav.admin')}</NavButton><NavButton icon="settings" active={view === 'funding'} onClick={() => navigate('funding')}>Métodos de pago</NavButton></>}
        <button className="secondary menu-exit" onClick={signOut}>{translate(language, 'nav.logout')}</button>
      </nav>
      <section className="content">
        <div className="toolbar"><span className="toolbar-user">{user.email} · <span className="role">{user.role === 'admin' ? t('role.admin') : t('role.customer')}</span></span><span className="sandbox"><i /> {t('common.sandbox')}</span></div>
        {view === 'summary' && <Summary userId={user.public_id} setView={setView} run={run} />}
        {view === 'beneficiaries' && <Beneficiaries userId={user.public_id} run={run} />}
        {view === 'quote' && <QuotePanel userId={user.public_id} run={run} />}
        {view === 'remittances' && <Remittances userId={user.public_id} run={run} />}{view === 'profile' && <ProfilePanel userId={user.public_id} run={run} />}{view === 'security' && <SecurityPanel userId={user.public_id} run={run} />}{view === 'kyc' && <KycPanel userId={user.public_id} run={run} />}
        {view === 'notifications' && <NotificationsPanel userId={user.public_id} run={run} onUnreadChange={setUnreadNotifications} />}
        {view === 'admin' && user.role === 'admin' && <AdminOperations userId={user.public_id} run={run} />}{view === 'funding' && user.role === 'admin' && <AdminFundingPanel userId={user.public_id} run={run} />}
        {message && <Alert text={message} />}
      </section>
    </div>
    <footer>{t('app.footerSandbox')}</footer>
  </main>;
}


function RateChart({ points, base, quote }: { points: ExchangeRateHistoryPoint[]; base: string; quote: string }) {
  const t = useT();
  const svgRef = useRef<SVGSVGElement | null>(null);
  const [hover, setHover] = useState<number | null>(null);
  const data = points
    .map((p) => ({ ...p, value: Number(p.rate), time: new Date(p.effective_date).getTime() }))
    .filter((p) => Number.isFinite(p.value) && Number.isFinite(p.time))
    .sort((a, b) => a.time - b.time);

  if (data.length < 2) {
    return <div className="rate-chart-empty muted">{t('chart.notEnoughData')}</div>;
  }

  const width = 640;
  const height = 220;
  const pad = { l: 52, r: 16, t: 16, b: 28 };
  const innerW = width - pad.l - pad.r;
  const innerH = height - pad.t - pad.b;
  const values = data.map((d) => d.value);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || Math.max(Math.abs(max) * 0.001, 0.000001);
  const yMin = min - span * 0.08;
  const yMax = max + span * 0.08;
  const xMin = data[0].time;
  const xMax = data[data.length - 1].time || xMin + 1;
  const xSpan = xMax - xMin || 1;
  const x = (time: number) => pad.l + ((time - xMin) / xSpan) * innerW;
  const y = (v: number) => pad.t + (1 - (v - yMin) / (yMax - yMin || 1)) * innerH;
  const coords = data.map((d) => ({ ...d, x: x(d.time), y: y(d.value) }));
  const linePath = coords.map((c, i) => `${i === 0 ? 'M' : 'L'} ${c.x.toFixed(2)} ${c.y.toFixed(2)}`).join(' ');
  const areaPath = `${linePath} L ${coords[coords.length - 1].x.toFixed(2)} ${(pad.t + innerH).toFixed(2)} L ${coords[0].x.toFixed(2)} ${(pad.t + innerH).toFixed(2)} Z`;
  const up = coords[coords.length - 1].value >= coords[0].value;
  const stroke = up ? '#16a34a' : '#ef4444';
  const fillId = `rate-fill-${base}-${quote}`.replace(/[^a-zA-Z0-9_-]/g, '');
  const yTicks = 4;
  const xTicks = Math.min(4, data.length - 1);
  const formatY = (v: number) => v.toLocaleString(undefined, { maximumFractionDigits: v >= 100 ? 2 : v >= 1 ? 4 : 6 });
  const formatX = (tms: number) => new Date(tms).toLocaleDateString(undefined, { month: 'short', day: '2-digit' });

  const onMove = (e: React.MouseEvent<SVGSVGElement>) => {
    const svg = svgRef.current;
    if (!svg) return;
    const rect = svg.getBoundingClientRect();
    const relX = ((e.clientX - rect.left) / rect.width) * width;
    let best = 0;
    let bestD = Infinity;
    for (let i = 0; i < coords.length; i++) {
      const d = Math.abs(coords[i].x - relX);
      if (d < bestD) { bestD = d; best = i; }
    }
    setHover(best);
  };

  const hi = hover !== null ? coords[hover] : null;
  const change = ((coords[coords.length - 1].value - coords[0].value) / coords[0].value) * 100;

  return <div className="rate-chart">
    <div className="rate-chart-header">
      <span className="rate-chart-pair">{base}/{quote}</span>
      <span className={`rate-chart-change ${up ? 'up' : 'down'}`}>{up ? '▲' : '▼'} {change >= 0 ? '+' : ''}{change.toFixed(2)}%</span>
      {hi && <span className="rate-chart-hover">{formatY(hi.value)} · {new Date(hi.time).toLocaleDateString()}</span>}
    </div>
    <svg ref={svgRef} className="rate-chart-svg" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
      <defs>
        <linearGradient id={fillId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={stroke} stopOpacity="0.28" />
          <stop offset="100%" stopColor={stroke} stopOpacity="0.02" />
        </linearGradient>
      </defs>
      {Array.from({ length: yTicks + 1 }, (_, i) => {
        const v = yMin + (i / yTicks) * (yMax - yMin);
        const yy = y(v);
        return <g key={i}>
          <line x1={pad.l} x2={width - pad.r} y1={yy} y2={yy} className="rate-chart-grid" />
          <text x={pad.l - 6} y={yy + 4} textAnchor="end" className="rate-chart-axis">{formatY(v)}</text>
        </g>;
      })}
      {Array.from({ length: xTicks + 1 }, (_, i) => {
        const tms = xMin + (i / xTicks) * xSpan;
        const xx = x(tms);
        return <text key={i} x={xx} y={height - 8} textAnchor="middle" className="rate-chart-axis">{formatX(tms)}</text>;
      })}
      <path d={areaPath} fill={`url(#${fillId})`} />
      <path d={linePath} fill="none" stroke={stroke} strokeWidth="2.2" strokeLinejoin="round" strokeLinecap="round" />
      {coords.map((c, i) => <circle key={i} cx={c.x} cy={c.y} r={hover === i ? 4 : 0} fill={stroke} />)}
      {hi && <>
        <line x1={hi.x} x2={hi.x} y1={pad.t} y2={pad.t + innerH} className="rate-chart-cross" />
        <line x1={pad.l} x2={width - pad.r} y1={hi.y} y2={hi.y} className="rate-chart-cross" />
        <circle cx={hi.x} cy={hi.y} r={4.5} fill={stroke} stroke="#fff" strokeWidth="1.5" />
      </>}
    </svg>
  </div>;
}

function LandingRateCard({ language }: { language: Language }) {
  const text = (key: string) => translate(language, key);
  const locale = localeFor(language);
  const cacheKey = 'sederg_rate_both_last_valid';
  const [rates, setRates] = useState<{ rubToCup: ExchangeRate; cupToRub: ExchangeRate } | null>(null);
  const [history, setHistory] = useState<{ rubToCup: ExchangeRateHistoryPoint[]; cupToRub: ExchangeRateHistoryPoint[] }>({ rubToCup: [], cupToRub: [] });
  const [historyPair, setHistoryPair] = useState<'RUB_CUP' | 'CUP_RUB'>('RUB_CUP');
  const [staleReason, setStaleReason] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const readCache = (): { rubToCup: ExchangeRate; cupToRub: ExchangeRate } | null => {
    try {
      const raw = localStorage.getItem(cacheKey);
      if (!raw) return null;
      const parsed = JSON.parse(raw) as { rubToCup?: ExchangeRate; cupToRub?: ExchangeRate };
      return parsed.rubToCup && parsed.cupToRub ? { rubToCup: parsed.rubToCup, cupToRub: parsed.cupToRub } : null;
    } catch {
      return null;
    }
  };
  const writeCache = (value: { rubToCup: ExchangeRate; cupToRub: ExchangeRate }) => {
    try { localStorage.setItem(cacheKey, JSON.stringify(value)); } catch { /* ignore */ }
  };

  const load = async (silent = false) => {
    if (!silent) setLoading(true);
    setError('');
    try {
      const [rubToCup, cupToRub] = await api.bothExchangeRates();
      const next = { rubToCup, cupToRub };
      setRates(next);
      const anyStale = rubToCup.stale || cupToRub.stale;
      const reason = rubToCup.stale_reason || cupToRub.stale_reason || (anyStale ? translate(language, 'landing.rateStaleHint') : null);
      setStaleReason(reason);
      if (!anyStale) writeCache(next);
    } catch (reason) {
      const cached = readCache();
      if (cached) {
        setRates(cached);
        setStaleReason((reason as Error)?.message || translate(language, 'landing.rateStaleHint'));
      } else {
        setError((reason as Error)?.message || translate(language, 'landing.rateUnavailable'));
      }
    } finally {
      setLoading(false);
    }
  };

  const loadHistory = async () => {
    try {
      const [rubCup, cupRub] = await Promise.all([
        api.exchangeRateHistory('RUB', 'CUP', 60),
        api.exchangeRateHistory('CUP', 'RUB', 60),
      ]);
      setHistory({ rubToCup: rubCup.points || [], cupToRub: cupRub.points || [] });
    } catch {
      // keep empty history on failure
    }
  };

  useEffect(() => {
    void load(false);
    void loadHistory();
    const id = window.setInterval(() => { void load(true); void loadHistory(); }, 60000);
    const onUpdate = () => { try { localStorage.removeItem(cacheKey); } catch {} void load(true); void loadHistory(); };
    window.addEventListener('sederg:rates-updated', onUpdate);
    return () => { window.clearInterval(id); window.removeEventListener('sederg:rates-updated', onUpdate); };
  }, []);

  if (loading && !rates) return <section className="landing-rate-card-wrap" aria-live="polite"><div className="exchange-rate-card exchange-rate-card-loading"><span className="exchange-rate-capsule">{text('landing.rate')}</span><p className="muted">{text('landing.rateLoading')}</p></div></section>;
  if (!rates) return <section className="landing-rate-card-wrap" aria-live="polite"><div className="exchange-rate-card exchange-rate-card-error"><span className="exchange-rate-capsule">{text('landing.rate')}</span><p className="alert">{error || text('landing.rateUnavailable')}</p></div></section>;

  const { rubToCup, cupToRub } = rates;
  const isStale = !!staleReason || !!rubToCup.stale || !!cupToRub.stale;
  const primary = rubToCup;
  const sourceType = (primary.source_type === 'administrative' || primary.manual_override) ? 'administrative' : 'official';
  const sourceTypeLabel = sourceType === 'administrative' ? text('landing.rateAdministrative') : text('landing.rateOfficial');
  const provenance = primary.provenance || primary.source_label || '—';
  const updatedAt = primary.updated_at || primary.valid_from;
  const updatedText = updatedAt ? new Date(updatedAt).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : primary.effective_date;
  const statusText = isStale ? text('landing.rateCached') : text('landing.rateLive');
  const flagBase = `${import.meta.env.BASE_URL}assets/sederg-pay/flags`;

  const renderPair = (pair: ExchangeRate, fromFlag: string, toFlag: string, fromAlt: string, toAlt: string) => {
    const variation = pair.variation_direction ?? 'unknown';
    const hasVariation = variation === 'up' || variation === 'down';
    const variationAbs = pair.variation_absolute ? Number(pair.variation_absolute) : null;
    const variationPct = pair.variation_percent ? Number(pair.variation_percent) : null;
    const variationText = hasVariation && variationAbs !== null
      ? `${variation === 'up' ? '+' : ''}${variationAbs.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 6 })}${variationPct !== null ? ` (${variation === 'up' ? '+' : ''}${variationPct.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%)` : ''}`
      : text('landing.rateNoVariation');
    return (
      <div className="exchange-rate-pair" aria-label={`${pair.base_currency} a ${pair.quote_currency}`}>
        <div className="exchange-rate-main">
          <div className="exchange-rate-leg"><img className="exchange-rate-flag" src={`${flagBase}/${fromFlag}`} alt={fromAlt} /><div><strong>1 {pair.base_currency}</strong></div></div>
          <div className="exchange-rate-symbol" aria-hidden="true">⇒</div>
          <div className="exchange-rate-leg"><div><strong className="exchange-rate-value">{Number(pair.rate).toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 6 })} {pair.quote_currency}</strong></div><img className="exchange-rate-flag" src={`${flagBase}/${toFlag}`} alt={toAlt} /></div>
        </div>
        <div className={`exchange-rate-variation ${variation}`}><span className="exchange-rate-arrow" aria-hidden="true">{variation === 'up' ? '↑' : variation === 'down' ? '↓' : '•'}</span><span>{variationText}</span></div>
        <div className="exchange-rate-pair-meta"><span>{text('landing.rateUpdatedAt')}: {pair.effective_date}</span><span className="exchange-rate-sep">·</span><span>{pair.provenance || pair.source_label || '—'}</span></div>
      </div>
    );
  };

  const chartPoints = historyPair === 'RUB_CUP' ? history.rubToCup : history.cupToRub;
  const chartBase = historyPair === 'RUB_CUP' ? 'RUB' : 'CUP';
  const chartQuote = historyPair === 'RUB_CUP' ? 'CUP' : 'RUB';

  return <section className="landing-rate-card-wrap" aria-live="polite">
    <article className={`exchange-rate-card${isStale ? ' is-stale' : ''}`}>
      <header className="exchange-rate-header"><span className="exchange-rate-capsule">{text('landing.rate')}</span><span className={`exchange-rate-source-badge ${sourceType}`}>{sourceTypeLabel}</span></header>
      <p className="exchange-rate-subtitle"><span>{text('landing.rateUpdatedAt')}: {primary.effective_date}</span><span className="exchange-rate-sep">·</span><span>{provenance}</span></p>
      {isStale && <p className="exchange-rate-stale" role="status"><strong>{text('landing.rateStale')}:</strong> {staleReason || text('landing.rateStaleHint')}</p>}
      <div className="exchange-rate-pairs">
        {renderPair(rubToCup, 'russia.svg', 'cuba.svg', 'Rusia', 'Cuba')}
        {renderPair(cupToRub, 'cuba.svg', 'russia.svg', 'Cuba', 'Rusia')}
      </div>
      <div className="rate-chart-section">
        <div className="rate-chart-section-head">
          <h3>{text('chart.title')}</h3>
          <div className="rate-chart-tabs" role="tablist" aria-label={text('chart.title')}>
            <button type="button" role="tab" aria-selected={historyPair === 'RUB_CUP'} className={historyPair === 'RUB_CUP' ? 'rate-chart-tab active' : 'rate-chart-tab'} onClick={() => setHistoryPair('RUB_CUP')}>RUB → CUP</button>
            <button type="button" role="tab" aria-selected={historyPair === 'CUP_RUB'} className={historyPair === 'CUP_RUB' ? 'rate-chart-tab active' : 'rate-chart-tab'} onClick={() => setHistoryPair('CUP_RUB')}>CUP → RUB</button>
          </div>
        </div>
        <RateChart points={chartPoints} base={chartBase} quote={chartQuote} />
      </div>
      <footer className="exchange-rate-meta">
        <span><strong>{text('landing.rateUpdatedAt')}:</strong> {updatedText}</span>
        <span><strong>{text('landing.rateSource')}:</strong> {provenance}</span>
        <span className={`exchange-rate-status ${isStale ? 'cached' : 'live'}`}><i /> {text('landing.rateConnection')}: {statusText}</span>
      </footer>
    </article>
  </section>;
}
function Landing({ language, onLanguageChange, onLogin, onRegister, theme, onThemeChange }: { language: Language; onLanguageChange: (language: Language) => void; onLogin: () => void; onRegister: () => void; theme: ThemeId; onThemeChange: (theme: ThemeId) => void }) {
  const text = (key: string) => translate(language, key);
  const [rubToCup, setRubToCup] = useState<ExchangeRate | null>(null);
  const [cupToRub, setCupToRub] = useState<ExchangeRate | null>(null);
  useEffect(() => {
    const loadRates = () => api.bothExchangeRates().then(([r2c, c2r]) => { setRubToCup(r2c); setCupToRub(c2r); }).catch(() => undefined);
    loadRates();
    window.addEventListener('sederg:rates-updated', loadRates);
    return () => window.removeEventListener('sederg:rates-updated', loadRates);
  }, []);
  const [amount, setAmount] = useState('10000');
  const [from, setFrom] = useState<'RUB' | 'CUP'>('RUB');
  const [to, setTo] = useState<'RUB' | 'CUP'>('CUP');
  const currentRate = rubToCup ? Number(rubToCup.rate).toFixed(5) : '—';
  const receiveExample = rubToCup ? (10000 * Number(rubToCup.rate)).toLocaleString(localeFor(language), { maximumFractionDigits: 2 }) : '—';
  const rateValue = from === to ? null : (from === 'RUB' && to === 'CUP' ? rubToCup : from === 'CUP' && to === 'RUB' ? cupToRub : null);
  const rate = rateValue ? Number(rateValue.rate) : null;
  const amountNumber = Number(amount);
  const canConvert = rate !== null && Number.isFinite(amountNumber) && amountNumber > 0;
  const receivedAmount = canConvert && rate !== null ? amountNumber * rate : null;
  const receivedText = receivedAmount !== null ? receivedAmount.toLocaleString(localeFor(language), { maximumFractionDigits: 2 }) : '—';
  const rateText = rate !== null ? `${Number(rate).toFixed(5)} ${to} por ${from}` : '—';
  const swapCurrencies = () => { setFrom(to); setTo(from); };
  return <main className="landing">
    <header className="landing-header">
      <Header language={language} onLanguageChange={onLanguageChange} theme={theme} onThemeChange={onThemeChange} />
      <nav className="landing-actions" aria-label="Acceso">
        <button className="landing-login" onClick={onLogin}>{text('common.login')}</button>
        <button onClick={onRegister}>{text('common.register')} <span aria-hidden="true">→</span></button>
      </nav>
    </header>
    <RateTicker />
    <section className="landing-hero">
      <div className="landing-copy">
        <div className="eyebrow">{text('landing.eyebrow')}</div>
        <h1>{text('landing.title')} <em>{text('landing.titleAccent')}</em></h1>
        <p>{text('landing.description')}</p>
        <div className="landing-cta"><button onClick={onRegister}>{text('landing.start')} <span aria-hidden="true">↗</span></button><button className="secondary" onClick={onLogin}>{text('landing.existing')}</button></div>
        <div className="landing-proof"><span className="proof-icon">✓</span><span>{text('landing.proofOne')}</span><span className="proof-icon">✓</span><span>{text('landing.proofTwo')}</span></div>
      </div>
      <div className="landing-visual" aria-label={text("landing.quote") }>
        <div className="visual-orbit orbit-one" /><div className="visual-orbit orbit-two" />
        <div className="transfer-card">
          <div className="transfer-head"><span>{text('landing.quote')}</span><span className="live-dot">{text('landing.live')}</span></div>
          <div className="transfer-route"><strong>RUB</strong><span>→</span><strong className="route-cup">CUP</strong></div>
          <div className="transfer-amount">10,000 <small>RUB</small></div>
          <div className="transfer-divider" />
          <div className="transfer-detail"><span>{text('landing.receive')}</span><strong>{receiveExample} CUP</strong></div>
          <div className="transfer-detail"><span>{text('landing.currentRate')}</span><strong>{currentRate}</strong></div>
          <button onClick={onRegister}>{text('landing.quoteAction')} <span aria-hidden="true">→</span></button>
        </div>
      </div>
    </section>
    <LandingRateCard language={language} />
    <section className="landing-calculator">
      <div className="section-intro"><span className="eyebrow">{text('landing.calculatorEyebrow')}</span><h2>{text('landing.calculatorTitle')}<br /><em>{text('landing.calculatorAccent')}</em></h2><p>{text('landing.calculatorDescription')}</p></div>
      <div className="calculator">
        <div className="calculator-top">
          <label className="calculator-field"><span>{text('landing.calcAmount')}</span><input className="calculator-amount" inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value.replace(/[^0-9.,]/g, ''))} placeholder="10000" /></label>
          <label className="calculator-field"><span>{text('landing.calcFrom')}</span><select className="calculator-select" value={from} onChange={(event) => setFrom(event.target.value as 'RUB' | 'CUP')}><option value="RUB">RUB</option><option value="CUP">CUP</option></select></label>
          <button className="calculator-swap" type="button" onClick={swapCurrencies} aria-label={text('landing.calcSwap')}><svg className="calculator-swap-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M17 8l4 4-4 4" /><path d="M21 12H3" /></svg></button>
          <label className="calculator-field"><span>{text('landing.calcTo')}</span><select className="calculator-select" value={to} onChange={(event) => setTo(event.target.value as 'RUB' | 'CUP')}><option value="RUB">RUB</option><option value="CUP">CUP</option></select></label>
        </div>
        <div className="calculator-result">
          <div><span>{text('landing.calcYouReceive')}</span><strong className="calculator-result-value">{canConvert ? `${receivedText} ${to}` : '—'}</strong><small>{canConvert && rateValue ? `${rateText} · ${rateValue.source_label}` : text('landing.calcInvalid')}</small></div>
        </div>
      </div>
    </section>
    <section className="landing-section">
      <div className="section-intro"><span className="eyebrow">{text('landing.featuresEyebrow')}</span><h2>{text('landing.featuresTitle')}<br /><em>{text('landing.featuresAccent')}</em></h2><p>{text('landing.featuresDescription')}</p></div>
      <div className="feature-grid"><Feature icon="transfer" title={text('landing.featureClear')} text={text('landing.featureClearText')} /><Feature icon="shield" title={text('landing.featureSecure')} text={text('landing.featureSecureText')} /><Feature icon="history" title={text('landing.featureTrack')} text={text('landing.featureTrackText')} /></div>
    </section>
    <section className="landing-steps"><div className="section-intro"><span className="eyebrow">{text('landing.stepsEyebrow')}</span><h2>{text('landing.stepsTitle')}<br /><em>{text('landing.stepsAccent')}</em></h2></div><div className="steps"><Step number="01" title={text('landing.stepOne')} text={text('landing.stepOneText')} /><Step number="02" title={text('landing.stepTwo')} text={text('landing.stepTwoText')} /><Step number="03" title={text('landing.stepThree')} text={text('landing.stepThreeText')} /></div></section>
    <footer className="landing-footer"><Brand language={language} /><span>{text('brand.slogan')}</span><span>© 2026 SEDERG_PAY</span></footer>
  </main>;
}

function Feature({ icon, title, text }: { icon: string; title: string; text: string }) { return <article className="feature"><span className="feature-icon"><Icon name={icon} /></span><h3>{title}</h3><p>{text}</p></article>; }
function Step({ number, title, text }: { number: string; title: string; text: string }) { return <article className="step"><span>{number}</span><div><h3>{title}</h3><p>{text}</p></div></article>; }

function AuthScreen({ language, onLanguageChange, theme, onThemeChange, mode, setMode, busy, message, run, onBack, onAuthenticated }: {
  language: Language; onLanguageChange: (language: Language) => void; theme: ThemeId; onThemeChange: (theme: ThemeId) => void;
  mode: 'login' | 'register'; setMode: (mode: 'login' | 'register') => void; busy: boolean;
  message: string; run: (action: () => Promise<void>) => Promise<void>; onBack: () => void; onAuthenticated: (user: User) => void;
}) {
  const text = (key: string) => translate(language, key);
  const [verificationEmail, setVerificationEmail] = useState('');
  const [notification, setNotification] = useState('');
  const [activationState, setActivationState] = useState<'idle' | 'activating' | 'success' | 'error'>('idle');
  const [authMode, setAuthMode] = useState<'login' | 'register' | 'forgot' | 'reset'>(mode);
  const [twoFactor, setTwoFactor] = useState<{ challenge: string } | null>(null);
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [resetEmail, setResetEmail] = useState('');
  const [resetToken, setResetToken] = useState('');
  const [localBusy, setLocalBusy] = useState(false);
  const [localMessage, setLocalMessage] = useState('');
  // Ventana de verificacion: se abre cuando el login detecta una cuenta
  // registrada cuyo correo todavia no ha sido verificado.
  const [verifyPrompt, setVerifyPrompt] = useState<{ email: string } | null>(null);
  const [verifyEmail, setVerifyEmail] = useState('');
  const [verifyNotice, setVerifyNotice] = useState('');
  const [verifyBusy, setVerifyBusy] = useState(false);

  useEffect(() => { if (!twoFactor && (authMode === 'login' || authMode === 'register')) setAuthMode(mode); }, [mode]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const verifyEmail = params.get('verify_email');
    const token = params.get('token');
    if (params.get('reset_password') === '1' && token) {
      setAuthMode('reset');
      setResetEmail(params.get('email') || '');
      setResetToken(token);
      return;
    }
    if (!verifyEmail || !token) return;
    setVerificationEmail(verifyEmail);
    setActivationState('activating');
    void run(async () => {
      try {
        await api.verifyEmail(verifyEmail, token);
        setActivationState('success');
        setNotification(text('auth.activationSuccess'));
        setVerificationEmail('');
        window.history.replaceState({}, '', '/?access=login');
        setMode('login'); setAuthMode('login');
      } catch (error) {
        setActivationState('error');
        throw error;
      }
    });
  }, []);

  const showMessage = localMessage || message;
  const effectiveBusy = busy || localBusy;

  const doLocal = async (fn: () => Promise<void>) => {
    setLocalBusy(true); setLocalMessage('');
    try { await fn(); }
    catch (e) { setLocalMessage((e as Error).message); }
    finally { setLocalBusy(false); }
  };

  return <main className="shell narrow"><ToastHost /><Header language={language} onLanguageChange={onLanguageChange} theme={theme} onThemeChange={onThemeChange} /><RateTicker /><section className="auth-card card">
    <button className="back-link" type="button" onClick={onBack}>{text('common.backHome')}</button>
    <div className="eyebrow">{text('auth.secure')}</div>
    <h1>{authMode === 'forgot' ? text('auth.forgotPasswordTitle') : authMode === 'reset' ? text('auth.resetPasswordTitle') : authMode === 'register' ? text('auth.create') : text('auth.welcome')}</h1>
    <p className="muted">{authMode === 'forgot' ? text('auth.forgotPasswordHint') : authMode === 'reset' ? text('auth.resetPasswordHint') : isDevelopmentAuth ? text('auth.description') : text('auth.oidcDescription')}</p>
    {(authMode === 'login' || authMode === 'register') && !twoFactor && <div className="tabs"><button className={authMode === 'login' ? 'tab active' : 'tab'} onClick={() => { setAuthMode('login'); setMode('login'); setLocalMessage(''); }}>{text('common.login')}</button><button className={authMode === 'register' ? 'tab active' : 'tab'} onClick={() => { setAuthMode('register'); setMode('register'); setLocalMessage(''); }}>{text('common.register')}</button></div>}
    {!isDevelopmentAuth && authMode !== 'reset' && <p className="alert">{text('auth.oidcSetup')}</p>}
    {activationState === 'activating' && <div className="activation-panel"><span className="activation-spinner" /><strong>{text('auth.activating')}</strong><p>{text('auth.verifyingLink')}</p></div>}
    {activationState === 'error' && <div className="notice-box notice-error" role="alert"><Icon name="alert-circle"/><strong>{text('auth.invalidLink')}</strong></div>}
    {verificationEmail && activationState === 'idle' && <div className="verification-panel"><div className="notice-box notice-info" role="status">{notification || <>{text('auth.verificationSent')} <strong>{verificationEmail}</strong>. {text('auth.checkInbox')}</>}</div><p className="muted">{text('auth.openActivationLink')}</p><button type="button" className="secondary" disabled={effectiveBusy} onClick={() => void run(async () => { await api.resendVerification(verificationEmail); setNotification(text('auth.resent')); })}>{text('auth.resendLink')}</button></div>}
    {notification && activationState === 'success' && <div className="notice-box notice-success" role="status"><Icon name="check"/><strong>{notification || text('auth.activationSuccess')}</strong></div>}

    {isDevelopmentAuth && twoFactor && <form onSubmit={(e) => {
      e.preventDefault();
      void doLocal(async () => {
        const user = await api.login2FA(twoFactor.challenge, twoFactorCode);
        setTwoFactor(null); setTwoFactorCode('');
        onAuthenticated(user);
      });
    }}><div className="notice-box notice-info" role="status"><Icon name="shield" /><strong>{text('auth.twoFactorTitle')}</strong></div><p className="muted">{text('auth.twoFactorHint')}</p><Field name="code" label={text('auth.twoFactorCode')} value={twoFactorCode} onChange={(e) => setTwoFactorCode((e.target as HTMLInputElement).value)} inputMode="text" placeholder="123456" /><div className="form-actions"><button type="button" className="secondary" onClick={() => { setTwoFactor(null); setTwoFactorCode(''); setLocalMessage(''); }}>{text('auth.backToLogin')}</button><button disabled={effectiveBusy || twoFactorCode.trim().length < 6}>{effectiveBusy ? text('common.validating') : text('auth.verifyCode')}</button></div></form>}

    {!verificationEmail && isDevelopmentAuth && !twoFactor && authMode === 'login' && <><form onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      void doLocal(async () => {
        const email = String(data.get('email'));
        const password = String(data.get('password'));
        try {
          const result = await api.login(email, password);
          if (isLoginChallenge(result)) { setTwoFactor({ challenge: result.challenge }); setTwoFactorCode(''); setLocalMessage(''); return; }
          onAuthenticated(result);
        } catch (error) {
          if (isEmailVerificationRequired(error)) {
            // La cuenta existe pero el correo no esta verificado: se abre la
            // ventana para reenviar la verificacion.
            setLocalMessage('');
            setVerifyNotice('');
            setVerifyEmail(email);
            setVerifyPrompt({ email });
            return;
          }
          throw error;
        }
      });
    }}><Field name="email" label={text('field.email')} type="email" /><Field name="password" label={text('field.password')} type="password" /><button disabled={effectiveBusy}>{effectiveBusy ? text('common.validating') : text('common.login')}</button></form><p style={{ marginTop: 12 }}><button type="button" className="text-button" onClick={() => { setAuthMode('forgot'); setLocalMessage(''); setNotification(''); }}>{text('auth.forgotPassword')}</button></p></>}

    {!verificationEmail && isDevelopmentAuth && !twoFactor && authMode === 'register' && <form onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      void run(async () => {
        const email = String(data.get('email')); const password = String(data.get('password'));
        const result = await api.register({ email, password, first_name: data.get('first_name'), last_name: data.get('last_name'), residence_country_code: data.get('country'), consent_policy_version: 'v1', accept_terms: true, accept_privacy: true });
        setVerificationEmail(email);
        setNotification(`${text('auth.accountCreated')} ${result.email}.`);
      });
    }}><Field name="first_name" label={text('field.firstName')} /><Field name="last_name" label={text('field.lastName')} /><Field name="email" label={text('field.email')} type="email" /><Field name="password" label={text('field.passwordMin')} type="password" /><label>{text('field.residenceCountry')}<CountrySelect name="country" defaultValue="RU" /></label><button disabled={effectiveBusy}>{effectiveBusy ? text('common.creating') : text('common.register')}</button></form>}

    {isDevelopmentAuth && authMode === 'forgot' && <form onSubmit={(e) => {
      e.preventDefault(); const email = String(new FormData(e.currentTarget).get('email') || '');
      void doLocal(async () => {
        const res = await api.forgotPassword(email);
        setNotification(res.dev_reset_url ? `${text('auth.resetLinkSent')} ${res.dev_reset_url}` : text('auth.resetLinkSent'));
      });
    }}><Field name="email" label={text('field.email')} type="email" defaultValue={resetEmail} /><div className="form-actions"><button type="button" className="secondary" onClick={() => { setAuthMode('login'); setMode('login'); setLocalMessage(''); setNotification(''); }}>{text('auth.backToLogin')}</button><button disabled={effectiveBusy}>{text('auth.sendResetLink')}</button></div></form>}

    {authMode === 'reset' && <form onSubmit={(e) => {
      e.preventDefault(); const d = new FormData(e.currentTarget); const email = String(d.get('email') || resetEmail); const token = String(d.get('token') || resetToken); const password = String(d.get('password') || '');
      void doLocal(async () => {
        await api.resetPassword(email, token, password);
        setNotification(text('auth.resetSuccess'));
        window.history.replaceState({}, '', '/?access=login');
        setAuthMode('login'); setMode('login'); setResetToken('');
      });
    }}><input type="hidden" name="token" value={resetToken} /><Field name="email" label={text('field.email')} type="email" defaultValue={resetEmail} /><Field name="password" label={text('auth.newPassword')} type="password" /><div className="form-actions"><button type="button" className="secondary" onClick={() => { setAuthMode('login'); setMode('login'); window.history.replaceState({}, '', '/?access=login'); }}>{text('auth.backToLogin')}</button><button disabled={effectiveBusy}>{text('auth.resetPassword')}</button></div></form>}

    {notification && !verificationEmail && activationState !== 'success' && <div className="notice-box notice-success" role="status"><Icon name="check"/><strong style={{ wordBreak: 'break-word' }}>{notification}</strong></div>}
    {showMessage && <Alert text={showMessage} />}
    {verifyPrompt && <div className="details-modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) { setVerifyPrompt(null); setVerifyNotice(''); } }}>
      <section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="verify-required-title">
        <div className="section-heading details-modal-heading">
          <div><span className="eyebrow">{text('auth.verifyAccount')}</span><h2 id="verify-required-title">{text('auth.verifyRequiredTitle')}</h2></div>
          <button type="button" className="secondary compact-button" onClick={() => { setVerifyPrompt(null); setVerifyNotice(''); }}>{text('common.close')}</button>
        </div>
        <p className="muted">{text('auth.verifyRequiredHint')}</p>
        <form className="modern-form" onSubmit={(event) => {
          event.preventDefault();
          const email = verifyEmail.trim();
          if (!email) return;
          setVerifyBusy(true); setVerifyNotice('');
          void api.resendVerification(email)
            .then((result) => {
              setVerifyNotice(result.dev_verification_url
                ? `${text('auth.verificationSent')} ${email}. ${text('auth.checkInbox')} ${result.dev_verification_url}`
                : `${text('auth.verificationRequested')} ${email}.`);
            })
            .catch((error) => { setVerifyNotice((error as Error).message); })
            .finally(() => setVerifyBusy(false));
        }}>
          <Field name="verify_email" label={text('field.email')} type="email" value={verifyEmail} onChange={(event) => setVerifyEmail((event.target as HTMLInputElement).value)} placeholder="nombre@correo.com" />
          <div className="form-actions">
            <button disabled={verifyBusy || !verifyEmail.trim()}>{verifyBusy ? text('common.validating') : text('auth.sendVerification')}</button>
            <button type="button" className="secondary" onClick={() => { setVerifyPrompt(null); setVerifyNotice(''); }}>{text('common.cancel')}</button>
          </div>
        </form>
        {verifyNotice && <div className="notice-box notice-success" role="status"><Icon name="check"/><strong style={{ wordBreak: 'break-word' }}>{verifyNotice}</strong></div>}
      </section>
    </div>}
  </section></main>;
}

function ProfilePanel({userId,run}:{userId:string;run:(action:()=>Promise<void>)=>Promise<void>}){
  const t = useT();
  const [profile,setProfile]=useState<any>(null);
  const [editing,setEditing]=useState(false);
  const [notification,setNotification]=useState('');
  const [notificationType,setNotificationType]=useState<'success'|'error'|'info'>('info');
  const [fieldErrors,setFieldErrors]=useState<Record<string,string>>({});
  const [saving,setSaving]=useState(false);
  useEffect(()=>{void run(async()=>setProfile(await api.profile(userId)));},[userId]);
  if(!profile)return <section className="card"><p className="muted">{t('common.loadingProfile')}</p></section>;
  const validate=():boolean=>{
    const errors:Record<string,string>={};
    const firstName=String(profile.first_name||'').trim();
    const lastName=String(profile.last_name||'').trim();
    if(!firstName) errors.first_name=t('profile.validation.firstNameRequired');
    if(!lastName) errors.last_name=t('profile.validation.lastNameRequired');
    const phone=String(profile.phone_e164||'').trim();
    if(phone!=='' && !/^\+[1-9]\d{7,14}$/.test(phone)) errors.phone_e164=t('profile.validation.phoneInvalid');
    const dob=String(profile.date_of_birth||'');
    if(dob!=='' && !/^\d{4}-\d{2}-\d{2}$/.test(dob)) errors.date_of_birth=t('profile.validation.dateInvalid');
    setFieldErrors(errors);
    return Object.keys(errors).length===0;
  };
  const handleSave=async(e:FormEvent)=>{
    e.preventDefault();
    setNotification('');
    setFieldErrors({});
    if(!validate())return;
    const d=new FormData(e.currentTarget as HTMLFormElement);
    const body={first_name:d.get('first_name'),last_name:d.get('last_name'),middle_name:d.get('middle_name'),phone_e164:d.get('phone_e164'),date_of_birth:d.get('date_of_birth')||null,residence_country_code:d.get('residence_country_code'),nationality_country_code:d.get('nationality_country_code'),address_line:d.get('address_line'),city:d.get('city'),postal_code:d.get('postal_code')};
    setSaving(true);
    try{
      const updated=await api.updateProfile(userId,body);
      setProfile(updated);
      setEditing(false);
      setNotification(t('profile.saveSuccess'));
      setNotificationType('success');
      notifySuccess(t('toast.profileSaved'));
      setTimeout(()=>setNotification(''),3500);
    }catch(error){
      const message=(error as Error).message;
      setNotification(message);
      setNotificationType('error');
      const lower=message.toLowerCase();
      if(lower.includes('phone'))setFieldErrors(prev=>({...prev,phone_e164:message}));
      else if(lower.includes('first name')||lower.includes('last name'))setFieldErrors(prev=>({...prev,first_name:message,last_name:message}));
      else if(lower.includes('date of birth'))setFieldErrors(prev=>({...prev,date_of_birth:message}));
      else if(lower.includes('country'))setFieldErrors(prev=>({...prev,residence_country_code:message,nationality_country_code:message}));
    }finally{
      setSaving(false);
    }
  };
  return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('profile.personalAccount')}</span><h2>{t('nav.profile')}</h2><p className="muted">{t('profile.description')}</p></div><span className="status-pill status-info"><Icon name="user"/> {t('nav.profile')}</span></div>
  {notification&&<div className={`notice-box notice-${notificationType||'info'}`} role="status"><Icon name={notificationType==='error'?'alert-circle':notificationType==='success'?'check':'info'}/><strong>{notification}</strong></div>}
  {!editing?(
    <section className="card profile-card"><div className="profile-header-card"><div className="profile-avatar-wrap"><span className="profile-avatar">{profile.first_name?.[0]||'U'}{profile.last_name?.[0]||''}</span></div><div className="profile-identity"><h3>{profile.first_name} {profile.last_name}</h3><p className="profile-email">{profile.email}</p><small className="profile-id">{t('common.userId')||'ID'}: {profile.public_id}</small></div><button className="edit-button" onClick={()=>setEditing(true)} disabled={saving}><Icon name="edit"/>{t('profile.edit')}</button></div>
    <div className="profile-cards-grid"><article className="profile-card-item"><div className="card-title"><Icon name="user"/><span>{t('profile.accountInfo')}</span></div><div className="card-body"><div className="profile-field"><span className="profile-field-label">{t('field.email')}</span><span className="profile-field-value">{profile.email}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.residenceCountry')}</span><span className="profile-field-value">{profile.residence_country_code==='RU'?'🇷🇺 Rusia':profile.residence_country_code==='CU'?'🇨🇺 Cuba':profile.residence_country_code}</span></div></div></article><article className="profile-card-item"><div className="card-title"><Icon name="user"/><span>{t('profile.personalData')}</span></div><div className="card-body"><div className="profile-field"><span className="profile-field-label">{t('field.firstName')}</span><span className="profile-field-value">{profile.first_name}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.lastName')}</span><span className="profile-field-value">{profile.last_name}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.middleName')}</span><span className="profile-field-value">{profile.middle_name||'—'}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.phone')}</span><span className="profile-field-value">{profile.phone_e164||'—'}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.birthDate')}</span><span className="profile-field-value">{profile.date_of_birth||'—'}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.nationality')}</span><span className="profile-field-value">{profile.nationality_country_code==='CU'?'🇨🇺 '+t('country.cuba'):profile.nationality_country_code==='RU'?'🇷🇺 '+t('country.russia'):profile.nationality_country_code}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.address')}</span><span className="profile-field-value">{profile.address_line||'—'}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.city')}</span><span className="profile-field-value">{profile.city||'—'}</span></div><div className="profile-field"><span className="profile-field-label">{t('field.postalCode')}</span><span className="profile-field-value">{profile.postal_code||'—'}</span></div></div></article></div></section>
  ):(
    <section className="card profile-card"><div className="profile-header-card"><div className="profile-avatar-wrap"><span className="profile-avatar">{profile.first_name?.[0]||'U'}{profile.last_name?.[0]||''}</span></div><div className="profile-identity"><h3>{profile.first_name} {profile.last_name}</h3><p className="profile-email">{profile.email}</p><small className="profile-id">{t('common.userId')||'ID'}: {profile.public_id}</small></div><button className="edit-button secondary" onClick={()=>setEditing(false)} disabled={saving}><Icon name="arrow-left"/>{t('profile.cancel')}</button></div>
    <div className="profile-section-title"><Icon name="user"/><div><strong>{t('profile.personalInformation')}</strong><small>{t('profile.emailImmutable')}</small></div></div>
    <form className="modern-form" onSubmit={handleSave}><div className="form-grid"><Field name="first_name" label={t('field.firstName')} defaultValue={profile.first_name} error={fieldErrors.first_name}/><Field name="last_name" label={t('field.lastName')} defaultValue={profile.last_name} error={fieldErrors.last_name}/><Field name="middle_name" label={t('field.middleName')} required={false} defaultValue={profile.middle_name||''} error={fieldErrors.middle_name}/><label>Correo electrónico<input type="email" value={profile.email} disabled/></label><Field name="phone_e164" label={t('field.phone')} type="tel" required={false} defaultValue={profile.phone_e164||''} error={fieldErrors.phone_e164}/><Field name="date_of_birth" label={t('field.birthDate')} type="date" required={false} defaultValue={profile.date_of_birth||''} error={fieldErrors.date_of_birth}/><label className={fieldErrors.residence_country_code ? 'field-error' : undefined}>{t('field.residenceCountry')}<CountrySelect name="residence_country_code" defaultValue={(profile.residence_country_code as any)||'RU'} error={fieldErrors.residence_country_code} />{fieldErrors.residence_country_code && <small className="field-error-message" id="residence_country_code-error" role="alert">{fieldErrors.residence_country_code}</small>}</label><label className={fieldErrors.nationality_country_code ? 'field-error' : undefined}>{t('field.nationality')}<CountrySelect name="nationality_country_code" defaultValue={(profile.nationality_country_code as any)||'CU'} error={fieldErrors.nationality_country_code} />{fieldErrors.nationality_country_code && <small className="field-error-message" id="nationality_country_code-error" role="alert">{fieldErrors.nationality_country_code}</small>}</label><Field name="address_line" label={t('field.address')} required={false} defaultValue={profile.address_line||''} error={fieldErrors.address_line}/><Field name="city" label={t('field.city')} required={false} defaultValue={profile.city||''} error={fieldErrors.city}/><Field name="postal_code" label={t('field.postalCode')} required={false} defaultValue={profile.postal_code||''} error={fieldErrors.postal_code}/></div><div className="form-actions"><button type="button" className="secondary" onClick={()=>setEditing(false)} disabled={saving}>{t('profile.cancel')}</button><button type="submit" disabled={saving}>{saving?'Guardando…':<><Icon name="check"/>{t('common.saveChanges')}</>}</button></div></form></section>
  )}</div>;
}

function SecurityPanel({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const t = useT();
  const [status, setStatus] = useState<SecurityStatus | null>(null);
  const [notice, setNotice] = useState('');
  const [noticeType, setNoticeType] = useState<'success' | 'error' | 'info'>('info');
  const [busy, setBusy] = useState(false);
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [qrDataUrl, setQrDataUrl] = useState('');
  const [confirmCode, setConfirmCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [showDisable, setShowDisable] = useState(false);

  const refresh = () => run(async () => setStatus(await api.securityStatus(userId)));
  useEffect(() => { void refresh(); }, [userId]);
  useEffect(() => {
    if (!setup?.otpauth_uri) { setQrDataUrl(''); return; }
    void QRCode.toDataURL(setup.otpauth_uri, { width: 220, margin: 1 }).then(setQrDataUrl).catch(() => setQrDataUrl(''));
  }, [setup?.otpauth_uri]);

  const act = async (fn: () => Promise<void>) => {
    setBusy(true); setNotice('');
    try { await fn(); }
    catch (e) { setNotice((e as Error).message); setNoticeType('error'); }
    finally { setBusy(false); }
  };

  if (!status) return <section className="card"><p className="muted">{t('common.loading')}</p></section>;

  return <div className="feature-view">
    <div className="view-heading"><div><span className="eyebrow">{t('nav.security')}</span><h2>{t('security.title')}</h2><p className="muted">{t('security.description')}</p></div><span className={`status-pill ${status.two_factor_enabled ? 'status-success' : 'status-warning'}`}><Icon name="shield" /> {status.two_factor_enabled ? t('security.twoFactorEnabled') : t('security.twoFactorDisabled')}</span></div>
    {notice && <div className={`notice-box notice-${noticeType}`} role="status"><Icon name={noticeType === 'error' ? 'alert-circle' : 'check'} /><strong>{notice}</strong></div>}
    {recoveryCodes.length > 0 && <section className="card"><div className="section-heading"><div><span className="eyebrow">2FA</span><h3>{t('security.recoveryCodes')}</h3></div></div><p className="muted">{t('security.recoveryCodesHint')}</p><div className="form-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(140px,1fr))', marginTop: 12 }}>{recoveryCodes.map(c => <code key={c} style={{ padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border, #e2e8f0)', textAlign: 'center' }}>{c}</code>)}</div><div className="form-actions"><button type="button" className="secondary" onClick={() => setRecoveryCodes([])}>OK</button></div></section>}

    <section className="card">
      <div className="section-heading"><div><span className="eyebrow">{t('security.password')}</span><h3>{t('security.changePassword')}</h3></div><span className="section-icon"><Icon name="lock" /></span></div>
      {!status.password_change_available ? <p className="muted">{t('security.oidcPasswordNote')}</p> : <form className="modern-form" onSubmit={(e) => {
        e.preventDefault(); const d = new FormData(e.currentTarget); const current = String(d.get('current_password') || ''); const next = String(d.get('new_password') || '');
        void act(async () => { await api.changePassword(userId, current, next); (e.target as HTMLFormElement).reset(); setNotice(t('security.passwordChanged')); setNoticeType('success'); });
      }}><div className="form-grid"><Field name="current_password" label={t('security.currentPassword')} type="password" /><Field name="new_password" label={t('security.newPassword')} type="password" placeholder="••••••••••••" /></div><div className="form-actions"><button disabled={busy}>{t('security.changePassword')}</button></div></form>}
    </section>

    <section className="card">
      <div className="section-heading"><div><span className="eyebrow">2FA</span><h3>{t('security.twoFactor')}</h3></div><span className="section-icon"><Icon name="shield" /></span></div>
      {!status.two_factor_enabled ? <>
        {!setup ? <div className="form-actions"><button disabled={busy} onClick={() => void act(async () => { setSetup(await api.twoFactorSetup(userId)); setConfirmCode(''); setNotice(''); })}>{t('security.enable2fa')}</button></div>
          : <div><p className="muted">{t('security.setup2faHint')}</p>{qrDataUrl && <img src={qrDataUrl} alt="2FA QR" width={220} height={220} style={{ borderRadius: 12, background: '#fff', padding: 8 }} />}<p><small>{t('security.manualKey')}: <code style={{ wordBreak: 'break-all' }}>{setup.secret}</code></small></p>
            <form className="modern-form" onSubmit={(e) => { e.preventDefault(); void act(async () => { const res = await api.twoFactorConfirm(userId, confirmCode); setRecoveryCodes(res.recovery_codes); setSetup(null); setConfirmCode(''); setNotice(t('security.twoFactorEnabled')); setNoticeType('success'); await refresh(); }); }}><div className="form-grid"><Field name="code" label={t('auth.twoFactorCode')} value={confirmCode} onChange={(e) => setConfirmCode((e.target as HTMLInputElement).value)} inputMode="numeric" placeholder="123456" /></div><div className="form-actions"><button type="button" className="secondary" onClick={() => setSetup(null)}>{t('common.cancel')}</button><button disabled={busy || confirmCode.trim().length < 6}>{t('security.confirm2fa')}</button></div></form></div>}
      </> : <>
        {!showDisable ? <div className="form-actions"><button className="secondary" disabled={busy} onClick={() => setShowDisable(true)}>{t('security.disable2fa')}</button></div>
          : <form className="modern-form" onSubmit={(e) => {
            e.preventDefault(); const d = new FormData(e.currentTarget); const password = String(d.get('password') || ''); const code = String(d.get('code') || '');
            void act(async () => { await api.twoFactorDisable(userId, password, code); setShowDisable(false); setNotice(t('security.twoFactorDisabled')); setNoticeType('success'); await refresh(); });
          }}><p className="muted">{t('security.disableHint')}</p><div className="form-grid"><Field name="password" label={t('security.currentPassword')} type="password" /><Field name="code" label={t('auth.twoFactorCode')} inputMode="text" placeholder="123456 / XXXXX-XXXXX" /></div><div className="form-actions"><button type="button" className="secondary" onClick={() => setShowDisable(false)}>{t('common.cancel')}</button><button disabled={busy}>{t('security.disable2fa')}</button></div></form>}
      </>}
    </section>
  </div>;
}

function KycPanel({userId,run}:{userId:string;run:(action:()=>Promise<void>)=>Promise<void>}){
  const t = useT();
 const [kyc,setKyc]=useState<any>(null);const refresh=()=>run(async()=>setKyc(await api.kyc(userId)));useEffect(()=>{refresh();},[userId]);
 const hasIdentity=Boolean(kyc?.documents?.some((d:any)=>d.document_type==='identity'));const hasAddress=Boolean(kyc?.documents?.some((d:any)=>d.document_type==='address'));const limit=kyc?.daily_limit;
 return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('kyc.accountProtection')}</span><h2>{t('kyc.title')}</h2><p className="muted">{t('kyc.description')}</p></div><span className="status-pill status-info"><Icon name="shield"/> KYC</span></div>
 {limit?.limited&&<div className="notice-box notice-info" role="status"><Icon name="info"/><strong>{t('toast.dailyLimit')}</strong><span>{` · ${limit.used} / ${limit.limit} RUB utilizados hoy`}</span></div>}
 <section className="card kyc-card"><div className={`kyc-status-banner ${kyc?.status||'pending'}`}><span className="kyc-shield"><Icon name="shield"/></span><div><small>{t('kyc.verificationStatus')}</small><strong>{kyc?.status||t('common.loading')}</strong><p>{kyc?.review_reason||t('kyc.uploadPrompt')}</p></div><StatusBadge status={kyc?.status||'pending'}/></div>
 <div className="kyc-checklist"><div className={hasIdentity?'kyc-check complete':'kyc-check'}><Icon name={hasIdentity?'check':'receipt'}/><span><strong>{t('kyc.identityDocument')}</strong><small>{hasIdentity?t('kyc.documentReceived'):t('kyc.pendingUpload')}</small></span></div><div className={hasAddress?'kyc-check complete':'kyc-check'}><Icon name={hasAddress?'check':'location'}/><span><strong>{t('kyc.addressProof')}</strong><small>{hasAddress?t('kyc.documentReceived'):t('kyc.pendingUpload')}</small></span></div></div>
 <form className="modern-form kyc-upload" onSubmit={e=>{e.preventDefault();const form=e.currentTarget;const d=new FormData(form);const file=d.get('file') as File;void run(async()=>{if(!file||!file.size)throw new Error(t('kyc.selectFile'));if(file.size>5*1024*1024)throw new Error(t('kyc.fileTooLarge'));const mime=(file.type||'').toLowerCase();if(mime&&!['application/pdf','image/jpeg','image/png'].includes(mime))throw new Error(t('kyc.fileTypeNotAllowed'));try{await api.uploadKycDocument(userId,file,String(d.get('document_type')));}catch(error){const key=kycUploadErrorKey((error as Error).message);throw new Error(key?t(key):(error as Error).message);}setKyc(await api.kyc(userId));form.reset();notifySuccess(t('toast.kycDocumentUploaded'));});}}><div className="form-grid"><label>{t('kyc.documentType')}<select name="document_type"><option value="identity">{t('kyc.identityDocument')}</option><option value="address">{t('kyc.addressProof')}</option><option value="selfie">{t('kyc.selfieOptional')}</option></select></label><label>{t('kyc.fileTypes')}<input name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required/></label></div><button><Icon name="plus"/> {t('kyc.uploadDocument')}</button></form>
 <h3 className="subsection-title">{t('kyc.submittedDocuments')}</h3>{!kyc?.documents?.length?<p className="muted">{t('kyc.noDocuments')}</p>:<div className="document-list">{kyc.documents.map((d:any)=><div className="document-item" key={d.public_id}><span className="document-icon"><Icon name="receipt"/></span><div><strong>{d.document_type}</strong><small>{d.original_name} · {(d.file_size/1024).toFixed(0)} KB</small></div><StatusBadge status={d.status||'received'}/></div>)}</div>}
 <div className="form-actions"><button disabled={!hasIdentity||!hasAddress||['approved','pending_review'].includes(kyc?.status)} onClick={()=>void run(async()=>{await api.submitKyc(userId);setKyc(await api.kyc(userId));notifySuccess(t('toast.kycSubmitted'));})}>{t('kyc.submitForReview')}</button></div><p className="secure-note"><Icon name="lock"/> {t('kyc.secureStorage')}</p></section></div>;
}
function Summary({ userId, setView, run }: { userId: string; setView: (view: View) => void; run: (action: () => Promise<void>) => Promise<void> }) {
  const t = useT();
  const locale = localeFor(useLanguage());
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  useEffect(() => { void run(async () => setRemittances(await api.remittances(userId))); }, [userId]);
  const completed = remittances.filter(item => item.status === 'completed').length;
  const pending = remittances.filter(item => !['completed','cancelled','rejected'].includes(item.status)).length;
  const recent = [...remittances].slice(0, 5);
  return <div className="dashboard-view">
    <div className="view-heading"><div><span className="eyebrow">{t('dashboard.activity')}</span><h2>{t('dashboard.title')}</h2><p className="muted">{t('dashboard.description')}</p></div><span className="status-pill status-success"><Icon name="shield" /> {t('dashboard.accountActive')}</span></div>
    <div className="dashboard-stats">
      <article className="metric-card metric-primary"><span>{t('dashboard.remittancesCreated')}</span><strong>{remittances.length}</strong><small><Icon name="receipt" /> {t('dashboard.operationsRegistered')}</small></article>
      <article className="metric-card"><span>{t('common.completed')}</span><strong>{completed}</strong><small className="metric-positive"><Icon name="check" /> {t('dashboard.deliveriesCompleted')}</small></article>
      <article className="metric-card"><span>{t('common.inProgress')}</span><strong>{pending}</strong><small><Icon name="clock" /> {t('dashboard.needTracking')}</small></article>
    </div>
    <section className="card dashboard-actions"><div className="section-heading"><div><span className="eyebrow">{t('dashboard.quickAccess')}</span><h2>{t('dashboard.whatNext')}</h2></div></div><div className="action-grid">
      <button className="action-tile" onClick={() => setView('quote')}><span className="action-icon"><Icon name="send"/></span><strong>{t('nav.quote')}</strong><small>{t('dashboard.quoteDescription')}</small><span className="action-arrow">→</span></button>
      <button className="action-tile" onClick={() => setView('beneficiaries')}><span className="action-icon action-green"><Icon name="users"/></span><strong>{t('dashboard.manageBeneficiaries')}</strong><small>{t('dashboard.beneficiariesDescription')}</small><span className="action-arrow">→</span></button>
      <button className="action-tile" onClick={() => setView('remittances')}><span className="action-icon action-coral"><Icon name="history"/></span><strong>{t('dashboard.trackRemittances')}</strong><small>{t('dashboard.trackingDescription')}</small><span className="action-arrow">→</span></button>
    </div></section>
    <section className="card recent-activity"><div className="section-heading"><div><span className="eyebrow">{t('dashboard.recentActivity')}</span><h2>{t('dashboard.latestOperations')}</h2></div><button className="text-button" onClick={() => setView('remittances')}>{t('common.viewAll')} <span>→</span></button></div>
      {recent.length===0?<div className="empty-state"><span className="empty-icon"><Icon name="receipt"/></span><strong>{t('dashboard.noOperations')}</strong><p>{t('dashboard.noOperationsDescription')}</p><button onClick={() => setView('quote')}>{t('dashboard.createFirstQuote')}</button></div>:<div className="activity-list">{recent.map(item=><div className="activity-item" key={item.public_id}><span className="activity-symbol"><Icon name="transfer"/></span><div className="activity-main"><strong>{item.reference}</strong><small>{item.created_at ? new Date(item.created_at).toLocaleString(locale) : 'Fecha no disponible'}</small></div><div className="activity-amount"><strong>{item.send_amount} {item.send_currency}</strong><small>→ {item.receive_amount} {item.receive_currency}</small></div><StatusBadge status={item.status}/></div>)}</div>}
    </section>
  </div>;
}
function Beneficiaries({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const t = useT();
 const [items,setItems]=useState<Beneficiary[]>([]); const [method,setMethod]=useState('cash_pickup'); const [editing,setEditing]=useState<Beneficiary|null>(null); const [fieldErrors,setFieldErrors]=useState<Record<string,string>>({}); const [benefCountry,setBenefCountry]=useState<'CU'|'RU'>('CU'); const [benefProvider,setBenefProvider]=useState('');
 const refresh=()=>run(async()=>setItems(await api.beneficiaries(userId))); useEffect(()=>{refresh();},[userId]); useEffect(()=>{const handler=(event:Event)=>{const detail=(event as CustomEvent<{field:string;message:string}>).detail;setFieldErrors((current)=>({...current,[detail.field]:detail.message}));};window.addEventListener('sederg:beneficiary-field-error',handler);return()=>window.removeEventListener('sederg:beneficiary-field-error',handler);},[]);
 const submit=(event:FormEvent<HTMLFormElement>)=>{event.preventDefault();const form=event.currentTarget;const d=new FormData(form);const phone=String(d.get('phone_e164')||'').replace(/\D/g,'');const account=String(d.get('payout_account_number')||'').replace(/\D/g,'');const card=String(d.get('payout_card_number')||'').replace(/\D/g,'');const provider=String(d.get('payout_provider')||'').trim();const errors:Record<string,string>={};if(phone.length<8||phone.length>15)errors.phone_e164='Introduce un teléfono internacional válido (entre 8 y 15 dígitos).';if(method==='bank_account'&&!provider)errors.payout_provider='Indica el banco o proveedor de la cuenta.';if(method==='bank_account'&&(!editing||account)&&(account.length<6||account.length>34))errors.payout_account_number='La cuenta debe contener entre 6 y 34 dígitos.';if(method==='card_transfer'&&(!editing||card)&&!isValidCardNumber(card))errors.payout_card_number='Introduce un número de tarjeta válido (12–19 dígitos y checksum correcto).';setFieldErrors(errors);if(Object.keys(errors).length)return;void run(async()=>{const body={country_code:d.get('country'),first_name:d.get('first_name'),last_name:d.get('last_name'),phone_e164:phone ? '+' + phone : '',email:d.get('email'),payout_method_code:method,payout_provider:provider||undefined,payout_account_number:account||undefined,payout_card_number:card||undefined};if(editing){await api.updateBeneficiary(userId,editing.public_id,body);notifySuccess(t('toast.beneficiaryUpdated'));}else{await api.createBeneficiary(userId,body);notifySuccess(t('toast.beneficiaryCreated'));}setEditing(null);setMethod('cash_pickup');setFieldErrors({});setBenefCountry('CU');setBenefProvider('');form.reset();await refresh();});};
 const [pendingDelete,setPendingDelete]=useState<Beneficiary|null>(null);
 const remove=(item:Beneficiary)=>setPendingDelete(item);
 const confirmDelete=()=>{if(!pendingDelete)return;const target=pendingDelete;setPendingDelete(null);void run(async()=>{await api.deleteBeneficiary(userId,target.public_id);notifySuccess(t('toast.beneficiaryDeleted'));await refresh();});};
 return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('beneficiaries.eyebrow')}</span><h2>{t('nav.beneficiaries')}</h2><p className="muted">{t('beneficiaries.description')}</p></div><span className="count-chip">{items.length} {t('common.registered')}</span></div>
 <div className="beneficiary-layout"><section className="card form-card"><div className="section-heading"><div><span className="eyebrow">{editing?t('common.updateData'):t('beneficiaries.newRecipient')}</span><h3>{editing?t('beneficiaries.edit'):t('beneficiaries.add')}</h3></div><span className="section-icon"><Icon name="user"/></span></div><p className="muted">{t('beneficiaries.editSecurity')}</p><form className="modern-form" key={editing?.public_id ?? 'new-beneficiary'} onSubmit={submit}><div className="form-grid"><Field name="first_name" label={t('field.firstName')} defaultValue={editing?.first_name}/><Field name="last_name" label={t('field.lastName')} defaultValue={editing?.last_name}/><Field name="phone_e164" label={t('field.phone')} type="tel" mask="phone" error={fieldErrors.phone_e164} placeholder="+7 999 123-45-67 / +53 5 123 4567" defaultValue={editing?.phone_e164??''}/><Field name="email" label={t('field.email')} type="email" defaultValue={editing?.email??''}/><label>{t('field.country')}<CountrySelect name="country" value={benefCountry} onChange={(c: 'CU' | 'RU')=>{setBenefCountry(c);setBenefProvider('');}} /></label><label>Método de entrega preferido<select value={method} onChange={e=>setMethod(e.target.value)}>{paymentMethods.map(m=><option key={m.code} value={m.code}>{t('payout.'+m.code)}</option>)}</select></label></div>
 <div className="conditional-fields"><label>{t('field.bankProvider')}{method==='bank_account' ? <BankSelect banks={BANKS_BY_COUNTRY[benefCountry]} value={benefProvider} valueKey="name" name="payout_provider" onChange={(val)=>setBenefProvider(val)} placeholder="Selecciona un banco" allowEmptyOption emptyLabel="Selecciona un banco" error={fieldErrors.payout_provider} /> : <input name="payout_provider" value={benefProvider} onChange={e=>setBenefProvider(e.target.value)} placeholder={t('field.bankProvider')} />}{fieldErrors.payout_provider && method!=='bank_account' && <span className="field-error-message" role="alert">{fieldErrors.payout_provider}</span>}{fieldErrors.payout_provider && method==='bank_account' && <span className="field-error-message" role="alert">{fieldErrors.payout_provider}</span>}</label><Field name="payout_account_number" label={t('field.accountNumberKeep')} error={fieldErrors.payout_account_number} mask="account" placeholder="0000 0000 0000 0000" inputMode="numeric" pattern="[0-9 ]{6,42}" required={method==='bank_account'&&!editing}/><Field name="payout_card_number" label={t('field.cardNumberKeep')} error={fieldErrors.payout_card_number} mask="card" placeholder="0000 0000 0000 0000" inputMode="numeric" pattern="[0-9 ]{12,23}" required={method==='card_transfer'&&!editing}/></div><p className="muted">Puedes registrar los datos bancarios y de tarjeta ahora. Los campos que dejes vacíos podrán completarse al seleccionar ese método en una cotización.</p><p className="secure-note"><Icon name="lock"/> {t('beneficiaries.encryptedData')}</p><div className="form-actions"><button>{editing?t('common.saveChanges'):t('beneficiaries.save')}</button>{editing&&<button type="button" className="secondary" onClick={()=>{setEditing(null);setMethod('cash_pickup');setBenefCountry('CU');setBenefProvider('');setFieldErrors({});}}>{t('common.cancel')}</button>}</div></form></section>
 <section className="card beneficiary-list-card"><div className="section-heading"><div><span className="eyebrow">{t('beneficiaries.addressBook')}</span><h3>{t('beneficiaries.registeredPeople')}</h3></div><span className="section-icon"><Icon name="users"/></span></div>{items.length===0?<div className="empty-state"><span className="empty-icon"><Icon name="users"/></span><strong>{t('beneficiaries.empty')}</strong><p>{t('beneficiaries.emptyDescription')}</p></div>:<div className="beneficiary-list">{items.map(item=><article className="beneficiary-item" key={item.public_id}><div className="beneficiary-avatar">{(item.first_name?.[0]||'B').toUpperCase()}{(item.last_name?.[0]||'')[0]?.toUpperCase()}</div><div className="beneficiary-info"><strong>{item.first_name} {item.last_name}</strong><span><Icon name="globe"/> {item.country_code==='CU'?t('country.cuba'):t('country.russia')} · {item.phone_e164||item.email||t('common.noContact')}</span><small>{t('payout.'+item.payout_method_code)} {item.payout_provider??''} {item.payout_account_last4?'· cuenta ****'+item.payout_account_last4:''} {item.payout_card_last4?'· tarjeta ****'+item.payout_card_last4:''}</small></div><div className="beneficiary-actions"><StatusBadge status={item.status}/><button className="secondary compact-button" type="button" onClick={()=>{setEditing(item);setMethod(item.payout_method_code);setBenefCountry((item.country_code as 'CU'|'RU')||'CU');setBenefProvider(item.payout_provider||'');setFieldErrors({});}}><Icon name="edit"/> {t('common.edit')}</button><button className="secondary compact-button danger" type="button" onClick={()=>remove(item)}><Icon name="delete"/> {t('common.delete')}</button></div></article>)}</div>}</section></div>
 {pendingDelete&&<div className="details-modal-backdrop" onMouseDown={event=>{if(event.target===event.currentTarget)setPendingDelete(null);}}><section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="delete-beneficiary-title"><div className="section-heading details-modal-heading"><div><span className="eyebrow">{t('common.confirm')}</span><h2 id="delete-beneficiary-title">{t('toast.deleteBeneficiaryTitle')}</h2></div><button type="button" className="secondary compact-button" onClick={()=>setPendingDelete(null)}>{t('common.close')}</button></div><p className="muted">{t('toast.deleteBeneficiaryHint')} <strong>{pendingDelete.first_name} {pendingDelete.last_name}</strong>.</p><div className="form-actions"><button type="button" className="danger" onClick={confirmDelete}>{t('common.delete')}</button><button type="button" className="secondary" onClick={()=>setPendingDelete(null)}>{t('common.cancel')}</button></div></section></div>}
 </div>;
}
function CountryFlag({ country, className = '' }: { country: 'CU' | 'RU'; className?: string }) {
  if (country === 'RU') {
    return <svg className={className} viewBox="0 0 30 20" role="img" aria-label="Bandera de Rusia" xmlns="http://www.w3.org/2000/svg">
      <rect width="30" height="20" rx="2" fill="#fff" />
      <path d="M0 6.67h30v6.66H0z" fill="#0039A6" />
      <path d="M0 13.33h30V20H0z" fill="#D52B1E" />
    </svg>;
  }
  return <svg className={className} viewBox="0 0 30 20" role="img" aria-label="Bandera de Cuba" xmlns="http://www.w3.org/2000/svg">
    <rect width="30" height="20" rx="2" fill="#fff" />
    <path d="M0 0h30v4H0zM0 8h30v4H0zM0 16h30v4H0z" fill="#002A8F" />
    <path d="M0 0 15 10 0 20z" fill="#CF142B" />
    <path d="m5.1 6.8 1.1 2.3 2.5.3-1.8 1.7.5 2.5-2.3-1.2-2.2 1.2.4-2.5-1.8-1.7 2.5-.3z" fill="#fff" />
  </svg>;
}

const COUNTRIES: { code: 'CU' | 'RU'; name: string; flag: string }[] = [
  { code: 'CU', name: 'Cuba', flag: `${import.meta.env.BASE_URL}assets/sederg-pay/flags/cuba.svg` },
  { code: 'RU', name: 'Rusia', flag: `${import.meta.env.BASE_URL}assets/sederg-pay/flags/russia.svg` },
];

function CountrySelect({
  value,
  defaultValue,
  onChange,
  name,
  error,
  placeholder,
}: {
  value?: 'CU' | 'RU' | string;
  defaultValue?: 'CU' | 'RU' | string;
  onChange?: (value: 'CU' | 'RU') => void;
  name?: string;
  error?: string;
  placeholder?: string;
}) {
  const [open, setOpen] = useState(false);
  const [internal, setInternal] = useState<'CU' | 'RU' | string>((defaultValue ?? 'CU') as 'CU' | 'RU');
  const current = (value !== undefined ? value : internal) as 'CU' | 'RU';
  const ref = useRef<HTMLDivElement | null>(null);
  const selected = COUNTRIES.find((c) => c.code === current);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
  }, [open]);
  const select = (code: 'CU' | 'RU') => {
    if (value === undefined) setInternal(code);
    onChange?.(code);
    setOpen(false);
  };
  return <div className={`bank-select country-select${open ? ' open' : ''}${error ? ' has-error' : ''}`} ref={ref}>
    {name && <input type="hidden" name={name} value={current} />}
    <button type="button" className="bank-select-trigger" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
      {selected ? <><img className="country-flag-icon" src={selected.flag} alt="" /><span className="bank-select-label">{selected.name}</span></> : <span className="bank-select-placeholder">{placeholder || 'Selecciona un país'}</span>}
      <span className="bank-select-caret" aria-hidden="true">▾</span>
    </button>
    {open && <div className="bank-select-list" role="listbox">
      {COUNTRIES.map((c) => {
        const isSel = c.code === current;
        return <button key={c.code} type="button" role="option" aria-selected={isSel} className={`bank-select-option${isSel ? ' selected' : ''}`} onClick={() => select(c.code)}>
          <img className="country-flag-icon" src={c.flag} alt="" /><span className="bank-select-label">{c.name}</span>
        </button>;
      })}
    </div>}
  </div>;
}

function QuotePanel({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const t = useT();
  const locale = localeFor(useLanguage());
  const [step, setStep] = useState(0);
  const [quote, setQuote] = useState<Quote | null>(null);
  const [created, setCreated] = useState<Remittance | null>(null);
  const [beneficiaries, setBeneficiaries] = useState<Beneficiary[]>([]);
  const [method, setMethod] = useState('cash_pickup');
  const [beneficiaryId, setBeneficiaryId] = useState('');
  const [missingDetails, setMissingDetails] = useState(false);
  const [pendingAmount, setPendingAmount] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [cardNumber, setCardNumber] = useState('');
  const [bankProvider, setBankProvider] = useState('');
  const [modalErrors, setModalErrors] = useState<Record<string, string>>({});
  const [direction, setDirection] = useState<'RU-CU'|'CU-RU'>('RU-CU');
  const [feeMode, setFeeMode] = useState<'add_to_send'|'deduct_from_receive'>('add_to_send');
  const [amount, setAmount] = useState('15000.00');
  const [wizardError, setWizardError] = useState('');
  useEffect(() => { void run(async () => setBeneficiaries(await api.beneficiaries(userId))); }, [userId]);
  useEffect(() => {
    const handler = (event: Event) => {
      const detail = (event as CustomEvent<{field:string;message:string}>).detail;
      const key = detail.field === 'payout_provider' ? 'bank_provider' : detail.field === 'payout_account_number' ? 'account_number' : detail.field === 'payout_card_number' ? 'card_number' : detail.field;
      setModalErrors(current => ({...current,[key]:detail.message}));
    };
    window.addEventListener('sederg:beneficiary-field-error', handler);
    return () => window.removeEventListener('sederg:beneficiary-field-error', handler);
  }, []);
  const sourceCountry = direction === 'RU-CU' ? 'RU' : 'CU';
  const destinationCountry = direction === 'RU-CU' ? 'CU' : 'RU';
  const sendCurrency = direction === 'RU-CU' ? 'RUB' : 'CUP';
  const receiveCurrency = direction === 'RU-CU' ? 'CUP' : 'RUB';
  const matchingBeneficiaries = beneficiaries.filter(item => item.country_code === destinationCountry);
  const selectedBeneficiary = beneficiaries.find(item => item.public_id === beneficiaryId);
  useEffect(() => {
    if (selectedBeneficiary && selectedBeneficiary.country_code === destinationCountry) setMethod(selectedBeneficiary.payout_method_code);
    else if (!beneficiaryId && matchingBeneficiaries.length) setBeneficiaryId(matchingBeneficiaries[0].public_id);
  }, [beneficiaries, beneficiaryId, destinationCountry]);
  const calculateQuote = async (value: string) => {
    if (!beneficiaryId || !selectedBeneficiary) throw new Error('Selecciona un beneficiario válido para el país de destino.');
    if (!/^\d+(?:[.,]\d{1,2})?$/.test(value) || Number(value.replace(',', '.')) <= 0) throw new Error('Introduce un importe válido mayor que cero.');
    const needsBank = method === 'bank_account' && !selectedBeneficiary.payout_account_last4;
    const needsCard = method === 'card_transfer' && !selectedBeneficiary.payout_card_last4;
    if (needsBank || needsCard) {
      setPendingAmount(value);
      setMissingDetails(true);
      return false;
    }
    const result = await api.quote(userId, {
      source_country_code: sourceCountry,
      destination_country_code: destinationCountry,
      send_amount: value.replace(',', '.'),
      send_currency: sendCurrency,
      receive_currency: receiveCurrency,
      payout_method_code: method,
      fee_mode: feeMode,
      beneficiary_public_id: beneficiaryId,
    });
    setQuote(result);
    return true;
  };
  const goNext = () => {
    setWizardError('');
    if (step === 0) {
      if (!beneficiaryId || !selectedBeneficiary) { setWizardError('Selecciona un beneficiario del país de destino.'); return; }
      setStep(1);
    } else if (step === 1) {
      void run(async () => { if (await calculateQuote(amount)) setStep(2); });
    } else if (step === 2) {
      if (!quote) { setWizardError('Calcula primero una cotización actualizada.'); return; }
      setStep(3);
    }
  };
  const stepLabels = ['Destino','Importe y comisión','Entrega','Confirmación'];
  return <div className="feature-view">
    <div className="view-heading"><div><span className="eyebrow">{t('quote.route')}</span><h2>{t('nav.quote')}</h2><p className="muted">{t('quote.description')}</p></div><span className="status-pill status-info"><Icon name="shield"/> {t('quote.transparent')}</span></div>
    <section className="card remittance-wizard">
      <div className="wizard-progress" aria-label="Progreso de creación de remesa">
        {stepLabels.map((label,index)=><div key={label} className={index===step?'wizard-step active':index<step?'wizard-step completed':'wizard-step'}><span>{index<step?'✓':index+1}</span><small>{label}</small></div>)}
      </div>
      <div className="wizard-heading"><div><span className="eyebrow">PASO {step+1} DE 4</span><h3>{['¿A quién enviarás el dinero?','Define el importe y la comisión','Revisa los datos de entrega','Confirma tu remesa'][step]}</h3><p className="muted">{['Selecciona el sentido de la operación y el destinatario.','Conoce cuánto pagarás y cuánto recibirá la otra persona.','Comprueba el beneficiario y el método de pago seleccionado.','Revisa todos los datos antes de crear la operación.'][step]}</p></div></div>
      {step===0 && <div className="wizard-panel modern-form">
        <label>Dirección del envío<select value={direction} onChange={event=>{setDirection(event.target.value as 'RU-CU'|'CU-RU');setBeneficiaryId('');setQuote(null);setWizardError('');}}><option value="RU-CU">🇷🇺 Rusia → 🇨🇺 Cuba</option><option value="CU-RU">🇨🇺 Cuba → 🇷🇺 Rusia</option></select></label>
        <label>{t('field.beneficiary')}<select value={beneficiaryId} onChange={event=>{setBeneficiaryId(event.target.value);setQuote(null);}}><option value="">{t('quote.selectBeneficiary')}</option>{matchingBeneficiaries.map(item=><option key={item.public_id} value={item.public_id}>{item.first_name} {item.last_name} · {item.phone_e164||item.email||item.country_code}</option>)}</select></label>
        {matchingBeneficiaries.length===0 && <div className="alert">{t('quote.noMatchingBeneficiary')}</div>}
        <label>{t('quote.deliveryMethod')}<select value={method} onChange={event=>{setMethod(event.target.value);setQuote(null);}}>{paymentMethods.map(item=><option key={item.code} value={item.code}>{t('payout.'+item.code)}</option>)}</select></label>
        <div className="quote-note"><Icon name="info"/> El método de entrega debe estar disponible para el país de destino. La disponibilidad real de bancos y operadores depende de la habilitación operativa de la plataforma.</div>
      </div>}
      {step===1 && <div className="wizard-panel modern-form">
        <div className="wizard-route-chip"><CountryFlag country={sourceCountry}/><strong>{sendCurrency}</strong><span>→</span><CountryFlag country={destinationCountry}/><strong>{receiveCurrency}</strong></div>
        <div className="amount-input-wrap"><Field name="wizard_amount" label={t('quote.sendAmount')} value={amount} onChange={(event:ChangeEvent<HTMLInputElement>)=>{setAmount(event.target.value);setQuote(null);}} inputMode="numeric"/><span>{sendCurrency}</span></div>
        <label>Modalidad de comisión<select value={feeMode} onChange={event=>{setFeeMode(event.target.value as 'add_to_send'|'deduct_from_receive');setQuote(null);}}><option value="add_to_send">Añadir la comisión al pago del remitente</option><option value="deduct_from_receive">Descontar la comisión de lo que recibe el beneficiario</option></select></label>
        <div className="quote-note"><Icon name="info"/> La cotización mostrará la tasa disponible, su fuente y fecha de vigencia. La comisión inicial configurada es del 3%, sujeta a las condiciones vigentes de la plataforma.</div>
      </div>}
      {step===2 && quote && <div className="wizard-panel">
        <div className="wizard-recipient"><span className="beneficiary-avatar">{selectedBeneficiary?.first_name?.[0]}{selectedBeneficiary?.last_name?.[0]}</span><div><strong>{selectedBeneficiary?.first_name} {selectedBeneficiary?.last_name}</strong><small>{destinationCountry==='CU'?'Cuba':'Rusia'} · {selectedBeneficiary?.phone_e164||selectedBeneficiary?.email||'Contacto guardado'}</small></div><span className="status-pill status-success">Destinatario seleccionado</span></div>
        <div className="wizard-delivery-card"><span className="section-icon"><Icon name="transfer"/></span><div><strong>{t('payout.'+method)}</strong><small>Se utilizarán los datos de entrega guardados en la ficha del beneficiario.</small></div><button type="button" className="text-button" onClick={()=>setStep(0)}>Cambiar</button></div>
        <div className="quote-amounts wizard-amounts"><div className="quote-total quote-total-receive"><span>{t('quote.beneficiaryReceives')}</span><strong>{quote.receive_amount} <small>{quote.receive_currency}</small></strong></div><div className="quote-total quote-total-pay"><span>Total que pagas</span><strong>{quote.total_debit_amount} <small>{quote.send_currency}</small></strong><small>{quote.fee_mode==='deduct_from_receive'?'La comisión se descuenta del importe recibido.':'La comisión se añade al pago del remitente.'}</small></div></div>
        <div className="quote-breakdown"><div><span>{t('quote.amountSent')}</span><strong>{quote.send_amount} {quote.send_currency}</strong></div><div><span>{t('quote.exchangeRate')}</span><strong>{quote.exchange_rate} {quote.receive_currency} / {quote.send_currency}</strong></div><div><span>{t('quote.fee')}</span><strong>{quote.fee_amount} {quote.fee_currency||(quote.fee_mode==='deduct_from_receive'?quote.receive_currency:quote.send_currency)}</strong></div><div><span>{t('quote.rateSource')}</span><strong>{quote.exchange_rate_source}</strong></div><div><span>{t('quote.validity')}</span><strong>{quote.exchange_rate_effective_date}</strong></div><div><span>{t('quote.expires')}</span><strong>{new Date(quote.expires_at).toLocaleString(locale)}</strong></div></div>
        {quote.limits && <div className="quote-breakdown quote-limits"><div><span>Límites de envío (principal en RUB)</span><strong>{quote.limits.minimum}–{quote.limits.maximum} RUB</strong></div><div><span>Importe evaluado en RUB</span><strong>{quote.limits.evaluated_amount} RUB</strong></div></div>}
        <p className="muted">Si cambias el importe, la dirección, el método de entrega o la modalidad de comisión, será necesario recalcular la cotización.</p>
      </div>}
      {step===3 && quote && <div className="wizard-panel">
        <div className="wizard-confirm-banner"><Icon name="shield"/><div><strong>Revisa antes de confirmar</strong><small>La remesa todavía no se ha creado. Se generará al pulsar el botón de confirmación.</small></div></div>
        <div className="wizard-confirm-grid"><div><small>Dirección</small><strong>{direction==='RU-CU'?'🇷🇺 Rusia → 🇨🇺 Cuba':'🇨🇺 Cuba → 🇷🇺 Rusia'}</strong></div><div><small>Beneficiario</small><strong>{selectedBeneficiary?.first_name} {selectedBeneficiary?.last_name}</strong></div><div><small>Método de entrega</small><strong>{t('payout.'+method)}</strong></div><div><small>Modalidad de comisión</small><strong>{quote.fee_mode==='deduct_from_receive'?'Descontada del beneficiario':'Añadida al pago'}</strong></div></div>
        <div className="quote-amounts wizard-amounts"><div className="quote-total quote-total-receive"><span>{t('quote.beneficiaryReceives')}</span><strong>{quote.receive_amount} <small>{quote.receive_currency}</small></strong></div><div className="quote-total quote-total-pay"><span>Total que pagas</span><strong>{quote.total_debit_amount} <small>{quote.send_currency}</small></strong></div></div>
        <div className="quote-breakdown"><div><span>{t('quote.fee')}</span><strong>{quote.fee_amount} {quote.fee_currency||quote.send_currency}</strong></div><div><span>{t('quote.exchangeRate')}</span><strong>{quote.exchange_rate}</strong></div><div><span>{t('quote.expires')}</span><strong>{new Date(quote.expires_at).toLocaleString(locale)}</strong></div></div>
        {quote.limits && <div className="quote-breakdown quote-limits"><div><span>Límites de envío (principal en RUB)</span><strong>{quote.limits.minimum}–{quote.limits.maximum} RUB</strong></div><div><span>Importe evaluado en RUB</span><strong>{quote.limits.evaluated_amount} RUB</strong></div></div>}
      </div>}
      {wizardError && <p className="field-error-message wizard-error" role="alert">{wizardError}</p>}
      <div className="wizard-actions">
        <button type="button" className="secondary" disabled={step===0} onClick={()=>{setWizardError('');setStep(current=>Math.max(0,current-1));}}>{t('common.back')||'Atrás'}</button>
        {step<3 ? <button type="button" disabled={step===0&&!beneficiaryId} onClick={goNext}>{step===1?'Calcular y continuar':step===2?'Continuar':'Continuar'} <span aria-hidden="true">→</span></button> :
          <button type="button" disabled={!quote} onClick={()=>void run(async()=>{if(!quote) return;const remittance=await api.createRemittance(userId,{quote_public_id:quote.public_id,beneficiary_public_id:beneficiaryId});setCreated(remittance);setQuote(null);setStep(0);})}>Confirmar y crear remesa <span aria-hidden="true">✓</span></button>}
      </div>
    </section>
    <aside className="quote-side wizard-side"><div className="quote-side-head"><span className="eyebrow">{t('quote.yourTransfer')}</span><h3>{sendCurrency} <span>→</span> {receiveCurrency}</h3><p>{direction==='RU-CU'?'Rusia → Cuba':'Cuba → Rusia'}</p></div><div className="quote-side-route"><span className="flag-bubble"><CountryFlag country={sourceCountry}/></span><div><strong>{sourceCountry==='RU'?'Rusia':'Cuba'}</strong><small>Origen del envío</small></div><span className="route-line"/><span className="flag-bubble"><CountryFlag country={destinationCountry}/></span><div><strong>{destinationCountry==='RU'?'Rusia':'Cuba'}</strong><small>Destino de entrega</small></div></div>{quote&&<div className="wizard-side-amount"><small>Recibe el beneficiario</small><strong>{quote.receive_amount} {quote.receive_currency}</strong><small>Total que pagas</small><strong>{quote.total_debit_amount} {quote.send_currency}</strong></div>}<div className="quote-side-foot"><Icon name="lock"/> {t('quote.protectedData')}</div></aside>
    {missingDetails && <div className="details-modal-backdrop"><section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="missing-payout-title"><div className="section-heading"><div><span className="eyebrow">Datos del beneficiario</span><h3 id="missing-payout-title">Completa los datos de entrega</h3></div><button type="button" className="secondary compact-button" onClick={()=>setMissingDetails(false)}>{t('common.close')}</button></div><p className="muted">El beneficiario no tiene registrados todos los datos necesarios para este método. Se guardarán cifrados en su ficha para futuras operaciones.</p><form className="modern-form" onSubmit={event=>{event.preventDefault();const errors:Record<string,string>={};if(method==='bank_account'&&!bankProvider.trim())errors.bank_provider='Indica el banco del beneficiario.';if(method==='bank_account'&&accountNumber.replace(/\D/g,'').length<6)errors.account_number='La cuenta debe contener al menos 6 dígitos.';if(method==='card_transfer'&&!isValidCardNumber(cardNumber))errors.card_number='Introduce un número de tarjeta válido (12–19 dígitos y checksum correcto).';setModalErrors(errors);if(Object.keys(errors).length)return;void run(async()=>{const selected=beneficiaries.find(item=>item.public_id===beneficiaryId);if(!selected)throw new Error('Beneficiario no encontrado');await api.updateBeneficiary(userId,selected.public_id,{country_code:selected.country_code,first_name:selected.first_name,last_name:selected.last_name,phone_e164:selected.phone_e164,email:selected.email,payout_method_code:method,payout_provider:method==='bank_account'?bankProvider:selected.payout_provider,...(method==='bank_account'?{payout_account_number:accountNumber.replace(/\D/g,'')}:{payout_card_number:cardNumber.replace(/\D/g,'')})});setBeneficiaries(await api.beneficiaries(userId));setMissingDetails(false);const result=await api.quote(userId,{source_country_code:sourceCountry,destination_country_code:destinationCountry,send_amount:pendingAmount.replace(',', '.'),send_currency:sendCurrency,receive_currency:receiveCurrency,payout_method_code:method,fee_mode:feeMode,beneficiary_public_id:beneficiaryId});setQuote(result);setStep(2);setAccountNumber('');setCardNumber('');});}}><div className="form-grid">{method==='bank_account'&&<><label>{t('field.bankProvider')}<BankSelect banks={BANKS_BY_COUNTRY[destinationCountry]} value={bankProvider} valueKey="name" name="bank_provider" onChange={(val)=>setBankProvider(val)} placeholder="Selecciona un banco" allowEmptyOption emptyLabel="Selecciona un banco" error={modalErrors.bank_provider} />{modalErrors.bank_provider && <span className="field-error-message" role="alert">{modalErrors.bank_provider}</span>}</label><Field name="account_number" label={t('field.accountNumberKeep')} error={modalErrors.account_number} value={accountNumber} mask="account" placeholder="0000 0000 0000 0000" onChange={(event:ChangeEvent<HTMLInputElement>)=>setAccountNumber(event.target.value)} inputMode="numeric" pattern="[0-9 ]{6,42}"/></>}{method==='card_transfer'&&<Field name="card_number" label={t('field.cardNumberKeep')} error={modalErrors.card_number} value={cardNumber} mask="card" placeholder="0000 0000 0000 0000" onChange={(event:ChangeEvent<HTMLInputElement>)=>setCardNumber(event.target.value)} inputMode="numeric" pattern="[0-9 ]{12,23}"/>}</div><div className="form-actions"><button disabled={method==='bank_account'?!accountNumber||!bankProvider:!cardNumber}>Guardar y continuar</button><button type="button" className="secondary" onClick={()=>setMissingDetails(false)}>{t('common.cancel')}</button></div></form></section></div>}
    {created&&<PaymentQr remittance={created}/>}
  </div>;
}
function Remittances({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const t = useT();
  const locale = localeFor(useLanguage());
  const [items, setItems] = useState<Remittance[]>([]);
  const [selected, setSelected] = useState<RemittanceDetail | null>(null);
  const [filter,setFilter]=useState('all');
  const [payingFor,setPayingFor]=useState<Remittance|null>(null);
  const [fundingMethods,setFundingMethods]=useState<FundingMethod[]>([]);
  const [loadingMethods,setLoadingMethods]=useState(false);
  const refresh = () => run(async () => setItems(await api.remittances(userId)));
  useEffect(() => { refresh(); }, [userId]);
  useEffect(() => {
    if (!payingFor) { setFundingMethods([]); return; }
    const country = payingFor.send_currency.toUpperCase() === 'CUP' ? 'CU' : 'RU';
    setLoadingMethods(true);
    void run(async () => {
      const result = await api.fundingMethods(userId,country);
      setFundingMethods(result.methods);
    }).finally(() => setLoadingMethods(false));
  }, [payingFor?.public_id,userId]);
  const visible=items.filter(item=>filter==='all'||(filter==='active'&&!['completed','cancelled','rejected'].includes(item.status))||(filter==='completed'&&item.status==='completed'));
  const startPayment = (item:Remittance) => setPayingFor(item);
  const executeDeposit = async (method:FundingMethod, options?: { payeePhoneE164?: string; purpose?: string }) => {
    const target = payingFor;
    if(!target) return;
    try {
      await api.deposit(userId,target.public_id,method.code,options);
    } catch (error) {
      // 409: la remesa ya tiene una orden de pago generada. No se permite
      // emitir otra; se abre el detalle con la instrucción existente.
      if (error instanceof ApiError && error.status === 409) {
        notify('Esta remesa ya tiene una orden de pago. Se muestra la instrucción existente.', 'info');
        const detail=await api.remittance(userId,target.public_id);
        setSelected(detail);
        setPayingFor(null);
        setItems(await api.remittances(userId));
        return;
      }
      throw error;
    }
    const detail=await api.remittance(userId,target.public_id);
    setSelected(detail);
    setPayingFor(null);
    setItems(await api.remittances(userId));
  };
  const chooseMethod = (method:FundingMethod) => {
    if(!payingFor)return;
    void run(async()=>{
      await executeDeposit(method);
    });
  };
  return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('remittances.eyebrow')}</span><h2>{t('nav.remittances')}</h2><p className="muted">{t('remittances.description')}</p></div><span className="count-chip">{items.length} {t('common.operations')}</span></div>
  <section className="card remittances-card"><div className="filter-tabs" role="group" aria-label={t('remittances.filter')}>{[['all',t('common.all')],['active',t('common.inProgress')],['completed',t('common.completed')]].map(([value,label])=><button key={value} className={filter===value?'filter-tab active':'filter-tab'} onClick={()=>setFilter(value)}>{label}</button>)}</div>
  {visible.length===0?<div className="empty-state"><span className="empty-icon"><Icon name="history"/></span><strong>{t('remittances.empty')}</strong><p>{t('remittances.emptyDescription')}</p></div>:<div className="remittance-list">{visible.map(item=><article className="remittance-item" key={item.public_id}><span className="remittance-icon"><Icon name="transfer"/></span><div className="remittance-main"><strong>{item.reference}</strong><small>{item.created_at?new Date(item.created_at).toLocaleString(locale):'Fecha no disponible'}</small><span>{item.send_amount} {item.send_currency} <b>→</b> {item.receive_amount} {item.receive_currency}</span></div><div className="remittance-status"><StatusBadge status={item.status}/><button className="text-button" onClick={() => void run(async () => setSelected(await api.remittance(userId, item.public_id)))}>{t('common.viewDetails')} <span>→</span></button></div>
  <div className="remittance-operations">{item.status === 'awaiting_payment' && <button className="secondary compact-button" onClick={() => startPayment(item)}>{t('remittances.registerDeposit')}</button>}{item.status === 'compliance_review' && <button onClick={() => void run(async () => { await api.review(userId, item.public_id, 'approved'); refresh(); })}>{t('common.approve')} (sandbox)</button>}{item.status === 'approved' && <button onClick={() => void run(async () => { const payout = await api.payout(userId, item.public_id); await api.completePayout(userId, payout.public_id); refresh(); })}>{t('remittances.executePayout')} (sandbox)</button>}{item.status === 'reconciliation_pending' && <button className="secondary compact-button" onClick={() => void run(async () => { await api.reconcile(userId, item.public_id); refresh(); })}>{t('remittances.reconcile')}</button>}</div></article>)}</div>}</section>
  {payingFor && <div className="details-modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setPayingFor(null);}}><section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="funding-method-title"><div className="section-heading details-modal-heading"><div><span className="eyebrow">Segundo paso · Pago</span><h2 id="funding-method-title">Selecciona cómo pagar</h2><p className="muted">{payingFor.total_debit_amount ?? payingFor.send_amount} {payingFor.send_currency}</p></div><button className="secondary compact-button" onClick={()=>setPayingFor(null)}>Cerrar</button></div>{loadingMethods?<p className="muted">Cargando métodos disponibles…</p>:fundingMethods.length===0?<div className="alert">No hay métodos de pago habilitados para esta moneda. El administrador debe configurar una cuenta receptora SEDERG_PAY y activar el método correspondiente.</div>:<div className="payment-method-options">{fundingMethods.map(method=><button key={method.code} className="payment-method-option" type="button" onClick={()=>chooseMethod(method)}><strong>{method.name}</strong><small>{method.code==='ru_sbp_qr'?'SBP C2B · QR dinámico de sandbox':method.code==='ru_sbp_c2p'?'SBP C2P · QR persona a persona (sandbox)':method.method_type==='instant_qr'?'QR dinámico de sandbox':'Transferencia bancaria'} · {method.provider_code||'Canal manual'}</small><span>Seleccionar →</span></button>)}</div>}<p className="muted">El QR de sandbox sirve para probar la experiencia; no inicia una transferencia bancaria real ni confirma el ingreso. Solo se genera una orden de pago por remesa; si el depósito no se recibe en 6 horas, la remesa se cancelará automáticamente.</p></section></div>}
  {selected && <RemittanceDetails detail={selected} onClose={() => setSelected(null)} />}
  </div>;
}
function PaymentQr({ remittance }: { remittance: Remittance }) {
  const t = useT();
  const [src, setSrc] = useState('');
  const instructions = remittance.payment_instructions ?? {};
  useEffect(() => { setSrc(''); if (!remittance.payment_qr_payload) return; void QRCode.toDataURL(remittance.payment_qr_payload, { width: 220, margin: 2 }).then(setSrc); }, [remittance.payment_qr_payload]);
  return <div className="payment-panel payment-panel-modern"><div><span className="eyebrow">{t('payment.platformPayment')}</span><h3>{t('payment.transferCreated')}: {remittance.reference}</h3><p>Transfiere <strong>{instructions.amount ?? remittance.deposit_expected_amount ?? remittance.send_amount} {instructions.currency_code ?? remittance.deposit_currency ?? remittance.send_currency}</strong></p><div className="payment-bank-details"><p><span>Banco</span><strong>{instructions.bank_name ?? '—'}</strong></p><p><span>Titular</span><strong>{instructions.account_holder_name ?? 'SEDERG_PAY'}</strong></p><p><span>Cuenta</span><strong>{instructions.account_mask ?? '—'}</strong></p><p><span>BIC / código de ruta</span><strong>{instructions.routing_mask ?? '—'}</strong></p><p><span>Referencia obligatoria</span><strong>{instructions.reference ?? remittance.payment_reference ?? '—'}</strong></p></div>{remittance.payment_qr_payload && <p className="sandbox-note">{remittance.payment_qr_type === 'sbp_c2b_sandbox' ? 'QR SBP C2B de sandbox.' : remittance.payment_qr_type === 'sbp_c2p_sandbox' ? 'QR SBP C2P persona a persona (sandbox).' : 'QR de prueba SEDERG_PAY.'} No es pagable ni confirma el ingreso.</p>}{!remittance.payment_qr_payload && <p className="muted">Este método utiliza transferencia bancaria con referencia; no dispone de QR.</p>}</div>{src && <img className="payment-qr" src={src} alt={`QR de pago sandbox ${remittance.reference}`} />}</div>;
}
function RemittanceDetails({ detail, onClose }: { detail: RemittanceDetail; onClose: () => void }) {
  const t = useT();
  const locale = localeFor(useLanguage());
  const events = detail.state_events || [];
  const direction = (detail.direction || '').toLowerCase();
  const isCubaToRussia = direction === 'cu_to_ru' ||
    (!['ru_to_cu', 'cu_to_ru'].includes(direction) && detail.send_currency.toUpperCase() === 'CUP');
  const originCountry = isCubaToRussia ? 'CU' : 'RU';
  const destinationCountry = isCubaToRussia ? 'RU' : 'CU';
  const countryName = (code: string) => code === 'CU' ? t('country.cuba') : t('country.russia');
  const countryFlag = (code: string) => code === 'CU' ? 'CU' : 'RU';

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.body.style.overflow = 'hidden';
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
      previouslyFocused?.focus();
    };
  }, [onClose]);

  return <div className="details-modal-backdrop" onMouseDown={(event) => {
    if (event.target === event.currentTarget) onClose();
  }}>
    <section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="remittance-details-title" onKeyDown={(event) => {
      if (event.key !== 'Tab') return;
      const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
      if (focusable.length === 0) { event.preventDefault(); return; }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }}>
      <div className="section-heading details-modal-heading">
        <div><span className="eyebrow">{t('details.receipt')}</span><h2 id="remittance-details-title">{detail.reference}</h2><StatusBadge status={detail.status}/></div>
        <button className="secondary compact-button" type="button" autoFocus onClick={onClose}>{t('common.close')}</button>
      </div>
      {detail.status === 'cancelled' && detail.failure_reason_code === 'payment_window_expired' && <div className="alert" role="status">Esta remesa se canceló automáticamente porque no se recibió el depósito dentro de las 6 horas de la ventana de pago. Ya no es posible generar una nueva orden de pago para esta transferencia.</div>}
      <div className="remittance-direction" aria-label={`${countryName(originCountry)} → ${countryName(destinationCountry)}`}>
        <div className="remittance-country"><span className="remittance-country-flag" role="img" aria-label={countryName(originCountry)}><CountryFlag country={countryFlag(originCountry)} /></span><div><small>{t('details.senderSends')}</small><strong>{countryName(originCountry)}</strong></div></div>
        <span className="remittance-direction-arrow" aria-hidden="true">→</span>
        <div className="remittance-country"><span className="remittance-country-flag" role="img" aria-label={countryName(destinationCountry)}><CountryFlag country={countryFlag(destinationCountry)} /></span><div><small>{t('quote.beneficiaryReceives')}</small><strong>{countryName(destinationCountry)}</strong></div></div>
      </div>
      <div className="detail-highlight"><div><span>{t('quote.beneficiaryReceives')}</span><strong>{detail.receive_amount} <small>{detail.receive_currency}</small></strong></div><div><span>{t('details.senderSends')}</span><strong>{detail.send_amount} <small>{detail.send_currency}</small></strong></div></div>
      <div className="detail-grid">
        <Detail label={t('details.compliance')} value={detail.compliance_status || '—'} />
        <Detail label={t('quote.fee')} value={`${detail.fee_amount} ${detail.fee_currency}`} />
        <Detail label={t('details.totalDebit')} value={`${detail.total_debit_amount} ${detail.send_currency}`} />
        <Detail label={t('quote.exchangeRate')} value={`${detail.exchange_rate} ${detail.receive_currency} / ${detail.send_currency}`} />
        <Detail label={t('details.created')} value={detail.created_at ? new Date(detail.created_at).toLocaleString(locale) : '—'} />
        <Detail label={t('field.beneficiary')} value={`${detail.beneficiary_first_name} ${detail.beneficiary_last_name}`} />
        <Detail label={t('field.contact')} value={detail.beneficiary_phone || detail.beneficiary_email || '—'} />
        <Detail label={t('field.payoutMethod')} value={`${t('payout.' + detail.payout_method_code)}${detail.payout_provider ? ` · ${detail.payout_provider}` : ''}`} />
        <Detail label={t('details.accountCard')} value={detail.payout_account_last4 ? `Cuenta ****${detail.payout_account_last4}` : detail.payout_card_last4 ? `Tarjeta ****${detail.payout_card_last4}` : '—'} />
      </div>
      {detail.deposit_public_id && <PaymentQr remittance={detail} />}
      <div className="timeline-heading"><span className="eyebrow">{t('details.traceability')}</span><h3>{t('details.statusHistory')}</h3></div>
      {events.length === 0 ? <p className="muted">{t('details.noEvents')}</p> : <div className="timeline timeline-modern">{events.map((event) => <div className="timeline-item" key={`${event.to_status}-${event.created_at}`}><span className="timeline-dot"><Icon name="check"/></span><div><strong>{event.to_status}</strong><span>{event.created_at}</span><small>{event.reason}</small></div></div>)}</div>}
    </section>
  </div>;
}
function Detail({ label, value }: { label: string; value: string }) { return <div className="detail-item"><span>{label}</span><strong>{value}</strong></div>; }

function AdminOperations({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const t = useT();
  const locale = localeFor(useLanguage());
  const [items, setItems] = useState<AdminOperation[]>([]);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [searchUsers, setSearchUsers] = useState('');
  const [filterDirections, setFilterDirections] = useState('');
  const [filterStatuses, setFilterStatuses] = useState('');
  const [filterRubHistory, setFilterRubHistory] = useState('');
  const [filterCupHistory, setFilterCupHistory] = useState('');
  const [filterUsers, setFilterUsers] = useState('');
  const [filterAudit, setFilterAudit] = useState('');
  const [rateHistory, setRateHistory] = useState<AdminRate[]>([]);
  const [cupToRubHistory, setCupToRubHistory] = useState<AdminRate[]>([]);
  const [rubHistoryPage, setRubHistoryPage] = useState(1);
  const [cupHistoryPage, setCupHistoryPage] = useState(1);
  const [dashboard, setDashboard] = useState<AdminDashboard | null>(null);
  const [rate, setRate] = useState<ExchangeRate | null>(null);
  const [cupToRub, setCupToRub] = useState<ExchangeRate | null>(null);
  const [rateValue, setRateValue] = useState('');
  const [cupToRubValue, setCupToRubValue] = useState('');
  const [settings, setSettings] = useState<PlatformSetting[]>([]);
  const [settingsMessage, setSettingsMessage] = useState('');
  const [rateSourceBusy, setRateSourceBusy] = useState(false);
  const [cupToRubSourceBusy, setCupToRubSourceBusy] = useState(false);
  const [rateMessage, setRateMessage] = useState('');
  const [kycCases, setKycCases] = useState<any[]>([]);
  const [kycReason, setKycReason] = useState<Record<string,string>>({});
  useEffect(() => { void run(async () => setItems(await api.adminOperations(userId))); }, [userId]);
  useEffect(() => { void run(async () => { const [userResult, rubResult, cupResult, dashboardResult] = await Promise.all([api.adminUsers(userId), api.adminRateHistory(userId, 'RUB', 'CUP'), api.adminRateHistory(userId, 'CUP', 'RUB'), api.adminDashboard(userId)]); setUsers(userResult); setRateHistory(rubResult.rates); setCupToRubHistory(cupResult.rates); setDashboard(dashboardResult); }); }, [userId]);
  useEffect(() => { void run(async () => { const [r2c, c2r] = await Promise.all([api.currentExchangeRate('RUB', 'CUP'), api.currentExchangeRate('CUP', 'RUB')]); setRate(r2c); setRateValue(r2c.manual_override ? r2c.rate : ''); setCupToRub(c2r); setCupToRubValue(c2r.manual_override ? c2r.rate : ''); }); }, []);
  useEffect(() => { void run(async () => setSettings((await api.adminSettings(userId)).settings)); }, [userId]);
  useEffect(() => { void run(async () => setKycCases((await api.adminKyc(userId)).cases)); }, [userId]);
  const filteredUsers = users.filter(u => 
    u.email.toLowerCase().includes(searchUsers.toLowerCase()) || 
    (u.full_name && u.full_name.toLowerCase().includes(searchUsers.toLowerCase()))
  );
  const updateSearchUsers = (value: string) => setSearchUsers(value);
  const normalize = (value: string) => value.toLowerCase();
  const filteredDirections = dashboard ? dashboard.directions.filter((item) => !filterDirections.trim() || normalize(item.direction + ' ' + (item.currency ?? '')).includes(normalize(filterDirections))) : [];
  const filteredStatuses = dashboard ? dashboard.statuses.filter((item) => !filterStatuses.trim() || normalize(item.status + ' ' + (item.currency ?? '')).includes(normalize(filterStatuses))) : [];
  const filteredRubHistory = rateHistory.filter((item) => !filterRubHistory.trim() || normalize([item.rate, item.source, item.valid_from, item.valid_until ?? '', item.created_by_email ?? ''].join(' ')).includes(normalize(filterRubHistory)));
  const filteredCupHistory = cupToRubHistory.filter((item) => !filterCupHistory.trim() || normalize([item.rate, item.source, item.valid_from, item.valid_until ?? '', item.created_by_email ?? ''].join(' ')).includes(normalize(filterCupHistory)));
  const filteredUsersByColumn = filteredUsers.filter((item) => !filterUsers.trim() || normalize([item.email, item.full_name ?? '', item.role, item.status].join(' ')).includes(normalize(filterUsers)));
  const filteredAudit = items.filter((item) => !filterAudit.trim() || normalize([item.reference, item.customer_name ?? '', item.customer_email ?? '', item.status, item.direction].join(' ')).includes(normalize(filterAudit)));
  const rubPage = Math.max(1, Math.min(rubHistoryPage, Math.max(1, Math.ceil(filteredRubHistory.length / 10))));
  const rubRows = filteredRubHistory.slice((rubPage - 1) * 10, rubPage * 10);
  const cupPage = Math.max(1, Math.min(cupHistoryPage, Math.max(1, Math.ceil(filteredCupHistory.length / 10))));
  const cupRows = filteredCupHistory.slice((cupPage - 1) * 10, cupPage * 10);
  const [showUserDetail, setShowUserDetail] = useState(false);
  const [currentUserDetail, setCurrentUserDetail] = useState<AdminUser | null>(null);
  const updateSetting = (key: string, value: string | number | boolean) => setSettings((current) => current.map((setting) => setting.key === key ? { ...setting, value } : setting));
  const settingsByCategory = (category: PlatformSetting['category']) => settings.filter((setting) => setting.category === category);
  const refreshBothRates = async () => {
    const [r2c, c2r] = await Promise.all([api.currentExchangeRate('RUB', 'CUP'), api.currentExchangeRate('CUP', 'RUB')]);
    setRate(r2c); setCupToRub(c2r);
    setRateValue((v) => r2c.manual_override ? r2c.rate : v);
    setCupToRubValue((v) => c2r.manual_override ? c2r.rate : v);
    try { localStorage.removeItem('sederg_rate_both_last_valid'); } catch {}
    window.dispatchEvent(new Event('sederg:rates-updated'));
    return { r2c, c2r };
  };
  const saveSettings = () => void run(async () => {
    // El tema visual se gestiona en su propia sección para no sobrescribirlo.
    const values = Object.fromEntries(settings.filter((setting) => setting.key !== THEME_SETTING_KEY).map((setting) => [setting.key, setting.value]));
    const response = await api.updateAdminSettings(userId, values);
    setSettings(response.settings);
    setSettingsMessage(t('admin.settingsSaved'));
    await refreshBothRates().catch(() => undefined);
  });
  return <><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.controlCenter')}</div><h2>{t('admin.dashboard')}</h2></div><span className="badge">{t('admin.adminOnly')}</span></div>{dashboard && <><div className="stats"><Stat label={t("admin.users")} value={String(dashboard.total_users)} /><Stat label={t("nav.remittances")} value={String(dashboard.total_remittances)} /><Stat label={t("admin.waiting")} value={String(dashboard.statuses.filter((item) => !['completed', 'cancelled'].includes(item.status)).reduce((sum, item) => sum + Number(item.operations), 0))} /></div><div className="search-users"><input inputMode="text" value={searchUsers} onChange={(e) => updateSearchUsers(e.target.value)} placeholder={t('admin.searchUsers')} className="admin-search-input" /></div><h3>{t('admin.transferredByDirection')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.direction')}</th><th>{t('admin.operations')}</th><th>{t('admin.sent')}</th><th>{t('admin.received')}</th></tr><tr className="filter-row"><th><ColumnFilterInput value={filterDirections} onChange={setFilterDirections} label={t('admin.direction')} /></th><th /><th /><th /></tr></thead><tbody>{filteredDirections.map((item) => <tr key={`${item.direction}-${item.currency}`}><td>{t('direction.'+item.direction) === 'direction.'+item.direction ? item.direction : t('direction.'+item.direction)}</td><td>{item.operations}</td><td>{item.sent_amount} {item.currency}</td><td>{item.received_amount} {item.receive_currency}</td></tr>)}{filteredDirections.length === 0 && <tr><td colSpan={4}><em>{t('admin.noFilterResults')}</em></td></tr>}</tbody></table></div><h3>{t('admin.amountsByStatus')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.status')}</th><th>{t('admin.operations')}</th><th>{t('admin.amountInFlow')}</th></tr><tr className="filter-row"><th><ColumnFilterInput value={filterStatuses} onChange={setFilterStatuses} label={t('admin.status')} /></th><th /><th /></tr></thead><tbody>{filteredStatuses.map((item) => <tr key={`${item.status}-${item.currency}`}><td><span className="badge">{item.status}</span></td><td>{item.operations}</td><td>{item.send_amount} {item.currency}</td></tr>)}{filteredStatuses.length === 0 && <tr><td colSpan={3}><em>{t('admin.noFilterResults')}</em></td></tr>}</tbody></table></div></>}</section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.controlCenter')}</div><h2>{t('admin.platformSettings')}</h2></div></div><p className="muted">{t('admin.settingsDescription')}</p><ButtonModeToggle />{settings.length === 0 ? <p className="muted">{t('common.loading')}</p> : <div className="settings-grid"><SettingsGroup title={t('admin.pricing')} settings={settingsByCategory('pricing')} onChange={updateSetting} /><SettingsGroup title={t('admin.operations')} settings={settingsByCategory('operations')} onChange={updateSetting} /><SettingsGroup title={t('admin.security')} settings={settingsByCategory('security')} onChange={updateSetting} /></div>}<div className="settings-actions"><button onClick={saveSettings} disabled={settings.length === 0}>{t('admin.saveSettings')}</button>{settingsMessage && <span className="success-inline">{settingsMessage}</span>}</div></section><AdminThemes userId={userId} /><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.marketControl')}</div><h2>{t('admin.rateRubCup')}</h2></div>{rate && <span className="badge">{rate.manual_override ? t('admin.manual') : t('admin.official')}</span>}</div>{rate && <p className="muted">{t('admin.current')}: <strong>{rate.rate} CUP por RUB</strong> · {rate.source_label} · fecha {rate.effective_date}</p>}<label>{t('admin.activeRateSource')}<select value={rate?.manual_override ? 'admin_override' : 'official'} disabled={rateSourceBusy || !rate} onChange={(event) => { const source = event.target.value as 'official' | 'admin_override'; setRateSourceBusy(true); setRateMessage(''); void (async () => { try { await api.setExchangeRateSource(userId, source, 'RUB', 'CUP'); await refreshBothRates(); setRateHistory((await api.adminRateHistory(userId, 'RUB', 'CUP')).rates); setRateMessage(t('admin.settingsSaved')); } catch (e: any) { setRateMessage(e?.message || t('common.error')); await refreshBothRates().catch(() => undefined); } finally { setRateSourceBusy(false); } })(); }}><option value="official">{t('admin.officialRate')}</option><option value="admin_override">{t('admin.adminRate')}</option></select></label><p className="muted">{t('admin.rateSourceHelp')}</p><form onSubmit={(event) => { event.preventDefault(); void run(async () => { setRateSourceBusy(true); try { await api.setExchangeRate(userId, rateValue, 'RUB', 'CUP'); await refreshBothRates(); setRateValue(rateValue); setRateHistory((await api.adminRateHistory(userId, 'RUB', 'CUP')).rates); setRubHistoryPage(1); setRateMessage(t('admin.settingsSaved')); } catch (e: any) { setRateMessage(e?.message || t('common.error')); } finally { setRateSourceBusy(false); } }); }}><Field name="admin_rate" label={t("admin.defineManualRate")} value={rateValue} onChange={(event) => setRateValue(event.target.value)} placeholder="0.0000000000" /><button disabled={!rateValue}>{t('admin.saveAdminRate')}</button></form><h3>{t('admin.rateHistory')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.rate')}</th><th>{t('admin.source')}</th><th>{t('admin.validity')}</th><th>{t('admin.createdBy')}</th></tr><tr className="filter-row"><th><ColumnFilterInput value={filterRubHistory} onChange={setFilterRubHistory} label={t('admin.rateHistory')} /></th><th /><th /><th /></tr></thead><tbody>{rubRows.map((item) => <tr key={`${item.valid_from}-${item.source}-${item.rate}`}><td>{item.rate} CUP/RUB</td><td>{item.source}</td><td>{item.valid_from} - {item.valid_until || t('admin.active')}</td><td>{item.created_by_email || t('admin.officialSystem')}</td></tr>)}{rubRows.length === 0 && <tr><td colSpan={4}><em>{t('admin.noFilterResults')}</em></td></tr>}</tbody></table></div>{filteredRubHistory.length > 10 && <div className="pager"><button type="button" className="secondary" disabled={rubPage <= 1} onClick={() => setRubHistoryPage((p) => Math.max(1, p - 1))}>{t('common.prev')}</button><span>{t('common.page')} {rubPage} / {Math.ceil(filteredRubHistory.length / 10)}</span><button type="button" className="secondary" disabled={rubPage >= Math.ceil(filteredRubHistory.length / 10)} onClick={() => setRubHistoryPage((p) => Math.min(Math.ceil(filteredRubHistory.length / 10), p + 1))}>{t('common.next')}</button></div>}<p className="muted">{t('admin.rateFallback')}</p></section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.marketControl')}</div><h2>{t('admin.rateCupRub')}</h2></div>{cupToRub && <span className="badge">{cupToRub.manual_override ? t('admin.manual') : t('admin.official')}</span>}</div>{cupToRub && <p className="muted">{t('admin.current')}: <strong>{cupToRub.rate} RUB por CUP</strong> · {cupToRub.source_label} · {t('admin.validity')}: {cupToRub.effective_date}</p>}<label>{t('admin.activeRateSource')}<select value={cupToRub?.manual_override ? 'admin_override' : 'official'} disabled={cupToRubSourceBusy || !cupToRub} onChange={(event) => { const source = event.target.value as 'official' | 'admin_override'; setCupToRubSourceBusy(true); setRateMessage(''); void (async () => { try { await api.setExchangeRateSource(userId, source, 'CUP', 'RUB'); await refreshBothRates(); setCupToRubHistory((await api.adminRateHistory(userId, 'CUP', 'RUB')).rates); setRateMessage(t('admin.settingsSaved')); } catch (e: any) { setRateMessage(e?.message || t('common.error')); await refreshBothRates().catch(() => undefined); } finally { setCupToRubSourceBusy(false); } })(); }}><option value="official">{t('admin.officialRate')}</option><option value="admin_override">{t('admin.adminRate')}</option></select></label><p className="muted">{t('admin.rateSourceHelp')}</p><form onSubmit={(event) => { event.preventDefault(); void run(async () => { setCupToRubSourceBusy(true); try { await api.setExchangeRate(userId, cupToRubValue, 'CUP', 'RUB'); await refreshBothRates(); setCupToRubValue(cupToRubValue); setCupToRubHistory((await api.adminRateHistory(userId, 'CUP', 'RUB')).rates); setCupHistoryPage(1); setRateMessage(t('admin.settingsSaved')); } catch (e: any) { setRateMessage(e?.message || t('common.error')); } finally { setCupToRubSourceBusy(false); } }); }}><Field name="admin_rate_cup_rub" label={t("admin.defineManualRateCupRub")} value={cupToRubValue} onChange={(event) => setCupToRubValue(event.target.value)} placeholder="0.0000000000" /><button disabled={!cupToRubValue || cupToRubSourceBusy}>{cupToRubSourceBusy ? 'Guardando…' : t('admin.saveAdminRate')}</button>{rateMessage && <span className="success-inline">{rateMessage}</span>}</form><h3>{t('admin.rateHistoryCupRub')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.rate')}</th><th>{t('admin.source')}</th><th>{t('admin.validity')}</th><th>{t('admin.createdBy')}</th></tr><tr className="filter-row"><th><ColumnFilterInput value={filterCupHistory} onChange={setFilterCupHistory} label={t('admin.rateHistoryCupRub')} /></th><th /><th /><th /></tr></thead><tbody>{cupRows.map((item) => <tr key={`${item.valid_from}-${item.source}-${item.rate}`}><td>{item.rate} RUB por CUP</td><td>{item.source}</td><td>{item.valid_from} {item.valid_until ? 'hasta ' + item.valid_until : t('admin.active')}</td><td>{item.created_by_email || t('admin.officialSystem')}</td></tr>)}{cupRows.length === 0 && <tr><td colSpan={4}><em>{t('admin.noFilterResults')}</em></td></tr>}</tbody></table></div>{filteredCupHistory.length > 10 && <div className="pager"><button type="button" className="secondary" disabled={cupPage <= 1} onClick={() => setCupHistoryPage((p) => Math.max(1, p - 1))}>{t('common.prev')}</button><span>{t('common.page')} {cupPage} / {Math.ceil(filteredCupHistory.length / 10)}</span><button type="button" className="secondary" disabled={cupPage >= Math.ceil(filteredCupHistory.length / 10)} onClick={() => setCupHistoryPage((p) => Math.min(Math.ceil(filteredCupHistory.length / 10), p + 1))}>{t('common.next')}</button></div>}<p className="muted">{t('admin.rateFallback')}</p></section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.users')}</div><h2>{t('admin.registeredUsers')}</h2></div><span className="badge">{users.length}</span></div><div className="table-wrap"><table><thead><tr><th>{t('admin.user')}</th><th>{t('admin.roleStatus')}</th><th>{t('admin.verification')}</th><th>{t('nav.remittances')}</th><th>{t('admin.createdAt')}</th><th>{t('admin.actions')}</th></tr><tr className="filter-row"><th><ColumnFilterInput value={filterUsers} onChange={setFilterUsers} label={t('admin.user')} /></th><th /><th /><th /><th /><th /></tr></thead><tbody>{filteredUsersByColumn.length === 0 ? <tr><td colSpan={6}><em>{t('admin.noUsersFound')}</em></td></tr> : filteredUsersByColumn.map((item) => <tr key={item.public_id}><td><strong>{item.full_name || t('admin.noName')}</strong><small>{item.email}</small></td><td>{t('role.'+item.role)}<small>{t('status.'+item.status)}</small></td><td>{item.email_verified_at || t('status.pending')}</td><td>{item.remittance_count}</td><td>{item.created_at}</td><td><div className="quick-actions"><ActionButton icon="user" label={t('common.viewDetails')} onClick={() => { setCurrentUserDetail(item); setShowUserDetail(true); }} /><ActionButton icon="power" className={item.status === 'active' ? 'danger' : 'secondary'} label={item.status === 'active' ? t('admin.deactivate') : t('admin.activate')} disabled={item.role === 'admin'} title={item.role === 'admin' ? t('admin.adminProtected') : undefined} onClick={() => void run(async () => { const result = await api.adminUserToggle(userId, item.public_id); setUsers((current) => current.map((u) => u.public_id === item.public_id ? { ...u, status: result.status as AdminUser['status'] } : u)); notify(t(result.status === 'active' ? 'toast.userActivated' : 'toast.userDeactivated'), 'success'); })} /></div></td></tr>)}</tbody></table></div></section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.compliance')}</div><h2>{t('admin.kycReview')}</h2></div><span className="badge">{kycCases.filter(c => c.status === 'pending_review').length} {t('admin.pendingCases')}</span></div>{kycCases.length === 0 ? <p className="muted">{t('admin.noKycCases')}</p> : <div className="list">{kycCases.map((item: any) => <div className="row" key={item.public_id}><div><strong>{item.first_name} {item.last_name} · {item.email}</strong><span>{t('admin.status')}: {t('status.'+item.status)} · {t('admin.documents')}: {item.document_count}</span><span>{item.submitted_at || t('admin.notSubmitted')} {item.review_reason || ''}</span><div className="quick-actions"><ActionButton icon="download" className="secondary" label={t('admin.downloadDocuments')} onClick={() => void run(async () => { const docs = (await api.adminKycDocuments(userId, item.public_id)).documents; if (docs.length === 0) { notify(t('admin.noKycDocuments'), 'info'); return; } const failures: string[] = []; for (const doc of docs) { try { const blob = await api.downloadKycDocument(userId, doc.public_id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = doc.original_name; link.click(); URL.revokeObjectURL(url); } catch (error) { failures.push(doc.original_name + ': ' + (error as Error).message); } } if (failures.length === 0) { notify(t('admin.documentsDownloaded'), 'success'); } else { notifyError(t('admin.documentsDownloadFailed') + ' ' + failures.join(' | ')); } })} />{item.status === 'pending_review' && <><input placeholder={t('admin.reason')} value={kycReason[item.public_id] || ''} onChange={e => setKycReason(v => ({...v, [item.public_id]: e.target.value}))}/><ActionButton icon="check" className="" label={t('common.approve')} onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'approved', ''); setKycCases((await api.adminKyc(userId)).cases); })} /><ActionButton icon="close" className="secondary" label={t('admin.reject')} onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'rejected', kycReason[item.public_id] || ''); setKycCases((await api.adminKyc(userId)).cases); })} /><ActionButton icon="info" className="secondary" label={t('admin.requestInformation')} onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'more_information', kycReason[item.public_id] || ''); setKycCases((await api.adminKyc(userId)).cases); })} /></>}</div></div></div>)}</div>}</section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.audit')}</div><h2>{t('admin.operationsPerformed')}</h2></div><span className="badge">{items.length} {t('admin.records')}</span></div>{items.length === 0 ? <p className="muted">{t('admin.noOperations')}</p> : <div className="table-wrap"><table><thead><tr><th>{t('admin.reference')}</th><th>{t('admin.customer')}</th><th>{t('admin.amount')}</th><th>{t('admin.status')}</th><th>{t('admin.date')}</th></tr><tr className="filter-row"><th><ColumnFilterInput value={filterAudit} onChange={setFilterAudit} label={t('admin.reference')} /></th><th /><th /><th /><th /></tr></thead><tbody>{filteredAudit.map((item) => <tr key={item.public_id}><td><strong>{item.reference}</strong><small>{t('direction.'+item.direction) === 'direction.'+item.direction ? item.direction : t('direction.'+item.direction)}</small></td><td>{item.customer_name || item.customer_email}<small>{item.customer_email}</small></td><td>{item.send_amount} {item.send_currency}<small>→ {item.receive_amount} {item.receive_currency}</small></td><td><span className="badge">{t('status.'+item.status)}</span><small>{t('status.'+item.compliance_status) === 'status.'+item.compliance_status ? item.compliance_status : t('status.'+item.compliance_status)}</small></td><td>{new Date(item.created_at).toLocaleString(locale)}</td></tr>)}{filteredAudit.length === 0 && <tr><td colSpan={5}><em>{t('admin.noFilterResults')}</em></td></tr>}</tbody></table></div>}</section>{showUserDetail && currentUserDetail && <UserDetailModal user={currentUserDetail} onClose={() => { setShowUserDetail(false); setCurrentUserDetail(null); }} />}</>;
}

// -----------------------------------------------------------------------------
// Administración · Temas de la plataforma
// El tema elegido aquí se guarda como ajuste global (`appearance.theme`) y se
// aplica a toda la plataforma (landing, panel del cliente y administración).
// La vista previa se aplica en vivo, sin guardar, hasta confirmar.
// -----------------------------------------------------------------------------
function AdminThemes({ userId }: { userId: string }) {
  const t = useT();
  const [saved, setSaved] = useState<ThemeId | null>(null);
  const [preview, setPreview] = useState<ThemeId | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let active = true;
    api.adminSettings(userId)
      .then((response) => {
        if (!active) return;
        const setting = response.settings.find((item) => item.key === THEME_SETTING_KEY);
        const current = normalizeTheme(setting?.value);
        setSaved(current);
        setPreview(current);
      })
      .catch(() => undefined);
    return () => { active = false; };
  }, [userId]);

  const active = preview ?? saved;
  const choose = (id: ThemeId) => {
    setPreview(id);
    applyTheme(id);
  };
  const reset = () => {
    const next = saved ?? getInitialTheme();
    setPreview(saved);
    applyTheme(next);
  };
  const save = () => void (async () => {
    if (!preview) return;
    setBusy(true);
    try {
      const response = await api.updateAdminSettings(userId, { [THEME_SETTING_KEY]: preview });
      const setting = response.settings.find((item) => item.key === THEME_SETTING_KEY);
      const stored = normalizeTheme(setting?.value) ?? preview;
      setSaved(stored);
      setPreview(stored);
      applyTheme(stored, { persist: 'platform' });
      notifySuccess(t('admin.themeSaved'));
    } catch (error) {
      notifyError((error as Error).message || t('common.error'));
    } finally {
      setBusy(false);
    }
  })();

  return <section className="card">
    <div className="section-heading">
      <div><div className="eyebrow">{t('admin.appearance')}</div><h2>{t('admin.themes')}</h2></div>
      <span className="badge">{active ? t(themeById(active).labelKey) : t('common.loading')}</span>
    </div>
    <p className="muted">{t('admin.themeHint')}</p>
    <div className="theme-grid" role="radiogroup" aria-label={t('admin.themes')}>
      {THEMES.map((option) => {
        const selected = active === option.id;
        return <button
          key={option.id}
          type="button"
          role="radio"
          aria-checked={selected}
          className={selected ? 'theme-card is-selected' : 'theme-card'}
          onClick={() => choose(option.id)}
        >
          <span className={`theme-preview ${option.id}`} aria-hidden="true">
            <span className="theme-preview-bar" />
            <span className="theme-preview-card" />
            <span className="theme-preview-card small" />
          </span>
          <span className="theme-card-body">
            <strong>{t(option.labelKey)}</strong>
            <small>{t(option.taglineKey)}</small>
            <span className="theme-swatches" aria-hidden="true">{option.swatches.map((color) => <i key={color} style={{ background: color }} />)}</span>
          </span>
          {selected && <span className="theme-check" aria-hidden="true">✓</span>}
        </button>;
      })}
    </div>
    <div className="settings-actions">
      <button type="button" onClick={save} disabled={busy || !preview || preview === saved}>{busy ? t('common.saving') : t('admin.themeSave')}</button>
      <button type="button" className="secondary" onClick={reset} disabled={busy || !preview || preview === saved}>{t('admin.themeReset')}</button>
    </div>
    <p className="muted theme-note">{t('admin.themeNote')}</p>
  </section>;
}

function SettingsGroup({ title, settings, onChange }: { title: string; settings: PlatformSetting[]; onChange: (key: string, value: string | number | boolean) => void }) {
  const t = useT();
  return <section className="settings-group"><h3>{title}</h3>{settings.map((setting) => <label key={setting.key}>{setting.label}{setting.type === 'boolean' ? <span className="toggle-row"><input type="checkbox" checked={Boolean(setting.value)} onChange={(event) => onChange(setting.key, event.target.checked)} /><span>{setting.value ? t('admin.enabled') : t('admin.disabled')}</span></span> : setting.type === 'enum' ? <select value={String(setting.value)} onChange={(event) => onChange(setting.key, event.target.value)}>{(setting.options ?? []).map((option) => <option key={option} value={option}>{option === 'official' ? t('admin.officialRate') : option === 'admin_override' ? t('admin.adminRate') : option}</option>)}</select> : <input type={setting.type === 'integer' ? 'number' : 'text'} inputMode={setting.type === 'integer' ? 'numeric' : 'decimal'} value={String(setting.value)} onChange={(event) => onChange(setting.key, event.target.value)} />}</label>)}</section>;
}

function UserDetailModal({ user, onClose }: { user: AdminUser; onClose: () => void }): JSX.Element {
  const t = useT();

  return <div className="details-modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
    <section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="user-detail-title">
      <div className="section-heading details-modal-heading">
        <div>
          <span className="eyebrow">{t('admin.users')}</span>
          <h2 id="user-detail-title">{t('admin.userDetails')}</h2>
        </div>
        <button type="button" className="secondary compact-button" onClick={onClose}>{t('common.close')}</button>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('admin.email')}</span>
        <span className="detail-value">{user.email}</span>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('admin.fullName')}</span>
        <span className="detail-value">{user.full_name || t('admin.noName')}</span>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('admin.role')}</span>
        <span className="detail-value">{user.role === 'admin' ? t('role.admin') : t('role.customer')}</span>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('admin.status')}</span>
        <span className="detail-value">{user.status === 'active' ? t('status.active') : t('status.suspended')}</span>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('admin.lastLogin')}</span>
        <span className="detail-value">{user.last_login_at ? new Date(user.last_login_at).toLocaleString() : t('common.never')}</span>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('nav.remittances')}</span>
        <span className="detail-value">{user.remittance_count}</span>
      </div>
      <div className="detail-row">
        <span className="detail-label">{t('common.userId')}</span>
        <span className="detail-value">{user.public_id}</span>
      </div>
    </section>
  </div>;
}

function StatusBadge({status}:{status:string}) {
  const t = useT();
  const normalized=(status||'pending').toLowerCase();
  const tone=['completed','approved','active','verified','success','received'].includes(normalized)?'success':['rejected','failed','cancelled','error'].includes(normalized)?'danger':['pending_review','compliance_review','awaiting_payment','pending'].includes(normalized)?'warning':'info';
  return <span className={`status-pill status-${tone}`}>{t(`status.${normalized}`) === `status.${normalized}` ? status.replace(/_/g,' ') : t(`status.${normalized}`)}</span>;
}

function NavButton({ active, onClick, children, icon }: { active: boolean; onClick: () => void; children: React.ReactNode; icon?: string }) { return <button className={active ? 'nav-button active' : 'nav-button'} onClick={onClick}>{icon && <Icon name={icon} />}<span className="nav-button-label">{children}</span></button>; }
function Logo({ language, monochrome = false }: { language: Language; monochrome?: boolean }) {
  const slogan = translate(language, 'brand.slogan');
  // El repositorio solo publica los logotipos en SVG (no hay PNG), por eso se
  // usa el horizontal y, si falla, se cae al isotipo. La variante monocroma
  // se aplica con el filtro CSS de .logo-monochrome.
  const logoFile = "sederg-pay-horizontal.svg";
  const logoFallback = "sederg-pay-isotipo.svg";
  return <div className={monochrome ? 'logo logo-monochrome' : 'logo'} aria-label={`SEDERG_PAY — ${slogan}`}>
    <img
      className="logo-brand-image"
      src={import.meta.env.BASE_URL + "assets/sederg-pay/logos/" + logoFile}
      alt="SEDERG_PAY"
      onError={(event) => {
        const image = event.currentTarget;
        if (!image.dataset.fallback) {
          image.dataset.fallback = '1';
          image.src = import.meta.env.BASE_URL + "assets/sederg-pay/logos/" + logoFallback;
        }
      }}
    />
    <span className="sr-only">{slogan}</span>
  </div>;
}
function Brand({ language }: { language: Language }) { return <Logo language={language} />; }
function LanguageSwitch({ language, onChange }: { language: Language; onChange: (language: Language) => void }) {
  const t = useT();
  return <div className="language-switch" role="group" aria-label="Idioma">
    {(Object.keys(languageLabels) as Language[]).map((option) => <button key={option} type="button" className={language === option ? 'language-option active' : 'language-option'} onClick={() => onChange(option)} aria-pressed={language === option}>{languageLabels[option]}</button>)}
  </div>;
}
function ThemeToggle({ theme, onChange }: { theme: ThemeId; onChange: (theme: ThemeId) => void }) {
  const t = useT();
  const isDark = themeById(theme).mode === 'dark';
  const label = isDark ? t('common.themeLight') : t('common.themeDark');
  return <button type="button" className="theme-toggle" onClick={() => onChange(toggleMode(theme))} aria-label={t('common.themeToggle')} title={label} aria-pressed={isDark}><svg className="theme-toggle-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{isDark ? <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /> : <><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></>}</svg></button>;
}

function NotificationBell({ userId, unreadCount, onUnreadChange, onViewAll }: { userId: string; unreadCount: number; onUnreadChange: (n: number) => void; onViewAll: () => void }) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<AppNotification[]>([]);
  const [loading, setLoading] = useState(false);
  const containerRef = useRef<HTMLDivElement | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const data = await api.notifications(userId, 8);
      setItems(data.items || []);
      onUnreadChange(data.unread_count || 0);
    } catch {
      // ignore
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    void load();
  }, [open, userId]);

  useEffect(() => {
    if (!open) return;
    const onDoc = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) setOpen(false);
    };
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
  }, [open]);

  const markRead = async (id: string) => {
    try {
      await api.markNotificationRead(userId, id);
      await load();
    } catch {
      // ignore
    }
  };
  const markAll = async () => {
    try {
      await api.markAllNotificationsRead(userId);
      await load();
    } catch {
      // ignore
    }
  };

  return <div className="notification-bell" ref={containerRef}>
    <button type="button" className="notification-bell-button" aria-label={t('nav.notifications')} aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
      <Icon name="bell" />
      {unreadCount > 0 && <span className="notification-bell-badge" aria-hidden="true">{unreadCount > 99 ? '99+' : unreadCount}</span>}
    </button>
    {open && <div className="notification-dropdown" role="menu" aria-label={t('nav.notifications')}>
      <div className="notification-dropdown-head">
        <strong>{t('notifications.title')}</strong>
        <div className="notification-dropdown-actions">
          <button type="button" className="text-button" onClick={() => void markAll()} disabled={unreadCount === 0}>{t('notifications.markAllRead')}</button>
        </div>
      </div>
      <div className="notification-dropdown-body">
        {loading ? <p className="muted">{t('common.loading')}</p>
          : items.length === 0 ? <p className="muted">{t('notifications.empty')}</p>
          : items.map((item) => (
            <button key={item.public_id} type="button" className={`notification-dropdown-item ${item.is_read ? 'is-read' : 'is-unread'}`} onClick={() => { if (!item.is_read) void markRead(item.public_id); }}>
              <span className="notification-dropdown-title">{item.title}</span>
              <span className="notification-dropdown-text">{item.body}</span>
              <small>{new Date(item.created_at).toLocaleString()}</small>
            </button>
          ))}
      </div>
      <div className="notification-dropdown-foot">
        <button type="button" className="text-button" onClick={() => { setOpen(false); onViewAll(); }}>{t('notifications.viewAll')}</button>
      </div>
    </div>}
  </div>;
}

function Header({ language, onLanguageChange, theme, onThemeChange, notification, menuControl }: { language: Language; onLanguageChange: (language: Language) => void; theme: ThemeId; onThemeChange: (theme: ThemeId) => void; notification?: { userId: string; unreadCount: number; onUnreadChange: (n: number) => void; onViewAll: () => void }; menuControl?: { open: boolean; toggle: () => void } }) {
  const t = useT();
  return <header className={menuControl ? 'app-header has-menu-toggle' : 'app-header'}>
    <div className="header-start">
      {menuControl && <button type="button" className="menu-toggle header-menu-toggle" aria-label={menuControl.open ? t('nav.closeMenu') : t('nav.openMenu')} aria-expanded={menuControl.open} aria-controls="app-menu" onClick={menuControl.toggle}><span /><span /><span /></button>}
      <Brand language={language} />
    </div>
    <div className="header-tools">
      {notification && <NotificationBell userId={notification.userId} unreadCount={notification.unreadCount} onUnreadChange={notification.onUnreadChange} onViewAll={notification.onViewAll} />}
      <span className={menuControl ? 'header-appearance' : undefined}><ThemeToggle theme={theme} onChange={onThemeChange} /><LanguageSwitch language={language} onChange={onLanguageChange} /></span>
      <span className="sandbox"><i /> {translate(language, 'common.sandbox')}</span>
    </div>
  </header>;
}
function RateTicker() {
  const t = useT();
  const [rubToCup, setRubToCup] = useState<ExchangeRate | null>(null);
  const [cupToRub, setCupToRub] = useState<ExchangeRate | null>(null);
  const [error, setError] = useState('');
  useEffect(() => {
    const load = () => api.bothExchangeRates().then(([r2c, c2r]) => { setRubToCup(r2c); setCupToRub(c2r); setError(''); }).catch((reason: Error) => setError(reason.message));
    load();
    window.addEventListener('sederg:rates-updated', load);
    return () => window.removeEventListener('sederg:rates-updated', load);
  }, []);
  if (error) return <p className="alert">{t('rate.unavailable')}</p>;
  if (!rubToCup || !cupToRub) return <p className="muted">{t('rate.loading')}</p>;
  const groups = [0, 1].map((copy) => (
    <div className="rate-ticker-group" key={copy} aria-hidden={copy === 1}>
      <span className="rate-ticker-item"><strong>{t('rate.rubToCup')}: {rubToCup.rate} CUP por RUB</strong><span>{rubToCup.source_label} · {rubToCup.effective_date}</span></span>
      <span className="rate-ticker-item"><strong>{t('rate.cupToRub')}: {cupToRub.rate} RUB por CUP</strong><span>{cupToRub.source_label} · {cupToRub.effective_date}</span></span>
    </div>
  ));
  return <div className="rate-ticker" role="status"><div className="rate-ticker-track">{groups}</div></div>;
}
function Alert({ text }: { text: string }) { return <p className="alert">{text}</p>; }
function isValidCardNumber(value: string) {
  const digits = value.replace(/\D/g, '');
  if (!/^\d{12,19}$/.test(digits)) return false;
  let sum = 0;
  let alternate = false;
  for (let index = digits.length - 1; index >= 0; index--) {
    let digit = Number(digits[index]);
    if (alternate) { digit *= 2; if (digit > 9) digit -= 9; }
    sum += digit;
    alternate = !alternate;
  }
  return sum % 10 === 0;
}
function formatInputMask(value: string, mask?: 'phone' | 'card' | 'account') {
  if (!mask) return value;
  const digits = value.replace(/\D/g, '');
  if (mask === 'card') return digits.slice(0, 19).replace(/(.{4})/g, '$1 ').trim();
  if (mask === 'account') return digits.slice(0, 34).replace(/(.{4})/g, '$1 ').trim();
  const plus = value.trim().startsWith('+') ? '+' : '';
  return plus + digits.slice(0, 15).replace(/(\d{3})(?=\d)/g, '$1 ').trim();
}
function Field({ name, label, type = 'text', defaultValue, value, onChange, placeholder, inputMode, pattern, required = true, mask, error }: { name: string; label: string; type?: string; defaultValue?: string; value?: string; onChange?: (event: ChangeEvent<HTMLInputElement>) => void; placeholder?: string; inputMode?: 'text' | 'numeric' | 'tel' | 'email' | 'url' | 'search'; pattern?: string; required?: boolean; mask?: 'phone' | 'card' | 'account'; error?: string }) {
  const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
    if (mask) event.currentTarget.value = formatInputMask(event.currentTarget.value, mask);
    onChange?.(event);
  };
  return <label className={error ? 'field-error' : undefined}>{label}<input required={required} name={name} type={type} defaultValue={mask ? formatInputMask(defaultValue ?? '', mask) : defaultValue} value={value === undefined ? undefined : (mask ? formatInputMask(value, mask) : value)} onChange={handleChange} placeholder={placeholder} inputMode={inputMode} pattern={pattern} aria-invalid={!!error} aria-describedby={error ? name + '-error' : undefined} />{error && <small className="field-error-message" id={name + '-error'} role="alert">{error}</small>}</label>;
}
function Stat({ label, value }: { label: string; value: string }) { return <div className="stat"><span>{label}</span><strong>{value}</strong></div>; }


function FundingMethodConfiguration({ userId, method, run, onSaved }: { userId: string; method: FundingMethod; run: (action: () => Promise<void>) => Promise<void>; onSaved: () => void }) {
  const [editing, setEditing] = useState(false);
  const [payeePhone, setPayeePhone] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [purpose, setPurpose] = useState('Fondeo SEDERG_PAY');

  useEffect(() => {
    const cfg = (method.configuration ?? {}) as Record<string, any>;
    setPayeePhone(String(cfg.payee_phone ?? ''));
    setAccountNumber(String(cfg.account_number ?? ''));
    setPurpose(String(cfg.purpose ?? 'Fondeo SEDERG_PAY'));
  }, [method.code, method.configuration]);

  const save = () => void run(async () => {
    const base = (method.configuration ?? {}) as Record<string, any>;
    await api.updateFundingMethod(userId, method.code, Boolean(method.is_active), {
      ...base,
      payee_phone: payeePhone.trim(),
      account_number: accountNumber.trim(),
      purpose: purpose.trim(),
    });
    notifySuccess('Configuración guardada correctamente.');
    setEditing(false);
    onSaved();
  });

  const cfg = (method.configuration ?? {}) as Record<string, any>;
  const shownPhone = String(cfg.payee_phone ?? '').trim() || '—';
  const shownAccount = String(cfg.account_number ?? '').trim() || '—';
  const shownPurpose = String(cfg.purpose ?? 'Fondeo SEDERG_PAY').trim() || 'Fondeo SEDERG_PAY';

  return <section className="card profile-card">
    <div className="profile-header-card">
      <div className="profile-identity">
        <span className="eyebrow">Método de pago</span>
        <h3>{method.name}</h3>
        <p className="muted">Cuenta P2P · SBP QR persona a persona (sandbox)</p>
      </div>
      {!editing
        ? <button className="edit-button" type="button" onClick={() => setEditing(true)}><Icon name="edit"/> Editar</button>
        : <button className="edit-button secondary" type="button" onClick={() => setEditing(false)}><Icon name="arrow-left"/> Cancelar</button>}
    </div>
    {!editing ? (
      <div className="profile-cards-grid">
        <article className="profile-card-item">
          <div className="card-title"><Icon name="user"/><span>Cuenta beneficiaria P2P</span></div>
          <div className="card-body">
            <div className="profile-field"><span className="profile-field-label">Teléfono del beneficiario</span><span className="profile-field-value">{shownPhone}</span></div>
            <div className="profile-field"><span className="profile-field-label">Número de cuenta</span><span className="profile-field-value">{shownAccount}</span></div>
            <div className="profile-field"><span className="profile-field-label">Finalidad del pago</span><span className="profile-field-value">{shownPurpose}</span></div>
            <div className="profile-field"><span className="profile-field-label">Referencia de transferencia</span><span className="profile-field-value">Generada automáticamente por el sistema</span></div>
          </div>
        </article>
      </div>
    ) : (
      <form className="modern-form" onSubmit={(e) => { e.preventDefault(); save(); }}>
        <div className="form-grid">
          <label className="funding-full">Teléfono del beneficiario (dígitos)<input value={payeePhone} onChange={(event) => setPayeePhone(event.target.value)} placeholder="79001234567" inputMode="tel" /></label>
          <label className="funding-full">Número de cuenta del beneficiario<input value={accountNumber} onChange={(event) => setAccountNumber(event.target.value)} placeholder="Opcional" /></label>
          <label className="funding-full">Finalidad del pago<input value={purpose} onChange={(event) => setPurpose(event.target.value)} /></label>
          <label className="funding-full">Referencia de transferencia<input value="Generada automáticamente por el sistema" readOnly disabled /></label>
        </div>
        <div className="form-actions">
          <button type="button" className="secondary" onClick={() => setEditing(false)}>Cancelar</button>
          <button type="submit"><Icon name="check"/> Guardar configuración</button>
        </div>
      </form>
    )}
  </section>;
}

type BankInfo = { code: string; name: string; routing?: string; legalName?: string; source?: string; short?: string; color?: string; logoUrl?: string };
const BANKS_BY_COUNTRY: Record<'RU'|'CU', BankInfo[]> = {
  RU: [
    { code: 'sberbank', name: 'Sberbank (Сбербанк)', routing: '044525225 / SABRRUMM', legalName: 'Публичное акционерное общество «Сбербанк России» (ПАО Сбербанк)', source: 'CBR/SWIFT', short: 'Sber', color: '#21A038', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/sberbank.png` },
    { code: 'vtb', name: 'VTB (ВТБ)', routing: '044525187 / VTBRRUMM', legalName: 'Банк ВТБ (публичное акционерное общество)', source: 'CBR/SWIFT', short: 'VTB', color: '#009FDF', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/vtb.svg` },
    { code: 'gazprombank', name: 'Gazprombank (Газпромбанк)', routing: '044525823 / GAZPRUMM', legalName: 'Банк ГПБ (АО)', source: 'CBR/SWIFT', short: 'GPB', color: '#0057B8', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/gazprombank.svg` },
    { code: 'alfa', name: 'Alfa-Bank (Альфа-Банк)', routing: '044525593 / ALFARUMM', legalName: 'АО «Альфа-Банк»', source: 'CBR/SWIFT', short: 'A', color: '#EF3124', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/alfa.svg` },
    { code: 'tbank', name: 'T-Bank (Т-Банк, ex Tinkoff)', routing: '044525974 / TICSRUMM', legalName: 'АО «ТБанк»', source: 'CBR/SWIFT', short: 'T', color: '#FFDD2D', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/tbank.svg` },
    { code: 'rosselkhozbank', name: 'Rosselkhozbank (Россельхозбанк)', routing: '044525219 / RUAGRUMM', legalName: 'АО «Россельхозбанк»', source: 'CBR/SWIFT', short: 'РСХБ', color: '#2E7D32', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/rshb.svg` },
    { code: 'sovcombank', name: 'Sovcombank (Совкомбанк)', routing: '044525297 / SOMRRUMM', legalName: 'ПАО «Совкомбанк»', source: 'CBR/SWIFT', short: 'Сов', color: '#0033A0', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/sovcombank.svg` },
    { code: 'promsvyazbank', name: 'Promsvyazbank (Промсвязьбанк)', routing: '044525555 / PRMSRUMM', legalName: 'ПАО «Промсвязьбанк»', source: 'CBR/SWIFT', short: 'ПСБ', color: '#F36F21', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/psb.png` },
    { code: 'raiffeisen', name: 'Raiffeisen Bank (Райффайзен Банк)', routing: '044525700 / RZBMRUMM', legalName: 'АО «Райффайзенбанк»', source: 'CBR/SWIFT', short: 'R', color: '#FFED00', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/raiffeisen.svg` },
    { code: 'rosbank', name: 'Rosbank (Росбанк)', routing: '044525256 / RSBNRUMM', legalName: 'ПАО РОСБАНК', source: 'CBR/SWIFT', short: 'RB', color: '#E31E24', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/rosbank.svg` },
    { code: 'unicredit', name: 'UniCredit Bank (ЮниКредит Банк)', routing: '044525545 / IMBKRUMM', legalName: 'АО ЮниКредит Банк', source: 'CBR/SWIFT', short: 'UC', color: '#E2001A', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/unicredit.svg` },
    { code: 'mkb', name: 'Credit Bank of Moscow (МКБ)', routing: '044525659 / MCRBRUMM', legalName: 'ПАО «Московский кредитный банк»', source: 'CBR/SWIFT', short: 'МКБ', color: '#D4002A', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/mkb.png` },
    { code: 'domrf', name: 'DOM.RF Bank (Банк ДОМ.РФ)', routing: '044525154 / JMBRRUMM', legalName: 'АО «Банк ДОМ.РФ»', source: 'CBR/SWIFT', short: 'ДОМ', color: '#1C3F94', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/domrf.png` },
  ],
  CU: [
    { code: 'bpa', name: 'Banco Popular de Ahorro (BPA)', routing: 'BPAHCUHH', legalName: 'Banco Popular de Ahorro', source: 'BCC/SWIFT', short: 'BPA', color: '#006747' },
    { code: 'bandec', name: 'Banco de Crédito y Comercio (BANDEC)', routing: 'BDCRCUHH', legalName: 'Banco de Crédito y Comercio', source: 'BCC/SWIFT', short: 'BANDEC', color: '#005BAA' },
    { code: 'banmet', name: 'Banco Metropolitano (BANMET)', routing: 'BMNBCUHH', legalName: 'Banco Metropolitano S.A.', source: 'BCC/SWIFT', short: 'BM', color: '#8B1E3F' },
    { code: 'bfi', name: 'Banco Financiero Internacional (BFI)', routing: 'BFICCUHH', legalName: 'Banco Financiero Internacional S.A.', source: 'BCC/SWIFT', short: 'BFI', color: '#003366', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/bfi.png` },
    { code: 'bnc', name: 'Banco Nacional de Cuba (BNC)', routing: 'BNACCUHH', legalName: 'Banco Nacional de Cuba', source: 'BCC/SWIFT', short: 'BNC', color: '#002A8F', logoUrl: `${import.meta.env.BASE_URL}assets/sederg-pay/banks/bnc.png` },
    { code: 'bec', name: 'Banco Exterior de Cuba (BEC)', routing: 'BEACCUHH', legalName: 'Banco Exterior de Cuba', source: 'BCC/SWIFT', short: 'BEC', color: '#0F766E' },
    { code: 'bi', name: 'Banco de Inversiones (BI)', routing: 'BICVCUHH', legalName: 'Banco de Inversiones S.A.', source: 'BCC/SWIFT', short: 'BI', color: '#B45309' },
  ],
};



function BankLogo({ bank, size = 24 }: { bank: { short?: string; color?: string; name: string; code?: string; logoUrl?: string }; size?: number }) {
  if (bank.logoUrl) {
    return <span className="bank-logo bank-logo-image" style={{ width: size, height: size, minWidth: size }} aria-hidden="true"><img src={bank.logoUrl} alt="" style={{ width: '100%', height: '100%', objectFit: 'contain' }} /></span>;
  }
  const bg = bank.color || '#64748b';
  const text = (bank.short || bank.name || '?').slice(0, 4);
  const dark = ['#FFDD2D', '#FFED00'].includes(bg) ? '#1f2937' : '#ffffff';
  return <span className="bank-logo" style={{ width: size, height: size, minWidth: size, background: bg, color: dark }} aria-hidden="true">{text}</span>;
}

function BankSelect({
  banks,
  value,
  onChange,
  valueKey,
  placeholder,
  error,
  name,
  allowEmptyOption,
  emptyLabel,
}: {
  banks: BankInfo[];
  value: string;
  onChange: (value: string, bank?: BankInfo) => void;
  valueKey: 'code' | 'name';
  placeholder?: string;
  error?: string;
  name?: string;
  allowEmptyOption?: boolean;
  emptyLabel?: string;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement | null>(null);
  const selected = banks.find((b) => b[valueKey] === value);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey); };
  }, [open]);
  return <div className={`bank-select${open ? ' open' : ''}${error ? ' has-error' : ''}`} ref={ref}>
    {name && <input type="hidden" name={name} value={value} />}
    <button type="button" className="bank-select-trigger" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
      {selected ? <><BankLogo bank={selected} size={22} /><span className="bank-select-label">{selected.name}</span></> : <span className="bank-select-placeholder">{placeholder || 'Selecciona un banco'}</span>}
      <span className="bank-select-caret" aria-hidden="true">▾</span>
    </button>
    {open && <div className="bank-select-list" role="listbox">
      {allowEmptyOption && <button type="button" role="option" aria-selected={!value} className={`bank-select-option${!value ? ' selected' : ''}`} onClick={() => { onChange('', undefined); setOpen(false); }}>
        <span className="bank-select-label">{emptyLabel || placeholder || 'Selecciona un banco'}</span>
      </button>}
      {banks.map((b) => {
        const val = b[valueKey];
        const isSel = val === value;
        return <button key={b.code} type="button" role="option" aria-selected={isSel} className={`bank-select-option${isSel ? ' selected' : ''}`} onClick={() => { onChange(val, b); setOpen(false); }}>
          <BankLogo bank={b} size={22} /><span className="bank-select-label">{b.name}</span>
        </button>;
      })}
    </div>}
  </div>;
}

function AdminFundingPanel({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const [methods,setMethods]=useState<FundingMethod[]>([]);
  const [accounts,setAccounts]=useState<FundingAccount[]>([]);
  const [country,setCountry]=useState<'RU'|'CU'>('RU');
  const [bankCode,setBankCode]=useState(BANKS_BY_COUNTRY.RU[0].code);
  const [bankName,setBankName]=useState(BANKS_BY_COUNTRY.RU[0].name);
  const [accountIdentifier,setAccountIdentifier]=useState('');
  const [routingIdentifier,setRoutingIdentifier]=useState(BANKS_BY_COUNTRY.RU[0].routing || '');
  const [legalEntityName,setLegalEntityName]=useState(BANKS_BY_COUNTRY.RU[0].legalName || '');
  const [notes,setNotes]=useState('');
  const [selectedMethods,setSelectedMethods]=useState<string[]>([]);
  const [saving,setSaving]=useState(false);
  const reload=()=>run(async()=>{const [m,a]=await Promise.all([api.adminFundingMethods(userId),api.adminFundingAccounts(userId)]);setMethods(m.methods);setAccounts(a.accounts);});
  useEffect(()=>{reload();},[userId]);
  const compatible=methods.filter(m=>m.country_code===country);
  const currency=country==='RU'?'RUB':'CUP';
  const toggle=(code:string)=>setSelectedMethods(old=>old.includes(code)?old.filter(x=>x!==code):[...old,code]);
  const create=()=>void run(async()=>{
    if(!selectedMethods.length)throw new Error('Selecciona al menos un método compatible.');
    setSaving(true);
    try{
      await api.createFundingAccount(userId,{country_code:country,currency_code:currency,bank_code:bankCode,bank_name:bankName,account_holder_name:'SEDERG_PAY',legal_entity_name:legalEntityName||null,account_identifier:accountIdentifier,routing_identifier:routingIdentifier,environment:'sandbox',status:'active',methods:selectedMethods,notes});
      setAccountIdentifier('');setRoutingIdentifier('');setLegalEntityName('');setNotes('');setSelectedMethods([]);
      notifySuccess('Cuenta receptora guardada correctamente.');
      await reload();
    }finally{setSaving(false);}
  });
  return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">Administración financiera</span><h2>Métodos de pago y cuentas receptoras</h2><p className="muted">Las cuentas registradas pertenecen a SEDERG_PAY. La lista de bancos se actualiza según entidades reconocidas oficialmente en cada país (Rusia/Cuba) para configuración operativa en sandbox; la disponibilidad e integración real dependen de contratos y habilitación.</p></div><span className="status-pill status-info">SANDBOX</span></div>
  <section className="card"><div className="section-heading"><div><h3>Catálogo de métodos de fondeo</h3><p className="muted">Independiente de los métodos de desembolso al beneficiario.</p></div></div>
  {methods.map(method=><div className="funding-method-row" key={method.code}><div><strong>{method.name}</strong><small>{method.country_code} · {method.currency_code} · {method.method_type} · {method.code}</small></div><label className="switch-label"><input type="checkbox" checked={Boolean(method.is_active)} onChange={e=>void run(async()=>{await api.updateFundingMethod(userId,method.code,e.target.checked,method.configuration ?? undefined);notifySuccess(e.target.checked ? 'Método de pago activado.' : 'Método de pago desactivado.');await reload();})}/> Activo</label></div>)}</section>
  {methods.filter(method=>method.code==='ru_sbp_c2p').map(method=><FundingMethodConfiguration key={method.code} userId={userId} method={method} run={run} onSaved={reload} />)}
  <section className="card"><div className="section-heading"><div><h3>Registrar cuenta receptora</h3><p className="muted">El número se cifra antes de almacenarse; solo se muestra enmascarado después del alta.</p></div></div>
  <form className="funding-account-form" onSubmit={e=>{e.preventDefault();create();}}>
    <label>País<CountrySelect name="country" value={country} onChange={(c: 'CU' | 'RU')=>{setCountry(c);setSelectedMethods([]);const first=BANKS_BY_COUNTRY[c][0];if(first){setBankCode(first.code);setBankName(first.name);setRoutingIdentifier(first.routing||'');setLegalEntityName(first.legalName||'');}else{setBankCode('');setBankName('');setRoutingIdentifier('');setLegalEntityName('');}}} /></label>
    <label>Moneda<input value={currency} readOnly/></label>
    <label>Banco<BankSelect banks={BANKS_BY_COUNTRY[country]} value={bankCode} valueKey="code" onChange={(code,b)=>{setBankCode(code);if(b){setBankName(b.name);setRoutingIdentifier(b.routing||'');setLegalEntityName(b.legalName||'');}}} placeholder="Selecciona un banco" /></label>
    <label>Código del banco<input value={bankCode} readOnly/></label>
    <p className="muted funding-full">Datos de referencia (BIC/SWIFT, БИК y razón social) obtenidos de directorios/registros públicos (Banco Central de Rusia y SWIFT para RU; Banco Central de Cuba y SWIFT para CU). Verificar contra la fuente oficial vigente antes de uso en producción.</p>
    <label>Titular<input value="SEDERG_PAY" readOnly/></label>
    <label className="funding-full">Denominación jurídica / razón social<input value={legalEntityName} onChange={e=>setLegalEntityName(e.target.value)} placeholder="Nombre legal registrado"/></label>
    <label className="funding-full">Número de cuenta / identificador bancario<input value={accountIdentifier} onChange={e=>setAccountIdentifier(e.target.value)} required autoComplete="off" /></label>
    <label className="funding-full">BIC / БИК / SWIFT / código de ruta<input value={routingIdentifier} onChange={e=>setRoutingIdentifier(e.target.value)} autoComplete="off" placeholder="SWIFT/BIC o БИК" /></label>
    <div className="funding-full"><strong>Métodos compatibles</strong>{compatible.length===0?<p className="muted">No hay métodos definidos para este país.</p>:compatible.map(method=><label className="funding-checkbox" key={method.code}><input type="checkbox" checked={selectedMethods.includes(method.code)} onChange={()=>toggle(method.code)}/>{method.name} <small>{method.method_type==='instant_qr'?'QR sandbox':'Transferencia'}</small></label>)}</div>
    <label className="funding-full">Notas<input value={notes} onChange={e=>setNotes(e.target.value)}/></label>
    <button type="submit" disabled={saving||!accountIdentifier||!selectedMethods.length}>{saving?'Guardando…':'Guardar cuenta sandbox'}</button>
  </form></section>
  <section className="card"><div className="section-heading"><div><h3>Cuentas receptoras registradas</h3><p className="muted">Para cambiar de cuenta, registra la nueva y desactiva la anterior. Los depósitos existentes conservan su fotografía original.</p></div></div>
  {accounts.length===0?<p className="muted">Todavía no hay cuentas configuradas.</p>:accounts.map(account=><article className="funding-account-row" key={account.public_id}><div><strong>{account.bank_name} · {account.country_code}/{account.currency_code}</strong><small>Titular: {account.account_holder_name} · Cuenta {account.account_mask}{account.routing_mask ? ` · BIC/ruta ${account.routing_mask}` : ''}</small><small>Métodos: {account.methods||'—'} · {account.environment}</small></div><div><span className={account.status==='active'?'status-pill status-success':'status-pill status-muted'}>{account.status}</span><button className="secondary compact-button" onClick={()=>void run(async()=>{const nextStatus=account.status==='active'?'inactive':'active';await api.updateFundingAccount(userId,account.public_id,nextStatus);notifySuccess(nextStatus==='active'?'Cuenta receptora activada.':'Cuenta receptora desactivada.');await reload();})}>{account.status==='active'?'Desactivar':'Activar'}</button></div></article>)}</section>
  </div>;
}

function NotificationsPanel({ userId, run, onUnreadChange }: { userId: string; run: (action: () => Promise<void>) => Promise<void>; onUnreadChange: (count: number) => void }) {
  const t = useT();
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      const data = await api.notifications(userId, 50);
      setItems(data.items || []);
      setUnread(data.unread_count || 0);
      onUnreadChange(data.unread_count || 0);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, [userId]);

  const markRead = (id: string) => void run(async () => {
    await api.markNotificationRead(userId, id);
    await load();
  });
  const markAll = () => void run(async () => {
    await api.markAllNotificationsRead(userId);
    await load();
  });

  return <div className="feature-view">
    <div className="view-heading">
      <div><span className="eyebrow">{t('notifications.eyebrow')}</span><h2>{t('notifications.title')}</h2><p className="muted">{t('notifications.description')}</p></div>
      <div className="header-actions">
        <ActionButton icon="refresh" label={t('notifications.refresh')} onClick={() => void load()} disabled={loading} />
        <ActionButton icon="check" label={t('notifications.markAllRead')} onClick={markAll} disabled={loading || unread === 0} />
      </div>
    </div>
    <section className="card">
      {loading ? <p className="muted">{t('common.loading')}</p>
        : items.length === 0 ? <p className="muted">{t('notifications.empty')}</p>
        : <div className="notifications-list">
            {items.map((item) => (
              <article key={item.public_id} className={`notification-item ${item.is_read ? 'is-read' : 'is-unread'}`}>
                <div className="notification-head">
                  <strong>{item.title}</strong>
                  <small>{new Date(item.created_at).toLocaleString()}</small>
                </div>
                <p>{item.body}</p>
                {!item.is_read && <button type="button" className="secondary compact-button" onClick={() => markRead(item.public_id)}>{t('notifications.markRead')}</button>}
              </article>
            ))}
          </div>}
    </section>
  </div>;
}
