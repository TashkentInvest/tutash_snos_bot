@php
    $r = $getRecord();
    $data = [
        'staff' => [$r->lat, $r->lng],
        'site' => $r->site_lat ? [$r->site_lat, $r->site_lng] : null,
        'accuracy' => $r->accuracy,
        'radius' => (int) config('tutash.max_distance_m'),
        'distance' => \App\Filament\Resources\LocationRejectionResource::meters($r->distance_m),
    ];
@endphp
<div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:8px;">
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dc2626;vertical-align:middle;"></span> Xodim yuborgan joy</span>
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#2563eb;vertical-align:middle;"></span> Bizdagi obyekt (ko‘k doira — ruxsat etilgan {{ $data['radius'] }} m)</span>
    </div>
    <div wire:ignore x-data="{ d: @js($data) }" x-init="
        const draw = () => {
            const map = L.map($refs.map);
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' }).addTo(map);
            const pts = [d.staff];
            L.circleMarker(d.staff, { radius: 9, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 }).addTo(map).bindTooltip('Xodim', { permanent: true, direction: 'top' });
            if (d.accuracy) L.circle(d.staff, { radius: d.accuracy, color: '#dc2626', weight: 1, fillOpacity: .08 }).addTo(map);
            if (d.site) {
                pts.push(d.site);
                L.circle(d.site, { radius: d.radius, color: '#2563eb', weight: 1, fillOpacity: .1 }).addTo(map);
                L.circleMarker(d.site, { radius: 9, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 }).addTo(map).bindTooltip('Obyekt', { permanent: true, direction: 'top' });
                L.polyline([d.staff, d.site], { color: '#dc2626', dashArray: '6 6' }).addTo(map).bindTooltip(d.distance, { permanent: true, className: 'font-bold' });
            }
            pts.length > 1 ? map.fitBounds(pts, { padding: [50, 50], maxZoom: 17 }) : map.setView(d.staff, 16);
        };
        if (window.L) return draw();
        const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
        const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = draw; document.head.appendChild(js);
    ">
        <div x-ref="map" style="height:460px;border-radius:12px;overflow:hidden;z-index:0;"></div>
    </div>
</div>
