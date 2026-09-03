import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { money } from '../../billing/api'
import { payroll, type OwnPayslipDetail, type OwnPayslipLine } from '../../payroll/api'

/**
 * Employee self-service: one finalized payslip in detail — company/employee/period
 * header, grouped earnings and deductions, and canonical gross/deductions/net totals
 * (never recomputed here; the server returns the finalized entry's values). Read-only.
 * The "Print" button uses the browser's own print dialog (window.print) — no PDF is
 * generated or stored. Chrome/nav/actions are hidden by the @media print rules in
 * styles.css so only the .payslip-print region is printed.
 */
export default function MyPayslipDetail() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [payslip, setPayslip] = useState<OwnPayslipDetail | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    payroll
      .myPayslip(id)
      .then((p) => active && setPayslip(p))
      .catch((e) => active && setError((e as Error).message))
    return () => {
      active = false
    }
  }, [id])

  function lineRows(lines: OwnPayslipLine[], emptyLabel: string) {
    if (lines.length === 0) {
      return (
        <tr><td colSpan={2} className="muted">{emptyLabel}</td></tr>
      )
    }
    return lines.map((l, i) => (
      <tr key={`${l.line_type}-${i}`}>
        <td>{l.label}</td>
        <td className="amount-cell">{payslip ? money(l.amount_minor, payslip.currency) : l.amount_minor}</td>
      </tr>
    ))
  }

  return (
    <div className="page">
      <p className="no-print"><Link to="/me/payslips">← {t('payroll.back_to_payslips')}</Link></p>
      {error && <p className="error no-print" role="alert">{error}</p>}
      {!payslip && !error && <p className="no-print" role="status">{t('common.loading')}</p>}

      {payslip && (
        <>
          <div className="row-actions no-print">
            <button type="button" onClick={() => window.print()}>{t('payroll.print')}</button>
          </div>

          <article className="card payslip-print">
            <header className="payslip-head">
              <div>
                <h1>{t('payroll.payslip')}</h1>
                <p className="muted">{payslip.company?.name ?? '—'}</p>
              </div>
              <div className="payslip-period">
                <p><strong>{t('payroll.pay_period')}:</strong> {payslip.period?.label ?? '—'}</p>
                {payslip.period?.start && payslip.period?.end && (
                  <p className="muted">{payslip.period.start} — {payslip.period.end}</p>
                )}
                {payslip.finalized_at && (
                  <p className="muted">{t('payroll.finalized_on')}: {new Date(payslip.finalized_at).toLocaleDateString()}</p>
                )}
              </div>
            </header>

            <section className="detail-grid payslip-employee">
              <div className="detail-row"><span className="detail-label">{t('payroll.employee')}</span><span>{payslip.employee?.name ?? '—'}</span></div>
              <div className="detail-row"><span className="detail-label">{t('payroll.employee_number')}</span><span>{payslip.employee?.employee_number ?? '—'}</span></div>
              <div className="detail-row"><span className="detail-label">{t('payroll.job_title')}</span><span>{payslip.employee?.job_title ?? '—'}</span></div>
              <div className="detail-row"><span className="detail-label">{t('payroll.currency')}</span><span>{payslip.currency}</span></div>
            </section>

            <section>
              <h3>{t('payroll.earnings')}</h3>
              <table>
                <tbody>{lineRows(payslip.earnings, t('common.none'))}</tbody>
                <tfoot>
                  <tr><th>{t('payroll.gross_earnings')}</th><th className="amount-cell">{money(payslip.gross_minor, payslip.currency)}</th></tr>
                </tfoot>
              </table>
            </section>

            <section>
              <h3>{t('payroll.deductions')}</h3>
              <table>
                <tbody>{lineRows(payslip.deductions, t('common.none'))}</tbody>
                <tfoot>
                  <tr><th>{t('payroll.total_deductions')}</th><th className="amount-cell">{money(payslip.deduction_minor, payslip.currency)}</th></tr>
                </tfoot>
              </table>
            </section>

            <section className="payslip-net">
              <span>{t('payroll.net_pay')}</span>
              <strong>{money(payslip.net_minor, payslip.currency)}</strong>
            </section>
          </article>
        </>
      )}
    </div>
  )
}
