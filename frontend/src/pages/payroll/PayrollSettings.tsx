import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { payroll, type PayrollSettings as Settings } from '../../payroll/api'

/** Company payroll settings (payroll.settings.manage). */
export default function PayrollSettings() {
  const { t } = useTranslation()
  const [settings, setSettings] = useState<Settings | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setSettings(await payroll.settings())
    } catch (e) {
      setError((e as Error).message)
    }
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function save(patch: Partial<Settings>) {
    if (!settings) return
    setBusy(true)
    setError(null)
    try {
      setSettings(await payroll.updateSettings(patch))
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  if (!settings) {
    return <div className="page"><h1>{t('nav.payroll_settings')}</h1>{error && <p className="error" role="alert">{error}</p>}</div>
  }

  return (
    <div className="page">
      <h1>{t('nav.payroll_settings')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="card">
        <label>
          {t('payroll.settings_timezone')}
          <input
            value={settings.payroll_timezone}
            onChange={(e) => setSettings({ ...settings, payroll_timezone: e.target.value })}
            onBlur={(e) => void save({ payroll_timezone: e.target.value })}
          />
        </label>
        <label>
          <input type="checkbox" checked={settings.overtime_pay_enabled} disabled={busy} onChange={(e) => void save({ overtime_pay_enabled: e.target.checked })} />
          {t('payroll.settings_overtime_pay_enabled')}
        </label>
        <label>
          <input type="checkbox" checked={settings.require_four_eyes} disabled={busy} onChange={(e) => void save({ require_four_eyes: e.target.checked })} />
          {t('payroll.settings_require_four_eyes')}
        </label>
        <label>
          <input type="checkbox" checked={settings.allow_self_payroll} disabled={busy} onChange={(e) => void save({ allow_self_payroll: e.target.checked })} />
          {t('payroll.settings_allow_self_payroll')}
        </label>
      </div>
    </div>
  )
}
