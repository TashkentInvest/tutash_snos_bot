@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $p = fn ($v) => str_replace('.', ',', (string) $v).'%';
@endphp
<x-filament-widgets::widget>
    <style>
        .ov{display:flex;flex-direction:column;gap:1rem;--ov-card:#fff;--ov-border:#e5e7eb;--ov-muted:#64748b;--ov-text:#0f172a;--ov-track:#eef0f3}
        .dark .ov{--ov-card:rgb(24 24 27);--ov-border:rgba(255,255,255,.1);--ov-muted:#9ca3af;--ov-text:#f4f4f5;--ov-track:rgba(255,255,255,.08)}
        .ov-card{background:var(--ov-card);border:1px solid var(--ov-border);border-radius:.875rem;box-shadow:0 1px 2px rgba(15,23,42,.04);padding:1.1rem 1.25rem;color:var(--ov-text)}
        .ov-grid{display:grid;gap:1rem}
        .ov-k{grid-template-columns:repeat(4,minmax(0,1fr))}
        .ov-mid{grid-template-columns:minmax(0,1.85fr) minmax(0,1fr)}
        .ov-low{grid-template-columns:repeat(3,minmax(0,1fr))}
        @media (max-width:1280px){.ov-k{grid-template-columns:repeat(2,minmax(0,1fr))}.ov-mid,.ov-low{grid-template-columns:minmax(0,1fr)}}
        @media (max-width:640px){.ov-k{grid-template-columns:minmax(0,1fr)}.ov-bar{grid-template-columns:6.5rem minmax(0,1fr) 3.2rem}}
        .ov-label{font-weight:600;font-size:.95rem}
        .ov-big{font-size:2.15rem;font-weight:800;line-height:1.1;letter-spacing:-.02em}
        .ov-muted{color:var(--ov-muted);font-size:.8rem}
        .ov-track{height:.45rem;border-radius:999px;background:var(--ov-track);overflow:hidden;flex:1}
        .ov-track>span{display:block;height:100%;border-radius:999px}
        .ov-h{font-size:1.05rem;font-weight:700;margin-bottom:.9rem}
        .ov-bar{display:grid;grid-template-columns:8.5rem minmax(0,1fr) 3.6rem;align-items:center;gap:.75rem;font-size:.85rem;padding:.22rem 0;color:inherit}
        .ov-bar:hover .ov-track>span{filter:brightness(1.12)}
        .ov-ico{width:2.6rem;height:2.6rem;border-radius:.7rem;display:flex;align-items:center;justify-content:center;flex:none}
        .ov a{color:inherit;text-decoration:none}
        .ov-row{display:flex;justify-content:space-between;gap:.75rem;padding:.3rem 0;border-bottom:1px solid var(--ov-border);font-size:.85rem}
        .ov-row:last-child{border-bottom:0}
    </style>
    <div class="ov">
        {{-- 1. asosiy ko'rsatkichlar --}}
        <div class="ov-grid ov-k">
            @foreach ($kpis as $k)
                <a href="{{ $k['url'] }}" class="ov-card" style="display:block;">
                    <div class="ov-label">{{ $k['label'] }}</div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-top:.35rem;">
                        <div class="ov-big" style="color:{{ $loop->first ? 'var(--ov-text)' : $k['color'] }};white-space:nowrap;">
                            {{ $n($k['value']) }}@if ($k['showPct'] ?? false)<span style="font-size:1.45rem;"> · {{ $p($k['pct']) }}</span>@endif
                        </div>
                        @if ($k['spark'])
                            <svg viewBox="0 0 100 32" preserveAspectRatio="none" style="width:45%;max-width:11rem;height:2.6rem;flex:none;" aria-hidden="true">
                                <defs><linearGradient id="g{{ $loop->index }}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $k['color'] }}" stop-opacity=".22"/><stop offset="1" stop-color="{{ $k['color'] }}" stop-opacity="0"/></linearGradient></defs>
                                <path d="{{ $k['spark']['area'] }}" fill="url(#g{{ $loop->index }})"/>
                                <path d="{{ $k['spark']['line'] }}" fill="none" stroke="{{ $k['color'] }}" stroke-width="1.6" vector-effect="non-scaling-stroke"/>
                            </svg>
                        @endif
                    </div>
                    <div style="display:flex;align-items:center;gap:.75rem;margin-top:.7rem;">
                        <div class="ov-track"><span style="width:{{ min(100, max($k['pct'], $k['value'] ? 1.5 : 0)) }}%;background:{{ $k['color'] }};"></span></div>
                        <span class="ov-muted" style="min-width:3rem;text-align:right;">{{ $p($k['pct']) }}</span>
                    </div>
                    @if ($k['note'] ?? null)<div class="ov-muted" style="margin-top:.4rem;">{{ $k['note'] }}</div>@endif
                </a>
            @endforeach
        </div>

        {{-- 2. tumanlar kesimida bajarilish + davomat --}}
        <div class="ov-grid ov-mid">
            <div class="ov-card">
                <div class="ov-h" style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;">
                    <span>Tumanlar kesimida bajarilish</span>
                    <span class="ov-muted">shahar bo‘yicha: <b style="color:var(--ov-text);">{{ $p($districtTotal['pct']) }}</b> · {{ $day->format('d.m.Y') }}</span>
                </div>
                @foreach ($districts as $d)
                    <a href="{{ $d['url'] }}" class="ov-bar">
                        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $d['name'] }}</span>
                        <span class="ov-track" style="height:.8rem;border-radius:.25rem;"><span style="width:{{ min(100, $d['pct']) }}%;background:#1d4ed8;border-radius:.25rem;"></span></span>
                        <span style="text-align:right;font-variant-numeric:tabular-nums;">{{ $p($d['pct']) }}</span>
                    </a>
                @endforeach
                <div class="ov-muted" style="margin-top:.6rem;">Bajarilish — buzilgan va sud qarori bekor qilingan obyektlar ulushi. Tumanni bosing — obyektlari ochiladi.</div>
            </div>

            <div class="ov-card">
                <div class="ov-h">{{ $attendance['day']->isToday() ? 'Bugungi davomat' : 'Davomat — '.$attendance['day']->format('d.m.Y') }}</div>
                @php($tot = $attendance['total'])
                <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;">
                    <a href="{{ $attendance['url'] }}" style="position:relative;width:9rem;height:9rem;flex:none;margin:auto;">
                        <svg viewBox="0 0 42 42" style="width:100%;height:100%;transform:rotate(-90deg);" aria-hidden="true">
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--ov-track)" stroke-width="6"/>
                            @php($off = 0)
                            @foreach ($attendance['parts'] as $part)
                                @if ($tot && $part['value'])
                                    @php($len = $part['value'] / $tot * 100)
                                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $part['color'] }}" stroke-width="6" stroke-dasharray="{{ $len }} {{ 100 - $len }}" stroke-dashoffset="{{ -$off }}"/>
                                    @php($off += $len)
                                @endif
                            @endforeach
                        </svg>
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                            <div class="ov-big">{{ $tot }}</div><div class="ov-muted">so‘rov</div>
                        </div>
                    </a>
                    <div style="flex:1;min-width:9.5rem;">
                        @foreach ($attendance['parts'] as $part)
                            <a href="{{ $part['url'] }}" class="ov-row" style="display:grid;grid-template-columns:minmax(0,1fr) auto 3.4rem;gap:.5rem;padding:.6rem 0;white-space:nowrap;">
                                <span style="display:flex;align-items:center;gap:.5rem;"><span style="width:.7rem;height:.7rem;border-radius:.2rem;background:{{ $part['color'] }};flex:none;"></span>{{ $part['label'] }}</span>
                                <b style="text-align:right;">{{ $part['value'] }}</b>
                                <span class="ov-muted" style="text-align:right;">{{ $tot ? $p(round($part['value'] * 100 / $tot, 1)) : '—' }}</span>
                            </a>
                        @endforeach
                        @unless ($tot)<div class="ov-muted" style="margin-top:.5rem;">Bu kun davomat so‘rovi yuborilmagan.</div>@endunless
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. kartochkalar --}}
        <div class="ov-grid ov-low">
            <div class="ov-card">
                <a href="{{ $users['url'] }}" style="display:flex;gap:1rem;align-items:flex-start;">
                    <span class="ov-ico" style="background:rgba(29,78,216,.1);color:#1d4ed8;"><x-filament::icon icon="heroicon-o-users" style="width:1.4rem;height:1.4rem;"/></span>
                    <span>
                        <div class="ov-label">Bot foydalanuvchilari</div>
                        <div class="ov-big" style="margin-top:.3rem;">{{ $n($users['total']) }} <span class="ov-muted" style="font-size:.85rem;font-weight:500;">bugun faol: {{ $users['today'] }}</span></div>
                    </span>
                </a>
                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem;margin-top:.8rem;padding-top:.7rem;border-top:1px solid var(--ov-border);">
                    @foreach ($users['rows'] as $r)
                        <a href="{{ $r['url'] }}" style="display:block;min-width:0;">
                            <div class="ov-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $r['label'] }}</div>
                            <div style="font-weight:700;font-size:1.05rem;">{{ $r['value'] }}</div>
                            <div class="ov-muted" style="font-size:.72rem;">{{ $users['total'] ? $p(round($r['value'] * 100 / $users['total'], 1)) : '' }}</div>
                        </a>
                    @endforeach
                </div>
            </div>

            <a href="{{ $leases['url'] }}" class="ov-card" style="display:flex;gap:1rem;align-items:flex-start;">
                <span class="ov-ico" style="background:rgba(22,163,74,.12);color:#16a34a;"><x-filament::icon icon="heroicon-o-document-text" style="width:1.4rem;height:1.4rem;"/></span>
                <span>
                    <div class="ov-label">Ijara shartnomalari</div>
                    <div class="ov-big" style="margin-top:.3rem;color:#16a34a;">{{ $n($leases['count']) }}</div>
                    <div class="ov-muted" style="font-size:.9rem;">oylik to‘lov <b style="color:var(--ov-text);">{{ \App\Filament\Widgets\Overview::money($leases['monthly']) }} so‘m</b></div>
                    <div class="ov-muted">joriy yil: {{ \App\Filament\Widgets\Overview::money($leases['annual']) }} so‘m</div>
                </span>
            </a>

            @if ($dk)
                <a href="{{ $dk['url'] }}" class="ov-card" style="display:flex;gap:1rem;align-items:flex-start;">
                    <span class="ov-ico" style="background:rgba(217,119,6,.12);color:#d97706;"><x-filament::icon icon="heroicon-o-square-3-stack-3d" style="width:1.4rem;height:1.4rem;"/></span>
                    <span>
                        <div class="ov-label">Dizayn-kod ko‘chalari</div>
                        <div class="ov-big" style="margin-top:.3rem;">{{ $dk['streets'] }} <span class="ov-muted" style="font-size:.95rem;font-weight:500;">· {{ str_replace('.', ',', (string) $dk['km']) }} km</span></div>
                        <div style="color:#dc2626;font-weight:600;font-size:.9rem;">{{ $dk['illegal'] }} ta noqonuniy obyekt</div>
                        <div class="ov-muted">ko‘chadan {{ $dk['buffer'] }} m ichida · buzilgan: {{ $dk['demolished'] }}</div>
                    </span>
                </a>
            @else
                <div class="ov-card ov-muted" style="display:flex;align-items:center;">Dizayn-kod ma’lumoti hozircha olinmadi.</div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
