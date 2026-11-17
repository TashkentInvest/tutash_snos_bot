<x-filament-panels::page>
    <style>
        .leaflet-container .leaflet-interactive:focus, .leaflet-container path:focus { outline: none }
        .om-grid { display: grid; grid-template-columns: 300px 1fr; gap: 16px; align-items: start; }
        @media (max-width: 1000px) { .om-grid { grid-template-columns: 1fr; } }
        .om-filters { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; font-size: 13px; }
        .om-layer { padding: 10px 0; border-top: 1px solid rgba(127,127,127,.18); font-size: 13px; }
        .om-layer:first-child { border-top: 0; padding-top: 0; }
        .om-layer label { display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; }
        .om-count { margin-left: auto; font-weight: 600; font-size: 12px; padding: 1px 8px; border-radius: 999px; background: rgba(127,127,127,.15); font-variant-numeric: tabular-nums; }
        .om-states { display: flex; flex-wrap: wrap; gap: 4px 12px; margin: 6px 0 0 26px; font-size: 12px; opacity: .8; }
        .om-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.25); vertical-align: -1px; margin-right: 4px; }
        .om-op { display: flex; align-items: center; gap: 8px; margin: 6px 0 0 26px; font-size: 12px; opacity: .8; }
        .om-op input { flex: 1; accent-color: rgb(var(--primary-500)); }
        .om-pop { font-size: 13px; line-height: 1.45; min-width: 200px; }
        .om-pop a { color: #2563eb; font-weight: 600; }
    </style>

    <div x-data="{ cfg: @js($cfg) }" x-init="
        const load = (src, css) => new Promise(r => { const e = document.createElement(css ? 'link' : 'script'); css ? (e.rel = 'stylesheet', e.href = src) : (e.src = src); e.onload = r; e.onerror = r; document.head.appendChild(e); });
        const cdn = 'https://cdnjs.cloudflare.com/ajax/libs/';
        (async () => {
            if (!window.L) { load(cdn + 'leaflet/1.9.4/leaflet.min.css', true); await load(cdn + 'leaflet/1.9.4/leaflet.min.js'); }
            if (!L.markerClusterGroup) { load(cdn + 'leaflet.markercluster/1.5.3/MarkerCluster.min.css', true); load(cdn + 'leaflet.markercluster/1.5.3/MarkerCluster.Default.min.css', true); await load(cdn + 'leaflet.markercluster/1.5.3/leaflet.markercluster.min.js'); }
            if (!window.OverviewMap) await load('{{ asset('js/overview-map.js') }}?v=1');
            window.OverviewMap.init($el, cfg);
        })();
    " wire:ignore>
        <x-filament::section>
            <div class="om-filters">
                <label style="display:flex;align-items:center;gap:8px;">Tuman:
                    <x-filament::input.wrapper style="min-width:190px;"><x-filament::input.select data-f="district"><option value="">Butun shahar</option></x-filament::input.select></x-filament::input.wrapper>
                </label>
                <label style="display:flex;align-items:center;gap:8px;">Kun:
                    <x-filament::input.wrapper><x-filament::input type="date" data-f="day" :max="$cfg['today']" :value="$cfg['today']" style="color-scheme:light dark;" /></x-filament::input.wrapper>
                </label>
                <label style="display:flex;align-items:center;gap:8px;">Xodim:
                    <x-filament::input.wrapper style="min-width:230px;"><x-filament::input.select data-f="staff"><option value="">Hammasi</option></x-filament::input.select></x-filament::input.wrapper>
                </label>
                <span style="opacity:.65;font-size:12px;">Kun va xodim — davomat, soatlik qayd, tekshiruv va xatolar qatlamlari uchun</span>
            </div>
        </x-filament::section>

        <div class="om-grid" style="margin-top:16px;">
            <x-filament::section heading="Qatlamlar">
                @foreach ($cfg['layers'] as $l)
                    <div class="om-layer" data-layer="{{ $l['key'] }}">
                        <label><x-filament::input.checkbox data-toggle="{{ $l['key'] }}" :checked="$l['on']" /> {{ $l['name'] }} <span class="om-count" data-count="{{ $l['key'] }}">—</span></label>
                        <div class="om-states">
                            @foreach ($l['states'] as [$color, $label])
                                <span><span class="om-dot" style="background:{{ $color }}"></span>{{ $label }}</span>
                            @endforeach
                        </div>
                        <div class="om-op">Shaffoflik <input type="range" min="10" max="100" value="100" data-opacity="{{ $l['key'] }}"> <span data-opv="{{ $l['key'] }}">100%</span></div>
                    </div>
                @endforeach
                <div style="font-size:12px;opacity:.6;margin-top:8px;">Tanlov shu brauzerda eslab qolinadi. Nuqtani bossangiz — qisqa ma’lumot va sahifaga havola.</div>
            </x-filament::section>

            <x-filament::section>
                <div data-map style="height:76vh;min-height:560px;border-radius:12px;overflow:hidden;z-index:0;"></div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
