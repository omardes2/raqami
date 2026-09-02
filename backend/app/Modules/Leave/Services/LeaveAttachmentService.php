<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private attachment storage for leave requests, reusing the Sprint 1/2 pattern:
 * a private S3-compatible disk, tenant-prefixed keys, metadata-only rows
 * (storage_key hidden), authorized streamed downloads / short-lived signed URLs,
 * never a public URL, no binary in the DB. Audits metadata only — never contents.
 */
class LeaveAttachmentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function store(LeaveRequest $request, UploadedFile $file, ?string $category, mixed $actor = null): LeaveRequestAttachment
    {
        $key = sprintf(
            'tenants/%s/leave/%s/%s_%s',
            $this->context->tenantId(),
            $request->getKey(),
            (string) Str::ulid(),
            Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension(),
        );

        Storage::disk($this->disk())->putFileAs('', $file, $key, ['visibility' => 'private']);

        $attachment = LeaveRequestAttachment::query()->create([
            'leave_request_id' => $request->getKey(),
            'storage_key' => $key,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'category' => $category,
            'uploaded_by_user_id' => $actor?->getKey(),
        ]);

        $this->audit->log('leave.attachment_uploaded', [
            'actor' => $actor,
            'subject' => $attachment,
            'metadata' => [
                'leave_request_id' => (string) $request->getKey(),
                'original_filename' => $file->getClientOriginalName(),
                'category' => $category,
            ],
        ]);

        return $attachment;
    }

    public function download(LeaveRequestAttachment $attachment): StreamedResponse
    {
        return Storage::disk($this->disk())->download($attachment->storage_key, $attachment->original_filename);
    }

    /** Short-lived signed URL on S3, or null on local (SPA uses the download route). */
    public function temporaryUrl(LeaveRequestAttachment $attachment): ?string
    {
        $disk = Storage::disk($this->disk());

        if (method_exists($disk, 'temporaryUrl')) {
            return $disk->temporaryUrl($attachment->storage_key, now()->addMinutes(5));
        }

        return null;
    }

    public function delete(LeaveRequestAttachment $attachment, mixed $actor = null): void
    {
        Storage::disk($this->disk())->delete($attachment->storage_key);

        $this->audit->log('leave.attachment_deleted', [
            'actor' => $actor,
            'subject' => $attachment,
            'metadata' => [
                'leave_request_id' => (string) $attachment->leave_request_id,
                'original_filename' => $attachment->original_filename,
            ],
        ]);

        $attachment->delete();
    }

    private function disk(): string
    {
        return config('filesystems.default', 'local');
    }
}
