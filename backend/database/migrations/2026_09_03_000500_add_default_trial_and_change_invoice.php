<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial hardening additions:
 * - plans.is_default_trial: marks the single platform-configured default trial
 *   plan used to bootstrap a new tenant's trial (fail-closed entitlements). A
 *   partial unique index enforces "at most one" default trial plan.
 * - subscription_changes.invoice_id: links a pending upgrade/reactivation change
 *   to the invoice whose full payment applies it (no unpaid entitlement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_default_trial')->default(false)->after('is_featured');
        });

        // At most one default trial plan (partial unique index; PostgreSQL).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX plans_one_default_trial ON plans ((is_default_trial)) WHERE is_default_trial = true');
        }

        Schema::table('subscription_changes', function (Blueprint $table) {
            $table->ulid('invoice_id')->nullable()->after('subscription_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS plans_one_default_trial');
        }
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('is_default_trial'));
        Schema::table('subscription_changes', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
    }
};
