<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use App\Models\District;
use App\Support\Broadcast;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('broadcast')->label('Xabar yuborish')->icon('heroicon-o-megaphone')
                ->modalHeading('Xodimlarga xabarnoma')
                ->modalDescription('Xabar botda faol xodimlarga yuboriladi.')
                ->modalSubmitActionLabel('Yuborish')
                ->form([
                    Forms\Components\Select::make('districts')->label('Tumanlar')->multiple()
                        ->options(District::orderBy('name')->pluck('name', 'id'))
                        ->placeholder('Barcha xodimlar')->live()
                        ->helperText(fn (Forms\Get $get) => 'Oluvchilar: '.Broadcast::recipients(array_map('intval', $get('districts') ?? []))->count().' ta xodim'),
                    Forms\Components\Textarea::make('text')->label('Xabar matni')->required()->minLength(3)->maxLength(3500)->rows(6),
                ])
                ->action(function (array $data) {
                    $r = Broadcast::send($data['text'], array_map('intval', $data['districts'] ?? []), auth()->id());
                    Notification::make()->title("Yuborildi: {$r['sent']} ta xodimga".($r['failed'] ? ", yetkazilmadi: {$r['failed']}" : ''))
                        ->{$r['sent'] ? 'success' : 'warning'}()->send();
                }),
        ];
    }
}
