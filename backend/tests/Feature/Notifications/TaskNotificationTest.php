<?php

namespace Tests\Feature\Notifications;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Services\TaskAssignmentService;
use App\Modules\Tasks\Services\TaskService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B — task assignment notifications reach the assignee's User, keep
 * A → B → A distinct (activity-event discriminator), distinguish assign vs
 * reassign, skip employees without a User, and carry no task content.
 */
class TaskNotificationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0:Employee,1:User} linked, active member */
    private function linked(string $first): array
    {
        $user = User::factory()->create();
        $employee = app(EmployeeService::class)->create(['first_name' => $first, 'last_name' => 'X', 'employment_status' => 'active']);
        $employee->fill(['user_id' => $user->id])->save();
        TenantMembership::create(['user_id' => $user->id, 'status' => 'active']);

        return [$employee->fresh(), $user];
    }

    private function inbox(Tenant $tenant, string $userId, ?string $type = null): int
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->getKey()]);
        DB::statement("select set_config('app.user_id', ?, false)", [$userId]);
        DB::statement("select set_config('app.platform_readonly', 'off', false)");
        try {
            $q = DB::table('notifications');
            if ($type !== null) {
                $q->where('type', $type);
            }

            return (int) $q->count();
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_assignment_notifies_assignee_user(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $eUser = $this->withinTenant($tenant, function () use ($owner) {
            [$employee, $eUser] = $this->linked('Asg');
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $employee->getKey(), true);

            return $eUser;
        });

        $this->assertSame(1, $this->inbox($tenant, (string) $eUser->id, 'task.assigned'));
    }

    public function test_a_b_a_creates_distinct_notifications_and_reassign_type(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        [$u1, $u2] = $this->withinTenant($tenant, function () use ($owner) {
            [$e1, $u1] = $this->linked('One');
            [$e2, $u2] = $this->linked('Two');
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            $assign = app(TaskAssignmentService::class);

            $assign->assign($owner, $task->fresh(), $e1->getKey(), true);  // e1 new → assigned
            $assign->assign($owner, $task->fresh(), $e2->getKey(), true);  // e2 new → assigned (demotes e1 primary, no e1 row touch)
            $assign->assign($owner, $task->fresh(), $e1->getKey(), true);  // e1 existing → reassigned

            return [$u1, $u2];
        });

        $this->assertSame(1, $this->inbox($tenant, (string) $u1->id, 'task.assigned'));
        $this->assertSame(1, $this->inbox($tenant, (string) $u1->id, 'task.reassigned'));
        $this->assertSame(2, $this->inbox($tenant, (string) $u1->id));       // distinct, not deduped away
        $this->assertSame(1, $this->inbox($tenant, (string) $u2->id, 'task.assigned'));
    }

    public function test_employee_without_user_is_skipped(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $employee = app(EmployeeService::class)->create(['first_name' => 'NoUser', 'last_name' => 'X', 'employment_status' => 'active']);
            $task = app(TaskService::class)->create($owner, ['title' => 'T', 'scope_type' => 'company']);
            // Must not throw despite the assignee having no linked User.
            app(TaskAssignmentService::class)->assign($owner, $task->fresh(), $employee->getKey(), true);
        });

        $this->assertSame(0, $this->inbox($tenant, (string) $owner->id, 'task.assigned'));
    }
}
