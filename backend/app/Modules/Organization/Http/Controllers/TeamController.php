<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Http\Requests\TeamRequest;
use App\Modules\Organization\Http\Resources\TeamResource;
use App\Modules\Organization\Models\Team;
use App\Modules\Organization\Models\TeamMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Team::query()->withCount('members')->orderBy('name');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($departmentId = $request->query('department_id')) {
            $query->where('department_id', $departmentId);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        return response()->json(
            TeamResource::collection($query->paginate(min((int) $request->query('per_page', 20), 100)))->response()->getData(true)
        );
    }

    public function store(TeamRequest $request, AuditLogger $audit): JsonResponse
    {
        $team = Team::create($request->validated());
        $audit->log('team.created', ['actor' => $request->user(), 'subject' => $team,
            'metadata' => ['name' => $team->name, 'code' => $team->code]]);

        return (new TeamResource($team))->response()->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        return new TeamResource($team->loadCount('members'));
    }

    public function update(TeamRequest $request, Team $team, AuditLogger $audit): TeamResource
    {
        $team->update($request->validated());
        $audit->log('team.updated', ['actor' => $request->user(), 'subject' => $team,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new TeamResource($team);
    }

    public function archive(Request $request, Team $team, AuditLogger $audit): JsonResponse
    {
        $team->update(['status' => 'archived']);
        $audit->log('team.archived', ['actor' => $request->user(), 'subject' => $team]);

        return response()->json(['id' => $team->id, 'status' => $team->status]);
    }

    /** Add an employee to the team (validated tenant membership). */
    public function addMember(Request $request, Team $team, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string'], 'role_in_team' => ['sometimes', 'nullable', 'string', 'max:64']]);

        if (! Employee::query()->whereKey($data['employee_id'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => [__('organization.invalid_employee')]]);
        }

        $membership = TeamMembership::firstOrCreate(
            ['team_id' => $team->id, 'employee_id' => $data['employee_id']],
            ['role_in_team' => $data['role_in_team'] ?? null],
        );
        $audit->log('team.member_added', ['actor' => $request->user(), 'subject' => $team,
            'metadata' => ['employee_id' => $data['employee_id']]]);

        return response()->json(['id' => $membership->id, 'team_id' => $team->id, 'employee_id' => $membership->employee_id], 201);
    }

    public function removeMember(Request $request, Team $team, string $employee, AuditLogger $audit): JsonResponse
    {
        TeamMembership::query()->where('team_id', $team->id)->where('employee_id', $employee)->delete();
        $audit->log('team.member_removed', ['actor' => $request->user(), 'subject' => $team,
            'metadata' => ['employee_id' => $employee]]);

        return response()->json(['team_id' => $team->id, 'employee_id' => $employee, 'removed' => true]);
    }
}
