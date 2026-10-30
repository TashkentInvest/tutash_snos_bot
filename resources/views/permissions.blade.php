<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Ruxsatlar</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body { margin: 0; padding: 20px 16px 40px; font-family: -apple-system, Roboto, sans-serif; background: var(--tg-theme-bg-color, #fff); color: var(--tg-theme-text-color, #111); text-align: center; }
        h1 { font-size: 19px; margin: 6px 0 4px; }
        p { color: var(--tg-theme-hint-color, #666); font-size: 15px; line-height: 1.4; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 17px; background: var(--tg-theme-button-color, #2481cc); color: var(--tg-theme-button-text-color, #fff); margin-top: 12px; }
        button.secondary { background: transparent; color: var(--tg-theme-link-color, #2481cc); border: 1px solid currentColor; }
        button:disabled { opacity: .6; }
        .row { display: flex; align-items: center; gap: 10px; text-align: left; padding: 12px; margin-top: 10px; background: #8881; border-radius: 10px; font-size: 16px; }
        .row b { flex: 1; font-weight: 500; }
        .hint { text-align: left; font-size: 14px; margin: 6px 4px 0; }
        .hidden { display: none; }
        .ok-t { color: #2a9d4a; } .err { color: #d33; }
    </style>
</head>
<body>
<h1>🔐 Ruxsatlarni tekshirish</h1>
<p>Tekshiruv va davomat uchun botga <b>joylashuv</b>, <b>kamera</b> va <b>mikrofon</b> ruxsati kerak. Tugmani bosing va chiqqan so‘rovlarda <b>«Ruxsat berish»</b>ni tanlang.</p>

<div class="row"><span>📍</span><b>Joylashuv</b><span id="stLoc">—</span></div>
<button id="goLocSettings" class="secondary hidden">⚙️ Joylashuv ruxsatini yoqish</button>
<div class="row"><span>📷</span><b>Kamera va mikrofon</b><span id="stCam">—</span></div>
<p id="camHint" class="hint err hidden">iPhone: <b>Sozlamalar → Telegram</b> → «Kamera» va «Mikrofon»ni yoqing. Android: <b>Sozlamalar → Ilovalar → Telegram → Ruxsatlar</b>. Keyin oynani yopib, qayta oching.</p>

<button id="goCheck">✅ Ruxsatlarni tekshirish</button>
<p id="msg"></p>

<script src="/js/tg-location.js?v=2"></script>
<script>
const tg = window.Telegram && window.Telegram.WebApp;
tg && (tg.ready(), tg.expand());
const $ = id => document.getElementById(id);
const mark = (id, ok, text) => { $(id).textContent = text || (ok ? '✅' : '❌'); };

async function checkLocation() {
    mark('stLoc', false, '⏳');
    try {
        await TgLoc.get();
        mark('stLoc', true);
        $('goLocSettings').classList.add('hidden');
        return true;
    } catch (e) {
        mark('stLoc', false);
        $('goLocSettings').classList.toggle('hidden', !e.settings);
        $('msg').textContent = e.message || '';
        $('msg').className = 'err';
        return false;
    }
}

async function checkCamera() {
    mark('stCam', false, '⏳');
    try {
        const st = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        st.getTracks().forEach(t => t.stop());
        mark('stCam', true);
        $('camHint').classList.add('hidden');
        return true;
    } catch (e) {
        mark('stCam', false);
        $('camHint').classList.remove('hidden');
        return false;
    }
}

$('goCheck').onclick = async () => {
    $('goCheck').disabled = true; $('msg').textContent = ''; $('msg').className = '';
    const loc = await checkLocation();
    const cam = await checkCamera();
    $('goCheck').disabled = false;
    if (loc && cam) {
        $('msg').textContent = '✅ Hamma ruxsatlar berilgan. Oynani yopishingiz mumkin.';
        $('msg').className = 'ok-t';
        setTimeout(() => tg && tg.close(), 1500);
    }
};
$('goLocSettings').onclick = () => TgLoc.openSettings();
</script>
</body>
</html>
