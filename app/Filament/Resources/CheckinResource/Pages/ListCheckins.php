<?php

namespace App\Filament\Resources\CheckinResource\Pages;

use App\Filament\Resources\CheckinResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckins extends ListRecords
{
    protected static string $resource = CheckinResource::class;
}
