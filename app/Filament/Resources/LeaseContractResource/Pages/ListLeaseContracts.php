<?php

namespace App\Filament\Resources\LeaseContractResource\Pages;

use App\Filament\Resources\LeaseContractResource;
use Filament\Resources\Pages\ListRecords;

class ListLeaseContracts extends ListRecords
{
    protected static string $resource = LeaseContractResource::class;

    public function getSubheading(): ?string
    {
        return 'Shartnoma mas’ullari botda tayyorlagan shartnomalar (namuna bo‘yicha). Tasdiq yo‘q — chop etilib, tomonlar imzolaydi; bu yerda hisob va qayta yuklab olish uchun saqlanadi.';
    }
}
