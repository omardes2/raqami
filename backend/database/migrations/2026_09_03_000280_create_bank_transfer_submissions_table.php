<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank-transfer proof submissions awaiting platform review. The receipt lives on
 * a PRIVATE disk (proof_storage_key only). A platform admin approves/rejects;
 * approval creates the payment transactionally. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfer_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('invoice_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('transfer_reference')->nullable();
            $table->string('proof_storage_key');    // private storage key, never public
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->bigInteger('size');
            $table->string('status', 20)->default('pending_review'); // pending_review|approved|rejected
            $table->ulid('submitted_by_user_id')->nullable();
            $table->ulid('reviewed_by_platform_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->ulid('payment_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_submissions');
    }
};
