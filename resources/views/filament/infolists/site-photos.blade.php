<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;">
    @foreach ($getRecord()->photos ?? [] as $path)
        @php($url = route('admin.site-photo', ['path' => $path]))
        <a href="{{ $url }}" target="_blank" style="display:block;">
            <img src="{{ $url }}" alt="Obyekt rasmi" loading="lazy"
                 style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:.5rem;border:1px solid rgba(128,128,128,.3);">
        </a>
    @endforeach
</div>
