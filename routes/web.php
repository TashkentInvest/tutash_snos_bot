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

    // ?type=progress — tumanlar kesimida bajarilish jadvali (hisobotdagi ikkinchi rasm)
    $day = \App\Support\ReportPeriod::fromFilter($request->query('day'))->day;
    $t = fn (string $s) => \App\Telegram\Support\Script::apply($s);
    $png = $request->query('type') === 'progress' ? \App\Support\Svod::progressPng($day, $t) : \App\Support\Svod::png($day, $t);

    return response($png, 200, [
        'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
    ]);
})->middleware(['auth'])->name('admin.svod.png');
// Soatlik qayd hisoboti namunasi (Sozlamalar → Hisobotlar): ?type=hourly&slot=15:00 yoki ?type=daily — bugungi ma'lumot bilan
Route::get('/admin-checkin-report.png', function (\Illuminate\Http\Request $request) {
    abort_unless(\App\Models\User::currentIsAdmin(), 403);
    $slots = \App\Models\Checkin::slots();
    $day = rescue(fn () => \Carbon\Carbon::parse((string) $request->query('day', today()->toDateString()))->startOfDay(), today(), false);
    if ($request->query('type') === 'daily') {
        $report = \App\Telegram\Support\CheckinReport::daily($day, $day->isToday() ? now() : $day->copy()->endOfDay());
    } else {
        // so'ralgan soat yoki hozirgacha boshlangan oxirgi soat
        $slot = in_array($request->query('slot'), $slots, true) ? $request->query('slot')
            : (collect($slots)->last(fn ($s) => today()->setTimeFromTimeString($s)->lessThanOrEqualTo(now())) ?? ($slots[0] ?? '10:00'));
        $report = \App\Telegram\Support\CheckinReport::hourly($day, $slot);
    }

    return response($report['png'], 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
})->middleware(['auth'])->name('admin.checkin-report.png');
// Ertalabki davomat hisoboti rasmi (dashboard va sozlamada namuna)
Route::get('/admin-attendance-report.png', function (\Illuminate\Http\Request $request) {
    abort_unless(\App\Models\User::currentIsAdmin(), 403);
    $day = rescue(fn () => \Carbon\Carbon::parse((string) $request->query('day', today()->toDateString()))->startOfDay(), today(), false);

    return response(\App\Telegram\Support\AttendanceReport::image($day)['png'], 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
})->middleware(['auth'])->name('admin.attendance-report.png');
Route::get('/admin-checkin/{checkin}', [MediaController::class, 'checkinVideo'])->middleware(['auth'])->name('admin.checkin-video');
// ijara shartnomasi rasmi (joyida olingan, faqat admin)
Route::get('/admin-lease/{contract}/photo/{n}', function (\App\Models\LeaseContract $contract, int $n) {
    abort_unless(auth()->user()?->is_admin, 403);
    $path = ($contract->photos ?? [])[$n - 1] ?? null;
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    abort_unless($path && $disk->exists($path), 404);

    return response()->file($disk->path($path), ['Cache-Control' => 'private, max-age=3600']);
})->whereNumber('n')->middleware(['auth'])->name('admin.lease-photo');
// ijara shartnomasi fayli (faqat admin)
Route::get('/admin-lease/{contract}/{format}', function (\App\Models\LeaseContract $contract, string $format) {
    abort_unless(auth()->user()?->is_admin, 403);
    $path = $format === 'pdf' ? $contract->pdf_path : $contract->docx_path;
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    abort_unless($path && $disk->exists($path), 404);

    return response()->download($disk->path($path), 'ijara-shartnoma-'.$contract->id.'.'.($format === 'pdf' ? 'pdf' : 'docx'));
})->whereIn('format', ['docx', 'pdf'])->middleware(['auth'])->name('admin.lease-file');
Route::get('/admin-attendance/{attendance}',[MediaController::class, 'attendanceVideo'])->middleware(['auth'])->name('admin.attendance-video');

// Davomat joylashuvi (Telegram Mini App). Imzo (initData) controllerda tekshiriladi.
Route::get('/tg/attendance-location', [\App\Http\Controllers\AttendanceLocationController::class, 'page']);
// Ruxsatlarni tekshirish (joylashuv, kamera, mikrofon) — faqat brauzer tomonida, ma'lumot yuborilmaydi.
Route::get('/tg/permissions', fn () => response()->view('permissions')->header('Cache-Control', 'no-store'));
Route::post('/tg/attendance-state', [\App\Http\Controllers\AttendanceLocationController::class, 'state'])->middleware('throttle:tg-state');
Route::post('/tg/attendance-video', [\App\Http\Controllers\AttendanceLocationController::class, 'video'])->middleware('throttle:tg-video');
Route::post('/tg/attendance-location', [\App\Http\Controllers\AttendanceLocationController::class, 'store'])->middleware('throttle:tg-location');
// Shartnoma xodimlarining soatlik qaydi (joylashuv + video)
Route::get('/tg/checkin', [\App\Http\Controllers\CheckinController::class, 'page']);
Route::post('/tg/checkin-state', [\App\Http\Controllers\CheckinController::class, 'state'])->middleware('throttle:tg-state');
Route::post('/tg/checkin-location', [\App\Http\Controllers\CheckinController::class, 'location'])->middleware('throttle:tg-location');
Route::post('/tg/checkin-video', [\App\Http\Controllers\CheckinController::class, 'video'])->middleware('throttle:tg-video');

// Obyekt tekshiruvi (Telegram Mini App).
// Kadastr xodimi: obyekt qo'shish / tahrirlash (admin tasdiqlaydi)
Route::get('/tg/site-form', [\App\Http\Controllers\SiteFormController::class, 'page']);
// ijara shartnomasi (gastronomik ko'chalar mas'ullari)
Route::get('/tg/lease-form', [\App\Http\Controllers\LeaseFormController::class, 'page']);
Route::post('/tg/lease-form/state', [\App\Http\Controllers\LeaseFormController::class, 'state'])->middleware('throttle:tg-app');
Route::post('/tg/lease-form/save', [\App\Http\Controllers\LeaseFormController::class, 'save'])->middleware('throttle:tg-app');
Route::post('/tg/site-form/state', [\App\Http\Controllers\SiteFormController::class, 'state'])->middleware('throttle:tg-app');
Route::post('/tg/site-form/save', [\App\Http\Controllers\SiteFormController::class, 'save'])->middleware('throttle:tg-app');

Route::get('/tg/inspection', [\App\Http\Controllers\InspectionAppController::class, 'page']);
Route::prefix('tg/inspection')->middleware('throttle:tg-app')->group(function () {
    Route::post('/state', [\App\Http\Controllers\InspectionAppController::class, 'state']);
    Route::post('/location', [\App\Http\Controllers\InspectionAppController::class, 'location']);
    Route::post('/photo', [\App\Http\Controllers\InspectionAppController::class, 'photo'])->middleware('throttle:tg-media');
    Route::post('/video', [\App\Http\Controllers\InspectionAppController::class, 'video'])->middleware('throttle:tg-media');
    Route::post('/edit', [\App\Http\Controllers\InspectionAppController::class, 'edit']);
    Route::post('/reason', [\App\Http\Controllers\InspectionAppController::class, 'reason']);
    Route::post('/document', [\App\Http\Controllers\InspectionAppController::class, 'document'])->middleware('throttle:tg-media');
    Route::post('/document/remove', [\App\Http\Controllers\InspectionAppController::class, 'documentRemove']);
    Route::post('/documents/done', [\App\Http\Controllers\InspectionAppController::class, 'documentsDone']);
    Route::post('/submit', [\App\Http\Controllers\InspectionAppController::class, 'submit']);
    Route::post('/cancel', [\App\Http\Controllers\InspectionAppController::class, 'cancel']);
});

// Telegram webhook (production). Sarlavhadagi maxfiy token tekshiriladi.
Route::post('/telegram/webhook', TelegramWebhookController::class);
