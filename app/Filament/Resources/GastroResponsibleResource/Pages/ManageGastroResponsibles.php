<?php

namespace App\Filament\Resources\GastroResponsibleResource\Pages;

use App\Filament\Resources\GastroResponsibleResource;
use Filament\Resources\Pages\ManageRecords;

class ManageGastroResponsibles extends ManageRecords
{
    protected static string $resource = GastroResponsibleResource::class;

    public function getSubheading(): ?string
    {
        return 'Har tuman ijara shartnomalari kimga tegishli. Xodim almashsa — shu yerda boshqasini tanlang. Bu xodimning davomat va obyektlar tumanlariga ta’sir qilmaydi.';
    }
}
