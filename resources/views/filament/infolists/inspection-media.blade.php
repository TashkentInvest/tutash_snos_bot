@php
    $labels = [
        'before_photo' => 'Avvalgi holat (rasm)',
        'before_video' => 'Avvalgi holat (dumaloq video)',
        'process_photo' => 'Buzish jarayoni (rasm)',
        'process_video' => 'Buzish jarayoni (dumaloq video)',
        'after_photo' => 'Keyingi holat (rasm)',
        'after_video' => 'Keyingi holat (dumaloq video)',
    ];
    // hujjatlar (sud qarori sahifalari) — rasm va videolardan keyin, yuklangan tartibda
    $items = $getRecord()->media->sortBy(fn ($m) => [$m->isDocument() ? 1 : 0, $m->isDocument() ? $m->id : array_search($m->kind, array_keys($labels))]);
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;">
    @forelse ($items as $media)
        <div>
            <div class="text-sm font-medium" style="margin-bottom:.25rem;">{{ $labels[$media->kind] ?? ($media->isDocument() ? 'Hujjat (sud qarori) '.$loop->iteration : $media->kind) }}</div>
            @if (! $media->path)
                <div class="text-sm text-gray-500">Fayl yuklanmoqda…</div>
            @elseif ($media->isPdf())
                <a href="{{ route('admin.media', ['media' => $media, 'v' => $media->updated_at?->timestamp]) }}" target="_blank"
                   style="display:flex;align-items:center;justify-content:center;gap:.5rem;height:120px;border:1px dashed #8886;border-radius:.5rem;">@svg('fas-file-pdf', '', ['style' => 'width:1.25rem;height:1.25rem;color:#b91c1c;']) PDF faylni ochish</a>
            @elseif ($media->isVideo())
                <video controls preload="metadata" style="width:240px;height:240px;border-radius:50%;object-fit:cover;background:#000;"
                       src="{{ route('admin.media', ['media' => $media, 'v' => $media->updated_at?->timestamp]) }}"></video>
            @else
                <a href="{{ route('admin.media', ['media' => $media, 'v' => $media->updated_at?->timestamp]) }}" target="_blank">
                    <img src="{{ route('admin.media', ['media' => $media, 'v' => $media->updated_at?->timestamp]) }}" alt="" style="width:100%;border-radius:.5rem;" loading="lazy">
                </a>
            @endif
        </div>
    @empty
        <div class="text-sm text-gray-500">Media yo'q.</div>
    @endforelse
</div>
