@php
    /** @var \App\Models\Site $site */
    $site = $getRecord();
    $radius = \App\Support\Limits::inspectionRadius();
    // xodimlar tekshiruvda turgan joylar (qabul qilingan) va rad etilgan urinishlar
    $visits = $site->inspections()->whereNotNull('staff_lat')->with('staff')->latest('id')->limit(15)->get()
        ->map(fn ($in) => ['p' => [$in->staff_lat, $in->staff_lng], 'c' => '#16a34a',
            't' => 'Tekshiruv · '.($in->staff?->full_name ?? '').' · '.$in->created_at?->format('d.m.Y H:i').($in->distance_m !== null ? ' · '.$in->distance_m.' m' : '')])->values();
    $rejected = \App\Models\LocationRejection::where('site_id', $site->id)->where('source', 'inspection')->with('staff')->latest('id')->limit(15)->get()
        ->map(fn ($r) => ['p' => [$r->lat, $r->lng], 'c' => '#dc2626',
            't' => 'Rad etilgan · '.($r->staff?->full_name ?? '').' · '.$r->created_at?->format('d.m.Y H:i').' · '.\App\Filament\Resources\LocationRejectionResource::meters($r->distance_m)])->values();
    $area = $site->district_id ? \App\Support\Gps::districtRings($site->district_id) : ['rings' => [], 'real' => false];
    $data = ['site' => $site->lat ? [$site->lat, $site->lng] : null, 'radius' => $radius, 'points' => $visits->concat($rejected)->all(),
        'boundary' => $area['real'] ? $area['rings'] : [], 'district' => $site->district?->name, 'gastro' => \App\Models\GastroStreet::mapLines()];
@endphp
<style>.leaflet-container .leaflet-interactive:focus,.leaflet-container path:focus{outline:none}</style>
@if (! $data['site'])
    <div style="font-size:14px;color:#dc2626;">Obyektga koordinata kiritilmagan.@if (\App\Filament\Resources\SiteResource::canEdit($site)) «Tahrirlash» orqali xaritada joyini belgilang.@endif</div>
@else
    <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:8px;">
        <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#2563eb;vertical-align:middle;"></span> Obyekt — doira: tekshiruv uchun ruxsat etilgan hudud ({{ $radius }} m)</span>
        @if ($data['boundary'])<span><span style="display:inline-block;width:22px;border-top:2px dashed #475569;vertical-align:middle;"></span> {{ $data['district'] }} chegarasi</span>@endif
        @if ($visits->isNotEmpty())<span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#16a34a;vertical-align:middle;"></span> Tekshiruvlar ({{ $visits->count() }})</span>@endif
        @if ($rejected->isNotEmpty())<span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#dc2626;vertical-align:middle;"></span> Rad etilgan urinishlar ({{ $rejected->count() }})</span>@endif
    </div>
    <div wire:ignore x-data="{ d: @js($data) }" x-init="(async () => {
        if (!window.AdminMap) await new Promise(r => { const s = document.createElement('script'); s.src = '{{ asset('js/admin-map.js') }}?v={{ filemtime(public_path('js/admin-map.js')) }}'; s.onload = r; document.head.appendChild(s); });
        await AdminMap.ready();
        // umumiy standart (Umumiy xarita kabi): view darhol beriladi, keyin qatlamlar
        const m = AdminMap.create($refs.map, d.site, { zoom: 16 });
        m.boundary(d.boundary); // tuman chegarasi (KMZ / NGIS) — obyekt shu tuman ichidami, ko'rinib tursin
        m.gastro(d.gastro);
        const ring = L.circle(d.site, { radius: d.radius, color: '#2563eb', weight: 1, fillOpacity: .1, interactive: false }).addTo(m.map);
        const pts = [d.site];
        d.points.forEach(x => { pts.push(x.p); L.circleMarker(x.p, { radius: 7, color: '#fff', weight: 2, fillColor: x.c, fillOpacity: 1 }).addTo(m.map).bindTooltip(AdminMap.esc(x.t)); });
        L.circleMarker(d.site, { radius: 10, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 }).addTo(m.map).bindTooltip('Obyekt', { permanent: true, direction: 'top' });
        m.fit(ring.getBounds().extend(L.latLngBounds(pts)), { padding: [30, 30] });
    })()">
        <div x-ref="map" style="height:420px;border-radius:12px;overflow:hidden;z-index:0;"></div>
    </div>
@endif
