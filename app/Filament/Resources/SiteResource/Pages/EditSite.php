<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            // tekshiruvi bor obyekt o'chirilmaydi (arxiv yo'qolmasin; avval bazada FK xatosi chiqardi)
            Actions\DeleteAction::make()->before(function (Actions\DeleteAction $action, \App\Models\Site $record) {
                if ($record->inspections()->exists()) {
                    \Filament\Notifications\Notification::make()->danger()
                        ->title('Bu obyektda tekshiruv natijalari bor — o‘chirib bo‘lmaydi.')
                        ->body('Holatini o‘zgartiring (masalan, «Sud qarori bekor») yoki xato yozuv bo‘lsa administratorga murojaat qiling.')->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
