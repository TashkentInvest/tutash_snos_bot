<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Arxivlangan rasm/videolar faqat tizimga kirgan administratorlarga beriladi.
Route::get('/admin-media/{media}', MediaController::class)->middleware(['auth'])->name('admin.media');
Route::get('/admin-attendance/{attendance}', [MediaController::class, 'attendanceVideo'])->middleware(['auth'])->name('admin.attendance-video');

// Davomat joylashuvi (Telegram Mini App). Imzo (initData) controllerda tekshiriladi.
Route::get('/tg/attendance-location', [\App\Http\Controllers\AttendanceLocationController::class, 'page']);
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
