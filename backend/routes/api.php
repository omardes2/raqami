<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Authorization\Http\Controllers\PermissionController;
use App\Modules\Authorization\Http\Controllers\RoleAssignmentController;
use App\Modules\Authorization\Http\Controllers\RoleController;
use App\Modules\Employees\Http\Controllers\EmergencyContactController;
use App\Modules\Employees\Http\Controllers\EmployeeContractController;
use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Modules\Employees\Http\Controllers\EmployeeDocumentController;
use App\Modules\Employees\Http\Controllers\EmployeeHistoryController;
use App\Modules\Employees\Http\Controllers\EmployeeTransferController;
use App\Modules\Employees\Http\Controllers\EmployeeUserLinkController;
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
use App\Modules\Organization\Http\Controllers\BranchController;
use App\Modules\Organization\Http\Controllers\DepartmentController;
use App\Modules\Organization\Http\Controllers\JobTitleController;
use App\Modules\Organization\Http\Controllers\TeamController;
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
| Sprint 1 — Organization & Employees (tenant-scoped, permission-gated)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant', 'tenant.required'])->group(function () {
    // --- Organization structure ---
    Route::get('branches', [BranchController::class, 'index'])->middleware('permission.any:branches.view');
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.create');
    Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission.any:branches.view');
    Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.update');
    Route::post('branches/{branch}/archive', [BranchController::class, 'archive'])->middleware('permission:branches.archive');

    Route::get('departments', [DepartmentController::class, 'index'])->middleware('permission.any:departments.view');
    Route::post('departments', [DepartmentController::class, 'store'])->middleware('permission:departments.create');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])->middleware('permission.any:departments.view');
    Route::match(['put', 'patch'], 'departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:departments.update');
    Route::post('departments/{department}/archive', [DepartmentController::class, 'archive'])->middleware('permission:departments.archive');

    Route::get('teams', [TeamController::class, 'index'])->middleware('permission.any:teams.view');
    Route::post('teams', [TeamController::class, 'store'])->middleware('permission:teams.create');
    Route::get('teams/{team}', [TeamController::class, 'show'])->middleware('permission.any:teams.view');
    Route::match(['put', 'patch'], 'teams/{team}', [TeamController::class, 'update'])->middleware('permission:teams.update');
    Route::post('teams/{team}/archive', [TeamController::class, 'archive'])->middleware('permission:teams.archive');
    Route::post('teams/{team}/members', [TeamController::class, 'addMember'])->middleware('permission:teams.update');
    Route::delete('teams/{team}/members/{employee}', [TeamController::class, 'removeMember'])->middleware('permission:teams.update');

    Route::get('job-titles', [JobTitleController::class, 'index'])->middleware('permission.any:job_titles.view');
    Route::post('job-titles', [JobTitleController::class, 'store'])->middleware('permission:job_titles.create');
    Route::get('job-titles/{jobTitle}', [JobTitleController::class, 'show'])->middleware('permission.any:job_titles.view');
    Route::match(['put', 'patch'], 'job-titles/{jobTitle}', [JobTitleController::class, 'update'])->middleware('permission:job_titles.update');
    Route::post('job-titles/{jobTitle}/archive', [JobTitleController::class, 'archive'])->middleware('permission:job_titles.archive');

    // --- Employees ---
    Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission.any:employees.view');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission.any:employees.create');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission.any:employees.view');
    Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission.any:employees.update');
    Route::post('employees/{employee}/status', [EmployeeController::class, 'changeStatus'])->middleware('permission.any:employees.update');
    Route::post('employees/{employee}/archive', [EmployeeController::class, 'archive'])->middleware('permission.any:employees.archive');
    Route::post('employees/{employee}/transfer', [EmployeeTransferController::class, 'store'])->middleware('permission.any:employees.transfer');
    Route::post('employees/{employee}/user-link', [EmployeeUserLinkController::class, 'store'])->middleware('permission.any:employees.link_user');
    Route::delete('employees/{employee}/user-link', [EmployeeUserLinkController::class, 'destroy'])->middleware('permission.any:employees.link_user');
    Route::get('employees/{employee}/history', [EmployeeHistoryController::class, 'index'])->middleware('permission.any:employees.view');

    // Emergency contacts (sensitive)
    Route::get('employees/{employee}/emergency-contacts', [EmergencyContactController::class, 'index'])->middleware('permission.any:employees.view_sensitive');
    Route::post('employees/{employee}/emergency-contacts', [EmergencyContactController::class, 'store'])->middleware('permission.any:employees.view_sensitive');
    Route::delete('employees/{employee}/emergency-contacts/{contact}', [EmergencyContactController::class, 'destroy'])->middleware('permission.any:employees.view_sensitive');

    // Documents (private storage)
    Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->middleware('permission.any:employee_documents.view');
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware('permission.any:employee_documents.upload');
    Route::get('employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])
        ->middleware('permission.any:employee_documents.view')->name('employees.documents.download');
    Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware('permission.any:employee_documents.delete');

    // Contracts
    Route::get('employees/{employee}/contracts', [EmployeeContractController::class, 'index'])->middleware('permission.any:employee_contracts.view');
    Route::post('employees/{employee}/contracts', [EmployeeContractController::class, 'store'])->middleware('permission.any:employee_contracts.create');
    Route::match(['put', 'patch'], 'employees/{employee}/contracts/{contract}', [EmployeeContractController::class, 'update'])->middleware('permission.any:employee_contracts.update');
    Route::post('employees/{employee}/contracts/{contract}/archive', [EmployeeContractController::class, 'archive'])->middleware('permission.any:employee_contracts.archive');
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
