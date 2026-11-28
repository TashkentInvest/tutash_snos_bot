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
                <div class="ov-muted" style="margin-top:.4rem;">obyektlar: {{ $n($t['objects']) }} · peshtaxta yechildi: {{ $n($t['removed']) }} · tasdiq kutmoqda: {{ $t['review'] }}</div>
            </div>
        </div>

        {{-- 2. 14 kunlik faollik + tumanlar --}}
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

        {{-- 3. eng ko'p yuklangan ko'chalar --}}
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
