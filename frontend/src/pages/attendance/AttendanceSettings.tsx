import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type AttendanceSettings } from '../../attendance/api'

/** The single per-tenant attendance policy. */
export default function AttendanceSettingsPage() {
  const { t } = useTranslation()
  const [form, setForm] = useState<AttendanceSettings | null>(null)
  const [saved, setSaved] = useState(false)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { attendance.settings().then(setForm) }, [])

  if (!form) return <p>{t('common.loading')}</p>

  function set<K extends keyof AttendanceSettings>(key: K, value: AttendanceSettings[K]) {
    setForm((prev) => (prev ? { ...prev, [key]: value } : prev))
    setSaved(false)
  }

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (!form) return
    const updated = await attendance.updateSettings(form)
    setForm(updated)
    setSaved(true)
  }

  const toggle = (key: keyof AttendanceSettings, label: string) => (
    <label className="checkbox">
      <input type="checkbox" checked={form[key] as boolean} onChange={(e) => set(key, e.target.checked as never)} />
      {label}
    </label>
  )

  return (
    <form onSubmit={save} className="form-narrow">
      <h1>{t('attendance.settings.title')}</h1>

      <label>
        {t('attendance.settings.default_timezone')}
        <input value={form.default_timezone} onChange={(e) => set('default_timezone', e.target.value)} />
      </label>
      <label>
        {t('attendance.settings.default_grace')}
        <input type="number" min={0} value={form.default_grace_minutes}
          onChange={(e) => set('default_grace_minutes', Number(e.target.value) as never)} />
      </label>

      {toggle('geofence_required', t('attendance.settings.geofence_required'))}
      {toggle('require_gps', t('attendance.settings.require_gps'))}
      {toggle('allow_early_check_in', t('attendance.settings.allow_early'))}
      <label>
        {t('attendance.settings.early_window')}
        <input type="number" min={0} value={form.early_check_in_window_minutes}
          onChange={(e) => set('early_check_in_window_minutes', Number(e.target.value) as never)} />
      </label>
      {toggle('allow_late_check_in', t('attendance.settings.allow_late'))}
      {toggle('overtime_tracking_enabled', t('attendance.settings.overtime_tracking'))}
      {toggle('attendance_correction_enabled', t('attendance.settings.corrections_enabled'))}
      {toggle('allow_employee_correction_request', t('attendance.settings.employee_corrections'))}
      {toggle('allow_unscheduled_work', t('attendance.settings.unscheduled_work'))}

      <h2>{t('attendance.settings.advanced')}</h2>
      {toggle('materialization_enabled', t('attendance.settings.materialization'))}
      {toggle('allow_multiple_sessions', t('attendance.settings.multiple_sessions'))}
      {toggle('overtime_requires_approval', t('attendance.settings.overtime_requires_approval'))}
      {toggle('overtime_auto_approve', t('attendance.settings.overtime_auto_approve'))}
      <label>
        {t('attendance.settings.off_day_policy')}
        <select value={form.off_day_work_policy ?? 'reject'}
          onChange={(e) => set('off_day_work_policy', e.target.value as never)}>
          <option value="reject">{t('attendance.settings.off_day_reject')}</option>
          <option value="allow">{t('attendance.settings.off_day_allow')}</option>
          <option value="require_approval">{t('attendance.settings.off_day_require_approval')}</option>
        </select>
      </label>
      <label>
        {t('attendance.settings.default_mode')}
        <select value={form.default_attendance_mode ?? 'onsite'}
          onChange={(e) => set('default_attendance_mode', e.target.value as never)}>
          <option value="onsite">{t('attendance.mode.onsite')}</option>
          <option value="remote">{t('attendance.mode.remote')}</option>
          <option value="field">{t('attendance.mode.field')}</option>
        </select>
      </label>

      <div className="form-actions">
        <button type="submit" className="btn-primary">{t('common.save')}</button>
        {saved && <span className="muted">{t('attendance.settings.saved')}</span>}
      </div>
    </form>
  )
}
