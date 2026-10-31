<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Livewire yangilanishlari: 419 sababini jurnalga yozish uchun o'ralgan (LivewireUpdateController)
Route::post('/livewire/update', \App\Http\Controllers\LivewireUpdateController::class)->middleware('web')->name('app.livewire.update');

// Arxivlangan rasm/videolar faqat tizimga kirgan administratorlarga beriladi.
Route::get('/admin-media/{media}', MediaController::class)->middleware(['auth'])->name('admin.media');
// Obyektning avvalgi holat rasmlari (yopiq diskda) — panelga kira oladiganlar (admin, kuzatuvchi)
Route::get('/admin-site-photo', function (\Illuminate\Http\Request $request) {
    $path = (string) $request->query('path');
    abort_unless(auth()->user()?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')), 403);
    // faqat sites/ papkasidagi oddiy fayl nomi (../ va boshqa papkalar emas)
    abort_unless(preg_match('#^sites/[A-Za-z0-9._-]+$#', $path) === 1, 404);
    $disk = \Illuminate\Support\Facades\Storage::disk(config('tutash.media_disk'));
    abort_unless($disk->exists($path), 404);

    return response()->file($disk->path($path), ['Cache-Control' => 'private, max-age=86400']);
})->middleware(['auth'])->name('admin.site-photo');
// Svod jadval (Excel) — faqat adminlar
Route::get('/admin-svod.xlsx', function (\Illuminate\Http\Request $request) {
    abort_unless(\App\Models\User::currentIsAdmin(), 403);
    $day = \App\Support\ReportPeriod::fromFilter($request->query('day'))->day; // ?day=YYYY-MM-DD (bo'sh — joriy kun)

    return response(\App\Support\Svod::xlsx($day), 200, [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Content-Disposition' => 'attachment; filename="svod-'.$day->format('Y-m-d').'.xlsx"',
        'Cache-Control' => 'no-store',
    ]);
})->middleware(['auth'])->name('admin.svod');
// Svod jadval rasmi (hisobot namunasi uchun) — faqat adminlar
Route::get('/admin-svod.png', function (\Illuminate\Http\Request $request) {
    abort_unless(\App\Models\User::currentIsAdmin(), 403);

    return response(\App\Support\Svod::png(\App\Support\ReportPeriod::fromFilter($request->query('day'))->day,fn (string $s) => \App\Telegram\Support\Script::apply($s)), 200, [
        'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
    ]);
})->middleware(['auth'])->name('admin.svod.png');
Route::get('/admin-attendance/{attendance}',[MediaController::class, 'attendanceVideo'])->middleware(['auth'])->name('admin.attendance-video');

// Davomat joylashuvi (Telegram Mini App). Imzo (initData) controllerda tekshiriladi.
Route::get('/tg/attendance-location', [\App\Http\Controllers\AttendanceLocationController::class, 'page']);
// Ruxsatlarni tekshirish (joylashuv, kamera, mikrofon) — faqat brauzer tomonida, ma'lumot yuborilmaydi.
Route::get('/tg/permissions', fn () => response()->view('permissions')->header('Cache-Control', 'no-store'));
Route::post('/tg/attendance-state', [\App\Http\Controllers\AttendanceLocationController::class, 'state'])->middleware('throttle:tg-state');
Route::post('/tg/attendance-video', [\App\Http\Controllers\AttendanceLocationController::class, 'video'])->middleware('throttle:tg-video');
Route::post('/tg/attendance-location', [\App\Http\Controllers\AttendanceLocationController::class, 'store'])->middleware('throttle:tg-location');

// Obyekt tekshiruvi (Telegram Mini App).
Route::get('/tg/inspection', [\App\Http\Controllers\InspectionAppController::class, 'page']);
Route::prefix('tg/inspection')->middleware('throttle:tg-app')->group(function () {
    Route::post('/state', [\App\Http\Controllers\InspectionAppController::class, 'state']);
    Route::post('/location', [\App\Http\Controllers\InspectionAppController::class, 'location']);
    Route::post('/photo', [\App\Http\Controllers\InspectionAppController::class, 'photo'])->middleware('throttle:tg-media');
    Route::post('/video', [\App\Http\Controllers\InspectionAppController::class, 'video'])->middleware('throttle:tg-media');
    Route::post('/skip', [\App\Http\Controllers\InspectionAppController::class, 'skip']);
    Route::post('/edit', [\App\Http\Controllers\InspectionAppController::class, 'edit']);
    Route::post('/demolished', [\App\Http\Controllers\InspectionAppController::class, 'demolished']);
    Route::post('/reason', [\App\Http\Controllers\InspectionAppController::class, 'reason']);
    Route::post('/issue', [\App\Http\Controllers\InspectionAppController::class, 'issue']);
    Route::post('/document', [\App\Http\Controllers\InspectionAppController::class, 'document'])->middleware('throttle:tg-media');
    Route::post('/document/remove', [\App\Http\Controllers\InspectionAppController::class, 'documentRemove']);
    Route::post('/documents/done', [\App\Http\Controllers\InspectionAppController::class, 'documentsDone']);
    Route::post('/submit', [\App\Http\Controllers\InspectionAppController::class, 'submit']);
    Route::post('/cancel', [\App\Http\Controllers\InspectionAppController::class, 'cancel']);
});

// Telegram webhook (production). Sarlavhadagi maxfiy token tekshiriladi.
Route::post('/telegram/webhook', TelegramWebhookController::class);
