<?php

namespace App\Filament\Resources\Dizayn\BotUserResource\Pages;

use App\Filament\Resources\Dizayn\BotUserResource;
use Filament\Resources\Pages\ListRecords;

class ListBotUsers extends ListRecords
{
    protected static string $resource = BotUserResource::class;

    public function getSubheading(): ?string
    {
        return 'Dizayn-kod botiga kirgan barcha odamlar. Ichiga kiring — ro‘yxat ma’lumoti, joylashuvi, rasmlari va yuklab olganlari.';
    }
}
