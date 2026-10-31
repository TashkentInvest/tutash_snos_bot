<?php

namespace App\Http\Controllers;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire yangilanish so'rovi. Production'da Livewire TypeError'ni jurnalga yozmasdan "Page expired" (419) qaytaradi —
 * bu yerda xato avval jurnalga yoziladi, so'ng foydalanuvchiga xuddi o'sha 419 qaytariladi.
 */
class LivewireUpdateController
{
    public function __invoke(HandleRequests $handler)
    {
        $debug = config('app.debug');
        config(['app.debug' => true]); // Livewire TypeError'ni yutib yubormasligi uchun (faqat shu chaqiruv davomida)

        try {
            return $handler->handleUpdate();
        } catch (\TypeError $e) {
            report($e);
            abort(419);
        } finally {
            config(['app.debug' => $debug]);
        }
    }
}
