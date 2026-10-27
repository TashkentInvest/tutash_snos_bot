@php
    $labels = [
        'before_photo' => 'Avvalgi holat (rasm)',
        'before_video' => 'Avvalgi holat (dumaloq video)',
        'after_photo' => 'Keyingi holat (rasm)',
        'after_video' => 'Keyingi holat (dumaloq video)',
    ];
    $items = $getRecord()->media->sortBy(fn ($m) => array_search($m->kind, array_keys($labels)));
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;">
    @forelse ($items as $media)
        <div>
            <div class="text-sm font-medium" style="margin-bottom:.25rem;">{{ $labels[$media->kind] ?? $media->kind }}</div>
            @if (! $media->path)
                <div class="text-sm text-gray-500">Fayl yuklanmoqda…</div>
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
