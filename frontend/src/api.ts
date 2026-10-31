const apiBase = import.meta.env.VITE_API_BASE_URL ?? '';
const authMode = import.meta.env.VITE_AUTH_MODE ?? 'development';
export const isDevelopmentAuth = authMode === 'development';

function authHeaders(userId?: string): Record<string, string> {
  if (authMode === 'development') {
    return userId ? { 'X-User-Public-Id': userId } : {};
  }
  const token = localStorage.getItem('sederg_access_token');
  if (!token) {
    throw new Error('Inicia sesión con el proveedor de identidad para continuar');
  }
  return { Authorization: `Bearer ${token}` };
}

export type Beneficiary = {
  public_id: string;
  country_code: string;
  first_name: string;
  last_name: string;
  payout_method_code: string;
  payout_account_last4?: string;
  status: string;
};

export type Quote = {
  public_id: string;
  send_amount: string;
  send_currency: string;
  receive_amount: string;
  receive_currency: string;
  exchange_rate: string;
  fee_amount: string;
  total_debit_amount: string;
  payout_method_code: string;
  status: string;
  expires_at: string;
};

export type Remittance = {
  public_id: string;
  reference: string;
  direction: string;
  send_amount: string;
  send_currency: string;
  receive_amount: string;
  receive_currency: string;
  status: string;
  compliance_status: string;
};

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${apiBase}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(init.headers ?? {}),
    },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(body.message ?? body.name ?? `HTTP ${response.status}`);
  }
  return body as T;
}

export const api = {
  register: (body: Record<string, unknown>) => request<{ public_id: string }>('/api/v1/registrations', {
    method: 'POST',
    headers: { 'Idempotency-Key': `registration-${crypto.randomUUID()}` },
    body: JSON.stringify(body),
  }),
  beneficiaries: (userId: string) => request<Beneficiary[]>('/api/v1/beneficiaries', {
    headers: authHeaders(userId),
  }),
  createBeneficiary: (userId: string, body: Record<string, unknown>) => request<Beneficiary>('/api/v1/beneficiaries', {
    method: 'POST',
    headers: { ...authHeaders(userId), 'Idempotency-Key': `beneficiary-${crypto.randomUUID()}` },
    body: JSON.stringify(body),
  }),
  quote: (userId: string, body: Record<string, unknown>) => request<Quote>('/api/v1/quotes', {
    method: 'POST',
    headers: { ...authHeaders(userId), 'Idempotency-Key': `quote-${crypto.randomUUID()}` },
    body: JSON.stringify(body),
  }),
  remittances: (userId: string) => request<Remittance[]>('/api/v1/remittances', {
    headers: authHeaders(userId),
  }),
  createRemittance: (userId: string, body: Record<string, unknown>) => request<Remittance>('/api/v1/remittances', {
    method: 'POST',
    headers: { ...authHeaders(userId), 'Idempotency-Key': `remittance-${crypto.randomUUID()}` },
    body: JSON.stringify(body),
  }),
  deposit: (userId: string, remittanceId: string) => request<{ public_id: string; payment_reference: string }>('/api/v1/remittances/' + remittanceId + '/deposit', {
    method: 'POST',
    headers: { ...authHeaders(userId), 'Idempotency-Key': `deposit-${crypto.randomUUID()}` },
    body: '{}',
  }),
  confirmDeposit: (userId: string, depositId: string) => request(`/api/v1/deposits/${depositId}/simulate-confirmation`, {
    method: 'POST', headers: authHeaders(userId), body: '{}',
  }),
  review: (userId: string, remittanceId: string, decision: 'approved' | 'rejected') => request(`/api/v1/remittances/${remittanceId}/compliance-review`, {
    method: 'POST', headers: { ...authHeaders(userId), 'Idempotency-Key': `review-${crypto.randomUUID()}` },
    body: JSON.stringify({ decision, reason: 'Sandbox operator decision' }),
  }),
  payout: (userId: string, remittanceId: string) => request<{ public_id: string }>('/api/v1/remittances/' + remittanceId + '/payout', {
    method: 'POST', headers: { ...authHeaders(userId), 'Idempotency-Key': `payout-${crypto.randomUUID()}` }, body: '{}',
  }),
  completePayout: (userId: string, payoutId: string) => request(`/api/v1/payouts/${payoutId}/simulate-completion`, {
    method: 'POST', headers: authHeaders(userId), body: '{}',
  }),
  reconcile: (userId: string, remittanceId: string) => request(`/api/v1/remittances/${remittanceId}/reconcile`, {
    method: 'POST', headers: { ...authHeaders(userId), 'Idempotency-Key': `reconcile-${crypto.randomUUID()}` },
    body: JSON.stringify({ notes: 'Sandbox exact match verified' }),
  }),
};
