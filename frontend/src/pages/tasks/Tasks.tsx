import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { tasks, taskStatuses, type Task, type TaskStatus } from '../../tasks/api'
import { useAuth } from '../../auth/AuthContext'

/** Management task list (scoped + filtered) with a minimal create form. */
export default function Tasks() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [items, setItems] = useState<Task[]>([])
  const [statuses, setStatuses] = useState<TaskStatus[]>([])
  const [filters, setFilters] = useState({ status_id: '', priority: '' })
  const [form, setForm] = useState({ title: '', scope_type: 'company', priority: 'normal' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, unknown> = {}
      if (filters.status_id) params.status_id = filters.status_id
      if (filters.priority) params.priority = filters.priority
      const [ts, st] = await Promise.all([tasks.list(params), taskStatuses.list()])
      setItems(ts)
      setStatuses(st)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setLoading(false)
    }
  }, [filters])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await tasks.create({ ...form })
      setForm({ title: '', scope_type: 'company', priority: 'normal' })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <h1>{t('tasks.tasks')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      {can('tasks.create') && (
        <div className="card">
          <h2>{t('tasks.new_task')}</h2>
          <input placeholder={t('tasks.title')} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <select value={form.scope_type} onChange={(e) => setForm({ ...form, scope_type: e.target.value })}>
            {['company', 'branch', 'department', 'team'].map((s) => <option key={s} value={s}>{t(`tasks.scope_${s}`)}</option>)}
          </select>
          <select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
            {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{t(`tasks.priority_${p}`)}</option>)}
          </select>
          <button type="button" onClick={() => void create()} disabled={busy || !form.title}>{t('common.create')}</button>
        </div>
      )}

      <div className="filters">
        <select value={filters.status_id} onChange={(e) => setFilters({ ...filters, status_id: e.target.value })}>
          <option value="">{t('tasks.all_statuses')}</option>
          {statuses.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select value={filters.priority} onChange={(e) => setFilters({ ...filters, priority: e.target.value })}>
          <option value="">{t('tasks.all_priorities')}</option>
          {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{t(`tasks.priority_${p}`)}</option>)}
        </select>
      </div>

      {loading ? <p>{t('common.loading')}</p> : (
        <table>
          <thead>
            <tr><th>{t('tasks.title')}</th><th>{t('tasks.priority')}</th><th>{t('tasks.due')}</th><th>{t('tasks.status')}</th></tr>
          </thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan={4}>{t('tasks.none')}</td></tr>}
            {items.map((tk) => (
              <tr key={tk.id}>
                <td><Link to={`/tasks/${tk.id}`}>{tk.title}</Link></td>
                <td>{t(`tasks.priority_${tk.priority}`)}</td>
                <td>{tk.due_on ?? (tk.due_at ? tk.due_at.slice(0, 10) : '—')}{tk.is_overdue ? ` (${t('tasks.overdue')})` : ''}</td>
                <td>{tk.status_category ? t(`tasks.category_${tk.status_category}`) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
