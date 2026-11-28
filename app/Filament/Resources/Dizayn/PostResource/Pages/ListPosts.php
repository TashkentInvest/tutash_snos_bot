<?php

namespace App\Filament\Resources\Dizayn\PostResource\Pages;

use App\Filament\Resources\Dizayn\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    public function getSubheading(): ?string
    {
        return 'Dizayn-kod botida yuborilgan postlar. Yangi post — bosh sahifadagi «Post yuborish» tugmasi (Tutash hudud, Dizayn-kod yoki ikkalasiga birdan).';
    }
}
