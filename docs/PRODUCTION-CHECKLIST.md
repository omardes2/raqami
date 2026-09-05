# Production Readiness & Security Checklist — Raqmi Dawam V1

Go/no-go checklist for a production deployment. Every box is a gate; an unchecked
security box is a **release blocker**. Pairs with `docs/DEPLOYMENT.md` (how) and
`docs/SECURITY.md` (why).

## Tenant isolation (non-negotiable)
- [ ] DB application role is **non-superuser** and **`NOBYPASSRLS`**
      (`SELECT rolsuper, rolbypassrls …` both false).
- [ ] `DB_ENABLE_RLS=true`; tenant tables show `relforcerowsecurity = t`.
- [ ] CI RLS-validation gate is green on the deployed commit.
- [ ] Cross-tenant test suite passes (no query, job, report, or AI path reads
      another tenant's data).
- [ ] Queue workers re-establish `TenantContext` per job (no shared ambient
      tenant across jobs).
- [ ] Super Admin portal access is limited, audited, and its platform-read-only
      mode cannot write across tenants.

## Authentication & sessions
- [ ] `APP_KEY` set (generated once) and backed up in the secret manager.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` list only real SPA
      origins (no wildcards).
- [ ] `SESSION_DOMAIN` scoped to the app domain; cookies `Secure` + `HttpOnly`
      over HTTPS.
- [ ] Mobile tokens: login is rate-limited; logout revokes the device token;
      tokens carry the `mobile` ability only.
- [ ] Login brute-force lockout (per email|ip) verified on both SPA and mobile.

## Authorization & sensitive data
- [ ] Role-ceiling guard active: a non-owner cannot grant the owner role or a
      role exceeding their own permissions (Sprint 10).
- [ ] Payroll/salary, national IDs, bank details, and attachments are
      permission-gated and never returned by default.
- [ ] Scoped managers see only their branch/department/team (attendance, leave,
      tasks, employees) — no IDOR across scopes.
- [ ] Payroll **finalized** history is immutable (append-only + DB triggers);
      four-eyes approve≠finalize enforced; no cross-currency summing.

## Secrets & configuration
- [ ] No secrets in VCS; `.env` git-ignored; only `.env.example` committed.
- [ ] CI secret scan green.
- [ ] `AI_PROVIDER_DRIVER=null` unless AI is intentionally enabled with a
      server-side key (never exposed to the frontend); per-tenant daily cap set.
- [ ] `FILESYSTEM_DISK=s3` private bucket; documents served only via gated
      download routes; no tenant data under the public disk.

## Operations
- [ ] `php artisan migrate --force` applied; `migrate:status` clean.
- [ ] `config:cache`, `route:cache`, `event:cache` run on the release.
- [ ] Queue worker(s) supervised; restarted on deploy; failed-jobs alerting on.
- [ ] **Single** `schedule:run` cron entry installed; `schedule:list` matches
      the runbook table.
- [ ] `/up` health endpoint wired to the load balancer probe.
- [ ] Database backups + PITR configured and a restore tested in staging.
- [ ] Object storage versioning/retention enabled.
- [ ] Logs centralized; `LOG_LEVEL` = info/warning; audit log retained and
      protected.

## Bilingual (product contract)
- [ ] Arabic (RTL) and English (LTR) both correct across every user-facing
      surface; no hard-coded UI strings (all via i18n keys).

## Sign-off
- [ ] Owner has reviewed and approved this environment for production.
