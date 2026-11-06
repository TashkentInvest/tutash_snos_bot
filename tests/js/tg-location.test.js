// Ishga tushirish: node tests/js/tg-location.test.js public/js/tg-location.js
// Node harness: loads public/js/tg-location.js with a fake Telegram SDK that behaves like the real one
// (init calls back only once; a second init while inited returns without calling back).
const fs = require('fs');
const path = process.argv[2];
let pass = 0, failN = 0;
const check = (name, cond) => { cond ? pass++ : failN++; console.log((cond ? 'PASS ' : 'FAIL ') + name); };

function load(lmOpts) {
    let isInited = false;
    const lm = {
        get isInited() { return isInited; },
        get isLocationAvailable() { return isInited && lmOpts.available; },
        get isAccessRequested() { return isInited && true; },
        get isAccessGranted() { return lmOpts.granted; },
        initCalls: 0,
        init(cb) {
            this.initCalls++;
            if (isInited) return lm;                 // real SDK: no callback
            if (lmOpts.initNeverAnswers) return lm;
            setTimeout(() => { isInited = true; cb && cb(); }, 5);
            return lm;
        },
        getLocation(cb) {
            if (!isInited) throw Error('WebAppLocationManagerNotInited');
            setTimeout(() => cb(lmOpts.loc), 5);
            return lm;
        },
    };
    const w = {
        Telegram: { WebApp: { LocationManager: lm, isVersionAtLeast: () => true } },
    };
    Object.defineProperty(globalThis, "navigator", { configurable: true, value: { geolocation: { getCurrentPosition: (ok) => setTimeout(() => ok({ coords: { latitude: 1, longitude: 2, accuracy: 9 } }), 5) } } });
    new Function('window', fs.readFileSync(path, 'utf8'))(w);
    return { T: w.TgLoc, lm };
}

const withTimeout = (p, ms) => Promise.race([p, new Promise((_, r) => setTimeout(() => r(new Error('HANG')), ms))]);

(async () => {
    {
        const { T, lm } = load({ available: true, granted: true, loc: { latitude: 41.3, longitude: 69.2, horizontal_accuracy: 8 } });
        const a = await withTimeout(T.get(), 1000).catch(e => e);
        check('first call resolves with Telegram location', a && a.lat === 41.3);
        const b = await withTimeout(T.get(), 1000).catch(e => e);
        check('second call (Qayta tekshirish) resolves too', b && b.lat === 41.3);
        check('init called only once', lm.initCalls === 1);
    }
    {
        const { T } = load({ available: true, granted: false, loc: null });
        const e = await withTimeout(T.get(), 1000).catch(e => e);
        check('denied -> rejects with settings flag', e instanceof Error && e.settings === true);
    }
    {
        const { T } = load({ available: false, granted: false });
        const a = await withTimeout(T.get(), 1000).catch(e => e);
        check('not available -> browser GPS', a && a.lat === 1);
    }
    console.log(`${pass} passed, ${failN} failed`);
    process.exit(failN ? 1 : 0);
})();
