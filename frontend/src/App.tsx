import { ChangeEvent, FormEvent, useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { api, AdminDashboard, AdminOperation, AdminRate, AdminUser, Beneficiary, ExchangeRate, PlatformSetting, Quote, Remittance, RemittanceDetail, User, isDevelopmentAuth, paymentMethods } from './api';
import { getInitialLanguage, Language, languageLabels, translate } from './i18n';

type View = 'summary' | 'beneficiaries' | 'quote' | 'remittances' | 'profile' | 'kyc' | 'admin';
const storedUserId = isDevelopmentAuth ? localStorage.getItem('sederg_user') ?? '' : '';

export function App() {
  const [user, setUser] = useState<User | null>(null);
  const [language, setLanguage] = useState<Language>(getInitialLanguage);
  const [authReady, setAuthReady] = useState(!storedUserId);
  const [publicView, setPublicView] = useState<'landing' | 'auth'>(() => {
    const params = new URLSearchParams(window.location.search);
    return params.has('verify_email') || params.has('token') || params.get('access') ? 'auth' : 'landing';
  });
  const [authView, setAuthView] = useState<'login' | 'register'>(() => new URLSearchParams(window.location.search).get('access') === 'register' ? 'register' : 'login');
  const [view, setView] = useState<View>('summary');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  const changeLanguage = (next: Language) => {
    setLanguage(next);
    localStorage.setItem('sederg_language', next);
    document.documentElement.lang = next;
  };

  useEffect(() => {
    if (!storedUserId) return;
    api.me(storedUserId).then(setUser).catch(() => localStorage.removeItem('sederg_user')).finally(() => setAuthReady(true));
  }, []);

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    setMessage('');
    try { await action(); } catch (error) { setMessage((error as Error).message); } finally { setBusy(false); }
  };

  const signOut = () => {
    localStorage.removeItem('sederg_user');
    localStorage.removeItem('sederg_access_token');
    setUser(null);
    setView('summary');
  };

  if (!authReady) return <main className="shell narrow"><Header language={language} onLanguageChange={changeLanguage} /><section className="card"><p className="muted">Restaurando tu sesión…</p></section></main>;
  if (!user && publicView === 'landing') return <Landing language={language} onLanguageChange={changeLanguage} onLogin={() => { setAuthView('login'); setPublicView('auth'); }} onRegister={() => { setAuthView('register'); setPublicView('auth'); }} />;
  if (!user) return <AuthScreen language={language} onLanguageChange={changeLanguage} mode={authView} setMode={setAuthView} busy={busy} message={message} run={run} onBack={() => setPublicView('landing')} onAuthenticated={(next) => {
    localStorage.setItem('sederg_user', next.public_id);
    setUser(next);
  }} />;

  return <main className="shell">
    <Header language={language} onLanguageChange={changeLanguage} />
    <RateBanner />
    <section className="hero">
      <div><div className="eyebrow">Panel de operaciones</div><h1>Hola, {user.first_name}.</h1><p>Gestiona tus remesas con claridad y trazabilidad.</p></div>
      <div className="hero-mark" aria-hidden="true">↗</div>
    </section>
    <div className="workspace">
      <nav className="menu" aria-label="Navegación principal">
        <strong>Tu plataforma</strong>
        <NavButton active={view === 'summary'} onClick={() => setView('summary')}>{translate(language, 'nav.summary')}</NavButton>
        <NavButton active={view === 'beneficiaries'} onClick={() => setView('beneficiaries')}>{translate(language, 'nav.beneficiaries')}</NavButton>
        <NavButton active={view === 'quote'} onClick={() => setView('quote')}>{translate(language, 'nav.quote')}</NavButton>
        <NavButton active={view === 'remittances'} onClick={() => setView('remittances')}>{translate(language, 'nav.remittances')}</NavButton><NavButton active={view === 'profile'} onClick={() => setView('profile')}>Mi perfil</NavButton><NavButton active={view === 'kyc'} onClick={() => setView('kyc')}>Verificación KYC</NavButton>
        {user.role === 'admin' && <NavButton active={view === 'admin'} onClick={() => setView('admin')}>{translate(language, 'nav.admin')}</NavButton>}
        <button className="secondary menu-exit" onClick={signOut}>{translate(language, 'nav.logout')}</button>
      </nav>
      <section className="content">
        <div className="toolbar"><span>{user.email} · <span className="role">{user.role === 'admin' ? 'Administrador' : 'Cliente'}</span></span><span className="sandbox"><i /> SANDBOX</span></div>
        {view === 'summary' && <Summary userId={user.public_id} setView={setView} run={run} />}
        {view === 'beneficiaries' && <Beneficiaries userId={user.public_id} run={run} />}
        {view === 'quote' && <QuotePanel userId={user.public_id} run={run} />}
        {view === 'remittances' && <Remittances userId={user.public_id} run={run} />}{view === 'profile' && <ProfilePanel userId={user.public_id} run={run} />}{view === 'kyc' && <KycPanel userId={user.public_id} run={run} />}
        {view === 'admin' && user.role === 'admin' && <AdminOperations userId={user.public_id} run={run} />}
        {message && <Alert text={message} />}
      </section>
    </div>
    <footer>SEDERG_PAY · entorno sandbox · no mueve dinero real</footer>
  </main>;
}

function Landing({ language, onLanguageChange, onLogin, onRegister }: { language: Language; onLanguageChange: (language: Language) => void; onLogin: () => void; onRegister: () => void }) {
  const text = (key: string) => translate(language, key);
  const [rate, setRate] = useState<ExchangeRate | null>(null);
  useEffect(() => { api.currentExchangeRate().then(setRate).catch(() => undefined); }, []);
  const currentRate = rate ? Number(rate.rate).toFixed(5) : '—';
  const receiveExample = rate ? (10000 * Number(rate.rate)).toLocaleString('es-ES', { maximumFractionDigits: 2 }) : '—';
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
      <div className="landing-visual" aria-label="Resumen de una operación">
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
      <div className="feature-grid"><Feature icon="↗" title={text('landing.featureClear')} text={text('landing.featureClearText')} /><Feature icon="◈" title={text('landing.featureSecure')} text={text('landing.featureSecureText')} /><Feature icon="◎" title={text('landing.featureTrack')} text={text('landing.featureTrackText')} /></div>
    </section>
    <section className="landing-steps"><div className="section-intro"><span className="eyebrow">{text('landing.stepsEyebrow')}</span><h2>{text('landing.stepsTitle')}<br /><em>{text('landing.stepsAccent')}</em></h2></div><div className="steps"><Step number="01" title={text('landing.stepOne')} text={text('landing.stepOneText')} /><Step number="02" title={text('landing.stepTwo')} text={text('landing.stepTwoText')} /><Step number="03" title={text('landing.stepThree')} text={text('landing.stepThreeText')} /></div></section>
    <footer className="landing-footer"><Brand language={language} /><span>{text('brand.slogan')}</span><span>© 2026 SEDERG_PAY</span></footer>
  </main>;
}

function Feature({ icon, title, text }: { icon: string; title: string; text: string }) { return <article className="feature"><span className="feature-icon">{icon}</span><h3>{title}</h3><p>{text}</p></article>; }
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
        setNotification('Tu cuenta ha sido activada correctamente. Ya puedes iniciar sesión.');
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
    <p className="muted">{isDevelopmentAuth ? text('auth.description') : 'Inicia sesión con tu proveedor de identidad.'}</p>
    <div className="tabs"><button className={mode === 'login' ? 'tab active' : 'tab'} onClick={() => setMode('login')}>{text('common.login')}</button><button className={mode === 'register' ? 'tab active' : 'tab'} onClick={() => setMode('register')}>{text('common.register')}</button></div>
    {!isDevelopmentAuth && <p className="alert">Configura el proveedor OIDC para habilitar el acceso en producción.</p>}
    {activationState === 'activating' && <div className="activation-panel"><span className="activation-spinner" /><strong>{text('auth.activating')}</strong><p>Estamos verificando tu enlace de forma segura.</p></div>}
    {activationState === 'error' && <div className="alert" role="alert">El enlace no es válido o ha caducado. Solicita un nuevo correo desde el registro.</div>}
    {verificationEmail && activationState === 'idle' && <div className="verification-panel"><div className="success-alert" role="status">{notification || <>Se ha enviado un correo de verificación a <strong>{verificationEmail}</strong>. Revisa tu bandeja de entrada y spam.</>}</div><p className="muted">Abre el enlace del correo para activar tu cuenta automáticamente. No necesitas introducir la contraseña de nuevo.</p><button type="button" className="secondary" disabled={busy} onClick={() => void run(async () => { await api.resendVerification(verificationEmail); setNotification('Se ha reenviado el enlace de activación a tu correo.'); })}>Reenviar enlace</button></div>}
    {notification && activationState === 'success' && <div className="success-alert" role="status">{notification || text('auth.activationSuccess')}</div>}
    {!verificationEmail && isDevelopmentAuth && mode === 'login' && <form onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      void run(async () => onAuthenticated(await api.login(String(data.get('email')), String(data.get('password')))));
    }}><Field name="email" label="Email" type="email" /><Field name="password" label="Contraseña" type="password" /><button disabled={busy}>{busy ? 'Validando…' : 'Iniciar sesión'}</button></form>}
    {!verificationEmail && isDevelopmentAuth && mode === 'register' && <form onSubmit={(event) => {
      event.preventDefault(); const data = new FormData(event.currentTarget);
      void run(async () => {
        const email = String(data.get('email')); const password = String(data.get('password'));
        const result = await api.register({ email, password, first_name: data.get('first_name'), last_name: data.get('last_name'), residence_country_code: data.get('country'), consent_policy_version: 'v1', accept_terms: true, accept_privacy: true });
        setVerificationEmail(email);
        setVerificationToken('');
        setNotification(`Cuenta creada. Se ha enviado un correo con el código de verificación a ${result.email}.`);
      });
    }}><Field name="first_name" label="Nombre" /><Field name="last_name" label="Apellidos" /><Field name="email" label="Email" type="email" /><Field name="password" label="Contraseña (12+ caracteres)" type="password" /><label>País de residencia<select name="country" defaultValue="RU"><option value="RU">Rusia</option><option value="CU">Cuba</option></select></label><button disabled={busy}>{busy ? 'Creando…' : 'Crear cuenta'}</button></form>}
    {message && <Alert text={message} />}
  </section></main>;
}

function ProfilePanel({userId,run}:{userId:string;run:(action:()=>Promise<void>)=>Promise<void>}){
 const [profile,setProfile]=useState<any>(null);useEffect(()=>{void run(async()=>setProfile(await api.profile(userId)));},[userId]);
 if(!profile)return <section className="card"><h2>Mi perfil</h2><p className="muted">Cargando perfil…</p></section>;
 return <section className="card"><h2>Mi perfil</h2><p className="muted">Consulta y actualiza tus datos personales. El correo de acceso no se modifica desde este formulario.</p><form onSubmit={e=>{e.preventDefault();const d=new FormData(e.currentTarget);void run(async()=>setProfile(await api.updateProfile(userId,{first_name:d.get('first_name'),last_name:d.get('last_name'),middle_name:d.get('middle_name'),phone_e164:d.get('phone_e164'),date_of_birth:d.get('date_of_birth')||null,residence_country_code:d.get('residence_country_code'),nationality_country_code:d.get('nationality_country_code'),address_line:d.get('address_line'),city:d.get('city'),postal_code:d.get('postal_code')})));}}><Field name="first_name" label="Nombre" defaultValue={profile.first_name}/><Field name="last_name" label="Apellidos" defaultValue={profile.last_name}/><Field name="middle_name" label="Segundo nombre" required={false} defaultValue={profile.middle_name||''}/><label>Correo electrónico<input type="email" value={profile.email} disabled /></label><Field name="phone_e164" label="Teléfono" type="tel" required={false} defaultValue={profile.phone_e164||''}/><Field name="date_of_birth" label="Fecha de nacimiento" type="date" required={false} defaultValue={profile.date_of_birth||''}/><label>País de residencia<select name="residence_country_code" defaultValue={profile.residence_country_code||'RU'}><option value="RU">Rusia</option><option value="CU">Cuba</option></select></label><label>Nacionalidad<select name="nationality_country_code" defaultValue={profile.nationality_country_code||'CU'}><option value="CU">Cuba</option><option value="RU">Rusia</option></select></label><Field name="address_line" label="Dirección" required={false} defaultValue={profile.address_line||''}/><Field name="city" label="Ciudad" required={false} defaultValue={profile.city||''}/><Field name="postal_code" label="Código postal" required={false} defaultValue={profile.postal_code||''}/><p className="muted">Estado: {profile.status} · ID: {profile.public_id}</p><button>Guardar perfil</button></form></section>;
}
function KycPanel({userId,run}:{userId:string;run:(action:()=>Promise<void>)=>Promise<void>}){
 const [kyc,setKyc]=useState<any>(null);const refresh=()=>run(async()=>setKyc(await api.kyc(userId)));useEffect(()=>{refresh();},[userId]);
 return <section className="card"><h2>Verificación de identidad (KYC)</h2><p className="muted">Proceso de comprobación de identidad y domicilio para los controles de prevención de fraude y cumplimiento.</p><div className="stats"><Stat label="Estado KYC" value={kyc?.status||'Cargando'}/></div>{kyc?.review_reason&&<p className="alert">Observación: {kyc.review_reason}</p>}<form onSubmit={e=>{e.preventDefault();const form=e.currentTarget;const d=new FormData(form);const file=d.get('file') as File;void run(async()=>{if(!file||!file.size)throw new Error('Selecciona un archivo');await api.uploadKycDocument(userId,file,String(d.get('document_type')));await refresh();form.reset();});}}><label>Tipo de documento<select name="document_type"><option value="identity">Documento de identidad</option><option value="address">Comprobante de domicilio</option><option value="selfie">Selfie (opcional)</option></select></label><label>Archivo PDF, JPG o PNG (máximo 5 MB)<input name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required/></label><button>Subir documento</button></form><h3>Documentos enviados</h3>{!kyc?.documents?.length?<p className="muted">Todavía no has cargado documentos.</p>:<div className="list">{kyc.documents.map((d:any)=><div className="row" key={d.public_id}><strong>{d.document_type}</strong><span>{d.original_name} · {(d.file_size/1024).toFixed(0)} KB</span></div>)}</div>}<button disabled={!kyc||!kyc.documents?.some((d:any)=>d.document_type==='identity')||!kyc.documents?.some((d:any)=>d.document_type==='address')||['approved','pending_review'].includes(kyc.status)} onClick={()=>void run(async()=>{await api.submitKyc(userId);setKyc(await api.kyc(userId));})}>Enviar a revisión</button><p className="muted">Se requieren documento de identidad y comprobante de domicilio. Los archivos se almacenan fuera del directorio público.</p></section>;
}

function Summary({ userId, setView, run }: { userId: string; setView: (view: View) => void; run: (action: () => Promise<void>) => Promise<void> }) {
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  useEffect(() => { void run(async () => setRemittances(await api.remittances(userId))); }, [userId]);
  return <><div className="stats"><Stat label="Remesas creadas" value={String(remittances.length)} /><Stat label="Completadas" value={String(remittances.filter((item) => item.status === 'completed').length)} /><Stat label="Estado de cuenta" value="Activo" /></div><section className="card"><h2>¿Qué quieres hacer?</h2><div className="quick-actions"><button onClick={() => setView('quote')}>Cotizar una remesa</button><button className="secondary" onClick={() => setView('beneficiaries')}>Gestionar beneficiarios</button><button className="secondary" onClick={() => setView('remittances')}>Ver mis remesas</button></div></section></>;
}

function Beneficiaries({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
 const [items,setItems]=useState<Beneficiary[]>([]); const [method,setMethod]=useState('cash_pickup'); const [editing,setEditing]=useState<Beneficiary|null>(null);
 const refresh=()=>run(async()=>setItems(await api.beneficiaries(userId))); useEffect(()=>{refresh();},[userId]);
 const submit=(event:FormEvent<HTMLFormElement>)=>{event.preventDefault();const form=event.currentTarget;void run(async()=>{const d=new FormData(form);const body={country_code:d.get('country'),first_name:d.get('first_name'),last_name:d.get('last_name'),phone_e164:d.get('phone_e164'),email:d.get('email'),payout_method_code:method,payout_provider:d.get('payout_provider')||undefined,payout_account_number:d.get('payout_account_number')||undefined,payout_card_number:d.get('payout_card_number')||undefined};if(editing)await api.updateBeneficiary(userId,editing.public_id,body);else await api.createBeneficiary(userId,body);setEditing(null);setMethod('cash_pickup');form.reset();await refresh();});};
 return <section className="card"><h2>Beneficiarios</h2><p className="muted">Puedes editar los datos de cada beneficiario. Por seguridad, al modificar una cuenta o tarjeta deberás introducir nuevamente sus datos completos.</p><form key={editing?.public_id ?? 'new-beneficiary'} onSubmit={submit}><h3>{editing?'Editar beneficiario':'Añadir beneficiario'}</h3><Field name="first_name" label="Nombre" defaultValue={editing?.first_name}/><Field name="last_name" label="Apellidos" defaultValue={editing?.last_name}/><Field name="phone_e164" label="Teléfono" type="tel" defaultValue={editing?.phone_e164??''}/><Field name="email" label="Email" type="email" defaultValue={editing?.email??''}/><label>País<select name="country" defaultValue={editing?.country_code??'CU'}><option value="CU">Cuba</option><option value="RU">Rusia</option></select></label><label>Método de pago<select value={method} onChange={e=>setMethod(e.target.value)}>{paymentMethods.map(m=><option key={m.code} value={m.code}>{m.name}</option>)}</select></label>{method==='bank_account'&&<><Field name="payout_provider" label="Banco o proveedor" defaultValue={editing?.payout_provider??''}/><Field name="payout_account_number" label="Número de cuenta (déjalo vacío para conservar el actual)" inputMode="numeric" pattern="[0-9]{6,34}" required={!editing || editing.payout_method_code !== 'bank_account'}/></>}{method==='card_transfer'&&<Field name="payout_card_number" label="Número de tarjeta (déjalo vacío para conservar la actual)" inputMode="numeric" pattern="[0-9]{12,19}" required={!editing || editing.payout_method_code !== 'card_transfer'}/>}{method!=='cash_pickup'&&<p className="muted">Los datos financieros se cifran y nunca se muestran completos.</p>}<div className="quick-actions"><button>{editing?'Guardar cambios':'Guardar beneficiario'}</button>{editing&&<button type="button" className="secondary" onClick={()=>{setEditing(null);setMethod('cash_pickup');}}>Cancelar</button>}</div></form><div className="list">{items.map(item=><div className="row" key={item.public_id}><div><strong>{item.first_name} {item.last_name}</strong><span>{item.country_code} · {item.phone_e164} · {item.email}</span><span>{item.payout_method_code} {item.payout_provider??''} {item.payout_account_last4?'· cuenta ****'+item.payout_account_last4:''} {item.payout_card_last4?'· tarjeta ****'+item.payout_card_last4:''}</span></div><span className="badge">{item.status}</span><button className="secondary" type="button" onClick={()=>{setEditing(item);setMethod(item.payout_method_code);}}>Editar</button></div>)}</div></section>;
}

function QuotePanel({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const [quote, setQuote] = useState<Quote | null>(null); const [created, setCreated] = useState<Remittance | null>(null); const [beneficiaries, setBeneficiaries] = useState<Beneficiary[]>([]); const [method, setMethod] = useState('cash_pickup'); const [beneficiaryId, setBeneficiaryId] = useState('');
  useEffect(() => { void run(async () => setBeneficiaries(await api.beneficiaries(userId))); }, [userId]);
  const matchingBeneficiaries = beneficiaries.filter((item) => item.country_code === 'CU' && item.payout_method_code === method);
  useEffect(() => { if (!matchingBeneficiaries.some((item) => item.public_id === beneficiaryId)) setBeneficiaryId(matchingBeneficiaries[0]?.public_id ?? ''); }, [method, beneficiaries, beneficiaryId]);
  return <section className="card"><h2>Cotizar y crear remesa</h2>{beneficiaries.length === 0 && <p className="alert">Primero crea un beneficiario con todos sus datos y un método de pago.</p>}<form onSubmit={(event) => { event.preventDefault(); void run(async () => { const data = new FormData(event.currentTarget); if (!beneficiaryId) throw new Error('Selecciona un beneficiario compatible con el método de pago'); setQuote(await api.quote(userId, { source_country_code: 'RU', destination_country_code: 'CU', send_amount: data.get('amount'), send_currency: 'RUB', receive_currency: 'CUP', payout_method_code: method })); }); }}><label>Beneficiario<select value={beneficiaryId} onChange={(event) => setBeneficiaryId(event.target.value)} required><option value="">Selecciona un beneficiario</option>{matchingBeneficiaries.map((item) => <option key={item.public_id} value={item.public_id}>{item.first_name} {item.last_name} · {item.phone_e164}</option>)}</select></label><label>Método de pago al beneficiario<select value={method} onChange={(event) => setMethod(event.target.value)}>{paymentMethods.map((item) => <option key={item.code} value={item.code}>{item.name}</option>)}</select></label>{matchingBeneficiaries.length === 0 && <p className="muted">No hay un beneficiario de Cuba configurado para este método. Créalo desde “Beneficiarios”.</p>}<Field name="amount" label="Enviar RUB" defaultValue="15000.00" /><button disabled={!beneficiaryId}>Calcular cotización</button></form>{quote && <div className="result"><strong>{quote.receive_amount} CUP</strong><span>Tasa: {quote.exchange_rate} CUP por RUB · {quote.exchange_rate_source} · día {quote.exchange_rate_effective_date}</span><span>Comisión: {quote.fee_amount} RUB · vence {quote.expires_at}</span><button onClick={() => void run(async () => { const remittance = await api.createRemittance(userId, { quote_public_id: quote.public_id, beneficiary_public_id: beneficiaryId }); setCreated(remittance); setQuote(null); })}>Crear remesa</button></div>}{created && <PaymentQr remittance={created} />}</section>;
}

function Remittances({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
  const [items, setItems] = useState<Remittance[]>([]); const [selected, setSelected] = useState<RemittanceDetail | null>(null); const refresh = () => run(async () => setItems(await api.remittances(userId)));
  useEffect(() => { refresh(); }, [userId]);
  return <section className="card"><h2>Mis remesas</h2>{items.length === 0 ? <p className="muted">Aún no tienes remesas.</p> : items.map((item) => <div className="row" key={item.public_id}><div><strong>{item.reference}</strong><span>{item.send_amount} {item.send_currency} → {item.receive_amount} {item.receive_currency}</span></div><span className="badge">{item.status}</span><button className="secondary" onClick={() => void run(async () => setSelected(await api.remittance(userId, item.public_id)))}>Ver detalles</button>{item.status === 'awaiting_payment' && <button className="secondary" onClick={() => void run(async () => { const deposit = await api.deposit(userId, item.public_id); alert(`Depósito sandbox: ${deposit.payment_reference}`); refresh(); })}>Depósito</button>}{item.status === 'compliance_review' && <button onClick={() => void run(async () => { await api.review(userId, item.public_id, 'approved'); refresh(); })}>Aprobar sandbox</button>}{item.status === 'approved' && <button onClick={() => void run(async () => { const payout = await api.payout(userId, item.public_id); await api.completePayout(userId, payout.public_id); refresh(); })}>Payout sandbox</button>}{item.status === 'reconciliation_pending' && <button onClick={() => void run(async () => { await api.reconcile(userId, item.public_id); refresh(); })}>Conciliar</button>}</div>)}{selected && <RemittanceDetails detail={selected} onClose={() => setSelected(null)} />}</section>;
}

function PaymentQr({ remittance }: { remittance: Remittance }) {
  const [src, setSrc] = useState('');
  useEffect(() => { if (!remittance.payment_qr_payload) return; void QRCode.toDataURL(remittance.payment_qr_payload, { width: 220, margin: 2 }).then(setSrc); }, [remittance.payment_qr_payload]);
  return <div className="payment-panel"><div><div className="eyebrow">Pago a la plataforma</div><h3>Transferencia creada: {remittance.reference}</h3><p>Transfiere <strong>{remittance.payment_instructions?.amount ?? remittance.send_amount} {remittance.payment_instructions?.currency ?? remittance.send_currency}</strong> usando la referencia de la operación.</p><p className="muted">El QR contiene el importe, moneda, referencia y destino configurado. {remittance.receipt_email_sent ? 'El comprobante fue enviado a tu email.' : 'El comprobante estará disponible en el detalle.'}</p></div>{src && <img className="payment-qr" src={src} alt={`QR de pago ${remittance.reference}`} />}</div>;
}

function RemittanceDetails({ detail, onClose }: { detail: RemittanceDetail; onClose: () => void }) {
  return <div className="detail-panel"><div className="section-heading"><div><div className="eyebrow">Comprobante de operación</div><h2>{detail.reference}</h2></div><button className="secondary" onClick={onClose}>Cerrar</button></div><div className="detail-grid"><Detail label="Estado" value={detail.status} /><Detail label="Cumplimiento" value={detail.compliance_status} /><Detail label="Enviado" value={`${detail.send_amount} ${detail.send_currency}`} /><Detail label="Recibido" value={`${detail.receive_amount} ${detail.receive_currency}`} /><Detail label="Comisión" value={`${detail.fee_amount} ${detail.fee_currency}`} /><Detail label="Total a transferir" value={`${detail.total_debit_amount} ${detail.send_currency}`} /><Detail label="Tasa" value={`${detail.exchange_rate} CUP por RUB`} /><Detail label="Creada" value={detail.created_at ?? '—'} /><Detail label="Beneficiario" value={`${detail.beneficiary_first_name} ${detail.beneficiary_last_name}`} /><Detail label="Contacto" value={detail.beneficiary_phone || detail.beneficiary_email || '—'} /><Detail label="Método" value={`${detail.payout_method_code}${detail.payout_provider ? ` · ${detail.payout_provider}` : ''}`} /><Detail label="Cuenta/tarjeta" value={detail.payout_account_last4 ? `Cuenta ****${detail.payout_account_last4}` : detail.payout_card_last4 ? `Tarjeta ****${detail.payout_card_last4}` : '—'} /></div>{detail.payment_qr_payload && <PaymentQr remittance={detail} />}<h3>Historial de estados</h3><div className="timeline">{detail.state_events.map((event) => <div className="timeline-item" key={`${event.to_status}-${event.created_at}`}><strong>{event.to_status}</strong><span>{event.created_at}</span><small>{event.reason}</small></div>)}</div></div>;
}

function Detail({ label, value }: { label: string; value: string }) { return <div className="detail-item"><span>{label}</span><strong>{value}</strong></div>; }

function AdminOperations({ userId, run }: { userId: string; run: (action: () => Promise<void>) => Promise<void> }) {
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
    setSettingsMessage('Configuración guardada y auditada correctamente.');
  });
  return <><section className="card"><div className="section-heading"><div><div className="eyebrow">Centro de control</div><h2>Cuadro de mando</h2></div><span className="badge">Solo administradores</span></div>{dashboard && <><div className="stats"><Stat label="Usuarios" value={String(dashboard.total_users)} /><Stat label="Remesas" value={String(dashboard.total_remittances)} /><Stat label="En espera" value={String(dashboard.statuses.filter((item) => !['completed', 'cancelled'].includes(item.status)).reduce((sum, item) => sum + Number(item.operations), 0))} /></div><h3>Dinero transferido por dirección</h3><div className="table-wrap"><table><thead><tr><th>Dirección</th><th>Operaciones</th><th>Enviado</th><th>Recibido</th></tr></thead><tbody>{dashboard.directions.map((item) => <tr key={`${item.direction}-${item.currency}`}><td>{item.direction}</td><td>{item.operations}</td><td>{item.sent_amount} {item.currency}</td><td>{item.received_amount} {item.receive_currency}</td></tr>)}</tbody></table></div><h3>Importes por estado</h3><div className="table-wrap"><table><thead><tr><th>Estado</th><th>Operaciones</th><th>Importe en espera/flujo</th></tr></thead><tbody>{dashboard.statuses.map((item) => <tr key={`${item.status}-${item.currency}`}><td><span className="badge">{item.status}</span></td><td>{item.operations}</td><td>{item.send_amount} {item.currency}</td></tr>)}</tbody></table></div></>}</section><section className="card"><div className="section-heading"><div><div className="eyebrow">Centro de control</div><h2>Configuración de la plataforma</h2></div></div><p className="muted">Los cambios se aplican a nuevas operaciones. Las credenciales SMTP, OIDC, cifrado y base de datos permanecen protegidas en el entorno de despliegue.</p>{settings.length === 0 ? <p className="muted">Cargando configuración…</p> : <div className="settings-grid"><SettingsGroup title="Precios y cotizaciones" settings={settingsByCategory('pricing')} onChange={updateSetting} /><SettingsGroup title="Operaciones" settings={settingsByCategory('operations')} onChange={updateSetting} /><SettingsGroup title="Seguridad" settings={settingsByCategory('security')} onChange={updateSetting} /></div>}<div className="settings-actions"><button onClick={saveSettings} disabled={settings.length === 0}>Guardar configuración</button>{settingsMessage && <span className="success-inline">{settingsMessage}</span>}</div></section><section className="card"><div className="section-heading"><div><div className="eyebrow">Control de mercado</div><h2>Tasa RUB/CUP</h2></div>{rate && <span className="badge">{rate.manual_override ? 'Manual' : 'Oficial'}</span>}</div>{rate && <p className="muted">Vigente: <strong>{rate.rate} CUP por RUB</strong> · {rate.source_label} · fecha {rate.effective_date}</p>}<label>Fuente de tasa activa<select value={rate?.manual_override ? 'admin_override' : 'official'} disabled={rateSourceBusy || !rate} onChange={(event) => { const source = event.target.value as 'official' | 'admin_override'; setRateSourceBusy(true); void run(async () => { const nextRate = await api.setExchangeRateSource(userId, source); setRate(nextRate); setRateHistory((await api.adminRateHistory(userId)).rates); }).finally(() => setRateSourceBusy(false)); }}><option value="official">Tasa oficial (BCC; respaldo CBR)</option><option value="admin_override">Tasa definida por administrador</option></select></label><p className="muted">Puedes volver a la tasa oficial en cualquier momento. La actualización oficial automática no cambia esta selección.</p><form onSubmit={(event) => { event.preventDefault(); void run(async () => setRate(await api.setExchangeRate(userId, rateValue))); }}><Field name="admin_rate" label="Definir tasa manual (CUP por RUB)" value={rateValue} onChange={(event) => setRateValue(event.target.value)} placeholder="0.0000000000" /><button disabled={!rateValue}>Guardar tasa administrativa</button></form><h3>Historial de tasas</h3><div className="table-wrap"><table><thead><tr><th>Tasa</th><th>Origen</th><th>Vigencia</th><th>Registrada por</th></tr></thead><tbody>{rateHistory.map((item) => <tr key={`${item.valid_from}-${item.source}`}><td>{item.rate} CUP/RUB</td><td>{item.source}</td><td>{item.valid_from} — {item.valid_until || 'activa'}</td><td>{item.created_by_email || 'Sistema oficial'}</td></tr>)}</tbody></table></div><p className="muted">Si no existe una tasa manual activa, se usa automáticamente la tasa diaria oficial del Banco Central de Cuba.</p></section><section className="card"><div className="section-heading"><div><div className="eyebrow">Usuarios</div><h2>Usuarios registrados</h2></div><span className="badge">{users.length}</span></div><div className="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol/estado</th><th>Verificación</th><th>Remesas</th><th>Alta</th></tr></thead><tbody>{users.map((item) => <tr key={item.public_id}><td><strong>{item.full_name || 'Sin nombre'}</strong><small>{item.email}</small></td><td>{item.role}<small>{item.status}</small></td><td>{item.email_verified_at || 'Pendiente'}</td><td>{item.remittance_count}</td><td>{item.created_at}</td></tr>)}</tbody></table></div></section><section className="card"><div className="section-heading"><div><div className="eyebrow">Cumplimiento</div><h2>Revisión KYC</h2></div><span className="badge">{kycCases.filter(c => c.status === 'pending_review').length} pendientes</span></div>{kycCases.length === 0 ? <p className="muted">No hay expedientes KYC.</p> : <div className="list">{kycCases.map((item: any) => <div className="row" key={item.public_id}><div><strong>{item.first_name} {item.last_name} · {item.email}</strong><span>Estado: {item.status} · Documentos: {item.document_count}</span><span>{item.submitted_at || 'No enviado'} {item.review_reason || ''}</span><div className="quick-actions"><button className="secondary" onClick={() => void run(async () => { const docs = (await api.adminKycDocuments(userId, item.public_id)).documents; for (const doc of docs) { const blob = await api.downloadKycDocument(userId, doc.public_id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = doc.original_name; link.click(); URL.revokeObjectURL(url); } })}>Descargar documentos</button>{item.status === 'pending_review' && <><input placeholder="Motivo / observaciones" value={kycReason[item.public_id] || ''} onChange={e => setKycReason(v => ({...v, [item.public_id]: e.target.value}))}/><button onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'approved', ''); setKycCases((await api.adminKyc(userId)).cases); })}>Aprobar</button><button className="secondary" onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'rejected', kycReason[item.public_id] || ''); setKycCases((await api.adminKyc(userId)).cases); })}>Rechazar</button><button className="secondary" onClick={() => void run(async () => { await api.reviewKyc(userId, item.public_id, 'more_information', kycReason[item.public_id] || ''); setKycCases((await api.adminKyc(userId)).cases); })}>Solicitar información</button></>}</div></div></div>)}</div>}</section><section className="card"><div className="section-heading"><div><div className="eyebrow">Auditoría operativa</div><h2>Operaciones realizadas</h2></div><span className="badge">{items.length} registros</span></div>{items.length === 0 ? <p className="muted">No hay operaciones registradas.</p> : <div className="table-wrap"><table><thead><tr><th>Referencia</th><th>Cliente</th><th>Importe</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>{items.map((item) => <tr key={item.public_id}><td><strong>{item.reference}</strong><small>{item.direction}</small></td><td>{item.customer_name || item.customer_email}<small>{item.customer_email}</small></td><td>{item.send_amount} {item.send_currency}<small>→ {item.receive_amount} {item.receive_currency}</small></td><td><span className="badge">{item.status}</span><small>{item.compliance_status}</small></td><td>{new Date(item.created_at).toLocaleString()}</td></tr>)}</tbody></table></div>}</section></>;
}

function SettingsGroup({ title, settings, onChange }: { title: string; settings: PlatformSetting[]; onChange: (key: string, value: string | number | boolean) => void }) {
  return <section className="settings-group"><h3>{title}</h3>{settings.map((setting) => <label key={setting.key}>{setting.label}{setting.type === 'boolean' ? <span className="toggle-row"><input type="checkbox" checked={Boolean(setting.value)} onChange={(event) => onChange(setting.key, event.target.checked)} /><span>{setting.value ? 'Habilitado' : 'Deshabilitado'}</span></span> : setting.type === 'enum' ? <select value={String(setting.value)} onChange={(event) => onChange(setting.key, event.target.value)}>{(setting.options ?? []).map((option) => <option key={option} value={option}>{option === 'official' ? 'Tasa oficial' : option === 'admin_override' ? 'Tasa administrativa' : option}</option>)}</select> : <input type={setting.type === 'integer' ? 'number' : 'text'} inputMode={setting.type === 'integer' ? 'numeric' : 'decimal'} value={String(setting.value)} onChange={(event) => onChange(setting.key, event.target.value)} />}</label>)}</section>;
}

function NavButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: string }) { return <button className={active ? 'nav-button active' : 'nav-button'} onClick={onClick}>{children}</button>; }
function Logo({ language, monochrome = false }: { language: Language; monochrome?: boolean }) {
  const slogan = translate(language, 'brand.slogan');
  return <div className={monochrome ? 'logo logo-monochrome' : 'logo'} aria-label={`SEDERG_PAY — ${slogan}`}>
    <img className="logo-symbol logo-brand-image" src="/assets/sederg-pay/logos/sederg-pay-horizontal.svg" alt="" aria-hidden="true" />
    <span className="logo-wordmark logo-wordmark-accessible"><strong>SEDERG</strong><b>_PAY</b><small>{slogan}</small></span>
  </div>;
}
function Brand({ language }: { language: Language }) { return <Logo language={language} />; }
function LanguageSwitch({ language, onChange }: { language: Language; onChange: (language: Language) => void }) {
  return <div className="language-switch" role="group" aria-label="Idioma">
    {(Object.keys(languageLabels) as Language[]).map((option) => <button key={option} type="button" className={language === option ? 'language-option active' : 'language-option'} onClick={() => onChange(option)} aria-pressed={language === option}>{languageLabels[option]}</button>)}
  </div>;
}
function Header({ language, onLanguageChange }: { language: Language; onLanguageChange: (language: Language) => void }) {
  return <header><Brand language={language} /><div className="header-tools"><LanguageSwitch language={language} onChange={onLanguageChange} /><span className="sandbox"><i /> {translate(language, 'common.sandbox')}</span></div></header>;
}
function RateBanner() {
  const [rate, setRate] = useState<ExchangeRate | null>(null);
  const [error, setError] = useState('');
  useEffect(() => { api.currentExchangeRate().then(setRate).catch((reason: Error) => setError(reason.message)); }, []);
  if (error) return <p className="alert">Tasa RUB/CUP no disponible temporalmente.</p>;
  if (!rate) return <p className="muted">Cargando tasa RUB/CUP vigente…</p>;
  return <div className="rate-banner" role="status"><strong>Tasa vigente RUB/CUP: {rate.rate} CUP por RUB</strong><span>{rate.source_label} · {rate.effective_date}</span></div>;
}
function Alert({ text }: { text: string }) { return <p className="alert">{text}</p>; }
function Field({ name, label, type = 'text', defaultValue, value, onChange, placeholder, inputMode, pattern, required = true }: { name: string; label: string; type?: string; defaultValue?: string; value?: string; onChange?: (event: ChangeEvent<HTMLInputElement>) => void; placeholder?: string; inputMode?: 'text' | 'numeric' | 'tel' | 'email' | 'url' | 'search'; pattern?: string; required?: boolean }) { return <label>{label}<input required={required} name={name} type={type} defaultValue={defaultValue} value={value} onChange={onChange} placeholder={placeholder} inputMode={inputMode} pattern={pattern} /></label>; }
function Stat({ label, value }: { label: string; value: string }) { return <div className="stat"><span>{label}</span><strong>{value}</strong></div>; }
