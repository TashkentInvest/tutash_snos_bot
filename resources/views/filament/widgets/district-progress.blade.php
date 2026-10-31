<x-filament-widgets::widget>
    <x-filament::section heading="Tumanlar kesimida">
        @php($cell = 'border-bottom:1px solid rgba(128,128,128,.2);padding:.55rem .5rem;')
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr style="text-align:left;">
                        <th style="{{ $cell }}font-weight:600;">Tuman</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Jami</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Buzilgan</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Qolgan</th>
                        <th style="{{ $cell }}font-weight:600;text-align:right;">Bajarilish</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($districts as $d)
                        <tr>
                            <td style="{{ $cell }}"><a href="{{ \App\Filament\Widgets\DistrictProgress::sitesUrl($d) }}" style="color:inherit;">{{ $d->name }}</a></td>
                            <td style="{{ $cell }}text-align:right;">{{ $d->sites_count }}</td>
                            <td style="{{ $cell }}text-align:right;"><a href="{{ \App\Filament\Widgets\DistrictProgress::sitesUrl($d, \App\Models\Site::DEMOLISHED) }}" style="color:rgb(22,163,74);">{{ $d->demolished_count }}</a></td>
                            <td style="{{ $cell }}text-align:right;"><a href="{{ \App\Filament\Widgets\DistrictProgress::sitesUrl($d, \App\Models\Site::NOT_DEMOLISHED) }}" style="color:rgb(217,119,6);">{{ $d->sites_count - $d->demolished_count }}</a></td>
                            <td style="{{ $cell }}text-align:right;">{{ $d->sites_count ? round($d->demolished_count / $d->sites_count * 100, 1).'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
