<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Roles are tenant-owned. Default/system roles are seeded per tenant on
// creation. role_permission is the tenant-owned pivot to the global catalog.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->string('slug', 64);
            $table->boolean('is_system')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            // Pivot: composite primary key (no surrogate id needed).
            $table->ulid('tenant_id'); // denormalized for RLS isolation
            $table->ulid('role_id');
            $table->ulid('permission_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
    }
};
