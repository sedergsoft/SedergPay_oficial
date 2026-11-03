import { ChangeEvent, FormEvent, createContext, useContext, useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { api, AdminDashboard, AdminOperation, AdminRate, AdminUser, Beneficiary, ExchangeRate, FundingAccount, FundingMethod, PlatformSetting, Quote, Remittance, RemittanceDetail, User, isDevelopmentAuth, paymentMethods } from './api';
import { getInitialLanguage, Language, languageLabels, translate } from './i18n';

const LanguageContext = createContext<Language>('es');
const useLanguage = () => useContext(LanguageContext);
const useT = () => { const language = useLanguage(); return (key: string) => translate(language, key); };
const localeFor = (language: Language) => language === 'ru' ? 'ru-RU' : 'es-ES';

type View = 'summary' | 'beneficiaries' | 'quote' | 'remittances' | 'profile' | 'kyc' | 'admin' | 'funding';
const storedUserId = isDevelopmentAuth ? localStorage.getItem('sederg_user') ?? '' : '';

export function App() {
  const [language, setLanguage] = useState<Language>(getInitialLanguage);
  const changeLanguage = (next: Language) => {
    setLanguage(next);
    localStorage.setItem('sederg_language', next);
    document.documentElement.lang = next;
  };
  useEffect(() => { document.documentElement.lang = language; }, [language]);
  return <LanguageContext.Provider value={language}><AppContent language={language} onLanguageChange={changeLanguage} /></LanguageContext.Provider>;
}

function AppContent({ language, onLanguageChange }: { language: Language; onLanguageChange: (language: Language) => void }) {
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

  useEffect(() => {
    if (!storedUserId) return;
    api.me(storedUserId).then(setUser).catch(() => localStorage.removeItem('sederg_user')).finally(() => setAuthReady(true));
  }, []);

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    setMessage('');
    try { await action(); } catch (error) { const message=(error as Error).message; const lower=message.toLowerCase(); const field=lower.includes('phone')||lower.includes('telefono') ? 'phone_e164' : lower.includes('card')||lower.includes('tarjeta') ? 'payout_card_number' : lower.includes('account')||lower.includes('cuenta') ? 'payout_account_number' : lower.includes('provider')||lower.includes('banco') ? 'payout_provider' : ''; if(field){window.dispatchEvent(new CustomEvent('sederg:beneficiary-field-error',{detail:{field,message}}));}else{setMessage(message);} } finally { setBusy(false); }
  };

  const signOut = () => {
    localStorage.removeItem('sederg_user');
    localStorage.removeItem('sederg_access_token');
    setUser(null);
    setView('summary');
  };

  if (!authReady) return <main className="shell narrow"><Header language={language} onLanguageChange={onLanguageChange} /><section className="card"><p className="muted">{t('app.restoringSession')}</p></section></main>;
  if (!user && publicView === 'landing') return <Landing language={language} onLanguageChange={onLanguageChange} onLogin={() => { setAuthView('login'); setPublicView('auth'); }} onRegister={() => { setAuthView('register'); setPublicView('auth'); }} />;
  if (!user) return <AuthScreen language={language} onLanguageChange={onLanguageChange} mode={authView} setMode={setAuthView} busy={busy} message={message} run={run} onBack={() => setPublicView('landing')} onAuthenticated={(next) => {
    localStorage.setItem('sederg_user', next.public_id);
    setUser(next);
  }} />;

  return <main className="shell">
    <Header language={language} onLanguageChange={onLanguageChange} />
    <RateBanner />
    <section className="hero">
      <div><div className="eyebrow">{t('app.operationsPanel')}</div><h1>{t('app.hello')}, {user.first_name}.</h1><p>{t('app.welcomeDescription')}</p></div>
      <div className="hero-mark" aria-hidden="true">↗</div>
    </section>
    <div className="workspace">
      <nav className="menu" aria-label={t("nav.platform")}>
        <strong>{t('nav.platform')}</strong>
        <NavButton icon="home" active={view === 'summary'} onClick={() => setView('summary')}>{translate(language, 'nav.summary')}</NavButton>
        <NavButton icon="users" active={view === 'beneficiaries'} onClick={() => setView('beneficiaries')}>{translate(language, 'nav.beneficiaries')}</NavButton>
        <NavButton icon="send" active={view === 'quote'} onClick={() => setView('quote')}>{translate(language, 'nav.quote')}</NavButton>
        <NavButton icon="history" active={view === 'remittances'} onClick={() => setView('remittances')}>{translate(language, 'nav.remittances')}</NavButton><NavButton icon="user" active={view === 'profile'} onClick={() => setView('profile')}>{t('nav.profile')}</NavButton><NavButton icon="shield" active={view === 'kyc'} onClick={() => setView('kyc')}>{t('nav.kyc')}</NavButton>
        {user.role === 'admin' && <><NavButton icon="settings" active={view === 'admin'} onClick={() => setView('admin')}>{translate(language, 'nav.admin')}</NavButton><NavButton icon="settings" active={view === 'funding'} onClick={() => setView('funding')}>Métodos de pago</NavButton></>}
        <button className="secondary menu-exit" onClick={signOut}>{translate(language, 'nav.logout')}</button>
      </nav>
      <section className="content">
        <div className="toolbar"><span>{user.email} · <span className="role">{user.role === 'admin' ? t('role.admin') : t('role.customer')}</span></span><span className="sandbox"><i /> {t('common.sandbox')}</span></div>
        {view === 'summary' && <Summary userId={user.public_id} setView={setView} run={run} />}
        {view === 'beneficiaries' && <Beneficiaries userId={user.public_id} run={run} />}
        {view === 'quote' && <QuotePanel userId={user.public_id} run={run} />}
        {view === 'remittances' && <Remittances userId={user.public_id} run={run} />}{view === 'profile' && <ProfilePanel userId={user.public_id} run={run} />}{view === 'kyc' && <KycPanel userId={user.public_id} run={run} />}
        {view === 'admin' && user.role === 'admin' && <AdminOperations userId={user.public_id} run={run} />}{view === 'funding' && user.role === 'admin' && <AdminFundingPanel userId={user.public_id} run={run} />}
        {message && <Alert text={message} />}
      </section>
    </div>
    <footer>{t('app.footerSandbox')}</footer>
  </main>;
}

function Landing({ language, onLanguageChange, onLogin, onRegister }: { language: Language; onLanguageChange: (language: Language) => void; onLogin: () => void; onRegister: () => void }) {
  const text = (key: string) => translate(language, key);
  const [rate, setRate] = useState<ExchangeRate | null>(null);
  useEffect(() => { api.currentExchangeRate().then(setRate).catch(() => undefined); }, []);
  const currentRate = rate ? Number(rate.rate).toFixed(5) : '—';
  const receiveExample = rate ? (10000 * Number(rate.rate)).toLocaleString(localeFor(language), { maximumFractionDigits: 2 }) : '—';
  return <main className="landing">
    <header className="landing-header">
      <Header language={language} onLanguageChange={onLanguageChange} />
      <nav className="landing-actions" aria-label="Acceso">
        <button className="landing-login" onClick={onLogin}>{text('common.login')}</button>
        <button onClick={onRegister}>{text('common.register')} <span aria-hidden="true">→</span></button>
      </nav>
    </header>
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
    <section className="landing-rate"><div><span className="eyebrow">{text('landing.transparency')}</span><strong>{text('landing.rate')}</strong></div><div className="landing-rate-value"><strong>{currentRate}</strong><span>{text('landing.updated')} {rate ? rate.effective_date : '…'}</span></div></section>
    <section className="landing-section">
      <div className="section-intro"><span className="eyebrow">{text('landing.featuresEyebrow')}</span><h2>{text('landing.featuresTitle')}<br /><em>{text('landing.featuresAccent')}</em></h2><p>{text('landing.featuresDescription')}</p></div>
      <div className="feature-grid"><Feature icon="transfer" title={text('landing.featureClear')} text={text('landing.featureClearText')} /><Feature icon="shield" title={text('landing.featureSecure')} text={text('landing.featureSecureText')} /><Feature icon="history" title={text('landing.featureTrack')} text={text('landing.featureTrackText')} /></div>
    </section>
    <section className="landing-steps"><div className="section-intro"><span className="eyebrow">{text('landing.stepsEyebrow')}</span><h2>{text('landing.stepsTitle')}<br /><em>{text('landing.stepsAccent')}</em></h2></div><div className="steps"><Step number="01" title={text('landing.stepOne')} text={text('landing.stepOneText')} /><Step number="02" title={text('landing.stepTwo')} text={text('landing.stepTwoText')} /><Step number="03" title={text('landing.stepThree')} text={text('landing.stepThreeText')} /></div></section>
    <footer className="landing-footer"><Brand language={language} /><span>{text('brand.slogan')}</span><span>© 2026 SEDERG_PAY</span></footer>
  </main>;
}

function Icon({ name, className = "" }: { name: string; className?: string }) { return <svg className={"ui-icon " + className} aria-hidden="true" focusable="false"><use href={import.meta.env.BASE_URL + "assets/sederg-pay/icons/sprite.svg#" + name} /></svg>; }
function Feature({ icon, title, text }: { icon: string; title: string; text: string }) { return <article className="feature"><span className="feature-icon"><Icon name={icon} /></span><h3>{title}</h3><p>{text}</p></article>; }
function Step({ number, title, text }: { number: string; title: string; text: string }) { return <article className="step"><span>{number}</span><div><h3>{title}</h3><p>{text}</p></div></article>; }

function AuthScreen({ language, onLanguageChange, mode, setMode, busy, message, run, onBack, onAuthenticated }: {
  language: Language; onLanguageChange: (language: Language) => void;
  mode: 'login' | 'register'; setMode: (mode: 'login' | 'register') => void; busy: boolean;
  message: string; run: (action: () => Promise<void>) => Promise<void>; onBack: () => void; onAuthenticated: (user: User) => void;
}) {
  const text = (key: string) => translate(language, key);
  const [verificationEmail, setVerificationEmail] = useState('');
  const [verificationToken, setVerificationToken] = useState('');
  const [notification, setNotification] = useState('');
  const [activationState, setActivationState] = useState<'idle' | 'activating' | 'success' | 'error'>('idle');
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const email = params.get('verify_email');
    const token = params.get('token');
    if (!email || !token) return;
    setVerificationEmail(email);
    setActivationState('activating');
    void run(async () => {
      try {
        await api.verifyEmail(email, token);
        setActivationState('success');
        setNotification(text('auth.activationSuccess'));
        setVerificationEmail('');
        setVerificationToken('');
        window.history.replaceState({}, '', '/?access=login');
        setMode('login');
      } catch (error) {
        setActivationState('error');
        throw error;
      }
    });
  }, []);
  return <main className="shell narrow"><Header language={language} onLanguageChange={onLanguageChange} /><RateBanner /><section className="auth-card card">
    <button className="back-link" type="button" onClick={onBack}>{text('common.backHome')}</button>
    <div className="eyebrow">{text('auth.secure')}</div><h1>{mode === 'login' ? text('auth.welcome') : text('auth.create')}</h1>
    <p className="muted">{isDevelopmentAuth ? text('auth.description') : text('auth.oidcDescription')}</p>
    <div className="tabs"><button className={mode === 'login' ? 'tab active' : 'tab'} onClick={() => setMode('login')}>{text('common.login')}</button><button className={mode === 'register' ? 'tab active' : 'tab'} onClick={() => setMode('register')}>{text('common.register')}</button></div>
    {!isDevelopmentAuth && <p className="alert">{text('auth.oidcSetup')}</p>}
    {activationState === 'activating' && <div className="activation-panel"><span className="activation-spinner" /><strong>{text('auth.activating')}</strong><p>{text('auth.verifyingLink')}</p></div>}
    {activationState === 'error' && <div className="alert" role="alert">{text('auth.invalidLink')}</div>}
    {verificationEmail && activationState === 'idle' && <div className="verification-panel"><div className="success-alert" role="status">{notification || <>{text('auth.verificationSent')} <strong>{verificationEmail}</strong>. {text('auth.checkInbox')}</>}</div><p className="muted">{text('auth.openActivationLink')}</p><button type="button" className="secondary" disabled={busy} onClick={() => void run(async () => { await api.resendVerification(verificationEmail); setNotification(text('auth.resent')); })}>{text('auth.resendLink')}</button></div>}
    {notification && activationState === 'success' && <div className="success-alert" role="status">{notification || text('auth.activationSuccess')}</div>}
    {!verificationEmail && isDevelopmentAuth && mode === 'login' && <form onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      void run(async () => onAuthenticated(await api.login(String(data.get('email')), String(data.get('password')))));
    }}><Field name="email" label={text('field.email')} type="email" /><Field name="password" label={text('field.password')} type="password" /><button disabled={busy}>{busy ? text('common.validating') : text('common.login')}</button></form>}
    {!verificationEmail && isDevelopmentAuth && mode === 'register' && <form onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      void run(async () => {
        const email = String(data.get('email')); const password = String(data.get('password'));
        const result = await api.register({ email, password, first_name: data.get('first_name'), last_name: data.get('last_name'), residence_country_code: data.get('country'), consent_policy_version: 'v1', accept_terms: true, accept_privacy: true });
        setVerificationEmail(email);
        setVerificationToken('');
        setNotification(`${text('auth.accountCreated')} ${result.email}.`);
      });
    }}><Field name="first_name" label={text('field.firstName')} /><Field name="last_name" label={text('field.lastName')} /><Field name="email" label={text('field.email')} type="email" /><Field name="password" label={text('field.passwordMin')} type="password" /><label>{text('field.residenceCountry')}<select name="country" defaultValue="RU"><option value="RU">{text('country.russia')}</option><option value="CU">{text('country.cuba')}</option></select></label><button disabled={busy}>{busy ? text('common.creating') : text('common.register')}</button></form>}
    {message && <Alert text={message} />}
  </section></main>;
}

function ProfilePanel({userId,run}:{userId:string;run:(action:()=>Promise<void>)=>Promise<void>}){
  const t = useT();
 const [profile,setProfile]=useState<any>(null);useEffect(()=>{void run(async()=>setProfile(await api.profile(userId)));},[userId]);
 if(!profile)return <section className="card"><p className="muted">{t('common.loadingProfile')}</p></section>;
 return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('profile.personalAccount')}</span><h2>{t('nav.profile')}</h2><p className="muted">{t('profile.description')}</p></div><span className="status-pill status-info"><Icon name="user"/> {t('nav.profile')}</span></div>
 <section className="card profile-card"><div className="profile-intro"><span className="profile-avatar">{(profile.first_name?.[0]||'U').toUpperCase()}{(profile.last_name?.[0]||'').toUpperCase()}</span><div><h3>{profile.first_name} {profile.last_name}</h3><p>{profile.email}</p><small>Identificador: {profile.public_id}</small></div></div><div className="profile-section-title"><Icon name="user"/><div><strong>{t('profile.personalInformation')}</strong><small>{t('profile.emailImmutable')}</small></div></div>
 <form className="modern-form" onSubmit={e=>{e.preventDefault();const d=new FormData(e.currentTarget);void run(async()=>setProfile(await api.updateProfile(userId,{first_name:d.get('first_name'),last_name:d.get('last_name'),middle_name:d.get('middle_name'),phone_e164:d.get('phone_e164'),date_of_birth:d.get('date_of_birth')||null,residence_country_code:d.get('residence_country_code'),nationality_country_code:d.get('nationality_country_code'),address_line:d.get('address_line'),city:d.get('city'),postal_code:d.get('postal_code')})));}}>
 <div className="form-grid"><Field name="first_name" label={t('field.firstName')} defaultValue={profile.first_name}/><Field name="last_name" label={t('field.lastName')} defaultValue={profile.last_name}/><Field name="middle_name" label={t('field.middleName')} required={false} defaultValue={profile.middle_name||''}/><label>Correo electrónico<input type="email" value={profile.email} disabled /></label><Field name="phone_e164" label={t('field.phone')} type="tel" required={false} defaultValue={profile.phone_e164||''}/><Field name="date_of_birth" label={t('field.birthDate')} type="date" required={false} defaultValue={profile.date_of_birth||''}/><label>{t('field.residenceCountry')}<select name="residence_country_code" defaultValue={profile.residence_country_code||'RU'}><option value="RU">🇷🇺 Rusia</option><option value="CU">🇨🇺 Cuba</option></select></label><label>{t('field.nationality')}<select name="nationality_country_code" defaultValue={profile.nationality_country_code||'CU'}><option value="CU">🇨🇺 {t('country.cuba')}</option><option value="RU">🇷🇺 {t('country.russia')}</option></select></label><Field name="address_line" label={t('field.address')} required={false} defaultValue={profile.address_line||''}/><Field name="city" label={t('field.city')} required={false} defaultValue={profile.city||''}/><Field name="postal_code" label={t('field.postalCode')} required={false} defaultValue={profile.postal_code||''}/></div><div className="form-actions"><button><Icon name="check"/> {t('common.saveChanges')}</button></div></form></section></div>;
}
function KycPanel({userId,run}:{userId:string;run:(action:()=>Promise<void>)=>Promise<void>}){
  const t = useT();
 const [kyc,setKyc]=useState<any>(null);const refresh=()=>run(async()=>setKyc(await api.kyc(userId)));useEffect(()=>{refresh();},[userId]);
 const hasIdentity=Boolean(kyc?.documents?.some((d:any)=>d.document_type==='identity'));const hasAddress=Boolean(kyc?.documents?.some((d:any)=>d.document_type==='address'));
 return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('kyc.accountProtection')}</span><h2>{t('kyc.title')}</h2><p className="muted">{t('kyc.description')}</p></div><span className="status-pill status-info"><Icon name="shield"/> KYC</span></div>
 <section className="card kyc-card"><div className="kyc-status-banner"><span className="kyc-shield"><Icon name="shield"/></span><div><small>{t('kyc.verificationStatus')}</small><strong>{kyc?.status||t('common.loading')}</strong><p>{kyc?.review_reason||t('kyc.uploadPrompt')}</p></div><StatusBadge status={kyc?.status||'pending'}/></div>
 <div className="kyc-checklist"><div className={hasIdentity?'kyc-check complete':'kyc-check'}><Icon name={hasIdentity?'check':'receipt'}/><span><strong>{t('kyc.identityDocument')}</strong><small>{hasIdentity?t('kyc.documentReceived'):t('kyc.pendingUpload')}</small></span></div><div className={hasAddress?'kyc-check complete':'kyc-check'}><Icon name={hasAddress?'check':'location'}/><span><strong>{t('kyc.addressProof')}</strong><small>{hasAddress?t('kyc.documentReceived'):t('kyc.pendingUpload')}</small></span></div></div>
 <form className="modern-form kyc-upload" onSubmit={e=>{e.preventDefault();const form=e.currentTarget;const d=new FormData(form);const file=d.get('file') as File;void run(async()=>{if(!file||!file.size)throw new Error(t('kyc.selectFile'));await api.uploadKycDocument(userId,file,String(d.get('document_type')));setKyc(await api.kyc(userId));form.reset();});}}><div className="form-grid"><label>{t('kyc.documentType')}<select name="document_type"><option value="identity">{t('kyc.identityDocument')}</option><option value="address">{t('kyc.addressProof')}</option><option value="selfie">{t('kyc.selfieOptional')}</option></select></label><label>{t('kyc.fileTypes')}<input name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required/></label></div><button><Icon name="plus"/> {t('kyc.uploadDocument')}</button></form>
 <h3 className="subsection-title">{t('kyc.submittedDocuments')}</h3>{!kyc?.documents?.length?<p className="muted">{t('kyc.noDocuments')}</p>:<div className="document-list">{kyc.documents.map((d:any)=><div className="document-item" key={d.public_id}><span className="document-icon"><Icon name="receipt"/></span><div><strong>{d.document_type}</strong><small>{d.original_name} · {(d.file_size/1024).toFixed(0)} KB</small></div><StatusBadge status={d.status||'received'}/></div>)}</div>}
 <div className="form-actions"><button disabled={!hasIdentity||!hasAddress||['approved','pending_review'].includes(kyc?.status)} onClick={()=>void run(async()=>{await api.submitKyc(userId);setKyc(await api.kyc(userId));})}>{t('kyc.submitForReview')}</button></div><p className="secure-note"><Icon name="lock"/> {t('kyc.secureStorage')}</p></section></div>;
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
 const [items,setItems]=useState<Beneficiary[]>([]); const [method,setMethod]=useState('cash_pickup'); const [editing,setEditing]=useState<Beneficiary|null>(null); const [fieldErrors,setFieldErrors]=useState<Record<string,string>>({});
 const refresh=()=>run(async()=>setItems(await api.beneficiaries(userId))); useEffect(()=>{refresh();},[userId]); useEffect(()=>{const handler=(event:Event)=>{const detail=(event as CustomEvent<{field:string;message:string}>).detail;setFieldErrors((current)=>({...current,[detail.field]:detail.message}));};window.addEventListener('sederg:beneficiary-field-error',handler);return()=>window.removeEventListener('sederg:beneficiary-field-error',handler);},[]);
 const submit=(event:FormEvent<HTMLFormElement>)=>{event.preventDefault();const form=event.currentTarget;const d=new FormData(form);const phone=String(d.get('phone_e164')||'').replace(/\D/g,'');const account=String(d.get('payout_account_number')||'').replace(/\D/g,'');const card=String(d.get('payout_card_number')||'').replace(/\D/g,'');const provider=String(d.get('payout_provider')||'').trim();const errors:Record<string,string>={};if(phone.length<8||phone.length>15)errors.phone_e164='Introduce un teléfono internacional válido (entre 8 y 15 dígitos).';if(method==='bank_account'&&!provider)errors.payout_provider='Indica el banco o proveedor de la cuenta.';if(method==='bank_account'&&(!editing||account)&&(account.length<6||account.length>34))errors.payout_account_number='La cuenta debe contener entre 6 y 34 dígitos.';if(method==='card_transfer'&&(!editing||card)&&!isValidCardNumber(card))errors.payout_card_number='Introduce un número de tarjeta válido (12–19 dígitos y checksum correcto).';setFieldErrors(errors);if(Object.keys(errors).length)return;void run(async()=>{const body={country_code:d.get('country'),first_name:d.get('first_name'),last_name:d.get('last_name'),phone_e164:phone ? '+' + phone : '',email:d.get('email'),payout_method_code:method,payout_provider:provider||undefined,payout_account_number:account||undefined,payout_card_number:card||undefined};if(editing)await api.updateBeneficiary(userId,editing.public_id,body);else await api.createBeneficiary(userId,body);setEditing(null);setMethod('cash_pickup');setFieldErrors({});form.reset();await refresh();});};
 return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('beneficiaries.eyebrow')}</span><h2>{t('nav.beneficiaries')}</h2><p className="muted">{t('beneficiaries.description')}</p></div><span className="count-chip">{items.length} {t('common.registered')}</span></div>
 <div className="beneficiary-layout"><section className="card form-card"><div className="section-heading"><div><span className="eyebrow">{editing?t('common.updateData'):t('beneficiaries.newRecipient')}</span><h3>{editing?t('beneficiaries.edit'):t('beneficiaries.add')}</h3></div><span className="section-icon"><Icon name="user"/></span></div><p className="muted">{t('beneficiaries.editSecurity')}</p><form className="modern-form" key={editing?.public_id ?? 'new-beneficiary'} onSubmit={submit}><div className="form-grid"><Field name="first_name" label={t('field.firstName')} defaultValue={editing?.first_name}/><Field name="last_name" label={t('field.lastName')} defaultValue={editing?.last_name}/><Field name="phone_e164" label={t('field.phone')} type="tel" mask="phone" error={fieldErrors.phone_e164} placeholder="+7 999 123-45-67 / +53 5 123 4567" defaultValue={editing?.phone_e164??''}/><Field name="email" label={t('field.email')} type="email" defaultValue={editing?.email??''}/><label>{t('field.country')}<select name="country" defaultValue={editing?.country_code??'CU'}><option value="CU">🇨🇺 {t('country.cuba')}</option><option value="RU">🇷🇺 {t('country.russia')}</option></select></label><label>Método de entrega preferido<select value={method} onChange={e=>setMethod(e.target.value)}>{paymentMethods.map(m=><option key={m.code} value={m.code}>{t('payout.'+m.code)}</option>)}</select></label></div>
 <div className="conditional-fields"><Field name="payout_provider" label={t('field.bankProvider')} error={fieldErrors.payout_provider} defaultValue={editing?.payout_provider??''} required={method==='bank_account'}/><Field name="payout_account_number" label={t('field.accountNumberKeep')} error={fieldErrors.payout_account_number} mask="account" placeholder="0000 0000 0000 0000" inputMode="numeric" pattern="[0-9 ]{6,42}" required={method==='bank_account'&&!editing}/><Field name="payout_card_number" label={t('field.cardNumberKeep')} error={fieldErrors.payout_card_number} mask="card" placeholder="0000 0000 0000 0000" inputMode="numeric" pattern="[0-9 ]{12,23}" required={method==='card_transfer'&&!editing}/></div><p className="muted">Puedes registrar los datos bancarios y de tarjeta ahora. Los campos que dejes vacíos podrán completarse al seleccionar ese método en una cotización.</p><p className="secure-note"><Icon name="lock"/> {t('beneficiaries.encryptedData')}</p><div className="form-actions"><button>{editing?t('common.saveChanges'):t('beneficiaries.save')}</button>{editing&&<button type="button" className="secondary" onClick={()=>{setEditing(null);setMethod('cash_pickup');}}>{t('common.cancel')}</button>}</div></form></section>
 <section className="card beneficiary-list-card"><div className="section-heading"><div><span className="eyebrow">{t('beneficiaries.addressBook')}</span><h3>{t('beneficiaries.registeredPeople')}</h3></div><span className="section-icon"><Icon name="users"/></span></div>{items.length===0?<div className="empty-state"><span className="empty-icon"><Icon name="users"/></span><strong>{t('beneficiaries.empty')}</strong><p>{t('beneficiaries.emptyDescription')}</p></div>:<div className="beneficiary-list">{items.map(item=><article className="beneficiary-item" key={item.public_id}><div className="beneficiary-avatar">{(item.first_name?.[0]||'B').toUpperCase()}{(item.last_name?.[0]||'')[0]?.toUpperCase()}</div><div className="beneficiary-info"><strong>{item.first_name} {item.last_name}</strong><span><Icon name="globe"/> {item.country_code==='CU'?t('country.cuba'):t('country.russia')} · {item.phone_e164||item.email||t('common.noContact')}</span><small>{t('payout.'+item.payout_method_code)} {item.payout_provider??''} {item.payout_account_last4?'· cuenta ****'+item.payout_account_last4:''} {item.payout_card_last4?'· tarjeta ****'+item.payout_card_last4:''}</small></div><div className="beneficiary-actions"><StatusBadge status={item.status}/><button className="secondary compact-button" type="button" onClick={()=>{setEditing(item);setMethod(item.payout_method_code);}}><Icon name="edit"/> {t('common.edit')}</button></div></article>)}</div>}</section></div></div>;
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
    {missingDetails && <div className="details-modal-backdrop"><section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="missing-payout-title"><div className="section-heading"><div><span className="eyebrow">Datos del beneficiario</span><h3 id="missing-payout-title">Completa los datos de entrega</h3></div><button type="button" className="secondary compact-button" onClick={()=>setMissingDetails(false)}>{t('common.close')}</button></div><p className="muted">El beneficiario no tiene registrados todos los datos necesarios para este método. Se guardarán cifrados en su ficha para futuras operaciones.</p><form className="modern-form" onSubmit={event=>{event.preventDefault();const errors:Record<string,string>={};if(method==='bank_account'&&!bankProvider.trim())errors.bank_provider='Indica el banco del beneficiario.';if(method==='bank_account'&&accountNumber.replace(/\D/g,'').length<6)errors.account_number='La cuenta debe contener al menos 6 dígitos.';if(method==='card_transfer'&&!isValidCardNumber(cardNumber))errors.card_number='Introduce un número de tarjeta válido (12–19 dígitos y checksum correcto).';setModalErrors(errors);if(Object.keys(errors).length)return;void run(async()=>{const selected=beneficiaries.find(item=>item.public_id===beneficiaryId);if(!selected)throw new Error('Beneficiario no encontrado');await api.updateBeneficiary(userId,selected.public_id,{country_code:selected.country_code,first_name:selected.first_name,last_name:selected.last_name,phone_e164:selected.phone_e164,email:selected.email,payout_method_code:method,payout_provider:method==='bank_account'?bankProvider:selected.payout_provider,...(method==='bank_account'?{payout_account_number:accountNumber.replace(/\D/g,'')}:{payout_card_number:cardNumber.replace(/\D/g,'')})});setBeneficiaries(await api.beneficiaries(userId));setMissingDetails(false);const result=await api.quote(userId,{source_country_code:sourceCountry,destination_country_code:destinationCountry,send_amount:pendingAmount.replace(',', '.'),send_currency:sendCurrency,receive_currency:receiveCurrency,payout_method_code:method,fee_mode:feeMode,beneficiary_public_id:beneficiaryId});setQuote(result);setStep(2);setAccountNumber('');setCardNumber('');});}}><div className="form-grid">{method==='bank_account'&&<><Field name="bank_provider" label={t('field.bankProvider')} error={modalErrors.bank_provider} value={bankProvider} onChange={(event:ChangeEvent<HTMLInputElement>)=>setBankProvider(event.target.value)}/><Field name="account_number" label={t('field.accountNumberKeep')} error={modalErrors.account_number} value={accountNumber} mask="account" placeholder="0000 0000 0000 0000" onChange={(event:ChangeEvent<HTMLInputElement>)=>setAccountNumber(event.target.value)} inputMode="numeric" pattern="[0-9 ]{6,42}"/></>}{method==='card_transfer'&&<Field name="card_number" label={t('field.cardNumberKeep')} error={modalErrors.card_number} value={cardNumber} mask="card" placeholder="0000 0000 0000 0000" onChange={(event:ChangeEvent<HTMLInputElement>)=>setCardNumber(event.target.value)} inputMode="numeric" pattern="[0-9 ]{12,23}"/>}</div><div className="form-actions"><button disabled={method==='bank_account'?!accountNumber||!bankProvider:!cardNumber}>Guardar y continuar</button><button type="button" className="secondary" onClick={()=>setMissingDetails(false)}>{t('common.cancel')}</button></div></form></section></div>}
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
  const chooseMethod = (method:FundingMethod) => {
    if(!payingFor)return;
    void run(async()=>{
      await api.deposit(userId,payingFor.public_id,method.code);
      const detail=await api.remittance(userId,payingFor.public_id);
      setSelected(detail);
      setPayingFor(null);
      setItems(await api.remittances(userId));
    });
  };
  return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">{t('remittances.eyebrow')}</span><h2>{t('nav.remittances')}</h2><p className="muted">{t('remittances.description')}</p></div><span className="count-chip">{items.length} {t('common.operations')}</span></div>
  <section className="card remittances-card"><div className="filter-tabs" role="group" aria-label={t('remittances.filter')}>{[['all',t('common.all')],['active',t('common.inProgress')],['completed',t('common.completed')]].map(([value,label])=><button key={value} className={filter===value?'filter-tab active':'filter-tab'} onClick={()=>setFilter(value)}>{label}</button>)}</div>
  {visible.length===0?<div className="empty-state"><span className="empty-icon"><Icon name="history"/></span><strong>{t('remittances.empty')}</strong><p>{t('remittances.emptyDescription')}</p></div>:<div className="remittance-list">{visible.map(item=><article className="remittance-item" key={item.public_id}><span className="remittance-icon"><Icon name="transfer"/></span><div className="remittance-main"><strong>{item.reference}</strong><small>{item.created_at?new Date(item.created_at).toLocaleString(locale):'Fecha no disponible'}</small><span>{item.send_amount} {item.send_currency} <b>→</b> {item.receive_amount} {item.receive_currency}</span></div><div className="remittance-status"><StatusBadge status={item.status}/><button className="text-button" onClick={() => void run(async () => setSelected(await api.remittance(userId, item.public_id)))}>{t('common.viewDetails')} <span>→</span></button></div>
  <div className="remittance-operations">{item.status === 'awaiting_payment' && <button className="secondary compact-button" onClick={() => startPayment(item)}>{t('remittances.registerDeposit')}</button>}{item.status === 'compliance_review' && <button onClick={() => void run(async () => { await api.review(userId, item.public_id, 'approved'); refresh(); })}>{t('common.approve')} (sandbox)</button>}{item.status === 'approved' && <button onClick={() => void run(async () => { const payout = await api.payout(userId, item.public_id); await api.completePayout(userId, payout.public_id); refresh(); })}>{t('remittances.executePayout')} (sandbox)</button>}{item.status === 'reconciliation_pending' && <button className="secondary compact-button" onClick={() => void run(async () => { await api.reconcile(userId, item.public_id); refresh(); })}>{t('remittances.reconcile')}</button>}</div></article>)}</div>}</section>
  {payingFor && <div className="details-modal-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setPayingFor(null);}}><section className="details-modal card" role="dialog" aria-modal="true" aria-labelledby="funding-method-title"><div className="section-heading details-modal-heading"><div><span className="eyebrow">Segundo paso · Pago</span><h2 id="funding-method-title">Selecciona cómo pagar</h2><p className="muted">{payingFor.total_debit_amount ?? payingFor.send_amount} {payingFor.send_currency}</p></div><button className="secondary compact-button" onClick={()=>setPayingFor(null)}>Cerrar</button></div>{loadingMethods?<p className="muted">Cargando métodos disponibles…</p>:fundingMethods.length===0?<div className="alert">No hay métodos de pago habilitados para esta moneda. El administrador debe configurar una cuenta receptora SEDERG_PAY y activar el método correspondiente.</div>:<div className="payment-method-options">{fundingMethods.map(method=><button key={method.code} className="payment-method-option" type="button" onClick={()=>chooseMethod(method)}><strong>{method.name}</strong><small>{method.method_type==='instant_qr'?'QR dinámico de sandbox': 'Transferencia bancaria'} · {method.provider_code||'Canal manual'}</small><span>Seleccionar →</span></button>)}</div>}<p className="muted">El QR de sandbox sirve para probar la experiencia; no inicia una transferencia bancaria real ni confirma el ingreso.</p></section></div>}
  {selected && <RemittanceDetails detail={selected} onClose={() => setSelected(null)} />}
  </div>;
}
function PaymentQr({ remittance }: { remittance: Remittance }) {
  const t = useT();
  const [src, setSrc] = useState('');
  const instructions = remittance.payment_instructions ?? {};
  useEffect(() => { setSrc(''); if (!remittance.payment_qr_payload) return; void QRCode.toDataURL(remittance.payment_qr_payload, { width: 220, margin: 2 }).then(setSrc); }, [remittance.payment_qr_payload]);
  return <div className="payment-panel payment-panel-modern"><div><span className="eyebrow">{t('payment.platformPayment')}</span><h3>{t('payment.transferCreated')}: {remittance.reference}</h3><p>Transfiere <strong>{instructions.amount ?? remittance.deposit_expected_amount ?? remittance.send_amount} {instructions.currency_code ?? remittance.deposit_currency ?? remittance.send_currency}</strong></p><div className="payment-bank-details"><p><span>Banco</span><strong>{instructions.bank_name ?? '—'}</strong></p><p><span>Titular</span><strong>{instructions.account_holder_name ?? 'SEDERG_PAY'}</strong></p><p><span>Cuenta</span><strong>{instructions.account_mask ?? '—'}</strong></p><p><span>Referencia obligatoria</span><strong>{instructions.reference ?? remittance.payment_reference ?? '—'}</strong></p></div>{remittance.payment_qr_payload && <p className="sandbox-note">QR de prueba SEDERG_PAY. No es un QR bancario interoperable ni acredita el pago.</p>}{!remittance.payment_qr_payload && <p className="muted">Este método utiliza transferencia bancaria con referencia; no dispone de QR.</p>}</div>{src && <img className="payment-qr" src={src} alt={`QR de pago sandbox ${remittance.reference}`} />}</div>;
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
  const [rateHistory, setRateHistory] = useState<AdminRate[]>([]);
  const [dashboard, setDashboard] = useState<AdminDashboard | null>(null);
  const [rate, setRate] = useState<ExchangeRate | null>(null);
  const [rateValue, setRateValue] = useState('');
  const [settings, setSettings] = useState<PlatformSetting[]>([]);
  const [settingsMessage, setSettingsMessage] = useState('');
  const [rateSourceBusy, setRateSourceBusy] = useState(false);
  const [kycCases, setKycCases] = useState<any[]>([]);
  const [kycReason, setKycReason] = useState<Record<string,string>>({});
  useEffect(() => { void run(async () => setItems(await api.adminOperations(userId))); }, [userId]);
  useEffect(() => { void run(async () => { const [userResult, rateResult, dashboardResult] = await Promise.all([api.adminUsers(userId), api.adminRateHistory(userId), api.adminDashboard(userId)]); setUsers(userResult); setRateHistory(rateResult.rates); setDashboard(dashboardResult); }); }, [userId]);
  useEffect(() => { void run(async () => { const current = await api.currentExchangeRate(); setRate(current); setRateValue(current.manual_override ? current.rate : ''); }); }, []);
  useEffect(() => { void run(async () => setSettings((await api.adminSettings(userId)).settings)); }, [userId]);
  useEffect(() => { void run(async () => setKycCases((await api.adminKyc(userId)).cases)); }, [userId]);
  const updateSetting = (key: string, value: string | number | boolean) => setSettings((current) => current.map((setting) => setting.key === key ? { ...setting, value } : setting));
  const settingsByCategory = (category: PlatformSetting['category']) => settings.filter((setting) => setting.category === category);
  const saveSettings = () => void run(async () => {
    const values = Object.fromEntries(settings.map((setting) => [setting.key, setting.value]));
    const response = await api.updateAdminSettings(userId, values);
    setSettings(response.settings);
    setSettingsMessage(t('admin.settingsSaved'));
  });
  return <><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.controlCenter')}</div><h2>{t('admin.dashboard')}</h2></div><span className="badge">{t('admin.adminOnly')}</span></div>{dashboard && <><div className="stats"><Stat label={t("admin.users")} value={String(dashboard.total_users)} /><Stat label={t("nav.remittances")} value={String(dashboard.total_remittances)} /><Stat label={t("admin.waiting")} value={String(dashboard.statuses.filter((item) => !['completed', 'cancelled'].includes(item.status)).reduce((sum, item) => sum + Number(item.operations), 0))} /></div><h3>{t('admin.transferredByDirection')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.direction')}</th><th>{t('admin.operations')}</th><th>{t('admin.sent')}</th><th>{t('admin.received')}</th></tr></thead><tbody>{dashboard.directions.map((item) => <tr key={`${item.direction}-${item.currency}`}><td>{t('direction.'+item.direction) === 'direction.'+item.direction ? item.direction : t('direction.'+item.direction)}</td><td>{item.operations}</td><td>{item.sent_amount} {item.currency}</td><td>{item.received_amount} {item.receive_currency}</td></tr>)}</tbody></table></div><h3>{t('admin.amountsByStatus')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.status')}</th><th>{t('admin.operations')}</th><th>{t('admin.amountInFlow')}</th></tr></thead><tbody>{dashboard.statuses.map((item) => <tr key={`${item.status}-${item.currency}`}><td><span className="badge">{item.status}</span></td><td>{item.operations}</td><td>{item.send_amount} {item.currency}</td></tr>)}</tbody></table></div></>}</section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.controlCenter')}</div><h2>{t('admin.platformSettings')}</h2></div></div><p className="muted">{t('admin.settingsDescription')}</p>{settings.length === 0 ? <p className="muted">{t('common.loading')}</p> : <div className="settings-grid"><SettingsGroup title={t('admin.pricing')} settings={settingsByCategory('pricing')} onChange={updateSetting} /><SettingsGroup title={t('admin.operations')} settings={settingsByCategory('operations')} onChange={updateSetting} /><SettingsGroup title={t('admin.security')} settings={settingsByCategory('security')} onChange={updateSetting} /></div>}<div className="settings-actions"><button onClick={saveSettings} disabled={settings.length === 0}>{t('admin.saveSettings')}</button>{settingsMessage && <span className="success-inline">{settingsMessage}</span>}</div></section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.marketControl')}</div><h2>{t('admin.rateRubCup')}</h2></div>{rate && <span className="badge">{rate.manual_override ? t('admin.manual') : t('admin.official')}</span>}</div>{rate && <p className="muted">{t('admin.current')}: <strong>{rate.rate} CUP por RUB</strong> · {rate.source_label} · fecha {rate.effective_date}</p>}<label>{t('admin.activeRateSource')}<select value={rate?.manual_override ? 'admin_override' : 'official'} disabled={rateSourceBusy || !rate} onChange={(event) => { const source = event.target.value as 'official' | 'admin_override'; setRateSourceBusy(true); void run(async () => { const nextRate = await api.setExchangeRateSource(userId, source); setRate(nextRate); setRateHistory((await api.adminRateHistory(userId)).rates); }).finally(() => setRateSourceBusy(false)); }}><option value="official">{t('admin.officialRate')}</option><option value="admin_override">{t('admin.adminRate')}</option></select></label><p className="muted">{t('admin.rateSourceHelp')}</p><form onSubmit={(event) => { event.preventDefault(); void run(async () => setRate(await api.setExchangeRate(userId, rateValue))); }}><Field name="admin_rate" label={t("admin.defineManualRate")} value={rateValue} onChange={(event) => setRateValue(event.target.value)} placeholder="0.0000000000" /><button disabled={!rateValue}>{t('admin.saveAdminRate')}</button></form><h3>{t('admin.rateHistory')}</h3><div className="table-wrap"><table><thead><tr><th>{t('admin.rate')}</th><th>{t('admin.source')}</th><th>{t('admin.validity')}</th><th>{t('admin.createdBy')}</th></tr></thead><tbody>{rateHistory.map((item) => <tr key={`${item.valid_from}-${item.source}`}><td>{item.rate} CUP/RUB</td><td>{item.source}</td><td>{item.valid_from} — {item.valid_until || t('admin.active')}</td><td>{item.created_by_email || t('admin.officialSystem')}</td></tr>)}</tbody></table></div><p className="muted">{t('admin.rateFallback')}</p></section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.users')}</div><h2>{t('admin.registeredUsers')}</h2></div><span className="badge">{users.length}</span></div><div className="table-wrap"><table><thead><tr><th>{t('admin.user')}</th><th>{t('admin.roleStatus')}</th><th>{t('admin.verification')}</th><th>{t('nav.remittances')}</th><th>{t('admin.createdAt')}</th></tr></thead><tbody>{users.map((item) => <tr key={item.public_id}><td><strong>{item.full_name || t('admin.noName')}</strong><small>{item.email}</small></td><td>{t('role.'+item.role)}<small>{t('status.'+item.status)}</small></td><td>{item.email_verified_at || t('status.pending')}</td><td>{item.remittance_count}</td><td>{item.created_at}</td></tr>)}</tbody></table></div></section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.compliance')}</div><h2>{t('admin.kycReview')}</h2></div><span className="badge">{kycCases.filter(c => c.status === 'pending_review').length} {t('admin.pendingCases')}</span></div>{kycCases.length === 0 ? <p className="muted">{t('admin.noKycCases')}</p> : <div className="list">{kycCases.map((item: any) => <div className="row" key={item.public_id}><div><strong>{item.first_name} {item.last_name} · {item.email}</strong><span>{t('admin.status')}: {t('status.'+item.status)} · {t('admin.documents')}: {item.document_count}</span><span>{item.submitted_at || t('admin.notSubmitted')} {item.review_reason || ''}</span><div className="quick-actions"><button className="secondary" onClick={() => void run(async () => { const docs = (await api.adminKycDocuments(userId, item.public_id)).documents; for (const doc of docs) { const blob = await api.downloadKycDocument(userId, doc.public_id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = doc.original_name; link.click(); URL.revokeObjectURL(url); } })}>{t('admin.downloadDocuments')}</button>{item.status === 'pending_review' && <><input placeholder={t('admin.reason')} value={kycReason[item.public_id] || ''} onChange={e => setKycReason(v => ({...v, [item.public_id]: e.target.value}))}/><button onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'approved', ''); setKycCases((await api.adminKyc(userId)).cases); })}>{t('common.approve')}</button><button className="secondary" onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'rejected', kycReason[item.public_id] || ''); setKycCases((await api.adminKyc(userId)).cases); })}>{t('admin.reject')}</button><button className="secondary" onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'more_information', kycReason[item.public_id] || ''); setKycCases((await api.adminKyc(userId)).cases); })}>{t('admin.requestInformation')}</button></>}</div></div></div>)}</div>}</section><section className="card"><div className="section-heading"><div><div className="eyebrow">{t('admin.audit')}</div><h2>{t('admin.operationsPerformed')}</h2></div><span className="badge">{items.length} {t('admin.records')}</span></div>{items.length === 0 ? <p className="muted">{t('admin.noOperations')}</p> : <div className="table-wrap"><table><thead><tr><th>{t('admin.reference')}</th><th>{t('admin.customer')}</th><th>{t('admin.amount')}</th><th>{t('admin.status')}</th><th>{t('admin.date')}</th></tr></thead><tbody>{items.map((item) => <tr key={item.public_id}><td><strong>{item.reference}</strong><small>{t('direction.'+item.direction) === 'direction.'+item.direction ? item.direction : t('direction.'+item.direction)}</small></td><td>{item.customer_name || item.customer_email}<small>{item.customer_email}</small></td><td>{item.send_amount} {item.send_currency}<small>→ {item.receive_amount} {item.receive_currency}</small></td><td><span className="badge">{t('status.'+item.status)}</span><small>{t('status.'+item.compliance_status) === 'status.'+item.compliance_status ? item.compliance_status : t('status.'+item.compliance_status)}</small></td><td>{new Date(item.created_at).toLocaleString(locale)}</td></tr>)}</tbody></table></div>}</section></>;
}

function SettingsGroup({ title, settings, onChange }: { title: string; settings: PlatformSetting[]; onChange: (key: string, value: string | number | boolean) => void }) {
  const t = useT();
  return <section className="settings-group"><h3>{title}</h3>{settings.map((setting) => <label key={setting.key}>{setting.label}{setting.type === 'boolean' ? <span className="toggle-row"><input type="checkbox" checked={Boolean(setting.value)} onChange={(event) => onChange(setting.key, event.target.checked)} /><span>{setting.value ? t('admin.enabled') : t('admin.disabled')}</span></span> : setting.type === 'enum' ? <select value={String(setting.value)} onChange={(event) => onChange(setting.key, event.target.value)}>{(setting.options ?? []).map((option) => <option key={option} value={option}>{option === 'official' ? t('admin.officialRate') : option === 'admin_override' ? t('admin.adminRate') : option}</option>)}</select> : <input type={setting.type === 'integer' ? 'number' : 'text'} inputMode={setting.type === 'integer' ? 'numeric' : 'decimal'} value={String(setting.value)} onChange={(event) => onChange(setting.key, event.target.value)} />}</label>)}</section>;
}

function StatusBadge({status}:{status:string}) {
  const t = useT();
  const normalized=(status||'pending').toLowerCase();
  const tone=['completed','approved','active','verified','success','received'].includes(normalized)?'success':['rejected','failed','cancelled','error'].includes(normalized)?'danger':['pending_review','compliance_review','awaiting_payment','pending'].includes(normalized)?'warning':'info';
  return <span className={`status-pill status-${tone}`}>{t(`status.${normalized}`) === `status.${normalized}` ? status.replace(/_/g,' ') : t(`status.${normalized}`)}</span>;
}

function NavButton({ active, onClick, children, icon }: { active: boolean; onClick: () => void; children: string; icon?: string }) { return <button className={active ? 'nav-button active' : 'nav-button'} onClick={onClick}>{icon && <Icon name={icon} />}<span>{children}</span></button>; }
function Logo({ language, monochrome = false }: { language: Language; monochrome?: boolean }) {
  const slogan = translate(language, 'brand.slogan');
  return <div className={monochrome ? 'logo logo-monochrome' : 'logo'} aria-label={`SEDERG_PAY — ${slogan}`}>
    <img className="logo-symbol logo-brand-image" src={import.meta.env.BASE_URL + "assets/sederg-pay/logos/sederg-pay-isotipo.svg"} alt="" aria-hidden="true" />
    <span className="logo-wordmark logo-wordmark-accessible"><strong>SEDERG</strong><b>_PAY</b><small>{slogan}</small></span>
  </div>;
}
function Brand({ language }: { language: Language }) { return <Logo language={language} />; }
function LanguageSwitch({ language, onChange }: { language: Language; onChange: (language: Language) => void }) {
  const t = useT();
  return <div className="language-switch" role="group" aria-label="Idioma">
    {(Object.keys(languageLabels) as Language[]).map((option) => <button key={option} type="button" className={language === option ? 'language-option active' : 'language-option'} onClick={() => onChange(option)} aria-pressed={language === option}>{languageLabels[option]}</button>)}
  </div>;
}
function Header({ language, onLanguageChange }: { language: Language; onLanguageChange: (language: Language) => void }) {
  return <header><Brand language={language} /><div className="header-tools"><LanguageSwitch language={language} onChange={onLanguageChange} /><span className="sandbox"><i /> {translate(language, 'common.sandbox')}</span></div></header>;
}
function RateBanner() {
  const t = useT();
  const [rate, setRate] = useState<ExchangeRate | null>(null);
  const [error, setError] = useState('');
  useEffect(() => { api.currentExchangeRate().then(setRate).catch((reason: Error) => setError(reason.message)); }, []);
  if (error) return <p className="alert">{t('rate.unavailable')}</p>;
  if (!rate) return <p className="muted">{t('rate.loading')}</p>;
  return <div className="rate-banner" role="status"><strong>{t('rate.current')}: {rate.rate} CUP por RUB</strong><span>{rate.source_label} · {rate.effective_date}</span></div>;
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


function AdminFundingPanel({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const [methods,setMethods]=useState<FundingMethod[]>([]);
  const [accounts,setAccounts]=useState<FundingAccount[]>([]);
  const [country,setCountry]=useState<'RU'|'CU'>('RU');
  const [bankCode,setBankCode]=useState('sberbank');
  const [bankName,setBankName]=useState('Sberbank');
  const [accountIdentifier,setAccountIdentifier]=useState('');
  const [legalEntityName,setLegalEntityName]=useState('');
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
      await api.createFundingAccount(userId,{country_code:country,currency_code:currency,bank_code:bankCode,bank_name:bankName,account_holder_name:'SEDERG_PAY',legal_entity_name:legalEntityName||null,account_identifier:accountIdentifier,environment:'sandbox',status:'active',methods:selectedMethods,notes});
      setAccountIdentifier('');setLegalEntityName('');setNotes('');setSelectedMethods([]);
      await reload();
    }finally{setSaving(false);}
  });
  return <div className="feature-view"><div className="view-heading"><div><span className="eyebrow">Administración financiera</span><h2>Métodos de pago y cuentas receptoras</h2><p className="muted">Las cuentas registradas pertenecen a SEDERG_PAY. En esta fase solo se habilita sandbox.</p></div><span className="status-pill status-info">SANDBOX</span></div>
  <section className="card"><div className="section-heading"><div><h3>Catálogo de métodos de fondeo</h3><p className="muted">Independiente de los métodos de desembolso al beneficiario.</p></div></div>
  {methods.map(method=><div className="funding-method-row" key={method.code}><div><strong>{method.name}</strong><small>{method.country_code} · {method.currency_code} · {method.method_type} · {method.code}</small></div><label className="switch-label"><input type="checkbox" checked={Boolean(method.is_active)} onChange={e=>void run(async()=>{await api.updateFundingMethod(userId,method.code,e.target.checked);await reload();})}/> Activo</label></div>)}</section>
  <section className="card"><div className="section-heading"><div><h3>Registrar cuenta receptora</h3><p className="muted">El número se cifra antes de almacenarse; solo se muestra enmascarado después del alta.</p></div></div>
  <form className="funding-account-form" onSubmit={e=>{e.preventDefault();create();}}>
    <label>País<select value={country} onChange={e=>{setCountry(e.target.value as 'RU'|'CU');setSelectedMethods([]);if(e.target.value==='RU'){setBankCode('sberbank');setBankName('Sberbank');}else{setBankCode('banco_metropolitano');setBankName('Banco Metropolitano');}}}><option value="RU">Rusia</option><option value="CU">Cuba</option></select></label>
    <label>Moneda<input value={currency} readOnly/></label>
    <label>Código del banco<input value={bankCode} onChange={e=>setBankCode(e.target.value)} required/></label>
    <label>Nombre del banco<input value={bankName} onChange={e=>setBankName(e.target.value)} required/></label>
    <label>Titular<input value="SEDERG_PAY" readOnly/></label>
    <label>Denominación jurídica (si aplica)<input value={legalEntityName} onChange={e=>setLegalEntityName(e.target.value)} placeholder="Nombre legal validado por el banco"/></label>
    <label className="funding-full">Número de cuenta / identificador bancario<input value={accountIdentifier} onChange={e=>setAccountIdentifier(e.target.value)} required autoComplete="off" /></label>
    <div className="funding-full"><strong>Métodos compatibles</strong>{compatible.length===0?<p className="muted">No hay métodos definidos para este país.</p>:compatible.map(method=><label className="funding-checkbox" key={method.code}><input type="checkbox" checked={selectedMethods.includes(method.code)} onChange={()=>toggle(method.code)}/>{method.name} <small>{method.method_type==='instant_qr'?'QR sandbox':'Transferencia'}</small></label>)}</div>
    <label className="funding-full">Notas<input value={notes} onChange={e=>setNotes(e.target.value)}/></label>
    <button type="submit" disabled={saving||!accountIdentifier||!selectedMethods.length}>{saving?'Guardando…':'Guardar cuenta sandbox'}</button>
  </form></section>
  <section className="card"><div className="section-heading"><div><h3>Cuentas receptoras registradas</h3><p className="muted">Para cambiar de cuenta, registra la nueva y desactiva la anterior. Los depósitos existentes conservan su fotografía original.</p></div></div>
  {accounts.length===0?<p className="muted">Todavía no hay cuentas configuradas.</p>:accounts.map(account=><article className="funding-account-row" key={account.public_id}><div><strong>{account.bank_name} · {account.country_code}/{account.currency_code}</strong><small>Titular: {account.account_holder_name} · Cuenta {account.account_mask}</small><small>Métodos: {account.methods||'—'} · {account.environment}</small></div><div><span className={account.status==='active'?'status-pill status-success':'status-pill status-muted'}>{account.status}</span><button className="secondary compact-button" onClick={()=>void run(async()=>{await api.updateFundingAccount(userId,account.public_id,account.status==='active'?'inactive':'active');await reload();})}>{account.status==='active'?'Desactivar':'Activar'}</button></div></article>)}</section>
  </div>;
}
