<?php

namespace App\Filament\Resources\LeaseContractResource\Pages;

use App\Filament\Resources\LeaseContractResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLeaseContract extends ViewRecord
{
    protected static string $resource = LeaseContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\EditAction::make()->label('Tuzatish'),
            \Filament\Actions\DeleteAction::make()->label('O‘chirish')
                ->modalDescription('Shartnoma ro‘yxat, svod va hisobotlardan olib tashlanadi. «O‘chirilganlar» filtridan tiklash mumkin.')
                ->after(fn () => \App\Models\AuditLog::record('lease.deleted', null, ['contract' => $this->record->id, 'by' => auth()->id()])),
            \Filament\Actions\RestoreAction::make()->label('Tiklash'),
        ];
    }

    public function getTitle(): string
    {
        return 'Ijara shartnomasi №'.$this->record->id.' — '.$this->record->tenant_name;
    }
}
