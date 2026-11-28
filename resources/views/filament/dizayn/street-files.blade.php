{{-- Ko'chaning konsepsiyasi: birinchi PDF shu yerda ochiladi, hammasi havola sifatida. --}}
@include('filament.dizayn.card-styles')
@php($files = \App\Models\Dizayn\StreetFile::query()->where('street_id', $getRecord()->street_id)->orderBy('sort_order')->orderBy('id')->get())
@forelse ($files as $f)
    <p style="margin:0 0 6px"><a href="{{ route('admin.dizayn.file', $f->id) }}" target="_blank" class="dz-link">{{ $f->file_name }}</a>
        <span class="dz-muted">({{ max(1, round($f->size / 1048576)) }} MB)</span></p>
@empty
    <p class="dz-muted">Bu ko‘chaga PDF yuklanmagan.</p>
@endforelse
@if ($files->isNotEmpty())
    <iframe src="{{ route('admin.dizayn.file', $files->first()->id) }}" title="PDF" loading="lazy"
            style="width:100%;height:calc(100vh - 16rem);min-height:480px;border:1px solid rgba(127,127,127,.3);border-radius:8px;margin-top:6px"></iframe>
@endif
