{{-- Obyekt bo'yicha xodim dalillari, yangisi tepada. --}}
@include('filament.dizayn.card-styles')
@forelse ($getRecord()->inspections()->with('staff')->whereNotNull('review')->latest('id')->get() as $i)
    @include('filament.dizayn.inspection-card', ['i' => $i])
@empty
    <p class="dz-muted">Hali dalil yuborilmagan.</p>
@endforelse
