<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Tekshiruv</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { margin: 0; padding: 20px 16px 40px; font-family: -apple-system, Roboto, sans-serif; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #111); text-align: center; }
        h1 { font-size: 19px; margin: 6px 0; }
        #addr { font-size: 14px; color: var(--tg-theme-hint-color, #666); margin-bottom: 10px; }
        p { color: var(--tg-theme-hint-color, #666); font-size: 15px; line-height: 1.4; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 17px; background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); margin-top: 12px; }
        button.secondary { background: transparent; color: var(--tg-theme-link-color, #2481cc); border: 1px solid currentColor; }
        button.danger { background: #c0392b; color: #fff; }
        button.ok { background: #2a9d4a; color: #fff; }
        button:disabled { opacity: .6; }
        textarea { width: 100%; box-sizing: border-box; min-height: 110px; padding: 10px; border-radius: 10px; border: 1px solid #8884; font-size: 16px; font-family: inherit; background: transparent; color: inherit; }
        #msg { margin-top: 14px; font-size: 15px; }
        .err { color: #d33; } .ok-t { color: #2a9d4a; }
        .hidden { display: none; }
        .cam { margin: 14px auto; background: #000; overflow: hidden; position: relative; }
        .cam.round { width: 260px; height: 260px; border-radius: 50%; }
        .cam.sq { width: 100%; max-width: 360px; aspect-ratio: 4 / 3; border-radius: 12px; }
        .cam video { width: 100%; height: 100%; object-fit: cover; }
        .cam video.front { transform: scaleX(-1); }
        #timer { font-size: 22px; font-weight: 600; }
        .sum { text-align: left; background: #8881; border-radius: 10px; padding: 12px; font-size: 15px; line-height: 1.5; }
        label.opt { display: block; text-align: left; padding: 12px; margin-top: 8px; border: 1px solid #8884; border-radius: 10px; font-size: 15px; }
        .doc { display: flex; align-items: center; gap: 10px; text-align: left; padding: 8px; margin-top: 8px; background: #8881; border-radius: 10px; font-size: 15px; }
        .doc img { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; }
        .doc span { flex: 1; }
        .doc button { width: auto; margin: 0; padding: 6px 10px; font-size: 14px; background: transparent; color: #c0392b; }
        #cancelLink { margin-top: 26px; font-size: 14px; background: transparent; color: #c0392b; padding: 8px; }
    </style>
</head>
<body>
<h1 id="title">Tekshiruv</h1>
<div id="addr"></div>

<div id="sLocation" class="hidden">
    <p>Tekshiruv faqat <b>obyekt hududida</b> (belgilangan radius ichida) o‘tkaziladi. Joylashuvingiz telefon GPS’idan avtomatik aniqlanadi.</p>
    <button id="goLoc">📍 Joylashuvni yuborish</button>
    <button id="goLocSettings" class="secondary hidden">⚙️ Ruxsatni yoqish</button>
</div>

<div id="sPhoto" class="hidden">
    <p id="photoHint"></p>
    <div class="cam sq"><video id="pvPhoto" autoplay playsinline muted></video></div>
    <button id="goShoot">📸 Rasmga olish</button>
</div>

<div id="sVideo" class="hidden">
    <p id="videoHint">Hududni ko‘rsating. Video <b>faqat shu oynada, jonli</b> yoziladi ({{ $minSeconds }}–15 soniya): avval yuzingiz, so‘ng hudud.</p>
    <div class="cam round"><video id="pvVideo" autoplay playsinline muted></video></div>
    <div id="timer">0.0 s</div>
    <button id="goRec">🎥 Yozishni boshlash</button>
    <button id="stopRec" class="hidden">Yozishni tugatish va yuborish</button>
    <button id="skipVideo" class="secondary hidden">⏭ O‘tkazib yuborish</button>
</div>

<div id="sDemo" class="hidden">
    <p>Ushbu hudud <b>buzib tashlandimi</b>?</p>
    <button class="ok" data-v="1">✅ Ha, buzilgan</button>
    <button class="secondary" data-v="2">🚧 Jarayonda (qisman buzilgan)</button>
    <button class="danger" data-v="0">❌ Yo‘q, buzilmagan</button>
    <button class="secondary" data-v="3">⚖️ Muammo bor (sud qarori bekor / boshqa)</button>
</div>

<div id="sIssue" class="hidden">
    <p>Qanday muammo?</p>
    <label class="opt"><input type="radio" name="issueType" value="court_cancelled" checked> ⚖️ Sud qarori bekor qilingan (hujjat ilova qilinadi)</label>
    <label class="opt"><input type="radio" name="issueType" value="other"> 📝 Boshqa muammo</label>
    <p id="issueHint">Izoh yozing: qaysi sud, qaror raqami va sanasi (kamida 10 ta belgi).</p>
    <textarea id="issueText" maxlength="1000" placeholder="Masalan: Mirzo Ulug‘bek tumanlararo sudi, 08.01.2025, №2-1001-2412/47918"></textarea>
    <button id="goIssue">Davom etish</button>
</div>

<div id="sDocs" class="hidden">
    <p>Sud qarorining <b>barcha sahifalarini</b> rasmga olib yoki PDF faylini yuklang (ko‘pi bilan <span id="docMax">10</span> ta).</p>
    <div id="docList"></div>
    <input type="file" id="docFile" accept="image/*,application/pdf" multiple class="hidden">
    <button id="goDocAdd" class="secondary">📎 Fayl qo‘shish (rasm yoki PDF)</button>
    <button id="goDocsDone" class="ok">Davom etish</button>
</div>

<div id="sReason" class="hidden">
    <p id="reasonHint">Obyekt nima uchun buzilmagan? <b>Sababini yozing</b> (kamida 10 ta belgi).</p>
    <textarea id="reason" maxlength="1000" placeholder="Sabab…"></textarea>
    <button id="goReason">Davom etish</button>
</div>

<div id="sConfirm" class="hidden">
    <div class="sum" id="sum"></div>
    <button id="goSubmit" class="ok">✅ Natijani yuborish</button>
</div>

<div id="msg"></div>
<button id="cancelLink" class="hidden">❌ Tekshiruvni bekor qilish</button>

<script src="/js/tg-camera.js?v=2"></script>
<script src="/js/tg-location.js?v=2"></script>
<script>
const MIN = {{ (int) $minSeconds }}, MAX = 15;
const tg = window.Telegram && window.Telegram.WebApp;
tg && (tg.ready(), tg.expand());
const $ = id => document.getElementById(id), msg = $('msg');
const say = (t, c) => { msg.textContent = t; msg.className = c || ''; };
const initData = () => (tg ? tg.initData : '');
const SECTIONS = ['sLocation', 'sPhoto', 'sVideo', 'sDemo', 'sReason', 'sIssue', 'sDocs', 'sConfirm'];
const show = id => SECTIONS.forEach(s => $(s).classList.toggle('hidden', s !== id));
const TITLES = {
    location: '1. Joylashuv', before_photo: '2. Hozirgi holat rasmi', before_video: '3. Hudud videosi (ixtiyoriy)', demolished: '4. Natija',
    reason: '5. Sabab', issue: '5. Muammo', documents: '6. Sud qarori nusxasi', after_photo: '5. Buzilgandan keyingi rasm', after_video: '6. Buzilgan hudud videosi (majburiy)', confirm: 'Tasdiqlash',
};

// Sust/uzilib qoladigan mobil internet: so'rov cheksiz osilib qolmasligi uchun vaqt chegarasi.
async function post(path, body, ms) {
    const isForm = body instanceof FormData;
    const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), ms);
    try {
        const r = await fetch('/tg/inspection/' + path, {
            method: 'POST', signal: ctl.signal,
            headers: { 'Accept': 'application/json', ...(isForm ? {} : { 'Content-Type': 'application/json' }) },
            body: isForm ? body : JSON.stringify({ initData: initData(), device: TgCam.info(), ...(body || {}) }),
        });
        const j = await r.json().catch(() => ({}));
        return { ok: r.ok && j.ok, j };
    } finally { clearTimeout(timer); }
}

async function api(path, body) {
    const isForm = body instanceof FormData;
    if (isForm) { body.append('initData', initData()); body.append('device', TgCam.info()); }
    try {
        return await post(path, body, isForm ? 180000 : 25000);
    } catch (e) {
        // Javob yo'qolgan bo'lishi mumkin: serverdagi holatni so'raymiz — so'rov yetib borgan bo'lsa, keyingi bosqichga o'tamiz.
        const lost = { ok: false, j: { message: 'Internet aloqasi sust yoki uzildi. Aloqani tekshirib, qayta urinib ko‘ring.' } };
        if (path === 'state') return lost;
        try {
            const s = await post('state', null, 15000);
            if (s.ok && state && s.j.step !== state.step) return s;
        } catch (_) {}
        return lost;
    }
}

let busy = false, session = null, state = null;
TgCam.init($('pvPhoto'));

function closeSoon() { setTimeout(() => tg && tg.close(), 1200); }

// ---------------------------------------------------------------- bosqichlarni ko'rsatish
async function render(s) {
    state = s;
    session && session.abort && session.abort();
    session = null;
    TgCam.release();
    $('addr').textContent = s.address + (s.mahalla ? ' (' + s.mahalla + ')' : '');
    $('title').textContent = TITLES[s.step] || 'Tekshiruv';
    $('cancelLink').classList.remove('hidden');
    say('');

    switch (s.step) {
        case 'location': show('sLocation'); $('goLoc').textContent = '📍 Joylashuvni yuborish'; $('goLoc').disabled = false; checkLocation(); break;
        case 'before_photo':
        case 'after_photo':
            show('sPhoto');
            $('photoHint').innerHTML = s.step === 'before_photo'
                ? 'Hududning <b>hozirgi (buzishdan oldingi) holatini</b> kameraga oling.'
                : 'Hududning <b>buzilgandan keyingi holatini</b> kameraga oling.';
            await openPhotoCam();
            break;
        case 'before_video':
        case 'after_video':
            show('sVideo');
            $('skipVideo').classList.toggle('hidden', s.step !== 'before_video');
            $('goRec').classList.remove('hidden'); $('stopRec').classList.add('hidden'); $('timer').textContent = '0.0 s';
            await openVideoCam();
            break;
        case 'demolished': show('sDemo'); break;
        case 'reason':
            show('sReason');
            $('title').textContent = s.in_progress ? '5. Buzish jarayoni' : TITLES.reason;
            $('reasonHint').innerHTML = s.in_progress
                ? 'Buzish jarayoni haqida <b>izoh yozing</b>: necha foizi buzilgan, kim buzmoqda, qachon tugaydi (kamida 10 ta belgi).'
                : 'Obyekt nima uchun buzilmagan? <b>Sababini yozing</b> (kamida 10 ta belgi).';
            $('reason').placeholder = s.in_progress ? 'Masalan: 70% buzilgan, tadbirkor o‘zi buzmoqda…' : 'Sabab…';
            break;
        case 'issue': show('sIssue'); break;
        case 'documents': show('sDocs'); renderDocs(s); break;
        case 'confirm': show('sConfirm'); renderSummary(s); break;
        default: say('Tekshiruv yakunlangan.', 'ok-t');
    }
}

function renderSummary(s) {
    const result = s.demolished ? '✅ Natija: BUZILGAN'
        : s.exempt_type === 'court_cancelled' ? '⚖️ Natija: SUD QARORI BEKOR QILINGAN'
        : s.exempt_type === 'other' ? '📝 Natija: BOSHQA MUAMMO'
        : s.in_progress ? '🚧 Natija: JARAYONDA (qisman buzilgan)' : '❌ Natija: BUZILMAGAN';
    const lines = ['📍 ' + s.address, result];
    if (!s.demolished && s.reason) lines.push((s.in_progress || s.exempt_type ? 'Izoh: ' : 'Sabab: ') + s.reason);
    if ((s.documents || []).length) lines.push('Ilova qilingan hujjatlar: ' + s.documents.length + ' ta');
    lines.push('Rasm va video: ' + (s.media || []).filter(k => !k.startsWith('document_')).length + ' ta');
    if (s.distance !== null && s.distance !== undefined) lines.push('Obyektgacha masofa: ' + s.distance + ' m' + (s.far ? ' ⚠️' : ''));
    $('sum').innerHTML = '';
    lines.forEach(l => { const d = document.createElement('div'); d.textContent = l; $('sum').appendChild(d); });
}

// ---------------------------------------------------------------- joylashuv (public/js/tg-location.js)
// Oyna ochilishi bilanoq joylashuv aniqlanadi va obyekt hududidan uzoq bo'lsa, rad etiladi.
async function checkLocation() {
    if (busy) return;
    busy = true; $('goLoc').disabled = true; $('goLocSettings').classList.add('hidden'); say('Joylashuv aniqlanmoqda…');
    try {
        const { ok, j } = await api('location', await TgLoc.get());
        busy = false;
        if (ok) return render(j);
        say(j.message || 'Xatolik yuz berdi. Qayta urinib ko‘ring.', 'err');
    } catch (e) {
        say(e.message || 'Xatolik yuz berdi.', 'err');
        $('goLocSettings').classList.toggle('hidden', !e.settings);
    }
    busy = false;
    $('goLoc').textContent = '🔄 Qayta tekshirish'; $('goLoc').disabled = false;
}

$('goLoc').onclick = checkLocation;
$('goLocSettings').onclick = () => TgLoc.openSettings();

// ---------------------------------------------------------------- rasm
async function openPhotoCam() {
    TgCam.init($('pvPhoto'));
    try { await TgCam.open('environment', { hi: true }); say(''); }
    catch (e) { say(e.message || 'Kameraga ruxsat berilmadi. Telegram uchun kamera ruxsatini yoqing.', 'err'); }
}

$('goShoot').onclick = async () => {
    if (busy) return;
    if (!TgCam.stream) return openPhotoCam();
    busy = true; $('goShoot').disabled = true; say('Rasm olinmoqda…');
    try {
        const clip = await TgCam.shoot();
        const fd = new FormData();
        fd.append('clip', clip, 'clip.' + TgCam.ext(clip));
        say('Rasm yuborilmoqda…');
        const { ok, j } = await api('photo', fd);
        busy = false; $('goShoot').disabled = false;
        if (ok) return render(j);
        say(j.message || 'Rasm yuborilmadi. Qayta urinib ko‘ring.', 'err');
    } catch (e) { say('Aloqa xatosi. Qayta urinib ko‘ring.', 'err'); }
    busy = false; $('goShoot').disabled = false;
};

// ---------------------------------------------------------------- video
async function openVideoCam() {
    TgCam.init($('pvVideo'));
    try { await TgCam.open('user', { audio: true }); say(''); }
    catch (e) { say(e.message || 'Kameraga ruxsat berilmadi. Telegram uchun kamera ruxsatini yoqing.', 'err'); }
}

$('goRec').onclick = async () => {
    if (busy) return;
    if (!TgCam.stream) return openVideoCam();
    busy = true;
    $('goRec').classList.add('hidden'); $('skipVideo').classList.add('hidden');
    $('stopRec').classList.remove('hidden'); $('stopRec').disabled = true;
    say('Yozilmoqda…');
    session = TgCam.record({
        max: MAX, min: MIN,
        onTick: (sec, canStop) => { $('timer').textContent = sec.toFixed(1) + ' s'; $('stopRec').disabled = !canStop; },
        onAuto: res => uploadVideo(res),
        onError: text => retryVideo(text),
    });
};

$('stopRec').onclick = async () => {
    if (!session) return;
    $('stopRec').disabled = true;
    const res = await session.stop();
    res && uploadVideo(res);
};

async function uploadVideo(res) {
    session = null;
    $('stopRec').classList.add('hidden');
    say('Video yuborilmoqda va qayta ishlanmoqda… Biroz kuting.');
    const fd = new FormData();
    fd.append('duration', res.duration.toFixed(2));
    fd.append('video', res.main, 'video.' + TgCam.ext(res.main));
    if (res.face.size > 3000) fd.append('face', res.face, 'face.' + TgCam.ext(res.face));
    try {
        const { ok, j } = await api('video', fd);
        busy = false;
        if (ok) return render(j);
        retryVideo(j.message || 'Video yuborilmadi. Qayta yozing.');
    } catch (e) { retryVideo('Aloqa xatosi. Qayta yozib ko‘ring.'); }
}

async function retryVideo(text) {
    busy = false; session = null;
    $('stopRec').classList.add('hidden');
    $('goRec').classList.remove('hidden'); $('timer').textContent = '0.0 s';
    $('skipVideo').classList.toggle('hidden', !state || state.step !== 'before_video');
    await openVideoCam();
    say(text, 'err');
}

$('skipVideo').onclick = async () => {
    const { ok, j } = await api('skip');
    ok ? render(j) : say(j.message || 'Xatolik yuz berdi.', 'err');
};

// ---------------------------------------------------------------- natija, sabab, tasdiq
document.querySelectorAll('#sDemo button').forEach(b => b.onclick = async () => {
    const { ok, j } = await api('demolished', { value: b.dataset.v });
    ok ? render(j) : say(j.message || 'Xatolik yuz berdi.', 'err');
});

$('goReason').onclick = async () => {
    const { ok, j } = await api('reason', { reason: $('reason').value });
    ok ? render(j) : say(j.message || 'Xatolik yuz berdi.', 'err');
};

// ---------------------------------------------------------------- muammo: sud qarori bekor / boshqa
document.querySelectorAll('input[name=issueType]').forEach(r => r.onchange = () => {
    const court = r.value === 'court_cancelled' && r.checked;
    $('issueHint').textContent = court
        ? 'Izoh yozing: qaysi sud, qaror raqami va sanasi (kamida 10 ta belgi).'
        : 'Muammoni batafsil yozing (kamida 10 ta belgi).';
    $('issueText').placeholder = court ? 'Masalan: Mirzo Ulug‘bek tumanlararo sudi, 08.01.2025, №2-1001-2412/47918' : 'Muammo…';
});

$('goIssue').onclick = async () => {
    const type = document.querySelector('input[name=issueType]:checked').value;
    const { ok, j } = await api('issue', { type, reason: $('issueText').value });
    ok ? render(j) : say(j.message || 'Xatolik yuz berdi.', 'err');
};

function renderDocs(s) {
    const docs = s.documents || [];
    $('docMax').textContent = s.max_documents;
    $('docList').innerHTML = '';
    docs.forEach((d, i) => {
        const row = document.createElement('div');
        row.className = 'doc';
        const name = document.createElement('span');
        name.textContent = (d.pdf ? '📄 PDF fayl ' : '🖼 Sahifa ') + (i + 1);
        const del = document.createElement('button');
        del.textContent = '✕ O‘chirish';
        del.onclick = async () => { const { ok, j } = await api('document/remove', { kind: d.kind }); ok ? render(j) : say(j.message || 'Xatolik yuz berdi.', 'err'); };
        row.append(name, del);
        $('docList').appendChild(row);
    });
    $('goDocAdd').disabled = docs.length >= s.max_documents;
    $('goDocsDone').disabled = docs.length === 0;
}

// Telefon rasmi bir necha MB bo'ladi: sust internet uchun 1600 px gacha kichraytirib, JPEG qilib yuboriladi.
function shrink(file) {
    if (!file.type.startsWith('image/')) return Promise.resolve(file);
    return new Promise(resolve => {
        const img = new Image(), url = URL.createObjectURL(file);
        img.onload = () => {
            const k = Math.min(1, 1600 / Math.max(img.width, img.height));
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(url);
            c.toBlob(b => resolve(b || file), 'image/jpeg', 0.82);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

$('goDocAdd').onclick = () => $('docFile').click();
$('docFile').onchange = async () => {
    const files = Array.from($('docFile').files || []);
    $('docFile').value = '';
    if (!files.length || busy) return;
    busy = true; $('goDocAdd').disabled = true;
    let last = null;
    for (let i = 0; i < files.length; i++) {
        say('Yuklanmoqda: ' + (i + 1) + ' / ' + files.length + '…');
        const blob = await shrink(files[i]);
        if (blob.size > 4500 * 1024) { say('Fayl juda katta (4.5 MB dan oshmasin): ' + files[i].name, 'err'); continue; }
        const fd = new FormData();
        fd.append('file', blob, blob.type === 'application/pdf' ? 'doc.pdf' : 'doc.jpg');
        const { ok, j } = await api('document', fd);
        if (!ok) { say(j.message || 'Fayl yuklanmadi.', 'err'); break; }
        last = j;
    }
    busy = false;
    if (last) { render(last); say('Yuklandi.', 'ok-t'); } else { $('goDocAdd').disabled = false; }
};

$('goDocsDone').onclick = async () => {
    const { ok, j } = await api('documents/done');
    ok ? render(j) : say(j.message || 'Xatolik yuz berdi.', 'err');
};

$('goSubmit').onclick = async () => {
    $('goSubmit').disabled = true; say('Yuborilmoqda…');
    try {
        const { ok, j } = await api('submit');
        if (ok) { say('✅ Tekshiruv natijasi saqlandi. Oynani yopishingiz mumkin.', 'ok-t'); closeSoon(); return; }
        say(j.message || 'Yuborilmadi. Qayta urinib ko‘ring.', 'err');
    } catch (e) { say('Aloqa xatosi. Qayta urinib ko‘ring.', 'err'); }
    $('goSubmit').disabled = false;
};

$('cancelLink').onclick = async () => {
    const go = tg && tg.showConfirm
        ? await new Promise(r => tg.showConfirm('Tekshiruv bekor qilinsinmi? Kiritilgan ma’lumotlar o‘chiriladi.', r))
        : confirm('Tekshiruv bekor qilinsinmi?');
    if (!go) return;
    TgCam.release();
    const { ok } = await api('cancel');
    if (ok) { say('Tekshiruv bekor qilindi.', 'ok-t'); closeSoon(); }
};

// ---------------------------------------------------------------- boshlanish
(async () => {
    say('Yuklanmoqda…');
    try {
        const { ok, j } = await api('state');
        if (!ok) return say(j.message || 'Telegram ichida oching.', 'err');
        await render(j);
    } catch (e) { say('Aloqa xatosi. Qayta oching.', 'err'); }
})();
</script>
</body>
</html>
