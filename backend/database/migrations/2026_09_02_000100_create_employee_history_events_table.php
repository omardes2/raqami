<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-oriented HR/business timeline (distinct from the security Audit Log).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_history_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->string('event_type', 40); // hired|branch_changed|department_changed|team_changed|job_title_changed|manager_changed|status_changed|employment_type_changed|user_linked|user_unlinked|terminated|reactivated
            $table->date('effective_date')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->jsonb('metadata')->nullable(); // from/to values; never secrets
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_history_events');
    }
};
