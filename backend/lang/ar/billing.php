<?php

return [
    // Entitlements / employee limit
    'employee_limit_reached' => 'تسمح خطتك بحد أقصى :limit موظفًا. قم بترقية خطتك لإضافة المزيد.',
    'subscription_required' => 'يلزم وجود اشتراك نشط أو فترة تجريبية لاستخدام هذه الميزة. يرجى اختيار خطة من صفحة الفوترة.',

    // Coupons
    'coupon_invalid' => 'رمز القسيمة غير صالح.',
    'coupon_not_started' => 'هذه القسيمة غير مفعّلة بعد.',
    'coupon_expired' => 'انتهت صلاحية هذه القسيمة.',
    'coupon_exhausted' => 'وصلت هذه القسيمة إلى حد الاستخدام المسموح.',
    'coupon_plan_mismatch' => 'لا تنطبق هذه القسيمة على الخطة المختارة.',
    'coupon_currency_mismatch' => 'لا يمكن استخدام هذه القسيمة مع العملة المختارة.',
    'coupon_tenant_limit' => 'لقد استخدمت هذه القسيمة الحد الأقصى المسموح من المرات.',

    // Payments / invoices
    'amount_must_be_positive' => 'يجب أن يكون مبلغ الدفع أكبر من صفر.',
    'currency_mismatch' => 'يجب أن تطابق عملة الدفع عملة الفاتورة.',
    'invoice_not_payable' => 'لا يمكن دفع هذه الفاتورة.',
    'overpayment_rejected' => 'يتجاوز المبلغ المدفوع المبلغ المستحق على هذه الفاتورة.',
    'invoice_line_plan' => ':plan (:interval)',

    // Subscription flow
    'subscription_exists' => 'لدى هذه الشركة اشتراك بالفعل.',
    'no_subscription' => 'لا يوجد اشتراك لهذه الشركة بعد.',
    'plan_not_available' => 'الخطة المختارة غير متاحة.',
    'nothing_to_pay' => 'لا يوجد رصيد مستحق للدفع.',
    'currency_change_unsupported' => 'الانتقال إلى خطة بعملة مختلفة غير مدعوم.',
    'subscription_terminal' => 'انتهى هذا الاشتراك. ابدأ اشتراكًا جديدًا للمتابعة.',
    'no_pending_cancellation' => 'لا يوجد إلغاء مجدول لاستئنافه.',
    'not_terminal_for_reactivation' => 'هذا الاشتراك ما زال نشطًا؛ لا حاجة لإعادة التفعيل.',

    // Bank transfer
    'bank_account_unavailable' => 'لا يوجد حساب بنكي مهيأ لهذه العملة.',
    'proof_required' => 'يلزم إرفاق ملف إثبات الدفع.',
    'transfer_not_pending' => 'تمت مراجعة هذا التحويل البنكي بالفعل.',
];
