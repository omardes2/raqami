<?php

// Payment Gateway abstraction (ADR-010). Provider-agnostic. NO payment provider
// is integrated in Sprint 0 — only the contract + an inert default driver exist.
return [
    'default' => env('PAYMENT_GATEWAY_DRIVER', 'manual'),

    // Payment methods the abstraction is designed to support. Card is defined
    // but NOT connected in Sprint 2 (no real provider integrated).
    'methods' => ['card', 'bank_transfer', 'cash', 'manual'],

    // Default free-trial length (days) when a plan does not set trial_days.
    'default_trial_days' => (int) env('BILLING_DEFAULT_TRIAL_DAYS', 14),

    // Grace period (days) after a failed/expired payment before suspension.
    // A foundation value only — no card-retry engine in Sprint 2.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

    // Invoice payment due window (days) from issue.
    'invoice_due_days' => (int) env('BILLING_INVOICE_DUE_DAYS', 7),

    // ISO 4217 currency codes the billing UI/validation accepts (foundation;
    // no automatic FX conversion in Sprint 2 — one currency per invoice/payment).
    'currencies' => ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'JOD', 'ILS'],
];
