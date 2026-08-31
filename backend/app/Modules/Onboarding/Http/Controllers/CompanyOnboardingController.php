<?php

namespace App\Modules\Onboarding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Localization\Services\LocaleService;
use App\Modules\Onboarding\Services\CompanyOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyOnboardingController extends Controller
{
    public function store(
        Request $request,
        CompanyOnboardingService $onboarding,
        LocaleService $locales,
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'default_locale' => ['sometimes', 'in:'.implode(',', $locales->supported())],
            'default_currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $tenant = $onboarding->createCompany($request->user(), $data);

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'default_locale' => $tenant->default_locale,
            'status' => $tenant->status,
        ], 201);
    }
}
