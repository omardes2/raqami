<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cross-entity FKs deferred until all referenced tables exist (avoids circular
// create-order problems between employees <-> departments/teams).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('direct_manager_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('parent_department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('manager_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreign('team_lead_employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->dropForeign(['department_id']);
            $t->dropForeign(['direct_manager_employee_id']);
        });
        Schema::table('departments', function (Blueprint $t) {
            $t->dropForeign(['parent_department_id']);
            $t->dropForeign(['manager_employee_id']);
        });
        Schema::table('teams', fn (Blueprint $t) => $t->dropForeign(['team_lead_employee_id']));
    }
};
