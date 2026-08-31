import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../lib/api'

interface Entry { id: string; tenant_id: string | null; action: string; actor_type: string; actor_label: string | null; created_at: string }

export default function PlatformAudit() {
  const { t } = useTranslation()
  const [entries, setEntries] = useState<Entry[]>([])

  useEffect(() => { api.get('/platform/audit-logs').then(({ data }) => setEntries(data.data)) }, [])

  return (
    <div>
      <h1>{t('platform.audit')}</h1>
      <table className="data-table">
        <thead>
          <tr><th>{t('audit.action')}</th><th>{t('audit.actor')}</th><th>{t('audit.when')}</th></tr>
        </thead>
        <tbody>
          {entries.map((e) => (
            <tr key={e.id}>
              <td><code className="perm">{e.action}</code></td>
              <td>{e.actor_label ?? e.actor_type}</td>
              <td>{new Date(e.created_at).toLocaleString()}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
