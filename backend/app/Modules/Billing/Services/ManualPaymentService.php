<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Records an offline (cash / manual / externally-verified bank) payment against
 * a tenant invoice (spec §14). ONLY a platform admin performs this — normal
 * tenant users can never manufacture a succeeded payment. Runs inside the
 * tenant's context so RLS is satisfied; application is transactional.
 */
class ManualPaymentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array{tenant_id:string, invoice_id:string, amount_minor:int,
     *   currency?:string, method?:string, reference?:?string, notes?:?string}  $data
     */
    public function record(array $data, PlatformAdmin $admin): Payment
    {
        return $this->context->runAs($data['tenant_id'], function () use ($data, $admin) {
            return DB::transaction(function () use ($data, $admin) {
                $invoice = Invoice::query()->whereKey($data['invoice_id'])->firstOrFail();
                $method = PaymentMethod::from($data['method'] ?? 'manual');

                return $this->payments->applyToInvoice($invoice, [
                    'amount_minor' => (int) $data['amount_minor'],
                    'currency' => $data['currency'] ?? $invoice->currency,
                    'method' => $method,
                    'reference' => $data['reference'] ?? null,
                    'approved_by_platform_admin_id' => (string) $admin->getKey(),
                    'audit_action' => 'payment.recorded',
                    'metadata' => ['source' => 'platform_manual', 'notes' => $data['notes'] ?? null],
                ], $admin);
            });
        });
    }
}
