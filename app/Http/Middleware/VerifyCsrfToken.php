<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'telegram/webhook',
        // Mini App'lar: sessiya cookie'si yo'q, har bir so'rov Telegram imzosi (initData) bilan tekshiriladi.
        // Butun prefiks — yangi endpoint qo'shilganda "CSRF token mismatch" takrorlanmasligi uchun.
        'tg/*',
    ];
}
