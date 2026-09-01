<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Document metadata only. The storage key is NEVER exposed; downloads go through
 * the authorized download endpoint (download_url), not a public storage URL.
 *
 * @mixin EmployeeDocument
 */
class EmployeeDocumentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'category' => $this->category,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'download_url' => route('employees.documents.download', [
                'employee' => $this->employee_id,
                'document' => $this->id,
            ]),
        ];
    }
}
