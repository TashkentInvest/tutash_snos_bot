<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],


    'telegram' => [
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    // Dizayn-kod dashboardi: ko'chalar (GeoJSON) — «Umumiy xarita»dagi qatlam uchun; token bo'sh bo'lsa qatlam o'chiq
    'dizayn_kod' => [
        'url' => env('DIZAYN_KOD_API_URL', 'https://bot-thudud.toshkentinvest.uz/api/v1/streets'),
        'token' => env('DIZAYN_KOD_TOKEN'),
    ],

    // Dizayn-kod boti — SNOS panelidan boshqariladi (baza: database.connections.dizayn).
    // enabled=false bo'lsa «Dizayn-kod boti» bo'limi menyuda ko'rinmaydi; papkalar — botning fayllari (o'sha serverda).
    'dizayn_bot' => [
        'enabled' => (bool) env('DIZAYN_BOT_ENABLED', true),
        'data_dir' => env('DIZAYN_DATA_DIR', '/srv/tutash/data'),
        'uploads_dir' => env('DIZAYN_UPLOADS_DIR', '/srv/tutash/uploads'),
    ],

];
