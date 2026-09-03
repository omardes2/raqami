import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { taskStatuses, type TaskStatus } from '../../tasks/api'

/** Tenant task status catalog administration (tasks.settings.manage). */
export default function TaskStatuses() {
  const { t } = useTranslation()
  const [items, setItems] = useState<TaskStatus[]>([])
  const [form, setForm] = useState({ name: '', code: '', category: 'todo' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setItems(await taskStatuses.list())
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await taskStatuses.create({ ...form })
      setForm({ name: '', code: '', category: 'todo' })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <h1>{t('tasks.statuses')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="card">
        <input placeholder={t('tasks.status_name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <input placeholder={t('tasks.status_code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
        <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
          {['backlog', 'todo', 'in_progress', 'blocked', 'done', 'cancelled'].map((c) => <option key={c} value={c}>{t(`tasks.category_${c}`)}</option>)}
        </select>
        <button type="button" onClick={() => void create()} disabled={busy || !form.name || !form.code}>{t('common.create')}</button>
      </div>
      <table>
        <thead><tr><th>{t('tasks.status_name')}</th><th>{t('tasks.category')}</th><th>{t('tasks.status_default')}</th><th>{t('tasks.status_active')}</th></tr></thead>
        <tbody>
          {items.map((s) => (
            <tr key={s.id}>
              <td>{s.name}</td><td>{t(`tasks.category_${s.category}`)}</td><td>{s.is_default ? '✓' : ''}</td><td>{s.active ? '✓' : ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
