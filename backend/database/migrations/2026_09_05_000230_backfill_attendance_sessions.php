<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill session #1 for every existing attendance_record that has a check-in,
 * copying its punch/geo/minutes/snapshot verbatim so legacy Sprint 3 data stays
 * valid as the new daily-aggregate model. Idempotent (skips records that already
 * have sessions). Reads across tenants via the audited read-only context;
 * inserted sessions carry the source record's own tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET app.platform_readonly = 'on'");
        }

        try {
            $records = DB::table('attendance_records')->whereNotNull('check_in_at')->get();

            foreach ($records as $r) {
                $already = DB::table('attendance_sessions')
                    ->where('attendance_record_id', $r->id)->exists();
                if ($already) {
                    continue;
                }

                DB::table('attendance_sessions')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $r->tenant_id,
                    'attendance_record_id' => $r->id,
                    'employee_id' => $r->employee_id,
                    'sequence' => 1,
                    'check_in_at' => $r->check_in_at,
                    'check_out_at' => $r->check_out_at,
                    'scheduled_start_at' => $r->scheduled_start_at,
                    'scheduled_end_at' => $r->scheduled_end_at,
                    'worked_minutes' => $r->worked_minutes,
                    'break_minutes' => $r->break_minutes,
                    'late_minutes' => $r->late_minutes,
                    'early_leave_minutes' => $r->early_leave_minutes,
                    'overtime_minutes' => $r->overtime_minutes,
                    'grace_minutes' => $r->grace_minutes,
                    'source' => $r->source,
                    'is_manual' => $r->is_manual,
                    'check_in_latitude' => $r->check_in_latitude,
                    'check_in_longitude' => $r->check_in_longitude,
                    'check_in_inside_geofence' => $r->check_in_inside_geofence,
                    'check_in_location_id' => $r->check_in_location_id,
                    'check_out_latitude' => $r->check_out_latitude,
                    'check_out_longitude' => $r->check_out_longitude,
                    'check_out_inside_geofence' => $r->check_out_inside_geofence,
                    'check_out_location_id' => $r->check_out_location_id,
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
        // Backfilled sessions are removed with the table drop in the create migration.
    }
};
