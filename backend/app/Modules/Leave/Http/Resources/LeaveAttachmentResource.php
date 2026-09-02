<?php

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeaveRequestAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveRequestAttachment
 *
 * Never exposes storage_key; downloads go via the authorized download route.
 */
class LeaveAttachmentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_request_id' => $this->leave_request_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'category' => $this->category,
            'created_at' => $this->created_at?->toISOString(),
            'download_url' => url("/api/leave/requests/{$this->leave_request_id}/attachments/{$this->id}/download"),
        ];
    }
}
