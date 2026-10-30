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
        #cancelLink { margin-top: 26px; font-size: 14px; background: transparent; color: #c0392b; padding: 8px; }
    </style>
</head>
<body>
<h1 id="title">Tekshiruv</h1>
<div id="addr"></div>

<div id="sLocation" class="hidden">
    <p>Tekshiruv faqat <b>obyekt hududida</b> (belgilangan radius ichida) o‘tkaziladi. Joylashuvingiz telefon GPS’idan avtomatik aniqlanadi.</p>
    <button id="goLoc">📍 Joylashuvni yuborish</button>
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
<script>
const MIN = {{ (int) $minSeconds }}, MAX = 15;
const tg = window.Telegram && window.Telegram.WebApp;
tg && (tg.ready(), tg.expand());
const $ = id => document.getElementById(id), msg = $('msg');
const say = (t, c) => { msg.textContent = t; msg.className = c || ''; };
const initData = () => (tg ? tg.initData : '');
const SECTIONS = ['sLocation', 'sPhoto', 'sVideo', 'sDemo', 'sReason', 'sConfirm'];
const show = id => SECTIONS.forEach(s => $(s).classList.toggle('hidden', s !== id));
const TITLES = {
    location: '1. Joylashuv', before_photo: '2. Hozirgi holat rasmi', before_video: '3. Hudud videosi (ixtiyoriy)', demolished: '4. Natija',
    reason: '5. Sabab', after_photo: '5. Buzilgandan keyingi rasm', after_video: '6. Buzilgan hudud videosi (majburiy)', confirm: 'Tasdiqlash',
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
        case 'confirm': show('sConfirm'); renderSummary(s); break;
        default: say('Tekshiruv yakunlangan.', 'ok-t');
    }
}

function renderSummary(s) {
    const lines = ['📍 ' + s.address, s.demolished ? '✅ Natija: BUZILGAN' : (s.in_progress ? '🚧 Natija: JARAYONDA (qisman buzilgan)' : '❌ Natija: BUZILMAGAN')];
    if (!s.demolished && s.reason) lines.push((s.in_progress ? 'Izoh: ' : 'Sabab: ') + s.reason);
    lines.push('Rasm va video: ' + (s.media || []).length + ' ta');
    if (s.distance !== null && s.distance !== undefined) lines.push('Obyektgacha masofa: ' + s.distance + ' m' + (s.far ? ' ⚠️' : ''));
    $('sum').innerHTML = '';
    lines.forEach(l => { const d = document.createElement('div'); d.textContent = l; $('sum').appendChild(d); });
}

// ---------------------------------------------------------------- joylashuv
function getPosition() {
    return new Promise((resolve, reject) => {
        const lm = tg && tg.LocationManager;
        if (lm && tg.isVersionAtLeast && tg.isVersionAtLeast('8.0')) {
            lm.init(() => {
                if (!lm.isLocationAvailable) return reject(new Error('Qurilmada joylashuv xizmati mavjud emas.'));
                lm.getLocation(loc => loc
                    ? resolve({ lat: loc.latitude, lng: loc.longitude, accuracy: loc.horizontal_accuracy })
                    : reject(new Error('Joylashuvga ruxsat berilmadi. Sozlamalarda ruxsat bering va qayta urinib ko‘ring.')));
            });
            return;
        }
        navigator.geolocation.getCurrentPosition(
            p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
            () => reject(new Error('Joylashuvga ruxsat berilmadi. Telegram uchun joylashuvga ruxsat bering.')),
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
}

// Oyna ochilishi bilanoq joylashuv aniqlanadi va obyekt hududidan uzoq bo'lsa, rad etiladi.
async function checkLocation() {
    if (busy) return;
    busy = true; $('goLoc').disabled = true; say('Joylashuv aniqlanmoqda…');
    try {
        const { ok, j } = await api('location', await getPosition());
        busy = false;
        if (ok) return render(j);
        say(j.message || 'Xatolik yuz berdi. Qayta urinib ko‘ring.', 'err');
    } catch (e) { say(e.message || 'Xatolik yuz berdi.', 'err'); }
    busy = false;
    $('goLoc').textContent = '🔄 Qayta tekshirish'; $('goLoc').disabled = false;
}

$('goLoc').onclick = checkLocation;

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
