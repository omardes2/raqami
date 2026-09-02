<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Enums\LeaveTypeStatus;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for tenant leave types (archive over delete). Category is generic; no
 * entitlement/legal rule lives here. Every change is audited.
 */
class LeaveTypeService
{
    private const FIELDS = [
        'code', 'name', 'description', 'category', 'paid_classification',
        'requires_attachment', 'attachment_required_after_minutes',
        'allow_half_day', 'allow_hourly', 'color',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, mixed $actor = null): LeaveType
    {
        return DB::transaction(function () use ($input, $actor) {
            $type = LeaveType::query()->create(array_merge(
                array_intersect_key($input, array_flip(self::FIELDS)),
                ['status' => LeaveTypeStatus::Active],
            ));

            $this->audit->log('leave.type_created', [
                'actor' => $actor,
                'subject' => $type,
                'metadata' => ['code' => $type->code],
            ]);

            return $type;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(LeaveType $type, array $input, mixed $actor = null): LeaveType
    {
        return DB::transaction(function () use ($type, $input, $actor) {
            $type->fill(array_intersect_key($input, array_flip(self::FIELDS)))->save();

            $this->audit->log('leave.type_updated', [
                'actor' => $actor,
                'subject' => $type,
                'metadata' => ['code' => $type->code],
            ]);

            return $type->fresh();
        });
    }

    public function archive(LeaveType $type, mixed $actor = null): LeaveType
    {
        return DB::transaction(function () use ($type, $actor) {
            $type->fill(['status' => LeaveTypeStatus::Archived])->save();

            $this->audit->log('leave.type_archived', [
                'actor' => $actor,
                'subject' => $type,
                'metadata' => ['code' => $type->code],
            ]);

            return $type->fresh();
        });
    }
}
