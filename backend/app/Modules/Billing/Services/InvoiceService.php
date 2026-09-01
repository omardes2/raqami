<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

/**
 * Builds and maintains invoices. ALL money is computed here server-side from the
 * line items — the client never supplies totals (spec §9, §10, §31). Discount
 * and tax are applied as: total = subtotal − discount + tax, where
 * tax = round((subtotal − discount) * tax_rate%). Money is integer minor units.
 */
class InvoiceService
{
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    /**
     * @param  array{
     *   currency:string,
     *   items:array<int,array{description:string,quantity?:int,unit_amount_minor:int,metadata?:array}>,
     *   subscription_id?:?string,
     *   discount_minor?:int,
     *   coupon_id?:?string,
     *   coupon_code?:?string,
     *   tax_rate?:?float,
     *   tax_label?:?string,
     *   due_at?:?\DateTimeInterface,
     *   billing_period_start?:?\DateTimeInterface,
     *   billing_period_end?:?\DateTimeInterface,
     *   notes?:?string,
     *   issue?:bool
     * }  $data
     */
    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            $subtotal = 0;
            foreach ($items as $item) {
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $subtotal += $qty * (int) $item['unit_amount_minor'];
            }

            $discount = max(0, (int) ($data['discount_minor'] ?? 0));
            $discount = min($discount, $subtotal); // never discount below zero
            $taxable = $subtotal - $discount;

            $taxRate = $data['tax_rate'] ?? null;
            $tax = $taxRate !== null ? (int) round($taxable * ((float) $taxRate) / 100) : 0;

            $total = $taxable + $tax;
            $issue = (bool) ($data['issue'] ?? true);

            $invoice = Invoice::query()->create([
                'subscription_id' => $data['subscription_id'] ?? null,
                'invoice_number' => $this->numbers->next(),
                'status' => $issue ? InvoiceStatus::Issued : InvoiceStatus::Draft,
                'currency' => $data['currency'],
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discount,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'amount_paid_minor' => 0,
                'amount_due_minor' => $total,
                'tax_rate' => $taxRate,
                'tax_label' => $data['tax_label'] ?? null,
                'coupon_id' => $data['coupon_id'] ?? null,
                'coupon_code' => $data['coupon_code'] ?? null,
                'issued_at' => $issue ? now() : null,
                'due_at' => $data['due_at'] ?? null,
                'billing_period_start' => $data['billing_period_start'] ?? null,
                'billing_period_end' => $data['billing_period_end'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $unit = (int) $item['unit_amount_minor'];
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->getKey(),
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_amount_minor' => $unit,
                    'subtotal_minor' => $qty * $unit,
                    'metadata' => $item['metadata'] ?? null,
                ]);
            }

            return $invoice->fresh('items');
        });
    }

    /**
     * Apply a successful payment amount to an invoice and recompute its balance
     * and status. Must be called inside a transaction with the invoice locked.
     */
    public function applyPaymentAmount(Invoice $invoice, int $amountMinor): Invoice
    {
        $paid = $invoice->amount_paid_minor + $amountMinor;
        $invoice->amount_paid_minor = $paid;
        $invoice->amount_due_minor = max(0, $invoice->total_minor - $paid);

        if ($invoice->amount_due_minor === 0) {
            $invoice->status = InvoiceStatus::Paid;
            $invoice->paid_at = now();
        } elseif ($paid > 0) {
            $invoice->status = InvoiceStatus::PartiallyPaid;
        }

        $invoice->save();

        return $invoice;
    }

    public function markOverdue(Invoice $invoice): Invoice
    {
        if ($invoice->status->isPayable()) {
            $invoice->status = InvoiceStatus::Overdue;
            $invoice->save();
        }

        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        $invoice->status = InvoiceStatus::Void;
        $invoice->save();

        return $invoice;
    }
}
