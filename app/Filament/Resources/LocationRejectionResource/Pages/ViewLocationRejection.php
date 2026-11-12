<?php

namespace App\Filament\Resources\LocationRejectionResource\Pages;

use App\Filament\Resources\LocationRejectionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLocationRejection extends ViewRecord
{
    protected static string $resource = LocationRejectionResource::class;

    public function getTitle(): string
    {
        return 'Joylashuv xatoligi — '.($this->record->staff?->full_name ?? '');
    }
}
