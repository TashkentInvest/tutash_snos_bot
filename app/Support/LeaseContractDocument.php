<?php

namespace App\Support;

use App\Models\LeaseContract;
use App\Telegram\Support\Script;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Ijara shartnomasi fayli: resources/contracts/lease-{legal|individual}.docx shablonidagi {{kalit}} larni to'ldiradi
 * (namunadagi sariq joylar), to'lov jadvali oylarini kerakli qatorlarga yoyadi; LibreOffice bo'lsa PDF ham yasaydi.
 * Shablonlarni namunadan qayta yasash: tools/contracts/make_templates.py.
 */
class LeaseContractDocument
{
    public const MONTHS = [1 => 'yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentyabr', 'oktyabr', 'noyabr', 'dekabr'];

    /** Faylni yasaydi va contracts/{id}/ ga saqlaydi; [docx, pdf|null] yo'llari (local disk). */
    public static function render(LeaseContract $c): array
    {
        $tpl = resource_path('contracts/lease-'.($c->party === LeaseContract::LEGAL ? 'legal' : 'individual').'.docx');
        $disk = Storage::disk('local');
        $dir = "contracts/{$c->id}";
        $disk->makeDirectory($dir);
        $docx = "{$dir}/shartnoma-{$c->id}.docx";

        $zip = new \ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'lease');
        copy($tpl, $tmp);
        if ($zip->open($tmp) !== true) {
            throw new \RuntimeException('Shablon ochilmadi: '.$tpl);
        }
        $xml = self::fill($zip->getFromName('word/document.xml'), self::values($c), $c->schedule());
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $disk->put($docx, file_get_contents($tmp));
        @unlink($tmp);

        $pdf = self::toPdf($disk->path($docx));

        return [$docx, $pdf ? "{$dir}/".basename($pdf) : null];
    }

    /** {{kalit}} => qiymat (yangi alifboda, XML uchun xavfsiz). */
    public static function values(LeaseContract $c): array
    {
        // namunadagi kabi «ko‘çasi» (o‘ saqlanadi), qolgan joylarda yangi alifbo
        $t = fn (?string $s) => preg_replace(['/köç/u', '/Köç/u'], ['ko‘ç', 'Ko‘ç'], Script::toNewAlphabet(trim((string) $s)));
        $blank = fn (?string $s, string $line = '_____________') => filled($s) ? $t($s) : $line;
        $legal = $c->party === LeaseContract::LEGAL;
        $street = (string) $c->street?->name;
        $streetFull = preg_match('/ko[‘\'’`]?cha|yo[‘\'’`]?li|shoh/ui', $street) ? $street : $street.' ko‘chasi';
        $mfy = preg_match('/MFY$/u', trim($c->mfy)) ? trim($c->mfy) : trim($c->mfy).' MFY';
        $date = fn ($d) => $d->year.' yil “'.$d->format('d').'” '.self::MONTHS[$d->month];

        return [
            'tenant_name' => $legal ? $t($c->tenant_name) : mb_strtoupper($t($c->tenant_name)),
            'tenant_address' => $blank($c->tenant_address, '___________________________________'),
            'district' => $t($c->district?->name),
            'mfy' => $t($mfy),
            'street_house' => $t($streetFull.(filled($c->house) ? ' '.trim($c->house).'-uy' : '')),
            'application' => $c->application_date && $c->application_number
                ? $c->application_date->year.' yil '.$c->application_date->day.' '.self::MONTHS[$c->application_date->month].' da kelib tuşgan '.$t($c->application_number).'-son'
                : '202__ yil ____ da kelib tuşgan ___-son',
            'cadastre' => $c->cadastre,
            'area' => fmod($c->area_m2, 1.0) == 0.0 ? (string) (int) $c->area_m2 : number_format($c->area_m2, 2, ',', ''),
            'activity' => $t($c->activity),
            'period' => $date($c->start_date).' dan '.$date($c->end_date),
            'amount' => self::money($c->annual_amount),
            'account' => $blank($c->account),
            'inn' => $blank($c->inn),
            'mfo' => $blank($c->mfo),
            'phone' => $blank($c->phone),
            'passport' => $blank($c->passport),
            'passport_issuer' => $blank($c->passport_issuer),
            'passport_year' => $blank($c->passport_year, '_________'),
            'jshshir' => $blank($c->jshshir),
            'signature' => $t(self::initials($legal ? (string) $c->director : $c->tenant_name)),
        ];
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    /** «Yermatov Vali Rustamovich» → «V.R.Yermatov» (familiya birinchi yoziladi). */
    public static function initials(string $full): string
    {
        $p = preg_split('/\s+/u', trim($full)) ?: [];
        if (count($p) < 2) {
            return trim($full);
        }
        $last = mb_convert_case(mb_strtolower($p[0]), MB_CASE_TITLE);

        return collect(array_slice($p, 1, 2))->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).'.')->join('').$last;
    }

    /** Shablonni to'ldirish: kalitlar + oylar jadvali (har qatorda 4 oy: nomi va summasi). */
    public static function fill(string $xml, array $values, array $schedule): string
    {
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // oylar jadvali: {{m1}} li qator + keyingi {{v1}} li qator — oylar soniga qarab ko'paytiriladi
        // (hujjat katta — regex o'rniga satr qidiruvi: PCRE JIT steki yetmaydi)
        $mRow = self::element($xml, '{{m1}}', 'w:tr');
        $vRow = self::element($xml, '{{v1}}', 'w:tr');
        if ($mRow && $vRow) {
            $rows = '';
            foreach (array_chunk($schedule, 4) as $chunk) {
                $m = $mRow[2];
                $v = $vRow[2];
                for ($k = 1; $k <= 4; $k++) {
                    if (isset($chunk[$k - 1])) {
                        [$month, $sum] = $chunk[$k - 1];
                        $m = str_replace('{{m'.$k.'}}', $esc(mb_convert_case(self::MONTHS[$month], MB_CASE_TITLE)), $m);
                        $v = str_replace('{{v'.$k.'}}', $esc(self::money($sum)), $v);
                    } else { // qisqa oxirgi qator — ortiqcha kataklar olib tashlanadi (namunadagi kabi)
                        $m = self::without($m, '{{m'.$k.'}}');
                        $v = self::without($v, '{{v'.$k.'}}');
                    }
                }
                $rows .= $m.$v;
            }
            $xml = substr($xml, 0, $mRow[0]).$rows.substr($xml, $vRow[1]);
        }

        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => $esc($values[$m[1]] ?? ''), $xml);
    }

    /** $needle turgan katakni (<w:tc>) qatordan olib tashlaydi. */
    private static function without(string $row, string $needle): string
    {
        $cell = self::element($row, $needle, 'w:tc');

        return $cell ? substr($row, 0, $cell[0]).substr($row, $cell[1]) : $row;
    }

    /** $needle turgan eng ichki <$tag ...>…</$tag> elementi: [boshi, oxiri, xml] (topilmasa null). */
    private static function element(string $xml, string $needle, string $tag): ?array
    {
        $pos = strpos($xml, $needle);
        if ($pos === false) {
            return null;
        }
        $a = max((int) strrpos(substr($xml, 0, $pos), "<{$tag} "), (int) strrpos(substr($xml, 0, $pos), "<{$tag}>"));
        $b = strpos($xml, "</{$tag}>", $pos);
        if ($b === false) {
            return null;
        }
        $b += strlen("</{$tag}>");

        return [$a, $b, substr($xml, $a, $b - $a)];
    }

    /** LibreOffice (soffice) o'rnatilgan bo'lsa — PDF; bo'lmasa null (Word fayl baribir tayyor). */
    public static function toPdf(string $docxPath): ?string
    {
        $bin = config('tutash.soffice_bin');
        if (! $bin || ! is_executable($bin)) {
            return null;
        }
        $home = sys_get_temp_dir().'/lo-profile';
        @mkdir($home, 0775, true);
        $p = new Process([$bin, '--headless', '--convert-to', 'pdf', '--outdir', dirname($docxPath), $docxPath], null, ['HOME' => $home]);
        $p->setTimeout(90);
        $p->run();
        $pdf = preg_replace('/\.docx$/', '.pdf', $docxPath);

        return $p->isSuccessful() && is_file($pdf) ? $pdf : null;
    }
}
