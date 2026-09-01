<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Infrastructure idempotency guard (scope + key unique). Not tenant-owned. */
class IdempotencyRecord extends Model
{
    use HasUlids;

    protected $fillable = ['scope', 'idempotency_key', 'status', 'result_reference'];
}
