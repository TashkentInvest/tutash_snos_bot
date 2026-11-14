<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Ijara shartnomasi</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
        body { margin: 0; padding: 16px; font-family: -apple-system, Roboto, sans-serif; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #111); }
        h1 { font-size: 19px; margin: 4px 0 4px; text-align: center; }
        h2 { font-size: 15px; margin: 22px 0 2px; padding-top: 12px; border-top: 1px solid rgba(127,127,127,.25); }
        .sub { text-align: center; color: var(--tg-theme-hint-color, #777); font-size: 14px; margin-bottom: 8px; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 12px 0 6px; }
        label small, .hint { font-weight: 400; color: var(--tg-theme-hint-color, #888); font-size: 13px; }
        input, select { width: 100%; box-sizing: border-box; padding: 12px; font-size: 16px; border-radius: 10px; font-family: inherit;
            border: 1px solid var(--tg-theme-hint-color, #ccc); background: var(--tg-theme-secondary-bg-color, #f5f5f5); color: inherit; }
        .row { display: flex; gap: 8px; } .row > * { flex: 1; min-width: 0; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 17px; margin-top: 18px;
            background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); }
        button.secondary { background: transparent; color: var(--tg-theme-link-color, #2481cc); border: 1px solid currentColor; margin-top: 8px; padding: 11px; font-size: 15px; }
        button:disabled { opacity: .6; }
        #msg { margin-top: 14px; font-size: 15px; text-align: center; } .err { color: #d33; } .ok { color: #2a9d4a; }
        .hidden { display: none !important; }
        #map { height: 300px; border-radius: 10px; border: 1px solid var(--tg-theme-hint-color, #ccc); }
        .pin { font-size: 30px; line-height: 30px; text-align: center; filter: drop-shadow(0 2px 2px rgba(0,0,0,.4)); }
        .leaflet-container .leaflet-interactive:focus { outline: none; }
        #coords { margin-top: 6px; }
        .seg { display: flex; gap: 8px; }
        .seg label { flex: 1; margin: 0; padding: 12px; text-align: center; border-radius: 10px; font-weight: 600;
            border: 1px solid var(--tg-theme-hint-color, #ccc); background: var(--tg-theme-secondary-bg-color, #f5f5f5); }
        .seg input { display: none; }
        .seg label.on { background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); border-color: transparent; }
        .preview { margin-top: 8px; font-size: 13px; color: var(--tg-theme-hint-color, #777); }
        details summary { margin-top: 16px; font-size: 14px; color: var(--tg-theme-link-color, #2481cc); }
        .photos { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .shot { position: relative; margin: 0; height: 120px; border-radius: 10px; border: 2px dashed var(--tg-theme-hint-color, #bbb);
            display: flex; align-items: center; justify-content: center; overflow: hidden; font-weight: 600; background: var(--tg-theme-secondary-bg-color, #f5f5f5); }
        .shot input { display: none; }
        .shot img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: none; }
        .shot.done { border-style: solid; border-color: #2a9d4a; }
        .shot.done img { display: block; }
        .shot.done span { position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,.55); color: #fff; padding: 2px 6px; border-radius: 6px; font-size: 12px; }
    </style>
</head>
<body>
<h1>📄 Ijara shartnomasi</h1>
<div class="sub">Obyekt oldida to‘ldiring. Tayyor Word fayl botga keladi — chop etib, imzolanadi.</div>

<form id="f" class="hidden" autocomplete="off">
    <h2>1. Joy</h2>
    <div id="place" style="font-size:16px;font-weight:600;"></div>
    <select id="district" class="hidden"></select>
    <select id="street" class="hidden"></select>
    <label>Tutash hudud <small>(xaritada hudud burchaklarini ketma-ket bosing — ko‘k bo‘lib chiziladi)</small></label>
    <div id="map"></div>
    <div id="coords" class="hint">Joylashuv aniqlanmoqda…</div>
    <div id="polyInfo" class="hint">Hudud chizilmagan — burchaklarni bosing</div>
    <div class="row">
        <button type="button" id="undo" class="secondary">↶ Oxirgi nuqta</button>
        <button type="button" id="clearPoly" class="secondary">🗑 Qaytadan</button>
    </div>
    <button type="button" id="goLoc" class="secondary">📍 Joylashuvni qayta olish</button>
    <div class="row">
        <div><label>MFY</label><input id="mfy" list="mfyList" required minlength="3" maxlength="150" placeholder="masalan: Oybek"><datalist id="mfyList"></datalist></div>
        <div><label>Uy <small>(raqami)</small></label><input id="house" maxlength="100" placeholder="21A"></div>
    </div>
    <div class="row">
        <div><label>Kadastr raqami</label><input id="cadastre" required minlength="5" maxlength="60" placeholder="10:11:40:02:01:0612"></div>
        <div><label>Maydon, m² <small>(chizmadan)</small></label><input id="area" type="number" inputmode="decimal" min="0.5" step="0.01" required placeholder="chizing"></div>
    </div>
    <label>Faoliyat turi</label>
    <select id="activity"></select>
    <input id="activityOther" class="hidden" maxlength="150" placeholder="Faoliyat turini yozing" style="margin-top:8px;">

    <h2>2. Ijarachi</h2>
    <div class="seg">
        <label class="on"><input type="radio" name="party" value="legal" checked> Yuridik shaxs</label>
        <label><input type="radio" name="party" value="individual"> Jismoniy shaxs</label>
    </div>
    <div data-party="legal">
        <label>Tashkilot nomi <small>(masalan: “GLOBAL PROPERTY DEVELOPMENT” MChJ)</small></label>
        <input id="orgName" maxlength="255">
        <label>Tashkilot manzili</label>
        <input id="orgAddress" maxlength="500" placeholder="Toshkent shahri, Mirobod tumani, Taras Shevchenko ko‘chasi, 21A-uy">
        <label>Direktor F.I.SH <small>(familiya, ism, otasining ismi)</small></label>
        <input id="director" maxlength="150" placeholder="Yermatov Vali Rustamovich">
        <label>Hisob raqami (x/r)</label>
        <input id="account" inputmode="numeric" maxlength="40">
        <div class="row">
            <div><label>STIR</label><input id="inn" inputmode="numeric" maxlength="20"></div>
            <div><label>MFO</label><input id="mfo" inputmode="numeric" maxlength="10"></div>
        </div>
    </div>
    <div data-party="individual" class="hidden">
        <label>F.I.SH <small>(familiya, ism, otasining ismi)</small></label>
        <input id="personName" maxlength="255" placeholder="Kadirov Abduraxmon Abdugafurovich">
        <label>Yashash manzili <small>(ixtiyoriy)</small></label>
        <input id="personAddress" maxlength="500" placeholder="Shayxontohur tumani, … ko‘chasi, …-uy">
        <div class="row">
            <div><label>Pasport</label><input id="passport" maxlength="20" placeholder="AD 7739566"></div>
            <div><label>JShShIR</label><input id="jshshir" inputmode="numeric" maxlength="20"></div>
        </div>
        <div class="row">
            <div><label>Kim bergan <small>(ixtiyoriy)</small></label><input id="passportIssuer" maxlength="200"></div>
            <div><label>Yili</label><input id="passportYear" inputmode="numeric" maxlength="10" placeholder="2020"></div>
        </div>
    </div>
    <label>Telefon</label>
    <input id="phone" inputmode="tel" maxlength="30" placeholder="+998 90 123 45 67">

    <h2>3. Muddat va to‘lov</h2>
    <div class="row">
        <div><label>Boshlanishi</label><input id="start" type="date" required></div>
        <div><label>Tugashi</label><input id="end" type="date" required></div>
    </div>
    <label>Joriy yil uchun ijara to‘lovi, so‘m <small>(QQS bilan, baholovchi hisobotidan)</small></label>
    <input id="amount" type="number" inputmode="decimal" min="1" step="0.01" required placeholder="21787682.67">
    <div id="schedule" class="preview"></div>

    <h2>4. Rasmlar <small class="hint">(joyida, kamera bilan — 4 ta)</small></h2>
    <div class="photos">
        @for ($i = 0; $i < 4; $i++)
            <label class="shot" data-i="{{ $i }}">
                <input type="file" accept="image/*" capture="environment">
                <img alt="">
                <span>📷 {{ $i + 1 }}-rasm</span>
            </label>
        @endfor
    </div>
    <div id="photoInfo" class="hint"></div>

    <details>
        <summary>Ariza raqami va sanasi (ixtiyoriy)</summary>
        <div class="row">
            <div><label>Ariza raqami</label><input id="appNo" maxlength="40"></div>
            <div><label>Ariza sanasi</label><input id="appDate" type="date"></div>
        </div>
    </details>

    <button type="submit" id="goSave">Shartnomani tayyorlash</button>
</form>
<div id="msg"></div>

<script src="/js/tg-location.js?v=4"></script>
<script>
const tg = window.Telegram && window.Telegram.WebApp;
tg && (tg.ready(), tg.expand(), tg.disableVerticalSwipes && tg.disableVerticalSwipes());
const $ = id => document.getElementById(id), msg = $('msg');
const say = (t, c) => { msg.textContent = t; msg.className = c || ''; };
const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const initData = () => (tg ? tg.initData : '');
let districts = [], radius = 300, maxAcc = 150, busy = false, map = null, marker = null, point = null, layers = [];
const streetParam = new URLSearchParams(location.search).get('street');

async function api(path, body) {
    const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), 60000);
    try {
        const r = await fetch('/tg/lease-form/' + path, { method: 'POST', signal: ctl.signal,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ initData: initData(), ...body }) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok && j.errors) j.message = Object.values(j.errors)[0][0];
        return { ok: r.ok && j.ok, j };
    } finally { clearTimeout(timer); }
}

// maydonlar + rasmlar bitta so'rovda (multipart)
async function apiForm(path, body, files) {
    const fd = new FormData();
    fd.append('initData', initData());
    Object.entries(body).forEach(([k, v]) => v === null || v === undefined || fd.append(k, v));
    files.forEach((b, i) => fd.append('photos[]', b, `rasm-${i + 1}.jpg`));
    const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), 120000);
    try {
        const r = await fetch('/tg/lease-form/' + path, { method: 'POST', signal: ctl.signal, headers: { 'Accept': 'application/json' }, body: fd });
        const j = await r.json().catch(() => ({}));
        if (!r.ok && j.errors) j.message = Object.values(j.errors)[0][0];
        return { ok: r.ok && j.ok, j };
    } finally { clearTimeout(timer); }
}

const district = () => districts.find(d => String(d.id) === $('district').value) || { streets: [], mahallas: [], boundary: [] };
const street = () => district().streets.find(s => String(s.id) === $('street').value);

// ko'chagacha masofa (metr) — server ham xuddi shunday tekshiradi
function distToLine(lat, lng, line) {
    const kx = Math.cos(lat * Math.PI / 180) * 111320, ky = 110574;
    const xy = line.map(p => [(p[1] - lng) * kx, (p[0] - lat) * ky]);
    let min = Infinity;
    for (let i = 0; i < xy.length - 1; i++) {
        const [ax, ay] = xy[i], [bx, by] = xy[i + 1], dx = bx - ax, dy = by - ay;
        const t = (dx || dy) ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / (dx * dx + dy * dy))) : 0;
        min = Math.min(min, Math.hypot(ax + t * dx, ay + t * dy));
    }
    return min;
}
// joy mosmi: GPS aniqligi va ko'chagacha masofa (server ham xuddi shunday tekshiradi)
function placeOk() {
    if (!point) return false;
    const s = street();
    return (point.acc == null || point.acc <= maxAcc) && (!s || distToLine(point.lat, point.lng, s.line) <= radius);
}
function showPoint() {
    if (!point) return;
    const s = street(), d = s ? Math.round(distToLine(point.lat, point.lng, s.line)) : null;
    const acc = point.acc != null ? ` · aniqlik ±${Math.round(point.acc)} m` : '';
    $('coords').textContent = point.acc != null && point.acc > maxAcc
        ? `⚠️ GPS aniqligi past (±${Math.round(point.acc)} m). Ochiq joyga chiqib, qayta oling (talab: ±${maxAcc} m).`
        : (d === null ? '✅ Joylashuv olindi' + acc : (d <= radius ? `✅ Ko‘chadan ~${d} m — mos${acc}` : `⚠️ Ko‘chadan ~${d} m uzoq (ruxsat: ${radius} m). Obyekt oldiga boring va qayta oling.`));
    $('coords').className = 'hint ' + (placeOk() ? 'ok' : 'err');
}
// faqat GPS: xodim obyekt oldida turadi — xaritada qo'lda belgilab bo'lmaydi
function setPoint(lat, lng, zoom, acc) {
    point = { lat: +lat, lng: +lng, acc: acc == null ? null : +acc };
    if (map) {
        if (!marker) {
            marker = L.marker([point.lat, point.lng], { icon: L.divIcon({ className: 'pin', html: '📍', iconSize: [30, 30], iconAnchor: [15, 28] }), interactive: false }).addTo(map);
        } else marker.setLatLng([point.lat, point.lng]);
        zoom && map.setView([point.lat, point.lng], Math.max(map.getZoom(), zoom));
    }
    showPoint();
}
function drawDistrict() {
    if (!map) return;
    layers.forEach(l => map.removeLayer(l)); layers = [];
    const d = district(), sel = $('street').value, all = [];
    (d.boundary || []).forEach(r => { all.push(...r); layers.push(L.polygon(r, { color: '#64748b', weight: 1, dashArray: '4 4', fill: false, interactive: false }).addTo(map)); });
    d.streets.forEach(s => {
        const on = String(s.id) === sel;
        layers.push(L.polyline(s.line, { color: on ? '#dc2626' : '#f59e0b', weight: on ? 7 : 4, opacity: on ? .95 : .7 }).addTo(map).bindTooltip(s.name, { sticky: true }));
    });
    const s = street();
    point ? map.setView([point.lat, point.lng], 19) : (s ? map.fitBounds(s.line, { padding: [40, 40], maxZoom: 17 }) : all.length && map.fitBounds(all, { padding: [20, 20] }));
}
function fillDistrict() {
    const d = district();
    $('street').innerHTML = d.streets.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    $('mfyList').innerHTML = d.mahallas.map(m => `<option value="${esc(m)}">`).join('');
    drawDistrict(); showPoint();
}
$('district').onchange = fillDistrict;
$('street').onchange = () => { drawDistrict(); showPoint(); };

async function locate() {
    $('goLoc').disabled = true; $('coords').textContent = 'Joylashuv aniqlanmoqda…'; $('coords').className = 'hint';
    try { const p = await TgLoc.get(); setPoint(p.lat, p.lng, 19, p.accuracy); }
    catch (e) { $('coords').textContent = e.message || 'Joylashuv aniqlanmadi. Telefonda GPS (joylashuv) yoqilganini tekshiring.'; $('coords').className = 'hint err'; }
    $('goLoc').disabled = false;
}
$('goLoc').onclick = locate;

// ---- tutash hudud: xaritada burchaklar ketma-ket bosiladi (maydoni avtomatik, kerak bo'lsa qo'lda tuzatiladi)
let poly = [], polyLayers = [], areaTouched = false;
function areaM2(r) {
    if (r.length < 3) return 0;
    const lat0 = r.reduce((s, p) => s + p[0], 0) / r.length, lng0 = r.reduce((s, p) => s + p[1], 0) / r.length;
    const kx = Math.cos(lat0 * Math.PI / 180) * 111320, ky = 110574;
    let sum = 0;
    for (let i = 0; i < r.length; i++) {
        const a = r[i], b = r[(i + 1) % r.length];
        sum += (a[1] - lng0) * kx * (b[0] - lat0) * ky - (b[1] - lng0) * kx * (a[0] - lat0) * ky;
    }
    return Math.abs(sum) / 2;
}
const polyFar = () => { const s = street(); return s && poly.length ? Math.max(...poly.map(p => distToLine(p[0], p[1], s.line))) : 0; };
const polyOk = () => poly.length >= 3 && polyFar() <= radius && areaM2(poly) >= 0.5;
function drawPoly() {
    if (!map) return;
    polyLayers.forEach(l => map.removeLayer(l)); polyLayers = [];
    if (poly.length >= 3) polyLayers.push(L.polygon(poly, { color: '#1d4ed8', weight: 3, fillOpacity: .3, interactive: false }).addTo(map));
    else if (poly.length === 2) polyLayers.push(L.polyline(poly, { color: '#1d4ed8', weight: 3, interactive: false }).addTo(map));
    poly.forEach(p => polyLayers.push(L.circleMarker(p, { radius: 6, color: '#fff', weight: 2, fillColor: '#1d4ed8', fillOpacity: 1, interactive: false }).addTo(map)));
    const a = areaM2(poly), far = Math.round(polyFar());
    if (poly.length < 3) {
        $('polyInfo').textContent = poly.length ? `${poly.length} nuqta — yana bosing (kamida 3 ta burchak)` : 'Hudud chizilmagan — burchaklarni bosing';
        $('polyInfo').className = 'hint';
    } else {
        $('polyInfo').textContent = far > radius ? `⚠️ Hudud ko‘chadan ${far} m gacha uzoq (ruxsat: ${radius} m)` : `✅ Hudud: ${poly.length} burchak · ${a.toFixed(1).replace('.', ',')} m²`;
        $('polyInfo').className = 'hint ' + (far > radius ? 'err' : 'ok');
        areaTouched || ($('area').value = a.toFixed(1));
    }
}
$('undo').onclick = () => { poly.pop(); drawPoly(); };
$('clearPoly').onclick = () => { poly = []; areaTouched = false; drawPoly(); };
$('area').addEventListener('input', () => { areaTouched = true; });

// ---- rasmlar: faqat kameradan, joyida (eski / galereya rasmi qabul qilinmaydi); yuborishdan oldin kichraytiriladi
const shots = [null, null, null, null];
async function shrink(file) {
    const url = URL.createObjectURL(file);
    try {
        const img = await new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = url; });
        const k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        const c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        return await new Promise(res => c.toBlob(res, 'image/jpeg', 0.82));
    } finally { URL.revokeObjectURL(url); }
}
function photoInfo(text, cls) {
    const n = shots.filter(Boolean).length;
    $('photoInfo').textContent = text || (n === 4 ? '✅ 4 ta rasm olindi' : `${n} / 4 rasm olindi`);
    $('photoInfo').className = 'hint ' + (cls || (n === 4 ? 'ok' : ''));
}
document.querySelectorAll('.shot').forEach(el => {
    const i = +el.dataset.i, input = el.querySelector('input');
    input.addEventListener('change', async () => {
        const f = input.files[0];
        input.value = '';
        if (!f) return;
        if (Date.now() - f.lastModified > 15 * 60 * 1000) return photoInfo('⚠️ Rasm joyida, hozir olinishi kerak — galereyadagi eski rasm qabul qilinmaydi.', 'err');
        try {
            shots[i] = await shrink(f);
            el.querySelector('img').src = URL.createObjectURL(shots[i]);
            el.classList.add('done');
            photoInfo();
        } catch (e) { photoInfo('Rasmni o‘qib bo‘lmadi — qayta oling.', 'err'); }
    });
});

// yuridik / jismoniy
const party = () => document.querySelector('input[name=party]:checked').value;
document.querySelectorAll('input[name=party]').forEach(i => i.addEventListener('change', () => {
    document.querySelectorAll('.seg label').forEach(l => l.classList.toggle('on', l.querySelector('input').checked));
    document.querySelectorAll('[data-party]').forEach(b => b.classList.toggle('hidden', b.dataset.party !== party()));
}));
$('activity').onchange = () => $('activityOther').classList.toggle('hidden', $('activity').value !== 'other');

// muddat: boshlanishdan 1 yil; to'lov jadvali — boshlanish oyidan dekabrgacha teng bo'linadi
const MONTHS = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentyabr', 'oktyabr', 'noyabr', 'dekabr'];
function schedule() {
    const s = $('start').value, a = Number(($('amount').value || '').replace(',', '.'));
    if (!s || !a) return $('schedule').textContent = '';
    const m = new Date(s).getMonth(), n = 12 - m, each = Math.floor(a / n * 100) / 100;
    $('schedule').textContent = `To‘lov jadvali: ${MONTHS[m]} – dekabr, ${n} oy × ${each.toLocaleString('ru-RU', { minimumFractionDigits: 2 })} so‘m`;
}
$('start').onchange = () => {
    if ($('start').value) { const d = new Date($('start').value); d.setFullYear(d.getFullYear() + 1); d.setDate(d.getDate() - 1); $('end').value = d.toISOString().slice(0, 10); }
    schedule();
};
$('amount').oninput = schedule;

$('f').onsubmit = async ev => {
    ev.preventDefault();
    if (busy) return;
    if (!placeOk()) { $('map').scrollIntoView({ behavior: 'smooth', block: 'center' }); return say(point ? 'Joylashuv mos emas — obyekt oldiga boring va «Joylashuvni qayta olish» tugmasini bosing.' : 'Joylashuv hali olinmadi — «Joylashuvni qayta olish» tugmasini bosing.', 'err'); }
    const legal = party() === 'legal', v = id => $(id).value.trim() || null;
    const activity = $('activity').value === 'other' ? v('activityOther') : $('activity').value;
    if (!activity) return say('Faoliyat turini yozing.', 'err');
    if (!(legal ? v('orgName') : v('personName'))) return say(legal ? 'Tashkilot nomini yozing.' : 'F.I.SH ni yozing.', 'err');
    if (!polyOk()) { $('map').scrollIntoView({ behavior: 'smooth', block: 'center' }); return say('Xaritada tutash hududni chizing — burchaklarni ketma-ket bosing (ko‘chadan ' + radius + ' m ichida).', 'err'); }
    if (shots.some(s => !s)) { $('photoInfo').scrollIntoView({ behavior: 'smooth', block: 'center' }); return say('Joyida 4 ta rasm oling.', 'err'); }
    busy = true; $('goSave').disabled = true; say('Shartnoma tayyorlanmoqda… (rasmlar yuklanmoqda)');
    try {
        const { ok, j } = await apiForm('save', {
            party: party(), district_id: Number($('district').value), gastro_street_id: Number($('street').value), lat: point.lat, lng: point.lng, accuracy: point.acc,
            tenant_name: legal ? v('orgName') : v('personName'), tenant_address: legal ? v('orgAddress') : v('personAddress'),
            director: legal ? v('director') : null, account: legal ? v('account') : null, inn: legal ? v('inn') : null, mfo: legal ? v('mfo') : null,
            passport: legal ? null : v('passport'), jshshir: legal ? null : v('jshshir'), passport_issuer: legal ? null : v('passportIssuer'),
            passport_year: legal ? null : v('passportYear'), phone: v('phone'),
            mfy: v('mfy'), house: v('house'), cadastre: v('cadastre'), area_m2: Number(($('area').value || '').replace(',', '.')), activity,
            start_date: v('start'), end_date: v('end'), annual_amount: Number(($('amount').value || '').replace(',', '.')),
            application_number: v('appNo'), application_date: v('appDate'), polygon: JSON.stringify(poly),
        }, shots);
        if (ok) {
            $('f').classList.add('hidden');
            say(j.sent ? '✅ Shartnoma tayyor — Word fayl botga yuborildi.' : '✅ Shartnoma saqlandi, lekin botga yuborib bo‘lmadi — administratorga murojaat qiling.', j.sent ? 'ok' : 'err');
            j.sent && setTimeout(() => tg && tg.close(), 1800);
            return;
        }
        say(j.message || 'Tayyorlab bo‘lmadi. Qayta urinib ko‘ring.', 'err');
    } catch (e) { say('Aloqa xatosi. Qayta urinib ko‘ring.', 'err'); }
    busy = false; $('goSave').disabled = false;
};

(async () => {
    say('Yuklanmoqda…');
    try {
        const { ok, j } = await api('state', {});
        if (!ok) return say(j.message || 'Telegram ichida oching.', 'err');
        districts = j.districts || []; radius = j.radius || 300; maxAcc = j.max_accuracy || 150;
        if (!districts.length) return say('Sizga ijara shartnomasi uchun tuman biriktirilmagan.', 'err');
        // tuman va ko'cha botdagi tugmalar bilan tanlanadi (…/tg/lease-form?street=ID)
        const d0 = districts.find(d => d.streets.some(s => String(s.id) === streetParam));
        if (!d0) return say('Botda «📄 Ijara shartnomasi tuzish» → tuman → ko‘chani tanlab, so‘ng «Shartnoma to‘ldirish» tugmasini bosing.', 'err');
        $('district').innerHTML = districts.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
        $('district').value = String(d0.id);
        $('activity').innerHTML = j.activities.map(a => `<option>${esc(a)}</option>`).join('') + '<option value="other">Boshqa — o‘zim yozaman</option>';
        const today = new Date().toISOString().slice(0, 10); $('start').value = today; $('start').onchange();
        say(''); $('f').classList.remove('hidden');
        if (window.L) {
            map = L.map('map', { doubleClickZoom: false }).setView([41.31, 69.27], 12);
            // hududni aniq chizish uchun sun'iy yo'ldosh rasmi (tomlar, ayvonlar ko'rinadi); oddiy xarita ham bor
            const esri = n => L.tileLayer(`https://server.arcgisonline.com/ArcGIS/rest/services/${n}/MapServer/tile/{z}/{y}/{x}`, { maxZoom: 19, attribution: '© Esri' });
            const sat = L.layerGroup([esri('World_Imagery'), esri('Reference/World_Transportation')]).addTo(map);
            L.control.layers({ 'Sun’iy yo’ldosh': sat, 'Xarita': esri('World_Street_Map') }, null, { position: 'topright' }).addTo(map);
            map.on('click', e => { poly.push([+e.latlng.lat.toFixed(7), +e.latlng.lng.toFixed(7)]); drawPoly(); });
            const fit = () => map.invalidateSize(); setTimeout(fit, 300); window.addEventListener('resize', fit); tg && tg.onEvent && tg.onEvent('viewportChanged', fit);
        } else $('coords').textContent = 'Xarita yuklanmadi — «Turgan joyimni olish» tugmasini bosing.';
        fillDistrict();
        $('street').value = streetParam; drawDistrict();
        $('place').textContent = '📍 ' + d0.name + ' · ' + street().name;
        locate(); // obyekt oldida — darhol GPS
    } catch (e) { say('Aloqa xatosi. Qayta oching.', 'err'); }
})();
</script>
</body>
</html>
