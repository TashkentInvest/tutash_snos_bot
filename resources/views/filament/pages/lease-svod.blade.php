@php
    $m = fn ($v) => number_format((float) $v, 0, ',', ' ');            // so'm (butun)
    $a = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');  // m²
    $t = $d['total'];
    $th = 'padding:10px 12px;font-weight:600;text-align:right;white-space:nowrap;';
    $td = 'padding:9px 12px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;';
@endphp
<x-filament-panels::page>
    {{-- filtr --}}
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">
            <label style="font-size:13px;">Dan<br><input type="date" wire:model.live="from" style="border:1px solid rgba(127,127,127,.35);border-radius:8px;padding:6px 8px;background:transparent;color:inherit;"></label>
            <label style="font-size:13px;">Gacha<br><input type="date" wire:model.live="to" style="border:1px solid rgba(127,127,127,.35);border-radius:8px;padding:6px 8px;background:transparent;color:inherit;"></label>
            <div style="display:flex;gap:6px;">
                @foreach (['today' => 'Bugun', 'month' => 'Shu oy', 'year' => 'Shu yil', 'all' => 'Hammasi'] as $k => $l)
                    <x-filament::button size="sm" color="gray" wire:click="preset('{{ $k }}')">{{ $l }}</x-filament::button>
                @endforeach
            </div>
            <label style="font-size:13px;display:flex;gap:6px;align-items:center;"><input type="checkbox" wire:model.live="unique"> Takrorlarsiz
                <span style="opacity:.6;">({{ $d['duplicates'] }} ta qayta tuzilgan)</span></label>
            <label style="font-size:13px;display:flex;gap:6px;align-items:center;"><input type="checkbox" wire:model.live="onsite"> Faqat joyida tuzilganlar
                <span style="opacity:.6;">({{ $d['remote'] }} ta uzoqdan)</span></label>
            <div style="margin-left:auto;">
                <x-filament::button tag="a" :href="$xlsx" icon="heroicon-o-arrow-down-tray">Excel</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- asosiy ko'rsatkichlar --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
        @foreach ([
            ['Shartnomalar', $t['count'], 'yuridik '.$t['legal'].' · jismoniy '.$t['individual']],
            ['Jami maydon', $a($t['area']).' m²', 'o‘rtacha '.$a($t['avg_area']).' m²'],
            ['Oylik to‘lov', $m($t['monthly']).' so‘m', 'barcha shartnomalar bo‘yicha'],
            ['Joriy yil to‘lovi', $m($t['annual']).' so‘m', 'boshlanish oyidan dekabrgacha'],
            ['To‘liq yil hajmi', $m($t['yearly']).' so‘m', 'oylik × 12'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div style="font-size:13px;opacity:.65;">{{ $label }}</div>
                <div style="font-size:22px;font-weight:700;margin-top:4px;font-variant-numeric:tabular-nums;">{{ $value }}</div>
                <div style="font-size:12px;opacity:.6;margin-top:2px;">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- tumanlar --}}
    <x-filament::section :heading="'Tumanlar kesimida — '.$d['period']">
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:14px;border-collapse:collapse;">
                <thead><tr style="opacity:.75;border-bottom:1px solid rgba(127,127,127,.35);">
                    <th style="{{ $th }}text-align:left;">Tuman</th>
                    <th style="{{ $th }}">Ko‘chalar</th>
                    <th style="{{ $th }}">Shartnomalar</th>
                    <th style="{{ $th }}">Yur. / Jism.</th>
                    <th style="{{ $th }}">Maydon, m²</th>
                    <th style="{{ $th }}">Tarif, 1 m²/oy</th>
                    <th style="{{ $th }}">Oylik to‘lov</th>
                    <th style="{{ $th }}">Joriy yil</th>
                    <th style="{{ $th }}">To‘liq yil (12 oy)</th>
                    <th style="{{ $th }}" title="Ko‘chadan ruxsat etilgan masofadan uzoqda tuzilgan (cheklov o‘chiq paytda)">Uzoqdan</th>
                </tr></thead>
                <tbody>
                    <tr style="font-weight:700;background:rgba(34,197,94,.10);">
                        <td style="{{ $td }}text-align:left;">JAMI</td>
                        <td style="{{ $td }}">{{ $t['streets'] }}</td>
                        <td style="{{ $td }}">{{ $t['count'] }}</td>
                        <td style="{{ $td }}">{{ $t['legal'] }} / {{ $t['individual'] }}</td>
                        <td style="{{ $td }}">{{ $a($t['area']) }}</td>
                        <td style="{{ $td }}">—</td>
                        <td style="{{ $td }}">{{ $m($t['monthly']) }}</td>
                        <td style="{{ $td }}">{{ $m($t['annual']) }}</td>
                        <td style="{{ $td }}">{{ $m($t['yearly']) }}</td>
                        <td style="{{ $td }}">{{ $t['remote'] ?: '—' }}</td>
                    </tr>
                    @foreach ($d['rows'] as $r)
                        <tr style="border-top:1px solid rgba(127,127,127,.2);{{ $r['count'] ? '' : 'opacity:.55;' }}">
                            <td style="{{ $td }}text-align:left;">
                                <div style="font-weight:600;">{{ $r['name'] }}</div>
                                <div style="font-size:12px;opacity:.6;white-space:normal;max-width:320px;">{{ $r['responsibles'] }}</div>
                            </td>
                            <td style="{{ $td }}">{{ $r['streets'] }}</td>
                            <td style="{{ $td }}font-weight:600;">{{ $r['count'] ?: '—' }}</td>
                            <td style="{{ $td }}">{{ $r['count'] ? $r['legal'].' / '.$r['individual'] : '—' }}</td>
                            <td style="{{ $td }}">{{ $r['count'] ? $a($r['area']) : '—' }}</td>
                            <td style="{{ $td }}">{{ $r['tariff'] ? number_format($r['tariff'], 2, ',', ' ') : '—' }}</td>
                            <td style="{{ $td }}">{{ $r['count'] ? $m($r['monthly']) : '—' }}</td>
                            <td style="{{ $td }}">{{ $r['count'] ? $m($r['annual']) : '—' }}</td>
                            <td style="{{ $td }}">{{ $r['count'] ? $m($r['yearly']) : '—' }}</td>
                            <td style="{{ $td }}{{ $r['remote'] ? 'color:#dc2626;' : '' }}">{{ $r['remote'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="font-size:12px;opacity:.6;margin-top:10px;">
            Faqat Word fayli tayyorlangan shartnomalar hisoblanadi. «Takrorlarsiz» — bir ijarachi va kadastr bo‘yicha qayta tuzilganlardan oxirgisi.
            Oylik to‘lov — maydon × shartnomadagi tarif; «To‘liq yil» — oylik × 12 (keyingi yillar uchun taxminiy hajm).
        </div>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;">
        <x-filament::section heading="Ko‘chalar kesimida">
            @if ($d['streets'])
                <table style="width:100%;font-size:14px;border-collapse:collapse;">
                    <thead><tr style="opacity:.75;"><th style="{{ $th }}text-align:left;">Ko‘cha</th><th style="{{ $th }}">Shartnoma</th><th style="{{ $th }}">m²</th><th style="{{ $th }}">Oylik</th></tr></thead>
                    <tbody>
                        @foreach ($d['streets'] as $r)
                            <tr style="border-top:1px solid rgba(127,127,127,.2);">
                                <td style="{{ $td }}text-align:left;white-space:normal;">{{ $r['name'] }} <span style="font-size:12px;opacity:.6;">· {{ $r['district'] }}</span></td>
                                <td style="{{ $td }}">{{ $r['count'] }}</td>
                                <td style="{{ $td }}">{{ $a($r['area']) }}</td>
                                <td style="{{ $td }}">{{ $m($r['monthly']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div style="opacity:.6;font-size:14px;">Bu davrda shartnoma yo‘q.</div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Xodimlar kesimida">
            @if ($d['staff'])
                <table style="width:100%;font-size:14px;border-collapse:collapse;">
                    <thead><tr style="opacity:.75;"><th style="{{ $th }}text-align:left;">Xodim</th><th style="{{ $th }}">Shartnoma</th><th style="{{ $th }}">m²</th><th style="{{ $th }}">Oxirgisi</th></tr></thead>
                    <tbody>
                        @foreach ($d['staff'] as $r)
                            <tr style="border-top:1px solid rgba(127,127,127,.2);">
                                <td style="{{ $td }}text-align:left;white-space:normal;">{{ $r['name'] }} <div style="font-size:12px;opacity:.6;">{{ $r['districts'] }}</div></td>
                                <td style="{{ $td }}">{{ $r['count'] }}</td>
                                <td style="{{ $td }}">{{ $a($r['area']) }}</td>
                                <td style="{{ $td }}">{{ $r['last'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div style="opacity:.6;font-size:14px;">Bu davrda shartnoma yo‘q.</div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
