import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../../lib/api'
import { fetchOptions } from '../../org/api'
import type { Option } from '../../org/types'
import { useAuth } from '../../auth/AuthContext'

interface EmployeeDetailData {
  id: string
  employee_number: string
  full_name: string
  first_name: string
  last_name: string
  employment_status: string
  employment_type: string
  hire_date: string | null
  branch_id: string | null
  department_id: string | null
  job_title_id: string | null
  direct_manager_employee_id: string | null
  user_id: string | null
  work_email: string | null
  work_phone: string | null
  status: string
  branch?: { id: string; name: string } | null
  department?: { id: string; name: string } | null
  job_title?: { id: string; title: string } | null
  manager?: { id: string; full_name: string } | null
  teams?: { id: string; name: string }[]
  sensitive?: Record<string, string | null>
}

type Tab = 'overview' | 'employment' | 'organization' | 'documents' | 'contracts' | 'history'

export default function EmployeeDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { can } = useAuth()
  const [emp, setEmp] = useState<EmployeeDetailData | null>(null)
  const [tab, setTab] = useState<Tab>('overview')

  const load = useCallback(async () => {
    const { data } = await api.get(`/employees/${id}`)
    setEmp(data)
  }, [id])

  useEffect(() => {
    // eslint-disable-next-line react/set-state-in-effect -- intentional data fetch on dependency change
    load()
  }, [load])

  if (!emp) return <p>{t('common.loading')}</p>

  const tabs: Tab[] = ['overview', 'employment', 'organization', 'documents', 'contracts', 'history']

  return (
    <div>
      <div className="page-head">
        <div>
          <h1>{emp.full_name}</h1>
          <div className="muted">
            <code className="perm">{emp.employee_number}</code>{' '}
            {emp.job_title?.title ?? ''}{' '}
            <span className="pill">{emp.employment_status}</span>
          </div>
        </div>
        {can('employees.archive') && emp.status === 'active' && (
          <button className="btn-link danger" onClick={async () => {
            if (!confirm(t('employees.archive_confirm'))) return
            await ensureCsrf()
            await api.post(`/employees/${emp.id}/archive`)
            navigate('/employees')
          }}>{t('org.archive')}</button>
        )}
      </div>

      <nav className="tabs">
        {tabs.map((tb) => (
          <button key={tb} className={tab === tb ? 'active' : ''} onClick={() => setTab(tb)}>
            {t(`employees.tabs.${tb}`)}
          </button>
        ))}
      </nav>

      <div className="tab-body">
        {tab === 'overview' && <Overview emp={emp} />}
        {tab === 'employment' && <Employment emp={emp} />}
        {tab === 'organization' && <Organization emp={emp} onChanged={load} />}
        {tab === 'documents' && <Documents employeeId={emp.id} />}
        {tab === 'contracts' && <Contracts employeeId={emp.id} />}
        {tab === 'history' && <History employeeId={emp.id} />}
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="detail-row">
      <span className="detail-label">{label}</span>
      <span>{value ?? '—'}</span>
    </div>
  )
}

function Overview({ emp }: { emp: EmployeeDetailData }) {
  const { t } = useTranslation()
  return (
    <div className="detail-grid">
      <Row label={t('employees.branch')} value={emp.branch?.name} />
      <Row label={t('employees.department')} value={emp.department?.name} />
      <Row label={t('employees.teams')} value={(emp.teams ?? []).map((x) => x.name).join(', ') || '—'} />
      <Row label={t('employees.manager')} value={emp.manager?.full_name} />
      <Row label={t('employees.hire_date')} value={emp.hire_date} />
      <Row label={t('employees.work_email')} value={emp.work_email} />
      {emp.sensitive && (
        <>
          <h3 className="sensitive-head">{t('employees.sensitive')}</h3>
          <Row label={t('employees.personal_email')} value={emp.sensitive.personal_email} />
          <Row label={t('employees.mobile_phone')} value={emp.sensitive.mobile_phone} />
          <Row label={t('employees.date_of_birth')} value={emp.sensitive.date_of_birth} />
          <Row label={t('employees.nationality')} value={emp.sensitive.nationality} />
        </>
      )}
    </div>
  )
}

function Employment({ emp }: { emp: EmployeeDetailData }) {
  const { t } = useTranslation()
  return (
    <div className="detail-grid">
      <Row label={t('employees.employment_status')} value={emp.employment_status} />
      <Row label={t('employees.employment_type')} value={emp.employment_type} />
      <Row label={t('employees.hire_date')} value={emp.hire_date} />
      <Row label={t('employees.has_account')} value={emp.user_id ? emp.user_id : '—'} />
    </div>
  )
}

function Organization({ emp, onChanged }: { emp: EmployeeDetailData; onChanged: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [branches, setBranches] = useState<Option[]>([])
  const [departments, setDepartments] = useState<Option[]>([])
  const [jobTitles, setJobTitles] = useState<Option[]>([])
  const [form, setForm] = useState<Record<string, string>>({
    branch_id: emp.branch_id ?? '',
    department_id: emp.department_id ?? '',
    job_title_id: emp.job_title_id ?? '',
  })
  const [msg, setMsg] = useState('')
  const [userId, setUserId] = useState('')

  useEffect(() => {
    fetchOptions('branches').then(setBranches).catch(() => {})
    fetchOptions('departments').then(setDepartments).catch(() => {})
    fetchOptions('job-titles', 'title').then(setJobTitles).catch(() => {})
  }, [])

  async function transfer(e: React.FormEvent) {
    e.preventDefault()
    setMsg('')
    await ensureCsrf()
    await api.post(`/employees/${emp.id}/transfer`, {
      branch_id: form.branch_id || null,
      department_id: form.department_id || null,
      job_title_id: form.job_title_id || null,
    })
    setMsg(t('employees.saved'))
    onChanged()
  }

  return (
    <div className="detail-grid">
      {can('employees.transfer') ? (
        <form onSubmit={transfer} className="transfer-form">
          <h3>{t('employees.transfer_title')}</h3>
          {msg && <p className="notice">{msg}</p>}
          <Sel label={t('employees.branch')} options={branches} value={form.branch_id} onChange={(v) => setForm({ ...form, branch_id: v })} />
          <Sel label={t('employees.department')} options={departments} value={form.department_id} onChange={(v) => setForm({ ...form, department_id: v })} />
          <Sel label={t('employees.job_title')} options={jobTitles} value={form.job_title_id} onChange={(v) => setForm({ ...form, job_title_id: v })} />
          <button className="btn-primary inline" type="submit">{t('employees.transfer')}</button>
        </form>
      ) : (
        <>
          <Row label={t('employees.branch')} value={emp.branch?.name} />
          <Row label={t('employees.department')} value={emp.department?.name} />
          <Row label={t('employees.job_title')} value={emp.job_title?.title} />
        </>
      )}

      {can('employees.link_user') && (
        <form className="link-user" onSubmit={async (e) => { e.preventDefault(); await ensureCsrf(); await api.post(`/employees/${emp.id}/user-link`, { user_id: userId }); onChanged() }}>
          <h3>{t('employees.link_user')}</h3>
          {emp.user_id ? (
            <div>
              <code className="perm">{emp.user_id}</code>{' '}
              <button type="button" className="btn-link danger" onClick={async () => { await ensureCsrf(); await api.delete(`/employees/${emp.id}/user-link`); onChanged() }}>{t('employees.unlink_user')}</button>
            </div>
          ) : (
            <div className="inline-form">
              <input placeholder={t('employees.user_id')} value={userId} onChange={(e) => setUserId(e.target.value)} />
              <button className="btn-primary inline" type="submit">{t('employees.link_user')}</button>
            </div>
          )}
        </form>
      )}
    </div>
  )
}

function Sel({ label, options, value, onChange }: { label: string; options: Option[]; value: string; onChange: (v: string) => void }) {
  const { t } = useTranslation()
  return (
    <div className="field">
      <label>{label}</label>
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{t('org.none')}</option>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </div>
  )
}

interface DocRow { id: string; title: string; category: string; original_filename: string; download_url: string }
function Documents({ employeeId }: { employeeId: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [docs, setDocs] = useState<DocRow[]>([])
  const load = useCallback(() => { api.get(`/employees/${employeeId}/documents`).then(({ data }) => setDocs(data.data)).catch(() => setDocs([])) }, [employeeId])
  useEffect(() => { load() }, [load])

  async function upload(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const fd = new FormData(e.currentTarget)
    await ensureCsrf()
    await api.post(`/employees/${employeeId}/documents`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    e.currentTarget.reset()
    load()
  }

  return (
    <div>
      {can('employee_documents.upload') && (
        <form className="inline-form" onSubmit={upload}>
          <input name="title" placeholder={t('employees.document_title')} required />
          <input name="file" type="file" required />
          <button className="btn-primary inline" type="submit">{t('employees.upload')}</button>
        </form>
      )}
      {docs.length === 0 ? <p className="muted">{t('employees.no_documents')}</p> : (
        <table className="data-table">
          <thead><tr><th>{t('employees.document_title')}</th><th>{t('employees.category')}</th><th></th></tr></thead>
          <tbody>
            {docs.map((d) => (
              <tr key={d.id}>
                <td>{d.title} <span className="muted">{d.original_filename}</span></td>
                <td>{d.category}</td>
                <td><a href={d.download_url}>{t('employees.download')}</a></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

interface ContractRow { id: string; contract_number: string; contract_type: string; start_date: string; end_date: string | null; status: string }
function Contracts({ employeeId }: { employeeId: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [rows, setRows] = useState<ContractRow[]>([])
  const load = useCallback(() => { api.get(`/employees/${employeeId}/contracts`).then(({ data }) => setRows(data.data)).catch(() => setRows([])) }, [employeeId])
  useEffect(() => { load() }, [load])

  async function add(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const fd = new FormData(e.currentTarget)
    await ensureCsrf()
    await api.post(`/employees/${employeeId}/contracts`, Object.fromEntries(fd))
    e.currentTarget.reset()
    load()
  }

  return (
    <div>
      {can('employee_contracts.create') && (
        <form className="inline-form" onSubmit={add}>
          <input name="contract_number" placeholder={t('employees.contract_number')} required />
          <input name="start_date" type="date" required />
          <button className="btn-primary inline" type="submit">{t('employees.add_contract')}</button>
        </form>
      )}
      {rows.length === 0 ? <p className="muted">{t('employees.no_contracts')}</p> : (
        <table className="data-table">
          <thead><tr><th>{t('employees.contract_number')}</th><th>{t('employees.contract_type')}</th><th>{t('employees.start_date')}</th><th>{t('employees.employment_status')}</th></tr></thead>
          <tbody>
            {rows.map((c) => (
              <tr key={c.id}><td>{c.contract_number}</td><td>{c.contract_type}</td><td>{c.start_date}</td><td><span className="pill">{c.status}</span></td></tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

interface HistoryRow { id: string; event_type: string; effective_date: string | null; created_at: string }
function History({ employeeId }: { employeeId: string }) {
  const { t } = useTranslation()
  const [rows, setRows] = useState<HistoryRow[]>([])
  useEffect(() => { api.get(`/employees/${employeeId}/history`).then(({ data }) => setRows(data.data)).catch(() => setRows([])) }, [employeeId])

  if (rows.length === 0) return <p className="muted">{t('employees.no_history')}</p>
  return (
    <table className="data-table">
      <thead><tr><th>{t('employees.event')}</th><th>{t('employees.when')}</th></tr></thead>
      <tbody>
        {rows.map((h) => (
          <tr key={h.id}><td><code className="perm">{h.event_type}</code></td><td>{new Date(h.created_at).toLocaleString()}</td></tr>
        ))}
      </tbody>
    </table>
  )
}
