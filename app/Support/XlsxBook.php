<?php

namespace App\Support;

/**
 * Bir nechta varaqli oddiy Excel (xlsx) — kutubxonasiz (openspout PHP 8.2 talab qiladi, loyiha 8.1).
 * Qator uslubi: 0 — oddiy, 1 — qalin (sarlavha), 2 — qalin + ko'k (ustunlar nomi), 3 — qalin + yashil (jami).
 */
class XlsxBook
{
    public const PLAIN = 0;
    public const TITLE = 1;
    public const HEAD = 2;
    public const TOTAL = 3;

    /** @var list<array{name: string, rows: list<array{0: list<mixed>, 1: int}>, widths: list<int>}> */
    private array $sheets = [];

    /** @param list<int> $widths ustunlar kengligi (belgi) */
    public function sheet(string $name, array $widths = []): self
    {
        // Excel varaq nomi: 31 belgigacha, : \ / ? * [ ] taqiqlangan
        $this->sheets[] = ['name' => mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name), 0, 31), 'rows' => [], 'widths' => $widths];

        return $this;
    }

    public function row(array $values, int $style = self::PLAIN): self
    {
        $this->sheets[count($this->sheets) - 1]['rows'][] = [array_values($values), $style];

        return $this;
    }

    public function binary(): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i) {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26).$s;
            }

            return $s;
        };
        $files = [];
        foreach ($this->sheets as $k => $sh) {
            $xml = '';
            foreach ($sh['rows'] as $r => [$values, $style]) {
                $n = $r + 1;
                $s = $style ? " s=\"{$style}\"" : '';
                $cells = '';
                foreach ($values as $i => $v) {
                    $ref = $col($i).$n;
                    $cells .= is_int($v) || is_float($v)
                        ? "<c r=\"{$ref}\"{$s}><v>{$v}</v></c>"
                        : ($v === null || $v === '' ? ($style ? "<c r=\"{$ref}\"{$s}/>" : '') : "<c r=\"{$ref}\" t=\"inlineStr\"{$s}><is><t>{$esc($v)}</t></is></c>");
                }
                $xml .= "<row r=\"{$n}\">{$cells}</row>";
            }
            $cols = '';
            foreach ($sh['widths'] as $i => $w) {
                $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $files['xl/worksheets/sheet'.($k + 1).'.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .($cols ? "<cols>{$cols}</cols>" : '')."<sheetData>{$xml}</sheetData></worksheet>";
        }

        $ns = 'http://schemas.openxmlformats.org/';
        $overrides = $sheetsXml = $rels = '';
        foreach ($this->sheets as $k => $sh) {
            $id = $k + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$id}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $sheetsXml .= '<sheet name="'.$esc($sh['name'])."\" sheetId=\"{$id}\" r:id=\"rId{$id}\"/>";
            $rels .= "<Relationship Id=\"rId{$id}\" Type=\"{$ns}officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$id}.xml\"/>";
        }
        $stylesId = count($this->sheets) + 1;
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            ."<Types xmlns=\"{$ns}package/2006/content-types\">"
            ."<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>"
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            ."<Relationships xmlns=\"{$ns}package/2006/relationships\">"
            ."<Relationship Id=\"rId1\" Type=\"{$ns}officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>";
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            ."<workbook xmlns=\"{$ns}spreadsheetml/2006/main\" xmlns:r=\"{$ns}officeDocument/2006/relationships\"><sheets>{$sheetsXml}</sheets></workbook>";
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            ."<Relationships xmlns=\"{$ns}package/2006/relationships\">{$rels}"
            ."<Relationship Id=\"rId{$stylesId}\" Type=\"{$ns}officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>";
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            ."<styleSheet xmlns=\"{$ns}spreadsheetml/2006/main\">"
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill></fills>'
            .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="4"><xf/><xf fontId="1" applyFont="1"/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf fontId="1" fillId="3" applyFont="1" applyFill="1"/></cellXfs>'
            .'</styleSheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }
}
