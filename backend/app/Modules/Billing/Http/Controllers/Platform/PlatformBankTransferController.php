<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Enums\BankTransferStatus;
use App\Modules\Billing\Http\Resources\BankTransferResource;
use App\Modules\Billing\Http\Resources\PaymentResource;
use App\Modules\Billing\Models\BankTransferSubmission;
use App\Modules\Billing\Services\BankTransferService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Super Admin bank-transfer review queue (spec §22). Reads are through the
 * audited platform read-only context; approval/rejection are performed by the
 * service inside the submission's tenant context (RLS-safe) with a row lock and
 * status guard that prevents double approval.
 */
class PlatformBankTransferController extends Controller
{
    public function __construct(private readonly BankTransferService $transfers) {}

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $status = $request->query('status', BankTransferStatus::PendingReview->value);
        $data = $context->runAsPlatform(fn () => BankTransferResource::collection(
            BankTransferSubmission::query()
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderBy('created_at')->limit(200)->get()
        )->resolve());

        return response()->json(['data' => $data]);
    }

    public function approve(string $submission, TenantContext $context): JsonResponse
    {
        $model = $this->find($submission, $context);

        try {
            $payment = $this->transfers->approve($model, Auth::guard('platform')->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => __('billing.transfer_not_pending')], 422);
        }

        return response()->json([
            'submission_id' => $model->id,
            'payment' => (new PaymentResource($payment))->resolve(),
        ]);
    }

    public function reject(string $submission, Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $model = $this->find($submission, $context);

        try {
            $result = $this->transfers->reject($model, Auth::guard('platform')->user(), $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => __('billing.transfer_not_pending')], 422);
        }

        return response()->json(['data' => (new BankTransferResource($result))->resolve()]);
    }

    public function downloadProof(string $submission, TenantContext $context)
    {
        $model = $this->find($submission, $context);

        // The key is read under platform context; the file itself is on the
        // private disk and streamed (never a public URL).
        return $context->runAsPlatform(
            fn () => Storage::disk(config('filesystems.default', 'local'))
                ->download($model->proof_storage_key, $model->original_filename),
        );
    }

    private function find(string $id, TenantContext $context): BankTransferSubmission
    {
        return $context->runAsPlatform(
            fn () => BankTransferSubmission::query()->findOrFail($id),
        );
    }
}
