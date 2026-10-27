/* Telegram Mini App uchun jonli kamera: rasm (qisqa lavhadan) va video (yuz -> hudud) olish.
 * Kanvasdan foydalanilmaydi (ba'zi Android qurilmalarda u qora kadr beradi): kamera oqimi to'g'ridan-to'g'ri yoziladi. */
window.TgCam = (function () {
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const gum = c => navigator.mediaDevices.getUserMedia(c);
    const stopVideo = st => st && st.getVideoTracks().forEach(t => t.stop());
    const SWITCH_MS = 1500;

    const T = { stream: null, preview: null };

    T.supported = () => !!(navigator.mediaDevices && window.MediaRecorder);

    T.init = preview => { T.preview = preview; };

    T.release = () => {
        clearTimeout(T._switch);
        T.stream && T.stream.getTracks().forEach(t => t.stop());
        T.stream = null;
        if (T.preview) T.preview.srcObject = null;
    };

    async function setStream(st, front) {
        T.stream = st;
        T.preview.srcObject = st;
        T.preview.classList.toggle('front', front);
        try { await T.preview.play(); } catch (e) { /* avtomatik ijro */ }
    }

    async function tryOpen(video) {
        for (let k = 0; k < 3; k++) {
            try { return await gum({ video, audio: false }); } catch (e) { await sleep(300); }
        }
        return null;
    }

    /** Kamerani ochadi. facing: 'user' | 'environment'; hi — rasm uchun yuqori sifat; audio — mikrofon bilan. */
    T.open = async function (facing, opts) {
        opts = opts || {};
        T.release();
        if (!T.supported()) throw new Error('Qurilma yoki Telegram versiyasi kamerani qo‘llab-quvvatlamaydi. Telegramni yangilang.');
        const size = opts.hi ? { width: { ideal: 1280 }, height: { ideal: 720 } } : { width: { ideal: 480 }, height: { ideal: 480 } };
        const open = async video => {
            if (!opts.audio) return await gum({ video, audio: false });
            try { return await gum({ video, audio: true }); } catch (e) { return await gum({ video, audio: false }); }
        };
        let st;
        try { st = await open({ facingMode: { exact: facing }, ...size }); }
        catch (e) { st = await open({ facingMode: { ideal: facing }, ...size }); }
        await setStream(st, facing === 'user');
    };

    function newRecorder(st, chunks, bps) {
        const types = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'];
        const type = types.find(t => MediaRecorder.isTypeSupported(t));
        const r = new MediaRecorder(st, { ...(type ? { mimeType: type } : {}), videoBitsPerSecond: bps || 500000, audioBitsPerSecond: 32000 });
        r.ondataavailable = e => e.data && e.data.size && chunks.push(e.data);
        return r;
    }

    const stopRecorder = r => new Promise(res => { if (!r || r.state === 'inactive') return res(); r.onstop = res; r.stop(); });

    /** Rasm: joriy kameradan ~1.2 soniyalik lavha yozadi (server undan kadr ajratadi). */
    T.shoot = async function () {
        if (!T.stream) throw new Error('Kamera ochilmagan.');
        await sleep(400); // diafragma / fokus barqarorlashsin
        const chunks = [];
        const rec = newRecorder(new MediaStream(T.stream.getVideoTracks()), chunks, 1500000);
        rec.start(200);
        await sleep(1200);
        await stopRecorder(rec);
        const type = rec.mimeType || 'video/webm';
        return new Blob(chunks, { type });
    };

    /**
     * Video: 1.5 soniya old kamera (yuz), so'ng orqa kamera (bo'lmasa old kamera) — hudud.
     * Qaytaradi: { stop(): Promise<{face, main, duration}> } ; xato bo'lsa onError(matn).
     */
    T.record = function ({ max, min, onTick, onAuto, onError }) {
        const faceChunks = [], mainChunks = [];
        let recFace = newRecorder(T.stream, faceChunks), recMain = null, tick, autoStop, finished = false;
        const startedAt = Date.now();
        recFace.start(300);

        T._switch = setTimeout(async () => {
            try {
                await stopRecorder(recFace);
                const audio = T.stream ? T.stream.getAudioTracks() : [];
                stopVideo(T.stream);
                await sleep(300); // ba'zi telefonlar (Samsung) kamerani bo'shatishga vaqt talab qiladi
                const back = await tryOpen({ facingMode: { exact: 'environment' }, width: { ideal: 480 }, height: { ideal: 480 } });
                const cam = back || await tryOpen({ facingMode: { ideal: 'user' }, width: { ideal: 480 }, height: { ideal: 480 } });
                if (!cam) throw new Error('kamera ochilmadi');
                await setStream(new MediaStream([...cam.getVideoTracks(), ...audio]), !back);
                // Orqa kamera tasvir bermasa (track "muted" yoki kadr yo'q) — old kameraga qaytiladi.
                await sleep(700);
                const vt = T.stream.getVideoTracks()[0];
                if (back && (!vt || vt.muted || vt.readyState !== 'live' || !T.preview.videoWidth)) {
                    stopVideo(T.stream);
                    await sleep(300);
                    const front = await tryOpen({ facingMode: { ideal: 'user' }, width: { ideal: 480 }, height: { ideal: 480 } });
                    if (!front) throw new Error('kamera ochilmadi');
                    await setStream(new MediaStream([...front.getVideoTracks(), ...audio]), true);
                }
                recMain = newRecorder(T.stream, mainChunks);
                recMain.start(300);
            } catch (e) {
                clearInterval(tick); clearTimeout(autoStop);
                finished = true;
                onError && onError('Kamerani almashtirib bo‘lmadi. Qayta urinib ko‘ring.');
            }
        }, SWITCH_MS);

        tick = setInterval(() => {
            const sec = (Date.now() - startedAt) / 1000;
            onTick && onTick(sec, !!(recMain && recMain.state === 'recording' && sec >= min));
        }, 100);

        const stop = async () => {
            if (finished || !recMain || recMain.state !== 'recording') return null;
            finished = true;
            clearInterval(tick); clearTimeout(autoStop);
            const duration = (Date.now() - startedAt) / 1000;
            await stopRecorder(recMain);
            const type = r => r.mimeType || 'video/webm';
            return {
                duration,
                face: new Blob(faceChunks, { type: type(recFace) }),
                main: new Blob(mainChunks, { type: type(recMain) }),
            };
        };
        autoStop = setTimeout(async () => { const res = await stop(); res && onAuto && onAuto(res); }, max * 1000);

        return {
            stop,
            abort: async () => { finished = true; clearInterval(tick); clearTimeout(autoStop); clearTimeout(T._switch); await Promise.all([stopRecorder(recFace), stopRecorder(recMain)]); },
        };
    };

    /** Qurilma ma'lumoti (JSON): brauzer, Telegram versiyasi, ishlatilayotgan kamera. */
    T.info = function () {
        const tg = window.Telegram && window.Telegram.WebApp;
        const tr = T.stream && T.stream.getVideoTracks()[0];
        const st = tr && tr.getSettings ? tr.getSettings() : {};
        return JSON.stringify({
            ua: navigator.userAgent.slice(0, 300),
            tg: { p: tg && tg.platform, v: tg && tg.version },
            cam: { label: tr && tr.label, w: st.width, h: st.height, f: st.facingMode, fps: st.frameRate },
            dpr: window.devicePixelRatio,
        });
    };

    T.ext = b => (b.type.includes('mp4') ? 'mp4' : 'webm');

    return T;
})();
