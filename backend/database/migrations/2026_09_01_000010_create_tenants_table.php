<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The tenant/company REGISTRY. This table is NOT tenant-owned (it lists tenants)
// so it carries no tenant_id and no RLS. Only the platform and a tenant's own
// members (via app logic) read it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');                      // display name
            $table->string('legal_name')->nullable();    // legal/registered name
            $table->string('slug')->unique();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('default_locale', 8)->default('en');
            $table->char('default_currency', 3)->default('USD');
            // Sprint 0 has no billing; lifecycle still modelled for later.
            $table->string('status', 20)->default('active'); // trialing|active|suspended|cancelled
            $table->ulid('owner_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes(); // deletion never bypasses retention/audit casually

            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
