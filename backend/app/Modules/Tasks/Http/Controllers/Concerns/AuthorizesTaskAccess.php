<?php

namespace App\Modules\Tasks\Http\Controllers\Concerns;

use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Support\TaskVisibilityResolver;

/**
 * Scope-safe access helpers: RLS + route-model binding already bound the row to
 * the tenant; TaskVisibilityResolver decides intra-tenant authorization. A hidden
 * row yields a 404 (never a 403 that leaks existence).
 */
trait AuthorizesTaskAccess
{
    protected function visibleTaskOr404(User $user, Task $task): Task
    {
        abort_unless(app(TaskVisibilityResolver::class)->canViewTask($user, $task), 404);

        return $task;
    }

    protected function visibleProjectOr404(User $user, Project $project): Project
    {
        abort_unless(app(TaskVisibilityResolver::class)->canViewProject($user, $project), 404);

        return $project;
    }
}
