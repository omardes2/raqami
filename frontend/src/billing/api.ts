import { api, ensureCsrf } from '../lib/api'

// --- Types (mirror the backend billing resources) ---

export interface PlanFeature {
  id: string
  feature_key: string
  enabled: boolean
  limit_value: number | null
}

export interface Plan {
  id: string
  name: string
  slug: string
  description: string | null
  status: string
  visibility: string
  monthly_price_minor: number
  annual_price_minor: number
  currency: string
  trial_days: number
  employee_limit: number | null
  is_featured: boolean
  features?: PlanFeature[]
}

export interface Subscription {
  id: string
  plan_id: string
  plan?: Plan
  status: string
  is_usable: boolean
  billing_interval: string
  currency: string
  trial_ends_at: string | null
  trial_days_remaining: number | null
  current_period_start: string | null
  current_period_end: string | null
  cancel_at_period_end: boolean
}

export interface InvoiceItem {
  id: string
  description: string
  quantity: number
  unit_amount_minor: number
  subtotal_minor: number
}

export interface Invoice {
  id: string
  tenant_id?: string
  invoice_number: string
  status: string
  currency: string
  subtotal_minor: number
  discount_minor: number
  tax_minor: number
  total_minor: number
  amount_paid_minor: number
  amount_due_minor: number
  coupon_code: string | null
  issued_at: string | null
  due_at: string | null
  items?: InvoiceItem[]
}

export interface Payment {
  id: string
  method: string
  amount_minor: number
  currency: string
  status: string
  reference: string | null
  paid_at: string | null
}

export interface BillingOverview {
  subscription: Subscription | null
  employee_usage: { used: number; limit: number | null; remaining: number | null }
  outstanding_balance_minor: number
  currency: string | null
  next_renewal_at: string | null
}

export interface BillingProfile {
  legal_name: string | null
  billing_email: string | null
  billing_phone: string | null
  country_code: string | null
  city: string | null
  address_line_1: string | null
  address_line_2: string | null
  postal_code: string | null
  tax_id: string | null
  preferred_currency: string | null
  invoice_notes: string | null
}

export interface BankAccount {
  id: string
  label: string
  bank_name: string
  account_holder: string
  account_number: string
  swift_code: string | null
  currency: string
  instructions: string | null
}

export interface BankTransfer {
  id: string
  invoice_id: string
  amount_minor: number
  currency: string
  transfer_reference: string | null
  original_filename: string
  status: string
  rejection_reason: string | null
  created_at: string
}

export interface Coupon {
  id: string
  code: string
  name: string
  type: string
  percentage: number | null
  amount_minor: number | null
  currency: string | null
  max_redemptions: number | null
  per_tenant_limit: number | null
  redeemed_count: number
  applicable_plan_id: string | null
  status: string
}

// ISO 4217 minor-unit exponents (mirror of backend config('billing.currency_exponents')).
// Money is authoritative in integer minor units; this only governs display.
const CURRENCY_EXPONENTS: Record<string, number> = {
  USD: 2, EUR: 2, GBP: 2, SAR: 2, AED: 2, ILS: 2, JOD: 3,
}

/** Minor-unit exponent for a currency (defaults to 2). */
export function currencyExponent(currency: string | null | undefined): number {
  return CURRENCY_EXPONENTS[(currency ?? '').toUpperCase()] ?? 2
}

/** Format integer minor units as a human currency string, honoring the exponent. */
export function money(minor: number, currency: string | null | undefined): string {
  const exp = currencyExponent(currency)
  return `${(minor / 10 ** exp).toFixed(exp)} ${currency ?? ''}`.trim()
}

// --- Tenant billing calls ---

async function post<T>(url: string, body?: unknown): Promise<T> {
  await ensureCsrf()
  const { data } = await api.post<T>(url, body)
  return data
}

export const billing = {
  overview: () => api.get<BillingOverview>('/billing/overview').then((r) => r.data),
  plans: () => api.get<{ data: Plan[] }>('/billing/plans').then((r) => r.data.data),
  subscription: () => api.get<{ data: Subscription | null }>('/billing/subscription').then((r) => r.data.data),
  subscribe: (body: { plan_id: string; interval: string; coupon_code?: string; trial?: boolean }) =>
    post('/billing/subscription', body),
  changePlan: (body: { plan_id: string; interval?: string }) => post('/billing/subscription/change-plan', body),
  cancel: () => post('/billing/subscription/cancel'),
  resume: () => post('/billing/subscription/resume'),
  invoices: () => api.get<{ data: Invoice[] }>('/billing/invoices').then((r) => r.data.data),
  invoice: (id: string) => api.get<Invoice>(`/billing/invoices/${id}`).then((r) => r.data),
  payments: () => api.get<{ data: Payment[] }>('/billing/payments').then((r) => r.data.data),
  profile: () => api.get<{ data: BillingProfile | null }>('/billing/profile').then((r) => r.data.data),
  saveProfile: async (body: Partial<BillingProfile>) => {
    await ensureCsrf()
    const { data } = await api.put<BillingProfile>('/billing/profile', body)
    return data
  },
  bankAccounts: (currency?: string) =>
    api.get<{ data: BankAccount[] }>('/billing/bank-accounts', { params: { currency } }).then((r) => r.data.data),
  bankTransfers: () => api.get<{ data: BankTransfer[] }>('/billing/bank-transfers').then((r) => r.data.data),
  submitBankTransfer: async (form: FormData) => {
    await ensureCsrf()
    const { data } = await api.post('/billing/bank-transfers', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
  },
}
