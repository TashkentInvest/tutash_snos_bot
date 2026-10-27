<?php

// Laravel validatsiya xabarlari (o'zbekcha). Bu yerda yo'q qoidalar uchun inglizcha (fallback) ishlatiladi.
return [
    'accepted' => ':attribute qabul qilinishi kerak.',
    'array' => ':attribute ro‘yxat bo‘lishi kerak.',
    'between' => [
        'numeric' => ':attribute :min va :max oralig‘ida bo‘lishi kerak.',
        'file' => ':attribute hajmi :min–:max KB oralig‘ida bo‘lishi kerak.',
        'string' => ':attribute :min–:max belgidan iborat bo‘lishi kerak.',
        'array' => ':attribute :min–:max ta elementdan iborat bo‘lishi kerak.',
    ],
    'boolean' => ':attribute «ha» yoki «yo‘q» bo‘lishi kerak.',
    'confirmed' => ':attribute tasdig‘i mos kelmadi.',
    'date' => ':attribute to‘g‘ri sana emas.',
    'email' => ':attribute to‘g‘ri elektron pochta manzili bo‘lishi kerak.',
    'exists' => 'Tanlangan :attribute topilmadi.',
    'file' => ':attribute fayl bo‘lishi kerak.',
    'image' => ':attribute rasm bo‘lishi kerak.',
    'in' => 'Tanlangan :attribute noto‘g‘ri.',
    'integer' => ':attribute butun son bo‘lishi kerak.',
    'max' => [
        'numeric' => ':attribute :max dan katta bo‘lmasligi kerak.',
        'file' => ':attribute hajmi :max KB dan oshmasligi kerak.',
        'string' => ':attribute :max belgidan oshmasligi kerak.',
        'array' => ':attribute :max tadan ko‘p elementga ega bo‘lmasligi kerak.',
    ],
    'mimes' => ':attribute quyidagi turdagi fayl bo‘lishi kerak: :values.',
    'min' => [
        'numeric' => ':attribute kamida :min bo‘lishi kerak.',
        'file' => ':attribute hajmi kamida :min KB bo‘lishi kerak.',
        'string' => ':attribute kamida :min belgidan iborat bo‘lishi kerak.',
        'array' => ':attribute kamida :min ta elementga ega bo‘lishi kerak.',
    ],
    'numeric' => ':attribute son bo‘lishi kerak.',
    'regex' => ':attribute formati noto‘g‘ri.',
    'required' => ':attribute kiritilishi shart.',
    'string' => ':attribute matn bo‘lishi kerak.',
    'unique' => 'Bunday :attribute allaqachon mavjud.',
    'uploaded' => ':attribute yuklanmadi. Internet aloqasini tekshirib, qayta urinib ko‘ring.',

    'custom' => [
        'video' => [
            'max' => 'Video hajmi juda katta (:max KB dan oshmasligi kerak). Videoni qisqaroq yozib, qayta yuboring.',
            'uploaded' => 'Video yuklanmadi (hajmi juda katta yoki aloqa uzildi). Qisqaroq yozib, qayta urinib ko‘ring.',
        ],
        'face' => [
            'max' => 'Yuz videosi hajmi juda katta (:max KB dan oshmasligi kerak). Qayta yozib ko‘ring.',
        ],
        'clip' => [
            'max' => 'Rasm uchun olingan lavha hajmi juda katta. Qayta urinib ko‘ring.',
        ],
        'duration' => [
            'max' => 'Video :max soniyadan uzun bo‘lmasligi kerak.',
        ],
    ],

    'attributes' => [
        'initData' => 'Telegram imzosi',
        'lat' => 'Kenglik',
        'lng' => 'Uzunlik',
        'accuracy' => 'GPS aniqligi',
        'video' => 'Video',
        'face' => 'Yuz videosi',
        'clip' => 'Rasm lavhasi',
        'duration' => 'Video davomiyligi',
        'reason' => 'Sabab',
        'value' => 'Javob',
        'device' => 'Qurilma ma’lumoti',
        'email' => 'Elektron pochta',
        'password' => 'Parol',
        'name' => 'Ism',
        'full_name' => 'F.I.SH',
        'phone' => 'Telefon',
        'address' => 'Manzil',
    ],
];
