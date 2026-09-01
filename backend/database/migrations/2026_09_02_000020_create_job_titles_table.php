<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reusable job titles / positions. Organizational metadata only — NO salary.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('title');
            $table->string('code', 64);
            $table->string('description')->nullable();
            $table->string('level', 40)->nullable(); // level/grade (metadata only)
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
