<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employee <-> Team (many-to-many). An employee may belong to 0..N teams.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('team_id');
            $table->ulid('employee_id');
            $table->string('role_in_team', 64)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'employee_id']);
            $table->unique(['team_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
    }
};
