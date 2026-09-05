# Deployment & Operations Runbook — Raqmi Dawam (رقمي دوام)

Production runbook for V1. It assumes the modular-monolith backend (Laravel 13,
PHP 8.3+, PostgreSQL with Row-Level Security) and the React/TypeScript SPA.
Everything here uses **real command names** that exist in this repository.

> **Security first.** The whole product depends on tenant isolation. The two
> settings that make or break it are the **PostgreSQL role** (must be
> `NOBYPASSRLS`, non-superuser) and `DB_ENABLE_RLS=true`. Never deploy with a
> superuser DB role — RLS is silently ignored for superusers/BYPASSRLS roles.

---

## 1. Architecture at a glance

| Component | Technology | Notes |
|---|---|---|
| API backend | Laravel 13 (PHP 8.3+) | Modular monolith under `app/Modules/*`. |
| Database | PostgreSQL | FORCE RLS per tenant; app role is `NOBYPASSRLS`. |
| Cache / sessions / queue | Redis | `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` all `redis`. |
| Object storage | S3-compatible | Private disk for employee documents/attachments. |
| Frontend | React + TypeScript (Vite) | Static build served by CDN / web server. |
| Auth | Sanctum | SPA cookie (web) + personal access tokens (mobile). |

---

## 2. Provisioning the database (once)

1. Create the database and an **application role that is NOT a superuser and has
   `NOBYPASSRLS`**:

   ```sql
   CREATE ROLE raqmi LOGIN PASSWORD '••••' NOBYPASSRLS;
   CREATE DATABASE raqmi_dawam OWNER raqmi;
   ```

2. Point `DB_*` in the environment at this role. Confirm the role cannot bypass
   RLS:

   ```sql
   SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'raqmi';
   -- rolsuper = f, rolbypassrls = f  (both MUST be false)
   ```

3. Keep `DB_ENABLE_RLS=true`. The RLS validation gate in CI (and the migrations
   that `ALTER TABLE … FORCE ROW LEVEL SECURITY`) assume this.

---

## 3. Environment configuration

Copy `.env.example` to `.env` and set real values. **Never commit `.env`.**
Secrets (`APP_KEY`, `DB_PASSWORD`, `AI_ANTHROPIC_API_KEY`, `AWS_*`,
`PLATFORM_ADMIN_PASSWORD`) come from the platform secret manager, not the repo.

Production must-sets:

| Variable | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | generated once via `php artisan key:generate` |
| `APP_URL` / `FRONTEND_URL` | real HTTPS URLs |
| `DB_ENABLE_RLS` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | the SPA domain(s) only |
| `CORS_ALLOWED_ORIGINS` | the SPA origin(s) only |
| `SESSION_DOMAIN` | the shared parent domain |
| `FILESYSTEM_DISK` | `s3` (private bucket) |
| `MAIL_MAILER` | a real transactional mailer |
| `AI_PROVIDER_DRIVER` | `null` (default, AI off) or `anthropic` (+ key) |

`APP_DEBUG=true` in production leaks stack traces and config — treat it as a
release blocker.

---

## 4. Release steps (each deploy)

Run from `backend/` on the release artifact:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force            # forward-only; see §8 for rollback
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Build and publish the SPA from `frontend/`:

```bash
npm ci
npm run build                          # emits the static bundle to deploy behind CDN
```

Zero-downtime ordering: put the app in maintenance only if a migration is not
backward-compatible.

```bash
php artisan down --render="errors::503" # optional, only for breaking migrations
# … deploy …
php artisan up
```

After deploy, verify (§7).

---

## 5. Long-running processes

### Queue workers (required)

Notifications, payslip fan-out, and other deferred work run on the Redis queue.
Run workers under a supervisor (systemd / Supervisor / Horizon-style):

```bash
php artisan queue:work redis --queue=default --tries=3 --max-time=3600 --sleep=1
```

- Run at least one worker per app node; scale by traffic.
- **Restart workers on every deploy** (`php artisan queue:restart`) so they load
  new code.
- Workers preserve tenant isolation: each job re-establishes `TenantContext`
  before touching data (ADR tenancy rules). Never disable that.

### Scheduler (required)

A **single** system cron entry drives all scheduled maintenance:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` fires the tasks registered in `routes/console.php`:

| Command | Cadence (UTC) | Purpose |
|---|---|---|
| `attendance:process-daily` | daily 01:00 | Materialize weekend/holiday/absent/incomplete state. |
| `leave:process-accruals` | daily 01:15 | Apply leave grants/accruals (idempotent). |
| `leave:process-periods` | daily 01:30 | Carry-forward / expire ended entitlement periods. |
| `billing:process-lifecycle` | daily 02:00 | Trial/grace expiry, scheduled cancel/downgrade. |
| `notifications:reconcile-payslips` | daily 02:30 | Re-deliver missed payslip notifications. |
| `notifications:remind-tasks` | daily 06:00 | Due-soon / overdue task reminders. |
| `notifications:prune` | weekly Sun 03:00 | Hard-delete notifications past retention. |
| `queue:prune-failed` / `queue:prune-batches` | weekly Sun 03:30/03:45 | Queue table hygiene. |

Every command is **idempotent and tenant-aware**, so a missed or repeated run
self-heals on the next run. `onOneServer` keeps each task single-fired across
nodes (requires the shared Redis cache). Confirm registration any time with:

```bash
php artisan schedule:list
```

Multi-timezone note: `attendance:process-daily` accepts `--date=YYYY-MM-DD`;
deployments spanning many timezones may run it more than once a day.

---

## 6. Storage & file security

- Employee documents and leave/attendance attachments live on a **private**
  disk (`FILESYSTEM_DISK=s3`, non-public bucket). They are served only through
  permission-gated download routes that stream the file — never via a public
  URL. Do not make the bucket public.
- `php artisan storage:link` is **not** needed for private documents; it only
  exposes `storage/app/public`, which must contain no tenant data.

---

## 7. Post-deploy verification (smoke)

1. **Health**: `GET /up` returns `200` (Laravel health endpoint; wire it to the
   load balancer's health probe).
2. **Migrations**: `php artisan migrate:status` shows nothing pending.
3. **RLS is live** (spot check with the app role):
   ```sql
   SELECT relname, relforcerowsecurity FROM pg_class
   WHERE relrowsecurity AND relnamespace = 'public'::regnamespace LIMIT 5;
   ```
   Tenant tables must show `relforcerowsecurity = t`.
4. **Queue**: enqueue nothing manually — instead confirm a worker is `Processing`
   and `php artisan queue:failed` is empty.
5. **Schedule**: `php artisan schedule:list` shows the table in §5.
6. **AI gating**: with `AI_PROVIDER_DRIVER=null`, `GET /api/ai/availability`
   reports unavailable — confirm no key leaks and the SPA hides the panel.
7. **Auth**: SPA login works; `POST /api/mobile/v1/auth/login` issues a token.

---

## 8. Migrations & rollback

- Migrations are applied with `--force` in production and are **forward-only** by
  policy. Destructive changes (dropping columns/tables, irreversible transforms)
  require owner sign-off and a tested rollback path (CLAUDE.md rule 2).
- Append-only ledgers (payroll finalized history, audit log, AI usage) have **no
  UPDATE/DELETE** RLS policy and DB triggers that reject mutation — they are
  immutable by design. Never "fix" data by editing these directly.
- Roll back a bad release by redeploying the previous artifact; only run a
  `down` migration that has been tested to preserve data.

---

## 9. Backup & recovery

- **Database**: automated daily `pg_dump` (or managed snapshots) with
  point-in-time recovery (WAL archiving) where available. Test a restore into a
  staging database quarterly.
- **Object storage**: enable bucket versioning + lifecycle retention so a
  deleted document is recoverable within the retention window.
- **Secrets**: back up the secret manager entries (APP_KEY especially — losing
  it makes encrypted values and existing tokens unrecoverable).
- **Retention**: `DATA_RETENTION_DEFAULT_DAYS` and the notification retention
  horizon govern automated pruning; align them with the customer contract before
  enabling pruning in a new environment.

---

## 10. Observability

- Ship application logs (`LOG_CHANNEL=stack`) to a central aggregator; set
  `LOG_LEVEL=info` or `warning` in production.
- Alert on: `/up` failing, queue depth / failed-jobs growth, scheduler not
  running (no `schedule:run` heartbeat), PostgreSQL connection saturation, and
  5xx rate.
- The **audit log** records authentication, permission changes, payroll runs,
  billing events, exports, and destructive actions with actor/tenant/target — it
  is the security record, not a debug log; protect and retain it accordingly.
