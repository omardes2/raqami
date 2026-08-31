<?php

// Focused Arabic validation messages. Any key not present here falls back to
// the English file automatically (translator fallback locale).
return [
    'required' => 'حقل :attribute مطلوب.',
    'email' => 'يجب أن يكون :attribute عنوان بريد إلكتروني صحيح.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'confirmed' => 'تأكيد :attribute غير متطابق.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'size' => [
        'string' => 'يجب أن يكون طول :attribute :size حرفاً.',
    ],
    'max' => [
        'string' => 'يجب ألا يزيد :attribute عن :max حرفاً.',
    ],
    'min' => [
        'string' => 'يجب ألا يقل :attribute عن :min حرفاً.',
    ],
    'alpha_dash' => 'يجب أن يحتوي :attribute على حروف وأرقام وشرطات فقط.',

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'locale' => 'اللغة',
        'timezone' => 'المنطقة الزمنية',
        'country_code' => 'رمز الدولة',
        'default_currency' => 'العملة',
    ],
];
