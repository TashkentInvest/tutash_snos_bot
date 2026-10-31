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
    public const COLUMNS = ['№', 'Tuman nomi', 'Qoldiq soni', 'Qoldiq maydoni (ga)', 'Bugun buzilgan', 'Sud qarori bekor', 'Jarayonda'];

    /** @return array{rows: array<int, array{name: string, all: int, left: int, area: float, today: int, progress: int, court: int}>, total: array{name: string, all: int, left: int, area: float, today: int, progress: int, court: int}} */
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

        // sud qarori bekor qilingani tasdiqlangan (jami sondan chiqarilgan) obyektlar; o'tgan kun uchun — davr oxirigacha tasdiqlanganlari
        $courtAt = Inspection::where('status', Inspection::SUBMITTED)->where('exempt_type', Inspection::EXEMPT_COURT)
            ->where('review_status', Inspection::REVIEW_APPROVED)->whereNull('followup_of')
            ->selectRaw('site_id, min(submitted_at) as at')->groupBy('site_id')->pluck('at', 'site_id');
        $court = Site::where('status', Site::EXEMPT)->get(['id', 'district_id'])
            ->filter(fn (Site $s) => $period->isOpen() || ! isset($courtAt[$s->id]) || Carbon::parse($courtAt[$s->id])->lessThanOrEqualTo($period->to))
            ->countBy('district_id');

        // davrda "buzilgan" deb yuborilgan obyektlar (tasdiqlangan va tasdiq kutayotgan; rad etilganlar hisobga olinmaydi)
        $districtOf = $sites->pluck('district_id', 'id');
        $today = $period->inspections()->orderBy('submitted_at')->get(['site_id', 'is_demolished'])->keyBy('site_id')
            ->where('is_demolished', true)->countBy(fn (Inspection $in) => $districtOf[$in->site_id] ?? null);

        $bySite = $sites->groupBy('district_id');
        $rows = District::query()->orderBy('name')->get(['id', 'name'])->map(function (District $d) use ($bySite, $demolished, $inProgress, $today, $court) {
            $left = ($bySite[$d->id] ?? collect())->reject($demolished);

            return [
                'name' => $d->name,
                'all' => ($bySite[$d->id] ?? collect())->count(), // jami (sud qarori bekor qilinganlarsiz)
                'left' => $left->count(),
                'area' => round((float) $left->sum('area_m2') / 10000, 2),
                'today' => (int) ($today[$d->id] ?? 0),
                'progress' => $left->filter($inProgress)->count(),
                'court' => (int) ($court[$d->id] ?? 0),
            ];
        })->values()->all();

        $total = [
            'name' => 'Toshkent shahar jami',
            'all' => array_sum(array_column($rows, 'all')),
            'left' => array_sum(array_column($rows, 'left')),
            'area' => round(array_sum(array_column($rows, 'area')), 2),
            'today' => array_sum(array_column($rows, 'today')),
            'progress' => array_sum(array_column($rows, 'progress')),
            'court' => array_sum(array_column($rows, 'court')),
        ];

        return ['rows' => $rows, 'total' => $total];
    }

    public static function area(float $ha): string
    {
        return number_format($ha, 2, ',', ' ');
    }

    /** Svod jadval rasmi (PNG). $t — matnni bot alifbosiga o'tkazish (Script::apply). */
    public static function png(CarbonInterface $day, ?callable $t = null): string
    {
        $data = self::data($day);
        $rows = [];
        foreach ([$data['total'], ...$data['rows']] as $n => $r) { // 0 — jami qatori
            $rows[] = [
                // ish yopilganlar yonma-yon: bugun buzilgan, sud qarori bekor, so'ng jarayonda
                'cells' => [$n ? (string) $n : '', $r['name'], (string) $r['left'], self::area($r['area']), (string) $r['today'], (string) $r['court'], (string) $r['progress']],
                'sum' => $n === 0,
                'colors' => $r['today'] > 0 ? [4 => 'red'] : [],
            ];
        }

        return self::table(
            'Tumanlar kesimida svod — '.$day->format('d.m.Y'),
            ['№', 'Tuman nomi', "Qoldiq\nsoni", "Qoldiq\nmaydoni (ga)", "Bugun\nbuzilgan", "Sud qarori\nbekor", 'Jarayonda'],
            [44, 250, 96, 124, 104, 104, 104], $rows, $t);
    }

    /**
     * Bajarilish ko'rsatkichlari: sud qarori bekor qilinganlar ham ish yopilgan (bajarilgan) hisoblanadi.
     *
     * @return array{all: int, demolished: int, court: int, left: int, done: int, pct: float}
     */
    public static function progress(array $r): array
    {
        $all = $r['all'] + $r['court']; // jami: hisobdagi + sud qarori bekor qilinganlar
        $done = $all - $r['left'];

        return ['all' => $all, 'demolished' => $r['all'] - $r['left'], 'court' => $r['court'], 'left' => $r['left'], 'done' => $done,
            'pct' => $all ? round($done / $all * 100, 1) : 0.0];
    }

    public static function pct(float $pct): string
    {
        return str_replace('.', ',', (string) $pct).'%';
    }

    /** Tumanlar kesimida bajarilish rasmi (PNG): jami, buzilgan, sud qarori bekor, qolgan, bajarilish foizi. */
    public static function progressPng(CarbonInterface $day, ?callable $t = null): string
    {
        $data = self::data($day);
        $rows = [];
        foreach ([$data['total'], ...$data['rows']] as $n => $r) { // 0 — jami qatori
            $p = self::progress($r);
            $rows[] = [
                'cells' => [$n ? (string) $n : '', $r['name'], (string) $p['all'], (string) $p['demolished'], (string) $p['court'], (string) $p['left'], self::pct($p['pct'])],
                'sum' => $n === 0,
                'colors' => [3 => 'green', 4 => 'green', 5 => 'orange'],
            ];
        }

        return self::table(
            'Tumanlar kesimida bajarilish — '.$day->format('d.m.Y'),
            ['№', 'Tuman nomi', 'Jami', 'Buzilgan', "Sud qarori\nbekor", 'Qolgan', 'Bajarilish'],
            [44, 250, 96, 104, 104, 96, 112], $rows, $t);
    }

    /**
     * Jadval chizuvchi (rasmiy uslub: yashil sarlavha, ko'k jami qatori, kulrang to'r).
     *
     * @param  array<int, array{cells: string[], sum: bool, colors: array<int, string>}>  $rows
     */
    private static function table(string $title, array $heads, array $widths, array $rows, ?callable $t): string
    {
        $t ??= fn (string $s) => $s;
        $font = self::font(false);
        $bold = self::font(true);

        $k = 2; // aniq ko'rinishi uchun ikki baravar o'lchamda chiziladi
        $widths = array_map(fn ($w) => $w * $k, $widths);
        $width = array_sum($widths) + 2 * $k;
        $titleH = 46 * $k;
        $headH = 54 * $k;
        $rowH = 34 * $k;
        $height = $titleH + $headH + count($rows) * $rowH + 2 * $k;

        $img = imagecreatetruecolor($width, $height);
        $c = fn (string $hex) => imagecolorallocate($img, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        $white = $c('#ffffff');
        $line = $c('#9e9e9e');
        $text = $c('#111111');
        $head = $c('#e2efda');
        $sum = $c('#ddebf7');
        $palette = ['red' => $c('#c0392b'), 'green' => $c('#1e8449'), 'orange' => $c('#b9770e')];
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
        $center($t($title), 0, 0, $width, $titleH, 15, $bold, $text);

        // ustun nomlari
        $y = $titleH;
        imagefilledrectangle($img, $k, $y, $width - $k, $y + $headH, $head);
        $x = $k;
        foreach ($widths as $i => $w) {
            $center($t($heads[$i]), $x, $y, $w, $headH, 11, $bold, $text);
            $x += $w;
        }

        // jami qatori (namunadagidek yuqorida), keyin tumanlar
        $y += $headH;
        foreach ($rows as $r) {
            if ($r['sum']) {
                imagefilledrectangle($img, $k, $y, $width - $k, $y + $rowH, $sum);
            }
            $x = $k;
            foreach ($widths as $i => $w) {
                $color = isset($r['colors'][$i]) ? $palette[$r['colors'][$i]] : $text;
                $f = $r['sum'] || ($r['colors'][$i] ?? null) === 'red' ? $bold : $font;
                $i === 1 && ! $r['sum']
                    ? $left($t($r['cells'][$i]), $x, $y, $rowH, 11, $f, $text)
                    : $center($t($r['cells'][$i]), $x, $y, $w, $rowH, 11, $f, $color);
                $x += $w;
            }
            $y += $rowH;
        }

        // to'r chiziqlari
        imagesetthickness($img, $k);
        $bottom = $titleH + $headH + count($rows) * $rowH;
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
        $xml .= $row(3, ['', $t['name'], $t['left'], $t['area'], $t['today'], $t['court'], $t['progress']], 3);
        foreach ($data['rows'] as $i => $r) {
            $xml .= $row($i + 4, [$i + 1, $r['name'], $r['left'], $r['area'], $r['today'], $r['court'], $r['progress']]);
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols><col min="1" max="1" width="5" customWidth="1"/><col min="2" max="2" width="28" customWidth="1"/><col min="3" max="7" width="16" customWidth="1"/></cols>'
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
