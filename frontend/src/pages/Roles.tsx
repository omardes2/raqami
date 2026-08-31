import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../lib/api'

interface Role { id: string; name: string; slug: string; is_system: boolean; permissions: string[] }

export default function Roles() {
  const { t } = useTranslation()
  const [roles, setRoles] = useState<Role[]>([])

  useEffect(() => { api.get('/roles').then(({ data }) => setRoles(data.data)) }, [])

  return (
    <div>
      <h1>{t('roles.title')}</h1>
      <table className="data-table">
        <thead>
          <tr><th>{t('roles.role')}</th><th>{t('roles.permissions')}</th></tr>
        </thead>
        <tbody>
          {roles.map((r) => (
            <tr key={r.id}>
              <td>
                {r.name}{' '}
                {r.is_system && <span className="pill pill-system">{t('roles.system')}</span>}
              </td>
              <td className="perm-cell">
                {r.permissions.length
                  ? r.permissions.map((p) => <code key={p} className="perm">{p}</code>)
                  : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
