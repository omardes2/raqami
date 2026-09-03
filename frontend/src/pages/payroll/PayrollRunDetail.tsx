import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'
import {
  payroll,
  type PayrollAdjustment,
  type PayrollEntry,
  type PayrollPeriod,
  type PayrollRun,
  type RunSummary,
} from '../../payroll/api'

const emptyForm = { employee_id: '', employee_visible_label: '', direction: 'earning', amount_minor: '', currency: '', internal_reason: '', source_payroll_entry_id: '' }

/**
 * Payroll run calculation, review, and Phase-2B management. Manual adjustments are
 * PERIOD-owned authoritative inputs (a replacement run consumes the same rows; the
 * run must be recalculated for changes to take effect). Approve (four-eyes) and
 * finalize (irreversible, closes the period). A finalized run is fully read-only. The
 * server decides every result; this UI only sends facts.
 */
export default function PayrollRunDetail() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { id = '' } = useParams()
  const canCalculate = can('payroll.calculate')
  const canAdjust = can('payroll.adjust')
  const canApprove = can('payroll.approve')
  const canFinalize = can('payroll.finalize')

  const [run, setRun] = useState<PayrollRun | null>(null)
  const [period, setPeriod] = useState<PayrollPeriod | null>(null)
  const [entries, setEntries] = useState<PayrollEntry[]>([])
  const [adjustments, setAdjustments] = useState<PayrollAdjustment[]>([])
  const [summary, setSummary] = useState<RunSummary | null>(null)
  const [openEntry, setOpenEntry] = useState<PayrollEntry | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const [form, setForm] = useState({ ...emptyForm })
  const [editingId, setEditingId] = useState<string | null>(null)
  const [finalizeReason, setFinalizeReason] = useState('')
  const [showFinalize, setShowFinalize] = useState(false)

  const load = useCallback(async () => {
    setError(null)
    try {
      const r = await payroll.run(id)
      setRun(r)
      const [periods, e, s, adj] = await Promise.all([
        payroll.periods(),
        payroll.runEntries(id),
        payroll.runSummary(id),
        payroll.periodAdjustments(r.payroll_period_id).catch(() => [] as PayrollAdjustment[]),
      ])
      setPeriod(periods.find((p) => p.id === r.payroll_period_id) ?? null)
      setEntries(e)
      setSummary(s)
      setAdjustments(adj)
      return r.status
    } catch (err) {
      setError((err as Error).message)
      return null
    }
  }, [id])

  useEffect(() => {
    let active = true
    const tick = async () => {
      const status = await load()
      if (active && status === 'calculating') {
        timer.current = setTimeout(() => void tick(), 2500)
      }
    }
    void tick()
    return () => {
      active = false
      if (timer.current) clearTimeout(timer.current)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  async function act(fn: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try {
      await fn()
      await load()
    } catch (err) {
      setError((err as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function openLines(entry: PayrollEntry) {
    try {
      setOpenEntry(await payroll.entry(entry.id))
    } catch (err) {
      setError((err as Error).message)
    }
  }

  function startEdit(a: PayrollAdjustment) {
    setEditingId(a.id)
    setForm({
      employee_id: a.employee_id,
      employee_visible_label: a.employee_visible_label,
      direction: a.direction,
      amount_minor: String(a.amount_minor),
      currency: a.currency,
      internal_reason: a.internal_reason,
      source_payroll_entry_id: a.source_payroll_entry_id ?? '',
    })
  }

  function resetForm() {
    setEditingId(null)
    setForm({ ...emptyForm })
  }

  async function submitAdjustment(ev: React.FormEvent) {
    ev.preventDefault()
    const body: Record<string, unknown> = {
      employee_visible_label: form.employee_visible_label,
      direction: form.direction,
      amount_minor: Number(form.amount_minor),
      currency: (form.currency || defaultCurrency).toUpperCase(),
      internal_reason: form.internal_reason,
      source_payroll_entry_id: form.source_payroll_entry_id || null,
    }
    await act(async () => {
      if (editingId) {
        await payroll.updateAdjustment(editingId, body)
      } else if (period) {
        await payroll.createAdjustment(period.id, { ...body, employee_id: form.employee_id })
      }
      resetForm()
    })
  }

  async function finalize() {
    await act(() => payroll.finalizeRun(id, hasNegative ? finalizeReason : undefined))
    setShowFinalize(false)
    setFinalizeReason('')
  }

  const status = run?.status ?? ''
  const calculating = status === 'calculating'
  const finalized = status === 'finalized'
  const periodClosed = period?.status === 'closed'
  const canRecalculate = ['calculated', 'calculation_failed'].includes(status)
  const canStart = ['draft', 'calculation_failed'].includes(status)
  const adjustEditable = canAdjust && !periodClosed && ['draft', 'calculated', 'calculation_failed'].includes(status)
  const approvable = canApprove && status === 'calculated'
  const finalizable = canFinalize && ['calculated', 'approved'].includes(status)
  const hasNegative = entries.some((e) => e.negative_net)
  const defaultCurrency = summary?.by_currency[0]?.currency ?? entries.find((e) => e.currency)?.currency ?? 'USD'

  return (
    <div className="page">
      <p><Link to="/payroll/runs">← {t('nav.payroll_runs')}</Link></p>
      <h1>{t('payroll.run_detail')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      <div className="card">
        <p><strong>{t('payroll.run_period')}:</strong> {period?.label ?? run?.payroll_period_id}</p>
        <p><strong>{t('payroll.run_status')}:</strong> {status ? t(`payroll.run_status_${status}`) : '—'}</p>
        {run?.calculation_version && <p><strong>{t('payroll.calculation_version')}:</strong> {run.calculation_version}</p>}
        {calculating && <p role="status">{t('payroll.calculating_in_progress')}</p>}
        {finalized && <p className="notice" role="status">{t('payroll.finalized_readonly')}</p>}
        {canCalculate && !finalized && (
          <div className="row">
            {canStart && <button type="button" onClick={() => void act(() => payroll.calculateRun(id))} disabled={busy || calculating}>{t('payroll.calculate')}</button>}
            {canRecalculate && <button type="button" onClick={() => void act(() => payroll.recalculateRun(id))} disabled={busy || calculating}>{t('payroll.recalculate')}</button>}
          </div>
        )}
      </div>

      {(approvable || finalizable) && (
        <section className="card">
          <h3>{t('payroll.workflow')}</h3>
          <div className="row">
            {approvable && <button type="button" onClick={() => void act(() => payroll.approveRun(id))} disabled={busy}>{t('payroll.approve')}</button>}
            {finalizable && !showFinalize && <button type="button" onClick={() => setShowFinalize(true)} disabled={busy}>{t('payroll.finalize')}</button>}
          </div>
          {finalizable && showFinalize && (
            <div className="stack">
              <p role="alert">{t('payroll.finalize_confirm')}</p>
              {hasNegative && (
                <label>
                  {t('payroll.negative_net_override_prompt')}
                  <input value={finalizeReason} onChange={(e) => setFinalizeReason(e.target.value)} />
                </label>
              )}
              <div className="row">
                <button type="button" onClick={() => void finalize()} disabled={busy || (hasNegative && finalizeReason.trim() === '')}>{t('payroll.finalize_confirm_button')}</button>
                <button type="button" className="btn-ghost" onClick={() => { setShowFinalize(false); setFinalizeReason('') }}>{t('common.cancel')}</button>
              </div>
            </div>
          )}
        </section>
      )}

      {summary && (
        <section className="card">
          <h3>{t('payroll.summary')}</h3>
          <p>
            {t('payroll.cohort')}: {summary.counts.cohort} · {t('payroll.status_calculated')}: {summary.counts.calculated} · {t('payroll.status_failed')}: {summary.counts.failed} · {t('payroll.status_pending')}: {summary.counts.pending}
          </p>
          <table>
            <thead><tr><th>{t('payroll.currency')}</th><th>{t('payroll.gross')}</th><th>{t('payroll.deductions')}</th><th>{t('payroll.net')}</th><th>{t('payroll.employees')}</th></tr></thead>
            <tbody>
              {summary.by_currency.map((g) => (
                <tr key={g.currency}><td>{g.currency}</td><td>{g.gross_minor}</td><td>{g.deduction_minor}</td><td>{g.net_minor}</td><td>{g.employee_count}</td></tr>
              ))}
              {summary.by_currency.length === 0 && <tr><td colSpan={5}>{t('common.none')}</td></tr>}
            </tbody>
          </table>
        </section>
      )}

      <section className="card">
        <h3>{t('payroll.entries')}</h3>
        <table>
          <thead><tr><th>{t('payroll.employee')}</th><th>{t('payroll.entry_status')}</th><th>{t('payroll.currency')}</th><th>{t('payroll.gross')}</th><th>{t('payroll.deductions')}</th><th>{t('payroll.net')}</th><th>{t('payroll.notes')}</th><th /></tr></thead>
          <tbody>
            {entries.map((e) => (
              <tr key={e.id}>
                <td>{e.employee.employee_number} — {e.employee.name}</td>
                <td>{t(`payroll.entry_status_${e.status}`)}</td>
                <td>{e.currency ?? '—'}</td>
                <td>{e.gross_minor ?? '—'}</td>
                <td>{e.deduction_minor ?? '—'}</td>
                <td>{e.net_minor ?? '—'}</td>
                <td>
                  {e.error_code && <span className="error">{t(`payroll.error_${e.error_code}`, e.error_code)}</span>}
                  {e.negative_net && <span className="error">{t('payroll.warning_negative_net')}</span>}
                </td>
                <td>{['calculated', 'finalized'].includes(e.status) && <button type="button" className="btn-link" onClick={() => void openLines(e)}>{t('payroll.view_lines')}</button>}</td>
              </tr>
            ))}
            {entries.length === 0 && <tr><td colSpan={8}>{t('common.none')}</td></tr>}
          </tbody>
        </table>
      </section>

      <section className="card">
        <h3>{t('payroll.adjustments')}</h3>
        {adjustEditable && <p className="hint">{t('payroll.adjustments_recalc_hint')}</p>}
        <table>
          <thead><tr><th>{t('payroll.employee')}</th><th>{t('payroll.adjustment_label')}</th><th>{t('payroll.direction')}</th><th>{t('payroll.amount')}</th><th>{t('payroll.currency')}</th><th>{t('payroll.adjustment_reason')}</th><th /></tr></thead>
          <tbody>
            {adjustments.map((a) => {
              const emp = entries.find((e) => e.employee_id === a.employee_id)
              return (
                <tr key={a.id}>
                  <td>{emp ? `${emp.employee.employee_number} — ${emp.employee.name}` : a.employee_id}</td>
                  <td>{a.employee_visible_label}</td>
                  <td>{t(`payroll.direction_${a.direction}`)}</td>
                  <td>{a.amount_minor}</td>
                  <td>{a.currency}</td>
                  <td>{a.internal_reason}</td>
                  <td>{adjustEditable && (
                    <span className="row">
                      <button type="button" className="btn-link" onClick={() => startEdit(a)} disabled={busy}>{t('common.edit')}</button>
                      <button type="button" className="btn-link" onClick={() => void act(() => payroll.deleteAdjustment(a.id))} disabled={busy}>{t('payroll.adjustment_remove')}</button>
                    </span>
                  )}</td>
                </tr>
              )
            })}
            {adjustments.length === 0 && <tr><td colSpan={7}>{t('common.none')}</td></tr>}
          </tbody>
        </table>

        {adjustEditable && (
          <form className="stack" onSubmit={(e) => void submitAdjustment(e)}>
            <h4>{editingId ? t('payroll.adjustment_edit') : t('payroll.adjustment_add')}</h4>
            {!editingId && (
              <label>{t('payroll.employee')}
                <select value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })} required>
                  <option value="">—</option>
                  {entries.map((e) => <option key={e.employee_id} value={e.employee_id}>{e.employee.employee_number} — {e.employee.name}</option>)}
                </select>
              </label>
            )}
            <label>{t('payroll.adjustment_label')}
              <input value={form.employee_visible_label} onChange={(e) => setForm({ ...form, employee_visible_label: e.target.value })} required />
            </label>
            <label>{t('payroll.direction')}
              <select value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}>
                <option value="earning">{t('payroll.direction_earning')}</option>
                <option value="deduction">{t('payroll.direction_deduction')}</option>
              </select>
            </label>
            <label>{t('payroll.amount')} ({t('payroll.minor_units')})
              <input type="number" min={1} value={form.amount_minor} onChange={(e) => setForm({ ...form, amount_minor: e.target.value })} required />
            </label>
            <label>{t('payroll.currency')}
              <input value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} placeholder={defaultCurrency} maxLength={3} />
            </label>
            <label>{t('payroll.adjustment_reason')}
              <input value={form.internal_reason} onChange={(e) => setForm({ ...form, internal_reason: e.target.value })} required />
            </label>
            <label>{t('payroll.adjustment_source')}
              <input value={form.source_payroll_entry_id} onChange={(e) => setForm({ ...form, source_payroll_entry_id: e.target.value })} placeholder={t('payroll.adjustment_source_hint')} />
            </label>
            <div className="row">
              <button type="submit" disabled={busy}>{editingId ? t('common.save') : t('payroll.adjustment_add')}</button>
              {editingId && <button type="button" className="btn-ghost" onClick={resetForm}>{t('common.cancel')}</button>}
            </div>
          </form>
        )}
      </section>

      {openEntry && (
        <section className="card">
          <h3>{t('payroll.lines_for')} {openEntry.employee.name}</h3>
          <button type="button" className="btn-ghost" onClick={() => setOpenEntry(null)}>{t('common.cancel')}</button>
          <table>
            <thead><tr><th>{t('payroll.line')}</th><th>{t('payroll.direction')}</th><th>{t('payroll.quantity_minutes')}</th><th>{t('payroll.amount')}</th></tr></thead>
            <tbody>
              {(openEntry.lines ?? []).map((l) => (
                <tr key={l.id}>
                  <td>{l.label}</td>
                  <td>{t(`payroll.direction_${l.direction}`)}</td>
                  <td>{l.quantity_minutes ?? '—'}</td>
                  <td>{l.amount_minor}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
    </div>
  )
}
