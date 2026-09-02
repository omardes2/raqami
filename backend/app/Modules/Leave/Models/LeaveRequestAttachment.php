<?php

namespace App\Modules\Leave\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private attachment metadata. storage_key is never serialized; downloads go via
 * an authorized streamed/signed route. Tenant-owned (tenant_id + RLS).
 */
class LeaveRequestAttachment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    /** storage_key is never serialized to clients (downloads go via signed/streamed route). */
    protected $hidden = ['storage_key'];

    protected $fillable = [
        'tenant_id', 'leave_request_id', 'storage_key', 'original_filename',
        'mime_type', 'size', 'category', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
