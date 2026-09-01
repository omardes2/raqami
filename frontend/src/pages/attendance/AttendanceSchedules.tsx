import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type WorkSchedule } from '../../attendance/api'

const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6]

interface DayForm {
  is_working_day: boolean
  start_time: string
  end_time: string
}

function blankDays(): DayForm[] {
  // Default Mon–Fri working 09:00–17:00 (weekday 0 = Sunday).
  return WEEKDAYS.map((w) => ({
    is_working_day: w >= 1 && w <= 5,
    start_time: '09:00',
    end_time: '17:00',
  }))
}

/** Work schedules: list existing and create a new one with per-weekday hours. */
export default function AttendanceSchedules() {
  const { t } = useTranslation()
  const [schedules, setSchedules] = useState<WorkSchedule[]>([])
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [timezone, setTimezone] = useState('UTC')
  const [days, setDays] = useState<DayForm[]>(blankDays())
  const [error, setError] = useState<string | null>(null)

  async function load() {
    setSchedules(await attendance.schedules())
  }

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [])

  function setDay(index: number, patch: Partial<DayForm>) {
    setDays((prev) => prev.map((d, i) => (i === index ? { ...d, ...patch } : d)))
  }

  async function create(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await attendance.createSchedule({
        name,
        code,
        timezone,
        days: days.map((d, weekday) => ({
          weekday,
          is_working_day: d.is_working_day,
          start_time: d.is_working_day ? d.start_time : null,
          end_time: d.is_working_day ? d.end_time : null,
        })),
      })
      setName('')
      setCode('')
      setDays(blankDays())
      await load()
    } catch {
      setError(t('common.error'))
    }
  }

  return (
    <div>
      <h1>{t('attendance.schedules.title')}</h1>

      <table className="data-table">
        <thead>
          <tr>
            <th>{t('attendance.schedules.name')}</th>
            <th>{t('attendance.schedules.code')}</th>
            <th>{t('attendance.schedules.timezone')}</th>
            <th>{t('attendance.schedules.assignments')}</th>
          </tr>
        </thead>
        <tbody>
          {schedules.length === 0 && (
            <tr><td colSpan={4} className="muted">{t('attendance.schedules.empty')}</td></tr>
          )}
          {schedules.map((s) => (
            <tr key={s.id}>
              <td>{s.name}</td>
              <td className="mono">{s.code}</td>
              <td>{s.timezone}</td>
              <td>{s.assignments?.length ?? 0}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <form onSubmit={create} className="form-narrow">
        <h3>{t('attendance.schedules.create')}</h3>
        <label>
          {t('attendance.schedules.name')}
          <input value={name} onChange={(e) => setName(e.target.value)} required />
        </label>
        <label>
          {t('attendance.schedules.code')}
          <input value={code} onChange={(e) => setCode(e.target.value)} required />
        </label>
        <label>
          {t('attendance.schedules.timezone')}
          <input value={timezone} onChange={(e) => setTimezone(e.target.value)} required />
        </label>

        <fieldset>
          <legend>{t('attendance.schedules.days')}</legend>
          {days.map((d, weekday) => (
            <div key={weekday} className="day-row">
              <label className="checkbox">
                <input type="checkbox" checked={d.is_working_day}
                  onChange={(e) => setDay(weekday, { is_working_day: e.target.checked })} />
                {t(`attendance.weekday.${weekday}`)}
              </label>
              {d.is_working_day && (
                <>
                  <input type="time" value={d.start_time} onChange={(e) => setDay(weekday, { start_time: e.target.value })} />
                  <input type="time" value={d.end_time} onChange={(e) => setDay(weekday, { end_time: e.target.value })} />
                </>
              )}
            </div>
          ))}
        </fieldset>

        {error && <p className="error">{error}</p>}
        <button type="submit" className="btn-primary">{t('common.create')}</button>
      </form>
    </div>
  )
}
