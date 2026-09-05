# Mobile API — Raqmi Dawam (رقمي دوام)

> **Status:** V1. The mobile surface is a thin authentication layer over the
> existing versioned application API (ADR-004). There is **no separate mobile
> business logic** — a mobile client consumes the same endpoints the SPA does,
> with the same tenancy, RLS, and permission guarantees. Only the identity
> carrier differs: a **Bearer token** instead of the SPA session cookie.

---

## 1. Authentication model

The web SPA authenticates with a first-party Sanctum **session cookie**. Mobile
apps cannot rely on cookies, so they authenticate once to obtain a stateless
**Sanctum personal access token** and send it as a Bearer token on every
subsequent request.

Both carriers resolve to the **same `auth:sanctum` guard**, so every downstream
guarantee (tenant isolation, PostgreSQL row-level security, permission checks,
organizational scope) is identical regardless of how the caller authenticated.

### Required headers (authenticated requests)

| Header | Value | Purpose |
|---|---|---|
| `Authorization` | `Bearer <token>` | Identifies the user (Sanctum PAT). |
| `X-Tenant-Id` | the company's tenant id | Selects the active company. Only a company the user is an **active member** of is accepted; anything else resolves **no** tenant (never a silent fallback). |
| `Accept` | `application/json` | Forces JSON responses / validation errors. |
| `Accept-Language` | `ar` or `en` | Optional; drives localized messages. |

A user who belongs to several companies switches company by changing
`X-Tenant-Id` — no re-login required.

---

## 2. Auth endpoints (`/api/mobile/v1/auth`)

### `POST /api/mobile/v1/auth/login`  *(guest, throttled 10/min + per email|ip 5-attempt lockout)*

Request:

```json
{ "email": "user@example.com", "password": "•••••••", "device_name": "Pixel 8" }
```

Response `201`:

```json
{
  "token": "12|abcdef…",
  "token_type": "Bearer",
  "user": { "id": "01…", "name": "…", "email": "…", "locale": "en", "timezone": "UTC" },
  "memberships": [
    { "tenant_id": "01…", "name": "Acme Co", "slug": "acme-co", "default_locale": "en", "status": "active" }
  ]
}
```

- `device_name` is **required** and names the token so a user can identify or
  revoke a specific device. Re-logging in with the **same** `device_name`
  supersedes (deletes) the previous token for that device, so a reinstalled or
  lost device does not accumulate live credentials.
- The token carries the `mobile` ability.
- Login never selects a tenant (the user may belong to several); it returns the
  user's active memberships so the client can pick one for `X-Tenant-Id`.
- Invalid credentials → `422` with an `email` validation error. Repeated
  failures are rate-limited identically to the SPA login.

### `GET /api/mobile/v1/auth/session`  *(Bearer; resolves `X-Tenant-Id`)*

Returns the authenticated user for the selected company, including
**backend-authoritative** `permissions` and `roles` (union across scopes, for
hiding UI controls only — never for client-side authorization) plus the user's
`memberships` for a company switcher. `active_tenant` is `null` when no valid
tenant is selected.

### `POST /api/mobile/v1/auth/logout`  *(Bearer)*

Revokes **only** the token that authenticated this request (single-device
logout). Other devices stay signed in.

---

## 3. Consuming the application API from mobile

Every authenticated application endpoint accepts the Bearer token. The mobile
app is expected to use the **self-service** subset below; management endpoints
remain available subject to the caller's permissions and scope.

| Area | Endpoint | Notes |
|---|---|---|
| Profile | `GET /api/me`, `PATCH /api/me` | View/update own name, locale, timezone. |
| Attendance | `POST /api/attendance/check-in`, `POST /api/attendance/check-out` | GPS punch; geofence + schedule enforced server-side. |
| Attendance | `GET /api/attendance/me`, `GET /api/attendance/me/today` | Own records / today's status. |
| Attendance | `POST /api/attendance/me/records/{record}/corrections` | Request a correction on an own record. |
| Leave | `GET /api/leave/me/balances`, `GET /api/leave/me/requests` | Own ledger balances and requests. |
| Leave | `POST /api/leave/requests` | Submit a leave request (permission `leave.request`). |
| Tasks | `GET /api/tasks/me` | Tasks assigned to / visible to the caller. |
| Notifications | `GET /api/me/notifications`, `GET /api/me/notifications/unread-count` | Personal inbox (recipient-scoped). |
| Notifications | `PATCH /api/me/notifications/{id}/read`, `POST /api/me/notifications/read-all` | Mark read. |
| Payslips | `GET /api/me/payslips`, `GET /api/me/payslips/{entry}` | Own **finalized** payslips (permission `payroll.view_own`). |

> The server decides every result; clients send only facts (coordinates,
> timestamps, ids). Sensitive data (salary, national id, bank details) stays
> permission-gated and is never returned by default.

---

## 4. Security notes for mobile clients

- **Store the token in the platform secure store** (iOS Keychain / Android
  Keystore), never in plain preferences.
- **On sign-out or account switch, call logout** so the server-side token is
  revoked, then discard the local copy.
- **Never cache permissions for authorization decisions** — they are UI hints
  only. The API re-checks every action.
- Tokens do not expire by policy in V1; revoke them via logout or (future)
  a device-management screen. Rotating the signing app key or deleting the
  `personal_access_tokens` row invalidates a token immediately.
- All the standard STOP-condition protections apply through the shared API: a
  token can never read or write another tenant's data, never see another user's
  notifications, and never bypass RLS.

---

## 5. Versioning

The surface is namespaced `/(api/)mobile/v1`. Breaking changes ship under a new
version prefix; `v1` auth responses remain stable for the life of `v1`.
