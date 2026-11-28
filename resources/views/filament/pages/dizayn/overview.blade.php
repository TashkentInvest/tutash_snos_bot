@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $p = fn ($v) => str_replace('.', ',', (string) $v).'%';
    $maxDay = max(1, max($downloads), max($users));
    $maxDistrict = max(1, (int) $districts->max());
@endphp
<x-filament-panels::page>
    @include('filament.partials.ov-styles')
    <div class="ov">
        {{-- 1. asosiy ko'rsatkichlar --}}
        <div class="ov-grid ov-k">
            <a href="{{ $urls['users'] }}" class="ov-card" style="display:block;">
                <div class="ov-label">Bot foydalanuvchilari</div>
                <div class="ov-big" style="margin-top:.35rem;">{{ $n($t['users']) }}</div>
                <div class="ov-muted" style="margin-top:.4rem;">ro‘yxatdan o‘tgan: <b style="color:var(--ov-text);">{{ $n($t['registered']) }}</b> · bugun yangi: {{ $t['new_today'] }} · bugun faol: {{ $t['active_today'] }}</div>
                @if ($t['blocked'])<div class="ov-muted">botni bloklagan: {{ $t['blocked'] }}</div>@endif
            </a>
            <a href="{{ $urls['downloads'] }}" class="ov-card" style="display:block;">
                <div class="ov-label">Yuklab olingan konsepsiyalar</div>
                <div class="ov-big" style="margin-top:.35rem;color:#1d4ed8;">{{ $n($t['downloads']) }}</div>
                <div class="ov-muted" style="margin-top:.4rem;">bugun: <b style="color:var(--ov-text);">{{ $n($t['downloads_today']) }}</b>
                    @if ($book->sum()) · ko‘cha konsepsiyalari: {{ $n($t['downloads'] - $book->sum()) }}@endif</div>
                @if ($book->sum())
                    <div class="ov-muted">umumiy dizayn-kod kitobi: {{ $n($book->sum()) }} ({{ $book->map(fn ($v, $k) => $k.' '.$n($v))->join(' · ') }})</div>
                @endif
            </a>
            <a href="{{ $t['feedback_open'] ? $urls['feedback_open'] : $urls['feedback'] }}" class="ov-card" style="display:block;">
                <div class="ov-label">Fuqarolar murojaatlari</div>
                <div class="ov-big" style="margin-top:.35rem;color:{{ $t['feedback_open'] ? '#dc2626' : 'var(--ov-text)' }};">{{ $n($t['feedback_open']) }} <span style="font-size:1rem;font-weight:600;">javobsiz</span></div>
                <div class="ov-muted" style="margin-top:.4rem;">jami: {{ $n($t['feedback']) }} · bugun: {{ $t['feedback_today'] }}</div>
            </a>
            <div class="ov-card">
                <div class="ov-label">Dizayn-kod bo‘yicha qo‘yildi</div>
                <div class="ov-big" style="margin-top:.35rem;color:#16a34a;">{{ $n($t['installed']) }} <span style="font-size:1rem;font-weight:600;color:var(--ov-muted);">/ {{ $n($t['planned']) }}</span></div>
                <div style="display:flex;align-items:center;gap:.75rem;margin-top:.6rem;">
                    <div class="ov-track"><span style="width:{{ min(100, $t['pct']) }}%;background:#16a34a;"></span></div>
                    <span class="ov-muted" style="min-width:3rem;text-align:right;">{{ $p($t['pct']) }}</span>
                </div>
                <div class="ov-muted" style="margin-top:.4rem;">peshtaxta yechildi: {{ $n($t['removed']) }} · <a href="{{ $urls['map'] }}" style="color:#3b82f6;">xaritada ko‘rish</a></div>
            </div>
        </div>

        {{-- 2. obyektlar va xodimlar ishi --}}
        <div class="ov-grid ov-k">
            <a href="{{ $urls['objects'] }}" class="ov-card" style="display:block;">
                <div class="ov-label">Kiritilgan tadbirkorlar</div>
                <div class="ov-big" style="margin-top:.35rem;">{{ $n($t['objects']) }} <span style="font-size:1rem;font-weight:600;color:var(--ov-muted);">/ {{ $n($t['planned']) }}</span></div>
                <div class="ov-muted" style="margin-top:.4rem;">bugun: <b style="color:var(--ov-text);">+{{ $t['objects_today'] }}</b> · ro‘yxatda {{ $n($t['planned']) }} ta obyekt</div>
            </a>
            <a href="{{ $urls['inspections'] }}" class="ov-card" style="display:block;">
                <div class="ov-label">Dalillar tasdiq kutmoqda</div>
                <div class="ov-big" style="margin-top:.35rem;color:{{ $t['review'] ? '#d97706' : '#16a34a' }};">{{ $n($t['review']) }}</div>
                <div class="ov-muted" style="margin-top:.4rem;">bugun yuborilgan: <b style="color:var(--ov-text);">{{ $t['evidence_today'] }}</b></div>
            </a>
            <a href="{{ $urls['process'] }}" class="ov-card" style="display:block;">
                <div class="ov-label">Jarayonli tasdiqlangan</div>
                <div class="ov-big" style="margin-top:.35rem;color:#3b82f6;">{{ $n($t['confirming']) }}</div>
                <div class="ov-muted" style="margin-top:.4rem;">kamchilik tuzatilishi kutilmoqda</div>
            </a>
            <div class="ov-card">
                <div class="ov-label">Tuman xodimlari</div>
                <div class="ov-big" style="margin-top:.35rem;color:{{ $t['staff_linked'] < $t['staff'] ? '#d97706' : 'var(--ov-text)' }};">{{ $t['staff_linked'] }} <span style="font-size:1rem;font-weight:600;color:var(--ov-muted);">/ {{ $t['staff'] }}</span></div>
                <div class="ov-muted" style="margin-top:.4rem;">botga bog‘langan · bugun faol: <b style="color:var(--ov-text);">{{ $t['staff_active_today'] }}</b></div>
            </div>
        </div>

        {{-- 3. 14 kunlik faollik + tumanlar --}}
        <div class="ov-grid ov-mid">
            <div class="ov-card" style="display:flex;flex-direction:column;">
                <div class="ov-h" style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap;margin-bottom:.2rem;">
                    <span>Oxirgi 14 kun</span>
                    <span class="ov-muted" style="font-weight:500;"><span style="display:inline-block;width:.7rem;height:.7rem;border-radius:.2rem;background:#3b82f6;vertical-align:middle;"></span> yuklab olishlar
                        &nbsp;<span style="display:inline-block;width:.7rem;height:.7rem;border-radius:.2rem;background:#93c5fd;vertical-align:middle;"></span> yangi foydalanuvchilar</span>
                </div>
                <div class="ov-muted" style="margin-bottom:.9rem;">14 kunda: <b style="color:var(--ov-text);">{{ $n(array_sum($downloads)) }}</b> yuklab olish · <b style="color:var(--ov-text);">{{ $n(array_sum($users)) }}</b> yangi foydalanuvchi</div>
                <div style="flex:1;min-height:13rem;display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:.35rem;">
                    @foreach ($downloads as $day => $v)
                        @php($u = $users[$day] ?? 0)
                        <div style="display:flex;flex-direction:column;min-width:0;" title="{{ \Carbon\Carbon::parse($day)->format('d.m') }}: {{ $v }} yuklab olish, {{ $u }} yangi foydalanuvchi">
                            <div style="flex:1;display:flex;gap:3px;align-items:flex-end;justify-content:center;border-bottom:1px solid var(--ov-border);">
                                @foreach ([[$v, '#3b82f6'], [$u, '#93c5fd']] as [$val, $color])
                                    <div style="width:44%;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;">
                                        <span style="font-size:.66rem;line-height:1.1;color:var(--ov-muted);margin-bottom:2px;font-variant-numeric:tabular-nums;">{{ $val ?: '' }}</span>
                                        <span style="width:100%;background:{{ $color }};border-radius:.2rem .2rem 0 0;height:{{ $val ? max(2, round($val / $maxDay * 88)) : 0 }}%;"></span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="ov-muted" style="font-size:.68rem;text-align:center;margin-top:.3rem;">{{ \Carbon\Carbon::parse($day)->format('d.m') }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="ov-card">
                <div class="ov-h">Tumanlar bo‘yicha yuklab olishlar</div>
                <div class="ov-muted" style="margin:-.4rem 0 .6rem;">ko‘cha konsepsiyalari (umumiy kitobsiz)</div>
                @forelse ($districts as $name => $v)
                    <div class="ov-bar" style="grid-template-columns:8.5rem minmax(0,1fr) 3rem;">
                        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ preg_replace('/\s+tumani$/u', '', $name) }}</span>
                        <span class="ov-track" style="height:.7rem;border-radius:.25rem;"><span style="width:{{ round($v / $maxDistrict * 100) }}%;background:#1d4ed8;border-radius:.25rem;"></span></span>
                        <span style="text-align:right;font-variant-numeric:tabular-nums;">{{ $n($v) }}</span>
                    </div>
                @empty
                    <div class="ov-muted">Hali yuklab olish yo‘q.</div>
                @endforelse
            </div>
        </div>

        {{-- 4. ko'chalar xatlovi (Dizayn bazasidagi ko'chalar): noturar obyektlar va kiritilganlar --}}
        @php($share = fn ($a, $b) => $b ? round($a * 100 / $b, 1) : 0)
        <div class="ov-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div>
                    <div class="ov-h" style="margin:0;">Ko‘chalar xatlovi</div>
                    <div class="ov-muted" style="margin-top:.2rem;">Dizayn-kod ko‘chalari: noturar obyektlar va tuman xodimlari kiritgan tadbirkorlar</div>
                </div>
                <div style="display:flex;gap:1.75rem;flex-wrap:wrap;">
                    <div><div class="ov-muted">Ko‘chalar</div><div style="font-weight:700;font-size:1.35rem;">{{ $n($xatlov['streets']) }}</div></div>
                    <div><div class="ov-muted">Noturar obyektlar</div><div style="font-weight:700;font-size:1.35rem;">{{ $n($xatlov['nonres']) }}</div></div>
                    <div><div class="ov-muted">Kiritilgan tadbirkorlar</div><div style="font-weight:700;font-size:1.35rem;color:#3b82f6;">{{ $n($xatlov['entered']) }} <span class="ov-muted" style="font-size:.85rem;font-weight:500;">{{ $p($share($xatlov['entered'], $xatlov['nonres'])) }}</span></div></div>
                    <div><div class="ov-muted">Qo‘yildi</div><div style="font-weight:700;font-size:1.35rem;color:#16a34a;">{{ $n($xatlov['installed']) }}</div></div>
                </div>
            </div>
            <div class="ov-xt">
                <div class="ov-xt-row ov-xt-head ov-muted">
                    <span>Tuman / ko‘cha</span><span>Ko‘chalar</span><span>Noturar obyektlar</span><span>Kiritilgan tadbirkorlar</span><span>Qamrov</span><span>Qo‘yildi</span>
                </div>
                @foreach ($xatlov['districts'] as $d)
                    @php($pct = $share($d['entered'], $d['nonres']))
                    <details>
                        <summary class="ov-xt-row">
                            <span style="font-weight:600;"><span class="ov-xt-caret">›</span> {{ $d['name'] }}</span>
                            <span>{{ $d['count'] }}</span>
                            <span>{{ $n($d['nonres']) }}</span>
                            <span style="color:#3b82f6;font-weight:600;">{{ $n($d['entered']) }}</span>
                            <span style="display:flex;align-items:center;gap:.5rem;"><span class="ov-track" style="height:.45rem;"><span style="width:{{ min(100, $pct) }}%;background:#3b82f6;"></span></span><span style="min-width:3rem;text-align:right;">{{ $p($pct) }}</span></span>
                            <span style="color:#16a34a;font-weight:600;">{{ $n($d['installed']) }}</span>
                        </summary>
                        @foreach ($d['streets'] as $i => $s)
                            <div class="ov-xt-row ov-xt-street">
                                <span>{{ $i + 1 }}. {{ $s['name'] }}</span>
                                <span></span>
                                <span>{{ $s['nonres'] === null ? '—' : $n($s['nonres']) }}</span>
                                <span>{{ $s['entered'] ?: '—' }}</span>
                                <span>{{ $s['nonres'] ? $p($share($s['entered'], $s['nonres'])) : '' }}</span>
                                <span>{{ $s['installed'] ?: '—' }}</span>
                            </div>
                        @endforeach
                    </details>
                @endforeach
                <div class="ov-xt-row" style="font-weight:700;border-top:1px solid var(--ov-border);">
                    <span>Jami</span><span>{{ $xatlov['streets'] }}</span><span>{{ $n($xatlov['nonres']) }}</span>
                    <span style="color:#3b82f6;">{{ $n($xatlov['entered']) }}</span><span>{{ $p($share($xatlov['entered'], $xatlov['nonres'])) }}</span>
                    <span style="color:#16a34a;">{{ $n($xatlov['installed']) }}</span>
                </div>
            </div>
            <div class="ov-muted" style="margin-top:.5rem;font-size:.75rem;">Qamrov — kiritilgan tadbirkorlar noturar obyektlar soniga nisbatan. Tuman ustiga bosing — ko‘chalari ochiladi.</div>
        </div>

        {{-- 5. eng ko'p yuklangan ko'chalar --}}
        <div class="ov-card">
            <div class="ov-h">Eng ko‘p yuklab olingan ko‘chalar</div>
            @forelse ($top as $i => $r)
                <div class="ov-row" style="display:grid;grid-template-columns:1.8rem minmax(0,1fr) 10rem 4rem;gap:.5rem;">
                    <span class="ov-muted">{{ $i + 1 }}</span>
                    <span><b>{{ $r['street'] }}</b></span>
                    <span class="ov-muted">{{ $r['district'] }}</span>
                    <span style="text-align:right;font-weight:700;">{{ $n($r['total']) }}</span>
                </div>
            @empty
                <div class="ov-muted">Hali yuklab olish yo‘q.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
