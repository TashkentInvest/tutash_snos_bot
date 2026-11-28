@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $p = fn ($v) => str_replace('.', ',', (string) $v).'%';
@endphp
<x-filament-widgets::widget>
    @include('filament.partials.ov-styles')
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

        {{-- 4. Dizayn-kod boti (ikkinchi bot) --}}
        @if ($dz)
            <div class="ov-card">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <a href="{{ $dz['url'] }}" style="display:flex;gap:.8rem;align-items:center;">
                        <span class="ov-ico" style="background:rgba(217,119,6,.12);color:#d97706;"><x-filament::icon icon="heroicon-o-paint-brush" style="width:1.3rem;height:1.3rem;"/></span>
                        <span><span class="ov-h" style="margin:0;">Dizayn-kod boti</span><br><span class="ov-muted">@DizaynKodBot · batafsil →</span></span>
                    </a>
                    <div style="display:flex;align-items:center;gap:.75rem;min-width:16rem;flex:0 1 22rem;">
                        <span class="ov-muted" style="white-space:nowrap;">qo‘yildi <b style="color:#16a34a;">{{ $n($dz['installed']) }}</b> / {{ $n($dz['planned']) }}</span>
                        <div class="ov-track"><span style="width:{{ min(100, $dz['pct']) }}%;background:#16a34a;"></span></div>
                        <b style="font-variant-numeric:tabular-nums;">{{ $p($dz['pct']) }}</b>
                    </div>
                </div>
                <div class="ov-dz">
                    @foreach ($dz['rows'] as [$label, $value, $sub, $url, $color])
                        <a @if ($url) href="{{ $url }}" @endif style="display:block;min-width:0;{{ $url ? '' : 'cursor:default;' }}">
                            <div class="ov-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $label }}</div>
                            <div style="font-weight:700;font-size:1.35rem;{{ $color ? 'color:'.$color.';' : '' }}">{{ is_int($value) ? $n($value) : $value }}</div>
                            <div class="ov-muted" style="font-size:.75rem;">{{ $sub }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
