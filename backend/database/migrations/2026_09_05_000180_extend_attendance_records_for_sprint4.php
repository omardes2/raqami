<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 evolves attendance_records into the daily AGGREGATE of its sessions.
 * check_in_at stays the first check-in, check_out_at the last check-out; the
 * minute columns become session sums. Adds:
 *  - version: optimistic-concurrency counter (bumped on every mutation), so a
 *    correction can detect the record changed since the request was made.
 *  - attendance_mode: onsite|remote|field context for the day.
 *  - is_materialized / materialized_at: set when the daily processor derived the
 *    state (absent/weekend/holiday/incomplete) rather than a real punch.
 *  - holiday_id: the resolved holiday when the day is a holiday.
 * The Sprint 3 "one open record per employee" index is replaced by the
 * per-session open index (multiple closed sessions may now share a work_date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('corrected_at');
            $table->string('attendance_mode', 20)->default('onsite')->after('version');
            $table->boolean('is_materialized')->default(false)->after('attendance_mode');
            $table->timestamp('materialized_at')->nullable()->after('is_materialized');
            $table->ulid('holiday_id')->nullable()->after('materialized_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Open state now lives on attendance_sessions.
            DB::statement('DROP INDEX IF EXISTS attendance_one_open_per_employee');
        }
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['version', 'attendance_mode', 'is_materialized', 'materialized_at', 'holiday_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX attendance_one_open_per_employee ON attendance_records (tenant_id, employee_id) WHERE check_out_at IS NULL'
            );
        }
    }
};
