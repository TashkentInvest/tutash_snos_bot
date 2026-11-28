{{-- Dizayn-kod boti: fuqaro ro'yxatdan o'tishda yuborgan obyekt rasmlari (fayl — bot papkasida, faqat admin ko'radi) --}}
@php($photos = $getRecord()->photos()->orderBy('id')->get())
<div style="display:flex;flex-wrap:wrap;gap:.75rem;">
    @foreach ($photos as $ph)
        <a href="{{ route('admin.dizayn.photo', $ph->id) }}" target="_blank" style="display:block;">
            <img src="{{ route('admin.dizayn.photo', $ph->id) }}" loading="lazy" alt="rasm"
                 style="width:12rem;height:9rem;object-fit:cover;border-radius:.6rem;border:1px solid rgba(128,128,128,.25);">
        </a>
    @endforeach
</div>
