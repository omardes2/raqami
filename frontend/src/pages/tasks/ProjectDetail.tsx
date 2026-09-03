import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { projects, taskStatuses, type Project, type Task, type TaskStatus } from '../../tasks/api'

/** Project detail: summary, progress, and a Kanban board grouped by status. */
export default function ProjectDetail() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [project, setProject] = useState<Project | null>(null)
  const [board, setBoard] = useState<Task[]>([])
  const [statuses, setStatuses] = useState<TaskStatus[]>([])
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const [p, b, st] = await Promise.all([projects.get(id), projects.board(id), taskStatuses.list()])
      setProject(p)
      setBoard(b)
      setStatuses(st)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [id])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  if (error) return <div className="page"><p className="error" role="alert">{error}</p></div>
  if (!project) return <div className="page"><p>{t('common.loading')}</p></div>

  return (
    <div className="page">
      <h1>{project.name}</h1>
      <p>
        {t('tasks.status')}: {t(`tasks.project_status_${project.status}`)} · {t('tasks.visibility')}: {t(`tasks.visibility_${project.visibility}`)}
        {project.progress != null && <> · {t('tasks.progress')}: {Math.round(project.progress * 100)}%</>}
      </p>
      <div className="board">
        {statuses.filter((s) => s.active).map((s) => (
          <div key={s.id} className="board-column">
            <h3>{s.name}</h3>
            {board.filter((tk) => tk.status_id === s.id).map((tk) => (
              <div key={tk.id} className="card">{tk.title}{tk.is_overdue && <span className="warn"> ({t('tasks.overdue')})</span>}</div>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
