import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../lib/api'
import { useAuth } from '../../auth/AuthContext'
import {
  payroll,
  type EmployeeCompensation as Compensation,
  type EmployeeComponentAssignment,
  type PayrollComponent,
} from '../../payroll/api'

interface EmployeeHit {
  id: string
  employee_number: string
  full_name: string
}

/**
 * Employee compensation management (Phase 1). Company-level payroll authority is
 * enforced by the backend; this page only shows what the viewer may act on.
 * Salary is effective-dated: a change is a NEW row and an explicit end — historical
 * rows are never edited in place.
 */
export default function EmployeeCompensation() {
  const { t } = useTranslation()
  const { can } = useAuth()
  // Assigning/ending a component to an employee is gated by compensation.manage on
  // the backend (the components.manage grant governs the catalog, not assignments).
  const canManage = can('payroll.compensation.manage')

  const [search, setSearch] = useState('')
  const [hits, setHits] = useState<EmployeeHit[]>([])
  const [selected, setSelected] = useState<EmployeeHit | null>(null)
  const [history, setHistory] = useState<Compensation[]>([])
  const [assignments, setAssignments] = useState<EmployeeComponentAssignment[]>([])
  const [components, setComponents] = useState<PayrollComponent[]>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const [comp, setComp] = useState({ currency: 'USD', base_amount_minor: '', effective_from: '', effective_to: '' })
  const [assign, setAssign] = useState({ payroll_component_id: '', fixed_amount_minor: '', currency: 'USD', rate_bps: '', effective_from: '', effective_to: '' })

  const runSearch = useCallback(async () => {
    setError(null)
    try {
      const { data } = await api.get('/employees', { params: { search, per_page: 10 } })
      setHits((data.data ?? []).map((r: EmployeeHit) => ({ id: r.id, employee_number: r.employee_number, full_name: r.full_name })))
    } catch (e) {
      setError((e as Error).message)
    }
  }, [search])

  const loadEmployee = useCallback(async (emp: EmployeeHit) => {
    setError(null)
    setSelected(emp)
    try {
      const [h, a, c] = await Promise.all([
        payroll.compensations(emp.id),
        payroll.employeeComponents(emp.id),
        payroll.components(),
      ])
      setHistory(h)
      setAssignments(a)
      setComponents(c)
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  async function reload() {
    if (selected) await loadEmployee(selected)
  }

  async function createComp() {
    if (!selected) return
    setBusy(true)
    setError(null)
    try {
      await payroll.createCompensation(selected.id, {
        currency: comp.currency,
        base_amount_minor: Number(comp.base_amount_minor),
        effective_from: comp.effective_from,
        effective_to: comp.effective_to || undefined,
      })
      setComp({ currency: 'USD', base_amount_minor: '', effective_from: '', effective_to: '' })
      await reload()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function endComp(row: Compensation) {
    const to = window.prompt(t('payroll.effective_to') ?? 'effective_to')
    if (!to) return
    try {
      await payroll.endCompensation(row.id, to)
      await reload()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  async function createAssignment() {
    if (!selected || !assign.payroll_component_id) return
    const mode = components.find((c) => c.id === assign.payroll_component_id)?.calculation_mode
    setBusy(true)
    setError(null)
    try {
      const payload: Record<string, unknown> = {
        payroll_component_id: assign.payroll_component_id,
        effective_from: assign.effective_from,
        effective_to: assign.effective_to || undefined,
      }
      if (mode === 'fixed') {
        payload.fixed_amount_minor = Number(assign.fixed_amount_minor)
        payload.currency = assign.currency
      } else {
        payload.rate_bps = Number(assign.rate_bps)
      }
      await payroll.assignComponent(selected.id, payload)
      setAssign({ payroll_component_id: '', fixed_amount_minor: '', currency: 'USD', rate_bps: '', effective_from: '', effective_to: '' })
      await reload()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function endAssignment(row: EmployeeComponentAssignment) {
    const to = window.prompt(t('payroll.effective_to') ?? 'effective_to')
    if (!to) return
    try {
      await payroll.endComponentAssignment(row.id, to)
      await reload()
    } catch (e) {
      setError((e as Error).message)
    }
  }

  const activeComponents = components.filter((c) => c.active)
  const selectedMode = components.find((c) => c.id === assign.payroll_component_id)?.calculation_mode
  const componentLabel = (id: string) => {
    const c = components.find((x) => x.id === id)
    return c ? `${c.code} — ${c.name}` : id
  }

  return (
    <div className="page">
      <h1>{t('nav.payroll_compensation')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      <div className="card">
        <label>{t('payroll.find_employee')}</label>
        <div className="row">
          <input type="search" value={search} placeholder={t('payroll.find_employee')} onChange={(e) => setSearch(e.target.value)} />
          <button type="button" onClick={() => void runSearch()} disabled={!search}>{t('common.search')}</button>
        </div>
        {hits.length > 0 && (
          <ul className="list">
            {hits.map((h) => (
              <li key={h.id}>
                <button type="button" className="btn-link" onClick={() => void loadEmployee(h)}>
                  {h.employee_number} — {h.full_name}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {selected && (
        <>
          <h2>{selected.employee_number} — {selected.full_name}</h2>

          <section className="card">
            <h3>{t('payroll.compensation_history')}</h3>
            <table>
              <thead><tr><th>{t('payroll.currency')}</th><th>{t('payroll.base_amount_minor')}</th><th>{t('payroll.effective_from')}</th><th>{t('payroll.effective_to')}</th><th /></tr></thead>
              <tbody>
                {history.map((row) => (
                  <tr key={row.id}>
                    <td>{row.currency}</td>
                    <td>{row.base_amount_minor}</td>
                    <td>{row.effective_from}</td>
                    <td>{row.effective_to ?? '—'}</td>
                    <td>{canManage && !row.effective_to && <button type="button" className="btn-ghost" onClick={() => void endComp(row)}>{t('payroll.end')}</button>}</td>
                  </tr>
                ))}
                {history.length === 0 && <tr><td colSpan={5}>{t('common.none')}</td></tr>}
              </tbody>
            </table>

            {canManage && (
              <div className="row">
                <input placeholder={t('payroll.currency')} value={comp.currency} onChange={(e) => setComp({ ...comp, currency: e.target.value })} />
                <input type="number" placeholder={t('payroll.base_amount_minor')} value={comp.base_amount_minor} onChange={(e) => setComp({ ...comp, base_amount_minor: e.target.value })} />
                <input type="date" aria-label={t('payroll.effective_from')} value={comp.effective_from} onChange={(e) => setComp({ ...comp, effective_from: e.target.value })} />
                <input type="date" aria-label={t('payroll.effective_to')} value={comp.effective_to} onChange={(e) => setComp({ ...comp, effective_to: e.target.value })} />
                <button type="button" onClick={() => void createComp()} disabled={busy || !comp.base_amount_minor || !comp.effective_from}>{t('payroll.add_compensation')}</button>
              </div>
            )}
          </section>

          <section className="card">
            <h3>{t('payroll.component_assignments')}</h3>
            <table>
              <thead><tr><th>{t('payroll.component')}</th><th>{t('payroll.value')}</th><th>{t('payroll.effective_from')}</th><th>{t('payroll.effective_to')}</th><th /></tr></thead>
              <tbody>
                {assignments.map((row) => (
                  <tr key={row.id}>
                    <td>{componentLabel(row.payroll_component_id)}</td>
                    <td>{row.rate_bps !== null ? `${row.rate_bps} ${t('payroll.bps')}` : `${row.fixed_amount_minor} ${row.currency ?? ''}`}</td>
                    <td>{row.effective_from}</td>
                    <td>{row.effective_to ?? '—'}</td>
                    <td>{canManage && !row.effective_to && <button type="button" className="btn-ghost" onClick={() => void endAssignment(row)}>{t('payroll.end')}</button>}</td>
                  </tr>
                ))}
                {assignments.length === 0 && <tr><td colSpan={5}>{t('common.none')}</td></tr>}
              </tbody>
            </table>

            {canManage && (
              <div className="row">
                <select value={assign.payroll_component_id} onChange={(e) => setAssign({ ...assign, payroll_component_id: e.target.value })} aria-label={t('payroll.component')}>
                  <option value="">{t('payroll.select_component')}</option>
                  {activeComponents.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}</option>)}
                </select>
                {selectedMode === 'percent_of_base' ? (
                  <input type="number" placeholder={t('payroll.rate_bps')} value={assign.rate_bps} onChange={(e) => setAssign({ ...assign, rate_bps: e.target.value })} />
                ) : (
                  <>
                    <input type="number" placeholder={t('payroll.fixed_amount_minor')} value={assign.fixed_amount_minor} onChange={(e) => setAssign({ ...assign, fixed_amount_minor: e.target.value })} />
                    <input placeholder={t('payroll.currency')} value={assign.currency} onChange={(e) => setAssign({ ...assign, currency: e.target.value })} />
                  </>
                )}
                <input type="date" aria-label={t('payroll.effective_from')} value={assign.effective_from} onChange={(e) => setAssign({ ...assign, effective_from: e.target.value })} />
                <input type="date" aria-label={t('payroll.effective_to')} value={assign.effective_to} onChange={(e) => setAssign({ ...assign, effective_to: e.target.value })} />
                <button type="button" onClick={() => void createAssignment()} disabled={busy || !assign.payroll_component_id || !assign.effective_from}>{t('payroll.assign_component')}</button>
              </div>
            )}
          </section>
        </>
      )}
    </div>
  )
}
