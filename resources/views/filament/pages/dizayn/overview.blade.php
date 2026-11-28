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
                <div class="ov-muted" style="margin-top:.4rem;">bugun: <b style="color:var(--ov-text);">{{ $n($t['downloads_today']) }}</b></div>
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
            <div class="ov-card">
                <div class="ov-h" style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;">
                    <span>Oxirgi 14 kun</span>
                    <span class="ov-muted"><span style="display:inline-block;width:.7rem;height:.7rem;border-radius:.2rem;background:#1d4ed8;vertical-align:middle;"></span> yuklab olishlar
                        &nbsp;<span style="display:inline-block;width:.7rem;height:.7rem;border-radius:.2rem;background:#93c5fd;vertical-align:middle;"></span> yangi foydalanuvchilar</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:.4rem;align-items:end;height:11rem;">
                    @foreach ($downloads as $day => $v)
                        @php($u = $users[$day] ?? 0)
                        <div style="display:flex;flex-direction:column;align-items:center;gap:.25rem;height:100%;justify-content:flex-end;" title="{{ \Carbon\Carbon::parse($day)->format('d.m') }}: {{ $v }} yuklab olish, {{ $u }} yangi foydalanuvchi">
                            <div class="ov-muted" style="font-size:.7rem;">{{ $v ?: '' }}</div>
                            <div style="display:flex;gap:2px;align-items:flex-end;width:100%;justify-content:center;height:100%;">
                                <span style="width:42%;background:#1d4ed8;border-radius:.2rem .2rem 0 0;height:{{ max($v ? 3 : 0, round($v / $maxDay * 100)) }}%;"></span>
                                <span style="width:42%;background:#93c5fd;border-radius:.2rem .2rem 0 0;height:{{ max($u ? 3 : 0, round($u / $maxDay * 100)) }}%;"></span>
                            </div>
                            <div class="ov-muted" style="font-size:.68rem;">{{ \Carbon\Carbon::parse($day)->format('d.m') }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="ov-card">
                <div class="ov-h">Tumanlar bo‘yicha yuklab olishlar</div>
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
