@php
    $t = $d['total'];
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $p = fn ($v) => str_replace('.', ',', (string) round((float) $v, 1)).'%';
    $km = fn ($v) => str_replace('.', ',', (string) round((float) $v, 1));
    $th = 'padding:8px 7px;font-weight:600;text-align:right;white-space:nowrap;font-size:12px;';
    $td = 'padding:7px 7px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;';
    $sep = 'border-left:1px solid rgba(127,127,127,.25);';
    $green = 'color:#16a34a;';
    $amber = 'color:#d97706;';
    $sel = collect($d['districts'])->firstWhere('id', $district);
    // bir qator (tuman yoki ko'cha) uchun raqamli ustunlar
    $cells = function (array $r) use ($td, $sep, $green, $amber, $n, $p) {
        $s = $r['snos'];
        return '<td style="'.$td.$sep.'">'.$n($r['dk_total']).'</td>'
            .'<td style="'.$td.$green.'">'.$n($r['dk_installed']).'</td>'
            .'<td style="'.$td.'font-weight:600;">'.$p($r['dk_pct']).'</td>'
            .'<td style="'.$td.$sep.'font-weight:700;">'.$n($s['total']).'</td>'
            .'<td style="'.$td.'">'.$n($s['not_demolished']).'</td>'
            .'<td style="'.$td.$amber.'">'.$n($s['in_progress']).'</td>'
            .'<td style="'.$td.$green.'">'.$n($s['demolished']).'</td>'
            .'<td style="'.$td.'">'.$n($s['left']).'</td>'
            .'<td style="'.$td.'font-weight:600;">'.$p($s['pct']).'</td>';
    };
    $head = function () use ($th, $sep, $buffer) {
        return '<th style="'.$th.$sep.'">Obyektlar</th><th style="'.$th.'">Talabga keltirildi</th><th style="'.$th.'">Bajarilish</th>'
            .'<th style="'.$th.$sep.'">Jami</th><th style="'.$th.'">Buzilmagan</th><th style="'.$th.'">Jarayonda</th>'
            .'<th style="'.$th.'">Buzilgan</th><th style="'.$th.'">Qolgan</th><th style="'.$th.'">Bajarilish</th>';
    };
    $groups = '<th></th><th></th><th></th><th></th>'
        .'<th colspan="3" style="'.$th.$sep.'text-align:center;color:#0891b2;">Dizayn-kod</th>'
        .'<th colspan="6" style="'.$th.$sep.'text-align:center;color:#e11d48;">Noqonuniy obyektlar — ko‘chadan '.$buffer.' m ichida</th>';
@endphp
<x-filament-panels::page>
    <style>
        .dk-t { width: 100%; font-size: 13.5px; border-collapse: collapse; }
        .dk-t thead tr { border-bottom: 1px solid rgba(127,127,127,.35); }
        .dk-t tbody tr { border-bottom: 1px solid rgba(127,127,127,.15); }
        .dk-t tbody tr.dk-row { cursor: pointer; }
        .dk-t tbody tr.dk-row:hover { background: rgba(127,127,127,.08); }
        .dk-t tr.dk-total { background: rgba(37,99,235,.08); font-weight: 700; }
        .dk-t tr.dk-sel { background: rgba(245,158,11,.16); }
    </style>

    @unless ($d['configured'])
        <x-filament::section>
            <div style="color:#d97706;">Dizayn-kod tizimi bilan ulanish hozircha sozlanmagan.</div>
        </x-filament::section>
    @endunless

    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
            <span style="font-size:13px;">Ko‘cha chizig‘idan masofa:</span>
            @foreach ($buffers as $b)
                <x-filament::button size="sm" :color="$b === $buffer ? 'primary' : 'gray'" wire:click="$set('buffer', {{ $b }})">{{ $b }} m</x-filament::button>
            @endforeach
            <span style="font-size:12px;opacity:.65;">Obyekt ko‘cha chizig‘idan shu masofa ichida bo‘lsa — shu ko‘chaga hisoblanadi. Chorrahadagi obyekt tuman va shahar jamida bir marta sanaladi; sud / buzilmaydiganlar jamiga kirmaydi.</span>
            <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-globe-asia-australia" :href="\App\Filament\Pages\OverviewMap::getUrl()" style="margin-left:auto;">Xaritada</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section :heading="'Tumanlar kesimida — '.now()->format('d.m.Y')">
        <div style="overflow-x:auto;">
            <table class="dk-t">
                <thead>
                    <tr style="border-bottom:none;">{!! $groups !!}</tr>
                    <tr>
                        <th style="{{ $th }}text-align:left;">№</th>
                        <th style="{{ $th }}text-align:left;">Tuman</th>
                        <th style="{{ $th }}">Ko‘chalar</th>
                        <th style="{{ $th }}">km</th>
                        {!! $head() !!}
                    </tr>
                </thead>
                <tbody>
                    <tr class="dk-total">
                        <td style="{{ $td }}"></td>
                        <td style="{{ $td }}text-align:left;">Toshkent shahar jami</td>
                        <td style="{{ $td }}">{{ $t['streets'] }}</td>
                        <td style="{{ $td }}">{{ $km($t['km']) }}</td>
                        {!! $cells($t) !!}
                    </tr>
                    @foreach ($d['districts'] as $i => $r)
                        <tr wire:key="d-{{ $r['id'] }}" wire:click="pick({{ $r['id'] }})" @class(['dk-row', 'dk-sel' => $district === $r['id']])>
                            <td style="{{ $td }}text-align:left;opacity:.6;">{{ $i + 1 }}</td>
                            <td style="{{ $td }}text-align:left;">{{ $r['name'] }}</td>
                            <td style="{{ $td }}">{{ $r['streets'] }}</td>
                            <td style="{{ $td }}">{{ $km($r['km']) }}</td>
                            {!! $cells($r) !!}
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="font-size:12px;opacity:.6;margin-top:8px;">Tuman qatorini bossangiz, uning ko‘chalari kesimi pastda chiqadi.</div>
    </x-filament::section>

    @if ($sel)
        <x-filament::section :heading="$sel['name'].' — ko‘chalar kesimida'">
            <div style="overflow-x:auto;">
                <table class="dk-t">
                    <thead>
                        <tr style="border-bottom:none;">{!! $groups !!}</tr>
                        <tr>
                            <th style="{{ $th }}text-align:left;">№</th>
                            <th style="{{ $th }}text-align:left;">Ko‘cha</th>
                                                        <th style="{{ $th }}">km</th>
                            {!! $head() !!}
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="dk-total">
                            <td style="{{ $td }}"></td>
                            <td style="{{ $td }}text-align:left;">Tuman jami</td>
                            <td style="{{ $td }}">{{ $km($sel['km']) }}</td>
                            {!! $cells($sel) !!}
                        </tr>
                        @foreach ($d['streets'] as $i => $s)
                            <tr wire:key="s-{{ $s['id'] }}" @class(['dk-sel' => $street === (int) $s['id']])>
                                <td style="{{ $td }}text-align:left;opacity:.6;">{{ $i + 1 }}</td>
                                <td style="{{ $td }}text-align:left;white-space:normal;min-width:180px;">{{ $s['name'] }}@unless ($s['shape'])<div style="font-size:11px;opacity:.6;">chizmasi taxminiy (boshi va oxiri)</div>@endunless</td>
                                                                <td style="{{ $td }}">{{ ($s['length_km'] ?? $s['drawn_km']) !== null ? $km($s['length_km'] ?? $s['drawn_km']) : '—' }}</td>
                                {!! $cells($s) !!}
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
