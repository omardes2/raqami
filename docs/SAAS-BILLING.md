# SaaS Billing — Plans, Subscriptions & Payments — Raqmi Dawam

**Status:** Design (planning phase). Covers subscription plans, the subscription
lifecycle, and the three payment methods: Visa/Mastercard (card gateway), bank
transfer, and manual/cash.

---

## 1. Goals

- Monetize the platform with flexible, configurable plans.
- Support card, bank transfer, and manual/cash payments.
- Keep billing correct, auditable, and tenant-isolated.
- Handle trials, upgrades/downgrades, proration, dunning, and cancellation.

## 2. Plans

A **plan** defines pricing and entitlements:
- `code`, `name`, `description`
- `price_amount` + `currency`, `billing_cycle` (`monthly | yearly`)
- `employee_limit` (and/or other usage limits)
- `feature_flags` (which modules/features are enabled)
- `is_active`

Plans gate features and limits (e.g., max employees, AI features, advanced
reports). Feature flags are enforced across modules.

## 3. Subscription Lifecycle

```
trialing ─► active ─► past_due ─► suspended ─► cancelled
   │           │           │
   │           └─ upgrade/downgrade (proration)
   └─ trial expiry ─► requires payment
```

- **Trial:** optional, time-boxed; full or limited features.
- **Active:** paid and in good standing.
- **Past due:** payment failed/overdue; **dunning** begins.
- **Suspended:** access restricted after dunning grace; data retained.
- **Cancelled:** subscription ended; data retention per policy.
- **Upgrades/downgrades:** effective immediately or next cycle, with
  **proration**.

Tenant `status` mirrors subscription state to gate access.

## 3a. Payment Gateway Abstraction (ADR-010)

Billing logic is **provider-agnostic**. It talks to a **Payment Gateway
abstraction** (a driver/adapter interface), never directly to Stripe,
Cybersource, or any single provider. The abstraction supports:

- **Card payment gateway** (Visa/Mastercard) — pluggable online providers.
- **Bank transfer** — recorded/reconciled.
- **Cash / manual payment** — recorded with proof.
- **Future regional payment gateways** — added as new drivers without touching
  billing logic.

**Actual online gateway integration is deferred to a later sprint.** The
foundation implements the abstraction plus the manual and bank-transfer flows;
online card providers plug in later behind the same interface. Which provider(s)
and regions/currencies are an open decision (`DECISIONS.md`) and **do not block**
the foundation because the abstraction isolates that choice.

## 4. Payment Methods

### 4.1 Visa / Mastercard (card gateway)
- Handled by a PCI-compliant **payment gateway** behind the Payment Gateway
  abstraction; the platform stores only tokens/references, **never raw card
  data**.
- Supports initial charge, recurring charges, retries, and refunds.
- Online integration is deferred; gateway selection is an open decision (see
  `DECISIONS.md`) — candidates should support the target regions and currencies.

### 4.2 Bank Transfer
- Tenant receives bank details and an invoice reference.
- Finance/admin **records** the received transfer with proof (upload).
- Reconciliation marks the invoice paid; subscription reactivates.

### 4.3 Manual / Cash
- Recorded by an authorized user (Super Admin or Company Admin per policy) with
  proof and notes.
- Useful for offline sales, resellers, or special arrangements.
- Fully **audited**; requires `payment.record` / `platform.payment.record`.

## 5. Invoicing

- Invoices generated per billing cycle and on plan changes (with proration).
- States: `draft → open → paid → void | uncollectible`.
- Receipts issued on payment.
- Bilingual invoice documents (Arabic/English), stored in object storage.

## 6. Dunning & Access Control

- On failed card charge: retry schedule + notifications.
- Grace period before **suspension**.
- Suspended tenants retain data but have restricted access until resolved.

## 7. Data Touchpoints

See `DATABASE.md` (central scope): `plans`, `subscriptions`, `invoices`,
`payments`. Card data is tokenized via the gateway; only references are stored.

## 8. Permissions

Tenant: `billing.view`, `billing.manage`, `subscription.change`,
`payment.record`, `payment.view`.
Platform: `platform.plan.manage`, `platform.billing.view`,
`platform.billing.manage`, `platform.payment.record`.

## 9. Security & Audit

- **No raw card data** stored; PCI handled by the gateway (`SECURITY.md`).
- All billing/payment actions (charges, manual records, refunds, plan changes)
  are **audited** with actor and reference.
- Webhooks from the gateway are verified (signatures) and idempotent.
- Amounts stored as integer minor units + currency.

## 10. Testing (when implemented)

Cover: plan-limit enforcement, proration math, dunning transitions, manual/bank
payment recording + reconciliation, webhook verification/idempotency, and tenant
isolation of billing data.

## 11. Decision Status & Open Questions

- **Decided (ADR-010):** provider-agnostic **Payment Gateway abstraction**
  (card/bank/manual + future regional); online gateway integration deferred to a
  later sprint.
- **Open (does not block the abstraction):** card gateway provider(s) and
  supported regions/currencies; tax/VAT handling on invoices per region;
  reseller/partner billing model (if any). (See `DECISIONS.md`.)

---

## 12. Sprint 2 — Implemented (SaaS Billing & Subscriptions)

Delivered on `feature/sprint-2-saas-billing` (not merged). Manual + bank-transfer
flows are complete; **no real card provider is integrated** (ADR-010 abstraction
preserved).

- **Plans & entitlements (platform-global):** `plans` (status/visibility,
  monthly/annual prices in minor units, trial days, employee limit) +
  `plan_features` (feature_key/enabled/limit_value). `EntitlementService`
  answers feature/limit/usage questions; **employee limit** is enforced on
  employee creation (countable = not terminated/archived; no plan = unlimited).
- **Subscriptions:** one primary subscription per tenant; lifecycle via
  `SubscriptionManager` with a `SubscriptionStatus` transition map (trialing →
  active → past_due → grace_period → suspended → canceled/expired), free trial,
  cancel-at-period-end + resume, immediate **upgrade** / scheduled **downgrade**
  (never deletes data), and an append-only `subscription_events` timeline.
- **Invoices & payments:** server-side totals (subtotal − discount + tax),
  `INV-YYYY-######` numbering, line items, **partial payments**, overpayment
  rejection, transactional application, and subscription activation/renewal on
  full payment. Printable HTML invoice (PDF foundation).
- **Bank transfer:** tenant submits proof (private disk) → platform admin
  approves/rejects; approval creates the payment + applies it, row-locked
  against double approval. **Manual/cash:** platform-admin-only recording.
- **Coupons:** percentage / fixed-amount, validity dates, global +
  per-tenant redemption limits, plan restriction.
- **Multi-currency & tax foundation:** ISO currency per invoice/payment (no FX);
  generic tax rate/label (no country tax logic). **Billing profile** +
  platform **bank accounts** configuration.
- **Idempotency & webhooks:** `IdempotencyService` + `idempotency_records`;
  `WebhookIngestionService` + `payment_webhook_events` seam (no provider, no
  public endpoint).
- **Permissions:** `billing.{view,manage}`, `billing.subscription.{view,change}`,
  `billing.invoices.view`, `billing.payments.view`, `billing.bank_transfer.submit`
  (owner=all, admin=full, accountant=read+submit; HR/managers/employee none).
  Platform billing is a separate guard, not tenant RBAC.
- **Out of scope (unchanged):** Stripe/Cybersource/PayPal/any card provider, AI,
  attendance/leave/payroll/tasks, country tax engines, FX, account credits,
  automatic reconciliation.

## 13. Sprint 2 — commercial hardening (final)

- **Entitlements are fail-closed** and bootstrapped by a platform **default trial
  plan** (`is_default_trial`); no usable subscription ⇒ no product entitlement.
- **Upgrades are payment-gated** (pending change + invoice → applied on full
  payment); **downgrades** scheduled at period end (no data loss);
  **reactivation** of a terminal subscription is an explicit paid purchase (no new
  trial).
- **Invoice numbers are globally unique** via a platform sequence
  (`invoice_number_sequences`); the per-tenant counter was removed.
- **Employee limit** is enforced under a per-tenant advisory lock (no race).
- **Currency exponents** (`CurrencyMetadata`) render minor units correctly
  (JOD = 3 decimals); arithmetic stays in integer minor units.
- **Lifecycle** transitions (trial/grace expiry, scheduled cancel/downgrade) are
  processed by the idempotent `billing:process-lifecycle` command (no cron yet).
