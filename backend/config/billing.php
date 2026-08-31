<?php

// Payment Gateway abstraction (ADR-010). Provider-agnostic. NO payment provider
// is integrated in Sprint 0 — only the contract + an inert default driver exist.
return [
    'default' => env('PAYMENT_GATEWAY_DRIVER', 'manual'),

    // Payment methods the abstraction is designed to support in future sprints.
    'methods' => ['card', 'bank_transfer', 'manual'],
];
