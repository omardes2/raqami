import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { leave, type LeaveType } from '../../leave/api'

const CATEGORIES = ['annual', 'sick', 'unpaid', 'emergency', 'parental', 'compensatory', 'other']

/** Admin CRUD for leave types (company scope). */
export default function LeaveTypes() {
  const { t } = useTranslation()
  const [types, setTypes] = useState<LeaveType[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ code: '', name: '', category: 'annual', allow_half_day: true, requires_attachment: false })

  const load = useCallback(async () => {
    setTypes(await leave.types())
    setLoading(false)
  }, [])

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [load])

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await leave.createType(form)
      setForm({ code: '', name: '', category: 'annual', allow_half_day: true, requires_attachment: false })
      await load()
    } catch (e) {
      setError((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  async function archive(id: string) {
    setBusy(true)
    try { await leave.archiveType(id); await load() } catch (e) { setError((e as Error).message) } finally { setBusy(false) }
  }

  if (loading) return <p>{t('common.loading')}</p>

  return (
    <div className="page">
      <h1>{t('leave.types')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      <section>
        <h2>{t('leave.new_type')}</h2>
        <div className="form-grid">
          <label>{t('leave.code')}<input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></label>
          <label>{t('leave.name')}<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
          <label>{t('leave.category')}
            <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
              {CATEGORIES.map((c) => <option key={c} value={c}>{t(`leave.category_${c}`)}</option>)}
            </select>
          </label>
          <label><input type="checkbox" checked={form.allow_half_day} onChange={(e) => setForm({ ...form, allow_half_day: e.target.checked })} /> {t('leave.allow_half_day')}</label>
          <label><input type="checkbox" checked={form.requires_attachment} onChange={(e) => setForm({ ...form, requires_attachment: e.target.checked })} /> {t('leave.requires_attachment')}</label>
        </div>
        <button type="button" onClick={() => void create()} disabled={busy || !form.code || !form.name}>{t('common.create')}</button>
      </section>
      <table>
        <thead>
          <tr><th>{t('leave.code')}</th><th>{t('leave.name')}</th><th>{t('leave.category')}</th><th>{t('leave.status')}</th><th></th></tr>
        </thead>
        <tbody>
          {types.map((ty) => (
            <tr key={ty.id}>
              <td>{ty.code}</td>
              <td>{ty.name}</td>
              <td>{t(`leave.category_${ty.category}`)}</td>
              <td>{ty.status}</td>
              <td>{ty.status === 'active' && <button type="button" onClick={() => void archive(ty.id)} disabled={busy}>{t('common.archive')}</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
