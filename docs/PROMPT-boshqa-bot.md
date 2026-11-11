# Prompt: davomat, soatlik qayd, obyekt tekshiruvi va hisobotlarni boshqa botga ko'chirish

> Quyidagi matnni yangi botning loyihasida ishlaydigan AI agentga (masalan Claude Code) bering.
> `[...]` ichidagilarni o'z loyihangizga moslang. Texnologiya tanlanmagan — agent yangi botning **o'z texnologiyasida** (til, framework, baza, admin panel) quradi.

---

## Vazifa

Mavjud Telegram botimizga xodimlar nazorati tizimini qo'sh. Xodim hamma ishni **Telegram Mini App (Web App)** oynasida bajaradi:
**joylashuv → jonli video → izoh**. Admin panelda hamma narsa ko'rinadi, hisobotlar Telegram'ga **rasm (jadval)** ko'rinishida boradi
va kimlarga borishi **Sozlamalar → Hisobotlar** sahifasida belgilanadi.

Bot: `[bot nomi]`, domen (HTTPS majburiy — Mini App faqat HTTPS'da ochiladi): `[https://...]`,
vaqt zonasi: `[Asia/Tashkent]`, ish kunlari: `[du–sha]`.

Avval loyihani o'qib chiq: qaysi til, framework, Telegram kutubxonasi, baza va admin panel ishlatilganini aniqla va **shularning o'zida** qur —
yangi texnologiya qo'shma, faqat juda zarur bo'lsa (masalan rasm chizish yoki video birlashtirish kutubxonasi) va sababini ayt.
Admin panel bo'lmasa — oddiy himoyalangan veb-sahifalar qil. Har bir qism uchun test yoz. Oxirida haqiqiy telefonda sinash ro'yxatini ber.

---

## 1. Rollar

- **Xodim** — asosiy ishni bajaradi (obyekt tekshiruvi) + ertalabki davomat; admin belgilasa — **soatlik qayd** ham (ikkinchi vazifa, `hourly_checkin` belgisi).
- **Shartnoma xodimi** — faqat ertalabki davomat + soatlik qayd (obyektlar bilan ishlamaydi). Unga soatlik qayd doim yoqilgan.
- **Aholi** (ixtiyoriy) — faqat ko'radi.
- Ro'yxatdan o'tish: `/start` → kirish turi → F.I.SH → **o'z telefonini** `request_contact` tugmasi bilan yuboradi
  (`contact.user_id == from.id`, forward emas). Raqam ruxsat ro'yxatida (tuman telefoni yoki admin oldindan qo'shgan "taklif") bo'lsa — tasdiqlanadi.
- **Ro'yxatdan o'tgan foydalanuvchidan hech qachon qayta `/start` yoki qayta ro'yxatdan o'tish so'ralmasin.**
  Har bir tugma/handler tekshiruvi rolga mos bo'lsin (shartnoma xodimi obyektlarni ko'rmasa ham davomat tugmalari unga ishlashi shart).
  Oddiy matn yozsa — "/start yuboring" emas, bosh sahifasi ko'rsatilsin. Telefon bosqichida raqamni qo'lda yozsa — kontakt tugmasi qayta chiqsin.
- Menyuda faqat 3 buyruq: `/start` (bosh sahifa), `/davomat`, `/yordam`. Matnlar qisqa va oddiy, texnik so'zlarsiz.

## 2. Ertalabki davomat

- Ish kunlari `08:00` da botdan so'rov: «Ishni boshladingizmi?» — **[✅ Ha] [❌ Yo'q]**. `08:45` da javob bermaganlarga eslatma, `09:00` muddat.
- **Ha** → Mini App: **1/3 joylashuv → 2/3 jonli video (3–15 s) → 3/3 izoh (majburiy, ≥5 belgi, masalan "Ofisdaman, yig'ilishdaman")** → yuborish.
- **Yo'q** → sabab matni (≥10 belgi), chatga yoziladi.
- **Qo'shimcha 3 daqiqa:** joylashuvni muddatgacha yuborib video yozayotgan xodimning videosi `09:03` gacha qabul qilinadi; yopish `09:03` da.
- Javob bermaganlar `09:03` da "javob bermadi" bo'ladi. Bot xatosi bo'lsa — admin buyrug'i bilan **davomatni qayta ochish** (har yozuvga o'z muddati `deadline_at`, uzr xabari bilan qayta so'rov).
- `/davomat` — boshlangan jarayonni **joyidan davom ettiradi** (joylashuv yuborilgan bo'lsa qayta so'ramaydi).
- Uzoqlik belgisi: eng yaqin biriktirilgan obyektdan `[5000]` m dan uzoq bo'lsa — "shubhali" belgisi (rad etmaydi).

## 3. Soatlik qayd

- Slotlar: `[10:00, 11:00, 12:00, 14:00, 15:00, 16:00, 17:00, 18:00]` (13:00 tushlik). Har slot boshida so'rov, javob muddati — `H:59:59`.
- Mini App: joylashuv → video → izoh (davomat bilan bir xil oyna, faqat endpointlar boshqa).
- Ertalab "sabab bilan yo'q" deganlarga yuborilmaydi. Video uchun ham 3 daqiqa qo'shimcha vaqt.
- Admin: kunlik jadval (qatorda xodim, ustunlarda davomat + har bir soat: vaqt, xarita, video, izoh), filtr *Hammasi / Shartnoma / Xodimlar*,
  "Eslatma yuborish" (javob bermaganlarga yoki hammaga).
- `/start` bosganda soatlik qayd holati va javob kutilayotgan bo'lsa — Mini App tugmasi ko'rsatilsin.

## 4. Obyekt tekshiruvi (rasm, video, izoh)

- Obyekt kartochkasida «Tekshiruvni boshlash» → tur: **Tekshirish** (oldingi holat → buzish jarayoni → keyingi holat, turli kunlarda),
  **Sud qarori bekor** (+ hujjatlar, 10 tagacha rasm/PDF), **Kelganda buzilgan**.
- Mini App: **joylashuv → jonli rasm → jonli video → [sud: hujjatlar] → izoh (majburiy) → tasdiqlash**.
  **Buzish jarayoni**da izoh bilan birga **buzilish foizi (1–99 %)** majburiy.
- Obyektdan `[300]` m dan uzoqda bo'lsa — **rad etiladi**; GPS aniqligi `[150]` m dan yomon bo'lsa — qayta urinish.
- **Moderatsiya:** "buzilgan" va "sud qarori bekor" natijalari admin tasdig'isiz obyekt holatini o'zgartirmaydi; "oldingi holat" va "jarayonda" — tasdiqsiz.
  Rad etilsa — xodim faqat ko'rsatilgan qismini qayta oladi (tuzatish). Tasdiqlashda izoh qoldirilsa — xodim bajarib, rasm/video bilan isbot yuboradi;
  bajarilmaguncha har kuni eslatma (10 martagacha). Obyekt boshqa yo'l bilan hal bo'lsa — eski eslatmalar o'zi yopiladi.
- Boshqa obyekt yoki eski eslatma tugmasi bosilganda, rasm/video olingan **tugallanmagan tekshiruv jimgina o'chirilmasin** — so'ralsin.
- Admin sahifasida: obyekt haqida **bitta ixcham blok** (manzil, holat, maydon, huquqbuzar, pasport, STIR, sud qarori, tuman mas'uli telefoni, xarita)
  — obyekt va tekshiruv sahifalarida bir xil; bo'sh maydonlar yashirin; **xodim izohi qizil va qalin**.

## 5. Mini App — majburiy texnik talablar (bularni o'tkazib yuborma)

1. **Autentifikatsiya:** har bir so'rov `initData` imzosi bilan tekshiriladi (HMAC, `WebAppData` kaliti). Amal qilish muddati **24 soat**
   (oyna yig'ib qo'yilib qaytilganda ham ishlasin). Xato matni: «Oyna eskirgan, tugmani qayta bosing».
2. **CSRF / sessiya:** Mini App so'rovlarida sessiya cookie'si yo'q — agar framework CSRF himoyasini qo'llasa, Mini App endpointlarining
   **butun prefiksini** (masalan `/tg/*`) undan ozod qil (bittasi unutilsa "CSRF token mismatch"). Himoya — faqat initData imzosi.
   Buni tekshiradigan test yoz (ko'p frameworklarda testlarda CSRF o'chiq bo'ladi, shuning uchun alohida kerak).
3. **Joylashuv:** Telegram `LocationManager` (8.0+) — `init()` faqat **bir marta** callback chaqiradi; ikkinchi chaqiruvda javob kelmaydi,
   shuning uchun init natijasini keshla. 25 s ichida javob bo'lmasa — `navigator.geolocation` ga o't. Ruxsat rad etilgan bo'lsa — "Ruxsatni yoqish" tugmasi (`openSettings`).
   Promise faqat bir marta yechilsin; **resolve/reject'ni qayta nomlama** (aks holda "Joylashuv aniqlanmoqda…" cheksiz aylanadi).
   Soxta `LocationManager` bilan JavaScript test yoz (masalan Node'da).
4. **Kamera:** faqat shu oynada jonli yozish (galereya emas). Old kamera (yuz) + orqa kamera (hudud) lavhalari serverda navbatda **bitta mp4'ga birlashtiriladi** (fon vazifasi, masalan ffmpeg bilan);
   birlashtirilmaguncha ikkalasi ham admin panelda ochiladi. Qora tasvir, takroriy fayl (hash), juda qisqa video — rad etiladi.
5. **Idempotentlik:** saqlangandan keyin javob tarmoqda yo'qolsa, qayta yuborish xato emas ("allaqachon saqlangan" → keyingi qadam / tayyor).
   Saqlangandan keyingi bot xabari `try/catch` ichida — Telegram sekin bo'lsa ham Mini App'ga xato qaytmasin.
6. **Holat serverda** (step): oyna yopilib qayta ochilsa joyidan davom etadi. Qo'sh bosishga qarshi: sahifada `busy` holati, serverda tranzaksiya va yozuvni qulflash (row lock) — 10 marta bossa ham bitta yozuv.
7. Tezlik chegarasi — Telegram foydalanuvchi bo'yicha (umumiy IP emas), xabar o'zbekcha. JS/CSS versiyasini (`?v=N`) har o'zgarishda oshir.

## 6. Hisobotlar va Sozlamalar

**Sozlamalar → Hisobotlar** sahifasi: har bir hisobot uchun **yoqish/o'chirish** va **oluvchilar (checkbox, qidiruv, hammasini belgilash)**,
"Namuna rasmni ochish" havolasi. Saqlash jadvali: `report_settings(key, enabled, recipients json)`. Xodim formasida alohida "hisobot oladi" belgisi bo'lmasin.

| Hisobot | Qachon | Ko'rinishi |
|---|---|---|
| Soatlik qayd — har soat | har slot muddati tugaganda, aniq **H:59:59** (rejalashtiruvchi H:59 da ishga tushib 59-soniyani kutadi, boshqa vazifalarni to'smasdan) | rasm: №, xodim, holat (qayd etildi / javob bermadi / sabab bilan yo'q), vaqt, izoh + "✅ 5/7 · ❌ 2" |
| Soatlik qayd — kun yakuni | **17:59:59** | rasm: xodim × davomat × har soat, har xodim "Jami 5/7" |
| Ertalabki davomat | davomat yopilganda (09:03) | rasm: №, xodim, holat, vaqt, izoh/sabab, belgi |
| `[Asosiy kunlik hisobot]` | `[18:00]` | `[rasm(lar)]` |

- Rasmlar serverda chiziladi (tanlangan tildagi grafika kutubxonasi + o'zbek harflarini (o‘, g‘) to'g'ri ko'rsatadigan TTF shrift, ikki baravar o'lchamda):
  yashil sarlavha, ko'k "Jami" qatori, rangli holatlar. Bitta rasm chizuvchi funksiya barcha hisobotlar uchun umumiy bo'lsin.
- Avtomatik yuborishda `enabled` tekshiriladi. **Dashboard'dagi qo'lda yuborish oynasi:** hisobot turi (davomat / soatlik qayd + soat / kun yakuni),
  rasmlar tanlovi, oluvchilar (sozlamadagilar oldindan belgilangan, o'zgartirish mumkin), jonli namuna rasm.
- Telegram API muddati ≥30 s; 429 da `retry_after` kutilsin; bloklagan foydalanuvchi (403) xato deb hisoblanmasin.

## 7. Ishonchlilik

- Rejadagi vazifalar: bir vaqtda ikki nusxa ishlamasin, lekin jarayon o'lib qolsa qulf **10–60 daqiqada o'zi ochilsin** (24 soat emas);
  uzoq vazifalar (hisobot, yopish) boshqalarni to'smasin. Ommaviy yuborishda bitta xodimdagi xato qolganlarni to'xtatmasin.
- Har bir tizim xatosi (va bot/Mini App yo'lidagi 419) `[DEBUG_ALERT_PHONES]` egasiga botda "🐞 Debug" xabari — bir xil xato 10 daqiqada bir marta.
- Kunlik zaxira: baza dump'i + media fayllar + maxfiy sozlamalar (shifrlash kaliti — usiz shifrlangan ma'lumot ochilmaydi), 14 kun,
  tiklash bir marta alohida bazada sinalsin; nusxalar server tashqarisiga ham ko'chirilsin.

## 8. Qabul mezonlari

- [ ] Shartnoma xodimi "Ha/Yo'q" ni bossa — "/start" chiqmaydi; sabab yozsa — qabul qilinadi.
- [ ] Mini App ikkinchi "Qayta tekshirish"da qotmaydi; joylashuv rad etilsa sozlama tugmasi chiqadi.
- [ ] Izohsiz (yoki <5 belgi) video qabul qilinmaydi; faqat izohda xato bo'lsa video qayta yozdirilmaydi.
- [ ] 08:59 da joylashuv, 09:01 da video — qabul; 09:04 da — rad.
- [ ] 15:59:59 da soatlik hisobot faqat sozlamadagi oluvchilarga rasm bo'lib boradi; 13:59 da (tushlik) yuborilmaydi; o'chirilgan hisobot yuborilmaydi.
- [ ] Svod/hisobotlardagi bir xil ko'rsatkich (masalan "qoldiq") hamma rasmda bir xil son.
- [ ] Barcha testlar yashil; deploydan keyin asosiy sahifalar 200, jurnalda xato yo'q.
