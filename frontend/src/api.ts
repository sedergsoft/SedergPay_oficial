const apiBase = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/+$/, '');
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

export type UserProfile = { public_id: string; email: string; phone_e164: string | null; preferred_language: string; status: string; first_name: string; last_name: string; middle_name: string | null; date_of_birth: string | null; residence_country_code: string | null; nationality_country_code: string | null; address_line: string | null; city: string | null; postal_code: string | null };
export type KycCase = { public_id?: string; status: string; submitted_at?: string | null; reviewed_at?: string | null; review_reason?: string | null; documents?: Array<{ public_id: string; document_type: string; original_name: string; mime_type: string; file_size: number; created_at: string }> };
export type AdminKycCase = { public_id: string; status: string; submitted_at: string | null; reviewed_at: string | null; review_reason: string | null; user_public_id: string; email: string; first_name: string; last_name: string; document_count: number };

export type Beneficiary = {
  public_id: string;
  country_code: string;
  first_name: string;
  last_name: string;
  phone_e164?: string;
  email?: string;
  payout_method_code: string;
  payout_provider?: string;
  payout_card_last4?: string;
  payout_account_last4?: string;
  status: string;
};

export type PaymentMethod = {
  code: string;
  name: string;
  requires_bank_account: boolean;
};

export const paymentMethods: PaymentMethod[] = [
  { code: 'cash_pickup', name: 'Pago en efectivo', requires_bank_account: false },
  { code: 'bank_account', name: 'Abono en cuenta bancaria', requires_bank_account: true },
  { code: 'card_transfer', name: 'Transferencia a tarjeta', requires_bank_account: false },
];

export type Quote = {
  public_id: string;
  send_amount: string;
  send_currency: string;
  receive_amount: string;
  receive_currency: string;
  exchange_rate: string;
  fee_amount: string;
  fee_currency?: string;
  fee_mode?: 'add_to_send' | 'deduct_from_receive';
  total_debit_amount: string;
  limits?: { currency: 'RUB'; minimum: string; maximum: string; evaluated_amount: string; valid: boolean };
  payout_method_code: string;
  status: string;
  expires_at: string;
  exchange_rate_source: string;
  exchange_rate_effective_date: string;
};

export type ExchangeRate = {
  base_currency: string;
  quote_currency: string;
  rate: string;
  source: string;
  source_label: string;
  effective_date: string;
  source_url: string | null;
  manual_override: boolean;
};

export type Remittance = {
  public_id: string;
  reference: string;
  direction: string;
  send_amount: string;
  total_debit_amount?: string;
  send_currency: string;
  receive_amount: string;
  receive_currency: string;
  status: string;
  compliance_status: string;
  created_at?: string;
  updated_at?: string;
  payment_qr_payload?: string | null;
  payment_method_code?: string | null;
  deposit_public_id?: string | null;
  deposit_status?: string | null;
  payment_reference?: string | null;
  deposit_expected_amount?: string | null;
  deposit_currency?: string | null;
  deposit_expires_at?: string | null;
  payment_instructions?: Record<string, any>;
  receipt_email_sent?: boolean;
};

export type RemittanceDetail = Remittance & {
  exchange_rate: string;
  fee_amount: string;
  fee_currency: string;
  total_debit_amount: string;
  paid_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  failure_reason_code: string | null;
  beneficiary_first_name: string;
  beneficiary_last_name: string;
  beneficiary_phone: string | null;
  beneficiary_email: string | null;
  payout_method_code: string;
  payout_provider: string | null;
  payout_account_last4: string | null;
  payout_card_last4: string | null;
  sender_email: string;
  sender_first_name: string;
  sender_last_name: string;
  deposit_public_id: string | null;
  payment_reference: string | null;
  deposit_expected_amount: string | null;
  deposit_currency: string | null;
  deposit_status: string | null;
  deposit_expires_at: string | null;
  deposit_confirmed_at: string | null;
  payout_public_id: string | null;
  payout_status: string | null;
  payout_provider_reference: string | null;
  payout_paid_at: string | null;
  state_events: Array<{ from_status: string | null; to_status: string; reason: string; created_at: string }>;
};

export type User = {
  public_id: string;
  email: string;
  first_name: string;
  last_name: string;
  role: 'customer' | 'admin';
  status: string;
};

export type AdminOperation = Remittance & {
  customer_email: string;
  customer_name: string;
  created_at: string;
  updated_at: string;
};

export type AdminUser = {
  public_id: string;
  email: string;
  phone_e164: string | null;
  role: 'customer' | 'admin';
  status: string;
  email_verified_at: string | null;
  created_at: string;
  last_login_at: string | null;
  full_name: string;
  remittance_count: number;
};

export type AdminRate = {
  rate: string;
  source: string;
  valid_from: string;
  valid_until: string | null;
  created_at: string;
  created_by_email: string | null;
};

export type AdminDashboard = {
  directions: Array<{ direction: string; currency: string; operations: number; sent_amount: string; receive_currency: string; received_amount: string }>;
  statuses: Array<{ status: string; operations: number; send_amount: string; currency: string }>;
  total_users: number;
  total_remittances: number;
};

export type FundingMethod = { code: string; name: string; method_type: string; provider_code: string | null; supports_dynamic_qr: boolean | number; requires_reference: boolean | number; country_code?: string; currency_code?: string; is_active?: boolean | number };
export type FundingAccount = { public_id: string; country_code: string; currency_code: string; bank_code: string; bank_name: string; account_holder_name: string; legal_entity_name: string | null; account_type: string; account_mask: string; routing_mask: string | null; environment: string; status: string; notes: string | null; methods: string | null };

export type PlatformSetting = {
  key: string;
  value: string | number | boolean;
  type: 'decimal' | 'integer' | 'boolean' | 'enum';
  options?: string[] | null;
  label: string;
  category: 'pricing' | 'operations' | 'security';
  updated_at: string | null;
};

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  if (!isDevelopmentAuth && apiBase === '') {
    throw new Error('La API no está configurada. Define VITE_API_BASE_URL en el despliegue.');
  }
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
  login: (email: string, password: string) => request<User>('/api/v1/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  }),
  me: (userId: string) => request<User>('/api/v1/auth/me', {
    headers: authHeaders(userId),
  }),
  verifyEmail: (email: string, token: string) => request<{ public_id: string; status: string }>('/api/v1/auth/verify-email', {
    method: 'POST',
    body: JSON.stringify({ email, token }),
  }),
  resendVerification: (email: string) => request<{ verification_sent: boolean }>('/api/v1/auth/resend-verification', {
    method: 'POST',
    body: JSON.stringify({ email }),
  }),
  register: (body: Record<string, unknown>) => request<{ public_id: string; email: string; verification_required: boolean }>('/api/v1/registrations', {
    method: 'POST',
    headers: { 'Idempotency-Key': `registration-${crypto.randomUUID()}` },
    body: JSON.stringify(body),
  }),
  profile: (userId: string) => request<UserProfile>('/api/v1/profile', { headers: authHeaders(userId) }),
  updateProfile: (userId: string, body: Record<string, unknown>) => request<UserProfile>('/api/v1/profile', { method: 'PUT', headers: authHeaders(userId), body: JSON.stringify(body) }),
  kyc: (userId: string) => request<KycCase>('/api/v1/kyc', { headers: authHeaders(userId) }),
  submitKyc: (userId: string) => request<KycCase>('/api/v1/kyc/submit', { method: 'POST', headers: authHeaders(userId), body: '{}' }),
  uploadKycDocument: async (userId: string, file: File, documentType: string) => { const form = new FormData(); form.append('file', file); form.append('document_type', documentType); const headers = authHeaders(userId); const response = await fetch(`${apiBase}/api/v1/kyc/documents`, { method: 'POST', headers, body: form }); const body = await response.json().catch(() => ({})); if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`); return body; },
  adminKycDocuments: (userId: string, publicId: string) => request<{ documents: Array<{ public_id: string; document_type: string; original_name: string; mime_type: string; file_size: number }> }>('/api/v1/admin/kyc/' + publicId + '/documents', { headers: authHeaders(userId) }),
  downloadKycDocument: async (userId: string, publicId: string) => { const response = await fetch(apiBase + '/api/v1/admin/kyc/documents/' + publicId, { headers: authHeaders(userId) }); if (!response.ok) throw new Error('No se pudo descargar el documento KYC'); return await response.blob(); },
  adminKyc: (userId: string) => request<{ cases: AdminKycCase[] }>('/api/v1/admin/kyc', { headers: authHeaders(userId) }),
  reviewKyc: (userId: string, publicId: string, decision: 'approved' | 'rejected' | 'more_information', reason: string) => request<KycCase>(`/api/v1/admin/kyc/${publicId}/review`, { method: 'POST', headers: authHeaders(userId), body: JSON.stringify({ decision, reason }) }),
  beneficiaries: (userId: string) => request<Beneficiary[]>('/api/v1/beneficiaries', {
    headers: authHeaders(userId),
  }),
  updateBeneficiary: (userId: string, publicId: string, body: Record<string, unknown>) => request<Beneficiary>('/api/v1/beneficiaries/' + publicId, { method: 'PUT', headers: authHeaders(userId), body: JSON.stringify(body) }),
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
  remittance: (userId: string, publicId: string) => request<RemittanceDetail>('/api/v1/remittances/' + publicId, {
    headers: authHeaders(userId),
  }),
  fundingMethods: (userId: string, countryCode: string) => request<{ methods: FundingMethod[]; environment: string }>(`/api/v1/funding-methods/${countryCode}`, { headers: authHeaders(userId) }),
  adminFundingMethods: (userId: string) => request<{ methods: FundingMethod[] }>('/api/v1/admin/funding-methods', { headers: authHeaders(userId) }),
  updateFundingMethod: (userId: string, code: string, isActive: boolean) => request<{ code: string; is_active: boolean }>(`/api/v1/admin/funding-methods/${code}`, { method: 'PUT', headers: authHeaders(userId), body: JSON.stringify({ is_active: isActive }) }),
  adminFundingAccounts: (userId: string) => request<{ accounts: FundingAccount[] }>('/api/v1/admin/funding-accounts', { headers: authHeaders(userId) }),
  createFundingAccount: (userId: string, body: Record<string, unknown>) => request<{ public_id: string; account_mask: string; status: string; environment: string }>('/api/v1/admin/funding-accounts', { method: 'POST', headers: authHeaders(userId), body: JSON.stringify(body) }),
  updateFundingAccount: (userId: string, publicId: string, status: 'active' | 'inactive') => request<{ public_id: string; status: string }>(`/api/v1/admin/funding-accounts/${publicId}`, { method: 'PUT', headers: authHeaders(userId), body: JSON.stringify({ status }) }),
  adminOperations: (userId: string) => request<AdminOperation[]>('/api/v1/admin/operations', {
    headers: authHeaders(userId),
  }),
  adminUsers: (userId: string) => request<AdminUser[]>('/api/v1/admin/users', {
    headers: authHeaders(userId),
  }),
  adminRateHistory: (userId: string) => request<{ rates: AdminRate[] }>('/api/v1/admin/rates/history', {
    headers: authHeaders(userId),
  }),
  adminDashboard: (userId: string) => request<AdminDashboard>('/api/v1/admin/dashboard', {
    headers: authHeaders(userId),
  }),
  adminSettings: (userId: string) => request<{ settings: PlatformSetting[] }>('/api/v1/admin/settings', {
    headers: authHeaders(userId),
  }),
  updateAdminSettings: (userId: string, settings: Record<string, string | number | boolean>) => request<{ settings: PlatformSetting[] }>('/api/v1/admin/settings', {
    method: 'PUT',
    headers: authHeaders(userId),
    body: JSON.stringify({ settings }),
  }),
  currentExchangeRate: () => request<ExchangeRate>('/api/v1/exchange-rates/current'),
  setExchangeRateSource: (userId: string, source: 'official' | 'admin_override') => request<ExchangeRate>('/api/v1/admin/exchange-rate/source', {
    method: 'PUT',
    headers: authHeaders(userId),
    body: JSON.stringify({ source }),
  }),
  setExchangeRate: (userId: string, rate: string) => request<ExchangeRate>('/api/v1/admin/exchange-rate', {
    method: 'POST',
    headers: authHeaders(userId),
    body: JSON.stringify({ rate }),
  }),
  createRemittance: (userId: string, body: Record<string, unknown>) => request<Remittance>('/api/v1/remittances', {
    method: 'POST',
    headers: { ...authHeaders(userId), 'Idempotency-Key': `remittance-${crypto.randomUUID()}` },
    body: JSON.stringify(body),
  }),
  deposit: (userId: string, remittanceId: string, methodCode: string) => request<{ public_id: string; payment_reference: string; qr_payload: string | null; qr_type: string; account_mask: string; bank_name: string }>('/api/v1/remittances/' + remittanceId + '/deposit', {
    method: 'POST',
    headers: { ...authHeaders(userId), 'Idempotency-Key': `deposit-${remittanceId}` },
    body: JSON.stringify({ method_code: methodCode }),
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
