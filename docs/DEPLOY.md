# Production (VDS) — joriy holat

- Server: Ubuntu 24.04, `89.127.200.23`, domen **https://thudud-snos.toshkentinvest.uz** (admin: `/admin`).
- Shu serverda **boshqa tizimlar ham ishlaydi** (3dot sayti, `tutash-bot` — `bot-thudud.toshkentinvest.uz`). Ularga tegilmagan: bizniki alohida foydalanuvchi, katalog, PHP-FPM pool, nginx vhost va PostgreSQL bazasi.

| Qism | Joyi |
|---|---|
| Kod | `/srv/snos` (egasi `snos`) |
| `.env` | `/srv/snos/.env` (600). DB paroli `/root/.snos_dbpw` da ham bor |
| PHP-FPM | pool `snos`, socket `/run/php/snos.sock`, `open_basedir` cheklangan |
| nginx | `/etc/nginx/sites-available/snos`, limitlar `/etc/nginx/conf.d/snos-limits.conf` |
| Baza | PostgreSQL 16, baza/rol `snos` (faqat localhost) |
| Navbat | `systemctl status snos-queue` |
| Scheduler | `/etc/cron.d/snos` (har daqiqa `schedule:run`) |
| Bot | webhook rejimida: `POST /telegram/webhook` (maxfiy sarlavha bilan) |
| HTTPS | certbot (avtomatik yangilanadi) |

## Yangilash
```bash
# kodni /srv/snos ga yuklagandan keyin:
cd /srv/snos
sudo -u snos composer install --no-dev --optimize-autoloader
sudo -u snos php artisan migrate --force
sudo -u snos php artisan config:cache && sudo -u snos php artisan route:cache && sudo -u snos php artisan view:cache
systemctl restart snos-queue
```
Webhookni qayta o'rnatish: `sudo -u snos php artisan bot:webhook https://thudud-snos.toshkentinvest.uz/telegram/webhook`.

## Eslatmalar
- Server PHP 8.4: `openspout` v4.32 ga yangilandi (4.25 PHP 8.4 ni qo'llamaydi). Laravel 10 uchun rasmiy qo'llab-quvvatlash tugagan — Laravel 11/12 ga o'tish rejalashtirilsin.
- Parollarni (admin, bot token, DB, webhook secret) almashtirish egasi tomonidan amalga oshiriladi.
