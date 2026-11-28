{{-- Bitta dalil: rasm (aylantirish bilan), video, joy, izoh va ko'rib chiqish natijasi. --}}
@php($kindLabel = \App\Models\Dizayn\Inspection::KINDS[$i->kind] ?? $i->kind)
<div class="dz-card">
    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
        <b>{{ $kindLabel }}</b>
        <span>{{ \App\Models\Dizayn\Inspection::REVIEWS[$i->review] ?? '' }}@if ($i->reviewed_by) · {{ $i->reviewed_by }}@endif</span>
    </div>
    <div class="dz-muted" style="margin:4px 0 8px">
        {{ \App\Support\Latin::of($i->staff?->full_name) }} · {{ $i->submitted_at?->format('d.m.Y H:i') }}
        @if ($i->objectMapUrl()) · <a href="{{ $i->objectMapUrl() }}" target="_blank" class="dz-link">obyekt joyi (xodim belgilagan)</a>@endif
        @if ($i->mapUrl()) · <a href="{{ $i->mapUrl() }}" target="_blank" class="dz-link">xodim turgan joy</a>@endif
        @if ($i->accuracy) · aniqlik ±{{ (int) $i->accuracy }} m @endif
        @if ($i->distance_m !== null) · xodim obyektdan {{ (int) $i->distance_m }} m @endif
    </div>
    <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px">
        @if ($i->photo)
            @php($src = route('admin.dizayn.inspection-media', [$i->id, 'photo']))
            <div x-data="{ v: 0, busy: false, turn(dir) { if (this.busy) return; this.busy = true;
                    fetch('{{ route('admin.dizayn.inspection-rotate', [$i->id, '__D__']) }}'.replace('__D__', dir), { method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
                    .then(r => { if (r.ok) this.v++; }).finally(() => this.busy = false); } }">
                <a :href="'{{ $src }}?v=' + v" href="{{ $src }}" target="_blank"><img :src="'{{ $src }}?v=' + v" src="{{ $src }}" alt="" loading="lazy" style="width:100%;border-radius:8px"></a>
                <div style="display:flex;gap:6px;margin-top:4px;font-size:13px">
                    <button type="button" @click="turn('left')" :disabled="busy" class="dz-btn">↺ Chapga</button>
                    <button type="button" @click="turn('right')" :disabled="busy" class="dz-btn">↻ O‘ngga</button>
                </div>
            </div>
        @endif
        @if ($i->video)
            <video src="{{ route('admin.dizayn.inspection-media', [$i->id, 'video']) }}" controls preload="metadata" style="width:100%;border-radius:8px;background:#000"></video>
        @endif
    </div>
    @if ($i->comment)
        <p style="margin:10px 0 0;font-weight:600;white-space:pre-wrap">{{ $i->comment }}</p>
    @endif
    @if ($i->review_note)
        <p style="margin:6px 0 0;font-size:13px">Admin izohi: {{ $i->review_note }}</p>
    @endif
</div>
