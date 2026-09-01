import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { attendance, type AttendanceLocation } from '../../attendance/api'

/** Geofence locations: list and create circular geofences (center + radius). */
export default function AttendanceLocations() {
  const { t } = useTranslation()
  const [locations, setLocations] = useState<AttendanceLocation[]>([])
  const [name, setName] = useState('')
  const [latitude, setLatitude] = useState('')
  const [longitude, setLongitude] = useState('')
  const [radius, setRadius] = useState('100')
  const [error, setError] = useState<string | null>(null)

  async function load() {
    setLocations(await attendance.locations())
  }

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { void load() }, [])

  async function create(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await attendance.createLocation({
        name,
        latitude: Number(latitude),
        longitude: Number(longitude),
        radius_meters: Number(radius),
      })
      setName('')
      setLatitude('')
      setLongitude('')
      setRadius('100')
      await load()
    } catch {
      setError(t('common.error'))
    }
  }

  return (
    <div>
      <h1>{t('attendance.locations.title')}</h1>

      <table className="data-table">
        <thead>
          <tr>
            <th>{t('attendance.locations.name')}</th>
            <th>{t('attendance.locations.latitude')}</th>
            <th>{t('attendance.locations.longitude')}</th>
            <th>{t('attendance.locations.radius')}</th>
            <th>{t('attendance.fields.status')}</th>
          </tr>
        </thead>
        <tbody>
          {locations.length === 0 && (
            <tr><td colSpan={5} className="muted">{t('attendance.locations.empty')}</td></tr>
          )}
          {locations.map((l) => (
            <tr key={l.id}>
              <td>{l.name}</td>
              <td className="mono">{l.latitude}</td>
              <td className="mono">{l.longitude}</td>
              <td>{l.radius_meters} m</td>
              <td>{l.status}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <form onSubmit={create} className="form-narrow">
        <h3>{t('attendance.locations.create')}</h3>
        <label>
          {t('attendance.locations.name')}
          <input value={name} onChange={(e) => setName(e.target.value)} required />
        </label>
        <label>
          {t('attendance.locations.latitude')}
          <input type="number" step="any" value={latitude} onChange={(e) => setLatitude(e.target.value)} required />
        </label>
        <label>
          {t('attendance.locations.longitude')}
          <input type="number" step="any" value={longitude} onChange={(e) => setLongitude(e.target.value)} required />
        </label>
        <label>
          {t('attendance.locations.radius')}
          <input type="number" min={10} value={radius} onChange={(e) => setRadius(e.target.value)} required />
        </label>
        {error && <p className="error">{error}</p>}
        <button type="submit" className="btn-primary">{t('common.create')}</button>
      </form>
    </div>
  )
}
