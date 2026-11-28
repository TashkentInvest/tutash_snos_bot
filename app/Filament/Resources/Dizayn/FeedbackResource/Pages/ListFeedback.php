<?php

namespace App\Filament\Resources\Dizayn\FeedbackResource\Pages;

use App\Filament\Resources\Dizayn\FeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    public function getSubheading(): ?string
    {
        return 'Fuqarolarning Dizayn-kod boti orqali yuborgan murojaatlari. «Javob berish» — javob bot orqali fuqaroga boradi.';
    }
}
