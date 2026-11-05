<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Tumanlar kesimida svod — {{ $day->format('d.m.Y') }}</x-slot>
        <x-slot name="headerEnd">
            <x-filament::button tag="a" :href="$excel" icon="heroicon-o-arrow-down-tray" size="sm" color="gray">Excel yuklab olish</x-filament::button>
        </x-slot>

        @php
            $td = 'border:1px solid rgba(128,128,128,.4);padding:.5rem;';
            // ustunlar: kun boshidagi qoldiq soni, maydoni; bugun buzilgan soni, maydoni; qoldiqlar maydoni (1, 3, 4 — gektar)
            $fmt = fn (array $r) => collect(\App\Support\Svod::cells($r))->map(fn ($v, $i) => in_array($i, [1, 3, 4], true) ? \App\Support\Svod::area($v) : $v)->all();
        @endphp
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr style="background:rgba(112,173,71,.18);">
                        @foreach (\App\Support\Svod::COLUMNS as $i => $col)
                            <th style="{{ $td }}text-align:{{ $i === 1 ? 'left' : 'center' }};font-weight:600;">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ([$total, ...$rows] as $n => $r)
                        <tr style="{{ $n === 0 ? 'background:rgba(91,155,213,.16);font-weight:700;' : '' }}">
                            <td style="{{ $td }}text-align:center;">{{ $n ?: '' }}</td>
                            <td style="{{ $td }}">{{ $r['name'] }}</td>
                            @foreach ($fmt($r) as $i => $v)
                                <td style="{{ $td }}text-align:center;{{ in_array($i, [2, 3], true) && $r['today'] ? 'color:#c0392b;font-weight:700;' : '' }}">{{ $v }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
