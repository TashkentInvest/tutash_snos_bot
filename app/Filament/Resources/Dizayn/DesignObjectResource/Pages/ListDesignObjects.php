<?php

namespace App\Filament\Resources\Dizayn\DesignObjectResource\Pages;

use App\Filament\Resources\Dizayn\DesignObjectResource;
use Filament\Resources\Pages\ListRecords;

class ListDesignObjects extends ListRecords
{
    protected static string $resource = DesignObjectResource::class;

    public function getSubheading(): ?string
    {
        return 'Dizayn-kod ko‘chalaridagi do‘kon va firmalar: tuman xodimlari kiritadi, holat dalil tasdiqlanganda o‘zgaradi.';
    }
}
