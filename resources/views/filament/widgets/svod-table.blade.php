<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Tumanlar kesimida svod — {{ $day->format('d.m.Y') }}</x-slot>
        <x-slot name="headerEnd">
            <x-filament::button tag="a" :href="$excel" icon="heroicon-o-arrow-down-tray" size="sm" color="gray">Excel yuklab olish</x-filament::button>
        </x-slot>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr style="background:rgba(112,173,71,.18);">
                        @foreach (\App\Support\Svod::COLUMNS as $i => $col)
                            <th style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:{{ $i === 1 ? 'left' : 'center' }};font-weight:600;">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:rgba(91,155,213,.16);font-weight:700;">
                        <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;"></td>
                        <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;">{{ $total['name'] }}</td>
                        <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ $total['left'] }}</td>
                        <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ \App\Support\Svod::area($total['area']) }}</td>
                        <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;{{ $total['today'] ? 'color:#c0392b;' : '' }}">{{ $total['today'] }}</td>
                        <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ $total['progress'] }}</td>
                    </tr>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ $i + 1 }}</td>
                            <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;">{{ $r['name'] }}</td>
                            <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ $r['left'] }}</td>
                            <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ \App\Support\Svod::area($r['area']) }}</td>
                            <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;{{ $r['today'] ? 'color:#c0392b;font-weight:700;' : '' }}">{{ $r['today'] }}</td>
                            <td style="border:1px solid rgba(128,128,128,.4);padding:.5rem;text-align:center;">{{ $r['progress'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
