{{--
    Obyekt joyini xaritadan tanlash (yaratish va tahrirlash formasi).
    Xarita bosilganda yoki belgi sudralganda "lat"/"lng" maydonlari to'ldiriladi; maydonlarga qo'lda yozilsa — belgi ko'chadi.
    Leaflet + OpenStreetMap faqat shu sahifada, birinchi marta kerak bo'lganda yuklanadi.
--}}
@php
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
@endphp

<div
    wire:ignore
    x-data="{
        latPath: @js($base . '.lat'),
        lngPath: @js($base . '.lng'),
        map: null,
        marker: null,
        picking: false,

        loadLeaflet() {
            if (window.L) return Promise.resolve();
            return window.snosLeaflet ??= new Promise((resolve, reject) => {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(css);
                const js = document.createElement('script');
                js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                js.onload = resolve;
                js.onerror = reject;
                document.head.appendChild(js);
            });
        },

        current() {
            const lat = parseFloat($wire.get(this.latPath));
            const lng = parseFloat($wire.get(this.lngPath));
            return isNaN(lat) || isNaN(lng) ? null : [lat, lng];
        },

        async init() {
            try { await this.loadLeaflet(); } catch (e) { this.$refs.error.hidden = false; return; }

            const point = this.current();
            // Toshkent markazi — koordinata hali kiritilmagan bo'lsa
            this.map = L.map(this.$refs.map).setView(point ?? [41.3111, 69.2797], point ? 17 : 11);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(this.map);
            if (point) this.place(point);

            this.map.on('click', (e) => this.pick(e.latlng.lat, e.latlng.lng));

            // maydonlarga qo'lda yozilganda belgini ko'chirish
            const follow = () => {
                if (this.picking) return;
                const p = this.current();
                if (p) { this.place(p); this.map.panTo(p); }
            };
            $wire.$watch(this.latPath, follow);
            $wire.$watch(this.lngPath, follow);

            // bo'lim ochilganda o'lcham to'g'ri hisoblansin
            new ResizeObserver(() => this.map.invalidateSize()).observe(this.$refs.map);
        },

        place(point) {
            if (this.marker) { this.marker.setLatLng(point); return; }
            this.marker = L.marker(point, { draggable: true }).addTo(this.map);
            this.marker.on('dragend', () => {
                const p = this.marker.getLatLng();
                this.pick(p.lat, p.lng);
            });
        },

        pick(lat, lng) {
            lat = Number(lat.toFixed(7));
            lng = Number(lng.toFixed(7));
            this.picking = true;
            this.place([lat, lng]);
            $wire.set(this.latPath, lat, false);
            $wire.set(this.lngPath, lng, false);
            this.$nextTick(() => { this.picking = false; });
        },
    }"
>
    {{-- isolation: Leaflet qatlamlari (z-index 400+) forma ro'yxatlari ustiga chiqmasin --}}
    <div x-ref="map" style="height: 380px; border-radius: .75rem; isolation: isolate;"></div>
    <p x-ref="error" hidden class="text-sm text-danger-600" style="margin-top: .5rem;">
        Xaritani yuklab bo‘lmadi. Internet aloqasini tekshiring yoki koordinatalarni qo‘lda kiriting.
    </p>
    <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-top: .5rem;">
        Xaritada obyekt joyini bosing — kenglik va uzunlik avtomatik yoziladi. Belgini sudrab aniqlashtirish mumkin.
    </p>
</div>
