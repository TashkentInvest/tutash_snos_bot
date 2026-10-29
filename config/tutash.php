<?php

return [
    // Xodim obyektdan shu masofadan (metr) uzoqda bo'lsa tekshiruv "shubhali" deb belgilanadi.
    'max_distance_m' => (int) env('INSPECTION_MAX_DISTANCE_M', 300),

    // true bo'lsa — uzoq masofadan yuborilgan tekshiruv butunlay rad etiladi, false bo'lsa — faqat belgilanadi.
    'strict_distance' => (bool) env('INSPECTION_STRICT_DISTANCE', true),

    // GPS aniqligi (metr) shundan yomon bo'lsa joylashuv rad etiladi (xodim ochiq joyga chiqib qayta urinadi).
    'max_gps_accuracy_m' => (int) env('MAX_GPS_ACCURACY_M', 150),

    // Barcha tumanlarni ko'ra oladigan telefonlar (.env: ALL_DISTRICTS_PHONES=+99890XXXXXXX,+99899XXXXXXX)
    'all_districts_phones' => env('ALL_DISTRICTS_PHONES', ''),

    // Admin bo'lmagan, lekin barcha tumanlarga biriktiriladigan xodim telefonlari (davomat yuritiladi).
    'all_districts_employee_phones' => env('ALL_DISTRICTS_EMPLOYEE_PHONES', ''),

    'page_size' => 8,

    // Bir xodimdan daqiqasiga qabul qilinadigan maksimal update soni.
    'rate_limit_per_minute' => 40,

    // Qora ro'yxat: shu daqiqada necha marta noto'g'ri ro'yxatdan o'tish urinishi mumkin
    'max_registration_attempts' => 3,

    'media_disk' => 'local', // storage/app/private

    // Kunlik davomat: xabar yuboriladigan vaqt, javob qabul qilishning oxirgi vaqti va ish kunlari (1=dushanba ... 7=yakshanba).
    'attendance' => [
        'send_at' => env('ATTENDANCE_SEND_AT', '08:45'),
        'deadline' => env('ATTENDANCE_DEADLINE', '09:30'),
        'days' => [1, 2, 3, 4, 5],
        'min_video_seconds' => 3,
        // Xodim biriktirilgan tumanlardagi eng yaqin obyektdan shu masofadan (metr) uzoqda bo'lsa davomat "shubhali" deb belgilanadi.
        'max_distance_m' => (int) env('ATTENDANCE_MAX_DISTANCE_M', 5000),
        // Kunlik davomat hisobotini global adminlardan tashqari oladigan telefonlar (vergul bilan).
        'report_phones' => env('ATTENDANCE_REPORT_PHONES', ''),
    ],

    // Kunlik buzilish (tekshiruvlar) hisoboti yuboriladigan vaqt.
    'daily_report_at' => env('DAILY_REPORT_AT', '18:00'),

    // Bot matnlari alifbosi: classic (o‘, g‘, sh, ch) yoki new (ö, ğ, ş, ç).
    'alphabet' => env('BOT_ALPHABET', 'classic'),
];
