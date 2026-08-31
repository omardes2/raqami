<?php

// AI Provider abstraction (ADR-011). Provider-agnostic. NO AI provider is called
// in Sprint 0 — only the contract + an inert default driver exist. AI may never
// autonomously perform sensitive/destructive actions; any future AI-assisted
// write requires explicit authorized user confirmation.
return [
    'default' => env('AI_PROVIDER_DRIVER', 'null'),
];
