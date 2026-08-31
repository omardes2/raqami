<?php

use Illuminate\Support\Facades\Route;

// The React SPA is served separately (Vite). This backend is API-first.
Route::get('/', fn () => response()->json([
    'app' => 'Raqmi Dawam API',
    'status' => 'ok',
]));

// A named "login" route so the framework's guest-redirect never 500s on
// non-JSON API requests. JSON clients receive a direct 401 (see bootstrap/app).
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');
