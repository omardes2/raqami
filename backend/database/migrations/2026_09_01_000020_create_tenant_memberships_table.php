<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Membership links a global user to a tenant (many-to-many). Tenant-owned, so
// RLS applies. Invitations are modelled here (status=invited + token).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('user_id')->nullable(); // null until an invite is accepted
            $table->string('status', 20)->default('active'); // active|invited|disabled
            $table->string('invited_email')->nullable();
            $table->ulid('invited_by')->nullable();
            $table->string('invitation_token', 64)->nullable()->unique();
            $table->timestamp('invitation_accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
