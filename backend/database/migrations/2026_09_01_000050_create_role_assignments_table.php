<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assigns a role to a user WITHIN an organizational scope (ADR-015).
// Company scope works now. branch/department/team scope_ids are stored without
// a foreign key because those business tables do not exist yet (no fake FKs).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('user_id');
            $table->ulid('role_id');
            $table->string('scope_type', 20)->default('company'); // company|branch|department|team
            $table->ulid('scope_id')->nullable(); // null for company scope
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'user_id', 'role_id', 'scope_type', 'scope_id'], 'role_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
