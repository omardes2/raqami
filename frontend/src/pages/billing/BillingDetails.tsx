import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/AuthContext'
import Field from '../../components/Field'
import { billing, type BillingProfile } from '../../billing/api'

const EMPTY: BillingProfile = {
  legal_name: '', billing_email: '', billing_phone: '', country_code: '', city: '',
  address_line_1: '', address_line_2: '', postal_code: '', tax_id: '',
  preferred_currency: '', invoice_notes: '',
}

export default function BillingDetailsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const editable = can('billing.manage')
  const [profile, setProfile] = useState<BillingProfile | null>(null)
  const [saved, setSaved] = useState(false)

  // eslint-disable-next-line react/set-state-in-effect
  useEffect(() => { billing.profile().then((p) => setProfile(p ?? { ...EMPTY })) }, [])

  if (!profile) return <p>{t('common.loading')}</p>
  const set = (k: keyof BillingProfile) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setProfile({ ...profile, [k]: e.target.value })

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!profile) return
    await billing.saveProfile(profile)
    setSaved(true)
  }

  return (
    <div className="narrow">
      {saved && <p className="notice">{t('billing.details.saved')}</p>}
      <form onSubmit={submit}>
        <Field label={t('billing.details.legal_name')} value={profile.legal_name ?? ''} onChange={set('legal_name')} disabled={!editable} />
        <div className="grid-2">
          <Field label={t('billing.details.billing_email')} value={profile.billing_email ?? ''} onChange={set('billing_email')} disabled={!editable} />
          <Field label={t('billing.details.billing_phone')} value={profile.billing_phone ?? ''} onChange={set('billing_phone')} disabled={!editable} />
        </div>
        <div className="grid-2">
          <Field label={t('billing.details.tax_id')} value={profile.tax_id ?? ''} onChange={set('tax_id')} disabled={!editable} />
          <Field label={t('billing.details.preferred_currency')} value={profile.preferred_currency ?? ''} maxLength={3} onChange={set('preferred_currency')} disabled={!editable} />
        </div>
        <Field label={t('billing.details.address_line_1')} value={profile.address_line_1 ?? ''} onChange={set('address_line_1')} disabled={!editable} />
        <Field label={t('billing.details.address_line_2')} value={profile.address_line_2 ?? ''} onChange={set('address_line_2')} disabled={!editable} />
        <div className="grid-2">
          <Field label={t('billing.details.city')} value={profile.city ?? ''} onChange={set('city')} disabled={!editable} />
          <Field label={t('billing.details.postal_code')} value={profile.postal_code ?? ''} onChange={set('postal_code')} disabled={!editable} />
        </div>
        <Field label={t('billing.details.country')} value={profile.country_code ?? ''} maxLength={2} onChange={set('country_code')} disabled={!editable} />
        {editable && <button className="btn-primary" type="submit">{t('common.save')}</button>}
      </form>
    </div>
  )
}
