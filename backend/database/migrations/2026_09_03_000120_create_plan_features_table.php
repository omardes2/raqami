<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global plan entitlements, kept SEPARATE from price. A feature_key is
 * commercial configuration only — its presence here does NOT imply the module
 * exists yet. limit_value is null for unlimited / boolean features.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('plan_id');
            $table->string('feature_key', 100);
            $table->boolean('enabled')->default(true);
            $table->bigInteger('limit_value')->nullable(); // null = unlimited
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $table->unique(['plan_id', 'feature_key']);
            $table->index('feature_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
