import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../../lib/api'
import { fetchOptions } from '../../org/api'
import type { OrgRow, Option } from '../../org/types'
import { useAuth } from '../../auth/AuthContext'

type FieldType = 'text' | 'textarea' | 'checkbox' | 'select'

interface FieldDef {
  name: string
  labelKey: string
  type?: FieldType
  optionsFrom?: string // endpoint to load select options from
  optionsLabel?: string
  required?: boolean
}

export interface OrgEntityConfig {
  endpoint: string // e.g. 'branches'
  titleKey: string // nav.* key
  labelField: 'name' | 'title'
  createPerm: string
  updatePerm: string
  archivePerm: string
  countField?: 'employees_count' | 'members_count'
  fields: FieldDef[]
}

export default function OrgEntityPage({ config }: { config: OrgEntityConfig }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [rows, setRows] = useState<OrgRow[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<OrgRow | null>(null)
  const [creating, setCreating] = useState(false)
  const [optionSets, setOptionSets] = useState<Record<string, Option[]>>({})

  const load = useCallback(async () => {
    setLoading(true)
    const { data } = await api.get(`/${config.endpoint}`, { params: { search, per_page: 50 } })
    setRows(data.data ?? [])
    setLoading(false)
  }, [config.endpoint, search])

  useEffect(() => {
    // eslint-disable-next-line react/set-state-in-effect -- intentional data fetch on dependency change
    load()
  }, [load])

  // Load select option lists referenced by fields.
  useEffect(() => {
    const selects = config.fields.filter((f) => f.type === 'select' && f.optionsFrom)
    Promise.all(
      selects.map(async (f) => [f.name, await fetchOptions(f.optionsFrom!, f.optionsLabel ?? 'name')] as const),
    ).then((entries) => setOptionSets(Object.fromEntries(entries)))
  }, [config.fields])

  async function archive(row: OrgRow) {
    if (!confirm(t('org.confirm_archive'))) return
    await ensureCsrf()
    try {
      await api.post(`/${config.endpoint}/${row.id}/archive`)
      await load()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      alert(msg ?? t('common.error'))
    }
  }

  const label = (r: OrgRow) => (config.labelField === 'title' ? r.title : r.name) ?? ''

  return (
    <div>
      <div className="page-head">
        <h1>{t(config.titleKey)}</h1>
        {can(config.createPerm) && (
          <button className="btn-primary inline" onClick={() => setCreating(true)}>
            {t('org.new')}
          </button>
        )}
      </div>

      <div className="inline-form">
        <input
          type="search"
          placeholder={t('org.search')}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {loading ? (
        <p>{t('common.loading')}</p>
      ) : rows.length === 0 ? (
        <p className="muted">{t('org.empty')}</p>
      ) : (
        <table className="data-table">
          <thead>
            <tr>
              <th>{config.labelField === 'title' ? t('org.title') : t('org.name')}</th>
              <th>{t('org.code')}</th>
              {config.countField && <th>{config.countField === 'members_count' ? t('org.members_count') : t('org.employees_count')}</th>}
              <th>{t('org.status')}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <td>{label(r)}</td>
                <td><code className="perm">{r.code}</code></td>
                {config.countField && <td>{(r[config.countField] as number) ?? 0}</td>}
                <td><span className={`pill pill-${r.status === 'active' ? 'active' : 'disabled'}`}>{t(`org.${r.status === 'active' ? 'active' : 'archived'}`)}</span></td>
                <td className="row-actions">
                  {can(config.updatePerm) && r.status === 'active' && (
                    <button className="btn-link" onClick={() => setEditing(r)}>{t('org.edit')}</button>
                  )}
                  {can(config.archivePerm) && r.status === 'active' && (
                    <button className="btn-link danger" onClick={() => archive(r)}>{t('org.archive')}</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {(creating || editing) && (
        <OrgEntityForm
          config={config}
          record={editing}
          options={optionSets}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={async () => {
            setCreating(false)
            setEditing(null)
            await load()
          }}
        />
      )}
    </div>
  )
}

function OrgEntityForm({
  config,
  record,
  options,
  onClose,
  onSaved,
}: {
  config: OrgEntityConfig
  record: OrgRow | null
  options: Record<string, Option[]>
  onClose: () => void
  onSaved: () => void
}) {
  const { t } = useTranslation()
  const [form, setForm] = useState<Record<string, unknown>>(() => {
    const initial: Record<string, unknown> = {}
    for (const f of config.fields) initial[f.name] = (record?.[f.name as keyof OrgRow] as unknown) ?? (f.type === 'checkbox' ? false : '')
    return initial
  })
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      await ensureCsrf()
      if (record) await api.patch(`/${config.endpoint}/${record.id}`, form)
      else await api.post(`/${config.endpoint}`, form)
      onSaved()
    } catch (e: unknown) {
      const resp = (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
      setErrors(resp?.data?.errors ?? {})
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <form className="modal" onClick={(e) => e.stopPropagation()} onSubmit={submit}>
        <h2>{record ? t('org.edit') : t('org.new')}</h2>
        {config.fields.map((f) => (
          <div className="field" key={f.name}>
            <label htmlFor={f.name}>{t(f.labelKey)}</label>
            {f.type === 'checkbox' ? (
              <input
                id={f.name}
                type="checkbox"
                checked={Boolean(form[f.name])}
                onChange={(e) => setForm({ ...form, [f.name]: e.target.checked })}
              />
            ) : f.type === 'textarea' ? (
              <textarea id={f.name} value={String(form[f.name] ?? '')} onChange={(e) => setForm({ ...form, [f.name]: e.target.value })} />
            ) : f.type === 'select' ? (
              <select id={f.name} value={String(form[f.name] ?? '')} onChange={(e) => setForm({ ...form, [f.name]: e.target.value || null })}>
                <option value="">{t('org.none')}</option>
                {(options[f.name] ?? []).filter((o) => o.value !== record?.id).map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
            ) : (
              <input id={f.name} value={String(form[f.name] ?? '')} onChange={(e) => setForm({ ...form, [f.name]: e.target.value })} />
            )}
            {errors[f.name] && <p className="field-error">{errors[f.name][0]}</p>}
          </div>
        ))}
        <div className="modal-actions">
          <button type="button" className="btn-ghost" onClick={onClose}>{t('org.cancel')}</button>
          <button type="submit" className="btn-primary inline" disabled={busy}>{t('org.save')}</button>
        </div>
      </form>
    </div>
  )
}
