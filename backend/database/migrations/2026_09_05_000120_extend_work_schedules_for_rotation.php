<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rotation foundation on work_schedules. A schedule is WEEKLY when
 * cycle_length_days is null (work_schedule_days.weekday = 0..6, Sprint 3
 * behavior, unchanged). It is CYCLIC when cycle_length_days is set: the resolver
 * maps a work_date to a day-index 0..(cycle_length-1) using anchor_date, and
 * work_schedule_days.weekday is reinterpreted as that day-index. No second
 * assignment mechanism — org precedence + effective dates still apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->unsignedSmallInteger('cycle_length_days')->nullable()->after('overtime_after_minutes');
            $table->date('anchor_date')->nullable()->after('cycle_length_days');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', function (Blueprint $table) {
            $table->dropColumn(['cycle_length_days', 'anchor_date']);
        });
    }
};
