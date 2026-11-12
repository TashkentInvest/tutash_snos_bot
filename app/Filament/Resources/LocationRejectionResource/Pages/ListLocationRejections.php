<?php

namespace App\Filament\Resources\LocationRejectionResource\Pages;

use App\Filament\Resources\LocationRejectionResource;
use Filament\Resources\Pages\ListRecords;

class ListLocationRejections extends ListRecords
{
    protected static string $resource = LocationRejectionResource::class;

    public function getSubheading(): ?string
    {
        return 'Tekshiruvda joylashuvi qabul qilinmagan urinishlar: xodim yuborgan nuqta va bizdagi obyekt nuqtasi. Ichiga kiring — ikkalasi xaritada.';
    }
}
