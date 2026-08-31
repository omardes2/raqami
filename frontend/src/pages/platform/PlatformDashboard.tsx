import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../lib/api'

interface TenantRow {
  id: string; name: string; slug: string; status: string; members_count: number; created_at: string
}

export default function PlatformDashboard() {
  const { t } = useTranslation()
  const [tenants, setTenants] = useState<TenantRow[]>([])

  useEffect(() => { api.get('/platform/tenants').then(({ data }) => setTenants(data.data)) }, [])

  return (
    <div>
      <h1>{t('platform.tenants')}</h1>
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('platform.company')}</th><th>{t('platform.status')}</th>
            <th>{t('platform.members')}</th><th>{t('platform.created')}</th>
          </tr>
        </thead>
        <tbody>
          {tenants.map((row) => (
            <tr key={row.id}>
              <td>{row.name} <span className="muted">/{row.slug}</span></td>
              <td><span className={`pill pill-${row.status}`}>{row.status}</span></td>
              <td>{row.members_count}</td>
              <td>{new Date(row.created_at).toLocaleDateString()}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
