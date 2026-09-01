<?php

namespace App\Modules\Employees\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeDocument;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Employee document handling on a PRIVATE, S3-compatible storage abstraction.
 * Files are never public: keys are tenant-prefixed, downloads are authorized and
 * streamed (or issued as short-lived signed URLs on S3). Only metadata is
 * stored in the database.
 */
class EmployeeDocumentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    private function disk(): string
    {
        return config('filesystems.default', 'local');
    }

    public function store(Employee $employee, UploadedFile $file, array $data, mixed $actor = null): EmployeeDocument
    {
        $tenantId = $this->context->tenantId();
        $key = sprintf(
            'tenants/%s/employees/%s/%s_%s',
            $tenantId,
            $employee->getKey(),
            (string) Str::ulid(),
            Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension()
        );

        // Private visibility — never public.
        Storage::disk($this->disk())->putFileAs('', $file, $key, ['visibility' => 'private']);

        $document = EmployeeDocument::create([
            'employee_id' => $employee->getKey(),
            'category' => $data['category'] ?? 'other',
            'title' => $data['title'],
            'storage_key' => $key,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'uploaded_by_user_id' => Auth::id(),
        ]);

        // Audit records the action + metadata only — never file contents.
        $this->audit->log('employee_document.uploaded', [
            'actor' => $actor,
            'subject' => $document,
            'metadata' => ['employee_id' => $employee->getKey(), 'category' => $document->category],
        ]);

        return $document;
    }

    /** Streamed, authorized download (authorization happens in the controller). */
    public function download(EmployeeDocument $document, mixed $actor = null)
    {
        $this->audit->log('employee_document.downloaded', [
            'actor' => $actor,
            'subject' => $document,
        ]);

        return Storage::disk($this->disk())->download($document->storage_key, $document->original_filename);
    }

    /**
     * Short-lived access reference. On S3 this is a temporary signed storage URL;
     * on local/other disks it is null (the SPA uses the authorized download
     * endpoint). Never a permanent/public URL.
     */
    public function temporaryUrl(EmployeeDocument $document): ?string
    {
        $disk = Storage::disk($this->disk());

        try {
            if (method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl($document->storage_key, now()->addMinutes(5));
            }
        } catch (\Throwable) {
            // Disk does not support temporary URLs (e.g. local) — fall through.
        }

        return null;
    }

    public function delete(EmployeeDocument $document, mixed $actor = null): void
    {
        Storage::disk($this->disk())->delete($document->storage_key);

        $meta = ['employee_id' => $document->employee_id, 'title' => $document->title];
        $document->delete();

        $this->audit->log('employee_document.deleted', [
            'actor' => $actor,
            'subject_type' => EmployeeDocument::class,
            'subject_id' => $document->getKey(),
            'metadata' => $meta,
        ]);
    }
}
