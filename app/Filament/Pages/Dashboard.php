<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\User;
use App\Telegram\Support\AttendanceReport;
use App\Telegram\Support\InspectionReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use SergiX44\Nutgram\Nutgram;

/** Bosh sahifa: jadval bo'yicha yuboriladigan hisobotlarni istalgan payt adminlarga qo'lda yuborish tugmalari. */
class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        $recipients = fn () => AttendanceReport::recipients()->pluck('full_name')->join(', ') ?: 'oluvchi yo‘q';

        return [
            Action::make('sendInspectionReport')->label('Buzilish hisobotini yuborish')->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()->modalHeading('Buzilish hisoboti (svod jadval) yuborilsinmi?')
                ->modalDescription(fn () => 'Bugungi holat bo‘yicha. Oluvchilar: '.$recipients())
                ->visible(fn () => User::currentIsAdmin())
                ->action(fn () => $this->report('inspection', fn (Nutgram $bot) => InspectionReport::send($bot, today()))),
            Action::make('sendAttendanceReport')->label('Davomat hisobotini yuborish')->icon('heroicon-o-clipboard-document-list')->color('gray')
                ->requiresConfirmation()->modalHeading('Davomat hisoboti yuborilsinmi?')
                ->modalDescription(fn () => 'Bugungi davomat bo‘yicha. Oluvchilar: '.$recipients())
                ->visible(fn () => User::currentIsAdmin())
                ->action(fn () => $this->report('attendance', fn (Nutgram $bot) => AttendanceReport::sendToAdmins($bot, today()))),
        ];
    }

    private function report(string $type, callable $send): void
    {
        $sent = $send(app(Nutgram::class));
        AuditLog::record("report.manual.{$type}", null, ['by' => auth()->id(), 'sent' => $sent]);
        Notification::make()->title($sent ? "Yuborildi: {$sent} ta xabar" : 'Yuborilmadi: hisobot oluvchilar yo‘q yoki botni bloklagan')
            ->{$sent ? 'success' : 'warning'}()->send();
    }
}
