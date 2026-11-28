<?php

namespace App\Filament\Resources\Dizayn\DesignObjectResource\Pages;

use App\Filament\Resources\Dizayn\DesignObjectResource;
use Filament\Resources\Pages\EditRecord;

class EditDesignObject extends EditRecord
{
    protected static string $resource = DesignObjectResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_at'] = now();
        \App\Models\AuditLog::record('dizayn.object.edited', null, ['object' => $this->record->id, 'by' => auth()->id(),
            'status' => [$this->record->status, $data['status'] ?? null]]);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
