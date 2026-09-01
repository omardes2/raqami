<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generic idempotency guard for financial operations (spec §28). The first call
 * for a (scope, key) claims the record and runs the callback; any duplicate is a
 * no-op that returns null. The claim uses an atomic INSERT so concurrent
 * duplicates cannot both proceed. Callers still combine this with row locks /
 * state checks for full safety.
 */
class IdempotencyService
{
    /**
     * Run $callback at most once for (scope, key). Returns the callback result on
     * the first execution, or null if this (scope, key) was already processed.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T|null
     */
    public function once(string $scope, string $key, callable $callback): mixed
    {
        if (! $this->claim($scope, $key)) {
            return null;
        }

        return $callback();
    }

    /** Attempt to atomically claim (scope, key). False if already claimed. */
    public function claim(string $scope, string $key): bool
    {
        $inserted = DB::table('idempotency_records')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'scope' => $scope,
            'idempotency_key' => $key,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted > 0;
    }

    public function seen(string $scope, string $key): bool
    {
        return IdempotencyRecord::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->exists();
    }
}
