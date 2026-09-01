import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../../lib/api'
import { fetchOptions } from '../../org/api'
import type { Option } from '../../org/types'
import Field from '../../components/Field'

const TYPES = ['full_time', 'part_time', 'contract', 'temporary', 'internship', 'freelance']

/** Create a new employee (organized, multi-section form; no payroll/attendance fields). */
export default function EmployeeForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [form, setForm] = useState<Record<string, unknown>>({ employment_type: 'full_time', employment_status: 'active' })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  const [branches, setBranches] = useState<Option[]>([])
  const [departments, setDepartments] = useState<Option[]>([])
  const [jobTitles, setJobTitles] = useState<Option[]>([])

  useEffect(() => {
    fetchOptions('branches').then(setBranches).catch(() => {})
    fetchOptions('departments').then(setDepartments).catch(() => {})
    fetchOptions('job-titles', 'title').then(setJobTitles).catch(() => {})
  }, [])

  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm({ ...form, [k]: e.target.value })

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      await ensureCsrf()
      const { data } = await api.post('/employees', form)
      navigate(`/employees/${data.id}`)
    } catch (err: unknown) {
      const resp = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
      setErrors(resp?.data?.errors ?? {})
    } finally {
      setBusy(false)
    }
  }

  const err = (k: string) => errors[k]?.[0]

  return (
    <div className="narrow-wide">
      <h1>{t('employees.new')}</h1>
      <form onSubmit={submit}>
        <fieldset>
          <legend>{t('employees.sections.identity')}</legend>
          <div className="grid-2">
            <Field label={t('employees.first_name')} value={String(form.first_name ?? '')} onChange={set('first_name')} error={err('first_name')} />
            <Field label={t('employees.last_name')} value={String(form.last_name ?? '')} onChange={set('last_name')} error={err('last_name')} />
          </div>
          <div className="grid-2">
            <Field label={t('employees.middle_name')} value={String(form.middle_name ?? '')} onChange={set('middle_name')} />
            <Field label={t('employees.display_name')} value={String(form.display_name ?? '')} onChange={set('display_name')} />
          </div>
          <Field label={t('employees.number')} value={String(form.employee_number ?? '')} onChange={set('employee_number')} error={err('employee_number')} />
        </fieldset>

        <fieldset>
          <legend>{t('employees.sections.organization')}</legend>
          <div className="grid-2">
            <SelectField label={t('employees.branch')} options={branches} value={form.branch_id} onChange={set('branch_id')} />
            <SelectField label={t('employees.department')} options={departments} value={form.department_id} onChange={set('department_id')} />
          </div>
          <SelectField label={t('employees.job_title')} options={jobTitles} value={form.job_title_id} onChange={set('job_title_id')} />
        </fieldset>

        <fieldset>
          <legend>{t('employees.sections.employment')}</legend>
          <div className="grid-2">
            <div className="field">
              <label>{t('employees.employment_type')}</label>
              <select value={String(form.employment_type ?? 'full_time')} onChange={set('employment_type')}>
                {TYPES.map((ty) => <option key={ty} value={ty}>{ty}</option>)}
              </select>
            </div>
            <Field label={t('employees.hire_date')} type="date" value={String(form.hire_date ?? '')} onChange={set('hire_date')} />
          </div>
        </fieldset>

        <fieldset>
          <legend>{t('employees.sections.contact')}</legend>
          <div className="grid-2">
            <Field label={t('employees.work_email')} type="email" value={String(form.work_email ?? '')} onChange={set('work_email')} error={err('work_email')} />
            <Field label={t('employees.work_phone')} value={String(form.work_phone ?? '')} onChange={set('work_phone')} />
          </div>
        </fieldset>

        <div className="modal-actions">
          <button type="button" className="btn-ghost" onClick={() => navigate('/employees')}>{t('org.cancel')}</button>
          <button type="submit" className="btn-primary inline" disabled={busy}>{t('org.create')}</button>
        </div>
      </form>
    </div>
  )
}

function SelectField({ label, options, value, onChange }: { label: string; options: Option[]; value: unknown; onChange: (e: React.ChangeEvent<HTMLSelectElement>) => void }) {
  const { t } = useTranslation()
  return (
    <div className="field">
      <label>{label}</label>
      <select value={String(value ?? '')} onChange={onChange}>
        <option value="">{t('org.none')}</option>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </div>
  )
}
