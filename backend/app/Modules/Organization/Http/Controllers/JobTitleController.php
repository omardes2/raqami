<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Http\Requests\JobTitleRequest;
use App\Modules\Organization\Http\Resources\JobTitleResource;
use App\Modules\Organization\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTitleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JobTitle::query()->withCount('employees')->orderBy('title');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));
        }

        return response()->json(
            JobTitleResource::collection($query->paginate(min((int) $request->query('per_page', 20), 100)))->response()->getData(true)
        );
    }

    public function store(JobTitleRequest $request, AuditLogger $audit): JsonResponse
    {
        $jobTitle = JobTitle::create($request->validated());
        $audit->log('job_title.created', ['actor' => $request->user(), 'subject' => $jobTitle,
            'metadata' => ['title' => $jobTitle->title, 'code' => $jobTitle->code]]);

        return (new JobTitleResource($jobTitle))->response()->setStatusCode(201);
    }

    public function show(JobTitle $jobTitle): JobTitleResource
    {
        return new JobTitleResource($jobTitle->loadCount('employees'));
    }

    public function update(JobTitleRequest $request, JobTitle $jobTitle, AuditLogger $audit): JobTitleResource
    {
        $jobTitle->update($request->validated());
        $audit->log('job_title.updated', ['actor' => $request->user(), 'subject' => $jobTitle,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new JobTitleResource($jobTitle);
    }

    public function archive(Request $request, JobTitle $jobTitle, AuditLogger $audit): JsonResponse
    {
        $active = Employee::query()->where('job_title_id', $jobTitle->id)
            ->whereNotIn('employment_status', ['terminated', 'archived'])->count();
        if ($active > 0) {
            return response()->json(['message' => __('organization.job_title_in_use')], 422);
        }

        $jobTitle->update(['status' => 'archived']);
        $audit->log('job_title.archived', ['actor' => $request->user(), 'subject' => $jobTitle]);

        return response()->json(['id' => $jobTitle->id, 'status' => $jobTitle->status]);
    }
}
