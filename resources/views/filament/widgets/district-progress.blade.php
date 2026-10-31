<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Tumanlar kesimida bajarilish — {{ $day->format('d.m.Y') }}</x-slot>
        @php($cell = 'border-bottom:1px solid rgba(128,128,128,.2);padding:.55rem .45rem;')
        @php($t = \App\Support\Svod::progress($total))
        {{-- sud qarori bekor qilinganlar ham bajarilgan (ish yopilgan) hisoblanadi; telefonda nomlar kesilmasligi uchun chapdan joy --}}
        <div style="overflow-x:auto;margin:0 -.25rem;padding:0 .25rem;">
            <table style="width:100%;min-width:22rem;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr>
                        <th style="{{ $cell }}font-weight:600;text-align:left;padding-left:.5rem;">Tuman</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Jami</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Buzilgan</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Sud qarori</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Qolgan</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Bajarilish</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:rgba(91,155,213,.12);font-weight:700;">
                        <td style="{{ $cell }}padding-left:.5rem;">{{ $total['name'] }}</td>
                        <td style="{{ $cell }}text-align:right;">{{ $t['all'] }}</td>
                        <td style="{{ $cell }}text-align:right;color:rgb(22,163,74);">{{ $t['demolished'] }}</td>
                        <td style="{{ $cell }}text-align:right;color:rgb(22,163,74);">{{ $t['court'] }}</td>
                        <td style="{{ $cell }}text-align:right;color:rgb(217,119,6);">{{ $t['left'] }}</td>
                        <td style="{{ $cell }}text-align:right;">{{ \App\Support\Svod::pct($t['pct']) }}</td>
                    </tr>
                    @foreach ($rows as $r)
                        @php($p = \App\Support\Svod::progress($r))
                        <tr>
                            <td style="{{ $cell }}padding-left:.5rem;"><a href="{{ \App\Filament\Widgets\DistrictProgress::sitesUrl($r['id']) }}" style="color:inherit;">{{ preg_replace('/\s+tumani$/u', '', $r['name']) }}</a></td>
                            <td style="{{ $cell }}text-align:right;">{{ $p['all'] }}</td>
                            <td style="{{ $cell }}text-align:right;"><a href="{{ \App\Filament\Widgets\DistrictProgress::sitesUrl($r['id'], $demolished) }}" style="color:rgb(22,163,74);">{{ $p['demolished'] }}</a></td>
                            <td style="{{ $cell }}text-align:right;color:rgb(22,163,74);">{{ $p['court'] }}</td>
                            <td style="{{ $cell }}text-align:right;"><a href="{{ \App\Filament\Widgets\DistrictProgress::sitesUrl($r['id'], $notDemolished) }}" style="color:rgb(217,119,6);">{{ $p['left'] }}</a></td>
                            <td style="{{ $cell }}text-align:right;">{{ \App\Support\Svod::pct($p['pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
