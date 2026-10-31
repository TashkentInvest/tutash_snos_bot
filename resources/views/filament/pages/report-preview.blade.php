<div style="display:flex;flex-direction:column;gap:1rem;font-size:.875rem;">
    <div>
        <div style="font-weight:600;margin-bottom:.25rem;">Kimlarga boradi ({{ $recipients->count() }}):</div>
        @forelse ($recipients as $r)
            <span style="display:inline-block;margin:0 .35rem .35rem 0;padding:.15rem .5rem;border-radius:999px;background:rgba(128,128,128,.15);">
                {{ $r->full_name }}{{ $r->is_global ? ' (admin)' : '' }}
            </span>
        @empty
            <div style="color:#c0392b;">Hisobot oluvchilar yo‘q. Xodimlar sahifasida «Hisobot» belgisini yoqing.</div>
        @endforelse
    </div>

    <div>
        <div style="font-weight:600;margin-bottom:.25rem;">Botda shunday ko‘rinadi:</div>
        <div style="border:1px solid rgba(128,128,128,.3);border-radius:.75rem;padding:.75rem;background:rgba(128,128,128,.06);">
            @if ($image)
                <img src="{{ $image }}" alt="Svod jadval" loading="lazy" style="width:100%;border-radius:.5rem;margin-bottom:.75rem;background:#fff;">
            @endif
            @forelse ($texts as $text)
                {{-- bot xabari (Telegram HTML: matnlar Ui::e bilan ekranlangan) --}}
                <div style="line-height:1.5;{{ $loop->first ? '' : 'margin-top:.75rem;padding-top:.75rem;border-top:1px dashed rgba(128,128,128,.3);' }}">{!! nl2br($text) !!}</div>
            @empty
                <div style="color:#d97706;">{{ $empty ?? 'Bugun uchun ma’lumot yo‘q — hech narsa yuborilmaydi.' }}</div>
            @endforelse
        </div>
    </div>
</div>
