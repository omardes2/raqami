import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, type LeaveSettings as Settings } from '../../leave/api'

/** Company leave settings (defaults; display-day length is display-only). */
export default function LeaveSettings() {
  const { t } = useTranslation()
  const [settings, setSettings] = useState<Settings | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)

  const load = useCallback(async () => {
    setSettings(await leave.settings())
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function save() {
    if (!settings) return
    setBusy(true)
    setError(null)
    setSaved(false)
    try {
      setSettings(await leave.updateSettings(settings))
      setSaved(true)
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  if (loading || !settings) return <p>{t('common.loading')}</p>

  return (
    <div className="page">
      <h1>{t('leave.settings')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      {saved && <p className="ok">{t('common.saved')}</p>}
      <div className="form-grid">
        <label>{t('leave.default_period_basis')}
          <select value={settings.default_period_basis} onChange={(e) => setSettings({ ...settings, default_period_basis: e.target.value })}>
            {['calendar_year', 'employment_anniversary', 'custom_tenant_year'].map((b) => <option key={b} value={b}>{b}</option>)}
          </select>
        </label>
        <label>{t('leave.default_approval_flow')}
          <select value={settings.default_approval_flow} onChange={(e) => setSettings({ ...settings, default_approval_flow: e.target.value })}>
            {['none', 'manager', 'hr', 'manager_then_hr'].map((b) => <option key={b} value={b}>{b}</option>)}
          </select>
        </label>
        <label>{t('leave.year_start_month')}<input type="number" min={1} max={12} value={settings.leave_year_start_month} onChange={(e) => setSettings({ ...settings, leave_year_start_month: Number(e.target.value) })} /></label>
        <label>{t('leave.year_start_day')}<input type="number" min={1} max={31} value={settings.leave_year_start_day} onChange={(e) => setSettings({ ...settings, leave_year_start_day: Number(e.target.value) })} /></label>
        <label>{t('leave.display_day_minutes')}<input type="number" value={settings.display_day_minutes} onChange={(e) => setSettings({ ...settings, display_day_minutes: Number(e.target.value) })} /></label>
        <label><input type="checkbox" checked={settings.allow_withdrawal} onChange={(e) => setSettings({ ...settings, allow_withdrawal: e.target.checked })} /> {t('leave.allow_withdrawal')}</label>
        <label><input type="checkbox" checked={settings.allow_cancellation_request} onChange={(e) => setSettings({ ...settings, allow_cancellation_request: e.target.checked })} /> {t('leave.allow_cancellation')}</label>
      </div>
      <button type="button" onClick={() => void save()} disabled={busy}>{t('common.save')}</button>
    </div>
  )
}
