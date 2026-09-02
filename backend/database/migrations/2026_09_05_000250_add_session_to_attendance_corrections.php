<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make attendance corrections SESSION-AWARE. Sprint 4 promotes attendance_sessions
 * to the authoritative punch state; a punch-time correction must therefore target
 * a specific session on a multi-session day. This adds a nullable
 * attendance_session_id so:
 *  - legacy Sprint 3 corrections keep attendance_session_id = null (still valid),
 *  - single-session days auto-resolve the one session,
 *  - multi-session days require an explicit target,
 *  - a correction that introduces attendance on a session-less (materialized)
 *    record creates its session on approval.
 * Additive + nullable; nullOnDelete keeps the correction row if the session is
 * later removed. attendance_corrections already carries tenant_id + RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->ulid('attendance_session_id')->nullable()->after('attendance_record_id');
            $table->foreign('attendance_session_id')->references('id')->on('attendance_sessions')->nullOnDelete();
            $table->index(['tenant_id', 'attendance_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropForeign(['attendance_session_id']);
            $table->dropIndex(['tenant_id', 'attendance_session_id']);
            $table->dropColumn('attendance_session_id');
        });
    }
};
