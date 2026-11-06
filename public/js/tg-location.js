// Joylashuvni aniqlash (Telegram Mini App). Telegram 8.0+ da joylashuv ruxsati har bir bot uchun alohida beriladi:
// xodim bir marta "ruxsat bermaslik"ni bossa, Telegram qayta so'ramaydi — telefon sozlamalarida ruxsat bo'lsa ham.
// Bunday holatda xato `settings: true` bilan qaytadi va sahifa "Ruxsatni yoqish" tugmasini ko'rsatadi (TgLoc.openSettings).
(function (w) {
    const T = {};
    const tg = w.Telegram && w.Telegram.WebApp;
    const lm = () => (tg && tg.LocationManager && tg.isVersionAtLeast && tg.isVersionAtLeast('8.0') ? tg.LocationManager : null);

    const fail = (text, settings) => Object.assign(new Error(text), { settings: !!settings });
    const DENIED = 'Bu botga joylashuv ruxsati berilmagan. «⚙️ Ruxsatni yoqish» tugmasini bosing va «Joylashuv» (Geoposition) ruxsatini yoqing, keyin qayta urinib ko‘ring.';

    function viaBrowser(resolve, reject) {
        if (!navigator.geolocation) return reject(fail('Qurilmada joylashuv xizmati mavjud emas.'));
        navigator.geolocation.getCurrentPosition(
            p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
            e => reject(e && e.code === 1
                ? fail(lm() ? DENIED : 'Joylashuvga ruxsat berilmadi. Telegram uchun joylashuv ruxsatini yoqing.', !!lm())
                : fail('Joylashuv aniqlanmadi. Ochiq joyga chiqib (GPS signali yaxshi joyda), qayta urinib ko‘ring.')),
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    }

    // LocationManager.init faqat BIR MARTA callback chaqiradi: ikkinchi marta (masalan «Qayta tekshirish») chaqirilsa,
    // Telegram callbackni umuman chaqirmaydi va sahifa "Joylashuv aniqlanmoqda…" da qotib qoladi. Shuning uchun init bir marta.
    let inited = null;
    const ready = m => inited || (inited = new Promise(r => (m.isInited ? r() : m.init(() => r()))));

    // Telegram ba'zan umuman javob bermaydi (eski Android klientlar) — 25 soniyadan keyin brauzer GPS'iga o'tamiz.
    const LIMIT = 25000;
    T.get = () => new Promise((settle, fault) => {
        const m = lm();
        if (!m) return viaBrowser(settle, fault);
        // natija faqat bir marta: Telegram yoki (vaqt tugasa) brauzer — qaysi biri birinchi bo'lsa
        let done = false;
        let timer = null;
        const resolve = v => { if (!done) { done = true; clearTimeout(timer); settle(v); } };
        const reject = e => { if (!done) { done = true; clearTimeout(timer); fault(e); } };
        timer = setTimeout(() => viaBrowser(resolve, reject), LIMIT);
        ready(m).then(() => {
            if (!m.isLocationAvailable) return viaBrowser(resolve, reject);
            if (m.isAccessRequested && !m.isAccessGranted) return reject(fail(DENIED, true));
            m.getLocation(loc => {
                if (loc) return resolve({ lat: loc.latitude, lng: loc.longitude, accuracy: loc.horizontal_accuracy });
                // so'rov endi rad etildi — sozlamalarni ochish kerak; aks holda (GPS vaqtincha javob bermadi) brauzer orqali urinamiz
                if (m.isAccessRequested && !m.isAccessGranted) return reject(fail(DENIED, true));
                viaBrowser(resolve, reject);
            });
        }).catch(() => viaBrowser(resolve, reject)); // SDK xato tashlasa (masalan getLocation) — brauzer GPS'i, osilib qolmasin
    });

    // Faqat tugma bosilganda chaqiriladi (Telegram talabi).
    T.openSettings = () => {
        const m = lm();
        if (m && m.openSettings) m.openSettings();
        else if (tg && tg.showAlert) tg.showAlert('Telegram: bot menyusi (⋯) → Sozlamalar → Joylashuv ruxsatini yoqing.');
    };

    // Mini App menyusiga (⋯) «Sozlamalar» bandi: bot ruxsatlarini (joylashuv) ochadi.
    if (tg && tg.SettingsButton && tg.isVersionAtLeast && tg.isVersionAtLeast('7.0')) {
        tg.SettingsButton.onClick(() => T.openSettings());
        tg.SettingsButton.show();
    }

    w.TgLoc = T;
})(window);
