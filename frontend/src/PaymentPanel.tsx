import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { api, PaymentIntentState, PublicPayment, Remittance, RemittanceDetail } from './api';

type PayableRemittance = Remittance | RemittanceDetail;

const STATUS_LABELS: Record<string, string> = {
  pending: 'Esperando pago',
  confirmed: 'Pago confirmado',
  expired: 'Enlace vencido',
  cancelled: 'Pago cancelado',
  failed: 'Pago fallido',
};

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status;
}

function formatCountdown(milliseconds: number): string {
  const total = Math.max(0, Math.floor(milliseconds / 1000));
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

export function paymentTokenFromPath(): string | null {
  const match = window.location.pathname.match(/^\/pay\/([A-Za-z0-9]{16,64})\/?$/);
  return match ? match[1] : null;
}

/**
 * Authenticated payment panel shown inside the operation detail.
 *
 * The QR payload always comes from the backend. A provider QR is labelled as
 * such; the sandbox informational link is labelled explicitly so nobody
 * mistakes it for a bank-issued QR.
 */
export function PaymentQr({ remittance, userId, onRefresh }: { remittance: PayableRemittance; userId: string; onRefresh?: () => void }) {
  const [src, setSrc] = useState('');
  const [state, setState] = useState<PaymentIntentState | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [now, setNow] = useState(Date.now());

  const instructions = remittance.payment_instructions ?? {};
  const depositId = remittance.deposit_public_id ?? null;
  const qrPayload = state?.qr_payload ?? remittance.payment_qr_payload ?? null;
  const status = state?.status ?? remittance.deposit_status ?? 'pending';
  const expiresAt = state?.expires_at ?? remittance.deposit_expires_at ?? null;
  const payUrl = state?.pay_url ?? remittance.payment_pay_url ?? null;
  const qrKind = state?.qr_kind ?? remittance.payment_qr_kind ?? null;
  const methodCode = state?.pay_token ? remittance.payment_method_code ?? null : remittance.payment_method_code ?? null;

  const amount = instructions.amount ?? state?.expected_amount ?? remittance.deposit_expected_amount ?? remittance.send_amount;
  const currency = instructions.currency_code ?? state?.currency_code ?? remittance.deposit_currency ?? remittance.send_currency;
  const remaining = expiresAt ? new Date(expiresAt).getTime() - now : null;
  const expired = status === 'expired' || (remaining !== null && remaining <= 0 && status === 'pending');

  useEffect(() => {
    setSrc('');
    if (!qrPayload) return;
    void QRCode.toDataURL(qrPayload, { width: 220, margin: 2 }).then(setSrc).catch(() => setSrc(''));
  }, [qrPayload]);

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    if (!depositId) return;
    let cancelled = false;
    const tick = async () => {
      try {
        const next = await api.depositStatus(userId, depositId);
        if (!cancelled) setState(next);
      } catch {
        // Transient polling failures must not replace the QR with an error.
      }
    };
    void tick();
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') void tick();
    }, 5000);
    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [depositId, userId]);

  const confirm = async () => {
    if (!depositId) return;
    setBusy(true);
    setError('');
    try {
      await api.confirmDeposit(userId, depositId);
      setState(await api.depositStatus(userId, depositId));
      onRefresh?.();
    } catch (exception) {
      setError((exception as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const regenerate = async () => {
    setBusy(true);
    setError('');
    try {
      await api.deposit(userId, remittance.public_id, methodCode ?? '');
      onRefresh?.();
    } catch (exception) {
      setError((exception as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const qrNote = qrKind === 'provider_dynamic'
    ? 'QR emitido por el proveedor de pagos. Escanéalo con la app del banco.'
    : qrKind === 'link'
      ? 'QR informativo: abre la página de pago con el importe y la referencia. No es un QR bancario.'
      : 'Este método usa transferencia con referencia.';

  return <div className="payment-panel payment-panel-modern">
    <div className="payment-panel-main">
      <span className="eyebrow">Pago a la plataforma</span>
      <h3>Transferencia creada: {remittance.reference}</h3>
      <p>Transfiere <strong>{amount} {currency}</strong> con la referencia <strong>{instructions.reference ?? remittance.payment_reference ?? state?.payment_reference ?? '—'}</strong>.</p>
      <div className="payment-bank-details">
        <p><span>Banco</span><strong>{instructions.bank_name ?? state?.bank_name ?? '—'}</strong></p>
        <p><span>Titular</span><strong>{instructions.account_holder_name ?? state?.account_holder_name ?? 'SEDERG_PAY'}</strong></p>
        <p><span>Cuenta</span><strong>{instructions.account_mask ?? state?.account_mask ?? '—'}</strong></p>
        <p><span>BIC / código de ruta</span><strong>{instructions.routing_mask ?? state?.routing_mask ?? '—'}</strong></p>
        <p><span>Referencia obligatoria</span><strong>{instructions.reference ?? state?.payment_reference ?? remittance.payment_reference ?? '—'}</strong></p>
      </div>
      <div className={`payment-status-line payment-status-${expired ? 'expired' : status}`}>
        <span className="payment-status-dot" /> {expired ? 'Enlace vencido' : statusLabel(status)}
        {remaining !== null && status === 'pending' && !expired && <small> · vence en {formatCountdown(remaining)}</small>}
      </div>
      {error && <p className="field-error-message" role="alert">{error}</p>}
      {status === 'pending' && !expired && <div className="payment-actions">
        <button type="button" onClick={() => void confirm()} disabled={busy}>{busy ? 'Confirmando…' : 'Confirmar pago (sandbox)'}</button>
        {payUrl && <a className="secondary compact-button" href={payUrl} target="_blank" rel="noreferrer">Abrir página de pago</a>}
      </div>}
      {expired && <div className="payment-actions">
        <div className="alert">Este enlace ya no es válido. Genera uno nuevo para seguir con el pago.</div>
        {methodCode && <button type="button" onClick={() => void regenerate()} disabled={busy}>{busy ? 'Generando…' : 'Generar nuevo QR'}</button>}
      </div>}
      {status === 'confirmed' && <p className="payment-success">Pago recibido. La operación pasó a revisión de cumplimiento.</p>}
    </div>
    <div className="payment-panel-code">
      {src ? <img className="payment-qr" src={src} alt={`QR de pago ${remittance.reference}`} /> : <div className="payment-qr-placeholder">Sin QR disponible</div>}
      {remaining !== null && status === 'pending' && !expired && <span className="payment-countdown">Vence en {formatCountdown(remaining)}</span>}
      <span className="sandbox-note">{qrNote}</span>
    </div>
  </div>;
}

/**
 * Standalone payer page reached by scanning the QR. It is intentionally
 * unauthenticated: the random token in the URL is the only capability needed
 * to see the amount, the reference and the receiving account.
 */
export function PublicPayPage({ token }: { token: string }) {
  const [intent, setIntent] = useState<PublicPayment | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [now, setNow] = useState(Date.now());

  const load = async () => {
    try {
      setIntent(await api.publicPayment(token));
      setError('');
    } catch (exception) {
      setError((exception as Error).message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, [token]);
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    if (!intent || !['pending'].includes(intent.status)) return;
    const timer = setInterval(() => { if (document.visibilityState === 'visible') void load(); }, 5000);
    return () => clearInterval(timer);
  }, [intent?.status, token]);

  const confirm = async () => {
    setBusy(true);
    setError('');
    try {
      await api.confirmPublicPayment(token);
      setMessage('Pago confirmado. La operación continúa en revisión de cumplimiento.');
      await load();
    } catch (exception) {
      setError((exception as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const remaining = intent?.expires_at ? new Date(intent.expires_at).getTime() - now : null;
  const expired = intent?.status === 'expired' || (remaining !== null && remaining <= 0 && intent?.status === 'pending');

  return <main className="pay-page">
    <section className="pay-card">
      <header className="pay-brand">
        <span className="eyebrow">SEDERG PAY</span>
        <h1>Pago de la operación</h1>
        <p>{intent?.remittance_reference ? <>Referencia de la operación: <strong>{intent.remittance_reference}</strong></> : 'Enlace de pago'}</p>
      </header>
      {loading && <p className="muted">Cargando los datos del pago…</p>}
      {!loading && error && !intent && <div className="alert">{error}</div>}
      {intent && <>
        <div className={`payment-status-line payment-status-${expired ? 'expired' : intent.status}`}>
          <span className="payment-status-dot" /> {expired ? 'Enlace vencido' : statusLabel(intent.status)}
          {remaining !== null && intent.status === 'pending' && !expired && <small> · vence en {formatCountdown(remaining)}</small>}
        </div>
        <div className="pay-amount">
          <span>Importe a pagar</span>
          <strong>{intent.amount ?? intent.expected_amount} {intent.currency_code}</strong>
        </div>
        <div className="payment-bank-details">
          <p><span>Banco</span><strong>{intent.bank_name ?? '—'}</strong></p>
          <p><span>Titular</span><strong>{intent.account_holder_name ?? 'SEDERG_PAY'}</strong></p>
          <p><span>Cuenta</span><strong>{intent.account_mask ?? '—'}</strong></p>
          <p><span>BIC / código de ruta</span><strong>{intent.routing_mask ?? '—'}</strong></p>
          <p><span>Referencia obligatoria</span><strong>{intent.payment_reference}</strong></p>
          <p><span>Método</span><strong>{intent.method_name ?? '—'}</strong></p>
        </div>
        {message && <p className="payment-success">{message}</p>}
        {error && <p className="field-error-message" role="alert">{error}</p>}
        {intent.can_confirm && !expired && intent.status === 'pending' && <button type="button" className="pay-confirm" onClick={() => void confirm()} disabled={busy}>{busy ? 'Confirmando…' : 'Confirmar pago (sandbox)'}</button>}
        {expired && <div className="alert">Este enlace ha vencido. Vuelve a la plataforma y genera uno nuevo.</div>}
        <p className="sandbox-note">Entorno de pruebas: la confirmación simula la recepción del dinero. En producción la confirma el proveedor de pagos mediante un webhook firmado.</p>
      </>}
    </section>
  </main>;
}
