<?php

// Arabic messages for the validation rules the API uses. Rules not listed
// here fall back to English (config app.fallback_locale).

return [
    'array' => 'يجب أن يكون حقل :attribute قائمة.',
    'between' => [
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفًا.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحًا أو خطأً.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'in' => 'قيمة :attribute غير صالحة.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'max' => [
        'numeric' => 'يجب ألا تزيد قيمة :attribute على :max.',
        'string' => 'يجب ألا يزيد طول :attribute على :max حرفًا.',
    ],
    'min' => [
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفًا.',
    ],
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_with' => 'حقل :attribute مطلوب عند إدخال :values.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'uuid' => 'يجب أن يكون :attribute معرّفًا صالحًا.',

    'attributes' => [
        'phone' => 'رقم الجوال',
        'code' => 'الرمز',
        'challenge_id' => 'معرّف الرمز',
        'device_name' => 'اسم الجهاز',
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'locale' => 'اللغة',
        'policy_version' => 'نسخة السياسة',
        'recipient_name' => 'اسم المستلم',
        'recipient_phone' => 'جوال المستلم',
        'city' => 'المدينة',
        'district' => 'الحي',
        'street' => 'الشارع',
        'building_no' => 'رقم المبنى',
        'postal_code' => 'الرمز البريدي',
        'additional_no' => 'الرقم الإضافي',
        'short_address' => 'العنوان المختصر',
        'lat' => 'خط العرض',
        'lng' => 'خط الطول',
        'is_default' => 'العنوان الافتراضي',
    ],
];
