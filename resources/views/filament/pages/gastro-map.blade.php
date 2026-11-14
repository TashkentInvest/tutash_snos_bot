<x-filament-panels::page>
    <style>.leaflet-container .leaflet-interactive:focus,.leaflet-container path:focus{outline:none}</style>
    <x-filament::section>
        <div wire:ignore x-data="{ d: @js($map) }" x-init="
            const draw = () => {
                const map = L.map($refs.map);
                L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' }).addTo(map);
                const all = [];
                d.districts.forEach(x => (x.rings || []).forEach(r => { all.push(...r);
                    L.polygon(r, { color: '#64748b', weight: 1, dashArray: '4 4', fillOpacity: .03 }).addTo(map).bindTooltip(x.name, { sticky: true }); }));
                d.streets.forEach(s => L.polyline(s.line, { color: '#dc2626', weight: 5, opacity: .85 }).addTo(map)
                    .bindTooltip(s.name + ' — ' + (s.district || ''), { sticky: true }));
                all.length ? map.fitBounds(all, { padding: [20, 20] }) : map.setView([41.31, 69.27], 12);
            };
            if (window.L) return draw();
            const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
            const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = draw; document.head.appendChild(js);
        ">
            <div x-ref="map" style="height:560px;border-radius:12px;overflow:hidden;z-index:0;"></div>
        </div>
        <div style="font-size:13px;opacity:.75;margin-top:8px;">Qizil chiziq — gastronomik va turizm ko‘chasi (ustiga borsangiz nomi chiqadi); punktir — tuman chegarasi.</div>
    </x-filament::section>

    <x-filament::section heading="Tumanlar bo‘yicha">
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:14px;border-collapse:collapse;">
                <thead><tr style="text-align:left;opacity:.65;">
                    <th style="padding:8px 16px 8px 0;font-weight:500;">Tuman</th>
                    <th style="padding:8px 16px 8px 0;font-weight:500;">Ko‘chalar</th>
                    <th style="padding:8px 0;font-weight:500;">Shartnoma mas’uli</th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid rgba(127,127,127,.25);vertical-align:top;">
                            <td style="padding:8px 16px 8px 0;white-space:nowrap;">{{ $r['name'] }}</td>
                            <td style="padding:8px 16px 8px 0;">{{ $r['streets'] ? implode(', ', $r['streets']) : '—' }}</td>
                            <td style="padding:8px 0;white-space:normal;">{{ $r['responsible'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
