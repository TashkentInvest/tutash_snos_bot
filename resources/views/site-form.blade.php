<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Obyekt qo‘shish</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
        body { margin: 0; padding: 16px; font-family: -apple-system, Roboto, sans-serif; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #111); }
        h1 { font-size: 19px; margin: 4px 0 4px; text-align: center; }
        .sub { text-align: center; color: var(--tg-theme-hint-color, #777); font-size: 14px; margin-bottom: 12px; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
        label small, .hint { font-weight: 400; color: var(--tg-theme-hint-color, #888); font-size: 13px; }
        input, select { width: 100%; box-sizing: border-box; padding: 12px; font-size: 16px; border-radius: 10px; font-family: inherit;
            border: 1px solid var(--tg-theme-hint-color, #ccc); background: var(--tg-theme-secondary-bg-color, #f5f5f5); color: inherit; }
        .row { display: flex; gap: 8px; } .row > * { flex: 1; min-width: 0; }
        .check { display: flex; align-items: center; gap: 10px; margin-top: 14px; font-size: 15px; font-weight: 400; }
        .check input { width: 22px; height: 22px; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 17px; margin-top: 18px;
            background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); }
        button.secondary { background: transparent; color: var(--tg-theme-link-color, #2481cc); border: 1px solid currentColor; margin-top: 8px; padding: 11px; font-size: 15px; }
        button:disabled { opacity: .6; }
        #msg { margin-top: 14px; font-size: 15px; text-align: center; } .err { color: #d33; } .ok { color: #2a9d4a; }
        .note { background: rgba(211, 51, 51, .1); color: #d33; border-radius: 10px; padding: 10px 12px; font-size: 14px; margin-bottom: 8px; }
        .hidden { display: none !important; }

        /* qidiriladigan ro'yxat (MFY, ko'cha) */
        .combo { position: relative; }
        .opts { position: absolute; z-index: 1000; left: 0; right: 0; top: calc(100% + 4px); max-height: 240px; overflow-y: auto; border-radius: 10px;
            background: var(--tg-theme-bg-color, #fff); border: 1px solid var(--tg-theme-hint-color, #ccc); box-shadow: 0 6px 20px rgba(0,0,0,.25); }
        .opts div { padding: 12px; font-size: 15px; border-bottom: 1px solid rgba(127,127,127,.15); }
        .opts div:last-child { border-bottom: 0; }
        .opts .add { color: var(--tg-theme-link-color, #2481cc); font-weight: 600; }
        .opts .empty { color: var(--tg-theme-hint-color, #888); }
        .new-hint { margin-top: 6px; font-size: 13px; color: var(--tg-theme-link-color, #2481cc); }

        /* xarita */
        #map { height: 300px; border-radius: 10px; border: 1px solid var(--tg-theme-hint-color, #ccc); }
        .pin { font-size: 30px; line-height: 30px; text-align: center; filter: drop-shadow(0 2px 2px rgba(0,0,0,.4)); }
        #coords { margin-top: 6px; }

        /* buzilish holati */
        .seg { display: flex; gap: 8px; }
        .seg label { flex: 1; margin: 0; padding: 12px; text-align: center; border-radius: 10px; font-weight: 600;
            border: 1px solid var(--tg-theme-hint-color, #ccc); background: var(--tg-theme-secondary-bg-color, #f5f5f5); }
        .seg input { display: none; }
        .seg label.on { background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); border-color: transparent; }
    </style>
</head>
<body>
<h1 id="title">➕ Yangi obyekt</h1>
<div class="sub">Buzilishi kerak bo‘lgan joy. Admin tasdiqlagach ro‘yxatga qo‘shiladi.</div>
<div id="note" class="note hidden"></div>

<form id="f" class="hidden" autocomplete="off">
    <label>Tuman</label>
    <select id="district" required></select>

    <label>MFY <small>(yozib qidiring)</small></label>
    <div class="combo" id="mahalla"><input placeholder="MFY nomini yozing…" maxlength="150"><div class="opts hidden"></div></div>
    <div class="new-hint hidden" id="mahallaNew"></div>

    <label>Ko‘cha <small>(yozib qidiring)</small></label>
    <div class="combo" id="street"><input placeholder="Ko‘cha nomini yozing…" maxlength="150"><div class="opts hidden"></div></div>
    <div class="new-hint hidden" id="streetNew"></div>

    <label>Uy, mo‘ljal <small>(ixtiyoriy)</small></label>
    <input id="house" maxlength="300" placeholder="masalan: 12-uy oldi">

    <label>Joylashuv <small>(xaritada obyekt joyini bosing, belgini surib aniqlashtiring)</small></label>
    <div id="map"></div>
    <div id="coords" class="hint">Joy tanlanmagan</div>
    <button type="button" id="goLoc" class="secondary">📍 Turgan joyimni olish</button>

    <label>Buzilish holati</label>
    <div class="seg">
        <label><input type="radio" name="status" value="not_demolished" checked> Buzilmagan</label>
        <label><input type="radio" name="status" value="in_progress"> Jarayonda</label>
    </div>

    <label>Egallangan maydon, m²</label>
    <input id="area" type="number" inputmode="decimal" min="1" step="0.01" required placeholder="masalan 120">
    <label class="check"><input id="tutash" type="checkbox" checked> Tutash hudud</label>

    <label>Huquqbuzar <small>(F.I.SH yoki tashkilot)</small></label>
    <input id="violator" required minlength="3" maxlength="255">
    <div class="row">
        <div><label>Pasport <small>(ixtiyoriy)</small></label><input id="passport" maxlength="20" placeholder="AA1234567"></div>
        <div><label>STIR <small>(ixtiyoriy)</small></label><input id="inn" inputmode="numeric" maxlength="20"></div>
    </div>

    <label>Sud qarori <small>(ixtiyoriy)</small></label>
    <div class="row">
        <input id="courtNo" maxlength="100" placeholder="Raqami">
        <input id="courtDate" type="date">
    </div>
    <input id="courtStatus" maxlength="150" placeholder="Sud holati (masalan: Qanoatlantirildi)" style="margin-top:8px;">

    <button type="submit" id="goSave">Yuborish</button>
</form>
<div id="msg"></div>

<script src="/js/tg-location.js?v=4"></script>
<script>
const tg = window.Telegram && window.Telegram.WebApp;
tg && (tg.ready(), tg.expand(), tg.disableVerticalSwipes && tg.disableVerticalSwipes()); // xaritani surganda oyna yopilib ketmasin
const $ = id => document.getElementById(id), msg = $('msg');
const say = (t, c) => { msg.textContent = t; msg.className = c || ''; };
const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const q = new URLSearchParams(location.search);
const initData = () => (tg ? tg.initData : '');
let districts = [], busy = false, gastro = [];

async function api(path, body) {
    const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), 25000);
    try {
        const r = await fetch('/tg/site-form/' + path, { method: 'POST', signal: ctl.signal,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ initData: initData(), ...body }) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok && j.errors) j.message = Object.values(j.errors)[0][0];
        return { ok: r.ok && j.ok, j };
    } finally { clearTimeout(timer); }
}

// ---- qidiriladigan ro'yxat: tanlanadi yoki ro'yxatda yo'q nom yangi sifatida qo'shiladi
const norm = t => String(t || '').toLowerCase().replace(/[‘’ʻʼ`´']/g, "'").replace(/\s+/g, ' ').trim();
function Combo(id, word) {
    const root = $(id), inp = root.querySelector('input'), box = root.querySelector('.opts'), hint = $(id + 'New');
    let items = [], picked = null;
    const exact = () => items.find(i => norm(i.name) === norm(inp.value));
    const showHint = () => {
        const isNew = !picked && !exact() && norm(inp.value).length >= 3;
        hint.textContent = isNew ? `➕ «${inp.value.trim()}» — yangi ${word} sifatida qo‘shiladi` : '';
        hint.classList.toggle('hidden', !isNew);
    };
    const render = () => {
        const s = norm(inp.value), list = items.filter(i => !s || norm(i.name).includes(s)).slice(0, 40);
        let html = list.map(i => `<div data-id="${i.id}">${esc(i.name)}</div>`).join('');
        if (s.length >= 3 && !exact()) html += `<div class="add" data-new="1">➕ «${esc(inp.value.trim())}» — yangi ${word} qo‘shish</div>`;
        box.innerHTML = html || `<div class="empty">${items.length ? 'Topilmadi' : 'Ro‘yxat bo‘sh'} — nomini yozing (kamida 3 harf)</div>`;
        box.classList.remove('hidden');
    };
    inp.addEventListener('focus', render);
    inp.addEventListener('input', () => { picked = null; render(); showHint(); });
    inp.addEventListener('blur', () => setTimeout(() => { box.classList.add('hidden'); showHint(); }, 250));
    box.addEventListener('click', e => {
        const d = e.target.closest('[data-id],[data-new]');
        if (!d) return;
        if (d.dataset.new) { picked = { id: null, name: inp.value.trim().replace(/\s+/g, ' ') }; }
        else { const it = items.find(i => String(i.id) === d.dataset.id); picked = { id: it.id, name: it.name }; inp.value = it.name; }
        box.classList.add('hidden'); inp.blur(); showHint();
    });
    return {
        setItems(list) { items = list || []; },
        set(id, name) { picked = id ? { id, name } : null; inp.value = name || ''; showHint(); },
        // tanlangan → id; aynan shu nom ro'yxatda bo'lsa → o'sha; aks holda yangi nom
        value() {
            if (picked) return picked;
            const e = exact();
            if (e) return { id: e.id, name: e.name };
            const name = inp.value.trim().replace(/\s+/g, ' ');
            return name.length >= 3 ? { id: null, name } : null;
        },
    };
}
// eski telefonlarda :has() yo'q — tanlangan holat klass bilan
const segSync = () => document.querySelectorAll('.seg label').forEach(l => l.classList.toggle('on', l.querySelector('input').checked));
document.querySelectorAll('.seg input').forEach(i => i.addEventListener('change', segSync)); segSync();
const mahalla = Combo('mahalla', 'MFY'), street = Combo('street', 'ko‘cha');
const current = () => districts.find(x => String(x.id) === $('district').value) || { mahallas: [], streets: [] };
$('district').onchange = () => {
    const d = current();
    mahalla.setItems(d.mahallas); mahalla.set(null, '');
    street.setItems(d.streets); street.set(null, '');
    point || (d.center && map && map.setView(d.center, 14));
};

// ---- xarita: bosilgan joy — obyekt; belgini surib aniqlashtiriladi
let map = null, marker = null, point = null;
const pin = () => L.divIcon({ className: 'pin', html: '📍', iconSize: [30, 30], iconAnchor: [15, 28] });
function setPoint(lat, lng, zoom) {
    point = { lat: +lat, lng: +lng };
    if (!marker) {
        marker = L.marker([point.lat, point.lng], { icon: pin(), draggable: true }).addTo(map);
        marker.on('dragend', () => { const p = marker.getLatLng(); setPoint(p.lat, p.lng); });
    } else marker.setLatLng([point.lat, point.lng]);
    zoom && map.setView([point.lat, point.lng], Math.max(map.getZoom(), zoom));
    $('coords').textContent = `✅ Tanlandi: ${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`;
    $('coords').className = 'hint ok';
}
function initMap(center) {
    if (!window.L) { $('coords').textContent = 'Xarita yuklanmadi — «Turgan joyimni olish» tugmasini bosing.'; return; }
    map = L.map('map').setView(center || [41.3111, 69.2797], center ? 14 : 11);
    // tile.openstreetmap.org va CARTO Telegram oynasini bloklaydi (Referer yo'q) — Esri kalitsiz ishlaydi;
    // sun'iy yo'ldosh — obyektni tomidan topish uchun (nomlar ustida)
    const esri = name => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${name}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri' });
    const streets = esri('World_Street_Map');
    const sat = L.layerGroup([esri('World_Imagery'), esri('Reference/World_Boundaries_and_Places'), esri('Reference/World_Transportation')]);
    streets.addTo(map);
    // gastronomik ko'chalar (KMZ) — bosilsa ham nuqta tanlanadi (hodisa xaritaga o'tadi)
    const g = L.layerGroup(gastro.map(s => L.polyline(s.l, { color: '#f59e0b', weight: 5, opacity: .85 }).bindTooltip('🍽 ' + s.n, { sticky: true }))).addTo(map);
    L.control.layers({ 'Xarita': streets, 'Sun’iy yo’ldosh': sat }, gastro.length ? { 'Gastronomik ko‘chalar': g } : null, { position: 'topright' }).addTo(map);
    map.on('click', e => setPoint(e.latlng.lat, e.latlng.lng));
    // Telegram oynani kengaytirganda / telefon burilganda xarita o'lchami yangilansin (aks holda kulrang bo'shliq qoladi)
    const fit = () => map.invalidateSize();
    setTimeout(fit, 300); window.addEventListener('resize', fit); tg && tg.onEvent && tg.onEvent('viewportChanged', fit);
}

$('goLoc').onclick = async () => {
    $('goLoc').disabled = true; say('Joylashuv aniqlanmoqda…');
    try {
        const p = await TgLoc.get();
        map ? setPoint(p.lat, p.lng, 17) : (point = { lat: p.lat, lng: p.lng }, $('coords').textContent = `✅ ${p.lat.toFixed(6)}, ${p.lng.toFixed(6)}`);
        say('📍 Joylashuv olindi' + (p.accuracy ? ` (aniqlik ~${Math.round(p.accuracy)} m). Kerak bo‘lsa belgini surib aniqlashtiring.` : ''), 'ok');
    } catch (e) { say(e.message || 'Joylashuv aniqlanmadi.', 'err'); }
    $('goLoc').disabled = false;
};

$('f').onsubmit = async ev => {
    ev.preventDefault();
    if (busy) return;
    const m = mahalla.value(), s = street.value();
    if (!m) return say('MFY ni tanlang yoki nomini yozing.', 'err');
    if (!s) return say('Ko‘chani tanlang yoki nomini yozing.', 'err');
    if (!point) { $('map').scrollIntoView({ behavior: 'smooth', block: 'center' }); return say('Xaritada obyekt joyini belgilang.', 'err'); }
    const num = v => (v.trim() === '' ? null : Number(v.trim().replace(',', '.')));
    busy = true; $('goSave').disabled = true; say('Yuborilmoqda…');
    try {
        const { ok, j } = await api('save', {
            site: q.get('site') || null, district_id: Number($('district').value),
            mahalla_id: m.id, mahalla_name: m.id ? null : m.name, street_id: s.id, street_name: s.id ? null : s.name,
            house: $('house').value.trim() || null, status: document.querySelector('input[name=status]:checked').value,
            lat: point.lat, lng: point.lng, area_m2: num($('area').value),
            is_tutash: $('tutash').checked, violator_name: $('violator').value.trim(), violator_passport: $('passport').value.trim() || null,
            org_inn: $('inn').value.trim() || null, court_number: $('courtNo').value.trim() || null,
            court_date: $('courtDate').value || null, court_status: $('courtStatus').value.trim() || null,
        });
        if (ok) { $('f').classList.add('hidden'); say('✅ Yuborildi. Admin tasdiqlagach ro‘yxatga qo‘shiladi.', 'ok'); setTimeout(() => tg && tg.close(), 1500); return; }
        say(j.message || 'Yuborilmadi. Qayta urinib ko‘ring.', 'err');
    } catch (e) { say('Aloqa xatosi. Qayta urinib ko‘ring.', 'err'); }
    busy = false; $('goSave').disabled = false;
};

(async () => {
    say('Yuklanmoqda…');
    try {
        const { ok, j } = await api('state', { site: q.get('site') || null });
        if (!ok) return say(j.message || 'Telegram ichida oching.', 'err');
        districts = j.districts || [];
        if (!districts.length) return say('Sizga tuman biriktirilmagan. Administratorga murojaat qiling.', 'err');
        $('district').innerHTML = districts.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
        const s = j.site;
        $('district').value = String(s ? s.district_id : (q.get('district') || districts[0].id));
        const d = current();
        mahalla.setItems(d.mahallas); street.setItems(d.streets);
        say(''); $('f').classList.remove('hidden');
        gastro = j.gastro || [];
        initMap(s && s.lat ? [s.lat, s.lng] : d.center); // forma ko'ringandan keyin — xarita o'lchami to'g'ri chiqadi
        if (s) {
            $('title').textContent = '✏️ Obyektni tahrirlash';
            if (s.approval === 'rejected') { $('note').textContent = '❌ Rad etilgan' + (s.approval_note ? ': ' + s.approval_note : '') + '. Tuzatib, qayta yuboring.'; $('note').classList.remove('hidden'); }
            const set = (id, v) => { $(id).value = v == null ? '' : v; };
            mahalla.set(s.mahalla_id, (d.mahallas.find(x => x.id === s.mahalla_id) || {}).name);
            street.set(s.street_id, s.street);
            set('house', s.house); set('area', s.area_m2);
            set('violator', s.violator_name); set('passport', s.violator_passport); set('inn', s.org_inn);
            set('courtNo', s.court_number); set('courtDate', s.court_date); set('courtStatus', s.court_status);
            $('tutash').checked = !!s.is_tutash;
            const st = document.querySelector(`input[name=status][value="${s.status}"]`); st && (st.checked = true); segSync();
            s.lat && map && setPoint(s.lat, s.lng);
            $('goSave').textContent = 'Qayta yuborish';
        }
    } catch (e) { say('Aloqa xatosi. Qayta oching.', 'err'); }
})();
</script>
</body>
</html>
