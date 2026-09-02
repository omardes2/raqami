import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  days,
  leave,
  minutes,
  type LeaveBalance,
  type LeavePreview,
  type LeaveRequest,
  type LeaveType,
} from '../../leave/api'

/**
 * Employee leave self-service: balances, a request form with a NON-authoritative
 * server preview (the server recalculates + reserves on submit), request history,
 * and withdraw / cancellation-request actions. Canonical unit is minutes; the UI
 * renders days/hours for readability only.
 */
export default function MyLeave() {
  const { t } = useTranslation()
  const [balances, setBalances] = useState<LeaveBalance[]>([])
  const [types, setTypes] = useState<LeaveType[]>([])
  const [requests, setRequests] = useState<LeaveRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const perDay = 480

  const [form, setForm] = useState({ leave_type_id: '', request_kind: 'full_day', starts_on: '', ends_on: '', reason: '' })
  const [preview, setPreview] = useState<LeavePreview | null>(null)

  const load = useCallback(async () => {
    const [b, ty, rq] = await Promise.all([leave.myBalances(), leave.types({ status: 'active' }), leave.myRequests({ per_page: 50 })])
    setBalances(b)
    setTypes(ty)
    setRequests(rq)
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function typeName(id: string): string {
    return types.find((x) => x.id === id)?.name ?? id
  }

  async function doPreview() {
    setError(null)
    setPreview(null)
    try {
      setPreview(await leave.preview(form))
    } catch (e) {
      setError((e as Error).message)
    }
  }

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      await leave.submit(form)
      setPreview(null)
      setForm({ leave_type_id: '', request_kind: 'full_day', starts_on: '', ends_on: '', reason: '' })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function withdraw(id: string) {
    setBusy(true)
    try { await leave.withdraw(id); await load() } catch (e) { setError((e as Error).message) } finally { setBusy(false) }
  }

  async function requestCancellation(id: string) {
    setBusy(true)
    try { await leave.requestCancellation(id); await load() } catch (e) { setError((e as Error).message) } finally { setBusy(false) }
  }

  if (loading) return <p>{t('common.loading')}</p>

  return (
    <div className="page">
      <h1>{t('leave.my_leave')}</h1>
      {error && <p className="error" role="alert">{error}</p>}

      <section>
        <h2>{t('leave.balances')}</h2>
        <table>
          <thead>
            <tr>
              <th>{t('leave.type')}</th>
              <th>{t('leave.available')}</th>
              <th>{t('leave.reserved')}</th>
              <th>{t('leave.used')}</th>
              <th>{t('leave.carried')}</th>
            </tr>
          </thead>
          <tbody>
            {balances.length === 0 && <tr><td colSpan={5}>{t('leave.no_balances')}</td></tr>}
            {balances.map((b) => (
              <tr key={b.id}>
                <td>{typeName(b.leave_type_id)}</td>
                <td>{days(b.available_minutes, perDay)} ({minutes(b.available_minutes)})</td>
                <td>{minutes(b.reserved_minutes)}</td>
                <td>{minutes(b.used_minutes)}</td>
                <td>{minutes(b.carried_minutes)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section>
        <h2>{t('leave.request_leave')}</h2>
        <div className="form-grid">
          <label>
            {t('leave.type')}
            <select value={form.leave_type_id} onChange={(e) => setForm({ ...form, leave_type_id: e.target.value })}>
              <option value="">—</option>
              {types.map((ty) => <option key={ty.id} value={ty.id}>{ty.name}</option>)}
            </select>
          </label>
          <label>
            {t('leave.portion')}
            <select value={form.request_kind} onChange={(e) => setForm({ ...form, request_kind: e.target.value })}>
              <option value="full_day">{t('leave.full_day')}</option>
              <option value="first_half">{t('leave.first_half')}</option>
              <option value="second_half">{t('leave.second_half')}</option>
            </select>
          </label>
          <label>
            {t('leave.starts_on')}
            <input type="date" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} />
          </label>
          <label>
            {t('leave.ends_on')}
            <input type="date" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} />
          </label>
          <label>
            {t('leave.reason')}
            <input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
          </label>
        </div>
        <div className="actions">
          <button type="button" onClick={() => void doPreview()} disabled={!form.leave_type_id || !form.starts_on || !form.ends_on}>
            {t('leave.preview')}
          </button>
          <button type="button" onClick={() => void submit()} disabled={busy || !form.leave_type_id || !form.starts_on || !form.ends_on}>
            {t('leave.submit')}
          </button>
        </div>
        {preview && (
          <div className="preview">
            <p>{t('leave.preview_note')}</p>
            <ul>
              <li>{t('leave.requested')}: {days(preview.total_consumption_minutes, perDay)} ({minutes(preview.total_consumption_minutes)})</li>
              <li>{t('leave.balance_before')}: {minutes(preview.available_before)}</li>
              <li>{t('leave.balance_after')}: {minutes(preview.available_after)}</li>
            </ul>
          </div>
        )}
      </section>

      <section>
        <h2>{t('leave.history')}</h2>
        <table>
          <thead>
            <tr>
              <th>{t('leave.type')}</th>
              <th>{t('leave.dates')}</th>
              <th>{t('leave.portion')}</th>
              <th>{t('leave.requested')}</th>
              <th>{t('leave.status')}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {requests.length === 0 && <tr><td colSpan={6}>{t('leave.no_requests')}</td></tr>}
            {requests.map((r) => (
              <tr key={r.id}>
                <td>{typeName(r.leave_type_id)}</td>
                <td>{r.starts_on} → {r.ends_on}</td>
                <td>{t(`leave.${r.request_kind}`)}</td>
                <td>{minutes(r.requested_consumption_minutes)}</td>
                <td>{t(`leave.status_${r.status}`)}</td>
                <td>
                  {r.status === 'pending' && <button type="button" onClick={() => void withdraw(r.id)} disabled={busy}>{t('leave.withdraw')}</button>}
                  {r.status === 'approved' && <button type="button" onClick={() => void requestCancellation(r.id)} disabled={busy}>{t('leave.request_cancellation')}</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  )
}
