<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe inbox representation. Deliberately omits tenant_id, recipient_user_id and
 * dedupe_key. The payload is exposed only as a stable translation key + params
 * (the frontend renders the localized text); no pre-rendered message, no subject
 * internals beyond the metadata type/id.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title_key' => $data['key'] ?? null,
            'params' => $data['params'] ?? [],
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
