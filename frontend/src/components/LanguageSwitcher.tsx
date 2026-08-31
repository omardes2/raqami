import { useTranslation } from 'react-i18next'
import { applyLocale, SUPPORTED_LOCALES } from '../i18n'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'

const NAMES: Record<string, string> = { en: 'English', ar: 'العربية' }

export default function LanguageSwitcher() {
  const { i18n } = useTranslation()
  const { user, refresh } = useAuth()

  async function choose(locale: string) {
    applyLocale(locale)
    // Persist the preference for signed-in users.
    if (user) {
      try {
        await api.patch('/me', { locale })
        await refresh()
      } catch {
        /* non-fatal */
      }
    }
  }

  return (
    <div className="lang-switch" role="group" aria-label="Language">
      {SUPPORTED_LOCALES.map((l) => (
        <button
          key={l}
          type="button"
          className={i18n.language === l ? 'active' : ''}
          onClick={() => choose(l)}
        >
          {NAMES[l]}
        </button>
      ))}
    </div>
  )
}
