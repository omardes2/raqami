# AI Features — Raqmi Dawam

**Status:** Design (planning phase). Covers the AI assistant, AI reports &
insights, AI task generation, and AI workload analysis — and the guardrails that
keep them tenant-isolated, permission-aware, and safe.

---

## 1. Goals

- Reduce manual work and surface insight across attendance, tasks, and
  workforce data.
- Provide a conversational assistant scoped to the tenant and the user's
  permissions.
- Keep AI features **secure by design**: never leak cross-tenant data, never
  bypass permissions, never expose secrets.

## 2. Capabilities

### 2.1 AI Assistant
A conversational assistant that answers questions and helps with actions within
the platform. It:
- Operates strictly within the **current tenant**.
- Only accesses data the **requesting user is permitted** to see
  (`PERMISSIONS.md`).
- Can summarize, explain, and draft; write-actions require the same permission
  checks and confirmations as manual actions.

### 2.2 AI Reports & Insights
- Generate narrative reports (attendance trends, absence/lateness patterns,
  overtime, workforce summaries).
- Produce insights/anomaly hints (e.g., unusual overtime, attendance drops).
- Runs **asynchronously** as jobs; results are permission-gated.

### 2.3 AI Task Generation
- From goals, notes, or descriptions, propose structured tasks (title,
  priority, suggested assignee, due date).
- Proposals are **suggestions**; a human confirms before creation. Generated
  tasks are flagged `ai_generated` (see `TASKS.md`).

### 2.4 AI Workload Analysis
- Analyze task/attendance distribution to flag over/under-loaded employees or
  teams and suggest rebalancing — within permission scope.

## 3. Architecture (conceptual)

```
User ─► AI request ─► [Permission + Tenant guard] ─► Context builder
                                                   │ (only permitted, tenant data)
                                                   ▼
                                            AI provider (abstracted)
                                                   │
                                     Result ─► [Post-filter/redaction] ─► User
                                                   │
                                             Audit + ai_jobs record
```

- **Provider abstraction:** the AI provider is behind an interface to avoid
  lock-in and to centralize data controls. Provider selection is an open
  decision (`DECISIONS.md`).
- **Context building:** only data the user may access is included; sensitive
  fields (payroll, national ID, bank details) are excluded unless explicitly
  permitted.
- **Async jobs:** heavier tasks (reports, insights) run via `ai_jobs`.

## 4. Data Touchpoints

See `DATABASE.md`: `ai_conversations`, `ai_messages`, `ai_jobs`. All are
tenant-scoped.

## 5. Guardrails (non-negotiable) — ADR-011

0. **Provider abstraction:** all AI access goes through an **AI Provider
   abstraction**. Business logic must **never** depend directly on a single AI
   provider, so the provider can be swapped without touching business code.
1. **Tenant isolation:** an AI feature can never read/write another tenant's
   data (`CLAUDE.md` rules 3–4).
2. **Permission-aware:** AI context and actions respect the user's permissions
   **and organizational scope** (ADR-015); sensitive data is excluded unless
   permitted (`CLAUDE.md` rule 5).
3. **No secrets to providers:** credentials, tokens, `.env` values, and raw
   card/bank data are never sent to an AI provider.
4. **No autonomous sensitive/destructive actions.** AI must **not**
   autonomously: modify payroll, approve payroll, change attendance records,
   approve leave, modify financial transactions, or perform destructive actions.
   Any such future AI-assisted action **requires explicit authorized user
   confirmation** — the AI may only *propose*; an authorized human commits.
5. **Auditability:** AI actions that change data are audited (`CLAUDE.md` rule
   6). AI usage may be logged for cost/quality.
6. **Privacy & data handling:** what tenant data may leave the platform for
   inference (if any), retention, and provider terms are governed by the AI
   provider ADR and `SECURITY.md`. Prefer providers/configurations that do not
   train on tenant data.
7. **Localization:** assistant responds appropriately in Arabic or English per
   user locale; UI strings around AI features are not hard-coded.

## 6. Permissions

`ai.assistant.use`, `ai.insights.view`, `ai.workload.view`, `task.ai.generate`,
`report.ai.generate` (see `PERMISSIONS.md`). Plan feature-flags may gate AI per
subscription tier (`SAAS-BILLING.md`).

## 7. Cost & Reliability

- AI features are **optional/asynchronous** where possible so failures/latency
  don't block core workflows.
- Usage is metered; limits may apply per plan.
- Graceful degradation: if the provider is unavailable, core product is
  unaffected.

## 8. Testing (when implemented)

Cover: tenant isolation of AI context, permission-scoped context building,
sensitive-field exclusion, human-confirm flow for writes, and audit logging of
AI-driven changes.

## 9. Decision Status & Open Questions

- **Decided (ADR-011):** AI Provider abstraction; no autonomous
  sensitive/destructive actions; explicit authorized confirmation for any
  AI-assisted write.
- **Open (deferred by the abstraction; needed at the AI sprint):** AI provider(s)
  and data-handling/training/residency terms; which features are early-tier vs
  premium. (See `DECISIONS.md`.)
