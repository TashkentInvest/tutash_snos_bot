<?php

namespace App\Filament\Resources\Dizayn\DownloadResource\Pages;

use App\Filament\Resources\Dizayn\DownloadResource;
use Filament\Resources\Pages\ListRecords;

class ListDownloads extends ListRecords
{
    protected static string $resource = DownloadResource::class;

    public function getSubheading(): ?string
    {
        return 'Dizayn-kod botidan yuklab olingan ko‘cha konsepsiyalari (PDF). Tuman va sana bo‘yicha saralash mumkin.';
    }
}
