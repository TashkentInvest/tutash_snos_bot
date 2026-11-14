@php
    $c = $getRecord();
    $data = ['polygon' => $c->polygon ?? [], 'street' => $c->street?->line ?? [], 'streetName' => $c->street?->name, 'gps' => [$c->lat, $c->lng]];
    $photos = array_keys($c->photos ?? []);
@endphp
<style>.leaflet-container .leaflet-interactive:focus,.leaflet-container path:focus{outline:none}</style>
<div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:8px;">
        <span><span style="display:inline-block;width:12px;height:12px;background:#1d4ed8;opacity:.6;vertical-align:middle;"></span> Chizilgan tutash hudud</span>
        <span><span style="display:inline-block;width:18px;height:4px;background:#f59e0b;vertical-align:middle;"></span> Gastronomik ko‘cha</span>
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dc2626;vertical-align:middle;"></span> Xodim turgan joy (GPS)</span>
    </div>
    <div wire:ignore x-data="{ d: @js($data) }" x-init="
        const draw = () => {
            const map = L.map($refs.map);
            const esri = n => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri' });
            const sat = L.layerGroup([esri('World_Imagery'), esri('Reference/World_Transportation')]).addTo(map);
            L.control.layers({ 'Sun’iy yo’ldosh': sat, 'Xarita': esri('World_Street_Map') }).addTo(map);
            d.street.length && L.polyline(d.street, { color: '#f59e0b', weight: 6, opacity: .9 }).addTo(map).bindTooltip(d.streetName || '', { sticky: true });
            const pts = [d.gps];
            if (d.polygon.length >= 3) { L.polygon(d.polygon, { color: '#1d4ed8', weight: 3, fillOpacity: .35 }).addTo(map); pts.push(...d.polygon); }
            L.circleMarker(d.gps, { radius: 7, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 }).addTo(map).bindTooltip('Xodim (GPS)');
            map.fitBounds(pts, { padding: [40, 40], maxZoom: 19 });
        };
        if (window.L) return draw();
        const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
        const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = draw; document.head.appendChild(js);
    ">
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
