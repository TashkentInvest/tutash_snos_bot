<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\InspectionResource;
use App\Models\Inspection;
use App\Support\InspectionReview;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        $pending = fn () => $this->record->review_status === Inspection::REVIEW_PENDING && \App\Models\User::currentIsAdmin();

        return [
            Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                ->modalHeading(fn () => $this->record->isCourtCancelled() ? 'Sud qarori bekor qilinganini tasdiqlaysizmi?' : 'Obyekt buzilganini tasdiqlaysizmi?')
                ->modalDescription(fn () => $this->record->isCourtCancelled()
                    ? 'Ilova qilingan hujjatni tekshiring. Tasdiqlangach obyekt «Buzilmaydi» holatiga o‘tadi va umumiy sondan chiqariladi.'
                    : 'Tasdiqlangach obyekt «Buzilgan» holatiga o‘tadi va hisoblarga qo‘shiladi.')
                ->form([InspectionResource::approveNote()])->modalSubmitActionLabel('Tasdiqlash')
                ->visible($pending)
                ->action(function (array $data) {
                    $ok = InspectionReview::approve($this->record, auth()->id(), $data['note'] ?? null);
                    Notification::make()->title($ok ? ($this->record->isCourtCancelled() ? 'Tasdiqlandi: obyekt umumiy hisobdan chiqarildi' : 'Tasdiqlandi: obyekt «Buzilgan» deb belgilandi') : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
                    $this->record->refresh();
                }),
            Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                ->modalHeading('Natijani rad etish')
                ->form([Textarea::make('note')->label('Sabab (xodimga yuboriladi)')->required()->minLength(5)->maxLength(500)])
                ->visible($pending)
                ->action(function (array $data) {
                    $ok = InspectionReview::reject($this->record, auth()->id(), $data['note']);
                    Notification::make()->title($ok ? 'Natija rad etildi, obyekt holati o‘zgarmadi' : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
                    $this->record->refresh();
                }),
            Action::make('remindFollowup')->label('Eslatish')->icon('heroicon-o-bell-alert')->color('warning')
                ->requiresConfirmation()->modalHeading('Xodimga eslatma yuborilsinmi?')
                ->modalDescription(fn () => 'Xodimga botda izoh va «Bajardingizmi?» savoli tugmalar bilan boradi: '.$this->record->staff?->full_name)
                ->visible(fn () => $this->record->followup === Inspection::FOLLOWUP_OPEN && \App\Models\User::currentIsAdmin())
                ->action(function () {
                    $ok = \App\Support\FollowupReminder::send($this->record, auth()->id());
                    Notification::make()->title($ok ? 'Eslatma yuborildi' : 'Yuborilmadi (xodim botni bloklagan bo‘lishi mumkin)')->{$ok ? 'success' : 'warning'}()->send();
                    $this->record->refresh();
                }),
            Action::make('closeFollowup')->label('Izohni yopish')->icon('heroicon-o-check-badge')->color('success')
                ->requiresConfirmation()->modalHeading('Izoh bajarildi deb yopilsinmi?')
                ->modalDescription('Xodim yuborgan rasm va videolarni tekshirib chiqing.')
                ->visible(fn () => $this->record->followup === Inspection::FOLLOWUP_DONE && \App\Models\User::currentIsAdmin())
                ->action(function () {
                    $ok = InspectionReview::closeFollowup($this->record, auth()->id());
                    Notification::make()->title($ok ? 'Izoh yopildi' : 'Izoh allaqachon yopilgan')->{$ok ? 'success' : 'warning'}()->send();
                    $this->record->refresh();
                }),
            Action::make('reopenFollowup')->label('Bajarilmagan')->icon('heroicon-o-arrow-path')->color('warning')
                ->modalHeading('Izoh to‘liq bajarilmagan')
                ->form([Textarea::make('note')->label('Yangi izoh (xodimga yuboriladi)')->required()->minLength(5)->maxLength(500)])
                ->visible(fn () => $this->record->followup === Inspection::FOLLOWUP_DONE && $this->record->review_status === Inspection::REVIEW_APPROVED && \App\Models\User::currentIsAdmin())
                ->action(function (array $data) {
                    $ok = InspectionReview::reopenFollowup($this->record, auth()->id(), $data['note']);
                    Notification::make()->title($ok ? 'Izoh xodimga qayta yuborildi' : 'Holat o‘zgargan, sahifani yangilang')->{$ok ? 'success' : 'warning'}()->send();
                    $this->record->refresh();
                }),
        ];
    }
}
