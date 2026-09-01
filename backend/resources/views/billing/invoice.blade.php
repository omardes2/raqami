@php
    /** @var \App\Modules\Billing\Models\Invoice $invoice */
    $dir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $money = fn ($minor) => number_format(((int) $minor) / 100, 2).' '.$invoice->currency;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: system-ui, 'Segoe UI', Tahoma, Arial, sans-serif; color: #1f2937; margin: 0; padding: 32px; }
        .wrap { max-width: 720px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111827; padding-bottom: 16px; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #6b7280; font-size: 13px; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; background: #eef2ff; color: #3730a3; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { text-align: start; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
        td.num, th.num { text-align: end; }
        .totals { margin-top: 16px; margin-inline-start: auto; width: 260px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; }
        .totals .grand { font-weight: 700; border-top: 2px solid #111827; margin-top: 6px; padding-top: 8px; }
        @media print { body { padding: 0; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>{{ $tenant?->name }}</h1>
            @if ($profile)
                <div class="muted">
                    {{ $profile->legal_name }}<br>
                    {{ $profile->address_line_1 }} {{ $profile->city }} {{ $profile->country_code }}<br>
                    @if ($profile->tax_id) Tax ID: {{ $profile->tax_id }} @endif
                </div>
            @endif
        </div>
        <div style="text-align: end;">
            <div style="font-size: 22px; font-weight: 700;">{{ $invoice->invoice_number }}</div>
            <div class="muted">Issued: {{ optional($invoice->issued_at)->toDateString() }}</div>
            <div class="muted">Due: {{ optional($invoice->due_at)->toDateString() }}</div>
            <div style="margin-top: 6px;"><span class="status">{{ str_replace('_', ' ', $invoice->status->value) }}</span></div>
        </div>
    </header>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $money($item->unit_amount_minor) }}</td>
                    <td class="num">{{ $money($item->subtotal_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>{{ $money($invoice->subtotal_minor) }}</span></div>
        @if ($invoice->discount_minor > 0)
            <div><span>Discount @if ($invoice->coupon_code)({{ $invoice->coupon_code }})@endif</span><span>-{{ $money($invoice->discount_minor) }}</span></div>
        @endif
        @if ($invoice->tax_minor > 0)
            <div><span>{{ $invoice->tax_label ?: 'Tax' }}</span><span>{{ $money($invoice->tax_minor) }}</span></div>
        @endif
        <div class="grand"><span>Total</span><span>{{ $money($invoice->total_minor) }}</span></div>
        <div><span>Paid</span><span>{{ $money($invoice->amount_paid_minor) }}</span></div>
        <div><span>Due</span><span>{{ $money($invoice->amount_due_minor) }}</span></div>
    </div>

    @if ($invoice->notes)
        <p class="muted" style="margin-top: 24px;">{{ $invoice->notes }}</p>
    @endif
</div>
</body>
</html>
