<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Authorization\Http\Controllers\PermissionController;
use App\Modules\Authorization\Http\Controllers\RoleAssignmentController;
use App\Modules\Authorization\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\MembershipController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use App\Modules\Identity\Http\Controllers\UserController;
use App\Modules\Localization\Http\Controllers\LocaleController;
use App\Modules\Onboarding\Http\Controllers\CompanyOnboardingController;
use App\Modules\Platform\Http\Controllers\PlatformAuditController;
use App\Modules\Platform\Http\Controllers\PlatformAuthController;
use App\Modules\Platform\Http\Controllers\PlatformTenantController;
use App\Modules\Tenancy\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
Route::get('locales', [LocaleController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Guest authentication (rate limited; Sanctum SPA cookie/session)
|--------------------------------------------------------------------------
*/
Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:6,1');
Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');

Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

/*
|--------------------------------------------------------------------------
| Authenticated tenant application (tenant context resolved per request)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy']);
    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1');

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);

    // Onboarding does NOT require an existing tenant (it creates one).
    Route::post('onboarding/company', [CompanyOnboardingController::class, 'store']);

    // Everything below requires an active tenant AND a backend permission check.
    Route::middleware('tenant.required')->group(function () {
        Route::get('company', [CompanyController::class, 'show'])->middleware('permission:company.view');
        Route::patch('company', [CompanyController::class, 'update'])->middleware('permission:company.update');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:user.view');
        Route::post('users/invitations', [InvitationController::class, 'store'])->middleware('permission:user.invite');
        Route::post('memberships/{membership}/activate', [MembershipController::class, 'activate'])->middleware('permission:user.manage');
        Route::post('memberships/{membership}/deactivate', [MembershipController::class, 'deactivate'])->middleware('permission:user.manage');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:role.view');
        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:role.view');
        Route::post('role-assignments', [RoleAssignmentController::class, 'store'])->middleware('permission:permission.assign');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    });
});

/*
|--------------------------------------------------------------------------
| Platform / Super Admin portal (SEPARATE guard; not tenant RBAC)
|--------------------------------------------------------------------------
*/
Route::prefix('platform')->group(function () {
    Route::post('login', [PlatformAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('platform')->group(function () {
        Route::post('logout', [PlatformAuthController::class, 'logout']);
        Route::get('me', [PlatformAuthController::class, 'me']);
        Route::get('tenants', [PlatformTenantController::class, 'index']);
        Route::get('tenants/{tenant}', [PlatformTenantController::class, 'show']);
        Route::get('audit-logs', [PlatformAuditController::class, 'index']);
    });
});
