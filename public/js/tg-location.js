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

    T.get = () => new Promise((resolve, reject) => {
        const m = lm();
        if (!m) return viaBrowser(resolve, reject);
        m.init(() => {
            if (!m.isLocationAvailable) return viaBrowser(resolve, reject);
            if (m.isAccessRequested && !m.isAccessGranted) return reject(fail(DENIED, true));
            m.getLocation(loc => {
                if (loc) return resolve({ lat: loc.latitude, lng: loc.longitude, accuracy: loc.horizontal_accuracy });
                // so'rov endi rad etildi — sozlamalarni ochish kerak; aks holda (GPS vaqtincha javob bermadi) brauzer orqali urinamiz
                if (m.isAccessRequested && !m.isAccessGranted) return reject(fail(DENIED, true));
                viaBrowser(resolve, reject);
            });
        });
    });

    // Faqat tugma bosilganda chaqiriladi (Telegram talabi).
    T.openSettings = () => {
        const m = lm();
        if (m && m.openSettings) m.openSettings();
        else if (tg && tg.showAlert) tg.showAlert('Telegram: bot menyusi (⋯) → Sozlamalar → Joylashuv ruxsatini yoqing.');
    };

    w.TgLoc = T;
})(window);
