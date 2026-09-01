<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 policy columns on the per-tenant attendance settings. All additive
 * and defaulted, so existing Sprint 3 rows keep working unchanged. These drive
 * daily materialization, split shifts, overtime approval, off-day work, and the
 * anomaly thresholds — never hard-coded, always tenant-configurable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // --- Daily materialization ---
            $table->boolean('materialization_enabled')->default(true)->after('allow_unscheduled_work');
            // Minutes after the scheduled segment start before an unpunched
            // expected employee is marked absent (never at midnight).
            $table->unsignedInteger('absence_materialize_after_minutes')->default(120)->after('materialization_enabled');

            // --- Split shifts ---
            $table->boolean('allow_multiple_sessions')->default(true)->after('absence_materialize_after_minutes');

            // --- Missing checkout / auto-close ---
            $table->boolean('auto_close_missing_checkout')->default(false)->after('allow_multiple_sessions');
            // Buffer after the scheduled segment end for a synthetic auto-close.
            $table->unsignedInteger('auto_close_after_minutes')->default(120)->after('auto_close_missing_checkout');

            // --- Overtime approval ---
            $table->boolean('overtime_requires_approval')->default(true)->after('auto_close_after_minutes');
            $table->boolean('overtime_auto_approve')->default(false)->after('overtime_requires_approval');

            // --- Off-day / attendance mode ---
            // reject | allow | require_approval
            $table->string('off_day_work_policy', 20)->default('reject')->after('overtime_auto_approve');
            // onsite | remote | field
            $table->string('default_attendance_mode', 20)->default('onsite')->after('off_day_work_policy');

            // --- Anomaly thresholds (nullable = rule disabled) ---
            $table->unsignedInteger('anomaly_max_session_minutes')->nullable()->after('default_attendance_mode');
            $table->unsignedInteger('anomaly_gps_jump_meters')->nullable()->after('anomaly_max_session_minutes');
            $table->unsignedInteger('anomaly_lateness_streak_days')->nullable()->after('anomaly_gps_jump_meters');
            $table->unsignedInteger('anomaly_corrections_threshold')->nullable()->after('anomaly_lateness_streak_days');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'materialization_enabled', 'absence_materialize_after_minutes',
                'allow_multiple_sessions', 'auto_close_missing_checkout', 'auto_close_after_minutes',
                'overtime_requires_approval', 'overtime_auto_approve',
                'off_day_work_policy', 'default_attendance_mode',
                'anomaly_max_session_minutes', 'anomaly_gps_jump_meters',
                'anomaly_lateness_streak_days', 'anomaly_corrections_threshold',
            ]);
        });
    }
};
