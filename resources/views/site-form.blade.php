<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Obyekt qo‘shish</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { margin: 0; padding: 16px; font-family: -apple-system, Roboto, sans-serif; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #111); }
        h1 { font-size: 19px; margin: 4px 0 4px; text-align: center; }
        .sub { text-align: center; color: var(--tg-theme-hint-color, #777); font-size: 14px; margin-bottom: 12px; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
        label small { font-weight: 400; color: var(--tg-theme-hint-color, #888); }
        input, select, textarea { width: 100%; box-sizing: border-box; padding: 12px; font-size: 16px; border-radius: 10px; font-family: inherit;
            border: 1px solid var(--tg-theme-hint-color, #ccc); background: var(--tg-theme-secondary-bg-color, #f5f5f5); color: inherit; }
        .row { display: flex; gap: 8px; } .row > * { flex: 1; }
        .check { display: flex; align-items: center; gap: 10px; margin-top: 14px; font-size: 15px; }
        .check input { width: 22px; height: 22px; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 17px; margin-top: 18px;
            background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); }
        button.secondary { background: transparent; color: var(--tg-theme-link-color, #2481cc); border: 1px solid currentColor; margin-top: 8px; padding: 11px; font-size: 15px; }
        button:disabled { opacity: .6; }
        #msg { margin-top: 14px; font-size: 15px; text-align: center; } .err { color: #d33; } .ok { color: #2a9d4a; }
        .note { background: rgba(211, 51, 51, .1); color: #d33; border-radius: 10px; padding: 10px 12px; font-size: 14px; margin-bottom: 8px; }
        .hidden { display: none; }
    </style>
</head>
<body>
<h1 id="title">➕ Yangi obyekt</h1>
<div class="sub">Buzilishi kerak bo‘lgan joy. Admin tasdiqlagach ro‘yxatga qo‘shiladi.</div>
<div id="note" class="note hidden"></div>

<form id="f" class="hidden" autocomplete="off">
    <label>Tuman</label>
    <select id="district" required></select>

    <label>MFY</label>
    <select id="mahalla"></select>
    <input id="mahallaName" class="hidden" placeholder="Yangi MFY nomi" maxlength="150" style="margin-top:8px;">

    <label>Manzil <small>(ko‘cha, uy, mo‘ljal)</small></label>
    <input id="address" required minlength="5" maxlength="500" placeholder="masalan: Bunyodkor ko‘chasi 12-uy oldi">

    <label>Joylashuv <small>(obyekt yonida turib — telefon GPS’i, yoki xaritadan nusxalab)</small></label>
    <div class="row">
        <input id="lat" inputmode="decimal" placeholder="Kenglik 41.3…">
        <input id="lng" inputmode="decimal" placeholder="Uzunlik 69.2…">
    </div>
    <button type="button" id="goLoc" class="secondary">📍 Joylashuvimni olish</button>

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
tg && (tg.ready(), tg.expand());
const $ = id => document.getElementById(id), msg = $('msg');
const say = (t, c) => { msg.textContent = t; msg.className = c || ''; };
const q = new URLSearchParams(location.search);
const initData = () => (tg ? tg.initData : '');
let districts = [], busy = false;

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

function fillMahallas(selected) {
    const d = districts.find(x => String(x.id) === $('district').value) || { mahallas: [] };
    $('mahalla').innerHTML = '<option value="">— MFY ni tanlang —</option>'
        + d.mahallas.map(m => `<option value="${m.id}">${m.name.replace(/</g, '&lt;')}</option>`).join('')
        + '<option value="new">➕ Ro‘yxatda yo‘q — yangisini yozish</option>';
    $('mahalla').value = selected ? String(selected) : '';
    $('mahallaName').classList.toggle('hidden', $('mahalla').value !== 'new');
}
$('district').onchange = () => fillMahallas(null);
$('mahalla').onchange = () => $('mahallaName').classList.toggle('hidden', $('mahalla').value !== 'new');

$('goLoc').onclick = async () => {
    $('goLoc').disabled = true; say('Joylashuv aniqlanmoqda…');
    try {
        const p = await TgLoc.get();
        $('lat').value = p.lat.toFixed(6); $('lng').value = p.lng.toFixed(6);
        say('📍 Joylashuv olindi' + (p.accuracy ? ` (aniqlik ~${Math.round(p.accuracy)} m)` : ''), 'ok');
    } catch (e) { say(e.message || 'Joylashuv aniqlanmadi.', 'err'); }
    $('goLoc').disabled = false;
};

// "41.311, 69.279" kabi bitta maydonga nusxalansa — ikkiga ajratiladi
$('lat').addEventListener('input', () => {
    const m = $('lat').value.match(/^\s*(-?\d+[.,]\d+)\s*[,; ]\s*(-?\d+[.,]\d+)\s*$/);
    if (m) { $('lat').value = m[1].replace(',', '.'); $('lng').value = m[2].replace(',', '.'); }
});

$('f').onsubmit = async ev => {
    ev.preventDefault();
    if (busy) return;
    const mahalla = $('mahalla').value;
    if (!mahalla || (mahalla === 'new' && $('mahallaName').value.trim().length < 3)) return say('MFY ni tanlang yoki nomini yozing.', 'err');
    const num = v => (v.trim() === '' ? null : Number(v.trim().replace(',', '.')));
    busy = true; $('goSave').disabled = true; say('Yuborilmoqda…');
    try {
        const { ok, j } = await api('save', {
            site: q.get('site') || null, district_id: Number($('district').value),
            mahalla_id: mahalla === 'new' ? null : Number(mahalla), mahalla_name: mahalla === 'new' ? $('mahallaName').value.trim() : null,
            address: $('address').value.trim(), lat: num($('lat').value), lng: num($('lng').value), area_m2: num($('area').value),
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
        $('district').innerHTML = districts.map(d => `<option value="${d.id}">${d.name.replace(/</g, '&lt;')}</option>`).join('');
        const s = j.site;
        $('district').value = String(s ? s.district_id : (q.get('district') || districts[0].id));
        fillMahallas(s ? s.mahalla_id : null);
        if (s) {
            $('title').textContent = '✏️ Obyektni tahrirlash';
            if (s.approval === 'rejected') { $('note').textContent = '❌ Rad etilgan' + (s.approval_note ? ': ' + s.approval_note : '') + '. Tuzatib, qayta yuboring.'; $('note').classList.remove('hidden'); }
            const set = (id, v) => { $(id).value = v == null ? '' : v; };
            set('address', s.address); set('lat', s.lat); set('lng', s.lng); set('area', s.area_m2);
            set('violator', s.violator_name); set('passport', s.violator_passport); set('inn', s.org_inn);
            set('courtNo', s.court_number); set('courtDate', s.court_date); set('courtStatus', s.court_status);
            $('tutash').checked = !!s.is_tutash;
            $('goSave').textContent = 'Qayta yuborish';
        }
        say(''); $('f').classList.remove('hidden');
    } catch (e) { say('Aloqa xatosi. Qayta oching.', 'err'); }
})();
</script>
</body>
</html>
