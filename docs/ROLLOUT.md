# Tutash Snos — joriy etish yo'riqnomasi

Ichki nazorat tizimi: **Telegram bot** (xodimlar uchun) + **Laravel/Filament** (arxiv va dashboard).
Manba ma'lumot: `dataset.csv` (849 obyekt, 12 tuman).

## 1. Tizim qanday ishlaydi

1. Xodim `/start` → F.I.SH yozadi → **o'z** telefon raqamini tugma orqali yuboradi.
   Raqam tumanlar jadvali (`DistrictSeeder`) bilan mos kelsa, shu tuman(lar)ga biriktiriladi.
   Bir raqam bir nechta tumanga tegishli bo'lishi mumkin (Yunusobod+Yashnobod, Bektemir+Yangihayot, Sergeli+Shayxontohur).
2. Keyingi kirishlarda `/start` → o'z tumanlari → **MFY** → obyekt. Ko'chalar ko'p bo'lgani uchun navigatsiya:
   - MFY ro'yxati (buzilmagani ko'pi tepada, sahifalangan),
   - MFY ichida faqat **buzilmagan** obyektlar (xohlasa "hammasi"),
   - 🔍 matn bo'yicha qidiruv, 📍 "yaqinimdagilar" (GPS bo'yicha eng yaqin 8 ta).
3. Tekshiruv: joylashuv → avvalgi holat rasmi → (ixtiyoriy) dumaloq video → "buzildimi?"
   - **Yo'q**: sabab (matn, ≥10 belgi).
   - **Ha**: keyingi holat rasmi + **majburiy dumaloq video**.
4. Yuborilgach obyekt holati yangilanadi, barcha media serverga arxivlanadi (queue), Filament'da ko'rinadi.

## 1a. Rollar va davomat (qo‘shimcha)

- `/start` da yangi foydalanuvchi **Xodim** yoki **Aholi** sifatida kirishni tanlaydi (bir marta; qayta tanlash — `/rol`, faqat tasdiqlanmaganlar uchun).
- **Aholi**: barcha tumanlar, MFY va obyektlar ro‘yxati (`jami/buzilgan`), huquqbuzar, sud qarori, holat va tuman mas’uli telefoni. Pasport, maydon, tekshiruv va GPS yo‘q.
- **Xodim**: telefon bo‘yicha tasdiqlanadi, pasport to‘liq ko‘rinadi, tekshiruv va "yaqinimdagilar" ishlaydi.
- **Davomat**: ish kunlari (du–ju) 08:45 da faol xodimlarga so‘rov, 09:30 gacha javob. *Ha* → joylashuv (faqat tugma) → dumaloq video (≥3 s, majburiy); *Yo‘q* → sabab matni. Javob bermaganlar 09:30 da "javob bermadi" bo‘ladi. Barcha tumanlarni ko‘radigan (admin) raqamlarda davomat yuritilmaydi (Xodimlar → "Kunlik davomat yuritiladi").
- Sozlamalar: `config/tutash.php` → `attendance` (`ATTENDANCE_SEND_AT`, `ATTENDANCE_DEADLINE`), bayram kunlari hozircha qo‘lda (`attendance:send` ni o‘tkazib yuborish uchun jadvaldan kunni chiqarish kerak).
- Imlo: `BOT_ALPHABET=classic` (o‘, g‘, sh, ch) yoki `new` (ö, ğ, ş, ç) — `.env` da o‘zgartirib, `php artisan config:cache`.

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
- **Tekshiruv o'zgarmas arxiv**: yuborilgan tekshiruv bot orqali o'zgartirilmaydi; admin faqat ko'radi. Holatni tuzatish — obyekt kartochkasida (admin o'zgarishlari uchun audit log — keyingi bosqich).
- **Ikki kalit (tavsiya)**: "buzilgan" natijalarni admin 48 soat ichida ko'rib chiqadi (Filament → Tekshiruvlar → filtr: *Shubhali*). Shubhali bo'lsa obyekt qayta tekshiruvga qaytariladi.
- KPI: tumanlar bo'yicha % buzilgan; tekshiruv/xodim/hafta; shubhali ulushi; o'rtacha "tekshiruvdan arxivgacha" vaqt; tekshirilmagan obyektlar soni (>30 kun).

## 4. Joriy etishdan oldin javob kerak bo'lgan savollar

**Jarayon**
1. "Buzilmagan" obyekt qancha vaqtda qayta tekshiriladi (har 7/14/30 kun)? Eslatma yuborilsinmi?
2. Natijani kim tasdiqlaydi — faqat admin, yoki tuman rahbari ham? Rollar: *admin*, *tuman rahbari (faqat o'z tumani)*, *respublika kuzatuvchisi (faqat o'qish)*?
3. Buzilgan obyekt noto'g'ri belgilansa, kim va qanday qaytaradi?
4. Xodim almashsa/telefon raqami o'zgarsa — jarayon qanday (jadvaldagi raqamni kim yangilaydi)?
5. Tuman ichida bir nechta xodim bo'lsa, bir obyektni ikki kishi parallel tekshirsa nima bo'ladi (hozir: ikkalasi ham saqlanadi, oxirgisi holatni belgilaydi)?
6. Internet yo'q hududlarda xodim nima qiladi (hozir: oflayn rejim yo'q)?

**Ma'lumot va huquqiy**
7. Pasport/STIR kabi shaxsiy ma'lumotlar bazada **shifrlangan** saqlanadi, botda niqoblanadi. Ularni umuman botga chiqarish kerakmi? Saqlash muddati va "Shaxsga doir ma'lumotlar to'g'risida"gi qonun bo'yicha asos kim tomonidan tasdiqlanadi?
8. Foto/video qancha vaqt saqlanadi (1 yil? doimiy?) va server qayerda (mamlakat ichida bo'lishi shartmi)?
9. Telegram xodimlarga rasman ruxsat etilganmi (ichki siyosat)? Aks holda muqobil (Mini App/ichki ilova) kerak.

**Texnik**
10. Server/domen kim beradi, HTTPS sertifikat, zaxira (backup) siyosati?
11. Bot tili: hozir lotin. Kirill (screenshotdagidek) kerakmi?
12. Dataset'da `Sud holati`, `Buzilgan sana` kabi ustunlar to'liq emas — ma'lumot egasi kim?
13. Obyektlarning ~(koordinatasi yo'q) qismi uchun masofa tekshiruvi ishlamaydi — to'ldiriladimi?
14. Masofa chegarasi: hozir 300 m, rejim "belgilash" (rad etmaydi). Qat'iy rad etish (`INSPECTION_STRICT_DISTANCE=true`) yoqilsinmi?

## 5. Xavfsizlik

### Amalga oshirilgan
- **Autentifikatsiya**: ro'yxatdan o'tish faqat Telegram `contact` (egasining o'z raqami; `contact.user_id == from.id`, forward qilinmagan) + ruxsat jadvali bilan mos kelish. 3 ta noto'g'ri urinishdan keyin bloklanadi, hammasi `audit_logs` ga yoziladi.
- **Avtorizatsiya**: har bir callback/xabarda xodimning tumani tekshiriladi (IDOR yo'q) — boshqa tuman obyektining `id`'sini qo'lda yuborish ishlamaydi (testda tekshirilgan).
- **Suiiste'mol va DDoS**:
  - foydalanuvchi bo'yicha tezlik chegarasi (daqiqasiga 40 update),
  - foydalanuvchi bo'yicha qulf (`Cache::lock`) — bir vaqtda bitta update (qo'sh bosish poygasi yo'q),
  - jarayon holati bazada (step), "Yuborish" tugmasi tranzaksiya + `lockForUpdate` ostida — 10 marta bossa ham bitta yozuv,
  - bir xodimda faqat bitta qoralama (partial unique index), eski qoralamalar soatlik tozalanadi,
  - webhook faqat maxfiy sarlavha (`X-Telegram-Bot-Api-Secret-Token`) bilan; `hash_equals`.
- **Soxtalashtirishga qarshi**: forward qilingan rasm/video/joylashuv rad etiladi; faqat dumaloq video (Telegram uni galereyadan yuklashga ruxsat bermaydi, faqat jonli yozish); video ≥3 soniya; bir xil fayl (`file_unique_id`) ikki marta ishlatib bo'lmaydi (global unique); xodim–obyekt masofasi hisoblanadi va uzoq bo'lsa "shubhali" deb belgilanadi.
- **Ma'lumot**: pasport va STIR `encrypted` cast (APP_KEY bilan); media `storage/app/private` da, faqat kirgan admin uchun `/admin-media/{id}` orqali; `X-Content-Type-Options: nosniff`.
- **Admin panel**: faqat `is_admin` foydalanuvchi; Filament login tezlik chegarasi o'rnatilgan.

### Halol cheklovlar
- **Oddiy rasm** Telegram'da galereyadan ham yuborilishi mumkin — botda buni to'liq to'sib bo'lmaydi. Himoya: forward taqiqi, takroriy fayl taqiqi, majburiy jonli dumaloq video, GPS masofa.
- **GPS** soxtalashtirilishi mumkin (Android "mock location"). Shuning uchun natija "belgilanadi", admin ko'rib chiqadi.
- Eng qat'iy yechim — **Telegram Mini App** (to'g'ridan-to'g'ri kamera + GPS + qurilma imzosi). Pilotdan keyin kerak bo'lsa 2-bosqichda qilinadi.

### Production checklist
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, kuchli `APP_KEY` (zaxirasi alohida seyfda — yo'qolsa shifrlangan ustunlar ochilmaydi)
- [ ] **Bot tokenni @BotFather → /revoke orqali yangilash** (token chatda oshkor bo'lgan), faqat `.env` da saqlash
- [ ] `TELEGRAM_WEBHOOK_SECRET` (≥32 belgi) va `php artisan bot:webhook https://domen/telegram/webhook`
- [ ] nginx: `limit_req` (IP bo'yicha), `client_max_body_size 5m`, `/admin` ni IP yoki VPN orqali cheklash, Cloudflare ortida bo'lsa — faqat Cloudflare IP'laridan qabul qilish
- [ ] PostgreSQL: alohida foydalanuvchi (superuser emas), kunlik `pg_dump` + `storage/app/private` zaxirasi
- [ ] Supervisor: `queue:work --tries=5`, `schedule:run` (cron har daqiqa)
- [ ] `composer audit` — ayni paytda Laravel 10 bo'yicha advisory bor: PHP 8.2+ ga o'tib Laravel 11/12 ga yangilash rejalashtirilsin

## 6. Texnik ishga tushirish

```bash
composer install
cp .env.example .env && php artisan key:generate
# .env: DB_*, TELEGRAM_TOKEN, ADMIN_EMAIL, ADMIN_PASSWORD, TELEGRAM_WEBHOOK_SECRET
php artisan migrate --seed
php artisan sites:import dataset.csv
php artisan nutgram:run            # lokal: polling
php artisan queue:work             # media arxivlash
php artisan serve                  # admin panel: /admin
```

> **Muhim:** Filament `ext-intl` ni talab qiladi. OSPanel'dagi PHP 8.1 da `php_intl.dll` yo'q — PHP 8.2+/8.3 modulini o'rnating.
