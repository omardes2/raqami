import { api, ensureCsrf } from '../lib/api'
import type { BankTransfer, Coupon, Invoice, Payment, Plan, Subscription } from './api'

async function post<T>(url: string, body?: unknown): Promise<T> {
  await ensureCsrf()
  const { data } = await api.post<T>(url, body)
  return data
}
async function put<T>(url: string, body?: unknown): Promise<T> {
  await ensureCsrf()
  const { data } = await api.put<T>(url, body)
  return data
}

export interface PlatformBankAccount {
  id: string
  label: string
  bank_name: string
  account_holder: string
  account_number: string
  swift_code: string | null
  currency: string
  country_code: string | null
  instructions: string | null
  internal_notes?: string | null
  status: string
}

export const platformBilling = {
  plans: () => api.get<{ data: Plan[] }>('/platform/plans').then((r) => r.data.data),
  createPlan: (body: Partial<Plan>) => post<Plan>('/platform/plans', body),
  updatePlan: (id: string, body: Partial<Plan>) => put<Plan>(`/platform/plans/${id}`, body),
  archivePlan: (id: string) => post(`/platform/plans/${id}/archive`),
  addFeature: (planId: string, body: { feature_key: string; enabled: boolean; limit_value: number | null }) =>
    post(`/platform/plans/${planId}/features`, body),

  coupons: () => api.get<{ data: Coupon[] }>('/platform/coupons').then((r) => r.data.data),
  createCoupon: (body: Partial<Coupon>) => post<Coupon>('/platform/coupons', body),
  archiveCoupon: (id: string) => post(`/platform/coupons/${id}/archive`),

  bankAccounts: () => api.get<{ data: PlatformBankAccount[] }>('/platform/bank-accounts').then((r) => r.data.data),
  createBankAccount: (body: Partial<PlatformBankAccount>) => post<PlatformBankAccount>('/platform/bank-accounts', body),
  archiveBankAccount: (id: string) => post(`/platform/bank-accounts/${id}/archive`),

  subscriptions: () => api.get<{ data: Subscription[] }>('/platform/subscriptions').then((r) => r.data.data),
  invoices: () => api.get<{ data: Invoice[] }>('/platform/invoices').then((r) => r.data.data),
  payments: () => api.get<{ data: Payment[] }>('/platform/payments').then((r) => r.data.data),
  recordManual: (body: { tenant_id: string; invoice_id: string; amount_minor: number; currency: string; method: string; reference?: string }) =>
    post('/platform/payments/manual', body),

  bankTransfers: (status = 'pending_review') =>
    api.get<{ data: BankTransfer[] }>('/platform/bank-transfers', { params: { status } }).then((r) => r.data.data),
  approveTransfer: (id: string) => post(`/platform/bank-transfers/${id}/approve`),
  rejectTransfer: (id: string, reason: string) => post(`/platform/bank-transfers/${id}/reject`, { reason }),
}
