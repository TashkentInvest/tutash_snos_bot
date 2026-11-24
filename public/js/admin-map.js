/**
 * Admin paneldagi kichik xaritalar (obyekt, ijara shartnomasi, rad etilgan joylashuv) uchun umumiy standart —
 * «Umumiy xarita» bilan bir xil: och kulrang fon (oddiy xarita va sun'iy yo'ldosh tanlovi), tuman chegarasi, qizil gastronomik ko'chalar,
 * to'liq ekran tugmasi. Xarita yaratilishi bilan view beriladi — aks holda Leaflet chiziqlarni chizishda xato beradi.
 */
window.AdminMap = {
    /** Leaflet (cdnjs) yuklanmagan bo'lsa yuklaydi. */
    ready() {
        if (window.L) return Promise.resolve();
        if (this._loading) return this._loading;
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
        document.head.appendChild(css);
        this._loading = new Promise(resolve => {
            const js = document.createElement('script');
            js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
            js.onload = resolve;
            document.head.appendChild(js);
        });
        return this._loading;
    },

    esc: s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';'),

    /**
     * @param el      xarita konteyneri
     * @param center  [lat, lng] — boshlang'ich markaz (keyin fit() bilan moslanadi)
     * @param opts    { base: 'gray' | 'street' | 'sat', zoom }
     * @return {{ map: L.Map, overlay: (name, layer) => void, fit: (points, opts) => void }}
     */
    create(el, center, opts = {}) {
        const map = L.map(el, { scrollWheelZoom: true }).setView(center || [41.31, 69.27], opts.zoom || 15);
        map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank">Leaflet</a>');
        const esri = (n, o = {}) => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri', ...o });
        const bases = {
            'Och kulrang': L.layerGroup([esri('Canvas/World_Light_Gray_Base', { maxNativeZoom: 16 }), esri('Canvas/World_Light_Gray_Reference', { maxNativeZoom: 16 })]),
            'Xarita': esri('World_Street_Map'),
            'Sun’iy yo‘ldosh': L.layerGroup([esri('World_Imagery'), esri('Reference/World_Transportation')]),
        };
        bases[{ street: 'Xarita', sat: 'Sun’iy yo‘ldosh' }[opts.base] || 'Och kulrang'].addTo(map);
        const control = L.control.layers(bases, null, { position: 'topright' }).addTo(map);

        // to'liq ekran
        const Fs = L.Control.extend({ onAdd() {
            const a = L.DomUtil.create('a', 'leaflet-bar');
            a.href = '#'; a.title = 'To‘liq ekran'; a.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>'; a.setAttribute('role', 'button');
            a.style.cssText = 'width:30px;height:30px;background:#fff;color:#111;display:flex;align-items:center;justify-content:center;';
            L.DomEvent.on(a, 'click', e => {
                L.DomEvent.preventDefault(e); L.DomEvent.stopPropagation(e);
                document.fullscreenElement ? document.exitFullscreen() : el.requestFullscreen?.();
            });
            return a;
        } });
        new Fs({ position: 'topleft' }).addTo(map);
        document.addEventListener('fullscreenchange', () => setTimeout(() => map.invalidateSize(), 200));
        setTimeout(() => map.invalidateSize(), 250);

        return {
            map,
            overlay: (name, layer) => control.addOverlay(layer, name),
            /** tuman chegarasi — «Umumiy xarita»dagi kabi punktir */
            boundary: rings => (Array.isArray(rings) ? rings : Object.values(rings || {})).forEach(r =>
                L.polygon(r, { color: '#475569', weight: 2, dashArray: '5 5', fill: false, interactive: false }).addTo(map)),
            /** gastronomik ko'chalar (qizil) — qatlam sifatida, yoqib/o'chirish mumkin */
            gastro: (lines, on = true) => {
                const g = L.layerGroup((lines || []).map(s => L.polyline(s.l, { color: '#dc2626', weight: 4, opacity: .85, lineCap: 'round' })
                    .bindTooltip(AdminMap.esc(s.n), { sticky: true })));
                on && g.addTo(map);
                control.addOverlay(g, 'Gastronomik ko‘chalar');
                return g;
            },
            fit: (bounds, o = {}) => {
                const b = bounds instanceof L.LatLngBounds ? bounds : L.latLngBounds(bounds);
                b.isValid() && map.fitBounds(b, { padding: [40, 40], maxZoom: 17, ...o });
            },
        };
    },
};
