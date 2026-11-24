@php
    $c = $getRecord();
    $data = ['polygon' => $c->polygon ?? [], 'street' => $c->street?->line ?? [], 'streetName' => $c->street?->name, 'gps' => [$c->lat, $c->lng]];
    $photos = array_keys($c->photos ?? []);
@endphp
<style>.leaflet-container .leaflet-interactive:focus,.leaflet-container path:focus{outline:none}</style>
<div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:8px;">
        <span><span style="display:inline-block;width:12px;height:12px;background:#1d4ed8;opacity:.6;vertical-align:middle;"></span> Chizilgan tutash hudud</span>
        <span><span style="display:inline-block;width:18px;height:4px;background:#dc2626;vertical-align:middle;"></span> Gastronomik ko‘cha</span>
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#f59e0b;vertical-align:middle;"></span> Xodim turgan joy (GPS)</span>
    </div>
    <div wire:ignore x-data="{ d: @js($data) }" x-init="(async () => {
        if (!window.AdminMap) await new Promise(r => { const s = document.createElement('script'); s.src = '{{ asset('js/admin-map.js') }}?v={{ filemtime(public_path('js/admin-map.js')) }}'; s.onload = r; document.head.appendChild(s); });
        await AdminMap.ready();
        // hudud chizmasi aniq ko'rinsin — boshlang'ich fon sun'iy yo'ldosh (o'zgartirish mumkin)
        const m = AdminMap.create($refs.map, d.gps, { zoom: 18, base: 'sat' });
        d.street.length && L.polyline(d.street, { color: '#dc2626', weight: 5, opacity: .9, lineCap: 'round' }).addTo(m.map).bindTooltip(AdminMap.esc(d.streetName), { sticky: true });
        const pts = [d.gps];
        if (d.polygon.length >= 3) { L.polygon(d.polygon, { color: '#1d4ed8', weight: 3, fillOpacity: .35 }).addTo(m.map); pts.push(...d.polygon); }
        L.circleMarker(d.gps, { radius: 7, color: '#fff', weight: 2, fillColor: '#f59e0b', fillOpacity: 1 }).addTo(m.map).bindTooltip('Xodim (GPS)');
        m.fit(pts, { maxZoom: 19 });
    })()">
        <div x-ref="map" style="height:420px;border-radius:12px;overflow:hidden;z-index:0;"></div>
    </div>
    @if ($photos)
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-top:14px;">
            @foreach ($photos as $i)
                <a href="{{ route('admin.lease-photo', ['contract' => $c, 'n' => $i + 1]) }}" target="_blank">
                    <img src="{{ route('admin.lease-photo', ['contract' => $c, 'n' => $i + 1]) }}" alt="{{ $i + 1 }}-rasm" style="width:100%;height:180px;object-fit:cover;border-radius:10px;">
                </a>
            @endforeach
        </div>
    @endif
</div>
