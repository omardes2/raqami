<?php

namespace App\Modules\Billing\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Http\Requests\BankAccountRequest;
use App\Modules\Billing\Http\Resources\BankAccountResource;
use App\Modules\Billing\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** Super Admin bank-account configuration (platform-global). */
class PlatformBankAccountController extends Controller
{
    private function admin()
    {
        return Auth::guard('platform')->user();
    }

    public function index(): JsonResponse
    {
        // internal_notes surfaced to platform admins only (hidden from tenants).
        $accounts = BankAccount::query()->orderBy('label')->get()
            ->map(fn (BankAccount $a) => array_merge(
                (new BankAccountResource($a))->resolve(request()),
                ['internal_notes' => $a->internal_notes],
            ));

        return response()->json(['data' => $accounts]);
    }

    public function store(BankAccountRequest $request, AuditLogger $audit): JsonResponse
    {
        $account = BankAccount::query()->create($request->validated());
        $audit->log('bank_account.created', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $account,
            'metadata' => ['label' => $account->label, 'currency' => $account->currency]]);

        return (new BankAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(BankAccountRequest $request, BankAccount $bankAccount, AuditLogger $audit): BankAccountResource
    {
        $bankAccount->update($request->validated());
        $audit->log('bank_account.updated', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $bankAccount,
            'metadata' => ['fields' => array_keys($request->validated())]]);

        return new BankAccountResource($bankAccount);
    }

    public function archive(BankAccount $bankAccount, AuditLogger $audit): JsonResponse
    {
        $bankAccount->update(['status' => 'archived']);
        $audit->log('bank_account.archived', ['actor' => $this->admin(), 'tenant_id' => null, 'subject' => $bankAccount]);

        return response()->json(['id' => $bankAccount->id, 'status' => $bankAccount->status]);
    }
}
