<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global subscription plans (NOT tenant-owned). Plans are shared
 * commercial configuration managed by Super Admin — never duplicated per tenant
 * and never carrying tenant_id / RLS. Money is stored in integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('description')->nullable();
            $table->string('status', 20)->default('draft');        // draft|active|archived
            $table->string('visibility', 20)->default('public');   // public|private|enterprise_only
            $table->bigInteger('monthly_price_minor')->default(0); // integer minor units
            $table->bigInteger('annual_price_minor')->default(0);
            $table->char('currency', 3)->default('USD');           // ISO 4217
            $table->unsignedInteger('trial_days')->default(14);
            $table->integer('employee_limit')->nullable();         // null = unlimited
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index('status');
            $table->index('visibility');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
