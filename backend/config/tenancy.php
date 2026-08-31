<?php

// Multi-tenancy configuration (ADR-002).
// Shared database, shared schema. Isolation is enforced by the Laravel tenant
// context + global query scopes + PostgreSQL Row-Level Security (RLS).

return [
    // Toggle the PostgreSQL RLS defense-in-depth layer. The application DB role
    // MUST be a non-superuser without BYPASSRLS for RLS to actually protect.
    'rls_enabled' => (bool) env('DB_ENABLE_RLS', true),

    // PostgreSQL session GUC names used to carry request/job context to RLS.
    // These are set per request/job and always reset afterwards so they can
    // never leak across connections (see TenantContext).
    'guc' => [
        'tenant' => 'app.tenant_id',
        // Platform read-only visibility for the audited Super Admin portal.
        // Never enabled inside a tenant request path.
        'platform_readonly' => 'app.platform_readonly',
    ],

    // Base domain for future subdomain/custom-domain resolution. Not required
    // in Sprint 0 (resolution is primarily via the authenticated user's active
    // tenant); the resolver is architected so this can be enabled later.
    'base_domain' => env('TENANT_BASE_DOMAIN', 'raqmidawam.test'),
    'central_domain' => env('CENTRAL_DOMAIN', 'admin.raqmidawam.test'),
];
