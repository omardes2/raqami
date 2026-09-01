<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\BankTransferSubmitRequest;
use App\Modules\Billing\Http\Resources\BankAccountResource;
use App\Modules\Billing\Http\Resources\BankTransferResource;
use App\Modules\Billing\Models\BankAccount;
use App\Modules\Billing\Models\BankTransferSubmission;
use App\Modules\Billing\Services\BankTransferService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tenant bank-transfer flow (spec §13): view active bank accounts, submit proof
 * for an invoice, and track review status. Proof files live on a PRIVATE disk;
 * downloads are authorized + streamed (never a public URL).
 */
class BankTransferController extends Controller
{
    public function __construct(private readonly BankTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $page = BankTransferSubmission::query()
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json(BankTransferResource::collection($page)->response()->getData(true));
    }

    /** Active bank accounts suitable for a currency (transfer instructions). */
    public function bankAccounts(Request $request): JsonResponse
    {
        $query = BankAccount::query()->where('status', 'active');
        if ($currency = $request->query('currency')) {
            $query->where('currency', $currency);
        }

        return response()->json(['data' => BankAccountResource::collection($query->orderBy('label')->get())->resolve()]);
    }

    public function store(BankTransferSubmitRequest $request, TenantContext $context): JsonResponse
    {
        $file = $request->file('proof');
        $key = sprintf(
            'tenants/%s/bank-transfers/%s_%s.%s',
            $context->tenantId(),
            (string) Str::ulid(),
            Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)),
            $file->getClientOriginalExtension(),
        );
        Storage::disk($this->disk())->putFileAs('', $file, $key, ['visibility' => 'private']);

        $submission = $this->transfers->submit([
            'invoice_id' => $request->validated('invoice_id'),
            'amount_minor' => (int) $request->validated('amount_minor'),
            'currency' => $request->validated('currency'),
            'transfer_reference' => $request->validated('transfer_reference'),
            'proof_storage_key' => $key,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
        ], $request->user());

        return (new BankTransferResource($submission))->response()->setStatusCode(201);
    }

    /** Authorized, streamed proof download (tenant owns it via RLS scope). */
    public function downloadProof(BankTransferSubmission $bankTransfer)
    {
        return Storage::disk($this->disk())->download($bankTransfer->proof_storage_key, $bankTransfer->original_filename);
    }

    private function disk(): string
    {
        return config('filesystems.default', 'local');
    }
}
