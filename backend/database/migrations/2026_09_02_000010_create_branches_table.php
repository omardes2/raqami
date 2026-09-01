<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tenant-owned company branches (locations).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->string('code', 64);
            $table->string('description')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('address_line')->nullable();
            $table->string('timezone', 64)->default('UTC'); // per-branch, not global
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_headquarters')->default(false);
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
