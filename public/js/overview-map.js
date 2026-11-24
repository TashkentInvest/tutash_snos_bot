/**
 * «Umumiy xarita» (admin): qatlamlar faqat yoqilganda yuklanadi (/admin-map/{layer}), filtr — tuman, kun, xodim.
 * Tanlov (yoqilgan qatlamlar, shaffoflik, filtrlar) localStorage'da eslab qolinadi.
 */
window.OverviewMap = {
    init(root, cfg) {
        const $ = s => root.querySelector(s);
        const $$ = s => [...root.querySelectorAll(s)];
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
        const KEY = 'overview-map:v1';
        let saved = {};
        try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { saved = {}; }
        const save = () => { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} };
        const state = {
            on: Object.fromEntries(cfg.layers.map(l => [l.key, saved.on?.[l.key] ?? l.on])),
            opacity: Object.fromEntries(cfg.layers.map(l => [l.key, saved.opacity?.[l.key] ?? 1])),
            district: saved.district || '', staff: saved.staff || '',
        };
        const colors = Object.fromEntries(cfg.layers.map(l => [l.key, Object.fromEntries(Object.entries(l.states).map(([k, v]) => [k, v[0]]))]));

        // filtrlar
        const fD = $('[data-f=district]'), fDay = $('[data-f=day]'), fS = $('[data-f=staff]');
        cfg.districts.forEach(d => fD.add(new Option(d.name, d.id)));
        cfg.staff.forEach(s => fS.add(new Option(s.name, s.id)));
        fD.value = state.district; fS.value = state.staff; fDay.value = cfg.today;

        // xarita: och kulrang (standart), oddiy xarita, sun'iy yo'ldosh
        const map = L.map($('[data-map]'), { preferCanvas: true }).setView([41.31, 69.27], 11);
        map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank">Leaflet</a>');
        const esri = (n, o = {}) => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri', ...o });
        const gray = L.layerGroup([esri('Canvas/World_Light_Gray_Base', { maxNativeZoom: 16 }), esri('Canvas/World_Light_Gray_Reference', { maxNativeZoom: 16 })]).addTo(map);
        L.control.layers({ 'Och kulrang': gray, 'Xarita': esri('World_Street_Map'),
            'Sun’iy yo‘ldosh': L.layerGroup([esri('World_Imagery'), esri('Reference/World_Transportation')]) }, null, { position: 'topright' }).addTo(map);

        // katta ekran: to'liq ekran (filtrlar, qatlamlar va xarita) va qatlamlar panelini yashirish (xarita butun kenglikda)
        const btn = (html, title, onClick) => {
            const C = L.Control.extend({ onAdd() {
                const a = L.DomUtil.create('a', 'leaflet-bar om-btn');
                a.href = '#'; a.title = title; a.innerHTML = html; a.setAttribute('role', 'button');
                L.DomEvent.on(a, 'click', e => { L.DomEvent.preventDefault(e); L.DomEvent.stopPropagation(e); onClick(a); });
                return a;
            } });
            return new C({ position: 'topleft' }).addTo(map);
        };
        const fsIcon = on => on ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/></svg>' : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>';
        btn(fsIcon(false), 'To‘liq ekran (katta ekran uchun)', () => document.fullscreenElement ? document.exitFullscreen() : root.requestFullscreen?.());
        btn('<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>', 'Qatlamlar panelini yashirish / ko‘rsatish', () => { root.classList.toggle('om-wide'); setTimeout(() => map.invalidateSize(), 200); });
        document.addEventListener('fullscreenchange', () => {
            root.querySelectorAll('.om-btn')[0].innerHTML = fsIcon(document.fullscreenElement === root);
            setTimeout(() => map.invalidateSize(), 250);
        });

        const bounds = L.layerGroup().addTo(map);
        const drawBounds = () => {
            bounds.clearLayers();
            const id = Number(fD.value) || null;
            cfg.districts.filter(d => !id || d.id === id).forEach(d => (d.rings || []).forEach(r =>
                L.polygon(r, { color: '#475569', weight: id ? 2 : 1, dashArray: '5 5', fill: false, interactive: false }).addTo(bounds)));
        };

        const groups = {}, polys = {}, data = {};
        const popup = p => `<div class='om-pop'><b>${esc(p.t)}</b>${p.s ? '<br>' + esc(p.s) : ''}${p.u ? `<br><a href='${esc(p.u)}'>Batafsil</a>` : ''}</div>`;
        const style = (key, color) => ({ radius: key === 'sites' ? 5 : 6, color: '#fff', weight: key === 'sites' ? 1.5 : 2, fillColor: color, fillOpacity: state.opacity[key], opacity: state.opacity[key] });

        const render = key => {
            const g = groups[key], d = data[key];
            g.clearLayers(); polys[key]?.clearLayers();
            if (!d) return;
            (d.lines || []).forEach(s => {
                if (s.c) { // dizayn-kod ko'chasi: rang — bajarilish holati; haqiqiy shakl — yaxlit (oq g'ilof bilan), yo'q bo'lsa boshi → oxiri uzuq chiziq
                    const c = colors[key][s.c] || '#64748b', o = state.opacity[key];
                    s.shape && L.polyline(s.line, { color: '#fff', weight: 9, opacity: .9 * o, lineCap: 'round', interactive: false }).addTo(g);
                    L.polyline(s.line, s.shape ? { color: c, weight: 5, opacity: o, lineCap: 'round' } : { color: c, weight: 3, opacity: o, dashArray: '8 7' })
                        .bindTooltip(esc(s.tip), { sticky: true })
                        .bindPopup(`<div class='om-pop'><b>${esc(s.t)}</b>${(s.rows || []).map(r => '<br>' + esc(r)).join('')}`
                            + `${s.small ? `<br><small style='opacity:.7'>${esc(s.small)}</small>` : ''}`
                            + `${s.u ? `<br><a href='${esc(s.u)}'>Svodda ko‘rish</a>` : ''}</div>`).addTo(g);
                    return;
                }
                L.polyline(s.line, { color: colors[key].street || '#dc2626', weight: 4, opacity: .9 * state.opacity[key], lineCap: 'round' })
                    .bindTooltip(esc(s.name), { sticky: true }).addTo(g);
            });
            (d.points || []).forEach(p => {
                const c = colors[key][p.c] || '#64748b';
                (p.poly || []).length >= 3 && polys[key] && L.polygon(p.poly, { color: c, weight: 2, fill: false, opacity: state.opacity[key], interactive: false }).addTo(polys[key]);
                L.circleMarker(p.p, style(key, c)).bindPopup(popup(p)).addTo(g);
            });
            $(`[data-count=${key}]`).textContent = d.count ?? (d.points || []).length;
            const note = $(`[data-note=${key}]`);
            if (note) { note.textContent = d.note || ''; note.hidden = !d.note; }
        };

        const params = () => new URLSearchParams({ district: fD.value, day: fDay.value, staff: fS.value }).toString();
        const fetchLayer = async key => {
            $(`[data-count=${key}]`).textContent = '…';
            try {
                const r = await fetch(`${cfg.url}/${key}?${params()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                data[key] = r.ok ? await r.json() : null;
            } catch (e) { data[key] = null; }
            render(key);
        };

        cfg.layers.forEach(l => {
            // obyektlar ham alohida nuqta (raqamli doiralarga birlashtirilmaydi) — ko'cha bo'yidagi joylashuvi ko'rinsin
            groups[l.key] = L.featureGroup();
            if (l.key === 'gastro') polys[l.key] = L.layerGroup(); // yaqinlashtirilganda — shartnomalar hududi
        });
        const zoomPolys = () => Object.entries(polys).forEach(([k, g]) => (state.on[k] && map.getZoom() >= 16) ? map.addLayer(g) : map.removeLayer(g));
        map.on('zoomend', zoomPolys);

        const fit = () => {
            const b = L.latLngBounds([]);
            cfg.layers.forEach(l => { if (state.on[l.key] && groups[l.key].getLayers().length) b.extend(groups[l.key].getBounds()); });
            const id = Number(fD.value) || null;
            if (!b.isValid() && id) bounds.eachLayer(x => b.extend(x.getBounds()));
            b.isValid() ? map.fitBounds(b, { padding: [30, 30], maxZoom: 16 }) : map.setView([41.31, 69.27], 11);
        };

        const toggle = async (key, on) => {
            state.on[key] = on; save();
            if (on) { map.addLayer(groups[key]); await fetchLayer(key); }
            else { map.removeLayer(groups[key]); $(`[data-count=${key}]`).textContent = '—'; }
            zoomPolys();
        };
        $$('[data-toggle]').forEach(cb => {
            const key = cb.dataset.toggle;
            cb.checked = !!state.on[key];
            cb.addEventListener('change', async () => { await toggle(key, cb.checked); cb.checked && fit(); });
        });
        $$('[data-opacity]').forEach(r => {
            const key = r.dataset.opacity;
            r.value = Math.round(state.opacity[key] * 100); $(`[data-opv=${key}]`).textContent = r.value + '%';
            r.addEventListener('input', () => { state.opacity[key] = r.value / 100; $(`[data-opv=${key}]`).textContent = r.value + '%'; save(); render(key); });
        });
        const reload = async () => {
            state.district = fD.value; state.staff = fS.value; save(); drawBounds();
            await Promise.all(cfg.layers.filter(l => state.on[l.key]).map(l => fetchLayer(l.key)));
            fit(); zoomPolys();
        };
        [fD, fDay, fS].forEach(el => el.addEventListener('change', reload));

        drawBounds();
        cfg.layers.forEach(l => state.on[l.key] && map.addLayer(groups[l.key]));
        reload();
        setTimeout(() => map.invalidateSize(), 250);
    },
};
