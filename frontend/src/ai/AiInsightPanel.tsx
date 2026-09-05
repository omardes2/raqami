import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ai, type AiFeature } from './api'

/**
 * Sprint 9 — a lightweight, opt-in AI insights panel. Rendered only for users
 * with ai.use. It requests a read-only summary on demand; when AI is disabled,
 * not entitled, or rate-limited it shows a localized notice and the rest of the
 * page is unaffected. Content is plain text (no HTML injection).
 */
export default function AiInsightPanel({ feature = 'dashboard_summary' as AiFeature }: { feature?: AiFeature }) {
  const { t } = useTranslation()
  const [loading, setLoading] = useState(false)
  const [summary, setSummary] = useState<string | null>(null)
  const [highlights, setHighlights] = useState<string[]>([])
  const [notice, setNotice] = useState<string | null>(null)

  async function run() {
    setLoading(true)
    setNotice(null)
    setSummary(null)
    setHighlights([])
    try {
      const res = await ai.insight(feature)
      if (res.available && res.summary) {
        setSummary(res.summary)
        setHighlights(res.highlights)
      } else {
        setNotice(t(`ai.reason.${res.unavailable_reason ?? 'unavailable'}`, { defaultValue: t('ai.reason.unavailable') }))
      }
    } catch {
      setNotice(t('ai.error'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="card ai-panel">
      <div className="ai-panel-head">
        <strong>{t('ai.title')}</strong>
        <button type="button" className="btn-ghost" onClick={run} disabled={loading}>
          {loading ? t('ai.generating') : t('ai.summarize')}
        </button>
      </div>
      {notice && <p className="muted" role="status">{notice}</p>}
      {summary && <p className="ai-summary">{summary}</p>}
      {highlights.length > 0 && (
        <ul className="ai-highlights">
          {highlights.map((h, i) => (
            <li key={i}>{h}</li>
          ))}
        </ul>
      )}
      {!summary && !notice && !loading && <p className="muted">{t('ai.hint')}</p>}
    </section>
  )
}
