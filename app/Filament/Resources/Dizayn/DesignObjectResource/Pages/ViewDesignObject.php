<?php

namespace App\Filament\Resources\Dizayn\DesignObjectResource\Pages;

use App\Filament\Resources\Dizayn\DesignObjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDesignObject extends ViewRecord
{
    protected static string $resource = DesignObjectResource::class;

    public function getTitle(): string
    {
        return $this->record->label() ?: 'Obyekt';
    }

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()->label('Tuzatish')];
    }
}
