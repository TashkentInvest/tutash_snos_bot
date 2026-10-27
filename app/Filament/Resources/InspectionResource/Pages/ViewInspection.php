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
        $pending = fn () => $this->record->review_status === Inspection::REVIEW_PENDING;

        return [
            Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                ->requiresConfirmation()->modalHeading('Obyekt buzilganini tasdiqlaysizmi?')
                ->modalDescription('Tasdiqlangach obyekt «Buzilgan» holatiga o‘tadi va hisoblarga qo‘shiladi.')
                ->visible($pending)
                ->action(function () {
                    $ok = InspectionReview::approve($this->record, auth()->id());
                    Notification::make()->title($ok ? 'Tasdiqlandi: obyekt «Buzilgan» deb belgilandi' : 'Bu natija allaqachon ko‘rib chiqilgan')->{$ok ? 'success' : 'warning'}()->send();
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
        ];
    }
}
