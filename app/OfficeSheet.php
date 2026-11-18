<?php
declare(strict_types=1);

/**
 * The quick view of Excel files, from the oldest to the newest:
 *  - Excel 2.1, 3.0, 4.0 (BIFF2 to BIFF4) and Excel 5.0 / 95 (BIFF5), Excel 97 to 2003 and the .xls of every later Excel (BIFF8);
 *  - Excel 2003 XML, Excel 2007 to 365 (.xlsx, .xlsm, .xltx, .xltm) and the binary workbook (.xlsb);
 *  - a workbook saved as a web page (.xls holding HTML), and CSV.
 * Every sheet shows as Excel draws it: column letters and row numbers, column widths and row heights, merged cells,
 * fonts, fills, borders, alignment, wrapped text, number / date / currency formats, text running over empty cells,
 * hidden rows and columns, gridlines on or off, and the pictures placed on the sheet.
 */
final class OfficeSheet
{
    public const MAX_ROWS = 2500;
    public const MAX_COLS = 150;
    public const MAX_CELLS = 250000;
    /** The colours of Excel by index (BIFF and the indexed colours of .xlsx) */
    private const PALETTE = ['000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF', '000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
        '800000', '008000', '000080', '808000', '800080', '008080', 'C0C0C0', '808080', '9999FF', '993366', 'FFFFCC', 'CCFFFF', '660066', 'FF8080', '0066CC', 'CCCCFF', '000080', 'FF00FF',
        'FFFF00', '00FFFF', '800080', '800000', '008080', '0000FF', '00CCFF', 'CCFFFF', 'CCFFCC', 'FFFF99', '99CCFF', 'FF99CC', 'CC99FF', 'FFCC99', '3366FF', '33CCCC', '99CC00', 'FFCC00',
        'FF9900', 'FF6600', '666699', '969696', '003366', '339966', '003300', '333300', '993300', '993366', '333399', '333333'];
    /** The number formats built into Excel */
    private const BUILTIN = [0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 5 => '"$"#,##0_);("$"#,##0)', 6 => '"$"#,##0_);[Red]("$"#,##0)',
        7 => '"$"#,##0.00_);("$"#,##0.00)', 8 => '"$"#,##0.00_);[Red]("$"#,##0.00)', 9 => '0%', 10 => '0.00%', 11 => '0.00E+00', 12 => 'General', 13 => 'General',
        14 => 'm/d/yyyy', 15 => 'd-mmm-yy', 16 => 'd-mmm', 17 => 'mmm-yy', 18 => 'h:mm AM/PM', 19 => 'h:mm:ss AM/PM', 20 => 'h:mm', 21 => 'h:mm:ss', 22 => 'm/d/yyyy h:mm',
        37 => '#,##0_);(#,##0)', 38 => '#,##0_);[Red](#,##0)', 39 => '#,##0.00_);(#,##0.00)', 40 => '#,##0.00_);[Red](#,##0.00)', 41 => '_(* #,##0_);_(* (#,##0);_(* "-"_);_(@_)',
        42 => '_("$"* #,##0_);_("$"* (#,##0);_("$"* "-"_);_(@_)', 43 => '_(* #,##0.00_);_(* (#,##0.00);_(* "-"??_);_(@_)', 44 => '_("$"* #,##0.00_);_("$"* (#,##0.00);_("$"* "-"??_);_(@_)',
        45 => 'mm:ss', 46 => '[h]:mm:ss', 47 => 'mm:ss.0', 48 => '##0.0E+0', 49 => '@'];
    private const ERR = [0x00 => '#NULL!', 0x07 => '#DIV/0!', 0x0F => '#VALUE!', 0x17 => '#REF!', 0x1D => '#NAME?', 0x24 => '#NUM!', 0x2A => '#N/A', 0x2B => '#GETTING_DATA'];
    private const LINES = [1 => '1px solid', 2 => '2px solid', 3 => '1px dashed', 4 => '1px dotted', 5 => '3px solid', 6 => '3px double', 7 => '1px dotted', 8 => '2px dashed',
        9 => '1px dashed', 10 => '2px dashed', 11 => '1px dotted', 12 => '2px dotted', 13 => '2px dashed'];
    private const XLINES = ['thin' => 1, 'medium' => 2, 'dashed' => 3, 'dotted' => 4, 'thick' => 5, 'double' => 6, 'hair' => 7, 'mediumDashed' => 8, 'dashDot' => 9,
        'mediumDashDot' => 10, 'dashDotDot' => 11, 'mediumDashDotDot' => 12, 'slantDashDot' => 13];

    // the workbook being read
    private array $palette = [];
    private array $theme = [];
    private array $fonts = [];
    private array $formats = [];
    private array $xf = [];
    private array $xfCss = [];
    private array $sst = [];
    private bool $date1904 = false;
    private int $codepage = 1252;
    private int $biff = 8;
    private array $notes = [];
    private int $cells = 0;
    private array $baseFont = ['sz' => 11, 'name' => 'Calibri'];

    public static function render(string $file, string $ext): string
    {
        return self::html(self::read($file, strtolower($ext)));
    }

    /** ['sheets' => [...], 'notes' => [...], 'font' => ['sz','name']] */
    public static function read(string $file, string $ext): array
    {
        $me = new self();
        $me->palette = self::PALETTE;
        $head = (string)file_get_contents($file, false, null, 0, 8192);
        if ($head === '') {
            return ['sheets' => [], 'notes' => ['The file is empty.'], 'font' => $me->baseFont];
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('the Excel file is damaged');
            }
            try {
                $sheets = $zip->locateName('xl/workbook.bin') !== false ? $me->xlsb($zip) : $me->xlsx($zip);
            } finally {
                $zip->close();
            }
        } elseif (OleFile::is($head)) {
            $ole = new OleFile((string)file_get_contents($file));
            if ($ole->has('EncryptionInfo') || $ole->has('EncryptedPackage')) {
                throw new OfficeLockedException('This Excel file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
            }
            $wb = $ole->read('Workbook') ?? $ole->read('Book');
            if ($wb === null) {
                throw new RuntimeException('it holds no Excel workbook');
            }
            $sheets = $me->biff($wb);
        } elseif (in_array(OfficePreview::u16($head, 0), [0x0009, 0x0209, 0x0409, 0x0809], true)) {
            $sheets = $me->biff((string)file_get_contents($file));
        } elseif (!preg_match('/^\s*(<!DOCTYPE\s+html|<html)/i', $head) && preg_match('/<Workbook[^>]+urn:schemas-microsoft-com:office:spreadsheet|<ss:Workbook/i', $head)) {
            $sheets = $me->xml2003((string)file_get_contents($file));
        } elseif (preg_match('/<html|<table|<!DOCTYPE\s+html/i', $head)) {
            $sheets = $me->htmlTables((string)file_get_contents($file));
        } else {
            $sheets = [$me->csv($file)];
        }
        self::$current = $me;
        return ['sheets' => $sheets, 'notes' => array_values(array_unique($me->notes)), 'font' => $me->baseFont];
    }

    // ================================================================== shared pieces

    private static function colName(int $c): string
    {
        $s = '';
        for ($c++; $c > 0; $c = intdiv($c - 1, 26)) {
            $s = chr(65 + ($c - 1) % 26) . $s;
        }
        return $s;
    }

    /** "B12" as [row, column] from 0 */
    private static function ref(string $r): array
    {
        if (!preg_match('/^\$?([A-Z]{1,3})\$?(\d+)$/i', $r, $m)) {
            return [-1, -1];
        }
        $c = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) {
            $c = $c * 26 + ord($ch) - 64;
        }
        return [(int)$m[2] - 1, $c - 1];
    }

    private static function newSheet(string $name): array
    {
        return ['name' => $name, 'hidden' => false, 'rows' => [], 'heights' => [], 'hiddenRows' => [], 'cols' => [], 'hiddenCols' => [], 'merges' => [], 'pics' => [],
            'grid' => true, 'defW' => 64, 'defH' => 20, 'cut' => false, 'note' => ''];
    }

    private function cellPut(array &$sh, int $r, int $c, string $v, string $kind, int $xf, bool $styleOnly = false): void
    {
        if ($r < 0 || $c < 0 || $r >= self::MAX_ROWS || $c >= self::MAX_COLS) {
            if ($r >= self::MAX_ROWS || $c >= self::MAX_COLS) {
                $sh['cut'] = true;
            }
            return;
        }
        if (++$this->cells > self::MAX_CELLS) {
            $sh['cut'] = true;
            return;
        }
        $sh['rows'][$r][$c] = ['v' => $v, 'k' => $kind, 'x' => $xf, 'so' => $styleOnly];
    }

    /** A number in the format of its cell style */
    private function fmtNum(float $v, int $xf): string
    {
        $code = $this->xf[$xf]['fmt'] ?? 'General';
        return OfficePreview::number($v, $code, $this->date1904);
    }

    private function color(?int $icv): string
    {
        // the automatic colours: 64 / 65 (Excel 5 and later), 24 / 25 (Excel 2 to 4)
        if ($icv === null || $icv >= 64 || $icv < 0 || ($this->biff <= 4 && ($icv === 24 || $icv === 25))) {
            return '';
        }
        return '#' . strtolower($this->palette[$icv] ?? '000000');
    }

    // ------------------------------------------------------------------ styles as CSS

    /**
     * A cell style: ['font' => ['b','i','u','s','c','sz','name'], 'fill' => '#hex', 'al' => horizontal, 'va' => vertical, 'wrap', 'indent',
     * 'bd' => [l, r, t, b] each [line code, '#color'], 'fmt' => number format code]
     */
    private function css(int $xf): string
    {
        if (isset($this->xfCss[$xf])) {
            return $this->xfCss[$xf];
        }
        $x = $this->xf[$xf] ?? [];
        $s = '';
        $f = $x['font'] ?? [];
        if (!empty($f['b'])) {
            $s .= 'font-weight:700;';
        }
        if (!empty($f['i'])) {
            $s .= 'font-style:italic;';
        }
        $dec = array_keys(array_filter(['underline' => !empty($f['u']), 'line-through' => !empty($f['s'])]));
        if ($dec) {
            $s .= 'text-decoration:' . implode(' ', $dec) . ';';
        }
        if (!empty($f['c']) && $f['c'] !== '#000000') {
            $s .= 'color:' . $f['c'] . ';';
        }
        if (!empty($f['sz']) && abs($f['sz'] - $this->baseFont['sz']) > 0.01) {
            $s .= 'font-size:' . max(4, min(72, (float)$f['sz'])) . 'pt;';
        }
        if (!empty($f['name']) && strcasecmp($f['name'], $this->baseFont['name']) !== 0) {
            $s .= 'font-family:' . OfficePreview::font($f['name']) . ';';
        }
        if (!empty($x['fill'])) {
            $s .= 'background:' . $x['fill'] . ';';
        }
        $al = $x['al'] ?? '';
        if (in_array($al, ['left', 'center', 'right', 'justify'], true)) {
            $s .= 'text-align:' . $al . ';';
        }
        $va = $x['va'] ?? '';
        if ($va === 'top' || $va === 'middle') {
            $s .= 'vertical-align:' . $va . ';';
        }
        if (!empty($x['indent'])) {
            $s .= 'padding-left:' . (4 + 9 * min(15, (int)$x['indent'])) . 'px;';
        }
        foreach (['left', 'right', 'top', 'bottom'] as $k => $side) {
            $b = $x['bd'][$k] ?? null;
            if ($b && $b[0] > 0 && isset(self::LINES[$b[0]])) {
                $s .= 'border-' . $side . ':' . self::LINES[$b[0]] . ' ' . ($b[1] ?: '#000000') . ';';
            }
        }
        return $this->xfCss[$xf] = $s;
    }

    // ================================================================== Excel 97-2003 and older (BIFF2 to BIFF8)

    private function biff(string $d): array
    {
        $n = strlen($d);
        $bofType = OfficePreview::u16($d, 0);
        $vers = OfficePreview::u16($d, 4);
        $this->biff = match ($bofType) {
            0x0009 => 2, 0x0209 => 3, 0x0409 => 4, default => $vers === 0x0500 ? 5 : 8,
        };
        if ($this->biff <= 5) {
            $this->notes[] = 'Read as an Excel ' . ['2' => '2.1', '3' => '3.0', '4' => '4.0', '5' => '5.0 / 95'][(string)$this->biff] . ' workbook.';
        }
        $this->baseFont = ['sz' => 10, 'name' => 'Arial'];
        if ($this->biff <= 4) {
            // one worksheet (or a BIFF4 workbook of worksheets one after the other): styles and cells in the same stream
            $sheets = [];
            $pos = 0;
            $k = 0;
            while ($pos < $n && $k < 200) {
                $t = OfficePreview::u16($d, $pos);
                if (!in_array($t, [0x0009, 0x0209, 0x0409, 0x0809], true)) {
                    break;
                }
                $dt = OfficePreview::u16($d, $pos + 6);
                [$sheet, $pos] = $this->biffSheet($d, $pos, 'Sheet' . (++$k));
                if ($dt !== 0x0100 && ($sheet['rows'] || $sheet['merges'])) {
                    $sheets[] = $sheet;
                }
            }
            return $sheets ?: [self::newSheet('Sheet1')];
        }
        // the workbook globals
        $bound = [];
        $pos = 0;
        $sstParts = [];
        $dgg = '';
        $lastType = 0;
        while ($pos + 4 <= $n) {
            $t = OfficePreview::u16($d, $pos);
            $len = OfficePreview::u16($d, $pos + 2);
            $r = substr($d, $pos + 4, $len);
            $pos += 4 + $len;
            switch ($t) {
                case 0x002F:
                    throw new OfficeLockedException('This Excel file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
                case 0x0042:
                    $cp = OfficePreview::u16($r, 0);
                    $this->codepage = $cp === 0x8000 ? 10000 : ($cp === 0x8001 ? 1252 : ($cp ?: 1252));
                    break;
                case 0x0022:
                    $this->date1904 = OfficePreview::u16($r, 0) === 1;
                    break;
                case 0x0031:
                    $this->biffFont($r, 0x0031);
                    break;
                case 0x041E:
                    $this->biffFormat($r, $t);
                    break;
                case 0x00E0:
                    $this->biffXf($r);
                    break;
                case 0x0092:
                    $this->biffPalette($r);
                    break;
                case 0x0085:
                    $name = $this->biff === 8 ? $this->shortStr($r, 6) : OfficePreview::cp(substr($r, 7, OfficePreview::u8($r, 6)), $this->codepage);
                    $bound[] = ['pos' => OfficePreview::u32($r, 0), 'state' => OfficePreview::u8($r, 4) & 3, 'type' => OfficePreview::u8($r, 5), 'name' => $name];
                    break;
                case 0x00FC:
                    $sstParts = [$r];
                    break;
                case 0x00EB:
                    $dgg .= $r;
                    break;
                case 0x003C:
                    if ($lastType === 0x00FC) {
                        $sstParts[] = $r;
                        $t = 0x00FC;
                    } elseif ($lastType === 0x00EB) {
                        $dgg .= $r;
                        $t = 0x00EB;
                    }
                    break;
            }
            $lastType = $t;
            if ($t === 0x000A) {
                break;
            }
        }
        if ($sstParts) {
            $this->readSst($sstParts);
        }
        $pics = $dgg !== '' ? OfficePreview::bstore($dgg) : [];
        $f0 = $this->fonts[0] ?? null;
        if ($f0) {
            $this->baseFont = ['sz' => $f0['sz'] ?? 10, 'name' => $f0['name'] ?? 'Arial'];
        }
        $sheets = [];
        foreach ($bound as $b) {
            if ($b['type'] === 2) {
                $s = self::newSheet($b['name']);
                $s['note'] = 'This is a chart sheet: charts show in the page view.';
                $s['hidden'] = $b['state'] > 0;
                $sheets[] = $s;
                continue;
            }
            if ($b['type'] !== 0 || $b['pos'] >= $n) {
                continue;
            }
            [$s] = $this->biffSheet($d, $b['pos'], $b['name'], $pics);
            $s['hidden'] = $b['state'] > 0;
            $sheets[] = $s;
        }
        return $sheets ?: [self::newSheet('Sheet1')];
    }

    /** A sheet from its BOF to its EOF: [sheet, the position after it] */
    private function biffSheet(string $d, int $pos, string $name, array $pics = []): array
    {
        $sh = self::newSheet($name);
        $n = strlen($d);
        $pendingFormula = null;
        $drawing = '';
        $depth = 0;
        $fmtIndex = count($this->formats);
        while ($pos + 4 <= $n) {
            $t = OfficePreview::u16($d, $pos);
            $len = OfficePreview::u16($d, $pos + 2);
            $r = substr($d, $pos + 4, $len);
            $pos += 4 + $len;
            if (in_array($t, [0x0009, 0x0209, 0x0409, 0x0809], true)) {
                $depth++;
                continue;
            }
            if ($t === 0x000A) {
                if (--$depth <= 0) {
                    break;
                }
                continue;
            }
            if ($depth > 1) {
                continue;
            }
            $b2 = $this->biff === 2;
            switch ($t) {
                // styles inside the sheet (Excel 2 to 4)
                case 0x0031:
                case 0x0231:
                    $this->biffFont($r, $t);
                    break;
                case 0x0045:
                    if ($this->fonts) {
                        $this->fonts[count($this->fonts) - 1]['c'] = $this->color(OfficePreview::u16($r, 0));
                    }
                    break;
                case 0x001E:
                case 0x041E:
                    $this->biffFormat($r, $t, $fmtIndex++);
                    break;
                case 0x0043:
                case 0x0243:
                case 0x0443:
                case 0x00E0:
                    $this->biffXf($r, $t);
                    break;
                case 0x0092:
                    $this->biffPalette($r);
                    break;
                case 0x0022:
                    $this->date1904 = OfficePreview::u16($r, 0) === 1;
                    break;
                case 0x0042:
                    $cp = OfficePreview::u16($r, 0);
                    $this->codepage = $cp === 0x8000 ? 10000 : ($cp === 0x8001 ? 1252 : ($cp ?: 1252));
                    break;
                // the layout of the sheet
                case 0x0055:
                    $sh['defW'] = (int)round(OfficePreview::u16($r, 0) * 7 + 5);
                    break;
                case 0x0099:
                    $sh['defW'] = (int)round(OfficePreview::u16($r, 0) / 256 * 7 + 5);
                    break;
                case 0x0225:
                    $sh['defH'] = max(4, (int)round((OfficePreview::u16($r, 2) & 0x7FFF) / 15));
                    break;
                case 0x0025:
                    $sh['defH'] = max(4, (int)round((OfficePreview::u16($r, 0) & 0x7FFF) / 15));
                    break;
                case 0x007D:
                    $w = (int)round(OfficePreview::u16($r, 4) / 256 * 7 + 5);
                    $hid = (bool)(OfficePreview::u16($r, 8) & 1);
                    for ($c = OfficePreview::u16($r, 0), $e = min(self::MAX_COLS - 1, OfficePreview::u16($r, 2)); $c <= $e; $c++) {
                        $sh['cols'][$c] = $w;
                        if ($hid || $w <= 5) {
                            $sh['hiddenCols'][$c] = true;
                        }
                    }
                    break;
                case 0x0024:
                    for ($c = OfficePreview::u8($r, 0), $e = min(self::MAX_COLS - 1, OfficePreview::u8($r, 1)); $c <= $e; $c++) {
                        $sh['cols'][$c] = (int)round(OfficePreview::u16($r, 2) / 256 * 7 + 5);
                    }
                    break;
                case 0x0208:
                case 0x0008:
                    $row = OfficePreview::u16($r, 0);
                    $h = OfficePreview::u16($r, 6);
                    if (!($h & 0x8000) || $this->biff >= 5) {
                        $sh['heights'][$row] = max(0, (int)round(($h & 0x7FFF) / 15));
                    }
                    if ($this->biff >= 5 && (OfficePreview::u16($r, 12) & 0x20)) {
                        $sh['hiddenRows'][$row] = true;
                    }
                    break;
                case 0x023E:
                    $sh['grid'] = (bool)(OfficePreview::u16($r, 0) & 0x0002);
                    break;
                case 0x003E:
                    $sh['grid'] = OfficePreview::u8($r, 1) !== 0;
                    break;
                case 0x00E5:
                    for ($k = 0, $m = OfficePreview::u16($r, 0); $k < $m; $k++) {
                        $o = 2 + 8 * $k;
                        $sh['merges'][] = [OfficePreview::u16($r, $o), OfficePreview::u16($r, $o + 4), OfficePreview::u16($r, $o + 2), OfficePreview::u16($r, $o + 6)];
                    }
                    break;
                case 0x00EC:
                    $drawing .= $r;
                    break;
                // cells
                case 0x00FD:
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), (string)($this->sst[OfficePreview::u32($r, 6)] ?? ''), 's', OfficePreview::u16($r, 4));
                    break;
                case 0x0204:
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), $this->biff === 8 ? $this->uniStr($r, 6) : OfficePreview::cp(substr($r, 8, OfficePreview::u16($r, 6)), $this->codepage), 's', OfficePreview::u16($r, 4));
                    break;
                case 0x00D6:
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), OfficePreview::cp(substr($r, 8, OfficePreview::u16($r, 6)), $this->codepage), 's', OfficePreview::u16($r, 4));
                    break;
                case 0x0004:
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), OfficePreview::cp(substr($r, 8, OfficePreview::u8($r, 7)), $this->codepage), 's', $this->attrXf($r));
                    break;
                case 0x0203:
                    $xf = OfficePreview::u16($r, 4);
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), $this->fmtNum(OfficePreview::f64($r, 6), $xf), 'n', $xf);
                    break;
                case 0x0003:
                    $xf = $this->attrXf($r);
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), $this->fmtNum(OfficePreview::f64($r, 7), $xf), 'n', $xf);
                    break;
                case 0x0002:
                    $xf = $this->attrXf($r);
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), $this->fmtNum((float)OfficePreview::u16($r, 7), $xf), 'n', $xf);
                    break;
                case 0x027E:
                case 0x007E:
                    $xf = OfficePreview::u16($r, 4);
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), $this->fmtNum(self::rk(OfficePreview::u32($r, 6)), $xf), 'n', $xf);
                    break;
                case 0x00BD:
                    $row = OfficePreview::u16($r, 0);
                    $c0 = OfficePreview::u16($r, 2);
                    for ($k = 0, $m = intdiv(strlen($r) - 6, 6); $k < $m; $k++) {
                        $xf = OfficePreview::u16($r, 4 + 6 * $k);
                        $this->cellPut($sh, $row, $c0 + $k, $this->fmtNum(self::rk(OfficePreview::u32($r, 6 + 6 * $k)), $xf), 'n', $xf);
                    }
                    break;
                case 0x0205:
                case 0x0005:
                    $o = $t === 0x0005 ? 7 : 6;
                    $xf = $t === 0x0005 ? $this->attrXf($r) : OfficePreview::u16($r, 4);
                    $v = OfficePreview::u8($r, $o);
                    $err = OfficePreview::u8($r, $o + 1) === 1;
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), $err ? (self::ERR[$v] ?? '#ERR') : ($v ? 'TRUE' : 'FALSE'), $err ? 'e' : 'b', $xf);
                    break;
                case 0x0006:
                case 0x0206:
                case 0x0406:
                    $o = $b2 ? 7 : 6;
                    $xf = $b2 ? $this->attrXf($r) : OfficePreview::u16($r, 4);
                    $row = OfficePreview::u16($r, 0);
                    $col = OfficePreview::u16($r, 2);
                    if (OfficePreview::u16($r, $o + 6) === 0xFFFF) {
                        $kind = OfficePreview::u8($r, $o);
                        $v = OfficePreview::u8($r, $o + 2);
                        if ($kind === 0) {
                            $pendingFormula = [$row, $col, $xf];
                        } elseif ($kind === 1) {
                            $this->cellPut($sh, $row, $col, $v ? 'TRUE' : 'FALSE', 'b', $xf);
                        } elseif ($kind === 2) {
                            $this->cellPut($sh, $row, $col, self::ERR[$v] ?? '#ERR', 'e', $xf);
                        } else {
                            $this->cellPut($sh, $row, $col, '', 's', $xf);
                        }
                    } else {
                        $this->cellPut($sh, $row, $col, $this->fmtNum(OfficePreview::f64($r, $o), $xf), 'n', $xf);
                    }
                    break;
                case 0x0207:
                case 0x0007:
                    if ($pendingFormula) {
                        $v = $t === 0x0007 ? OfficePreview::cp(substr($r, 1, OfficePreview::u8($r, 0)), $this->codepage)
                            : ($this->biff === 8 ? $this->uniStr($r, 0) : OfficePreview::cp(substr($r, 2, OfficePreview::u16($r, 0)), $this->codepage));
                        $this->cellPut($sh, $pendingFormula[0], $pendingFormula[1], $v, 's', $pendingFormula[2]);
                        $pendingFormula = null;
                    }
                    break;
                case 0x0201:
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), '', 's', OfficePreview::u16($r, 4), true);
                    break;
                case 0x0001:
                    $this->cellPut($sh, OfficePreview::u16($r, 0), OfficePreview::u16($r, 2), '', 's', $this->attrXf($r), true);
                    break;
                case 0x00BE:
                    $row = OfficePreview::u16($r, 0);
                    $c0 = OfficePreview::u16($r, 2);
                    for ($k = 0, $m = intdiv(strlen($r) - 6, 2); $k < $m; $k++) {
                        $this->cellPut($sh, $row, $c0 + $k, '', 's', OfficePreview::u16($r, 4 + 2 * $k), true);
                    }
                    break;
            }
        }
        if ($drawing !== '' && $pics) {
            foreach (OfficePreview::shapes($drawing) as $s) {
                if ($s['pib'] > 0 && strlen($s['anchor']) >= 18) {
                    $a = $s['anchor'];
                    $sh['pics'][] = ['r' => OfficePreview::u16($a, 6), 'c' => OfficePreview::u16($a, 2), 'dx' => OfficePreview::u16($a, 4) / 1024, 'dy' => OfficePreview::u16($a, 8) / 256,
                        'r2' => OfficePreview::u16($a, 14), 'c2' => OfficePreview::u16($a, 10), 'dx2' => OfficePreview::u16($a, 12) / 1024, 'dy2' => OfficePreview::u16($a, 16) / 256,
                        'bytes' => $pics[$s['pib']] ?? ''];
                }
            }
        }
        return [$sh, $pos];
    }

    /** The style of a cell of Excel 2.1 (three bytes in the cell): an XF made from them */
    private function attrXf(string $r): int
    {
        $a0 = OfficePreview::u8($r, 4);
        $a1 = OfficePreview::u8($r, 5);
        $a2 = OfficePreview::u8($r, 6);
        $key = 0x10000 + ($a0 << 16 | $a1 << 8 | $a2);
        if (!isset($this->xf[$key])) {
            $fi = ($a1 >> 6) & 3;
            $alc = $a2 & 7;
            $bd = [];
            foreach ([3 => 0, 4 => 1, 5 => 2, 6 => 3] as $bit => $side) {
                $bd[$side] = ($a2 >> $bit) & 1 ? [1, '#000000'] : null;
            }
            $this->xf[$key] = ['font' => $this->fonts[$fi] ?? [], 'fmt' => $this->formats[$a1 & 0x3F] ?? 'General', 'al' => [1 => 'left', 2 => 'center', 3 => 'right'][$alc] ?? '',
                'va' => '', 'wrap' => false, 'bd' => $bd, 'fill' => ($a2 & 0x80) ? '#d9d9d9' : ''];
        }
        return $key;
    }

    private static function rk(int $rk): float
    {
        if ($rk & 2) {
            $v = $rk >> 2;
            if ($v & 0x20000000) {
                $v -= 0x40000000;
            }
            $v = (float)$v;
        } else {
            $v = (float)unpack('e', "\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC))[1];
        }
        return ($rk & 1) ? $v / 100 : $v;
    }

    private function biffFont(string $r, int $t): void
    {
        $sz = OfficePreview::u16($r, 0) / 20;
        $g = OfficePreview::u16($r, 2);
        if ($this->biff >= 5) {
            $f = ['sz' => $sz, 'i' => (bool)($g & 2), 's' => (bool)($g & 8), 'c' => $this->color(OfficePreview::u16($r, 4)), 'b' => OfficePreview::u16($r, 6) >= 600,
                'u' => OfficePreview::u8($r, 10) !== 0, 'name' => $this->biff === 8 ? $this->shortStr($r, 14) : OfficePreview::cp(substr($r, 15, OfficePreview::u8($r, 14)), $this->codepage)];
        } elseif ($t === 0x0231) {
            $f = ['sz' => $sz, 'b' => (bool)($g & 1), 'i' => (bool)($g & 2), 'u' => (bool)($g & 4), 's' => (bool)($g & 8), 'c' => $this->color(OfficePreview::u16($r, 4)),
                'name' => OfficePreview::cp(substr($r, 7, OfficePreview::u8($r, 6)), $this->codepage)];
        } else {
            $f = ['sz' => $sz, 'b' => (bool)($g & 1), 'i' => (bool)($g & 2), 'u' => (bool)($g & 4), 's' => (bool)($g & 8), 'c' => '',
                'name' => OfficePreview::cp(substr($r, 5, OfficePreview::u8($r, 4)), $this->codepage)];
        }
        $this->fonts[] = $f;
        // there is no font 4
        if (count($this->fonts) === 4) {
            $this->fonts[] = $f;
        }
    }

    private function biffFormat(string $r, int $t, int $order = -1): void
    {
        if ($this->biff === 8) {
            $this->formats[OfficePreview::u16($r, 0)] = $this->uniStr($r, 2);
        } elseif ($this->biff === 5) {
            $this->formats[OfficePreview::u16($r, 0)] = OfficePreview::cp(substr($r, 3, OfficePreview::u8($r, 2)), $this->codepage);
        } elseif ($t === 0x041E) {
            $this->formats[$order] = OfficePreview::cp(substr($r, 3, OfficePreview::u8($r, 2)), $this->codepage);
        } else {
            $this->formats[$order] = OfficePreview::cp(substr($r, 1, OfficePreview::u8($r, 0)), $this->codepage);
        }
    }

    private function biffPalette(string $r): void
    {
        for ($k = 0, $m = OfficePreview::u16($r, 0); $k < $m && 8 + $k < 64; $k++) {
            $this->palette[8 + $k] = sprintf('%02X%02X%02X', OfficePreview::u8($r, 2 + 4 * $k), OfficePreview::u8($r, 3 + 4 * $k), OfficePreview::u8($r, 4 + 4 * $k));
        }
        $this->xfCss = [];
    }

    private function biffXf(string $r, int $t = 0x00E0): void
    {
        $alH = [1 => 'left', 2 => 'center', 3 => 'right', 5 => 'justify', 6 => 'center', 7 => 'justify'];
        $alV = [0 => 'top', 1 => 'middle', 2 => 'bottom'];
        if ($this->biff === 8) {
            $a = OfficePreview::u8($r, 6);
            $b1 = OfficePreview::u32($r, 10);
            $b2 = OfficePreview::u32($r, 14);
            $fills = OfficePreview::u16($r, 18);
            $x = ['ifnt' => OfficePreview::u16($r, 0), 'ifmt' => OfficePreview::u16($r, 2), 'alc' => $a & 7, 'wrap' => (bool)($a & 8), 'alcv' => ($a >> 4) & 7,
                'indent' => OfficePreview::u8($r, 8) & 0x0F,
                'bd' => [[$b1 & 15, ($b1 >> 16) & 0x7F], [($b1 >> 4) & 15, ($b1 >> 23) & 0x7F], [($b1 >> 8) & 15, $b2 & 0x7F], [($b1 >> 12) & 15, ($b2 >> 7) & 0x7F]],
                'fls' => ($b2 >> 26) & 0x3F, 'fore' => $fills & 0x7F, 'back' => ($fills >> 7) & 0x7F];
        } elseif ($this->biff === 5) {
            $a = OfficePreview::u8($r, 6);
            $f = OfficePreview::u32($r, 8);
            $g = OfficePreview::u32($r, 12);
            $x = ['ifnt' => OfficePreview::u16($r, 0), 'ifmt' => OfficePreview::u16($r, 2), 'alc' => $a & 7, 'wrap' => (bool)($a & 8), 'alcv' => ($a >> 4) & 7, 'indent' => 0,
                'bd' => [[($g >> 3) & 7, ($g >> 16) & 0x7F], [($g >> 6) & 7, ($g >> 23) & 0x7F], [$g & 7, ($g >> 9) & 0x7F], [($f >> 22) & 7, ($f >> 25) & 0x7F]],
                'fls' => ($f >> 16) & 0x3F, 'fore' => $f & 0x7F, 'back' => ($f >> 7) & 0x7F];
        } elseif ($t === 0x0443 || $t === 0x0243) {
            $a = $t === 0x0443 ? OfficePreview::u8($r, 4) : OfficePreview::u16($r, 4);
            $fill = OfficePreview::u16($r, 6);
            $b = OfficePreview::u32($r, 8);
            $x = ['ifnt' => OfficePreview::u8($r, 0), 'ifmt' => OfficePreview::u8($r, 1), 'alc' => $a & 7, 'wrap' => (bool)($a & 8), 'alcv' => $t === 0x0443 ? ($a >> 4) & 3 : 2,
                'indent' => 0, 'bd' => [[($b >> 8) & 7, ($b >> 11) & 0x1F], [($b >> 24) & 7, ($b >> 27) & 0x1F], [$b & 7, ($b >> 3) & 0x1F], [($b >> 16) & 7, ($b >> 19) & 0x1F]],
                'fls' => $fill & 0x3F, 'fore' => ($fill >> 6) & 0x1F, 'back' => ($fill >> 11) & 0x1F];
        } else {
            // Excel 2.1: the cells carry their own style bytes
            $this->xf[] = ['font' => $this->fonts[OfficePreview::u8($r, 0)] ?? [], 'fmt' => 'General', 'al' => '', 'va' => '', 'wrap' => false, 'bd' => [], 'fill' => ''];
            return;
        }
        $ifnt = $x['ifnt'];
        $font = $this->fonts[$ifnt] ?? ($this->fonts[0] ?? []);
        $this->xf[] = ['font' => $font, 'fmt' => $this->formats[$x['ifmt']] ?? (self::BUILTIN[$x['ifmt']] ?? 'General'), 'al' => $alH[$x['alc']] ?? '', 'va' => $alV[$x['alcv']] ?? '',
            'wrap' => $x['wrap'], 'indent' => $x['indent'], 'fill' => $x['fls'] === 1 ? $this->color($x['fore']) : ($x['fls'] > 1 ? $this->color($x['fore']) : ''),
            'bd' => array_map(fn($b) => $b[0] ? [$b[0], $this->color($b[1]) ?: '#000000'] : null, $x['bd'])];
    }

    /** A string with a 2-byte length (XLUnicodeString) at $o */
    private function uniStr(string $r, int $o): string
    {
        $cch = OfficePreview::u16($r, $o);
        $flags = OfficePreview::u8($r, $o + 2);
        $p = $o + 3;
        if ($flags & 8) {
            $p += 2;
        }
        if ($flags & 4) {
            $p += 4;
        }
        return ($flags & 1) ? OfficePreview::utf16(substr($r, $p, 2 * $cch)) : OfficePreview::cp(substr($r, $p, $cch), 1252);
    }

    /** A string with a 1-byte length (ShortXLUnicodeString) at $o */
    private function shortStr(string $r, int $o): string
    {
        $cch = OfficePreview::u8($r, $o);
        $flags = OfficePreview::u8($r, $o + 1);
        return ($flags & 1) ? OfficePreview::utf16(substr($r, $o + 2, 2 * $cch)) : OfficePreview::cp(substr($r, $o + 2, $cch), 1252);
    }

    /** The shared strings (SST and its CONTINUE records: a string may go on in the next record, with its own flag byte) */
    private function readSst(array $parts): void
    {
        $data = implode('', $parts);
        $bounds = [];
        $at = 0;
        foreach ($parts as $p) {
            $at += strlen($p);
            $bounds[] = $at;
        }
        $nb = count($bounds);
        $total = OfficePreview::u32($data, 4);
        $pos = 8;
        $n = strlen($data);
        $bi = 0;
        for ($i = 0; $i < $total && $pos + 3 <= $n; $i++) {
            while ($bi < $nb && $bounds[$bi] <= $pos) {
                $bi++;
            }
            $cch = OfficePreview::u16($data, $pos);
            $flags = OfficePreview::u8($data, $pos + 2);
            $pos += 3;
            $runs = ($flags & 8) ? OfficePreview::u16($data, $pos) : 0;
            $pos += ($flags & 8) ? 2 : 0;
            $ext = ($flags & 4) ? OfficePreview::u32($data, $pos) : 0;
            $pos += ($flags & 4) ? 4 : 0;
            $wide = (bool)($flags & 1);
            $s = '';
            $left = $cch;
            while ($left > 0 && $pos < $n) {
                while ($bi < $nb && $bounds[$bi] <= $pos) {
                    $bi++;
                }
                $end = $bounds[$bi] ?? $n;
                $can = $wide ? intdiv($end - $pos, 2) : $end - $pos;
                $take = min($left, max(0, $can));
                $s .= $wide ? OfficePreview::utf16(substr($data, $pos, 2 * $take)) : OfficePreview::cp(substr($data, $pos, $take), 1252);
                $pos += $wide ? 2 * $take : $take;
                $left -= $take;
                if ($left > 0) {
                    // the next record starts with the flag of the rest
                    $wide = (bool)(OfficePreview::u8($data, $pos) & 1);
                    $pos++;
                }
            }
            $pos += 4 * $runs + $ext;
            $this->sst[] = $s;
        }
    }

    // ================================================================== Excel 2007 to 365 (.xlsx, .xlsm, .xltx, .xltm)

    private function xlsx(ZipArchive $zip): array
    {
        $wbPath = 'xl/workbook.xml';
        foreach ($this->rels($zip, '') as $r) {
            if (str_ends_with($r['type'], '/officeDocument')) {
                $wbPath = $r['path'];
            }
        }
        $wb = $this->dom($zip, $wbPath);
        if (!$wb) {
            throw new RuntimeException('it holds no Excel workbook');
        }
        $rels = $this->rels($zip, $wbPath);
        foreach ($rels as $r) {
            if (str_ends_with($r['type'], '/theme')) {
                $this->xTheme($this->dom($zip, $r['path']));
            }
        }
        foreach ($rels as $r) {
            if (str_ends_with($r['type'], '/styles')) {
                $this->xStyles($this->dom($zip, $r['path']));
            } elseif (str_ends_with($r['type'], '/sharedStrings')) {
                $this->xStrings($zip, $r['path']);
            }
        }
        foreach ($wb->getElementsByTagNameNS('*', 'workbookPr') as $p) {
            $this->date1904 = in_array(strtolower(OfficeWord::attr($p, 'date1904')), ['1', 'true'], true);
        }
        $sheets = [];
        foreach ($wb->getElementsByTagNameNS('*', 'sheet') as $s) {
            $rid = OfficeWord::attr($s, 'id');
            $state = OfficeWord::attr($s, 'state');
            $name = OfficeWord::attr($s, 'name');
            $rel = $rels[$rid] ?? null;
            if (!$rel) {
                continue;
            }
            if (str_ends_with($rel['type'], '/chartsheet')) {
                $sh = self::newSheet($name);
                $sh['note'] = 'This is a chart sheet: charts show in the page view.';
            } else {
                $sh = $this->xSheet($zip, $rel['path'], $name);
            }
            $sh['hidden'] = $state === 'hidden' || $state === 'veryHidden';
            $sheets[] = $sh;
        }
        return $sheets ?: [self::newSheet('Sheet1')];
    }

    private function dom(ZipArchive $zip, string $path): ?DOMDocument
    {
        $x = $zip->getFromName($path);
        return $x === false ? null : OfficeWord::loadXml($x);
    }

    private function rels(ZipArchive $zip, string $part): array
    {
        $dir = str_contains($part, '/') ? substr($part, 0, strrpos($part, '/')) : '';
        $dom = $this->dom($zip, ($dir !== '' ? $dir . '/' : '') . '_rels/' . ($part === '' ? '' : basename($part)) . '.rels');
        $out = [];
        if ($dom) {
            foreach ($dom->documentElement->childNodes as $r) {
                if ($r instanceof DOMElement) {
                    $ext = strcasecmp(OfficeWord::attr($r, 'TargetMode'), 'External') === 0;
                    $out[OfficeWord::attr($r, 'Id')] = ['type' => OfficeWord::attr($r, 'Type'), 'path' => $ext ? '' : OfficeWord::resolve($dir, OfficeWord::attr($r, 'Target'))];
                }
            }
        }
        return $out;
    }

    private function xTheme(?DOMDocument $d): void
    {
        if (!$d) {
            return;
        }
        $order = ['lt1', 'dk1', 'lt2', 'dk2', 'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6', 'hlink', 'folHlink'];
        $l = $d->getElementsByTagNameNS('*', 'clrScheme');
        if (!$l->length) {
            return;
        }
        $map = [];
        foreach ($l->item(0)->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            foreach ($c->childNodes as $v) {
                if ($v instanceof DOMElement) {
                    $map[$c->localName] = $v->localName === 'sysClr' ? (OfficeWord::attr($v, 'lastClr') ?: ($c->localName === 'dk1' ? '000000' : 'FFFFFF')) : OfficeWord::attr($v, 'val');
                }
            }
        }
        foreach ($order as $i => $k) {
            $this->theme[$i] = $map[$k] ?? '000000';
        }
    }

    /** A colour of a style (rgb, theme with tint, indexed) as "#rrggbb" */
    private function xColor(?DOMElement $c): string
    {
        if (!$c) {
            return '';
        }
        $rgb = OfficeWord::attr($c, 'rgb');
        if ($rgb !== '') {
            return OfficePreview::color($rgb);
        }
        $th = OfficeWord::attr($c, 'theme');
        if ($th !== '') {
            $hex = $this->theme[(int)$th] ?? '';
            if ($hex === '') {
                return '';
            }
            return self::tint('#' . strtolower($hex), (float)OfficeWord::attr($c, 'tint'));
        }
        $ix = OfficeWord::attr($c, 'indexed');
        if ($ix !== '') {
            $i = (int)$ix;
            return $i === 64 ? '#000000' : ($i === 65 ? '' : $this->color($i));
        }
        return '';
    }

    public static function tint(string $hex, float $tint): string
    {
        if ($tint == 0.0 || !preg_match('/^#[0-9a-f]{6}$/', $hex)) {
            return $hex;
        }
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $h = $s = 0.0;
        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > .5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            $h = match (true) {
                $max === $r => ($g - $b) / $d + ($g < $b ? 6 : 0), $max === $g => ($b - $r) / $d + 2, default => ($r - $g) / $d + 4,
            } / 6;
        }
        $l = $tint < 0 ? $l * (1 + $tint) : $l * (1 - $tint) + $tint;
        $hue = function ($p, $q, $t) {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            return $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < .5 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));
        };
        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            [$r, $g, $b] = [$hue($p, $q, $h + 1 / 3), $hue($p, $q, $h), $hue($p, $q, $h - 1 / 3)];
        }
        return sprintf('#%02x%02x%02x', (int)round($r * 255), (int)round($g * 255), (int)round($b * 255));
    }

    private function xStyles(?DOMDocument $d): void
    {
        if (!$d) {
            return;
        }
        $root = $d->documentElement;
        $kid = fn(DOMElement $e, string $n) => self::kid($e, $n);
        if ($cs = $kid($root, 'colors')) {
            if ($ic = $kid($cs, 'indexedColors')) {
                $i = 0;
                foreach ($ic->childNodes as $c) {
                    if ($c instanceof DOMElement) {
                        $rgb = OfficePreview::color(OfficeWord::attr($c, 'rgb'));
                        if ($rgb !== '') {
                            $this->palette[$i] = strtoupper(substr($rgb, 1));
                        }
                        $i++;
                    }
                }
            }
        }
        $fmts = [];
        if ($nf = $kid($root, 'numFmts')) {
            foreach ($nf->childNodes as $c) {
                if ($c instanceof DOMElement) {
                    $fmts[(int)OfficeWord::attr($c, 'numFmtId')] = OfficeWord::attr($c, 'formatCode');
                }
            }
        }
        $fonts = [];
        if ($fs = $kid($root, 'fonts')) {
            foreach ($fs->childNodes as $f) {
                if (!$f instanceof DOMElement) {
                    continue;
                }
                $x = ['b' => false, 'i' => false, 'u' => false, 's' => false, 'c' => '', 'sz' => 11, 'name' => ''];
                foreach ($f->childNodes as $p) {
                    if (!$p instanceof DOMElement) {
                        continue;
                    }
                    $v = OfficeWord::attr($p, 'val');
                    $on = !in_array(strtolower($v), ['0', 'false'], true);
                    switch ($p->localName) {
                        case 'b': $x['b'] = $on; break;
                        case 'i': $x['i'] = $on; break;
                        case 'u': $x['u'] = $v !== 'none'; break;
                        case 'strike': $x['s'] = $on; break;
                        case 'sz': $x['sz'] = (float)$v ?: 11; break;
                        case 'name':
                        case 'rFont': $x['name'] = $v; break;
                        case 'color': $x['c'] = $this->xColor($p); break;
                    }
                }
                $fonts[] = $x;
            }
        }
        $fills = [];
        if ($fl = $kid($root, 'fills')) {
            foreach ($fl->childNodes as $f) {
                if (!$f instanceof DOMElement) {
                    continue;
                }
                $c = '';
                if ($pf = $kid($f, 'patternFill')) {
                    $type = OfficeWord::attr($pf, 'patternType');
                    if ($type !== '' && $type !== 'none') {
                        $c = $this->xColor($kid($pf, 'fgColor')) ?: ($type !== 'solid' ? $this->xColor($kid($pf, 'bgColor')) : '');
                    }
                } elseif ($gf = $kid($f, 'gradientFill')) {
                    foreach ($gf->getElementsByTagNameNS('*', 'color') as $gc) {
                        $c = $this->xColor($gc);
                        break;
                    }
                }
                $fills[] = $c;
            }
        }
        $borders = [];
        if ($bs = $kid($root, 'borders')) {
            foreach ($bs->childNodes as $b) {
                if (!$b instanceof DOMElement) {
                    continue;
                }
                $sides = [];
                foreach (['left' => 'start', 'right' => 'end', 'top' => '', 'bottom' => ''] as $side => $alt) {
                    $e = $kid($b, $side) ?? ($alt !== '' ? $kid($b, $alt) : null);
                    $st = $e ? OfficeWord::attr($e, 'style') : '';
                    $sides[] = $st !== '' && $st !== 'none' && isset(self::XLINES[$st]) ? [self::XLINES[$st], $this->xColor($kid($e, 'color')) ?: '#000000'] : null;
                }
                $borders[] = $sides;
            }
        }
        $this->fonts = $fonts;
        if (isset($fonts[0])) {
            $this->baseFont = ['sz' => $fonts[0]['sz'] ?: 11, 'name' => $fonts[0]['name'] ?: 'Calibri'];
        }
        if ($cx = $kid($root, 'cellXfs')) {
            foreach ($cx->childNodes as $x) {
                if (!$x instanceof DOMElement) {
                    continue;
                }
                $id = (int)OfficeWord::attr($x, 'numFmtId');
                $al = $kid($x, 'alignment');
                $h = $al ? OfficeWord::attr($al, 'horizontal') : '';
                $v = $al ? OfficeWord::attr($al, 'vertical') : '';
                $this->xf[] = ['font' => $fonts[(int)OfficeWord::attr($x, 'fontId')] ?? [], 'fmt' => $fmts[$id] ?? (self::BUILTIN[$id] ?? 'General'),
                    'fill' => $fills[(int)OfficeWord::attr($x, 'fillId')] ?? '', 'bd' => $borders[(int)OfficeWord::attr($x, 'borderId')] ?? [],
                    'al' => match ($h) { 'left' => 'left', 'center', 'centerContinuous' => 'center', 'right' => 'right', 'justify', 'distributed' => 'justify', default => '' },
                    'va' => match ($v) { 'top' => 'top', 'center' => 'middle', default => '' },
                    'wrap' => $al && in_array(strtolower(OfficeWord::attr($al, 'wrapText')), ['1', 'true'], true), 'indent' => $al ? (int)OfficeWord::attr($al, 'indent') : 0];
            }
        }
    }

    private static function kid(DOMElement $e, string $local): ?DOMElement
    {
        foreach ($e->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $local) {
                return $c;
            }
        }
        return null;
    }

    private function xStrings(ZipArchive $zip, string $path): void
    {
        $x = $zip->getFromName($path);
        if ($x === false) {
            return;
        }
        $rd = new XMLReader();
        if (!$rd->XML($x, null, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT)) {
            return;
        }
        $cur = null;
        $inPh = 0;
        while (@$rd->read()) {
            if ($rd->nodeType === XMLReader::ELEMENT) {
                if ($rd->localName === 'si') {
                    $cur = '';
                } elseif ($rd->localName === 'rPh') {
                    $inPh++;
                } elseif ($rd->localName === 't' && $cur !== null && !$inPh) {
                    $cur .= $rd->readString();
                }
            } elseif ($rd->nodeType === XMLReader::END_ELEMENT) {
                if ($rd->localName === 'si') {
                    $this->sst[] = (string)$cur;
                    $cur = null;
                } elseif ($rd->localName === 'rPh') {
                    $inPh--;
                }
            }
        }
        $rd->close();
    }

    private function xSheet(ZipArchive $zip, string $path, string $name): array
    {
        $sh = self::newSheet($name);
        $x = $zip->getFromName($path);
        if ($x === false) {
            return $sh;
        }
        $rd = new XMLReader();
        if (!$rd->XML($x, null, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT)) {
            return $sh;
        }
        $row = -1;
        $nextCol = 0;
        $cell = null;
        $drawRid = '';
        $ok = @$rd->read();
        for (; $ok; $ok = @$rd->read()) {
            if ($rd->nodeType === XMLReader::ELEMENT && $rd->localName === 'row' && $row + 1 >= self::MAX_ROWS) {
                // the rows past the limit are skipped whole; the merges and pictures after them are still read
                $sh['cut'] = true;
                while ($ok && $rd->nodeType === XMLReader::ELEMENT && $rd->localName === 'row') {
                    $ok = @$rd->next();
                    while ($ok && $rd->nodeType !== XMLReader::ELEMENT && $rd->nodeType !== XMLReader::END_ELEMENT) {
                        $ok = @$rd->read();
                    }
                }
                if (!$ok) {
                    break;
                }
            }
            if ($rd->nodeType === XMLReader::ELEMENT) {
                switch ($rd->localName) {
                    case 'sheetView':
                        if (in_array($rd->getAttribute('showGridLines'), ['0', 'false'], true)) {
                            $sh['grid'] = false;
                        }
                        break;
                    case 'sheetFormatPr':
                        if (($w = $rd->getAttribute('defaultColWidth')) !== null) {
                            $sh['defW'] = (int)round((float)$w * 7 + 5);
                        } elseif (($w = $rd->getAttribute('baseColWidth')) !== null) {
                            $sh['defW'] = (int)round(((float)$w + 0.71) * 7 + 5);
                        }
                        if (($h = $rd->getAttribute('defaultRowHeight')) !== null) {
                            $sh['defH'] = max(4, (int)round((float)$h * 4 / 3));
                        }
                        break;
                    case 'col':
                        $w = (float)$rd->getAttribute('width');
                        $hid = in_array($rd->getAttribute('hidden'), ['1', 'true'], true);
                        for ($c = (int)$rd->getAttribute('min') - 1, $e = min(self::MAX_COLS, (int)$rd->getAttribute('max')) - 1; $c <= $e; $c++) {
                            if ($w > 0) {
                                $sh['cols'][$c] = (int)round($w * 7 + 5);
                            }
                            if ($hid) {
                                $sh['hiddenCols'][$c] = true;
                            }
                        }
                        break;
                    case 'row':
                        $row = ($r = $rd->getAttribute('r')) !== null ? (int)$r - 1 : $row + 1;
                        $nextCol = 0;
                        if (($ht = $rd->getAttribute('ht')) !== null && in_array($rd->getAttribute('customHeight'), ['1', 'true'], true)) {
                            $sh['heights'][$row] = max(0, (int)round((float)$ht * 4 / 3));
                        } elseif ($ht !== null) {
                            $sh['heights'][$row] = max(0, (int)round((float)$ht * 4 / 3));
                        }
                        if (in_array($rd->getAttribute('hidden'), ['1', 'true'], true)) {
                            $sh['hiddenRows'][$row] = true;
                        }
                        break;
                    case 'c':
                        $ref = (string)$rd->getAttribute('r');
                        [$r, $c] = $ref !== '' ? self::ref($ref) : [$row, $nextCol];
                        $nextCol = $c + 1;
                        $cell = ['r' => $r, 'c' => $c, 't' => (string)$rd->getAttribute('t'), 's' => (int)$rd->getAttribute('s'), 'v' => null, 'is' => ''];
                        if ($rd->isEmptyElement) {
                            $this->cellPut($sh, $r, $c, '', 's', $cell['s'], true);
                            $cell = null;
                        }
                        break;
                    case 'v':
                        if ($cell) {
                            $cell['v'] = $rd->readString();
                        }
                        break;
                    case 'f':
                        if ($cell) {
                            $cell['f'] = $rd->readString();
                        }
                        break;
                    case 'is':
                        if ($cell) {
                            $t = '';
                            $inner = new DOMDocument();
                            $node = $rd->expand($inner);
                            if ($node) {
                                foreach ($node->getElementsByTagNameNS('*', 't') as $te) {
                                    if (!($te->parentNode instanceof DOMElement && $te->parentNode->localName === 'rPh')) {
                                        $t .= $te->textContent;
                                    }
                                }
                            }
                            $cell['is'] = $t;
                        }
                        break;
                    case 'mergeCell':
                        $ref = (string)$rd->getAttribute('ref');
                        if (str_contains($ref, ':')) {
                            [$a, $b] = explode(':', $ref, 2);
                            [$r1, $c1] = self::ref($a);
                            [$r2, $c2] = self::ref($b);
                            if ($r1 >= 0 && $r2 >= $r1 && $c2 >= $c1) {
                                $sh['merges'][] = [$r1, $r2, $c1, $c2];
                            }
                        }
                        break;
                    case 'drawing':
                        $drawRid = (string)($rd->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships') ?? $rd->getAttribute('r:id'));
                        break;
                }
            } elseif ($rd->nodeType === XMLReader::END_ELEMENT && $rd->localName === 'c' && $cell) {
                $this->xCell($sh, $cell);
                $cell = null;
            }
        }
        $rd->close();
        if ($drawRid !== '') {
            $rels = $this->rels($zip, $path);
            if (isset($rels[$drawRid]) && $rels[$drawRid]['path'] !== '') {
                $this->xDrawing($zip, $rels[$drawRid]['path'], $sh);
            }
        }
        return $sh;
    }

    private function xCell(array &$sh, array $c): void
    {
        $t = $c['t'];
        $v = $c['v'];
        $xf = $c['s'];
        switch ($t) {
            case 's':
                $this->cellPut($sh, $c['r'], $c['c'], (string)($this->sst[(int)$v] ?? ''), 's', $xf);
                break;
            case 'inlineStr':
                $this->cellPut($sh, $c['r'], $c['c'], $c['is'], 's', $xf);
                break;
            case 'str':
                $this->cellPut($sh, $c['r'], $c['c'], (string)$v, 's', $xf);
                break;
            case 'b':
                $this->cellPut($sh, $c['r'], $c['c'], $v === '1' ? 'TRUE' : 'FALSE', 'b', $xf);
                break;
            case 'e':
                $this->cellPut($sh, $c['r'], $c['c'], (string)$v, 'e', $xf);
                break;
            case 'd':
                $ts = strtotime((string)$v);
                $this->cellPut($sh, $c['r'], $c['c'], $ts ? $this->fmtNum($ts / 86400 + 25569, $xf) : (string)$v, 'n', $xf);
                break;
            default:
                if (($v === null || $v === '') && ($c['f'] ?? '') !== '') {
                    // a formula saved without its result (made by a program, not by Excel): the formula is shown
                    $this->cellPut($sh, $c['r'], $c['c'], '=' . $c['f'], 'f', $xf);
                    $this->notes['f'] = 'Some formulas were saved without their results; they show as formulas here. The page view calculates them.';
                } elseif ($v === null || $v === '') {
                    $this->cellPut($sh, $c['r'], $c['c'], '', 's', $xf, true);
                } elseif (is_numeric($v)) {
                    $this->cellPut($sh, $c['r'], $c['c'], $this->fmtNum((float)$v, $xf), 'n', $xf);
                } else {
                    $this->cellPut($sh, $c['r'], $c['c'], (string)$v, 's', $xf);
                }
        }
    }

    /** The pictures (and the places of the charts) of a sheet */
    private function xDrawing(ZipArchive $zip, string $path, array &$sh): void
    {
        $d = $this->dom($zip, $path);
        if (!$d) {
            return;
        }
        $rels = $this->rels($zip, $path);
        foreach ($d->documentElement->childNodes as $a) {
            if (!$a instanceof DOMElement || !in_array($a->localName, ['twoCellAnchor', 'oneCellAnchor', 'absoluteAnchor'], true)) {
                continue;
            }
            $from = self::kid($a, 'from');
            $to = self::kid($a, 'to');
            $pos = fn(?DOMElement $e) => $e ? [(int)self::kid($e, 'row')?->textContent, (int)self::kid($e, 'col')?->textContent, (int)self::kid($e, 'rowOff')?->textContent / 9525,
                (int)self::kid($e, 'colOff')?->textContent / 9525] : [0, 0, 0, 0];
            [$r, $c, $dy, $dx] = $pos($from);
            $ext = null;
            foreach ($a->getElementsByTagNameNS('*', 'ext') as $e) {
                if (OfficeWord::attr($e, 'cx') !== '') {
                    $ext = [(int)OfficeWord::attr($e, 'cx') / 9525, (int)OfficeWord::attr($e, 'cy') / 9525];
                    break;
                }
            }
            $pic = ['r' => $r, 'c' => $c, 'dxp' => $dx, 'dyp' => $dy, 'w' => $ext[0] ?? 0, 'h' => $ext[1] ?? 0, 'bytes' => '', 'chart' => false];
            if ($to && !$ext) {
                [$r2, $c2, $dy2, $dx2] = $pos($to);
                $pic += ['r2' => $r2, 'c2' => $c2, 'dyp2' => $dy2, 'dxp2' => $dx2];
            }
            $blip = $a->getElementsByTagNameNS('*', 'blip')->item(0);
            if ($blip instanceof DOMElement) {
                $rid = OfficeWord::attr($blip, 'embed');
                if (isset($rels[$rid]) && $rels[$rid]['path'] !== '') {
                    $pic['bytes'] = (string)$zip->getFromName($rels[$rid]['path']);
                }
            } elseif ($a->getElementsByTagNameNS('*', 'chart')->length) {
                $pic['chart'] = true;
                $this->notes[] = 'Charts show as grey boxes in the quick view; the page view draws them.';
            } else {
                continue;
            }
            $sh['pics'][] = $pic;
        }
    }

    // ================================================================== Excel binary workbook (.xlsb)

    /** The records of a BIFF12 part: [[type, data], ...] */
    private static function b12(string $d): array
    {
        $out = [];
        $p = 0;
        $n = strlen($d);
        while ($p < $n) {
            $t = ord($d[$p++]);
            if ($t & 0x80) {
                $t = ($t & 0x7F) | ((ord($d[$p++] ?? "\0") & 0x7F) << 7);
            }
            $len = 0;
            for ($k = 0; $k < 4; $k++) {
                $b = ord($d[$p++] ?? "\0");
                $len |= ($b & 0x7F) << (7 * $k);
                if (!($b & 0x80)) {
                    break;
                }
            }
            $out[] = [$t, substr($d, $p, $len)];
            $p += $len;
            if (count($out) > 2000000) {
                break;
            }
        }
        return $out;
    }

    private static function wstr(string $d, int $o, int &$next = 0): string
    {
        $cch = OfficePreview::u32($d, $o);
        if ($cch === 0xFFFFFFFF || $cch > 100000) {
            $next = $o + 4;
            return '';
        }
        $next = $o + 4 + 2 * $cch;
        return OfficePreview::utf16(substr($d, $o + 4, 2 * $cch));
    }

    private function xlsb(ZipArchive $zip): array
    {
        $rels = $this->rels($zip, 'xl/workbook.bin');
        foreach ($rels as $r) {
            if (str_ends_with($r['type'], '/theme')) {
                $this->xTheme($this->dom($zip, $r['path']));
            }
        }
        foreach ($rels as $r) {
            if (str_ends_with($r['type'], '/sharedStrings')) {
                foreach (self::b12((string)$zip->getFromName($r['path'])) as [$t, $d]) {
                    if ($t === 0x13) {
                        $this->sst[] = self::wstr($d, 1);
                    }
                }
            } elseif (str_ends_with($r['type'], '/styles')) {
                $this->bStyles((string)$zip->getFromName($r['path']));
            }
        }
        $sheets = [];
        foreach (self::b12((string)$zip->getFromName('xl/workbook.bin')) as [$t, $d]) {
            if ($t === 0x99) {
                $this->date1904 = (bool)(OfficePreview::u32($d, 0) & 1);
            }
            if ($t !== 0x9C) {
                continue;
            }
            $state = OfficePreview::u32($d, 0);
            // the relationship id follows the state and the tab id (an early version of the format had one more number)
            $rel = null;
            $name = '';
            foreach ([8, 12] as $o) {
                $next = 0;
                $rid = self::wstr($d, $o, $next);
                if (isset($rels[$rid])) {
                    $rel = $rels[$rid];
                    $name = self::wstr($d, $next);
                    break;
                }
            }
            if (!$rel || $rel['path'] === '') {
                continue;
            }
            if (str_ends_with($rel['type'], '/chartsheet')) {
                $sh = self::newSheet($name);
                $sh['note'] = 'This is a chart sheet: charts show in the page view.';
            } else {
                $sh = $this->bSheet((string)$zip->getFromName($rel['path']), $name);
            }
            $sh['hidden'] = $state > 0;
            $sheets[] = $sh;
        }
        $this->notes[] = 'Read as an Excel binary workbook (.xlsb): values, fonts and number formats (the page view shows the fills and borders).';
        return $sheets ?: [self::newSheet('Sheet1')];
    }

    private function bStyles(string $d): void
    {
        $fmts = [];
        $fonts = [];
        $inXf = false;
        foreach (self::b12($d) as [$t, $r]) {
            switch ($t) {
                case 0x2C:
                    $fmts[OfficePreview::u16($r, 0)] = self::wstr($r, 2);
                    break;
                case 0x2B:
                    $g = OfficePreview::u16($r, 2);
                    $fonts[] = ['sz' => OfficePreview::u16($r, 0) / 20, 'i' => (bool)($g & 2), 's' => (bool)($g & 8), 'b' => OfficePreview::u16($r, 4) >= 600,
                        'u' => OfficePreview::u8($r, 8) !== 0, 'c' => '', 'name' => self::wstr($r, 21)];
                    break;
                case 0x269:
                    $inXf = true;
                    break;
                case 0x26A:
                    $inXf = false;
                    break;
                case 0x2F:
                    if ($inXf) {
                        $id = OfficePreview::u16($r, 2);
                        $al = OfficePreview::u8($r, 12);
                        $this->xf[] = ['font' => $fonts[OfficePreview::u16($r, 4)] ?? [], 'fmt' => $fmts[$id] ?? (self::BUILTIN[$id] ?? 'General'), 'fill' => '', 'bd' => [],
                            'al' => [1 => 'left', 2 => 'center', 3 => 'right', 5 => 'justify'][$al & 7] ?? '', 'va' => '', 'wrap' => (bool)(OfficePreview::u8($r, 13) & 1), 'indent' => 0];
                    }
                    break;
            }
        }
        if (isset($fonts[0])) {
            $this->baseFont = ['sz' => $fonts[0]['sz'] ?: 11, 'name' => $fonts[0]['name'] ?: 'Calibri'];
        }
    }

    private function bSheet(string $d, string $name): array
    {
        $sh = self::newSheet($name);
        $row = 0;
        foreach (self::b12($d) as [$t, $r]) {
            if ($t === 0x00) {
                $row = OfficePreview::u32($r, 0);
                $h = OfficePreview::u16($r, 8);
                if ($h > 0) {
                    $sh['heights'][$row] = (int)round($h / 15);
                }
                if ($row >= self::MAX_ROWS) {
                    $sh['cut'] = true;
                    break;
                }
                continue;
            }
            if ($t >= 0x01 && $t <= 0x0B) {
                $c = OfficePreview::u32($r, 0);
                $xf = OfficePreview::u32($r, 4) & 0xFFFFFF;
                switch ($t) {
                    case 0x01: $this->cellPut($sh, $row, $c, '', 's', $xf, true); break;
                    case 0x02: $this->cellPut($sh, $row, $c, $this->fmtNum(self::rk(OfficePreview::u32($r, 8)), $xf), 'n', $xf); break;
                    case 0x03:
                    case 0x0B: $this->cellPut($sh, $row, $c, self::ERR[OfficePreview::u8($r, 8)] ?? '#ERR', 'e', $xf); break;
                    case 0x04:
                    case 0x0A: $this->cellPut($sh, $row, $c, OfficePreview::u8($r, 8) ? 'TRUE' : 'FALSE', 'b', $xf); break;
                    case 0x05:
                    case 0x09: $this->cellPut($sh, $row, $c, $this->fmtNum(OfficePreview::f64($r, 8), $xf), 'n', $xf); break;
                    case 0x06:
                    case 0x08: $this->cellPut($sh, $row, $c, self::wstr($r, 8), 's', $xf); break;
                    case 0x07: $this->cellPut($sh, $row, $c, (string)($this->sst[OfficePreview::u32($r, 8)] ?? ''), 's', $xf); break;
                }
            } elseif ($t === 0x3C) {
                $w = (int)round(OfficePreview::u32($r, 8) / 256 * 7 + 5);
                for ($c = OfficePreview::u32($r, 0), $e = min(self::MAX_COLS - 1, OfficePreview::u32($r, 4)); $c <= $e; $c++) {
                    $sh['cols'][$c] = $w;
                    if (OfficePreview::u16($r, 16) & 1) {
                        $sh['hiddenCols'][$c] = true;
                    }
                }
            } elseif ($t === 0xB0) {
                $sh['merges'][] = [OfficePreview::u32($r, 0), OfficePreview::u32($r, 4), OfficePreview::u32($r, 8), OfficePreview::u32($r, 12)];
            } elseif ($t === 0x89) {
                $sh['grid'] = (bool)(OfficePreview::u16($r, 0) & 0x0008) || !(OfficePreview::u16($r, 0) & 0xFFFF) ? $sh['grid'] : $sh['grid'];
            }
        }
        return $sh;
    }

    // ================================================================== Excel 2003 XML, web pages, CSV

    private function xml2003(string $x): array
    {
        $d = OfficeWord::loadXml($x);
        if (!$d) {
            throw new RuntimeException('the XML of the workbook is damaged');
        }
        $this->notes[] = 'Read as an Excel 2003 XML workbook.';
        $styles = [];
        foreach ($d->getElementsByTagNameNS('*', 'Style') as $s) {
            $st = ['font' => [], 'fill' => '', 'al' => '', 'va' => '', 'wrap' => false, 'bd' => [], 'fmt' => 'General', 'indent' => 0];
            foreach ($s->childNodes as $c) {
                if (!$c instanceof DOMElement) {
                    continue;
                }
                switch ($c->localName) {
                    case 'Font':
                        $st['font'] = ['b' => OfficeWord::attr($c, 'Bold') === '1', 'i' => OfficeWord::attr($c, 'Italic') === '1', 'u' => !in_array(OfficeWord::attr($c, 'Underline'), ['', 'None'], true),
                            's' => OfficeWord::attr($c, 'StrikeThrough') === '1', 'c' => OfficePreview::color(ltrim(OfficeWord::attr($c, 'Color'), '#')), 'sz' => (float)OfficeWord::attr($c, 'Size') ?: 10,
                            'name' => OfficeWord::attr($c, 'FontName')];
                        break;
                    case 'Interior':
                        if (OfficeWord::attr($c, 'Pattern') !== '' && OfficeWord::attr($c, 'Pattern') !== 'None') {
                            $st['fill'] = OfficePreview::color(ltrim(OfficeWord::attr($c, 'Color'), '#'));
                        }
                        break;
                    case 'Alignment':
                        $h = OfficeWord::attr($c, 'Horizontal');
                        $st['al'] = ['Left' => 'left', 'Center' => 'center', 'Right' => 'right', 'Justify' => 'justify', 'CenterAcrossSelection' => 'center'][$h] ?? '';
                        $st['va'] = ['Top' => 'top', 'Center' => 'middle'][OfficeWord::attr($c, 'Vertical')] ?? '';
                        $st['wrap'] = OfficeWord::attr($c, 'WrapText') === '1';
                        break;
                    case 'NumberFormat':
                        $f = OfficeWord::attr($c, 'Format');
                        $st['fmt'] = ['General Number' => 'General', 'Fixed' => '0.00', 'Standard' => '#,##0.00', 'Percent' => '0.00%', 'Scientific' => '0.00E+00', 'Short Date' => 'm/d/yyyy',
                            'Medium Date' => 'd-mmm-yy', 'Long Date' => 'mmmm d, yyyy', 'Short Time' => 'h:mm', 'Medium Time' => 'h:mm AM/PM', 'Long Time' => 'h:mm:ss', 'Currency' => '"$"#,##0.00_);("$"#,##0.00)',
                            'Yes/No' => 'General', 'True/False' => 'General', 'On/Off' => 'General', '' => 'General'][$f] ?? $f;
                        break;
                    case 'Borders':
                        foreach ($c->childNodes as $b) {
                            if ($b instanceof DOMElement && $b->localName === 'Border') {
                                $k = ['Left' => 0, 'Right' => 1, 'Top' => 2, 'Bottom' => 3][OfficeWord::attr($b, 'Position')] ?? null;
                                if ($k !== null && OfficeWord::attr($b, 'LineStyle') !== 'None') {
                                    $w = (int)OfficeWord::attr($b, 'Weight');
                                    $ls = OfficeWord::attr($b, 'LineStyle');
                                    $st['bd'][$k] = [$ls === 'Double' ? 6 : ($ls === 'Dash' ? 3 : ($ls === 'Dot' ? 4 : ($w >= 3 ? 5 : ($w === 2 ? 2 : 1)))), OfficePreview::color(ltrim(OfficeWord::attr($b, 'Color'), '#')) ?: '#000000'];
                                }
                            }
                        }
                        break;
                }
            }
            $styles[OfficeWord::attr($s, 'ID')] = $st;
        }
        $def = $styles['Default'] ?? null;
        if ($def && !empty($def['font']['name'])) {
            $this->baseFont = ['sz' => $def['font']['sz'] ?: 10, 'name' => $def['font']['name']];
        }
        $ids = array_keys($styles);
        $this->xf = array_values($styles);
        $index = array_flip($ids);
        $sheets = [];
        foreach ($d->getElementsByTagNameNS('*', 'Worksheet') as $ws) {
            $sh = self::newSheet(OfficeWord::attr($ws, 'Name'));
            $table = self::kid($ws, 'Table');
            if ($table) {
                $r = -1;
                $col = 0;
                foreach ($table->childNodes as $e) {
                    if (!$e instanceof DOMElement) {
                        continue;
                    }
                    if ($e->localName === 'Column') {
                        $i = OfficeWord::attr($e, 'Index');
                        $col = $i !== '' ? (int)$i - 1 : $col;
                        $w = (float)OfficeWord::attr($e, 'Width');
                        for ($k = 0, $span = (int)OfficeWord::attr($e, 'Span'); $k <= $span; $k++) {
                            if ($w > 0) {
                                $sh['cols'][$col] = (int)round($w * 4 / 3);
                            }
                            if (OfficeWord::attr($e, 'Hidden') === '1') {
                                $sh['hiddenCols'][$col] = true;
                            }
                            $col++;
                        }
                    } elseif ($e->localName === 'Row') {
                        $i = OfficeWord::attr($e, 'Index');
                        $r = $i !== '' ? (int)$i - 1 : $r + 1;
                        if (($h = OfficeWord::attr($e, 'Height')) !== '') {
                            $sh['heights'][$r] = (int)round((float)$h * 4 / 3);
                        }
                        $c = 0;
                        foreach ($e->childNodes as $ce) {
                            if (!$ce instanceof DOMElement || $ce->localName !== 'Cell') {
                                continue;
                            }
                            $ci = OfficeWord::attr($ce, 'Index');
                            $c = $ci !== '' ? (int)$ci - 1 : $c;
                            $xf = $index[OfficeWord::attr($ce, 'StyleID')] ?? ($index['Default'] ?? 0);
                            $data = self::kid($ce, 'Data');
                            $type = $data ? OfficeWord::attr($data, 'Type') : '';
                            $val = $data ? $data->textContent : '';
                            if ($type === 'Number' && is_numeric($val)) {
                                $this->cellPut($sh, $r, $c, $this->fmtNum((float)$val, $xf), 'n', $xf);
                            } elseif ($type === 'DateTime' && ($ts = strtotime($val)) !== false) {
                                $this->cellPut($sh, $r, $c, $this->fmtNum($ts / 86400 + 25569, $xf), 'n', $xf);
                            } elseif ($type === 'Boolean') {
                                $this->cellPut($sh, $r, $c, $val === '1' ? 'TRUE' : 'FALSE', 'b', $xf);
                            } else {
                                $this->cellPut($sh, $r, $c, $val, $type === 'Error' ? 'e' : 's', $xf, !$data);
                            }
                            $ma = (int)OfficeWord::attr($ce, 'MergeAcross');
                            $md = (int)OfficeWord::attr($ce, 'MergeDown');
                            if ($ma || $md) {
                                $sh['merges'][] = [$r, $r + $md, $c, $c + $ma];
                            }
                            $c += 1 + $ma;
                        }
                    }
                }
            }
            $sheets[] = $sh;
        }
        return $sheets ?: [self::newSheet('Sheet1')];
    }

    /** A workbook saved as a web page: every table a sheet (text, merges, bold, colours and alignment) */
    private function htmlTables(string $html): array
    {
        $html = OfficePreview::utf8($html);
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->notes[] = 'This workbook was saved as a web page; the quick view shows its tables.';
        $this->xf = [];
        $sheets = [];
        $k = 0;
        foreach ($d->getElementsByTagName('table') as $t) {
            for ($p = $t->parentNode; $p; $p = $p->parentNode) {
                if ($p instanceof DOMElement && strtolower($p->tagName) === 'table') {
                    continue 2;
                }
            }
            $sh = self::newSheet('Table ' . (++$k));
            $taken = [];
            $r = 0;
            foreach ($t->getElementsByTagName('tr') as $tr) {
                for ($p = $tr->parentNode; $p && !($p instanceof DOMElement && strtolower($p->tagName) === 'table'); $p = $p->parentNode) {
                }
                if ($p !== $t) {
                    continue;
                }
                $c = 0;
                foreach ($tr->childNodes as $td) {
                    if (!$td instanceof DOMElement || !in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                        continue;
                    }
                    while (isset($taken[$r][$c])) {
                        $c++;
                    }
                    $cs = max(1, min(50, (int)$td->getAttribute('colspan') ?: 1));
                    $rs = max(1, min(500, (int)$td->getAttribute('rowspan') ?: 1));
                    $style = strtolower($td->getAttribute('style'));
                    $bold = strtolower($td->tagName) === 'th' || $td->getElementsByTagName('b')->length || $td->getElementsByTagName('strong')->length || preg_match('/font-weight:\s*(bold|[6-9]00)/', $style);
                    $bg = preg_match('/^#?([0-9a-f]{6})$/i', $td->getAttribute('bgcolor'), $m) ? '#' . strtolower($m[1]) : (preg_match('/background(?:-color)?:\s*#([0-9a-f]{6})/i', $style, $m) ? '#' . strtolower($m[1]) : '');
                    $al = strtolower($td->getAttribute('align')) ?: (preg_match('/text-align:\s*(left|center|right)/', $style, $m) ? $m[1] : '');
                    $this->xf[] = ['font' => ['b' => (bool)$bold], 'fill' => $bg, 'al' => in_array($al, ['left', 'center', 'right'], true) ? $al : '', 'va' => '', 'wrap' => true, 'bd' => [], 'fmt' => 'General'];
                    $text = trim(preg_replace('/[ \t\r\n]+/u', ' ', $td->textContent));
                    $this->cellPut($sh, $r, $c, $text, is_numeric(str_replace(',', '', $text)) ? 'n' : 's', count($this->xf) - 1);
                    if ($cs > 1 || $rs > 1) {
                        $sh['merges'][] = [$r, $r + $rs - 1, $c, $c + $cs - 1];
                    }
                    for ($i = 0; $i < $rs; $i++) {
                        for ($j = 0; $j < $cs; $j++) {
                            $taken[$r + $i][$c + $j] = true;
                        }
                    }
                    $c += $cs;
                }
                $r++;
            }
            $sheets[] = $sh;
        }
        return $sheets ?: [self::newSheet('Sheet1')];
    }

    private function csv(string $file): array
    {
        $sh = self::newSheet(pathinfo($file, PATHINFO_FILENAME) ?: 'CSV');
        $sample = OfficePreview::utf8((string)file_get_contents($file, false, null, 0, 65536));
        $first = strtok($sample, "\n") ?: '';
        $best = ',';
        $bestN = -1;
        foreach ([',', ';', "\t", '|'] as $sep) {
            $n = substr_count($first, $sep);
            if ($n > $bestN) {
                [$best, $bestN] = [$sep, $n];
            }
        }
        $h = fopen('php://temp', 'w+');
        fwrite($h, OfficePreview::utf8((string)file_get_contents($file, false, null, 0, 30000000)));
        rewind($h);
        $this->xf = [['font' => [], 'fill' => '', 'al' => '', 'va' => '', 'wrap' => false, 'bd' => [], 'fmt' => 'General']];
        $r = 0;
        while (($row = fgetcsv($h, 0, $best, '"', '')) !== false) {
            foreach ($row as $c => $v) {
                $v = (string)$v;
                if ($v !== '') {
                    $this->cellPut($sh, $r, $c, $v, is_numeric($v) ? 'n' : 's', 0);
                }
            }
            if (++$r >= self::MAX_ROWS) {
                $sh['cut'] = true;
                break;
            }
        }
        fclose($h);
        return $sh;
    }

    // ================================================================== the sheets as HTML

    public static function html(array $wb): string
    {
        $sheets = $wb['sheets'];
        $font = $wb['font'] ?? ['sz' => 11, 'name' => 'Calibri'];
        $out = '<div class="op-sheets" data-op-sheets>';
        foreach ($wb['notes'] ?? [] as $n) {
            $out .= '<div class="op-note"><i class="bi bi-info-circle"></i> ' . h($n) . '</div>';
        }
        if (!$sheets) {
            return $out . '<div class="op-msg"><i class="bi bi-table"></i>This workbook has no sheets.</div></div>';
        }
        // the first sheet shown is the first one not hidden
        $active = 0;
        foreach ($sheets as $i => $s) {
            if (!$s['hidden']) {
                $active = $i;
                break;
            }
        }
        // the tabs of the sheets, as at the bottom of Excel (one tab too: it names the sheet)
        if ($sheets) {
            $out .= '<div class="op-tabs" role="tablist" aria-label="Sheets">';
            foreach ($sheets as $i => $s) {
                $out .= '<button type="button" role="tab" data-op-sheet="' . $i . '" aria-selected="' . ($i === $active ? 'true' : 'false') . '"' . ($i === $active ? ' class="active"' : '') . '>'
                    . ($s['hidden'] ? '<i class="bi bi-eye-slash" title="Hidden in Excel"></i>' : '') . h($s['name'] !== '' ? $s['name'] : 'Sheet ' . ($i + 1)) . '</button>';
            }
            $out .= '</div>';
        }
        foreach ($sheets as $i => $s) {
            $out .= '<section class="op-sheet" data-op-panel="' . $i . '"' . ($i === $active ? '' : ' hidden') . '>' . self::sheetHtml($s, $font) . '</section>';
        }
        return $out . '</div>';
    }

    /** The style of each cell is kept with the sheet: the HTML is made by a reader instance (see sheetHtml) */
    private static ?self $current = null;

    private static function sheetHtml(array $s, array $font): string
    {
        $me = self::$current;
        if ($s['note'] !== '' && !$s['rows']) {
            return '<div class="op-msg"><i class="bi bi-bar-chart"></i>' . h($s['note']) . '</div>';
        }
        // the used range: cells, merges and pictures
        $maxR = -1;
        $maxC = -1;
        foreach ($s['rows'] as $r => $cells) {
            foreach ($cells as $c => $cell) {
                if ($cell['v'] !== '' || ($me && $me->css($cell['x']) !== '' && !$cell['so']) || ($me && $cell['so'] && self::visibleStyle($me, $cell['x']))) {
                    $maxR = max($maxR, $r);
                    $maxC = max($maxC, $c);
                }
            }
        }
        foreach ($s['merges'] as $m) {
            $maxR = max($maxR, min(self::MAX_ROWS - 1, $m[1]));
            $maxC = max($maxC, min(self::MAX_COLS - 1, $m[3]));
        }
        foreach ($s['pics'] as $p) {
            // the whole picture is in the range: to its last cell, or as far as its size reaches
            $r2 = $p['r2'] ?? ($p['r'] + (int)ceil(($p['h'] ?? 0) / max(1, $s['defH'])));
            $c2 = $p['c2'] ?? ($p['c'] + (int)ceil(($p['w'] ?? 0) / max(1, $s['defW'])));
            $maxR = max($maxR, min(self::MAX_ROWS - 1, $r2));
            $maxC = max($maxC, min(self::MAX_COLS - 1, $c2));
        }
        // text running over empty cells past the last one used is shown whole
        if ($me) {
            foreach ($s['rows'] as $r => $cells) {
                foreach ($cells as $c => $cell) {
                    if ($cell['v'] === '' || $cell['k'] !== 's' || !empty($me->xf[$cell['x']]['wrap']) || !in_array($me->xf[$cell['x']]['al'] ?? '', ['', 'left'], true)) {
                        continue;
                    }
                    $sz = (float)($me->xf[$cell['x']]['font']['sz'] ?? 0) ?: (float)($font['sz'] ?? 11);
                    $need = mb_strlen($cell['v']) * $sz * 4 / 3 * 0.55 + 8;
                    $cc = $c;
                    while ($need > 0 && $cc < self::MAX_COLS - 1 && $cc - $c < 40) {
                        $need -= empty($s['hiddenCols'][$cc]) ? (int)($s['cols'][$cc] ?? $s['defW']) : 0;
                        if ($need > 0) {
                            $cc++;
                        }
                    }
                    $maxC = max($maxC, $cc - 1);
                }
            }
        }
        if ($maxR < 0) {
            return '<div class="op-msg"><i class="bi bi-grid-3x3"></i>This sheet is empty.</div>';
        }
        $maxR = min(self::MAX_ROWS - 1, $maxR + 1);
        $maxC = min(self::MAX_COLS - 1, $maxC + 1);
        // a very large sheet draws its first rows only (the browser stays quick)
        if (($maxR + 1) * ($maxC + 1) > 100000) {
            $maxR = max(50, intdiv(100000, $maxC + 1) - 1);
            $s['cut'] = true;
        }
        $colW = [];
        for ($c = 0; $c <= $maxC; $c++) {
            $colW[$c] = !empty($s['hiddenCols'][$c]) ? 0 : max(8, min(1200, (int)($s['cols'][$c] ?? $s['defW'])));
        }
        $rowH = fn(int $r) => !empty($s['hiddenRows'][$r]) ? 0 : max(6, min(800, (int)($s['heights'][$r] ?? $s['defH'])));
        // a number is never cut: its column is made wide enough (Excel would show ####)
        $base0 = (float)($font['sz'] ?? 11);
        $inMerge = [];
        foreach ($s['merges'] as [$r1, $r2, $c1, $c2]) {
            if ($c2 > $c1) {
                $inMerge[$r1][$c1] = true;
            }
        }
        foreach ($s['rows'] as $r => $cells) {
            if ($r > $maxR) {
                continue;
            }
            foreach ($cells as $c => $cell) {
                if ($c <= $maxC && $colW[$c] > 0 && ($cell['k'] === 'n' || $cell['k'] === 'b') && $cell['v'] !== '' && !isset($inMerge[$r][$c])) {
                    $sz = (float)($me->xf[$cell['x']]['font']['sz'] ?? $base0) ?: $base0;
                    $need = (int)ceil(strlen($cell['v']) * $sz * 4 / 3 * (!empty($me->xf[$cell['x']]['font']['b']) ? 0.6 : 0.55) + 10);
                    if ($need > $colW[$c]) {
                        $colW[$c] = min(400, $need);
                    }
                }
            }
        }
        // merged cells: the first cell spans, the others are covered
        $span = [];
        $cover = [];
        foreach ($s['merges'] as [$r1, $r2, $c1, $c2]) {
            $r2 = min($r2, $maxR);
            $c2 = min($c2, $maxC);
            if ($r1 > $maxR || $c1 > $maxC) {
                continue;
            }
            $span[$r1][$c1] = [$r2 - $r1 + 1, $c2 - $c1 + 1];
            for ($r = $r1; $r <= $r2; $r++) {
                for ($c = $c1; $c <= $c2; $c++) {
                    if ($r !== $r1 || $c !== $c1) {
                        $cover[$r][$c] = true;
                    }
                }
            }
        }
        // pictures at their cells
        $picAt = [];
        foreach ($s['pics'] as $p) {
            if ($p['r'] > $maxR || $p['c'] > $maxC) {
                continue;
            }
            $w = $p['w'] ?? 0;
            $h = $p['h'] ?? 0;
            $dx = $p['dxp'] ?? (($p['dx'] ?? 0) * ($colW[$p['c']] ?? 64));
            $dy = $p['dyp'] ?? (($p['dy'] ?? 0) * $rowH($p['r']));
            if (!$w && isset($p['c2'])) {
                $w = -$dx;
                for ($c = $p['c']; $c < $p['c2'] && $c <= $maxC + 50; $c++) {
                    $w += $colW[$c] ?? $s['defW'];
                }
                $w += $p['dxp2'] ?? (($p['dx2'] ?? 0) * ($colW[$p['c2']] ?? $s['defW']));
                $h = -$dy;
                for ($r = $p['r']; $r < $p['r2'] && $r <= $maxR + 200; $r++) {
                    $h += $rowH($r);
                }
                $h += $p['dyp2'] ?? (($p['dy2'] ?? 0) * $rowH($p['r2']));
            }
            $w = (int)max(8, min(2000, $w ?: 120));
            $h = (int)max(8, min(2000, $h ?: 80));
            $img = !empty($p['chart']) || $p['bytes'] === '' ? '' : OfficePreview::img($p['bytes'], 'width:' . $w . 'px;height:' . $h . 'px');
            if ($img === '') {
                $img = OfficePreview::picBox('width:' . $w . 'px;height:' . $h . 'px');
            }
            $picAt[$p['r']][$p['c']] = ($picAt[$p['r']][$p['c']] ?? '') . '<span class="op-anchor" style="left:' . (int)$dx . 'px;top:' . (int)$dy . 'px">' . $img . '</span>';
        }
        $pxPerPt = 4 / 3;
        $base = (float)($font['sz'] ?? 11);
        $total = 44 + array_sum($colW);
        $html = '<div class="op-sheet-info">' . ($s['cut'] ? 'The first ' . ($maxR + 1) . ' rows and ' . ($maxC + 1) . ' columns are shown; the page view or the download has the rest.' : 'Cells A1 to ' . self::colName($maxC) . ($maxR + 1)) . '</div>';
        $html .= '<div class="op-gridwrap"><table class="op-grid' . ($s['grid'] ? '' : ' op-nogrid') . '" style="width:' . $total . 'px;font-size:' . $base . 'pt;font-family:' . h(OfficePreview::font((string)($font['name'] ?? 'Calibri'))) . '"><colgroup><col style="width:44px">';
        for ($c = 0; $c <= $maxC; $c++) {
            $html .= '<col style="width:' . $colW[$c] . 'px' . ($colW[$c] === 0 ? ';visibility:collapse' : '') . '">';
        }
        $html .= '</colgroup><thead><tr><th class="op-rh"></th>';
        for ($c = 0; $c <= $maxC; $c++) {
            $html .= '<th' . ($colW[$c] === 0 ? ' style="visibility:collapse"' : '') . '>' . self::colName($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($r = 0; $r <= $maxR; $r++) {
            $hgt = $rowH($r);
            $html .= '<tr' . ($hgt === 0 ? ' hidden' : ' style="height:' . $hgt . 'px"') . '><th class="op-rh">' . ($r + 1) . '</th>';
            $cells = $s['rows'][$r] ?? [];
            for ($c = 0; $c <= $maxC; $c++) {
                if (isset($cover[$r][$c])) {
                    continue;
                }
                $cell = $cells[$c] ?? null;
                $attr = '';
                $css = $cell && $me ? $me->css($cell['x']) : '';
                $cls = [];
                $wrap = $cell && $me && !empty($me->xf[$cell['x']]['wrap']);
                if ($wrap) {
                    $cls[] = 'w';
                }
                $kind = $cell['k'] ?? 's';
                $al = $cell && $me ? ($me->xf[$cell['x']]['al'] ?? '') : '';
                if ($kind === 'n' && $al === '') {
                    $cls[] = 'n';
                } elseif ($kind === 'b' || $kind === 'e' || $kind === 'f') {
                    $cls[] = $kind === 'b' ? 'n' : $kind;
                }
                if (isset($span[$r][$c])) {
                    [$rs, $cs] = $span[$r][$c];
                    $attr .= ($rs > 1 ? ' rowspan="' . $rs . '"' : '') . ($cs > 1 ? ' colspan="' . $cs . '"' : '');
                    if ($rs > 1 && !str_contains($css, 'vertical-align')) {
                        $css .= 'vertical-align:bottom;';
                    }
                } elseif ($cell && $cell['v'] !== '' && !$wrap && $kind === 's' && in_array($al, ['', 'left'], true)) {
                    // text runs over the empty cells on its right, as in Excel
                    $sz = (float)($me->xf[$cell['x']]['font']['sz'] ?? $base) ?: $base;
                    $need = mb_strlen($cell['v']) * $sz * $pxPerPt * (!empty($me->xf[$cell['x']]['font']['b']) ? 0.58 : 0.52) + 8;
                    $have = $colW[$c];
                    $cs = 1;
                    while ($have < $need && $c + $cs <= $maxC && !isset($cover[$r][$c + $cs]) && !isset($span[$r][$c + $cs]) && !isset($picAt[$r][$c + $cs])
                        && (($cells[$c + $cs]['v'] ?? '') === '') && (!isset($cells[$c + $cs]) || !self::visibleStyle($me, $cells[$c + $cs]['x']))) {
                        $have += $colW[$c + $cs];
                        $cover[$r][$c + $cs] = true;
                        $cs++;
                    }
                    if ($cs > 1) {
                        $attr .= ' colspan="' . $cs . '"';
                    }
                }
                if (isset($picAt[$r][$c])) {
                    $cls[] = 'pa';
                    $css .= 'overflow:visible;';
                }
                $html .= '<td' . $attr . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . ($css !== '' ? ' style="' . h($css) . '"' : '') . '>' . ($picAt[$r][$c] ?? '') . h($cell['v'] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        if ($s['note'] !== '') {
            $html .= '<div class="op-more">' . h($s['note']) . '</div>';
        }
        return $html;
    }

    private static function visibleStyle(self $me, int $xf): bool
    {
        $x = $me->xf[$xf] ?? [];
        if (!empty($x['fill'])) {
            return true;
        }
        foreach ($x['bd'] ?? [] as $b) {
            if ($b && $b[0] > 0) {
                return true;
            }
        }
        return false;
    }
}
