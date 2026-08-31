<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Global permission catalog (not tenant-owned). Only permissions that exist in
// Sprint 0 are seeded; future modules add their own catalog entries.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();      // e.g. company.view
            $table->string('module', 64);          // e.g. organization
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
