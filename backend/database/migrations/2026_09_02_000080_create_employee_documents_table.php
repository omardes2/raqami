<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employee document METADATA. Files live in private S3-compatible storage;
// only storage keys are stored here. Never a public path.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('employee_id');
            $table->string('category', 40)->default('other'); // contract|id|certificate|cv|policy|other
            $table->string('title');
            $table->string('storage_key');       // private disk key, never public URL
            $table->string('original_filename');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size');
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('notes')->nullable();
            $table->ulid('uploaded_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
