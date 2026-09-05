<?php

// AI Provider abstraction (ADR-011, ADR-024). Provider-agnostic; business logic
// depends only on the AiProvider contract. DISABLED BY DEFAULT: the default
// driver is the inert 'null' provider, so no external service is called until an
// owner explicitly sets AI_PROVIDER_DRIVER and the provider's API key in the
// environment. AI is assistive/read-only — it may never autonomously modify
// payroll, attendance, leave, financial records, or perform destructive actions.
// The API key is read from env here and NEVER hard-coded or exposed to the
// frontend.
return [
    'default' => env('AI_PROVIDER_DRIVER', 'null'),

    'providers' => [
        'anthropic' => [
            'api_key' => env('AI_ANTHROPIC_API_KEY'),
            'model' => env('AI_ANTHROPIC_MODEL', 'claude-sonnet-5'),
            'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => '2023-06-01',
            'max_tokens' => (int) env('AI_ANTHROPIC_MAX_TOKENS', 1024),
            'timeout' => (int) env('AI_ANTHROPIC_TIMEOUT', 20),
        ],
    ],

    // Rough cost estimate (micro-USD per token) for the usage ledger only —
    // operational metadata, not billing. 0 when unknown.
    'cost_micro_per_input_token' => (int) env('AI_COST_MICRO_PER_INPUT_TOKEN', 0),
    'cost_micro_per_output_token' => (int) env('AI_COST_MICRO_PER_OUTPUT_TOKEN', 0),

    // Entitlement feature key gating AI usage (Billing PlanFeature).
    'feature_key' => 'ai_assistant',

    // Per-tenant daily call cap (0 = unlimited); a floor safety limit independent
    // of plan featureLimit, which takes precedence when smaller.
    'daily_call_cap' => (int) env('AI_DAILY_CALL_CAP', 500),
];
