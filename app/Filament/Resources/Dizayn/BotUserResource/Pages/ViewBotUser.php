<?php

namespace App\Filament\Resources\Dizayn\BotUserResource\Pages;

use App\Filament\Resources\Dizayn\BotUserResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBotUser extends ViewRecord
{
    protected static string $resource = BotUserResource::class;

    public function getTitle(): string
    {
        return $this->record->displayName();
    }
}
