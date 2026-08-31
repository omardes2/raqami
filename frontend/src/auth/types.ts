export interface ActiveTenant {
  id: string
  name: string
  slug: string
  default_locale: string
  status: string
}

export interface AuthUser {
  id: string
  name: string
  email: string
  locale: string
  direction: 'rtl' | 'ltr'
  timezone: string
  status: string
  email_verified: boolean
  active_tenant: ActiveTenant | null
  permissions: string[]
  roles: string[]
}

export interface PlatformAdmin {
  id: string
  name: string
  email: string
  is_platform_admin: true
}
