import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../lib/api'
import { fetchOptions } from '../../org/api'
import type { EmployeeRow, Option } from '../../org/types'
import { useAuth } from '../../auth/AuthContext'

const STATUSES = ['active', 'onboarding', 'probation', 'suspended', 'on_leave', 'terminated', 'archived']

export default function Employees() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const navigate = useNavigate()
  const [rows, setRows] = useState<EmployeeRow[]>([])
  const [meta, setMeta] = useState<{ current_page: number; last_page: number }>({ current_page: 1, last_page: 1 })
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [branch, setBranch] = useState('')
  const [status, setStatus] = useState('')
  const [branches, setBranches] = useState<Option[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchOptions('branches').then(setBranches).catch(() => setBranches([]))
  }, [])

  const load = useCallback(async () => {
    setLoading(true)
    const { data } = await api.get('/employees', {
      params: { search, branch_id: branch || undefined, employment_status: status || undefined, page, per_page: 20 },
    })
    setRows(data.data ?? [])
    setMeta(data.meta ?? { current_page: 1, last_page: 1 })
    setLoading(false)
  }, [search, branch, status, page])

  useEffect(() => {
    // eslint-disable-next-line react/set-state-in-effect -- intentional data fetch on dependency change
    load()
  }, [load])

  return (
    <div>
      <div className="page-head">
        <h1>{t('employees.title')}</h1>
        {can('employees.create') && (
          <button className="btn-primary inline" onClick={() => navigate('/employees/new')}>
            {t('employees.new')}
          </button>
        )}
      </div>

      <div className="filters">
        <input type="search" placeholder={t('employees.search_placeholder')} value={search}
          onChange={(e) => { setPage(1); setSearch(e.target.value) }} />
        <select value={branch} onChange={(e) => { setPage(1); setBranch(e.target.value) }}>
          <option value="">{t('employees.branch')}: {t('employees.all')}</option>
          {branches.map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
        </select>
        <select value={status} onChange={(e) => { setPage(1); setStatus(e.target.value) }}>
          <option value="">{t('employees.employment_status')}: {t('employees.all')}</option>
          {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      {loading ? (
        <p>{t('common.loading')}</p>
      ) : rows.length === 0 ? (
        <p className="muted">{t('org.empty')}</p>
      ) : (
        <>
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('employees.number')}</th>
                <th>{t('employees.full_name')}</th>
                <th>{t('employees.branch')}</th>
                <th>{t('employees.department')}</th>
                <th>{t('employees.job_title')}</th>
                <th>{t('employees.employment_status')}</th>
                <th>{t('employees.has_account')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="clickable" onClick={() => navigate(`/employees/${r.id}`)}>
                  <td><Link to={`/employees/${r.id}`}>{r.employee_number}</Link></td>
                  <td>{r.full_name}</td>
                  <td>{r.branch ?? '—'}</td>
                  <td>{r.department ?? '—'}</td>
                  <td>{r.job_title ?? '—'}</td>
                  <td><span className="pill">{r.employment_status}</span></td>
                  <td>{r.has_user_account ? t('employees.yes') : t('employees.no')}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {meta.last_page > 1 && (
            <div className="pagination">
              <button className="btn-ghost" disabled={meta.current_page <= 1} onClick={() => setPage((p) => p - 1)}>‹</button>
              <span>{meta.current_page} / {meta.last_page}</span>
              <button className="btn-ghost" disabled={meta.current_page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>›</button>
            </div>
          )}
        </>
      )}
    </div>
  )
}
