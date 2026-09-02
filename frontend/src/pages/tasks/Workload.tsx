import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { tasks, type WorkloadRow } from '../../tasks/api'

/** Transparent per-employee workload — derived counts only, never a score. */
export default function Workload() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<WorkloadRow[]>([])
  const [error, setError] = useState<string | null>(null)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => {
    tasks.workload().then(setRows).catch((e: Error) => setError(e.message))
  }, [])

  return (
    <div className="page">
      <h1>{t('tasks.workload')}</h1>
      <p className="hint">{t('tasks.workload_hint')}</p>
      {error && <p className="error" role="alert">{error}</p>}
      <table>
        <thead>
          <tr><th>{t('tasks.employee')}</th><th>{t('tasks.active')}</th><th>{t('tasks.high_urgent')}</th><th>{t('tasks.overdue')}</th><th>{t('tasks.estimate')}</th><th>{t('tasks.due_soon')}</th></tr>
        </thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6}>{t('tasks.none')}</td></tr>}
          {rows.map((r) => (
            <tr key={r.employee_id}>
              <td>{r.employee_id}</td><td>{r.active}</td><td>{r.high_urgent}</td><td>{r.overdue}</td><td>{r.estimated_minutes}</td><td>{r.due_soon}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
