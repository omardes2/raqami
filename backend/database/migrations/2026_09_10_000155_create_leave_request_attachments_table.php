<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private attachment metadata for a leave request (e.g. medical certificate).
 * The binary lives on a private S3-compatible disk; only storage_key is stored
 * (hidden from serialization). Downloads are authorized + streamed / signed —
 * never a public URL. Sensitive (medical) access is gated separately by
 * leave.attachments.view_sensitive. Tenant-owned (tenant_id + RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('leave_request_id');
            $table->string('storage_key');
            $table->string('original_filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('category', 30)->nullable(); // medical_certificate|other
            $table->ulid('uploaded_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
            $table->index(['tenant_id', 'leave_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_attachments');
    }
};
