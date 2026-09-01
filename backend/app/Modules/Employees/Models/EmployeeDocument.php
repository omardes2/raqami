<?php

namespace App\Modules\Employees\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'employee_id', 'category', 'title', 'storage_key',
        'original_filename', 'mime_type', 'size', 'issued_at', 'expires_at',
        'notes', 'uploaded_by_user_id',
    ];

    // storage_key is never serialized to clients (downloads go via signed URLs).
    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'size' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
