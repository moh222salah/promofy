<?php

return [
    'accepted' => 'يجب قبول حقل :attribute.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'boolean' => 'يجب أن تكون قيمة :attribute صح أو خطأ.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صالحًا.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'distinct' => 'حقل :attribute يحتوي قيمة مكررة.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صالحًا.',
    'exists' => 'القيمة المحددة في :attribute غير صالحة.',
    'in' => 'القيمة المحددة في :attribute غير صالحة.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'max' => [
        'array' => 'يجب ألا يزيد :attribute عن :max عنصر.',
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
        'string' => 'يجب ألا يزيد :attribute عن :max حرفًا.',
    ],
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصر على الأقل.',
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string' => 'يجب ألا يقل :attribute عن :min أحرف.',
    ],
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'required' => 'حقل :attribute مطلوب.',
    'size' => ['string' => 'يجب أن يتكون :attribute من :size أحرف.'],
    'string' => 'يجب أن يكون :attribute نصًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صالحة.',
    'unique' => ':attribute مستخدم من قبل.',
    'password' => [
        'letters' => 'يجب أن يحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي :attribute على حرف كبير وآخر صغير.',
        'numbers' => 'يجب أن يحتوي :attribute على رقم واحد على الأقل.',
    ],
    'attributes' => [
        'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور',
        'phone' => 'الهاتف', 'country_code' => 'الدولة', 'locale' => 'اللغة',
        'role_ids' => 'الأدوار', 'code' => 'الرمز',
    ],
];
