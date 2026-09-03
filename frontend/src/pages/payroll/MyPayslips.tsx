import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { money } from '../../billing/api'
import { payroll, type OwnPayslip } from '../../payroll/api'

/**
 * Employee self-service: list of the current employee's finalized payslips (newest
 * period first). Read-only; the server returns only finalized history for the
 * authenticated, employee-linked user in the current tenant.
 */
export default function MyPayslips() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<OwnPayslip[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    payroll
      .myPayslips()
      .then((r) => active && setRows(r))
      .catch((e) => active && setError((e as Error).message))
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="page">
      <h1>{t('payroll.my_payslips')}</h1>
      {error && <p className="error" role="alert">{error}</p>}
      {rows === null && !error && <p role="status">{t('common.loading')}</p>}

      {rows !== null && rows.length === 0 && <p className="muted">{t('payroll.no_payslips')}</p>}

      {rows !== null && rows.length > 0 && (
        <table>
          <thead>
            <tr>
              <th>{t('payroll.pay_period')}</th>
              <th>{t('payroll.currency')}</th>
              <th>{t('payroll.net_pay')}</th>
              <th>{t('payroll.finalized_on')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((p) => (
              <tr key={p.id}>
                <td>{p.period_label ?? p.period_start ?? '—'}</td>
                <td>{p.currency}</td>
                <td>{money(p.net_minor, p.currency)}</td>
                <td>{p.finalized_at ? new Date(p.finalized_at).toLocaleDateString() : '—'}</td>
                <td><Link className="btn-link" to={`/me/payslips/${p.id}`}>{t('payroll.view_payslip')}</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
