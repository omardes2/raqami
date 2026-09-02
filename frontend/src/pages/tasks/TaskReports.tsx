import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { tasks } from '../../tasks/api'

/** Task reporting: counts by status category and priority, plus overdue. */
export default function TaskReports() {
  const { t } = useTranslation()
  const [summary, setSummary] = useState<Record<string, unknown> | null>(null)
  const [error, setError] = useState<string | null>(null)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => {
    tasks.summary().then(setSummary).catch((e: Error) => setError(e.message))
  }, [])

  if (error) return <div className="page"><p className="error" role="alert">{error}</p></div>
  if (!summary) return <div className="page"><p>{t('common.loading')}</p></div>

  const byStatus = (summary.by_status ?? {}) as Record<string, number>
  const byPriority = (summary.by_priority ?? {}) as Record<string, number>

  return (
    <div className="page">
      <h1>{t('tasks.reports')}</h1>
      <h2>{t('tasks.by_status')}</h2>
      <ul>{Object.entries(byStatus).map(([k, v]) => <li key={k}>{t(`tasks.category_${k}`)}: {v}</li>)}</ul>
      <h2>{t('tasks.by_priority')}</h2>
      <ul>{Object.entries(byPriority).map(([k, v]) => <li key={k}>{t(`tasks.priority_${k}`)}: {v}</li>)}</ul>
      <p>{t('tasks.overdue')}: {String(summary.overdue ?? 0)}</p>
    </div>
  )
}
