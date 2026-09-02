<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill a default segment (#1) for every existing working schedule day that
 * carries start_time/end_time, so the segment-based resolver keeps producing the
 * exact Sprint 3 hours. Idempotent (skips days that already have a segment).
 *
 * Reads across tenants via the audited platform read-only context (SELECT only);
 * each inserted segment carries its source day's own tenant_id — no cross-tenant
 * write. work_schedule_segments has no RLS yet (added by a later migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET app.platform_readonly = 'on'");
        }

        try {
            $days = DB::table('work_schedule_days')
                ->where('is_working_day', true)
                ->whereNotNull('start_time')
                ->whereNotNull('end_time')
                ->get();

            foreach ($days as $day) {
                $already = DB::table('work_schedule_segments')
                    ->where('work_schedule_day_id', $day->id)->exists();
                if ($already) {
                    continue;
                }

                DB::table('work_schedule_segments')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $day->tenant_id,
                    'work_schedule_day_id' => $day->id,
                    'sequence' => 1,
                    'start_time' => $day->start_time,
                    'end_time' => $day->end_time,
                    'break_minutes' => $day->break_minutes,
                    'grace_minutes' => $day->grace_minutes,
                    'overtime_after_minutes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET app.platform_readonly = 'off'");
            }
        }
    }

    public function down(): void
    {
        // Backfilled defaults are removed with the table drop in the create migration.
    }
};
