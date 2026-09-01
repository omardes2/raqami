<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable tenant holiday calendars (e.g. "Palestine Public Holidays",
 * "Jordan Branch Holidays"). A company may keep several. Tenant-owned
 * (tenant_id + RLS). No jurisdiction-specific holiday API — dates are entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->string('code', 64);
            $table->string('description')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendars');
    }
};
