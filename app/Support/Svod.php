<?php

namespace App\Support;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Site;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Tumanlar kesimida svod jadval: qoldiq (buzilmagan obyektlar) soni va maydoni, bugun buzilganlar va jarayondagilar.
 * Kunlik hisobotda rasm sifatida, admin panelda jadval va Excel ko'rinishida ishlatiladi.
 */
class Svod
{
    public const COLUMNS = ['№', 'Tuman nomi', 'Qoldiq soni', 'Qoldiq maydoni (ga)', 'Bugun buzilgan', 'Jarayonda'];

    /** @return array{rows: array<int, array{name: string, left: int, area: float, today: int, progress: int}>, total: array{name: string, left: int, area: float, today: int, progress: int}} */
    public static function data(?CarbonInterface $day = null): array
    {
        $period = ReportPeriod::for($day ?? ReportPeriod::current()->day);
        // qoldiq: jami hisobdagi (sud qarori bekor qilinganlarsiz) hali buzilmagan obyektlar — jarayondagilar ham qoldiqda
        $sites = Site::countable()->get(['id', 'district_id', 'status', 'area_m2', 'demolished_at']);

        if ($period->isOpen()) {
            // joriy davr: obyektlarning hozirgi holati
            $demolished = fn (Site $s) => $s->status === Site::DEMOLISHED;
            $inProgress = fn (Site $s) => $s->status === Site::IN_PROGRESS;
        } else {
            // o'tgan kun: davr oxiridagi (18:00) holat tiklanadi — buzilish vaqti tasdiqlangan natija yuborilgan payt
            $approvedAt = Inspection::where('status', Inspection::SUBMITTED)->where('is_demolished', true)
                ->where('review_status', Inspection::REVIEW_APPROVED)->whereNull('followup_of')
                ->selectRaw('site_id, min(submitted_at) as at')->groupBy('site_id')->pluck('at', 'site_id');
            $latest = Inspection::counted()->where('submitted_at', '<=', $period->to)->orderBy('submitted_at')
                ->get(['site_id', 'is_demolished', 'in_progress', 'exempt_type'])->keyBy('site_id');

            $demolished = fn (Site $s) => $s->status === Site::DEMOLISHED && (isset($approvedAt[$s->id])
                ? Carbon::parse($approvedAt[$s->id])->lessThanOrEqualTo($period->to)
                : (! $s->demolished_at || $s->demolished_at->toDateString() <= $period->to->toDateString())); // import qilingan
            $inProgress = fn (Site $s) => ($l = $latest[$s->id] ?? null) && ! $l->is_demolished && ! $l->exempt_type && $l->in_progress;
        }

        // davrda "buzilgan" deb yuborilgan obyektlar (tasdiqlangan va tasdiq kutayotgan; rad etilganlar hisobga olinmaydi)
        $districtOf = $sites->pluck('district_id', 'id');
        $today = $period->inspections()->orderBy('submitted_at')->get(['site_id', 'is_demolished'])->keyBy('site_id')
            ->where('is_demolished', true)->countBy(fn (Inspection $in) => $districtOf[$in->site_id] ?? null);

        $bySite = $sites->groupBy('district_id');
        $rows = District::query()->orderBy('name')->get(['id', 'name'])->map(function (District $d) use ($bySite, $demolished, $inProgress, $today) {
            $left = ($bySite[$d->id] ?? collect())->reject($demolished);

            return [
                'name' => $d->name,
                'left' => $left->count(),
                'area' => round((float) $left->sum('area_m2') / 10000, 2),
                'today' => (int) ($today[$d->id] ?? 0),
                'progress' => $left->filter($inProgress)->count(),
            ];
        })->values()->all();

        $total = [
            'name' => 'Toshkent shahar jami',
            'left' => array_sum(array_column($rows, 'left')),
            'area' => round(array_sum(array_column($rows, 'area')), 2),
            'today' => array_sum(array_column($rows, 'today')),
            'progress' => array_sum(array_column($rows, 'progress')),
        ];

        return ['rows' => $rows, 'total' => $total];
    }

    public static function area(float $ha): string
    {
        return number_format($ha, 2, ',', ' ');
    }

    /** Jadval rasmi (PNG). $t — matnni bot alifbosiga o'tkazish (Script::apply). */
    public static function png(CarbonInterface $day, ?callable $t = null): string
    {
        $t ??= fn (string $s) => $s;
        $data = self::data($day);
        $font = self::font(false);
        $bold = self::font(true);

        $k = 2; // aniq ko'rinishi uchun ikki baravar o'lchamda chiziladi
        $widths = array_map(fn ($w) => $w * $k, [44, 250, 96, 124, 104, 104]);
        $width = array_sum($widths) + 2 * $k;
        $titleH = 46 * $k;
        $headH = 54 * $k;
        $rowH = 34 * $k;
        $rows = count($data['rows']) + 1;
        $height = $titleH + $headH + $rows * $rowH + 2 * $k;

        $img = imagecreatetruecolor($width, $height);
        $c = fn (string $hex) => imagecolorallocate($img, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        $white = $c('#ffffff');
        $line = $c('#9e9e9e');
        $text = $c('#111111');
        $head = $c('#e2efda');
        $sum = $c('#ddebf7');
        $red = $c('#c0392b');
        imagefilledrectangle($img, 0, 0, $width, $height, $white);

        $center = function (string $s, int $x, int $y, int $w, int $h, float $size, string $f, int $color) use ($img, $k) {
            $lines = explode("\n", $s);
            $lh = (int) ($size * $k * 1.45);
            $y0 = $y + (int) (($h - $lh * count($lines)) / 2);
            foreach ($lines as $i => $ln) {
                $box = imagettfbbox($size * $k, 0, $f, $ln);
                $tw = $box[2] - $box[0];
                imagettftext($img, $size * $k, 0, $x + (int) (($w - $tw) / 2), $y0 + $lh * ($i + 1) - (int) ($lh * 0.22), $color, $f, $ln);
            }
        };
        $left = function (string $s, int $x, int $y, int $h, float $size, string $f, int $color) use ($img, $k) {
            $lh = (int) ($size * $k * 1.45); // markazlangan matn bilan bir chiziqda
            imagettftext($img, $size * $k, 0, $x + 10 * $k, $y + (int) (($h - $lh) / 2) + $lh - (int) ($lh * 0.22), $color, $f, $s);
        };

        // sarlavha
        $center($t('Tumanlar kesimida svod — '.$day->format('d.m.Y')), 0, 0, $width, $titleH, 15, $bold, $text);

        // ustun nomlari
        $y = $titleH;
        imagefilledrectangle($img, $k, $y, $width - $k, $y + $headH, $head);
        $heads = ['№', 'Tuman nomi', "Qoldiq\nsoni", "Qoldiq\nmaydoni (ga)", "Bugun\nbuzilgan", 'Jarayonda'];
        $x = $k;
        foreach ($widths as $i => $w) {
            $center($t($heads[$i]), $x, $y, $w, $headH, 11, $bold, $text);
            $x += $w;
        }

        // jami qatori (namunadagidek yuqorida), keyin tumanlar
        $all = [['n' => '', ...$data['total'], 'sum' => true]];
        foreach ($data['rows'] as $i => $r) {
            $all[] = ['n' => (string) ($i + 1), ...$r, 'sum' => false];
        }
        $y += $headH;
        foreach ($all as $r) {
            if ($r['sum']) {
                imagefilledrectangle($img, $k, $y, $width - $k, $y + $rowH, $sum);
            }
            $f = $r['sum'] ? $bold : $font;
            $cells = [$r['n'], $r['name'], (string) $r['left'], self::area($r['area']), (string) $r['today'], (string) $r['progress']];
            $x = $k;
            foreach ($widths as $i => $w) {
                $color = $i === 4 && $r['today'] > 0 ? $red : $text;
                $i === 1 && ! $r['sum']
                    ? $left($t($cells[$i]), $x, $y, $rowH, 11, $f, $text)
                    : $center($t($cells[$i]), $x, $y, $w, $rowH, 11, $r['sum'] || $i === 4 && $r['today'] > 0 ? $bold : $f, $color);
                $x += $w;
            }
            $y += $rowH;
        }

        // to'r chiziqlari
        imagesetthickness($img, $k);
        $bottom = $titleH + $headH + $rows * $rowH;
        foreach ([$titleH, ...range($titleH + $headH, $bottom, $rowH)] as $yy) {
            imageline($img, $k, $yy, $width - $k, $yy, $line);
        }
        $x = $k;
        imageline($img, $x, $titleH, $x, $bottom, $line);
        foreach ($widths as $w) {
            $x += $w;
            imageline($img, $x, $titleH, $x, $bottom, $line);
        }

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private static function font(bool $bold): string
    {
        $file = $bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf';

        return resource_path("fonts/{$file}");
    }

    /** Excel (.xlsx) fayl: tashqi kutubxonasiz, minimal SpreadsheetML (ZipArchive). */
    public static function xlsx(CarbonInterface $day): string
    {
        $data = self::data($day);
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cell = function (string $ref, $v, int $style = 0) use ($esc) {
            $s = $style ? " s=\"{$style}\"" : '';

            return is_int($v) || is_float($v)
                ? "<c r=\"{$ref}\"{$s}><v>{$v}</v></c>"
                : "<c r=\"{$ref}\" t=\"inlineStr\"{$s}><is><t>{$esc($v)}</t></is></c>";
        };
        $row = function (int $n, array $values, int $style = 0) use ($cell) {
            $cells = '';
            foreach (array_values($values) as $i => $v) {
                $cells .= $cell(chr(65 + $i).$n, $v, $style);
            }

            return "<row r=\"{$n}\">{$cells}</row>";
        };

        $xml = $row(1, ['Tumanlar kesimida svod — '.$day->format('d.m.Y')], 1)
            .$row(2, self::COLUMNS, 2);
        $t = $data['total'];
        $xml .= $row(3, ['', $t['name'], $t['left'], $t['area'], $t['today'], $t['progress']], 3);
        foreach ($data['rows'] as $i => $r) {
            $xml .= $row($i + 4, [$i + 1, $r['name'], $r['left'], $r['area'], $r['today'], $r['progress']]);
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols><col min="1" max="1" width="5" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="6" width="16" customWidth="1"/></cols>'
            ."<sheetData>{$xml}</sheetData></worksheet>";

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/></patternFill></fill></fills>'
            .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="4"><xf/><xf fontId="1" applyFont="1"/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="1" fillId="3" applyFont="1" applyFill="1"/></cellXfs>'
            .'</styleSheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'svod');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Svod" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }
}
