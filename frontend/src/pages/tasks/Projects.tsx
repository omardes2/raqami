import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { projects, type Project } from '../../tasks/api'
import { useAuth } from '../../auth/AuthContext'

/** Project list with a minimal create form. */
export default function Projects() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [items, setItems] = useState<Project[]>([])
  const [form, setForm] = useState({ name: '', scope_type: 'company', visibility: 'scoped' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setItems(await projects.list())
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setLoading(false)
    }
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await projects.create({ ...form })
      setForm({ name: '', scope_type: 'company', visibility: 'scoped' })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <h1>{t('tasks.projects')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      {can('projects.create') && (
        <div className="card">
          <h2>{t('tasks.new_project')}</h2>
          <input placeholder={t('tasks.project_name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <select value={form.scope_type} onChange={(e) => setForm({ ...form, scope_type: e.target.value })}>
            {['company', 'branch', 'department', 'team'].map((s) => <option key={s} value={s}>{t(`tasks.scope_${s}`)}</option>)}
          </select>
          <select value={form.visibility} onChange={(e) => setForm({ ...form, visibility: e.target.value })}>
            {['scoped', 'members_only'].map((v) => <option key={v} value={v}>{t(`tasks.visibility_${v}`)}</option>)}
          </select>
          <button type="button" onClick={() => void create()} disabled={busy || !form.name}>{t('common.create')}</button>
        </div>
      )}
      {loading ? <p>{t('common.loading')}</p> : (
        <table>
          <thead><tr><th>{t('tasks.project_name')}</th><th>{t('tasks.status')}</th><th>{t('tasks.visibility')}</th></tr></thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan={3}>{t('tasks.none')}</td></tr>}
            {items.map((p) => (
              <tr key={p.id}>
                <td><Link to={`/projects/${p.id}`}>{p.name}</Link></td>
                <td>{t(`tasks.project_status_${p.status}`)}{p.is_archived ? ` (${t('tasks.archived')})` : ''}</td>
                <td>{t(`tasks.visibility_${p.visibility}`)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
