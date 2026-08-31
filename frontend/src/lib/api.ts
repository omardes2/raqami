import axios from 'axios'

// First-party SPA client. Sanctum uses HTTP-only session cookies + an XSRF
// cookie; axios echoes the XSRF-TOKEN cookie back as the X-XSRF-TOKEN header.
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

let csrfReady = false

/** Fetch the CSRF cookie once before the first state-changing request. */
export async function ensureCsrf(): Promise<void> {
  if (csrfReady) return
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  csrfReady = true
}

/** Attach the active tenant (for users who belong to multiple companies). */
export function setActiveTenant(tenantId: string | null): void {
  if (tenantId) api.defaults.headers.common['X-Tenant-Id'] = tenantId
  else delete api.defaults.headers.common['X-Tenant-Id']
}

/** Tell the API which locale to use for this session. */
export function setApiLocale(locale: string): void {
  api.defaults.headers.common['X-Locale'] = locale
}
