<?php

namespace App\Filament\Resources\LeaseContractResource\Pages;

use App\Filament\Resources\LeaseContractResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLeaseContract extends ViewRecord
{
    protected static string $resource = LeaseContractResource::class;

    public function getTitle(): string
    {
        return 'Ijara shartnomasi №'.$this->record->id.' — '.$this->record->tenant_name;
    }
}
