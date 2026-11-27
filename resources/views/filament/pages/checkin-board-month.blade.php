{{-- Soatlik qayd — oylik statistika: har kun «javob / so'rov» (rang — foiz), ostida ertalabki davomat belgisi; oxirida oy jami. --}}
@php
    $pct = fn ($p) => $p === null ? '—' : str_replace('.', ',', (string) $p).'%';
    $bg = fn (int $present, int $total) => ! $total ? 'transparent'
        : ($present / $total >= .8 ? 'rgba(22,163,74,.16)' : ($present / $total >= .5 ? 'rgba(217,119,6,.18)' : 'rgba(220,38,38,.16)'));
    $attDot = [\App\Models\Attendance::PRESENT => ['#16a34a', 'Ertalabki davomat: keldi'], \App\Models\Attendance::ABSENT => ['#d97706', 'Sabab bilan yo‘q'],
        \App\Models\Attendance::MISSED => ['#dc2626', 'Davomatga javob bermagan']];
    $g = $m['grand'];
@endphp

{{-- oy jami — kartochkalar --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem;">
    <x-filament::section>
        <div style="font-size:.8rem;opacity:.7;">Soatlik qayd — javob berilgan</div>
        <div style="font-size:1.6rem;font-weight:800;">{{ $g['present'] }} <span style="font-size:.95rem;font-weight:500;opacity:.7;">/ {{ $g['total'] }} so‘rov</span></div>
        <div style="font-size:.85rem;font-weight:600;color:{{ ($g['pct'] ?? 0) >= 80 ? '#16a34a' : (($g['pct'] ?? 0) >= 50 ? '#d97706' : '#dc2626') }};">{{ $pct($g['pct']) }}</div>
    </x-filament::section>
    <x-filament::section>
        <div style="font-size:.8rem;opacity:.7;">Javob bermagan so‘rovlar</div>
        <div style="font-size:1.6rem;font-weight:800;color:#dc2626;">{{ $g['missed'] }}</div>
    </x-filament::section>
    <x-filament::section>
        <div style="font-size:.8rem;opacity:.7;">Ertalabki davomat — kelgan kunlar</div>
        <div style="font-size:1.6rem;font-weight:800;">{{ $g['att_present'] }} <span style="font-size:.95rem;font-weight:500;opacity:.7;">/ {{ $g['att_total'] }}</span></div>
    </x-filament::section>
    <x-filament::section>
        <div style="font-size:.8rem;opacity:.7;">Davr</div>
        <div style="font-size:1.1rem;font-weight:700;">{{ $m['from']->format('m.Y') }}</div>
        <div style="font-size:.8rem;opacity:.7;">{{ count($m['days']) }} kun · {{ $m['rows']->count() }} xodim</div>
    </x-filament::section>
</div>

<x-filament::section>
    @if ($m['rows']->isEmpty() || ! $m['days'])
        <div style="font-size:.875rem;">Bu oy uchun ma’lumot yo‘q.</div>
    @else
        <div style="font-size:.8rem;opacity:.75;margin-bottom:.6rem;">
            Har katakda: javob berilgan / yuborilgan soatlik so‘rov (yashil ≥ 80%, sariq ≥ 50%, qizil — kam). Nuqta — ertalabki davomat
            (<span style="color:#16a34a;">●</span> keldi, <span style="color:#d97706;">●</span> sabab bilan, <span style="color:#dc2626;">●</span> javob bermagan).
            Kunni bosing — o‘sha kunning jadvali ochiladi. Eng past natijali xodimlar tepada.
        </div>
        <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;font-size:.75rem;min-width:100%;">
                <thead>
                    <tr style="background:rgba(112,173,71,.18);">
                        <th style="{{ $cell }}text-align:left;font-weight:600;position:sticky;left:0;background:inherit;min-width:12rem;">Xodim</th>
                        @foreach ($m['days'] as $d)
                            <th style="{{ $cell }}font-weight:600;padding:.35rem .2rem;{{ $d->isWeekend() ? 'opacity:.55;' : '' }}">
                                <button type="button" wire:click="$set('day', '{{ $d->toDateString() }}'); $set('mode', 'day')" title="{{ $d->format('d.m.Y') }}" style="font-weight:600;">{{ $d->day }}</button>
                            </th>
                        @endforeach
                        <th style="{{ $cell }}font-weight:600;">Qayd</th>
                        <th style="{{ $cell }}font-weight:600;">Foiz</th>
                        <th style="{{ $cell }}font-weight:600;">Javobsiz</th>
                        <th style="{{ $cell }}font-weight:600;">Davomat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($m['rows'] as $row)
                        @php($s = $row['sum'])
                        <tr>
                            <td style="{{ $cell }}text-align:left;white-space:nowrap;position:sticky;left:0;background:var(--g-bg-strong, #fff);">
                                {{ $row['staff']->full_name }}
                                <div style="opacity:.6;">{{ $row['staff']->isContract() ? 'Shartnoma' : 'Xodim' }}</div>
                            </td>
                            @foreach ($m['days'] as $d)
                                @php($c = $row['cells'][$d->day])
                                <td style="{{ $cell }}padding:.3rem .15rem;background:{{ $bg($c['present'], $c['total']) }};white-space:nowrap;">
                                    @if ($c['total'])
                                        <span style="font-weight:600;">{{ $c['present'] }}</span><span style="opacity:.55;">/{{ $c['total'] }}</span>
                                    @else
                                        <span style="opacity:.3;">—</span>
                                    @endif
                                    @if ($c['att'] && isset($attDot[$c['att']]))
                                        <div style="line-height:1;color:{{ $attDot[$c['att']][0] }};" title="{{ $attDot[$c['att']][1] }}">●</div>
                                    @endif
                                </td>
                            @endforeach
                            <td style="{{ $cell }}font-weight:600;white-space:nowrap;">{{ $s['present'] }} / {{ $s['total'] }}</td>
                            <td style="{{ $cell }}font-weight:700;color:{{ $s['pct'] === null ? 'inherit' : ($s['pct'] >= 80 ? '#16a34a' : ($s['pct'] >= 50 ? '#d97706' : '#dc2626')) }};">{{ $pct($s['pct']) }}</td>
                            <td style="{{ $cell }}color:{{ $s['missed'] ? '#dc2626' : 'inherit' }};">{{ $s['missed'] }}</td>
                            <td style="{{ $cell }}white-space:nowrap;" title="keldi / so‘rov · sabab bilan: {{ $s['att_absent'] }} · javobsiz: {{ $s['att_missed'] }}">{{ $s['att_present'] }} / {{ $s['att_total'] }}</td>
                        </tr>
                    @endforeach
                    <tr style="background:rgba(91,155,213,.12);font-weight:700;">
                        <td style="{{ $cell }}text-align:left;position:sticky;left:0;background:inherit;">Jami</td>
                        @foreach ($m['days'] as $d)
                            @php($t = $m['dayTotals'][$d->day] ?? ['present' => 0, 'total' => 0])
                            <td style="{{ $cell }}padding:.3rem .15rem;background:{{ $bg($t['present'], $t['total']) }};">
                                @if ($t['total']){{ $t['total'] ? round($t['present'] * 100 / $t['total']) : '' }}%@else<span style="opacity:.3;">—</span>@endif
                            </td>
                        @endforeach
                        <td style="{{ $cell }}white-space:nowrap;">{{ $g['present'] }} / {{ $g['total'] }}</td>
                        <td style="{{ $cell }}">{{ $pct($g['pct']) }}</td>
                        <td style="{{ $cell }}">{{ $g['missed'] }}</td>
                        <td style="{{ $cell }}white-space:nowrap;">{{ $g['att_present'] }} / {{ $g['att_total'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
