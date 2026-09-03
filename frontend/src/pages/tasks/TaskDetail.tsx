import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { tasks, taskStatuses, type Task, type TaskComment, type TaskStatus } from '../../tasks/api'

/** Task detail: header, status change, checklist, comments, watch. */
export default function TaskDetail() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [task, setTask] = useState<Task | null>(null)
  const [statuses, setStatuses] = useState<TaskStatus[]>([])
  const [comments, setComments] = useState<TaskComment[]>([])
  const [body, setBody] = useState('')
  const [checkText, setCheckText] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [tk, st, cm] = await Promise.all([tasks.get(id), taskStatuses.list(), tasks.comments(id)])
      setTask(tk)
      setStatuses(st)
      setComments(cm)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [id])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function act(fn: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try { await fn(); await load() } catch (e) { setError((e as Error).message) } finally { setBusy(false) }
  }

  if (error) return <div className="page"><p className="error" role="alert">{error}</p></div>
  if (!task) return <div className="page"><p>{t('common.loading')}</p></div>

  return (
    <div className="page">
      <h1>{task.title}</h1>
      <p>
        {t('tasks.priority')}: {t(`tasks.priority_${task.priority}`)} · {t('tasks.status')}: {task.status_category ? t(`tasks.category_${task.status_category}`) : '—'}
        {task.is_overdue && <span className="warn"> ({t('tasks.overdue')})</span>}
      </p>
      {task.description && <p>{task.description}</p>}

      <label>
        {t('tasks.change_status')}
        <select value={task.status_id} onChange={(e) => void act(() => tasks.setStatus(task.id, e.target.value, task.version))} disabled={busy}>
          {statuses.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      </label>

      <h2>{t('tasks.checklist')}</h2>
      <ul>
        {(task.checklist_items ?? []).map((it) => (
          <li key={it.id}>
            <label>
              <input type="checkbox" checked={it.is_completed} onChange={(e) => void act(() => tasks.toggleChecklist(task.id, it.id, e.target.checked))} disabled={busy} />
              {it.text}
            </label>
          </li>
        ))}
      </ul>
      <input placeholder={t('tasks.checklist_add')} value={checkText} onChange={(e) => setCheckText(e.target.value)} />
      <button type="button" onClick={() => void act(async () => { await tasks.addChecklist(task.id, checkText); setCheckText('') })} disabled={busy || !checkText}>{t('common.add')}</button>

      <h2>{t('tasks.comments')}</h2>
      {comments.map((c) => (
        <div key={c.id} className="card">{c.is_deleted ? <em>{t('tasks.comment_deleted')}</em> : c.body}</div>
      ))}
      <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder={t('tasks.comment_placeholder')} />
      <button type="button" onClick={() => void act(async () => { await tasks.comment(task.id, body); setBody('') })} disabled={busy || !body}>{t('tasks.comment_send')}</button>

      <div>
        <button type="button" onClick={() => void act(() => tasks.watch(task.id, true))} disabled={busy}>{t('tasks.watch')}</button>
        <button type="button" onClick={() => void act(() => tasks.watch(task.id, false))} disabled={busy}>{t('tasks.unwatch')}</button>
      </div>
    </div>
  )
}
