<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Davomat</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { margin: 0; padding: 24px 16px; font-family: -apple-system, Roboto, sans-serif; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #111); text-align: center; }
        h1 { font-size: 20px; margin: 8px 0; }
        p { color: var(--tg-theme-hint-color, #666); font-size: 15px; line-height: 1.4; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 17px; background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); margin-top: 16px; }
        button:disabled { opacity: .6; }
        #msg { margin-top: 16px; font-size: 15px; }
        .err { color: #d33; } .ok { color: #2a9d4a; }
        .hidden { display: none; }
        #circle { width: 260px; height: 260px; border-radius: 50%; overflow: hidden; margin: 16px auto; background: #000; position: relative; }
        #circle video { width: 100%; height: 100%; object-fit: cover; }
        #circle video.front { transform: scaleX(-1); }
        #timer { font-size: 22px; font-weight: 600; }
    </style>
</head>
<body>
<div id="stepLocation" class="hidden">
    <h1>📍 1/2. Joylashuvni aniqlash</h1>
    <p>Pastdagi tugmani bosing. Telefoningiz GPS’idan joriy joylashuvingiz olinadi va davomatga qayd etiladi.</p>
    <button id="goLoc">Joylashuvni yuborish</button>
</div>

<div id="stepVideo" class="hidden">
    <h1>🎥 2/2. Video yozish</h1>
    <p>Hududdagi holatingizni ko‘rsating. Video <b>faqat shu oynada, jonli</b> yoziladi ({{ $minSeconds }}–15 soniya).</p>
    <div id="circle"><video id="preview" autoplay playsinline muted></video></div>
    <div id="timer">0.0 s</div>
    <button id="goRec">Yozishni boshlash</button>
    <button id="stopRec" class="hidden">Yozishni tugatish va yuborish</button>
</div>

<div id="msg"></div>
<script>
const MIN = {{ (int) $minSeconds }}, MAX = 15;
const tg = window.Telegram && window.Telegram.WebApp;
tg && (tg.ready(), tg.expand());
const $ = id => document.getElementById(id), msg = $('msg');
const say = (t, c) => { msg.textContent = t; msg.className = c || ''; };
const show = id => ['stepLocation', 'stepVideo'].forEach(s => $(s).classList.toggle('hidden', s !== id));
const initData = () => (tg ? tg.initData : '');

// Sust mobil internetda so'rov cheksiz osilib qolmasin: vaqt chegarasi o'tsa — xato (qayta urinish tugmasi chiqadi).
async function api(path, body) {
    const isForm = body instanceof FormData;
    const ctl = new AbortController(), timer = setTimeout(() => ctl.abort(), isForm ? 180000 : 25000);
    try {
        const r = await fetch(path, { method: 'POST', signal: ctl.signal, headers: { 'Accept': 'application/json', ...(isForm ? {} : { 'Content-Type': 'application/json' }) }, body: isForm ? body : JSON.stringify(body) });
        const j = await r.json().catch(() => ({}));
        return { ok: r.ok && j.ok, j };
    } catch (e) {
        return { ok: false, j: { message: 'Internet aloqasi sust yoki uzildi. Aloqani tekshirib, qayta urinib ko‘ring.' } };
    } finally { clearTimeout(timer); }
}

function deviceInfo() {
    const tr = stream && stream.getVideoTracks()[0];
    const st = tr && tr.getSettings ? tr.getSettings() : {};
    return JSON.stringify({
        ua: navigator.userAgent.slice(0, 300),
        tg: { p: tg && tg.platform, v: tg && tg.version },
        cam: { label: tr && tr.label, w: st.width, h: st.height, f: st.facingMode, fps: st.frameRate },
        dpr: window.devicePixelRatio,
    });
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

$('goLoc').onclick = async () => {
    $('goLoc').disabled = true; say('Joylashuv aniqlanmoqda…');
    try {
        const { ok, j } = await api('/tg/attendance-location', { initData: initData(), device: deviceInfo(), ...(await getPosition()) });
        if (ok) { say(''); show('stepVideo'); startCamera(); return; }
        say(j.message || 'Xatolik yuz berdi. Qayta urinib ko‘ring.', 'err');
    } catch (e) { say(e.message || 'Xatolik yuz berdi.', 'err'); }
    $('goLoc').disabled = false;
};

// ---------------------------------------------------------------- video
// Kamera oqimi to'g'ridan-to'g'ri yoziladi (kanvas ishlatilmaydi: ba'zi Android qurilmalarda u qora kadr beradi).
// 1-lavha: old kamera, 1.5 soniya (xodimning yuzi). 2-lavha: orqa kamera (bo'lmasa old kamera) — asosiy video.
const SWITCH_MS = 1500, SIZE = 480;
const SIZE_C = { width: { ideal: SIZE }, height: { ideal: SIZE } };
let stream, recFace, recMain, faceChunks, mainChunks, startedAt, tick, autoStop, switchTimer, busy = false;
const preview = $('preview');

const sleep = ms => new Promise(r => setTimeout(r, ms));
const gum = c => navigator.mediaDevices.getUserMedia(c);
const openCam = async video => { try { return await gum({ video, audio: true }); } catch (e) { return await gum({ video, audio: false }); } };
const stopVideoTracks = st => st && st.getVideoTracks().forEach(t => t.stop());

async function setStream(st, front) {
    stream = st;
    preview.srcObject = st;
    preview.classList.toggle('front', front);
    try { await preview.play(); } catch (e) { /* avtomatik ijro */ }
}

async function tryOpen(video) {
    for (let k = 0; k < 3; k++) {
        try { return await gum({ video, audio: false }); } catch (e) { await sleep(300); }
    }
    return null;
}

async function startCamera() {
    releaseCamera();
    try {
        if (!navigator.mediaDevices || !window.MediaRecorder) throw new Error('Qurilma yoki Telegram versiyasi video yozishni qo‘llab-quvvatlamaydi. Telegramni yangilang.');
        let st;
        try { st = await openCam({ facingMode: { exact: 'user' }, ...SIZE_C }); }
        catch (e) { st = await openCam({ facingMode: { ideal: 'user' }, ...SIZE_C }); }
        await setStream(st, true);
        say('');
    } catch (e) { say(e.message || 'Kameraga ruxsat berilmadi. Telegram uchun kamera ruxsatini yoqing.', 'err'); }
}

function releaseCamera() {
    clearTimeout(switchTimer);
    stream && stream.getTracks().forEach(t => t.stop());
    stream = null;
}

function newRecorder(st, chunks) {
    const types = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'];
    const type = types.find(t => MediaRecorder.isTypeSupported(t));
    const r = new MediaRecorder(st, { ...(type ? { mimeType: type } : {}), videoBitsPerSecond: 500000, audioBitsPerSecond: 32000 });
    r.ondataavailable = e => e.data && e.data.size && chunks.push(e.data);
    return r;
}

const stopRecorder = r => new Promise(res => { if (!r || r.state === 'inactive') return res(); r.onstop = res; r.stop(); });

// 1.5 soniyadan so'ng: yuz lavhasi tugaydi, orqa kamera (bo'lmasa old kamera) bilan asosiy lavha boshlanadi.
async function switchSegment() {
    try {
        await stopRecorder(recFace);
        const audio = stream ? stream.getAudioTracks() : [];
        stopVideoTracks(stream);
        await sleep(300); // ba'zi telefonlar (Samsung) kamerani bo'shatishga vaqt talab qiladi
        const back = await tryOpen({ facingMode: { exact: 'environment' }, ...SIZE_C });
        const cam = back || await tryOpen({ facingMode: { ideal: 'user' }, ...SIZE_C });
        if (!cam) throw new Error('kamera ochilmadi');
        await setStream(new MediaStream([...cam.getVideoTracks(), ...audio]), !back);
        // Orqa kamera tasvir bermasa (track "muted" yoki kadr yo'q) — old kameraga qaytiladi.
        await sleep(700);
        const vt = stream.getVideoTracks()[0];
        if (back && (!vt || vt.muted || vt.readyState !== 'live' || !preview.videoWidth)) {
            stopVideoTracks(stream);
            await sleep(300);
            const front = await tryOpen({ facingMode: { ideal: 'user' }, ...SIZE_C });
            if (!front) throw new Error('kamera ochilmadi');
            await setStream(new MediaStream([...front.getVideoTracks(), ...audio]), true);
        }
        recMain = newRecorder(stream, mainChunks);
        recMain.start(300);
    } catch (e) {
        await failRecording('Kamerani almashtirib bo‘lmadi. Qayta urinib ko‘ring.');
    }
}

async function failRecording(text) {
    clearInterval(tick); clearTimeout(autoStop); clearTimeout(switchTimer);
    await Promise.all([stopRecorder(recFace), stopRecorder(recMain)]);
    recFace = recMain = null; busy = false;
    $('stopRec').classList.add('hidden');
    await startCamera();
    say(text, 'err');
    $('timer').textContent = '0.0 s'; $('goRec').classList.remove('hidden');
}

$('goRec').onclick = () => {
    if (!stream) return startCamera();
    faceChunks = []; mainChunks = []; recMain = null; busy = true;
    recFace = newRecorder(stream, faceChunks);
    recFace.start(300);
    startedAt = Date.now();
    switchTimer = setTimeout(switchSegment, SWITCH_MS);
    $('goRec').classList.add('hidden'); $('stopRec').classList.remove('hidden'); $('stopRec').disabled = true;
    say('Yozilmoqda…');
    tick = setInterval(() => {
        const sec = (Date.now() - startedAt) / 1000;
        $('timer').textContent = sec.toFixed(1) + ' s';
        $('stopRec').disabled = !(recMain && recMain.state === 'recording' && sec >= MIN);
    }, 100);
    autoStop = setTimeout(finish, MAX * 1000);
};

$('stopRec').onclick = finish;

async function finish() {
    if (!busy || !recMain || recMain.state !== 'recording') return;
    busy = false;
    clearInterval(tick); clearTimeout(autoStop);
    const duration = (Date.now() - startedAt) / 1000;
    $('stopRec').classList.add('hidden');
    await stopRecorder(recMain);
    await upload(duration);
}

async function upload(duration) {
    say('Video yuborilmoqda…');
    const mainBlob = new Blob(mainChunks, { type: recMain.mimeType || 'video/webm' });
    const faceBlob = new Blob(faceChunks, { type: recFace.mimeType || 'video/webm' });
    const ext = b => (b.type.includes('mp4') ? 'mp4' : 'webm');
    const fd = new FormData();
    fd.append('initData', initData());
    fd.append('device', deviceInfo());
    fd.append('duration', duration.toFixed(2));
    fd.append('video', mainBlob, 'video.' + ext(mainBlob));
    if (faceBlob.size > 3000) fd.append('face', faceBlob, 'face.' + ext(faceBlob));
    try {
        const { ok, j } = await api('/tg/attendance-video', fd);
        if (ok) {
            releaseCamera();
            say('✅ Davomatingiz qayd etildi. Oynani yopishingiz mumkin.', 'ok');
            setTimeout(() => tg && tg.close(), 1200);
            return;
        }
        await failRecording(j.message || 'Video yuborilmadi. Qayta yozing.');
    } catch (e) { await failRecording('Aloqa xatosi. Qayta yozib ko‘ring.'); }
}

// ---------------------------------------------------------------- boshlanish
(async () => {
    say('Yuklanmoqda…');
    try {
        const { ok, j } = await api('/tg/attendance-state', { initData: initData() });
        if (!ok) return say(j.message || 'Telegram ichida oching.', 'err');
        say('');
        if (j.step === 'location') show('stepLocation');
        else if (j.step === 'video') { show('stepVideo'); startCamera(); }
        else if (j.step === 'done') say('✅ Davomatingiz allaqachon qayd etilgan.', 'ok');
        else say('Hozir davomat so‘rovi yo‘q yoki muddat tugagan. Botga qaytib, «Ha, ishni boshladim» tugmasini bosing.', 'err');
    } catch (e) { say('Aloqa xatosi. Qayta oching.', 'err'); }
})();
</script>
</body>
</html>
