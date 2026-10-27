# Tutash Snos

Telegram bot (xodimlar) + Laravel/Filament admin (arxiv, dashboard). To'liq yo'riqnoma: [docs/ROLLOUT.md](docs/ROLLOUT.md).

```bash
composer install
php artisan key:generate
# .env: DB_*, TELEGRAM_TOKEN, ADMIN_EMAIL, ADMIN_PASSWORD
php artisan migrate --seed && php artisan sites:import dataset.csv
php artisan nutgram:run   # bot (polling)
php artisan queue:work    # media arxivlash
php artisan serve         # /admin
php vendor/bin/phpunit    # testlar (hech qachon DB_* env eksport qilingan shell'da emas)
```

Talab: PHP 8.1+ (`intl`, `pdo_pgsql`, `mbstring`, `curl`, `gd`, `sodium`), PostgreSQL.
