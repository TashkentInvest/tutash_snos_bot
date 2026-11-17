<x-filament-panels::page>
    <style>
        .leaflet-container .leaflet-interactive:focus, .leaflet-container path:focus { outline: none }
        .gm-bar { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: center; font-size: 13px; }
        .gm-bar b { font-size: 15px; font-variant-numeric: tabular-nums; }
        .gm-legend { display: flex; flex-wrap: wrap; gap: 6px 18px; align-items: center; font-size: 12px; opacity: .85; margin-top: 12px; }
        .gm-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.25); vertical-align: -1px; margin-right: 4px; }
        .gm-line { display: inline-block; width: 22px; height: 4px; border-radius: 2px; vertical-align: 2px; margin-right: 4px; }
        .gm-pop { font-size: 13px; line-height: 1.45; min-width: 220px; }
        .gm-pop b { font-size: 14px; }
        .gm-pop a { color: #2563eb; font-weight: 600; }
    </style>

    <x-filament::section>
        <div wire:ignore x-data="{ d: @js($map) }" x-init="
            const esc = s => String(s ?? '').replace(/[\x26<>']/g, c => '\x26#' + c.charCodeAt(0) + ';'); // atributda HTML belgilari yozilmaydi
            const fmt = (v, dec = 0) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: dec, maximumFractionDigits: dec });
            const COLORS = { legal: '#2563eb', individual: '#16a34a', remote: '#f59e0b' };
            const LABELS = { legal: 'Yuridik shaxs', individual: 'Jismoniy shaxs', remote: 'Uzoqdan tuzilgan (sinov)' };

            const draw = () => {
                const map = L.map($refs.map, { zoomControl: true, preferCanvas: true });
                const esri = (n, o = {}) => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri', ...o });
                // och kulrang fon (ko'chalar va obyektlar aniq ko'rinadi); kerak bo'lsa — oddiy xarita yoki sun'iy yo'ldosh
                const gray = L.layerGroup([esri('Canvas/World_Light_Gray_Base', { maxNativeZoom: 16 }), esri('Canvas/World_Light_Gray_Reference', { maxNativeZoom: 16 })]).addTo(map);
                L.control.layers({ 'Och kulrang': gray, 'Xarita': esri('World_Street_Map'),
                    'Sun’iy yo‘ldosh': L.layerGroup([esri('World_Imagery'), esri('Reference/World_Transportation')]) }, null, { position: 'topright' }).addTo(map);

                const bounds = L.layerGroup().addTo(map), lines = L.layerGroup().addTo(map), polys = L.layerGroup(), dots = L.layerGroup().addTo(map);
                const sel = $refs.district, mode = $refs.mode;
                d.districts.forEach(x => sel.add(new Option(x.name, x.id)));

                const render = (fit) => {
                    const id = sel.value ? Number(sel.value) : null, byContract = mode.value === 'contracts';
                    [bounds, lines, polys, dots].forEach(g => g.clearLayers());
                    const pts = [];
                    d.districts.filter(x => !id || x.id === id).forEach(x => (x.rings || []).forEach(r => {
                        L.polygon(r, { color: '#475569', weight: id ? 2 : 1, dashArray: '5 5', fill: false, interactive: false }).addTo(bounds);
                    }));
                    const streets = d.streets.filter(s => !id || s.d === id);
                    streets.forEach(s => {
                        const color = byContract ? (s.n ? '#16a34a' : '#dc2626') : '#dc2626';
                        L.polyline(s.line, { color, weight: 4, opacity: .9, lineCap: 'round' }).addTo(lines)
                            .bindTooltip(`<b>${esc(s.name)}</b><br>${esc(s.district)} · ${s.n ? s.n + ' ta shartnoma' : 'shartnoma yo‘q'}`, { sticky: true });
                        pts.push(...s.line);
                    });
                    const cs = d.contracts.filter(c => !id || c.d === id);
                    cs.forEach(c => {
                        const color = COLORS[c.k] || '#64748b';
                        (c.poly || []).length >= 3 && L.polygon(c.poly, { color, weight: 2, fill: false, interactive: false }).addTo(polys);
                        pts.push(c.p);
                        L.circleMarker(c.p, { radius: 6, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 }).addTo(dots)
                            .bindPopup(`<div class='gm-pop'><b>№${c.id} · ${esc(c.name)}</b><br>${esc(c.addr)}<br>`
                                + `<span style='color:${color};font-weight:600;'>● ${LABELS[c.k] || ''}</span><br>`
                                + `Maydon: <b>${fmt(c.area, 1)} m²</b>` + (c.monthly ? ` · oyiga ${fmt(c.monthly)} so‘m` : '') + `<br>`
                                + `Joriy yil: ${fmt(c.annual)} so‘m<br>`
                                + `<span style='opacity:.7'>${esc(c.staff)} · ${esc(c.at)}</span><br><a href='${c.url}'>Shartnomani ochish →</a></div>`);
                    });
                    // ko'rsatkichlar (tanlangan tuman bo'yicha)
                    const covered = streets.filter(s => s.n > 0).length;
                    $refs.sStreets.textContent = streets.length;
                    $refs.sContracts.textContent = cs.length;
                    $refs.sArea.textContent = fmt(cs.reduce((a, c) => a + c.area, 0), 1) + ' m²';
                    $refs.sMonthly.textContent = fmt(cs.reduce((a, c) => a + (c.monthly || 0), 0)) + ' so‘m';
                    $refs.sCover.textContent = (streets.length ? Math.round(covered / streets.length * 1000) / 10 : 0).toString().replace('.', ',') + '%';
                    $refs.legendLines.innerHTML = byContract
                        ? `<span style='margin-right:18px'><span class='gm-line' style='background:#16a34a'></span>Shartnomasi bor ko‘cha</span><span><span class='gm-line' style='background:#dc2626'></span>Shartnomasi yo‘q ko‘cha</span>`
                        : `<span><span class='gm-line' style='background:#dc2626'></span><b>Gastronomik ko‘cha</b> (KMZ bo‘yicha)</span>`;
                    if (fit) pts.length ? map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 }) : map.setView([41.31, 69.27], 11);
                };
                // yaqinlashtirilganda — shartnomalar hududi (faqat chegarasi)
                const zoomPolys = () => map.getZoom() >= 16 ? map.addLayer(polys) : map.removeLayer(polys);
                map.on('zoomend', zoomPolys);
                sel.onchange = () => { render(true); zoomPolys(); };
                mode.onchange = () => render(false);
                render(true);
                setTimeout(() => map.invalidateSize(), 200);
            };
            if (window.L) return draw();
            const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(css);
            const js = document.createElement('script'); js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; js.onload = draw; document.head.appendChild(js);
        ">
            <div class="gm-bar">
                {{-- Filament'ning o'z maydonlari — qorong'i rejimda ham variantlar o'qiladi --}}
                <label style="display:flex;align-items:center;gap:8px;">Tuman:
                    <x-filament::input.wrapper style="min-width:200px;">
                        <x-filament::input.select x-ref="district"><option value="">Butun shahar</option></x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label style="display:flex;align-items:center;gap:8px;">Ko‘chalar rangi:
                    <x-filament::input.wrapper style="min-width:210px;">
                        <x-filament::input.select x-ref="mode"><option value="red">Gastronomik — qizil</option><option value="contracts">Shartnoma bor / yo‘q</option></x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <span>Ko‘chalar: <b x-ref="sStreets">0</b></span>
                <span>Shartnomalar: <b x-ref="sContracts">0</b></span>
                <span>Maydon: <b x-ref="sArea">0</b></span>
                <span>Oylik to‘lov: <b x-ref="sMonthly">0</b></span>
                <span title="Kamida bitta shartnomasi bor ko‘chalar ulushi">Qamrov: <b x-ref="sCover">0%</b></span>
            </div>
            <div class="gm-legend">
                <span x-ref="legendLines"></span>
                <span><span class="gm-line" style="background:repeating-linear-gradient(90deg,#475569 0 5px,transparent 5px 9px);"></span>tuman chegarasi</span>
                <span style="opacity:.5;">|</span>
                <span><span class="gm-dot" style="background:#2563eb"></span>Shartnoma: yuridik shaxs</span>
                <span><span class="gm-dot" style="background:#16a34a"></span>jismoniy shaxs</span>
                <span><span class="gm-dot" style="background:#f59e0b"></span>uzoqdan tuzilgan (sinov)</span>
                <span style="opacity:.7;">· yaqinlashtirilsa — hudud chegarasi</span>
            </div>
            <div x-ref="map" style="height:70vh;min-height:520px;border-radius:12px;overflow:hidden;z-index:0;margin-top:12px;"></div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tumanlar bo‘yicha">
        <div style="overflow-x:auto;">
            <table style="width:100%;font-size:14px;border-collapse:collapse;">
                <thead><tr style="text-align:left;opacity:.65;">
                    <th style="padding:8px 16px 8px 0;font-weight:500;">Tuman</th>
                    <th style="padding:8px 16px 8px 0;font-weight:500;">Ko‘chalar</th>
                    <th style="padding:8px 16px 8px 0;font-weight:500;">Shartnomalar</th>
                    <th style="padding:8px 0;font-weight:500;">Shartnoma mas’uli</th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid rgba(127,127,127,.25);vertical-align:top;">
                            <td style="padding:8px 16px 8px 0;white-space:nowrap;">{{ $r['name'] }}</td>
                            <td style="padding:8px 16px 8px 0;">{{ $r['streets'] ? implode(', ', $r['streets']) : '—' }}</td>
                            <td style="padding:8px 16px 8px 0;white-space:nowrap;">{{ $r['contracts'] ?: '—' }}</td>
                            <td style="padding:8px 0;white-space:normal;">{{ $r['responsible'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
