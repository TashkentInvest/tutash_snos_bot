<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Mahalla;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSites extends Command
{
    protected $signature = 'sites:import {file=dataset.csv : CSV fayl yo\'li (; ajratgich)}';

    protected $description = 'dataset.csv dan obyektlarni (tutashlar) bazaga yuklaydi. Qayta ishga tushirsa ham dublikat yaratmaydi.';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));
        if (! is_file($path)) {
            $path = $this->argument('file');
        }
        if (! is_file($path) || ($fh = fopen($path, 'r')) === false) {
            $this->error('Fayl topilmadi.');

            return self::FAILURE;
        }

        $districts = District::all()->keyBy(fn ($d) => $this->key($d->name));
        $created = $updated = $skipped = 0;
        $seen = [];

        fgetcsv($fh, 0, ';'); // sarlavha

        DB::transaction(function () use ($fh, $districts, &$created, &$updated, &$skipped, &$seen) {
            while (($r = fgetcsv($fh, 0, ';')) !== false) {
                $r = array_pad(array_map('trim', $r), 15, '');
                [$no, $tuman, $mahalla, $manzil, $loc, $fio, $passport, $inn, $area, $tutash, $courtDate, $courtNo, $courtStatus, $state, $stateDate] = $r;

                if ($tuman === '') {
                    $skipped++;
                    continue;
                }

                $district = $districts[$this->key($tuman)] ?? null;
                if (! $district) {
                    $this->warn("Noma'lum tuman: {$tuman} (qator {$no})");
                    $skipped++;
                    continue;
                }

                $mahallaName = $this->clean($mahalla) ?: 'Noma\'lum MFY';
                $mahallaModel = Mahalla::firstOrCreate(['district_id' => $district->id, 'name' => $mahallaName]);

                [$lng, $lat] = $this->coords($loc);
                $sourceNo = ctype_digit($no) ? (int) $no : null;
                $dupKey = $district->id.':'.$sourceNo;
                if ($sourceNo !== null && isset($seen[$dupKey])) {
                    $this->warn("Takroriy raqam {$no} ({$tuman}) — yangi raqam berilmadi, o'tkazib yuborildi");
                    $skipped++;
                    continue;
                }
                $seen[$dupKey] = true;

                $demolished = mb_stripos($state, 'бузилган') === 0; // "Бузилмаган" bu yerda mos kelmaydi
                $attrs = [
                    'mahalla_id' => $mahallaModel->id,
                    'address' => $this->clean($manzil) ?: '—',
                    'lat' => $lat,
                    'lng' => $lng,
                    'violator_name' => $this->clean(trim($fio, " ,\"")) ?: null,
                    'violator_passport' => $passport ?: null,
                    'org_inn' => $inn ?: null,
                    'area_m2' => $this->area($area),
                    'is_tutash' => $tutash !== '0',
                    'court_date' => $this->date($courtDate),
                    'court_number' => $courtNo ?: null,
                    'court_status' => $courtStatus ?: null,
                ];

                $site = $sourceNo !== null ? Site::where(['district_id' => $district->id, 'source_no' => $sourceNo])->first() : null;
                if ($site) {
                    $site->update($attrs); // holat (status) tekshiruvlar bilan boshqariladi, qayta import uni ustiga yozmaydi
                    $updated++;
                } else {
                    Site::create($attrs + [
                        'district_id' => $district->id,
                        'source_no' => $sourceNo,
                        'status' => $demolished ? Site::DEMOLISHED : Site::NOT_DEMOLISHED,
                        'demolished_at' => $demolished ? $this->date($stateDate) : null,
                    ]);
                    $created++;
                }
            }
        });

        fclose($fh);
        $this->info("Yaratildi: {$created}, yangilandi: {$updated}, o'tkazildi: {$skipped}");

        return self::SUCCESS;
    }

    private function area(string $s): ?float
    {
        $v = $s !== '' ? (float) str_replace([' ', ','], ['', '.'], $s) : null;

        return ($v !== null && $v >= 0 && $v < 1e9) ? $v : null;
    }

    private function key(string $s): string
    {
        $s = str_replace(['ʻ', 'ʼ', '’', '`', '‘'], "'", $s);

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** "69.2476, 41.2930" -> [lng, lat] (Google Maps teskari tartibda: lng, lat). */
    private function coords(string $s): array
    {
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)/', $s, $m)) {
            [$a, $b] = [(float) $m[1], (float) $m[2]];
            // Toshkent: lng ~69, lat ~41 — tartib qanday bo'lmasin to'g'rilab olamiz.
            [$lng, $lat] = $a > $b ? [$a, $b] : [$b, $a];

            // Noto'g'ri kiritilgan koordinatalar (masalan 4129308...) bazani buzmasligi uchun tashlab yuboriladi.
            return ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) ? [$lng, $lat] : [null, null];
        }

        return [null, null];
    }

    private function date(string $s): ?string
    {
        if (! preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $s)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d.m.Y', $s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
