<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The IMMUTABLE leave ledger — the authoritative source of balance truth.
 * Append-only: no updated_at; UPDATE/DELETE are blocked by RLS (no such policy)
 * plus a trigger (see the RLS migration). Corrections are compensating reversal/
 * adjustment rows, never edits. `minutes` is signed relative to the bucket the
 * transaction_type targets. `idempotency_key` (unique per tenant) makes accrual/
 * period processors safe to re-run. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->ulid('leave_type_id');
            $table->ulid('leave_policy_id')->nullable();
            $table->ulid('entitlement_period_id');
            $table->ulid('leave_request_id')->nullable();
            $table->string('transaction_type', 30); // grant|accrual|carry_forward|expiry|reservation|reservation_release|usage|usage_reversal|adjustment|adjustment_reversal
            $table->integer('minutes'); // signed relative to bucket
            $table->date('effective_date');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only: no updated_at

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->cascadeOnDelete();
            $table->foreign('entitlement_period_id')->references('id')->on('leave_entitlement_periods')->cascadeOnDelete();
            $table->index(['tenant_id', 'employee_id', 'leave_type_id', 'entitlement_period_id'], 'leave_ledger_period_idx');
            $table->index(['tenant_id', 'leave_request_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Idempotency: at most one ledger row per (tenant, idempotency_key).
            DB::statement(
                'CREATE UNIQUE INDEX leave_ledger_idempotency ON leave_balance_transactions (tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_transactions');
    }
};
