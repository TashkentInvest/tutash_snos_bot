<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPost extends ViewRecord
{
    protected static string $resource = PostResource::class;

    public function getTitle(): string
    {
        return 'Post — '.$this->record->created_at->format('d.m.Y H:i');
    }

    protected function getHeaderActions(): array
    {
        return [PostResource::recall(\Filament\Actions\Action::class)];
    }
}
