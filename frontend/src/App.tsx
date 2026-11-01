import { FormEvent, useEffect, useState } from 'react';
import { api, Beneficiary, Quote, Remittance, isDevelopmentAuth } from './api';

const storedUser = isDevelopmentAuth
  ? localStorage.getItem('sederg_user') ?? ''
  : localStorage.getItem('sederg_access_token') ? 'oidc-user' : '';

export function App() {
  const [userId, setUserId] = useState(storedUser);
  const [beneficiaries, setBeneficiaries] = useState<Beneficiary[]>([]);
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  const [quote, setQuote] = useState<Quote | null>(null);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  const refresh = async () => {
    if (!userId) return;
    try {
      const [nextBeneficiaries, nextRemittances] = await Promise.all([
        api.beneficiaries(userId),
        api.remittances(userId),
      ]);
      setBeneficiaries(nextBeneficiaries);
      setRemittances(nextRemittances);
    } catch (error) {
      setMessage((error as Error).message);
    }
  };

  useEffect(() => { void refresh(); }, [userId]);

  const submit = async (event: FormEvent<HTMLFormElement>, action: () => Promise<void>) => {
    event.preventDefault();
    setBusy(true);
    setMessage('');
    try { await action(); } catch (error) { setMessage((error as Error).message); } finally { setBusy(false); }
  };

  if (!userId) {
    return <main className="shell narrow"><Header /><section className="card">
      <div className="eyebrow">Remesas simples y trazables</div>
      <h1>{isDevelopmentAuth ? 'Crea tu cuenta' : 'Inicia sesión'}</h1>
      <p className="muted">{isDevelopmentAuth
        ? 'MVP sandbox. Tus datos se guardan con consentimiento.'
        : 'La sesión OIDC debe ser iniciada por la integración de identidad del host.'}</p>
      {!isDevelopmentAuth && <p className="alert">No hay una sesión OIDC activa. Configura el proveedor de identidad antes de usar el portal.</p>}
      {isDevelopmentAuth && <form onSubmit={(event) => submit(event, async () => {
        const data = new FormData(event.currentTarget);
        const result = await api.register({
          email: data.get('email'), password: data.get('password'), first_name: data.get('first_name'),
          last_name: data.get('last_name'), residence_country_code: data.get('country'),
          consent_policy_version: 'v1', accept_terms: true, accept_privacy: true,
        });
        localStorage.setItem('sederg_user', result.public_id);
        setUserId(result.public_id);
      })}>
        <Field name="first_name" label="Nombre" />
        <Field name="last_name" label="Apellidos" />
        <Field name="email" label="Email" type="email" />
        <Field name="password" label="Contraseña (12+ caracteres)" type="password" />
        <label>País de residencia<select name="country" defaultValue="RU"><option value="RU">Rusia</option><option value="CU">Cuba</option></select></label>
        <button disabled={busy}>{busy ? 'Creando…' : 'Crear cuenta'}</button>
      </form>}
      {message && <Alert text={message} />}
    </section></main>;
  }

  return <main className="shell"><Header /><section className="hero"><div><div className="eyebrow">Panel de operaciones</div><h1>Envía dinero con claridad.</h1><p>Gestiona beneficiarios, cotizaciones y remesas en un solo lugar.</p></div><div className="hero-mark" aria-hidden="true">↗</div></section><div className="toolbar"><span>Cliente sandbox: <code>{userId}</code></span><button className="secondary" onClick={() => { localStorage.removeItem('sederg_user'); setUserId(''); }}>Salir</button></div>
    <div className="grid">
      <section className="card"><h2>1. Beneficiario</h2><form onSubmit={(event) => submit(event, async () => {
        const data = new FormData(event.currentTarget);
        await api.createBeneficiary(userId, { country_code: data.get('country'), first_name: data.get('first_name'), last_name: data.get('last_name'), payout_method_code: 'cash_pickup' });
        await refresh();
      })}><Field name="first_name" label="Nombre" /><Field name="last_name" label="Apellidos" /><label>País<select name="country" defaultValue="CU"><option value="CU">Cuba</option><option value="RU">Rusia</option></select></label><button disabled={busy}>Guardar beneficiario</button></form></section>
      <section className="card"><h2>2. Cotización</h2><form onSubmit={(event) => submit(event, async () => {
        const data = new FormData(event.currentTarget);
        setQuote(await api.quote(userId, { source_country_code: 'RU', destination_country_code: 'CU', send_amount: data.get('amount'), send_currency: 'RUB', receive_currency: 'CUP', payout_method_code: 'cash_pickup', exchange_rate: data.get('rate') }));
      })}><Field name="amount" label="Enviar RUB" defaultValue="15000.00" /><Field name="rate" label="Tasa RUB/CUP" defaultValue="0.1200000000" /><button disabled={busy}>Calcular</button></form>{quote && <div className="result"><strong>{quote.receive_amount} CUP</strong><span>Comisión: {quote.fee_amount} RUB · vence {quote.expires_at}</span><button onClick={() => submit(new Event('submit') as unknown as FormEvent<HTMLFormElement>, async () => { if (!beneficiaries[0]) throw new Error('Crea un beneficiario primero'); await api.createRemittance(userId, { quote_public_id: quote.public_id, beneficiary_public_id: beneficiaries[0].public_id }); await refresh(); })}>Crear remesa</button></div>}</section>
    </div>
    <section className="card"><h2>Remesas</h2>{remittances.length === 0 ? <p className="muted">Aún no tienes remesas.</p> : remittances.map((item) => <div className="row" key={item.public_id}><div><strong>{item.reference}</strong><span>{item.send_amount} {item.send_currency} → {item.receive_amount} {item.receive_currency}</span></div><span className="badge">{item.status}</span>{item.status === 'awaiting_payment' && <button className="secondary" onClick={() => submit(new Event('submit') as unknown as FormEvent<HTMLFormElement>, async () => { const deposit = await api.deposit(userId, item.public_id); setMessage(`Depósito sandbox: ${deposit.payment_reference}. Usa el endpoint de confirmación para simular el ingreso.`); })}>Depósito</button>}{item.status === 'compliance_review' && <button onClick={() => submit(new Event('submit') as unknown as FormEvent<HTMLFormElement>, async () => { await api.review(userId, item.public_id, 'approved'); await refresh(); })}>Aprobar sandbox</button>}{item.status === 'approved' && <button onClick={() => submit(new Event('submit') as unknown as FormEvent<HTMLFormElement>, async () => { const payout = await api.payout(userId, item.public_id); await api.completePayout(userId, payout.public_id); await refresh(); })}>Payout sandbox</button>}{item.status === 'reconciliation_pending' && <button onClick={() => submit(new Event('submit') as unknown as FormEvent<HTMLFormElement>, async () => { await api.reconcile(userId, item.public_id); await refresh(); })}>Conciliar</button>}</div>)}</section>
    {message && <Alert text={message} />}
    <footer>SEDERG_PAY · entorno sandbox · no mueve dinero real</footer>
  </main>;
}

function Header() { return <header><div className="brand"><span className="brand-mark">S</span><span>SEDERG</span><b>_PAY</b></div><span className="sandbox"><i /> SANDBOX</span></header>; }
function Alert({ text }: { text: string }) { return <p className="alert">{text}</p>; }
function Field({ name, label, type = 'text', defaultValue }: { name: string; label: string; type?: string; defaultValue?: string }) { return <label>{label}<input required name={name} type={type} defaultValue={defaultValue} /></label>; }
