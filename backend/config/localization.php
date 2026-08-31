<?php

// Localization foundation (ADR-012). Arabic (RTL) + English (LTR) are mandatory
// from Sprint 0. All user-facing strings come from translation files.

return [
    // Locales the platform officially supports.
    'supported' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_SUPPORTED_LOCALES', 'en,ar'))
    ))),

    // Locales rendered right-to-left. Direction is derived from this list,
    // never from a hard-coded per-page value.
    'rtl' => ['ar'],

    // Human-readable names (endonyms) for the language switcher.
    'names' => [
        'en' => 'English',
        'ar' => 'العربية',
    ],

    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),
];
