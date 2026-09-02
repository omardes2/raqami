<?php

use App\Modules\Attendance\Http\Controllers\AttendanceAnomalyController;
use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Http\Controllers\AttendanceCorrectionController;
use App\Modules\Attendance\Http\Controllers\AttendanceExceptionController;
use App\Modules\Attendance\Http\Controllers\AttendanceLocationController;
use App\Modules\Attendance\Http\Controllers\AttendanceMaterializationController;
use App\Modules\Attendance\Http\Controllers\AttendanceRecordController;
use App\Modules\Attendance\Http\Controllers\AttendanceReportController;
use App\Modules\Attendance\Http\Controllers\AttendanceSettingsController;
use App\Modules\Attendance\Http\Controllers\HolidayCalendarController;
use App\Modules\Attendance\Http\Controllers\OvertimeApprovalController;
use App\Modules\Attendance\Http\Controllers\WorkScheduleController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Authorization\Http\Controllers\PermissionController;
use App\Modules\Authorization\Http\Controllers\RoleAssignmentController;
use App\Modules\Authorization\Http\Controllers\RoleController;
use App\Modules\Billing\Http\Controllers\BankTransferController;
use App\Modules\Billing\Http\Controllers\BillingOverviewController;
use App\Modules\Billing\Http\Controllers\BillingProfileController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\PaymentController;
use App\Modules\Billing\Http\Controllers\PlanCatalogController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformBankAccountController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformBankTransferController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformCouponController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformInvoiceController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformPaymentController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformPlanController;
use App\Modules\Billing\Http\Controllers\Platform\PlatformSubscriptionController;
use App\Modules\Billing\Http\Controllers\SubscriptionController;
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
use App\Modules\Leave\Http\Controllers\LeaveBalanceController;
use App\Modules\Leave\Http\Controllers\LeaveController;
use App\Modules\Leave\Http\Controllers\LeavePolicyController;
use App\Modules\Leave\Http\Controllers\LeaveReportController;
use App\Modules\Leave\Http\Controllers\LeaveRequestController;
use App\Modules\Leave\Http\Controllers\LeaveSettingsController;
use App\Modules\Leave\Http\Controllers\LeaveTypeController;
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
| Sprint 2 — Billing & Subscriptions (tenant-scoped, permission-gated)
|--------------------------------------------------------------------------
| The subscription belongs to the TENANT; billing is company-wide (not org
| scoped), so gates use `permission:` (company scope), not `permission.any:`.
*/
Route::middleware(['auth:sanctum', 'tenant', 'tenant.required'])->prefix('billing')->group(function () {
    Route::get('overview', [BillingOverviewController::class, 'show'])->middleware('permission:billing.view');
    Route::get('plans', [PlanCatalogController::class, 'index'])->middleware('permission:billing.subscription.view');

    Route::get('subscription', [SubscriptionController::class, 'show'])->middleware('permission:billing.subscription.view');
    Route::post('subscription', [SubscriptionController::class, 'subscribe'])->middleware('permission:billing.subscription.change');
    Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan'])->middleware('permission:billing.subscription.change');
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('permission:billing.subscription.change');
    Route::post('subscription/resume', [SubscriptionController::class, 'resume'])->middleware('permission:billing.subscription.change');
    Route::post('subscription/invoice', [SubscriptionController::class, 'invoice'])->middleware('permission:billing.subscription.change');

    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:billing.invoices.view');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:billing.invoices.view');
    Route::get('invoices/{invoice}/html', [InvoiceController::class, 'html'])->middleware('permission:billing.invoices.view');

    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:billing.payments.view');

    Route::get('profile', [BillingProfileController::class, 'show'])->middleware('permission:billing.view');
    Route::put('profile', [BillingProfileController::class, 'update'])->middleware('permission:billing.manage');

    Route::get('bank-accounts', [BankTransferController::class, 'bankAccounts'])->middleware('permission:billing.bank_transfer.submit');
    Route::get('bank-transfers', [BankTransferController::class, 'index'])->middleware('permission:billing.payments.view');
    Route::post('bank-transfers', [BankTransferController::class, 'store'])->middleware('permission:billing.bank_transfer.submit');
    Route::get('bank-transfers/{bankTransfer}/proof', [BankTransferController::class, 'downloadProof'])->middleware('permission:billing.payments.view');
});

/*
|--------------------------------------------------------------------------
| Sprint 3 — Attendance (tenant-scoped)
|--------------------------------------------------------------------------
| Self-service (check-in/out, own attendance, own correction request) is gated
| by authentication + employee link — NOT a permission. Company-wide config
| (settings, schedules, locations) uses `permission:` (company scope). Actions
| over other employees (records, manual entry, corrections, reports) use
| `permission.any:` and are further constrained by organizational scope in the
| controllers/services. The SERVER decides every result; clients send only facts.
*/
Route::middleware(['auth:sanctum', 'tenant', 'tenant.required'])->prefix('attendance')->group(function () {
    // --- Employee self-service ---
    Route::post('check-in', [AttendanceController::class, 'checkIn']);
    Route::post('check-out', [AttendanceController::class, 'checkOut']);
    Route::get('me', [AttendanceController::class, 'myAttendance']);
    Route::get('me/today', [AttendanceController::class, 'myToday']);
    Route::post('me/records/{record}/corrections', [AttendanceController::class, 'requestCorrection']);

    // --- Attendance settings (company scope) ---
    Route::get('settings', [AttendanceSettingsController::class, 'show'])->middleware('permission:attendance.settings.manage');
    Route::put('settings', [AttendanceSettingsController::class, 'update'])->middleware('permission:attendance.settings.manage');

    // --- Work schedules & assignments (company scope) ---
    Route::get('schedules', [WorkScheduleController::class, 'index'])->middleware('permission:attendance.schedules.view');
    Route::post('schedules', [WorkScheduleController::class, 'store'])->middleware('permission:attendance.schedules.manage');
    Route::get('schedules/{schedule}', [WorkScheduleController::class, 'show'])->middleware('permission:attendance.schedules.view');
    Route::match(['put', 'patch'], 'schedules/{schedule}', [WorkScheduleController::class, 'update'])->middleware('permission:attendance.schedules.manage');
    Route::post('schedules/{schedule}/assignments', [WorkScheduleController::class, 'assign'])->middleware('permission:attendance.schedules.manage');
    Route::delete('schedules/{schedule}/assignments/{assignment}', [WorkScheduleController::class, 'unassign'])->middleware('permission:attendance.schedules.manage');

    // --- Geofence locations (company scope) ---
    Route::get('locations', [AttendanceLocationController::class, 'index'])->middleware('permission:attendance.locations.manage');
    Route::post('locations', [AttendanceLocationController::class, 'store'])->middleware('permission:attendance.locations.manage');
    Route::match(['put', 'patch'], 'locations/{location}', [AttendanceLocationController::class, 'update'])->middleware('permission:attendance.locations.manage');
    Route::post('locations/{location}/archive', [AttendanceLocationController::class, 'archive'])->middleware('permission:attendance.locations.manage');

    // --- Records & manual entry (organizational scope) ---
    Route::get('records', [AttendanceRecordController::class, 'index'])->middleware('permission.any:attendance.view');
    Route::post('records/manual', [AttendanceRecordController::class, 'storeManual'])->middleware('permission.any:attendance.manage');
    Route::get('records/{record}', [AttendanceRecordController::class, 'show'])->middleware('permission.any:attendance.view');

    // --- Corrections review (organizational scope) ---
    Route::get('corrections', [AttendanceCorrectionController::class, 'index'])->middleware('permission.any:attendance.corrections.review');
    Route::post('corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->middleware('permission.any:attendance.corrections.review');
    Route::post('corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->middleware('permission.any:attendance.corrections.review');

    // --- Reports (organizational scope) ---
    Route::get('reports/summary', [AttendanceReportController::class, 'summary'])->middleware('permission.any:attendance.reports.view');
    Route::get('reports/advanced', [AttendanceReportController::class, 'advanced'])->middleware('permission.any:attendance.reports.view');
    Route::get('reports/by-employee', [AttendanceReportController::class, 'byEmployee'])->middleware('permission.any:attendance.reports.view');

    // --- Sprint 4: Holiday calendars (company scope) ---
    Route::get('holidays/calendars', [HolidayCalendarController::class, 'index'])->middleware('permission:attendance.holidays.view');
    Route::post('holidays/calendars', [HolidayCalendarController::class, 'store'])->middleware('permission:attendance.holidays.manage');
    Route::get('holidays/calendars/{calendar}', [HolidayCalendarController::class, 'show'])->middleware('permission:attendance.holidays.view');
    Route::match(['put', 'patch'], 'holidays/calendars/{calendar}', [HolidayCalendarController::class, 'update'])->middleware('permission:attendance.holidays.manage');
    Route::post('holidays/calendars/{calendar}/holidays', [HolidayCalendarController::class, 'addHoliday'])->middleware('permission:attendance.holidays.manage');
    Route::delete('holidays/calendars/{calendar}/holidays/{holiday}', [HolidayCalendarController::class, 'deleteHoliday'])->middleware('permission:attendance.holidays.manage');
    Route::post('holidays/calendars/{calendar}/assignments', [HolidayCalendarController::class, 'assign'])->middleware('permission:attendance.holidays.manage');
    Route::delete('holidays/calendars/{calendar}/assignments/{assignment}', [HolidayCalendarController::class, 'unassign'])->middleware('permission:attendance.holidays.manage');

    // --- Sprint 4: Attendance exceptions (organizational scope) ---
    Route::get('exceptions', [AttendanceExceptionController::class, 'index'])->middleware('permission.any:attendance.exceptions.view');
    Route::post('exceptions', [AttendanceExceptionController::class, 'store'])->middleware('permission.any:attendance.exceptions.manage');
    Route::post('exceptions/{exception}/revoke', [AttendanceExceptionController::class, 'revoke'])->middleware('permission.any:attendance.exceptions.manage');

    // --- Sprint 4: Overtime approval (organizational scope) ---
    Route::get('overtime', [OvertimeApprovalController::class, 'index'])->middleware('permission.any:attendance.overtime.view');
    Route::post('overtime/{approval}/approve', [OvertimeApprovalController::class, 'approve'])->middleware('permission.any:attendance.overtime.review');
    Route::post('overtime/{approval}/reject', [OvertimeApprovalController::class, 'reject'])->middleware('permission.any:attendance.overtime.review');

    // --- Sprint 4: Attendance anomalies (organizational scope) ---
    Route::get('anomalies', [AttendanceAnomalyController::class, 'index'])->middleware('permission.any:attendance.anomalies.view');
    Route::post('anomalies/{anomaly}/review', [AttendanceAnomalyController::class, 'review'])->middleware('permission.any:attendance.anomalies.manage');

    // --- Sprint 4: On-demand materialization (company scope) ---
    Route::post('materialization/run', [AttendanceMaterializationController::class, 'run'])->middleware('permission:attendance.materialization.run');
});

/*
|--------------------------------------------------------------------------
| Sprint 5 — Leave (tenant-scoped)
|--------------------------------------------------------------------------
| Self-service (own balances/requests/withdraw/cancellation/attachments) is
| gated by authentication + employee link — NOT a permission. Company-wide
| config (types, policies, settings) uses `permission:` (company scope).
| Actions over other employees (review, cancel, balances) use `permission.any:`
| and are further constrained by organizational scope in the controllers.
*/
Route::middleware(['auth:sanctum', 'tenant', 'tenant.required'])->prefix('leave')->group(function () {
    // --- Employee self-service ---
    Route::get('me/balances', [LeaveController::class, 'myBalances']);
    Route::get('me/requests', [LeaveController::class, 'myRequests']);
    Route::post('requests/preview', [LeaveController::class, 'preview']);
    Route::post('requests', [LeaveController::class, 'store']);
    Route::get('requests/{leaveRequest}', [LeaveController::class, 'show']);
    Route::post('requests/{leaveRequest}/withdraw', [LeaveController::class, 'withdraw']);
    Route::post('requests/{leaveRequest}/request-cancellation', [LeaveController::class, 'requestCancellation']);
    Route::post('requests/{leaveRequest}/attachments', [LeaveController::class, 'storeAttachment']);
    Route::get('me/requests/{leaveRequest}/attachments/{attachment}/download', [LeaveController::class, 'downloadAttachment']);

    // --- Management: request review (organizational scope) ---
    Route::get('requests', [LeaveRequestController::class, 'index'])->middleware('permission.any:leave.view');
    Route::get('manage/requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->middleware('permission.any:leave.view');
    Route::post('requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->middleware('permission.any:leave.approve');
    Route::post('requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->middleware('permission.any:leave.approve');
    Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->middleware('permission.any:leave.manage');
    Route::post('requests/{leaveRequest}/cancellation/approve', [LeaveRequestController::class, 'approveCancellation'])->middleware('permission.any:leave.approve');
    Route::post('requests/{leaveRequest}/cancellation/reject', [LeaveRequestController::class, 'rejectCancellation'])->middleware('permission.any:leave.approve');
    Route::get('requests/{leaveRequest}/attachments/{attachment}/download', [LeaveRequestController::class, 'downloadAttachment'])->middleware('permission.any:leave.view');

    // --- Balances (organizational scope) ---
    Route::get('balances', [LeaveBalanceController::class, 'index'])->middleware('permission.any:leave.balances.view');
    Route::post('balances/adjust', [LeaveBalanceController::class, 'adjust'])->middleware('permission.any:leave.balances.adjust');

    // --- Reports & team calendar (organizational scope) ---
    Route::get('reports/summary', [LeaveReportController::class, 'summary'])->middleware('permission.any:leave.reports.view');
    Route::get('calendar', [LeaveReportController::class, 'calendar'])->middleware('permission.any:leave.reports.view');

    // --- Leave types (company scope) ---
    Route::get('types', [LeaveTypeController::class, 'index'])->middleware('permission:leave.types.view');
    Route::post('types', [LeaveTypeController::class, 'store'])->middleware('permission:leave.types.manage');
    Route::get('types/{leaveType}', [LeaveTypeController::class, 'show'])->middleware('permission:leave.types.view');
    Route::match(['put', 'patch'], 'types/{leaveType}', [LeaveTypeController::class, 'update'])->middleware('permission:leave.types.manage');
    Route::post('types/{leaveType}/archive', [LeaveTypeController::class, 'archive'])->middleware('permission:leave.types.manage');

    // --- Leave policies + assignments (company scope) ---
    Route::get('policies', [LeavePolicyController::class, 'index'])->middleware('permission:leave.policies.view');
    Route::post('policies', [LeavePolicyController::class, 'store'])->middleware('permission:leave.policies.manage');
    Route::get('policies/{policy}', [LeavePolicyController::class, 'show'])->middleware('permission:leave.policies.view');
    Route::match(['put', 'patch'], 'policies/{policy}', [LeavePolicyController::class, 'update'])->middleware('permission:leave.policies.manage');
    Route::post('policies/{policy}/archive', [LeavePolicyController::class, 'archive'])->middleware('permission:leave.policies.manage');
    Route::post('policies/{policy}/assignments', [LeavePolicyController::class, 'assign'])->middleware('permission:leave.policies.manage');
    Route::delete('policies/{policy}/assignments/{assignment}', [LeavePolicyController::class, 'unassign'])->middleware('permission:leave.policies.manage');

    // --- Leave settings (company scope) ---
    Route::get('settings', [LeaveSettingsController::class, 'show'])->middleware('permission:leave.settings.manage');
    Route::put('settings', [LeaveSettingsController::class, 'update'])->middleware('permission:leave.settings.manage');
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

        // --- Sprint 2: platform billing management ---
        Route::get('plans', [PlatformPlanController::class, 'index']);
        Route::post('plans', [PlatformPlanController::class, 'store']);
        Route::get('plans/{plan}', [PlatformPlanController::class, 'show']);
        Route::match(['put', 'patch'], 'plans/{plan}', [PlatformPlanController::class, 'update']);
        Route::post('plans/{plan}/archive', [PlatformPlanController::class, 'archive']);
        Route::post('plans/{plan}/features', [PlatformPlanController::class, 'storeFeature']);
        Route::delete('plans/{plan}/features/{feature}', [PlatformPlanController::class, 'destroyFeature']);

        Route::get('coupons', [PlatformCouponController::class, 'index']);
        Route::post('coupons', [PlatformCouponController::class, 'store']);
        Route::match(['put', 'patch'], 'coupons/{coupon}', [PlatformCouponController::class, 'update']);
        Route::post('coupons/{coupon}/archive', [PlatformCouponController::class, 'archive']);

        Route::get('bank-accounts', [PlatformBankAccountController::class, 'index']);
        Route::post('bank-accounts', [PlatformBankAccountController::class, 'store']);
        Route::match(['put', 'patch'], 'bank-accounts/{bankAccount}', [PlatformBankAccountController::class, 'update']);
        Route::post('bank-accounts/{bankAccount}/archive', [PlatformBankAccountController::class, 'archive']);

        Route::get('subscriptions', [PlatformSubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}', [PlatformSubscriptionController::class, 'show']);

        Route::get('invoices', [PlatformInvoiceController::class, 'index']);

        Route::get('payments', [PlatformPaymentController::class, 'index']);
        Route::post('payments/manual', [PlatformPaymentController::class, 'manual']);

        Route::get('bank-transfers', [PlatformBankTransferController::class, 'index']);
        Route::post('bank-transfers/{submission}/approve', [PlatformBankTransferController::class, 'approve']);
        Route::post('bank-transfers/{submission}/reject', [PlatformBankTransferController::class, 'reject']);
        Route::get('bank-transfers/{submission}/proof', [PlatformBankTransferController::class, 'downloadProof']);
    });
});
