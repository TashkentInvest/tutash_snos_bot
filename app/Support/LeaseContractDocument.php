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
        $xml = self::annex($zip, $xml, $c); // 1-ilova: sxema, 2-ilova: fotosuratlar
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $disk->put($docx, file_get_contents($tmp));
        @unlink($tmp);

        $pdf = self::toPdf($disk->path($docx));

        return [$docx, $pdf ? "{$dir}/".basename($pdf) : null];
    }

    /** Yangi alifbo, lekin namunadagi kabi «ko‘çasi» (o‘ saqlanadi). */
    public static function script(?string $s): string
    {
        return preg_replace(['/köç/u', '/Köç/u'], ['ko‘ç', 'Ko‘ç'], Script::toNewAlphabet(trim((string) $s)));
    }

    /** {{kalit}} => qiymat (yangi alifboda, XML uchun xavfsiz). */
    public static function values(LeaseContract $c): array
    {
        $t = fn (?string $s) => self::script($s);
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
            'area' => self::area($c->area_m2),
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

    /**
     * Shartnoma oxiriga ilovalar: 1-ilova — tutash hudud sxemasi (poligon, maydoni, burchak koordinatalari),
     * 2-ilova — joyida olingan fotosuratlar. Rasmlar word/media ga qo'shiladi, bog'lanishlar va turlar ro'yxatga yoziladi.
     */
    public static function annex(\ZipArchive $zip, string $xml, LeaseContract $c): string
    {
        $disk = Storage::disk('local');
        $t = fn (string $s) => htmlspecialchars(self::script($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rels = $zip->getFromName('word/_rels/document.xml.rels');
        $types = $zip->getFromName('[Content_Types].xml');
        $add = [];
        $n = 0;
        $image = function (string $bytes, string $ext, float $widthCm) use ($zip, &$rels, &$n): string {
            $n++;
            $name = "lease_{$n}.{$ext}";
            $zip->addFromString("word/media/{$name}", $bytes);
            $rid = "rIdLease{$n}";
            $rels = str_replace('</Relationships>', '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$name.'"/></Relationships>', $rels);
            $size = getimagesizefromstring($bytes) ?: [4, 3];
            $cx = (int) ($widthCm * 360000);
            $cy = (int) ($cx * $size[1] / max($size[0], 1));
            $id = 9000 + $n;

            return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$cx.'" cy="'.$cy.'"/>'
                .'<wp:docPr id="'.$id.'" name="Rasm '.$id.'"/><wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
                .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="'.$id.'" name="'.$name.'"/><pic:cNvPicPr/></pic:nvPicPr>'
                .'<pic:blipFill><a:blip r:embed="'.$rid.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
                .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
        };
        $p = fn (string $runs, string $jc = 'center') => '<w:p><w:pPr><w:jc w:val="'.$jc.'"/></w:pPr>'.$runs.'</w:p>';
        $text = fn (string $s, bool $b = false, int $size = 24) => '<w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/>'.($b ? '<w:b/>' : '').'<w:sz w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'.$s.'</w:t></w:r>';
        $break = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

        if (count($c->polygon ?? []) >= 3) {
            $add[] = $break;
            $add[] = $p($text($t('1-ilova'), true, 26), 'right');
            $add[] = $p($text($t('Tutash hudud sxemasi'), true, 28));
            $add[] = $p($text($t('Toshkent shahri, '.$c->district?->name.', '.$c->mfy.(preg_match('/MFY$/u', trim($c->mfy)) ? '' : ' MFY').', '.$c->street?->name.(filled($c->house) ? ', '.$c->house.'-uy' : ''))));
            $add[] = $p($image(LeaseScheme::png($c), 'png', 16.5));
            $add[] = $p($text($t('Tutash hudud maydoni (sxema bo‘yicha): '.number_format((float) $c->polygon_area_m2, 1, ',', ' ').' kv.m; shartnomada: '.self::area($c->area_m2).' kv.m'), false, 22), 'left');
            $coords = collect($c->polygon)->map(fn ($v, $i) => ($i + 1).') '.number_format($v[0], 6, '.', '').', '.number_format($v[1], 6, '.', ''))->join(';  ');
            $add[] = $p($text($t('Burchak nuqtalari (kenglik, uzunlik): ').$coords, false, 20), 'left');
        }
        $photos = array_values(array_filter($c->photos ?? [], fn ($f) => $disk->exists($f)));
        if ($photos) {
            $add[] = $break;
            $add[] = $p($text($t('2-ilova'), true, 26), 'right');
            $add[] = $p($text($t('Tutash hudud fotosuratlari (joyida olingan, '.$c->created_at?->format('d.m.Y H:i').')'), true, 26));
            foreach (array_chunk($photos, 2) as $pair) {
                $add[] = $p(implode($text('  '), array_map(fn ($f) => $image($disk->get($f), strtolower(pathinfo($f, PATHINFO_EXTENSION)) ?: 'jpg', 7.8), $pair)));
            }
        }
        if (! $add) {
            return $xml;
        }
        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'] as $ext => $mime) {
            str_contains($types, 'Extension="'.$ext.'"') || $types = str_replace('</Types>', '<Default Extension="'.$ext.'" ContentType="'.$mime.'"/></Types>', $types);
        }
        $zip->addFromString('word/_rels/document.xml.rels', $rels);
        $zip->addFromString('[Content_Types].xml', $types);
        $pos = strrpos($xml, '<w:sectPr'); // hujjat oxiridagi bo'lim sozlamalaridan oldin

        return substr($xml, 0, $pos).implode('', $add).substr($xml, $pos);
    }

    public static function area(float $m2): string
    {
        return fmod($m2, 1.0) == 0.0 ? (string) (int) $m2 : number_format($m2, 2, ',', '');
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
