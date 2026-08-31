import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api, ensureCsrf } from '../lib/api'
import { useAuth } from '../auth/AuthContext'

interface Member {
  membership_id: string; status: string; invited_email: string | null
  user: { id: string; name: string; email: string; roles: string[] } | null
}

export default function Users() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [members, setMembers] = useState<Member[]>([])
  const [inviteEmail, setInviteEmail] = useState('')

  const load = () => api.get('/users').then(({ data }) => setMembers(data.data))
  useEffect(() => { load() }, [])

  async function invite(e: React.FormEvent) {
    e.preventDefault()
    await ensureCsrf()
    await api.post('/users/invitations', { email: inviteEmail })
    setInviteEmail('')
    await load()
  }

  async function setStatus(id: string, action: 'activate' | 'deactivate') {
    await ensureCsrf()
    await api.post(`/memberships/${id}/${action}`)
    await load()
  }

  return (
    <div>
      <h1>{t('users.title')}</h1>
      {can('user.invite') && (
        <form className="inline-form" onSubmit={invite}>
          <input type="email" placeholder={t('users.invite_email')} value={inviteEmail}
            onChange={(e) => setInviteEmail(e.target.value)} required />
          <button className="btn-primary" type="submit">{t('users.send_invite')}</button>
        </form>
      )}
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('users.member')}</th><th>{t('users.status')}</th>
            <th>{t('users.roles')}</th><th></th>
          </tr>
        </thead>
        <tbody>
          {members.map((m) => (
            <tr key={m.membership_id}>
              <td>{m.user ? `${m.user.name} (${m.user.email})` : m.invited_email}</td>
              <td><span className={`pill pill-${m.status}`}>{m.status}</span></td>
              <td>{m.user?.roles.join(', ') || '—'}</td>
              <td>
                {can('user.manage') && (
                  m.status === 'disabled'
                    ? <button className="btn-link" onClick={() => setStatus(m.membership_id, 'activate')}>{t('users.activate')}</button>
                    : <button className="btn-link" onClick={() => setStatus(m.membership_id, 'deactivate')}>{t('users.deactivate')}</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
