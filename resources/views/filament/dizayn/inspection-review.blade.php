{{-- Ko'rib chiqish oynasi: chapda ko'chaning konsepsiyasi (qanday bo'lishi kerak), o'ngda dalil va oldingilari. --}}
@php($i = $record ?? $getRecord())
@include('filament.dizayn.card-styles')
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px;align-items:start">
    <div>
        <p class="dz-muted" style="margin:0 0 6px">{{ $i->object?->shop_name }}
            @if ($i->object?->owner_name) · {{ $i->object->owner_name }} @endif
            @if ($i->object?->stir) · STIR {{ $i->object->stir }} @endif</p>
        <p class="dz-muted" style="margin:0 0 6px">Ko‘chaning konsepsiyasi (kerakli ko‘rinish):</p>
        @forelse (\App\Models\Dizayn\StreetFile::query()->where('street_id', $i->object?->street_id)->orderBy('sort_order')->orderBy('id')->get() as $f)
            <p style="margin:0 0 4px"><a href="{{ route('admin.dizayn.file', $f->id) }}" target="_blank" class="dz-link">{{ $f->file_name }}</a></p>
        @empty
            <p class="dz-muted">PDF yuklanmagan.</p>
        @endforelse
    </div>
    <div>@include('filament.dizayn.inspection-card', ['i' => $i])
        @php($earlier = \App\Models\Dizayn\Inspection::query()->with('staff')->where('object_id', $i->object_id)->where('id', '!=', $i->id)
            ->where('step', 'submitted')->orderByDesc('submitted_at')->orderByDesc('id')->get())
        @if ($earlier->isNotEmpty())
            <details style="margin-top:6px">
                <summary style="cursor:pointer;font-weight:600">Shu obyekt bo‘yicha oldingi dalillar ({{ $earlier->count() }})</summary>
                <div style="margin-top:8px">@foreach ($earlier as $e) @include('filament.dizayn.inspection-card', ['i' => $e]) @endforeach</div>
            </details>
        @endif
    </div>
</div>
