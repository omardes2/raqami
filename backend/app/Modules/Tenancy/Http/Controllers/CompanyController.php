<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Localization\Services\LocaleService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'slug' => $tenant->slug,
            'country_code' => $tenant->country_code,
            'timezone' => $tenant->timezone,
            'default_locale' => $tenant->default_locale,
            'default_currency' => $tenant->default_currency,
            'status' => $tenant->status,
        ]);
    }

    public function update(
        Request $request,
        TenantContext $context,
        LocaleService $locales,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'default_locale' => ['sometimes', 'in:'.implode(',', $locales->supported())],
            'default_currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $tenant = $context->tenant();
        $tenant->fill($validated)->save();

        $audit->log('company.updated', [
            'actor' => $request->user(),
            'subject' => $tenant,
            'metadata' => ['fields' => array_keys($validated)],
        ]);

        return $this->show($context);
    }
}
