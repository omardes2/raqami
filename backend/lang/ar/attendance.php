<?php

return [
    // Eligibility / sessions
    'not_eligible' => 'هذا الموظف غير مؤهل لتسجيل الحضور.',
    'already_open' => 'لدى هذا الموظف حضور مفتوح بالفعل؛ يجب تسجيل الخروج أولاً.',
    'already_recorded_today' => 'تم تسجيل حضور لهذا الموظف في هذا اليوم بالفعل.',
    'no_open' => 'لا يوجد حضور مفتوح لتسجيل الخروج منه.',
    'checkout_before_checkin' => 'لا يمكن أن يكون وقت الخروج قبل وقت الدخول.',
    'checkout_after_checkin' => 'يجب أن يكون وقت الخروج بعد وقت الدخول.',

    // Scheduling
    'no_schedule' => 'لا يوجد جدول عمل مُسنَد لهذا اليوم.',
    'not_working_day' => 'اليوم ليس يوم عمل حسب الجدول.',
    'early_not_allowed' => 'تسجيل الدخول المبكر غير مسموح.',
    'too_early' => 'الوقت مبكر جداً لتسجيل الدخول.',
    'late_not_allowed' => 'تسجيل الدخول المتأخر غير مسموح.',

    // Location / GPS
    'location_required' => 'الموقع الجغرافي مطلوب لتسجيل الدخول.',
    'location_required_out' => 'الموقع الجغرافي مطلوب لتسجيل الخروج.',
    'gps_inaccurate' => 'دقة تحديد الموقع غير كافية لتسجيل الدخول.',
    'gps_inaccurate_out' => 'دقة تحديد الموقع غير كافية لتسجيل الخروج.',
    'outside_geofence' => 'أنت خارج نطاق تسجيل الحضور المسموح به.',

    // Corrections
    'corrections_disabled' => 'تصحيحات الحضور معطّلة لهذه الشركة.',
    'employee_corrections_disabled' => 'طلبات تصحيح الحضور من الموظفين معطّلة.',
    'correction_empty' => 'يجب أن يغيّر التصحيح وقت الدخول و/أو وقت الخروج.',
    'correction_reviewed' => 'تمت مراجعة هذا التصحيح بالفعل.',
    'correction_self' => 'لا يمكنك مراجعة طلب التصحيح الخاص بك.',

    // Schedule assignments
    'assignment_company_no_scope' => 'إسناد على مستوى الشركة يجب ألا يحدّد نطاقاً محدداً.',
    'assignment_scope_required' => 'يجب تحديد نطاق (scope_id) لهذا النوع من الإسناد.',
    'assignment_scope_missing' => 'الهدف المحدّد للنطاق غير موجود في هذه الشركة.',

    // Identity / relations
    'not_linked' => 'حسابك غير مرتبط بسجل موظف.',
    'branch_invalid' => 'الفرع المحدّد غير موجود في هذه الشركة.',
    'date_range_too_large' => 'نطاق التاريخ كبير جداً؛ يرجى تضييقه إلى سنة واحدة أو أقل.',
];
