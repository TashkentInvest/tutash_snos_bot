<?php

namespace App\Livewire;

use App\Filament\Resources\DistrictResource;
use App\Models\District;
use Livewire\Component;

/** Yuqori panel: mas'uli botda hali ro'yxatdan o'tmagan tumanlar (60 soniyada yangilanadi). */
class UnregisteredDistrictsBell extends Component
{
    public function render()
    {
        $districts = District::unregistered()->orderBy('name')->get(['name', 'responsible_name']);

        return view('livewire.unregistered-districts-bell', [
            'count' => $districts->count(),
            'names' => $districts->map(fn ($d) => $d->name.($d->responsible_name ? " — {$d->responsible_name}" : ''))->all(),
            'url' => DistrictResource::unregisteredUrl(),
        ]);
    }
}
