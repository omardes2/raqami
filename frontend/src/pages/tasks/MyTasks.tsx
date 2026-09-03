import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { tasks, type Task } from '../../tasks/api'

/** Employee "My Tasks": tasks assigned to the acting employee, by section. */
export default function MyTasks() {
  const { t } = useTranslation()
  const [items, setItems] = useState<Task[]>([])
  const [section, setSection] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setItems(await tasks.me(section === 'all' ? {} : { section }))
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setLoading(false)
    }
  }, [section])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  return (
    <div className="page">
      <h1>{t('tasks.my_tasks')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <label>
        {t('tasks.section')}
        <select value={section} onChange={(e) => setSection(e.target.value)}>
          {['all', 'today', 'upcoming', 'overdue', 'completed'].map((s) => (
            <option key={s} value={s}>{t(`tasks.section_${s}`)}</option>
          ))}
        </select>
      </label>
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
