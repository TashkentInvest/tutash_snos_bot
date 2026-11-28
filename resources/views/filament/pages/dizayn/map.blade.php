<x-filament-panels::page>
    <style>
        .leaflet-container .leaflet-interactive:focus, .leaflet-container path:focus { outline: none }
        .dm-bar { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: center; font-size: 13px; }
        .dm-bar b { font-size: 15px; font-variant-numeric: tabular-nums; }
        .dm-legend { display: flex; flex-wrap: wrap; gap: 6px 18px; align-items: center; font-size: 12px; opacity: .85; margin-top: 12px; }
        .dm-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.25); vertical-align: -1px; margin-right: 4px; }
        .dm-line { display: inline-block; width: 22px; height: 4px; border-radius: 2px; vertical-align: 2px; margin-right: 4px; }
        .dm-pop { font-size: 13px; line-height: 1.45; min-width: 220px; }
        .dm-pop b { font-size: 14px; }
        .dm-pop a { color: #2563eb; font-weight: 600; }
    </style>

    <x-filament::section>
        <div wire:ignore x-data="{ d: @js($map) }" x-init="
            const esc = s => String(s ?? '').replace(/[\x26<>'\x22]/g, c => '\x26#' + c.charCodeAt(0) + ';'); // atributda qo'sh tirnoq yozilmaydi — \x22
            const num = v => String(v).replace('.', ',');
            const DOT = { pending: '#64748b', removed: '#f59e0b', installed: '#16a34a', review: '#3b82f6' };
            const pctColor = s => s.pct >= 100 ? '#16a34a' : (s.installed || s.removed ? '#f59e0b' : '#dc2626');

            const draw = () => {
                const map = L.map($refs.map, { zoomControl: true, preferCanvas: true });
                map.attributionControl.setPrefix('<a href=\x22https://leafletjs.com\x22 target=\x22_blank\x22>Leaflet</a>');
                const esri = (n, o = {}) => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri', ...o });
                const gray = L.layerGroup([esri('Canvas/World_Light_Gray_Base', { maxNativeZoom: 16 }), esri('Canvas/World_Light_Gray_Reference', { maxNativeZoom: 16 })]).addTo(map);
                L.control.layers({ 'Och kulrang': gray, 'Xarita': esri('World_Street_Map'),
                    'Sun’iy yo‘ldosh': L.layerGroup([esri('World_Imagery'), esri('Reference/World_Transportation')]) }, null, { position: 'topright' }).addTo(map);
                L.control.scale({ imperial: false }).addTo(map);

                const lines = L.layerGroup().addTo(map), dots = L.layerGroup().addTo(map);
                const sel = $refs.district, mode = $refs.mode;
                d.districts.forEach(x => sel.add(new Option(x.name, x.id)));

                const render = (fit) => {
                    const id = sel.value ? Number(sel.value) : null, byPct = mode.value === 'pct';
                    lines.clearLayers(); dots.clearLayers();
                    const pts = [];
                    const streets = d.streets.filter(s => !id || s.d === id).sort((a, b) => a.shape - b.shape); // KMZ shakllari ustida
                    streets.forEach(s => {
                        const color = byPct ? pctColor(s) : (s.shape ? '#dc2626' : '#64748b');
                        L.polyline(s.line, s.shape ? { color, weight: 4, opacity: .9, lineCap: 'round' } : { color, weight: 3, opacity: .75, dashArray: '8 7' }).addTo(lines)
                            .bindPopup(`<div class='dm-pop'><b>${esc(s.name)}</b><br>${esc(s.district)}`
                                + `<br>Uzunligi: <b>${s.km !== null ? num(s.km) + ' km' : '—'}</b> · noturar obyektlar: <b>${s.nonres ?? '—'}</b>`
                                + `<br>Ro‘yxatda: <b>${s.total}</b> · yechildi: ${s.removed} · qo‘yildi: ${s.installed}`
                                + `<br>Bajarilish: <b>${num(s.pct)}%</b></div>`)
                            .bindTooltip(`${esc(s.name)} · ${num(s.pct)}%`, { sticky: true });
                        pts.push(...s.line);
                    });
                    const os = d.objects.filter(o => !id || o.d === id);
                    os.forEach(o => {
                        pts.push(o.p);
                        L.circleMarker(o.p, { radius: 6, color: '#fff', weight: 2, fillColor: DOT[o.s] || '#64748b', fillOpacity: 1 }).addTo(dots)
                            .bindPopup(`<div class='dm-pop'><b>${esc(o.name)}</b><br>${esc(o.street)}${o.addr ? '<br>' + esc(o.addr) : ''}`
                                + `<br><span style='color:${DOT[o.s]};font-weight:600;'>${esc(d.statuses[o.s] || o.s)}</span>${o.exact ? '' : ' · <i>qo‘shilgan joyi</i>'}`
                                + `<br><a href='${o.url}'>Obyektni ochish →</a></div>`);
                    });
                    const planned = streets.reduce((a, s) => a + s.total, 0), done = streets.reduce((a, s) => a + s.installed, 0);
                    $refs.sStreets.textContent = streets.length;
                    $refs.sKm.textContent = num(Math.round(streets.reduce((a, s) => a + (s.km || 0), 0) * 10) / 10) + ' km';
                    $refs.sPlanned.textContent = planned;
                    $refs.sObjects.textContent = os.length;
                    $refs.sPct.textContent = num(planned ? Math.round(done / planned * 1000) / 10 : 0) + '%';
                    $refs.legendLines.innerHTML = byPct
                        ? `<span style='margin-right:14px'><span class='dm-line' style='background:#dc2626'></span>boshlanmagan</span><span style='margin-right:14px'><span class='dm-line' style='background:#f59e0b'></span>jarayonda</span><span><span class='dm-line' style='background:#16a34a'></span>to‘liq bajarilgan</span>`
                        : `<span><span class='dm-line' style='background:#dc2626'></span><b>Dizayn-kod ko‘chasi</b> (KMZ shakli)</span>`;
                    if (fit) pts.length ? map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 }) : map.setView([41.31, 69.27], 11);
                };
                sel.onchange = () => render(true);
                mode.onchange = () => render(false);
                render(true);
                setTimeout(() => map.invalidateSize(), 200);
            };
            if (window.L) return draw();
            const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
            const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = draw; document.head.appendChild(js);
        ">
            <div class="dm-bar">
                <label style="display:flex;align-items:center;gap:8px;">Tuman:
                    <x-filament::input.wrapper style="min-width:200px;">
                        <x-filament::input.select x-ref="district"><option value="">Butun shahar</option></x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label style="display:flex;align-items:center;gap:8px;">Ko‘chalar rangi:
                    <x-filament::input.wrapper style="min-width:210px;">
                        <x-filament::input.select x-ref="mode"><option value="pct">Bajarilish bo‘yicha</option><option value="red">Hammasi qizil</option></x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <span>Ko‘chalar: <b x-ref="sStreets">{{ $totals['streets'] }}</b></span>
                <span>Uzunligi: <b x-ref="sKm">{{ $totals['km'] }} km</b></span>
                <span>Ro‘yxatdagi obyektlar: <b x-ref="sPlanned">{{ $totals['planned'] }}</b></span>
                <span>Xaritadagi obyektlar: <b x-ref="sObjects">{{ $totals['objects'] }}</b></span>
                <span>Bajarilish: <b x-ref="sPct">{{ $totals['pct'] }}</b></span>
            </div>
            <div class="dm-legend">
                <span x-ref="legendLines"></span>
                <span><span class="dm-line" style="background:repeating-linear-gradient(90deg,#64748b 0 7px,transparent 7px 11px);"></span>shakli yuklanmagan (boshi–oxiri)</span>
                <span style="opacity:.5;">|</span>
                @foreach (['pending' => '#64748b', 'removed' => '#f59e0b', 'installed' => '#16a34a', 'review' => '#3b82f6'] as $k => $c)
                    <span><span class="dm-dot" style="background:{{ $c }}"></span>{{ $map['statuses'][$k] }}</span>
                @endforeach
            </div>
            <div x-ref="map" style="height:70vh;min-height:520px;border-radius:12px;overflow:hidden;z-index:0;margin-top:12px;"></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
