<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-wide task status catalog. `category` is the fixed semantic truth
 * (backlog|todo|in_progress|blocked|done|cancelled) — there is NO is_terminal
 * column (Correction E). Tenants may customize name/code/color/sort_order and add
 * multiple statuses per category. `bootstrap_key` is the immutable system
 * identity used for idempotent bootstrap (§9), independent of the editable code.
 * Exactly one active default per tenant (partial unique below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->string('code', 64);
            $table->string('bootstrap_key', 32)->nullable(); // todo|in_progress|blocked|done|cancelled
            $table->string('category', 20);                  // semantic truth
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active', 'sort_order']);
        });

        DB::statement("ALTER TABLE task_statuses ADD CONSTRAINT task_statuses_category_chk CHECK (category IN ('backlog','todo','in_progress','blocked','done','cancelled'))");
        // System bootstrap identity is unique per tenant (idempotent bootstrap).
        DB::statement('CREATE UNIQUE INDEX task_statuses_bootstrap_unique ON task_statuses (tenant_id, bootstrap_key) WHERE bootstrap_key IS NOT NULL');
        // At most one ACTIVE default per tenant (service guarantees at least one).
        DB::statement('CREATE UNIQUE INDEX task_statuses_one_default ON task_statuses (tenant_id) WHERE is_default = true AND active = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_statuses');
    }
};
