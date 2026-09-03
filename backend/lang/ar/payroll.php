<?php

return [
    // Authorization
    'self_payroll_forbidden' => 'لا يمكنك إدارة بيانات رواتبك الخاصة.',

    // Compensation
    'compensation_overlap' => 'تتداخل فترة هذا التعويض مع فترة أخرى موجودة للموظف.',
    'effective_to_before_from' => 'لا يمكن أن يكون تاريخ الانتهاء قبل تاريخ البدء.',

    // Components
    'component_inactive' => 'هذا المكوّن غير مُفعّل ولا يمكن إسناده.',
    'component_overlap' => 'تتداخل فترة هذا المكوّن مع فترة أخرى موجودة للموظف.',
    'fixed_requires_amount_currency' => 'يتطلب المكوّن الثابت مبلغًا وعملة.',
    'percent_requires_bps' => 'يتطلب المكوّن النسبي نسبة موجبة بوحدة نقاط الأساس.',

    // Periods
    'period_must_start_month' => 'يجب أن تبدأ فترة الرواتب في اليوم الأول من الشهر.',
    'period_must_be_full_month' => 'يجب أن تغطي فترة الرواتب شهرًا ميلاديًا كاملًا.',
    'period_exists' => 'توجد فترة رواتب مسبقًا لهذا الشهر.',
    'period_closed' => 'فترة الرواتب هذه مغلقة.',

    // Runs
    'run_exists' => 'توجد دورة رواتب نشطة مسبقًا لهذه الفترة.',
    'run_not_cancellable' => 'لم يعد بالإمكان إلغاء دورة الرواتب هذه.',

    // Calculation
    'run_not_calculable' => 'لا يمكن احتساب دورة الرواتب هذه من حالتها الحالية.',
    'run_calculation_in_progress' => 'يجري بالفعل احتساب لهذه الدورة.',
];
