<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Binds a policy to an organizational scope. Resolution precedence mirrors the
 * schedule engine: employee > team > department (subtree) > branch > company,
 * tie-broken by priority desc, effective_from desc, created_at desc, id desc.
 * leave_type_id is denormalized so the resolver can filter by type cheaply.
 * Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policy_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('leave_policy_id');
            $table->ulid('leave_type_id');
            $table->string('scope_type', 20); // company|branch|department|team|employee
            $table->ulid('scope_id')->nullable(); // null for company
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->index(['tenant_id', 'leave_type_id', 'scope_type']);
            $table->index(['tenant_id', 'leave_policy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policy_assignments');
    }
};
