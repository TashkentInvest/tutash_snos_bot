<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [\App\Filament\Actions\PostAction::make()->color('primary')];
    }

    public function getSubheading(): ?string
    {
        return 'Botda yuborilgan postlar. Ichiga kiring — kimga yetkazildi, kim tanishdi.';
    }
}
