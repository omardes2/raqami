import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type HolidayCalendar } from '../../attendance/api'

/** Holiday calendars: create calendars, add holidays, assign to company/branch. */
export default function AttendanceHolidays() {
  const { t } = useTranslation()
  const [calendars, setCalendars] = useState<HolidayCalendar[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [code, setCode] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setCalendars(await attendance.holidayCalendars())
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  function errorText(e: unknown): string {
    const err = e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
    const errors = err.response?.data?.errors
    const first = errors ? Object.values(errors)[0]?.[0] : undefined
    return first ?? err.response?.data?.message ?? t('common.error')
  }

  async function createCalendar(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await attendance.createHolidayCalendar({ name, code })
      setName('')
      setCode('')
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  async function addHoliday(calendarId: string) {
    const holidayName = window.prompt(t('attendance.holidays.holiday_name') ?? '')
    if (!holidayName) return
    const date = window.prompt(t('attendance.holidays.holiday_date') ?? '')
    if (!date) return
    setError(null)
    try {
      await attendance.addHoliday(calendarId, { name: holidayName, date })
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  async function assignCompany(calendarId: string) {
    const from = window.prompt(t('attendance.holidays.effective_from') ?? '')
    if (!from) return
    setError(null)
    try {
      await attendance.assignHolidayCalendar(calendarId, { scope_type: 'company', effective_from: from })
      await load()
    } catch (err) {
      setError(errorText(err))
    }
  }

  return (
    <div>
      <h1>{t('attendance.holidays.title')}</h1>

      <form className="inline-form" onSubmit={createCalendar}>
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('attendance.holidays.calendar_name') ?? ''} required />
        <input value={code} onChange={(e) => setCode(e.target.value)} placeholder={t('attendance.holidays.calendar_code') ?? ''} required />
        <button type="submit" className="btn-primary">{t('attendance.holidays.create_calendar')}</button>
      </form>

      {error && <p className="error">{error}</p>}

      {loading ? (
        <p>{t('common.loading')}</p>
      ) : calendars.length === 0 ? (
        <p className="muted">{t('attendance.holidays.empty')}</p>
      ) : (
        calendars.map((cal) => (
          <section key={cal.id} className="card">
            <div className="card-head">
              <h2>{cal.name} <span className="mono muted">({cal.code})</span></h2>
              <div className="row-actions">
                <button type="button" className="btn-link" onClick={() => addHoliday(cal.id)}>{t('attendance.holidays.add_holiday')}</button>
                <button type="button" className="btn-link" onClick={() => assignCompany(cal.id)}>{t('attendance.holidays.assign_company')}</button>
              </div>
            </div>

            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('attendance.holidays.holiday_name')}</th>
                  <th>{t('attendance.holidays.holiday_date')}</th>
                  <th>{t('attendance.holidays.end_date')}</th>
                  <th>{t('attendance.holidays.type')}</th>
                </tr>
              </thead>
              <tbody>
                {(cal.holidays ?? []).length === 0 && (
                  <tr><td colSpan={4} className="muted">{t('attendance.holidays.no_holidays')}</td></tr>
                )}
                {(cal.holidays ?? []).map((h) => (
                  <tr key={h.id}>
                    <td>{h.name}</td>
                    <td>{h.date}</td>
                    <td>{h.end_date ?? '—'}</td>
                    <td>{h.type}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {(cal.assignments ?? []).length > 0 && (
              <p className="muted">
                {t('attendance.holidays.assigned_to')}: {(cal.assignments ?? []).map((a) => a.scope_type).join(', ')}
              </p>
            )}
          </section>
        ))
      )}
    </div>
  )
}
