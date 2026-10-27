<?php

namespace App\Livewire;

use App\Filament\Resources\InspectionResource;
use App\Models\Inspection;
use Livewire\Component;

/** Yuqori paneldagi qo'ng'iroq: moderator tasdig'ini kutayotgan "buzilgan" natijalar soni (20 soniyada yangilanadi). */
class PendingInspectionsBell extends Component
{
    public function render()
    {
        return view('livewire.pending-inspections-bell', [
            'count' => Inspection::where('status', Inspection::SUBMITTED)->where('review_status', Inspection::REVIEW_PENDING)->count(),
            'url' => InspectionResource::getUrl('index', ['tableFilters' => ['review_status' => ['value' => Inspection::REVIEW_PENDING]]]),
        ]);
    }
}
