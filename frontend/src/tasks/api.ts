import { api, ensureCsrf } from '../lib/api'

// --- Types (mirror the backend task resources) ---

export interface TaskAssignee {
  employee_id: string
  is_primary: boolean
  assigned_at: string | null
}

export interface Task {
  id: string
  project_id: string | null
  parent_task_id: string | null
  title: string
  description?: string | null
  status_id: string
  status_category?: string | null
  priority: string
  scope_type: string | null
  scope_id: string | null
  due_type: string
  due_on: string | null
  due_at: string | null
  due_timezone: string | null
  start_on?: string | null
  is_overdue: boolean
  completed_at: string | null
  archived_at: string | null
  estimated_minutes: number | null
  board_rank: number | null
  version: number
  assignees?: TaskAssignee[]
  checklist_items?: ChecklistItem[]
  attachments?: TaskAttachment[]
  subtasks?: Task[]
}

export interface ChecklistItem {
  id: string
  text: string
  is_completed: boolean
  completed_at: string | null
  sort_order: number
}

export interface TaskAttachment {
  id: string
  task_id: string
  comment_id: string | null
  original_filename: string
  mime_type: string | null
  size_bytes: number
  uploaded_by_user_id: string
  created_at: string | null
}

export interface TaskComment {
  id: string
  task_id: string
  user_id: string
  employee_id: string | null
  body: string | null
  is_deleted: boolean
  edited_at: string | null
  version: number
  created_at: string | null
  mentions?: string[]
}

export interface TaskStatus {
  id: string
  name: string
  code: string
  category: string
  color: string | null
  sort_order: number
  is_default: boolean
  active: boolean
}

export interface TaskActivity {
  id: string
  task_id: string | null
  project_id: string | null
  actor_user_id: string | null
  event_type: string
  metadata: Record<string, unknown> | null
  created_at: string | null
}

export interface Project {
  id: string
  name: string
  code: string | null
  description: string | null
  status: string
  visibility: string
  scope_type: string
  scope_id: string | null
  owner_employee_id: string | null
  start_on: string | null
  due_on: string | null
  completed_at: string | null
  archived_at: string | null
  is_archived: boolean
  version: number
  progress?: number | null
  members?: ProjectMember[]
}

export interface ProjectMember {
  id: string
  project_id: string
  employee_id: string
  role: string
  created_at: string | null
}

export interface WorkloadRow {
  employee_id: string
  active: number
  high_urgent: number
  overdue: number
  estimated_minutes: number
  due_soon: number
}

const unwrap = <T>(d: { data?: T } | T): T => (d && typeof d === 'object' && 'data' in (d as object) ? (d as { data: T }).data : (d as T))

export const tasks = {
  async me(params: Record<string, unknown> = {}): Promise<Task[]> {
    const { data } = await api.get('/tasks/me', { params })
    return data.data ?? []
  },
  async list(params: Record<string, unknown> = {}): Promise<Task[]> {
    const { data } = await api.get('/tasks', { params })
    return data.data ?? []
  },
  async get(id: string): Promise<Task> {
    const { data } = await api.get(`/tasks/${id}`)
    return unwrap<Task>(data)
  },
  async create(payload: Record<string, unknown>): Promise<Task> {
    await ensureCsrf()
    const { data } = await api.post('/tasks', payload)
    return unwrap<Task>(data)
  },
  async update(id: string, payload: Record<string, unknown>): Promise<Task> {
    await ensureCsrf()
    const { data } = await api.patch(`/tasks/${id}`, payload)
    return unwrap<Task>(data)
  },
  async setStatus(id: string, statusId: string, expectedVersion?: number): Promise<Task> {
    await ensureCsrf()
    const { data } = await api.post(`/tasks/${id}/status`, { status_id: statusId, expected_version: expectedVersion })
    return unwrap<Task>(data)
  },
  async assign(id: string, employeeId: string, isPrimary = false): Promise<Task> {
    await ensureCsrf()
    const { data } = await api.post(`/tasks/${id}/assign`, { employee_id: employeeId, is_primary: isPrimary })
    return unwrap<Task>(data)
  },
  async unassign(id: string, employeeId: string): Promise<void> {
    await ensureCsrf()
    await api.delete(`/tasks/${id}/assignees/${employeeId}`)
  },
  async archive(id: string): Promise<Task> {
    await ensureCsrf()
    const { data } = await api.post(`/tasks/${id}/archive`, {})
    return unwrap<Task>(data)
  },
  async rank(id: string, statusId: string, afterTaskId?: string, beforeTaskId?: string): Promise<Task> {
    await ensureCsrf()
    const { data } = await api.post(`/tasks/${id}/rank`, { status_id: statusId, after_task_id: afterTaskId, before_task_id: beforeTaskId })
    return unwrap<Task>(data)
  },
  async comments(id: string): Promise<TaskComment[]> {
    const { data } = await api.get(`/tasks/${id}/comments`)
    return data.data ?? []
  },
  async comment(id: string, body: string, mentions: string[] = []): Promise<TaskComment> {
    await ensureCsrf()
    const { data } = await api.post(`/tasks/${id}/comments`, { body, mentions })
    return unwrap<TaskComment>(data)
  },
  async addChecklist(id: string, text: string): Promise<ChecklistItem> {
    await ensureCsrf()
    const { data } = await api.post(`/tasks/${id}/checklist`, { text })
    return unwrap<ChecklistItem>(data)
  },
  async toggleChecklist(id: string, itemId: string, completed: boolean): Promise<ChecklistItem> {
    await ensureCsrf()
    const { data } = await api.patch(`/tasks/${id}/checklist/${itemId}`, { is_completed: completed })
    return unwrap<ChecklistItem>(data)
  },
  async watch(id: string, on: boolean): Promise<void> {
    await ensureCsrf()
    if (on) await api.post(`/tasks/${id}/watch`, {})
    else await api.delete(`/tasks/${id}/watch`)
  },
  async activity(id: string): Promise<TaskActivity[]> {
    const { data } = await api.get(`/tasks/${id}/activity`)
    return data.data ?? []
  },
  async summary(): Promise<Record<string, unknown>> {
    const { data } = await api.get('/tasks/reports/summary')
    return unwrap<Record<string, unknown>>(data)
  },
  async workload(): Promise<WorkloadRow[]> {
    const { data } = await api.get('/tasks/reports/workload')
    return unwrap<WorkloadRow[]>(data)
  },
}

export const projects = {
  async list(params: Record<string, unknown> = {}): Promise<Project[]> {
    const { data } = await api.get('/projects', { params })
    return data.data ?? []
  },
  async get(id: string): Promise<Project> {
    const { data } = await api.get(`/projects/${id}`)
    return unwrap<Project>(data)
  },
  async create(payload: Record<string, unknown>): Promise<Project> {
    await ensureCsrf()
    const { data } = await api.post('/projects', payload)
    return unwrap<Project>(data)
  },
  async update(id: string, payload: Record<string, unknown>): Promise<Project> {
    await ensureCsrf()
    const { data } = await api.patch(`/projects/${id}`, payload)
    return unwrap<Project>(data)
  },
  async archive(id: string): Promise<Project> {
    await ensureCsrf()
    const { data } = await api.post(`/projects/${id}/archive`, {})
    return unwrap<Project>(data)
  },
  async board(id: string): Promise<Task[]> {
    const { data } = await api.get(`/projects/${id}/board`)
    return data.data ?? []
  },
  async members(id: string): Promise<ProjectMember[]> {
    const { data } = await api.get(`/projects/${id}/members`)
    return data.data ?? []
  },
  async addMember(id: string, employeeId: string, role: string): Promise<ProjectMember> {
    await ensureCsrf()
    const { data } = await api.post(`/projects/${id}/members`, { employee_id: employeeId, role })
    return unwrap<ProjectMember>(data)
  },
}

export const taskStatuses = {
  async list(): Promise<TaskStatus[]> {
    const { data } = await api.get('/task-statuses')
    return data.data ?? []
  },
  async create(payload: Record<string, unknown>): Promise<TaskStatus> {
    await ensureCsrf()
    const { data } = await api.post('/task-statuses', payload)
    return unwrap<TaskStatus>(data)
  },
}
