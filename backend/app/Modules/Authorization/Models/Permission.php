<?php

namespace App\Modules\Authorization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Global permission catalog entry (not tenant-owned). */
class Permission extends Model
{
    use HasUlids;

    protected $fillable = ['key', 'module', 'description'];
}
