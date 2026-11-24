@php
    $r = $getRecord();
    $data = [
        'staff' => [$r->lat, $r->lng],
        'site' => $r->site_lat ? [$r->site_lat, $r->site_lng] : null,
        'accuracy' => $r->accuracy,
        'radius' => \App\Support\Limits::inspectionRadius(),
        'distance' => \App\Filament\Resources\LocationRejectionResource::meters($r->distance_m),
        // davomat / soatlik qayd: xodimga biriktirilgan tuman hududlari (obyektlar o'rami)
        'areas' => in_array($r->source, ['inspection', 'lease'], true) || ! $r->staff ? [] : $r->staff->districts()->get(['districts.id', 'districts.name'])
            ->flatMap(fn ($d) => collect(\App\Support\Gps::districtRings($d->id)['rings'])->map(fn ($r) => ['name' => $d->name, 'pts' => $r]))->values()->all(),
        'district' => ! in_array($r->source, ['inspection', 'lease'], true),
        'lease' => $r->source === 'lease',
        'gastro' => \App\Models\GastroStreet::mapLines(),
    ];
@endphp
<style>.leaflet-container .leaflet-interactive:focus,.leaflet-container path:focus{outline:none}</style>
<div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:8px;">
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dc2626;vertical-align:middle;"></span> Xodim yuborgan joy</span>
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#2563eb;vertical-align:middle;"></span> {{ $data['lease'] ? 'Tanlangan gastro ko‘chaning eng yaqin nuqtasi (qizil chiziq — ko‘chalar)' : ($data['district'] ? 'Eng yaqin obyekt; punktir — biriktirilgan tuman hududi' : 'Bizdagi obyekt (ko‘k doira — ruxsat etilgan '.$data['radius'].' m)') }}</span>
    </div>
    <div wire:ignore x-data="{ d: @js($data) }" x-init="(async () => {
        if (!window.AdminMap) await new Promise(r => { const s = document.createElement('script'); s.src = '{{ asset('js/admin-map.js') }}?v={{ filemtime(public_path('js/admin-map.js')) }}'; s.onload = r; document.head.appendChild(s); });
        await AdminMap.ready();
        const m = AdminMap.create($refs.map, d.staff, { zoom: 16 }), map = m.map;
        m.gastro(d.gastro);
            const pts = [d.staff];
            (d.areas || []).forEach(a => { L.polygon(a.pts, { color: '#2563eb', weight: 2, dashArray: '4 4', fillOpacity: .06 }).addTo(map).bindTooltip(a.name + ' (hudud)', { sticky: true }); a.pts.forEach(p => pts.push(p)); });
            L.circleMarker(d.staff, { radius: 9, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 }).addTo(map).bindTooltip('Xodim', { permanent: true, direction: 'top' });
            if (d.accuracy) L.circle(d.staff, { radius: d.accuracy, color: '#dc2626', weight: 1, fillOpacity: .08 }).addTo(map);
            if (d.site) {
                pts.push(d.site);
                d.district || d.lease || L.circle(d.site, { radius: d.radius, color: '#2563eb', weight: 1, fillOpacity: .1 }).addTo(map);
                L.circleMarker(d.site, { radius: 9, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 }).addTo(map).bindTooltip(d.lease ? 'Ko‘cha' : (d.district ? 'Eng yaqin obyekt' : 'Obyekt'), { permanent: true, direction: 'top' });
                L.polyline([d.staff, d.site], { color: '#dc2626', dashArray: '6 6' }).addTo(map).bindTooltip(d.distance, { permanent: true, className: 'font-bold' });
            }
            pts.length > 1 && m.fit(pts, { padding: [50, 50] });
    })()">
        <div x-ref="map" style="height:460px;border-radius:12px;overflow:hidden;z-index:0;"></div>
    </div>
</div>
