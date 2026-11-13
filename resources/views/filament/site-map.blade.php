@php
    /** @var \App\Models\Site $site */
    $site = $getRecord();
    $radius = (int) config('tutash.max_distance_m');
    // xodimlar tekshiruvda turgan joylar (qabul qilingan) va rad etilgan urinishlar
    $visits = $site->inspections()->whereNotNull('staff_lat')->with('staff')->latest('id')->limit(15)->get()
        ->map(fn ($in) => ['p' => [$in->staff_lat, $in->staff_lng], 'c' => '#16a34a',
            't' => 'Tekshiruv · '.($in->staff?->full_name ?? '').' · '.$in->created_at?->format('d.m.Y H:i').($in->distance_m !== null ? ' · '.$in->distance_m.' m' : '')])->values();
    $rejected = \App\Models\LocationRejection::where('site_id', $site->id)->where('source', 'inspection')->with('staff')->latest('id')->limit(15)->get()
        ->map(fn ($r) => ['p' => [$r->lat, $r->lng], 'c' => '#dc2626',
            't' => 'Rad etilgan · '.($r->staff?->full_name ?? '').' · '.$r->created_at?->format('d.m.Y H:i').' · '.\App\Filament\Resources\LocationRejectionResource::meters($r->distance_m)])->values();
    $area = $site->district_id ? \App\Support\Gps::districtRings($site->district_id) : ['rings' => [], 'real' => false];
    $data = ['site' => $site->lat ? [$site->lat, $site->lng] : null, 'radius' => $radius, 'points' => $visits->concat($rejected)->all(),
        'boundary' => $area['real'] ? $area['rings'] : [], 'district' => $site->district?->name];
@endphp
@if (! $data['site'])
    <div style="font-size:14px;color:#dc2626;">Obyektga koordinata kiritilmagan — «Tahrirlash» orqali kenglik va uzunlikni kiriting.</div>
@else
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:8px;">
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#2563eb;vertical-align:middle;"></span> Obyekt ({{ round($site->lat, 6) }}, {{ round($site->lng, 6) }}) — doira: tekshiruv ruxsat etilgan {{ $radius }} m</span>
        @if ($data['boundary'])<span style="opacity:.8;">┅ {{ $data['district'] }} chegarasi</span>@endif
        @if ($visits->isNotEmpty())<span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#16a34a;vertical-align:middle;"></span> Tekshiruvlar ({{ $visits->count() }})</span>@endif
        @if ($rejected->isNotEmpty())<span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dc2626;vertical-align:middle;"></span> Rad etilgan urinishlar ({{ $rejected->count() }})</span>@endif
    </div>
    <div wire:ignore x-data="{ d: @js($data) }" x-init="
        const draw = () => {
            const map = L.map($refs.map);
            const esri = n => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri' });
            const streets = esri('World_Street_Map').addTo(map);
            const sat = L.layerGroup([esri('World_Imagery'), esri('Reference/World_Boundaries_and_Places'), esri('Reference/World_Transportation')]);
            L.control.layers({ 'Xarita': streets, 'Sun’iy yo’ldosh': sat }).addTo(map);
            // tuman chegarasi (KMZ / NGIS) — obyekt shu tuman ichidami, ko'rinib tursin
            (d.boundary || []).forEach(r => L.polygon(r, { color: '#64748b', weight: 2, dashArray: '5 5', fill: false, interactive: false }).addTo(map));
            const ring = L.circle(d.site, { radius: d.radius, color: '#2563eb', weight: 1, fillOpacity: .1 }).addTo(map);
            const pts = [d.site];
            d.points.forEach(x => { pts.push(x.p); L.circleMarker(x.p, { radius: 7, color: '#fff', weight: 2, fillColor: x.c, fillOpacity: 1 }).addTo(map).bindTooltip(x.t); });
            L.circleMarker(d.site, { radius: 10, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 }).addTo(map).bindTooltip('Obyekt', { permanent: true, direction: 'top' });
            map.setView(d.site, 16); // doira chegarasi xarita ulangandan keyin hisoblanadi
            map.fitBounds(ring.getBounds().extend(L.latLngBounds(pts)), { padding: [30, 30], maxZoom: 17 });
        };
        if (window.L) return draw();
        const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
        const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = draw; document.head.appendChild(js);
    ">
        <div x-ref="map" style="height:420px;border-radius:12px;overflow:hidden;z-index:0;"></div>
    </div>
@endif
