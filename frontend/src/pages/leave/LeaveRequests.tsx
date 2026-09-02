import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, minutes, type LeaveRequest } from '../../leave/api'
import { useAuth } from '../../auth/AuthContext'

/** Management review of employees' leave requests (organizational scope). */
export default function LeaveRequests() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [requests, setRequests] = useState<LeaveRequest[]>([])
  const [status, setStatus] = useState('pending')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setRequests(await leave.requests({ status, per_page: 100 }))
    setLoading(false)
  }, [status])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function act(fn: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try { await fn(); await load() } catch (e) { setError((e as Error).message) } finally { setBusy(false) }
  }

  if (loading) return <p>{t('common.loading')}</p>

  return (
    <div className="page">
      <h1>{t('leave.requests')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <label>
        {t('leave.status')}
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          {['pending', 'approved', 'rejected', 'withdrawn', 'cancellation_pending', 'cancelled'].map((s) => (
            <option key={s} value={s}>{t(`leave.status_${s}`)}</option>
          ))}
        </select>
      </label>
      <table>
        <thead>
          <tr>
            <th>{t('leave.employee')}</th>
            <th>{t('leave.dates')}</th>
            <th>{t('leave.requested')}</th>
            <th>{t('leave.status')}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {requests.length === 0 && <tr><td colSpan={5}>{t('leave.no_requests')}</td></tr>}
          {requests.map((r) => (
            <tr key={r.id}>
              <td>{r.employee_id}</td>
              <td>{r.starts_on} → {r.ends_on}</td>
              <td>{minutes(r.requested_consumption_minutes)}</td>
              <td>{t(`leave.status_${r.status}`)}</td>
              <td>
                {r.status === 'pending' && can('leave.approve') && (
                  <>
                    <button type="button" onClick={() => void act(() => leave.approve(r.id))} disabled={busy}>{t('leave.approve')}</button>
                    <button type="button" onClick={() => void act(() => leave.reject(r.id))} disabled={busy}>{t('leave.reject')}</button>
                  </>
                )}
                {r.status === 'cancellation_pending' && can('leave.approve') && (
                  <button type="button" onClick={() => void act(() => leave.approveCancellation(r.id))} disabled={busy}>{t('leave.approve_cancellation')}</button>
                )}
                {r.status === 'approved' && can('leave.manage') && (
                  <button type="button" onClick={() => { const reason = window.prompt(t('leave.cancel_reason_prompt')) ?? ''; if (reason) void act(() => leave.cancel(r.id, reason)) }} disabled={busy}>
                    {t('leave.cancel')}
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
