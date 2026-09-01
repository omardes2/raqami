export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
  links?: unknown
}

export interface OrgRow {
  id: string
  name?: string
  title?: string
  code: string
  status: string
  employees_count?: number
  members_count?: number
  branch_id?: string | null
  department_id?: string | null
  parent_department_id?: string | null
}

export interface EmployeeRow {
  id: string
  employee_number: string
  full_name: string
  work_email: string | null
  employment_status: string
  employment_type: string
  branch?: string | null
  department?: string | null
  job_title?: string | null
  manager?: string | null
  has_user_account: boolean
}

export interface Option {
  value: string
  label: string
}
