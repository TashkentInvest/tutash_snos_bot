<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\User;
use App\Telegram\Support\AttendanceReport;
use App\Telegram\Support\InspectionReport;
use App\Telegram\Support\Script;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use SergiX44\Nutgram\Nutgram;

/** Bosh sahifa: jadval bo'yicha yuboriladigan hisobotlarni istalgan payt adminlarga qo'lda yuborish (avval namuna ko'rsatiladi). */
class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendInspectionReport')->label('Buzilish hisobotini yuborish')->icon('heroicon-o-paper-airplane')
                ->modalHeading('Buzilish hisoboti — namuna')->modalWidth('2xl')->modalSubmitActionLabel('Yuborish')
                ->modalContent(fn () => view('filament.pages.report-preview', [
                    'recipients' => AttendanceReport::recipients(),
                    'texts' => [Script::apply(InspectionReport::caption(today()))],
                    'image' => route('admin.svod.png', ['t' => now()->timestamp]),
                ]))
                ->visible(fn () => User::currentIsAdmin())
                ->action(fn () => $this->report('inspection', fn (Nutgram $bot) => InspectionReport::send($bot, today()))),
            Action::make('sendAttendanceReport')->label('Davomat hisobotini yuborish')->icon('heroicon-o-clipboard-document-list')->color('gray')
                ->modalHeading('Davomat hisoboti — namuna')->modalWidth('2xl')->modalSubmitActionLabel('Yuborish')
                ->modalContent(fn () => view('filament.pages.report-preview', [
                    'recipients' => AttendanceReport::recipients(),
                    'texts' => array_map([Script::class, 'apply'], AttendanceReport::chunks(today())),
                    'image' => null,
                    'empty' => 'Bugun davomat so‘rovi yuborilmagan (dam olish kuni yoki so‘rov vaqti hali kelmagan) — hisobot bo‘sh, yuboriladigan narsa yo‘q.',
                ]))
                // ma'lumot bo'lmasa — yuborish tugmasi o'chiq
                ->modalSubmitAction(fn ($action) => $action->disabled(AttendanceReport::chunks(today()) === []))
                ->visible(fn () => User::currentIsAdmin())
                ->action(fn () => $this->report('attendance', fn (Nutgram $bot) => AttendanceReport::sendToAdmins($bot, today()))),
        ];
    }

    private function report(string $type, callable $send): void
    {
        $sent = $send(app(Nutgram::class));
        AuditLog::record("report.manual.{$type}", null, ['by' => auth()->id(), 'sent' => $sent]);
        Notification::make()->title($sent ? "Yuborildi: {$sent} ta xabar" : 'Yuborilmadi: hisobot bo‘sh yoki oluvchilar botni bloklagan')
            ->{$sent ? 'success' : 'warning'}()->send();
    }
}
