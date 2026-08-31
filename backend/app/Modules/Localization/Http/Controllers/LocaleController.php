<?php

namespace App\Modules\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Localization\Services\LocaleService;
use Illuminate\Http\JsonResponse;

class LocaleController extends Controller
{
    /** Public: supported locales and their text direction. */
    public function index(LocaleService $locales): JsonResponse
    {
        return response()->json([
            'locales' => $locales->catalog(),
            'default' => config('app.locale'),
            'fallback' => config('app.fallback_locale'),
        ]);
    }
}
