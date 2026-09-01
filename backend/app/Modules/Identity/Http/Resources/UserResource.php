<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Identity\Models\User;
use App\Modules\Localization\Services\LocaleService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** Return a flat user object (no "data" wrapper). */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $context = app(TenantContext::class);
        $access = app(AccessService::class);
        $locale = app(LocaleService::class);

        $tenant = $context->tenant();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'direction' => $locale->direction($this->locale),
            'timezone' => $this->timezone,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'active_tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'default_locale' => $tenant->default_locale,
                'status' => $tenant->status,
            ] : null,
            // Permissions/roles are backend-authoritative; the UI uses these
            // only to hide controls, never to authorize. Union across all scopes
            // so scoped managers still see the relevant navigation.
            'permissions' => $tenant ? $access->allPermissions($this->resource)->all() : [],
            'roles' => $tenant ? $access->roleSlugsFor($this->resource)->all() : [],
        ];
    }
}
