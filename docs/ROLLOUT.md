# Tutash Snos — joriy etish yo'riqnomasi

Ichki nazorat tizimi: **Telegram bot** (xodimlar uchun) + **Telegram Mini App** (joylashuv, jonli kamera) + **Laravel/Filament** (arxiv, moderatsiya va dashboard).
Manba ma'lumot: `dataset.csv` (849 obyekt, 12 tuman). Production holati: [DEPLOY.md](DEPLOY.md).

## 1. Tizim qanday ishlaydi

1. Foydalanuvchi `/start` → kirish turini tanlaydi (**Xodim** yoki **Aholi**) → F.I.SH yozadi → **o'z** telefon raqamini tugma orqali yuboradi.
   Raqam quyidagilardan biriga mos kelsa, tasdiqlanadi:
   - tumanning mas'ul telefoni (Tumanlar) yoki qo'shimcha xodim telefoni (`district_phones`, Tumanlar → telefonlar) — shu tuman(lar)ga **xodim** sifatida biriktiriladi.
     Bir raqam bir nechta tumanga tegishli bo'lishi mumkin; bir tumanda bir nechta xodim bo'lishi mumkin.
     Telefon qo'shilsa/o'zgarsa/o'chirilsa — shu raqamdagi xodimning tumanlari darhol yangilanadi;
   - oldindan qo'shilgan xodim (Filament → «Xodim qo'shish», `staff_invites`) — rol (xodim yoki shartnoma), ism va tumanlar taklifdan olinadi;
   - `ALL_DISTRICTS_PHONES` (barcha tumanlar, davomat yuritilmaydi) yoki `ALL_DISTRICTS_EMPLOYEE_PHONES` (barcha tumanlar, davomat yuritiladi).
2. Keyingi kirishlarda `/start` → o'z tumanlari → **MFY** → obyekt. Navigatsiya:
   - MFY ro'yxati (buzilmagani ko'pi tepada, sahifalangan),
   - MFY ichida faqat **buzilmagan** obyektlar (xohlasa "hammasi"),
   - 🔍 matn bo'yicha qidiruv, 📍 "yaqinimdagilar" (GPS bo'yicha eng yaqin 8 ta).
3. Tekshiruv — obyekt kartochkasida «Tekshiruvni boshlash» → tur tanlanadi:
   - **📋 Tekshirish** — bosqichlar turli kunlarda: avval **oldingi holat**; u qayd etilgandan keyin **buzish jarayoni** yoki **buzib bo'lindi (keyingi holat)**;
   - **⚖️ Sud qarori bekor qilingan** — rasm, video va hujjatlar (10 tagacha);
   - **🏚 Kelganda buzilgan ekan**.

   Har bir turda media **Mini App** orqali olinadi: joylashuv qurilma GPS'idan → jonli kameradan rasm → jonli video (≥3 s) → [sud: hujjatlar] → tasdiqlash.
   Obyektdan `INSPECTION_MAX_DISTANCE_M` (300 m) dan uzoqda bo'lsa tekshiruv **rad etiladi** (`INSPECTION_STRICT_DISTANCE=true`, standart);
   GPS aniqligi `MAX_GPS_ACCURACY_M` (150 m) dan yomon bo'lsa joylashuv qabul qilinmaydi.
4. Yuborilgach media serverga arxivlanadi (queue) va Filament'da ko'rinadi.
   - **Buzilgan** (keyingi holat / kelganda buzilgan) va **sud qarori bekor** natijalari **moderator tasdig'ini** kutadi; obyekt holati faqat tasdiqdan keyin o'zgaradi.
     Tasdiq kutilayotganda shu obyekt bo'yicha yangi tekshiruv boshlab bo'lmaydi.
   - **Rad etilsa** — xodimga izoh boradi, u «Tuzatish» orqali faqat moderator ko'rsatgan qismini (rasm, video, izoh, hujjat) qayta oladi.
   - **Tasdiqlashda izoh** qoldirilsa — xodim «Tushundim» deb javob beradi, bajarib, izoh bo'yicha tasdiq yuboradi (joylashuv → rasm → video → izoh);
     bajarilmagan izohlar bo'yicha ish kunlari `FOLLOWUP_REMIND_AT` (10:00) da eslatma ketadi. Moderator izohni yopadi yoki qayta ochadi.

## 1a. Rollar, davomat va hisobotlar

- Kirish turi bir marta tanlanadi; qayta tanlash — `/rol` (faqat tasdiqlanmaganlar uchun).
- **Aholi**: barcha tumanlar, MFY va obyektlar ro'yxati (`jami/buzilgan`), huquqbuzar, sud qarori, holat va tuman mas'uli telefoni. Pasport, maydon, tekshiruv va GPS yo'q.
- **Xodim**: telefon bo'yicha tasdiqlanadi, pasport to'liq ko'rinadi, tekshiruv va "yaqinimdagilar" ishlaydi, kunlik davomat.
- **Shartnoma xodimi** (faqat admin panel orqali, «Xodim qo'shish»): obyektlar bilan ishlamaydi — faqat kunlik davomat va **soatlik qayd**.
- **Davomat** (ish kunlari, du–sha):
  - `ATTENDANCE_SEND_AT` (08:00) da faol xodimlarga so'rov; yetmagan so'rovlar muddatgacha har daqiqa qayta yuboriladi (takror yubormaydi);
  - `ATTENDANCE_REMIND_AT` (08:45) da javob bermaganlarga eslatma;
  - `ATTENDANCE_DEADLINE` (09:00) da yopiladi, javob bermaganlar "javob bermadi" bo'ladi;
  - *Ha* → joylashuv (Mini App) → jonli video (≥3 s, majburiy); *Yo'q* → sabab matni;
  - eng yaqin biriktirilgan obyektdan `ATTENDANCE_MAX_DISTANCE_M` (5000 m) dan uzoq bo'lsa — "shubhali";
  - barcha tumanlarni ko'radigan (admin) raqamlarda davomat yuritilmaydi (Xodimlar → "Kunlik davomat yuritiladi").
- **Soatlik qayd** (shartnoma xodimlari): `CHECKIN_SLOTS` (10:00, 11:00, 12:00, 14:00 … 18:00; 13:00–14:00 tushlik), javob muddati `CHECKIN_WINDOW_MINUTES` (60).
  Joylashuv + video Mini App orqali. Bugun davomatda "yo'q" deganlarga yuborilmaydi. Filament → «Soatlik qayd» doskasidan javob bermaganlarga (yoki hammaga) eslatma yuboriladi.
- **Kunlik hisobot** `DAILY_REPORT_AT` (18:00): tumanlar kesimidagi svod rasmlari (albom). Hisobot kuni 18:00 dan 18:00 gacha.
  Faqat Xodimlar → «Hisobot oladi» belgilanganlarga yuboriladi; dashboard'dan oldindan ko'rib, qo'lda yuborish mumkin. Svod Excel/PNG ko'rinishida yuklab olinadi.
- **Xabar yuborish**: admin dashboard'dan tanlangan tumanlar xodimlariga umumiy xabar yuboradi.
- Bayram kunlari hozircha qo'lda: `config/tutash.php` → `attendance.days` (yoki jadvaldan kunni chiqarish).
- Imlo: `BOT_ALPHABET=classic` (o‘, g‘, sh, ch) yoki `new` (ö, ğ, ş, ç) — `.env` da o'zgartirib, `php artisan config:cache`.

## 2. Joriy etish bosqichlari

| Bosqich | Nima | Chiqish mezoni |
|---|---|---|
| 0. Tayyorgarlik (1 hafta) | PHP 8.2+/intl, PostgreSQL, HTTPS domen, webhook, admin akkaunt, zaxira nusxa, **bot tokenni yangilash** | Staging'da `phpunit` yashil, webhook ishlaydi |
| 1. Pilot (2 hafta) | Bektemir (19) va Yakkasaroy (19) — kichik tumanlar, 2–4 xodim | ≥90% tekshiruv to'liq yakunlanadi, "shubhali" ulushi tushuntirilgan |
| 2. Tuzatish | Pilot fikrlari: matnlar, masofa chegarasi, qo'shimcha maydonlar | Ochiq savollar (4-bo'lim) yopilgan |
| 3. Kengaytirish (2 hafta) | Qolgan 10 tuman, bosqichma-bosqich (3–4 tadan) | Har tumanda kamida 1 faol xodim |
| 4. Barqaror ish | Haftalik hisobot, oylik audit, kvartal xavfsizlik tekshiruvi | KPI'lar (3-bo'lim) |

## 3. Metodologiya va KPI

- **Yagona haqiqat manbai** — baza. CSV faqat boshlang'ich import (`php artisan sites:import`, qayta ishga tushirsa dublikat bermaydi va holatni bosib ketmaydi).
  `DistrictSeeder` faqat `db:seed` da ishlaydi (deploy uni ishga tushirmaydi) — productionda tuman telefonlari admin panelda yangilanadi.
- **Tekshiruv o'zgarmas arxiv**: yuborilgan tekshiruv bot orqali o'zgartirilmaydi. Moderator tasdiqlaydi yoki rad etadi (rad etilgani arxivlanadi, xodim tuzatib qayta yuboradi).
  Admin noto'g'ri turdagi natijani ko'chira oladi (masalan, «Oldingi holat» → «Keyingi holat», haqiqiy oldingi media yuklanadi).
- **Audit log** (`audit_logs`): ro'yxatdan o'tish, tekshiruv qadamlari (masofa/GPS rad etish, qayta ishlatilgan yoki qora media, yuborish), davomat, soatlik qayd,
  xodim qo'shish/roli, qo'lda hisobot va umumiy xabar yoziladi. Filament'dagi barcha tahrirlar uchun to'liq audit — keyingi bosqich.
- **Ikki kalit**: "buzilgan" va "sud qarori bekor" natijalari moderator tasdig'isiz obyekt holatini o'zgartirmaydi (Filament → Tekshiruvlar → filtr: *Tasdiq*, *Shubhali*). Tavsiya: 48 soat ichida ko'rib chiqish.
- KPI: tumanlar bo'yicha % buzilgan; tekshiruv/xodim/hafta; shubhali va rad etilgan ulushi; o'rtacha "yuborishdan tasdiqgacha" vaqt; davomat va soatlik qayd ishtiroki; tekshirilmagan obyektlar soni (>30 kun).

## 4. Joriy etishdan oldin javob kerak bo'lgan savollar

**Jarayon**
1. "Buzilmagan" obyekt qancha vaqtda qayta tekshiriladi (har 7/14/30 kun)? Eslatma yuborilsinmi?
2. ~~Natijani kim tasdiqlaydi?~~ Hozir: admin (moderator) tasdiqlaydi; **viewer** roli — panelni faqat ko'radi (obyekt qo'sha oladi). Tuman rahbari (faqat o'z tumani) roli kerakmi?
3. ~~Buzilgan obyekt noto'g'ri belgilansa?~~ Hozir: moderator rad etadi → xodim tuzatadi; noto'g'ri tur — admin ko'chiradi.
4. ~~Xodim almashsa/telefon o'zgarsa?~~ Hozir: admin Tumanlar → telefonlarda raqamni o'zgartiradi yoki «Xodim qo'shish» orqali oldindan qo'shadi; xodim o'chirilsa arxivlanadi.
5. Bir obyektni ikki kishi parallel tekshirsa: tasdiq kutilayotgan natija bo'lsa yangi tekshiruv bloklanadi; boshqa hollarda ikkalasi saqlanadi.
6. Internet yo'q hududlarda xodim nima qiladi (hozir: oflayn rejim yo'q)?

**Ma'lumot va huquqiy**
7. Pasport/STIR kabi shaxsiy ma'lumotlar bazada **shifrlangan** saqlanadi, aholiga ko'rsatilmaydi. Saqlash muddati va "Shaxsga doir ma'lumotlar to'g'risida"gi qonun bo'yicha asos kim tomonidan tasdiqlanadi?
8. Foto/video qancha vaqt saqlanadi (1 yil? doimiy?) va server qayerda (mamlakat ichida bo'lishi shartmi)?
9. Telegram xodimlarga rasman ruxsat etilganmi (ichki siyosat)?

**Texnik**
10. Zaxira (backup) siyosati: kim, qayerga, qancha muddat?
11. Bot tili: hozir lotin. Kirill kerakmi?
12. Dataset'da `Sud holati`, `Buzilgan sana` kabi ustunlar to'liq emas — ma'lumot egasi kim?
13. Koordinatasi yo'q obyektlar uchun masofa tekshiruvi ishlamaydi — to'ldiriladimi?
14. ~~Masofa rejimi~~ Hozir: qat'iy (300 m dan uzoq — rad etiladi). Chegara pilotdan keyin qayta ko'rilsinmi?
15. Bayram kunlari ro'yxati panelda boshqarilsinmi?

## 5. Xavfsizlik

### Amalga oshirilgan
- **Autentifikatsiya**: ro'yxatdan o'tish faqat Telegram `contact` (egasining o'z raqami; `contact.user_id == from.id`, forward qilinmagan) + tuman telefonlari / taklif bilan mos kelish. 3 ta noto'g'ri urinishdan keyin bloklanadi, hammasi `audit_logs` ga yoziladi.
- **Mini App**: har bir so'rov Telegram imzosi (`initData`) bilan tekshiriladi; endpointlarda tezlik chegarasi (`throttle:tg-*`).
- **Avtorizatsiya**: har bir callback/xabarda xodimning tumani tekshiriladi (IDOR yo'q) — boshqa tuman obyektining `id`'sini qo'lda yuborish ishlamaydi (testda tekshirilgan). Shartnoma xodimi obyektlarni ko'rmaydi.
- **Suiiste'mol va DDoS**:
  - foydalanuvchi bo'yicha tezlik chegarasi (daqiqasiga 40 update),
  - foydalanuvchi bo'yicha qulf (`Cache::lock`) — bir vaqtda bitta update (qo'sh bosish poygasi yo'q),
  - jarayon holati bazada (step), "Yuborish" tranzaksiya + `lockForUpdate` ostida — 10 marta bossa ham bitta yozuv,
  - bir xodimda faqat bitta qoralama (partial unique index), eski qoralamalar soatlik tozalanadi,
  - webhook faqat maxfiy sarlavha (`X-Telegram-Bot-Api-Secret-Token`) bilan; `hash_equals`.
- **Soxtalashtirishga qarshi**: forward qilingan rasm/video/joylashuv rad etiladi; tekshiruv, davomat va qayd media'si Mini App'da **jonli kameradan** olinadi (galereyadan emas);
  video ≥3 soniya; qora (yopilgan kamera) rasm/video rad etiladi; bir xil fayl ikki marta ishlatib bo'lmaydi; GPS aniqligi va xodim–obyekt masofasi tekshiriladi (uzoq — rad etiladi); qurilma ma'lumoti saqlanadi.
- **Ma'lumot**: pasport va STIR `encrypted` cast (APP_KEY bilan); media `storage/app/private` da, faqat kirgan foydalanuvchi uchun `/admin-media/{id}` orqali; `X-Content-Type-Options: nosniff`.
- **Admin panel**: `is_admin` (to'liq) yoki `is_viewer` (faqat ko'rish) foydalanuvchi; tasdiqlash/rad etish va xodimlar bilan ishlash faqat admin; Filament login tezlik chegarasi o'rnatilgan.

### Halol cheklovlar
- **GPS** soxtalashtirilishi mumkin (Android "mock location"). Himoya: aniqlik chegarasi, masofa, jonli video, moderator tasdig'i.
- Mini App kamerasi ham ekrandagi rasmni qayta suratga olishdan to'liq himoya qilmaydi — shuning uchun buzilgan natijalar moderator tasdig'idan o'tadi.

### Production checklist
Server holati — [DEPLOY.md](DEPLOY.md). Quyidagilar egasi tomonidan tekshiriladi:
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, kuchli `APP_KEY` (zaxirasi alohida seyfda — yo'qolsa shifrlangan ustunlar ochilmaydi)
- [ ] **Bot tokenni @BotFather → /revoke orqali yangilash** (token chatda oshkor bo'lgan), faqat `.env` da saqlash
- [x] `TELEGRAM_WEBHOOK_SECRET` va `php artisan bot:webhook https://domen/telegram/webhook` (webhook rejimida ishlaydi)
- [ ] nginx: `limit_req` (IP bo'yicha), `client_max_body_size`, `/admin` ni IP yoki VPN orqali cheklash
- [x] PostgreSQL: alohida rol (faqat localhost)
- [ ] Kunlik `pg_dump` + `storage/app/private` zaxirasi
- [x] Navbat (`snos-queue` systemd xizmati) va `schedule:run` (cron har daqiqa)
- [ ] `composer audit` — Laravel 10 qo'llab-quvvatlash tugagan: Laravel 11/12 ga yangilash rejalashtirilsin (server PHP 8.4)

## 6. Texnik ishga tushirish

```bash
composer install
cp .env.example .env && php artisan key:generate
# .env: DB_*, TELEGRAM_TOKEN, ADMIN_EMAIL, ADMIN_PASSWORD, TELEGRAM_WEBHOOK_SECRET
php artisan migrate --seed
php artisan sites:import dataset.csv
php artisan nutgram:run            # lokal: polling
php artisan queue:work             # media arxivlash
php artisan schedule:work          # davomat, qayd, hisobot, eslatmalar
php artisan serve                  # admin panel: /admin
php vendor/bin/phpunit             # testlar (SQLite xotirada)
```

> **Muhim:** Filament `ext-intl` ni talab qiladi. Production va CI — PHP 8.4. Mini App sahifalari (`/tg/*`) HTTPS talab qiladi — lokal sinov uchun tunnel kerak.
> Yangilash: `main` ga push → CI testlar → avtomatik deploy ([DEPLOY.md](DEPLOY.md)).
