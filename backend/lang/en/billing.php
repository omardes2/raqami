<?php

return [
    // Entitlements / employee limit
    'employee_limit_reached' => 'Your plan allows a maximum of :limit employees. Upgrade your plan to add more.',

    // Coupons
    'coupon_invalid' => 'This coupon code is invalid.',
    'coupon_not_started' => 'This coupon is not active yet.',
    'coupon_expired' => 'This coupon has expired.',
    'coupon_exhausted' => 'This coupon has reached its redemption limit.',
    'coupon_plan_mismatch' => 'This coupon does not apply to the selected plan.',
    'coupon_currency_mismatch' => 'This coupon cannot be used with the selected currency.',
    'coupon_tenant_limit' => 'You have already used this coupon the maximum number of times.',

    // Payments / invoices
    'amount_must_be_positive' => 'The payment amount must be greater than zero.',
    'currency_mismatch' => 'The payment currency must match the invoice currency.',
    'invoice_not_payable' => 'This invoice cannot be paid.',
    'overpayment_rejected' => 'The payment exceeds the amount due on this invoice.',
    'invoice_line_plan' => ':plan (:interval)',

    // Subscription flow
    'subscription_exists' => 'This company already has a subscription.',
    'no_subscription' => 'This company does not have a subscription yet.',
    'plan_not_available' => 'The selected plan is not available.',
    'nothing_to_pay' => 'There is no outstanding balance to pay.',

    // Bank transfer
    'bank_account_unavailable' => 'No bank account is configured for this currency.',
    'proof_required' => 'A payment proof file is required.',
    'transfer_not_pending' => 'This bank transfer has already been reviewed.',
];
