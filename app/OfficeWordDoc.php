<?php
declare(strict_types=1);

/**
 * Word binary files (.doc, .dot):
 *  - Word 97, 2000, 2002 (XP) and 2003 (and the .doc of every later Word): the piece table, character and paragraph
 *    formats (bold, italic, underline, size, colour, font, highlight, alignment, indents, spacing, shading), styles and
 *    headings, lists with their numbers and bullets, tables (merged cells, borders, cell colours), fields (their results),
 *    headers and footers, pictures (in line and floating) and text boxes;
 *  - Word 6.0 and Word 95: the same text, formats, styles, numbered lists and tables, without pictures;
 *  - Word 1.x and 2.0 for Windows (readPlain): the text.
 * Files protected with a password stop with OfficeLockedException.
 */
final class OfficeWordDoc
{
    /** Tests set it to see a reading error instead of the recovered text */
    public static bool $strict = false;
    private const CP1252 = [0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030,
        0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013,
        0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178];
    /** The 16 colours of Word (ico) */
    private const ICO = [1 => '#000000', 2 => '#0000ff', 3 => '#00ffff', 4 => '#00ff00', 5 => '#ff00ff', 6 => '#ff0000', 7 => '#ffff00', 8 => '#ffffff', 9 => '#000080',
        10 => '#008080', 11 => '#008000', 12 => '#800080', 13 => '#800000', 14 => '#808000', 15 => '#808080', 16 => '#c0c0c0'];
    /** The operands of the Word 6 / 95 sprms: [bytes, 0 fixed / 1 a length byte first / 2 a length word first] */
    private const W6 = [0 => [0, 0], 2 => [1, 0], 3 => [0, 1], 4 => [1, 0], 5 => [1, 0], 6 => [1, 0], 7 => [1, 0], 8 => [1, 0], 9 => [1, 0], 10 => [1, 0], 11 => [1, 0], 12 => [0, 1],
        13 => [1, 0], 14 => [1, 0], 15 => [0, 1], 16 => [2, 0], 17 => [2, 0], 18 => [2, 0], 19 => [2, 0], 20 => [4, 0], 21 => [2, 0], 22 => [2, 0], 23 => [0, 1], 24 => [1, 0],
        25 => [1, 0], 26 => [2, 0], 27 => [2, 0], 28 => [2, 0], 29 => [1, 0], 30 => [2, 0], 31 => [2, 0], 32 => [2, 0], 33 => [2, 0], 34 => [2, 0], 35 => [2, 0], 36 => [2, 0],
        37 => [1, 0], 38 => [2, 0], 39 => [2, 0], 40 => [2, 0], 41 => [2, 0], 42 => [2, 0], 43 => [2, 0], 44 => [1, 0], 45 => [2, 0], 46 => [2, 0], 47 => [2, 0], 48 => [2, 0],
        49 => [2, 0], 50 => [1, 0], 51 => [1, 0], 52 => [0, 0], 64 => [0, 1], 65 => [1, 0], 66 => [1, 0], 67 => [1, 0], 68 => [0, 1], 69 => [2, 0], 70 => [4, 0], 71 => [1, 0],
        72 => [2, 0], 73 => [3, 0], 74 => [0, 1], 75 => [1, 0], 79 => [0, 1], 80 => [2, 0], 81 => [0, 1], 82 => [0, 1], 83 => [0, 0], 85 => [1, 0], 86 => [1, 0], 87 => [1, 0],
        88 => [1, 0], 89 => [1, 0], 90 => [1, 0], 91 => [1, 0], 92 => [1, 0], 93 => [2, 0], 94 => [1, 0], 95 => [3, 0], 96 => [2, 0], 97 => [2, 0], 98 => [1, 0], 99 => [2, 0],
        100 => [1, 0], 101 => [2, 0], 102 => [1, 0], 103 => [0, 1], 104 => [1, 0], 105 => [0, 1], 106 => [0, 1], 107 => [2, 0], 108 => [0, 1], 109 => [2, 0], 110 => [2, 0],
        111 => [2, 0], 112 => [2, 0], 113 => [0, 1], 115 => [0, 1], 116 => [0, 1], 117 => [1, 0], 118 => [1, 0], 119 => [1, 0], 120 => [0, 1], 121 => [2, 0], 122 => [2, 0],
        123 => [2, 0], 124 => [2, 0], 131 => [1, 0], 132 => [1, 0], 133 => [0, 1], 136 => [3, 0], 137 => [3, 0], 138 => [1, 0], 139 => [1, 0], 140 => [2, 0], 141 => [2, 0],
        142 => [1, 0], 143 => [1, 0], 144 => [2, 0], 145 => [2, 0], 146 => [1, 0], 147 => [1, 0], 148 => [2, 0], 149 => [2, 0], 150 => [1, 0], 151 => [1, 0], 152 => [1, 0],
        153 => [1, 0], 154 => [2, 0], 155 => [2, 0], 156 => [2, 0], 157 => [2, 0], 158 => [1, 0], 159 => [1, 0], 160 => [2, 0], 161 => [2, 0], 162 => [1, 0], 163 => [0, 0],
        164 => [2, 0], 165 => [2, 0], 166 => [2, 0], 167 => [2, 0], 168 => [2, 0], 169 => [2, 0], 170 => [2, 0], 171 => [2, 0], 179 => [0, 1], 181 => [0, 1], 182 => [2, 0],
        183 => [2, 0], 184 => [2, 0], 185 => [1, 0], 186 => [1, 0], 187 => [12, 0], 188 => [0, 2], 189 => [2, 0], 190 => [0, 2], 191 => [0, 1], 192 => [4, 0], 193 => [5, 0],
        194 => [4, 0], 195 => [2, 0], 196 => [4, 0], 197 => [2, 0], 198 => [2, 0], 199 => [5, 0], 200 => [4, 0], 207 => [0, 1]];

    private string $w = '';
    private string $t = '';
    private string $data = '';
    private bool $v8 = true;
    private int $fcBase = 0;
    private int $cbFcLcb = 0;
    private array $ccp = [];
    /** [cpStart, cpEnd, fc, 1 byte a character, grpprl of the piece] */
    private array $pieces = [];
    private array $codes = [];
    private array $chpx = [];
    private array $papx = [];
    private array $styles = [];
    private array $resolved = [];
    private array $fonts = [];
    private array $lists = [];
    private array $lfo = [];
    private array $counters = [];
    private array $anld = ['n' => 0, 'on' => false];
    private array $spa = ['main' => [], 'hdr' => []];
    private array $shapes = [];
    private array $pics = [];
    private array $txbx = ['main' => [], 'hdr' => []];
    private array $fld = [];
    private array $notes = [];
    private int $count = 0;
    private int $depth = 0;

    public static function read(string $data): array
    {
        $ole = new OleFile($data);
        if ($ole->has('EncryptionInfo') || $ole->has('EncryptedPackage')) {
            throw new OfficeLockedException('This Word file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
        }
        $w = $ole->read('WordDocument');
        if ($w === null || strlen($w) < 0x80) {
            return OfficeWord::recover($data, 'it holds no Word document');
        }
        $flags = OfficePreview::u16($w, 0x0A);
        if ($flags & 0x0100) {
            throw new OfficeLockedException('This Word file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
        }
        $me = new self();
        $me->w = $w;
        $me->v8 = OfficePreview::u16($w, 2) >= 0xC0;
        $me->data = (string)$ole->read('Data');
        $me->t = $me->v8 ? (string)$ole->read(($flags & 0x0200) ? '1Table' : '0Table') : $w;
        try {
            return $me->parse($flags);
        } catch (OfficeLockedException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (self::$strict) {
                throw $e;
            }
            return OfficeWord::recover($w, 'its layout could not be read: ' . $e->getMessage());
        }
    }

    /** Word 1.x / 2.0 for Windows (and other early Words outside a compound file): the text, its paragraphs and tables */
    public static function readPlain(string $data): array
    {
        $fcMin = OfficePreview::u32($data, 0x18);
        $ccp = OfficePreview::u32($data, 0x34);
        if ($fcMin < 0x20 || $fcMin >= strlen($data)) {
            return OfficeWord::recover($data, 'an early Word file');
        }
        $fcMac = OfficePreview::u32($data, 0x1C);
        if ($ccp <= 0 || $fcMin + $ccp > strlen($data)) {
            $ccp = max(0, min(strlen($data), $fcMac) - $fcMin);
        }
        $me = new self();
        $me->v8 = false;
        $me->w = $data;
        $me->codes = self::bytesToCodes(substr($data, $fcMin, $ccp));
        $me->pieces = [[0, count($me->codes), $fcMin, true, '']];
        $blocks = $me->story(0, count($me->codes), 'main', true);
        return OfficeWord::doc($blocks, [], [], [], ['sz' => 12, 'font' => 'Times New Roman'], ['Read as Word 1.x / 2.0 for Windows: the text and tables (the page view shows the formatting).']);
    }

    // ------------------------------------------------------------------ the file information block

    private function fcLcb(int $i): array
    {
        if ($this->v8) {
            if ($i >= $this->cbFcLcb) {
                return [0, 0];
            }
            $o = $this->fcBase + 8 * $i;
        } else {
            $o = $i;
        }
        return [OfficePreview::u32($this->w, $o), OfficePreview::u32($this->w, $o + 4)];
    }

    private function part(int $i): string
    {
        [$fc, $lcb] = $this->fcLcb($i);
        return $lcb > 0 && $lcb < 0x8000000 && $fc + $lcb <= strlen($this->t) ? substr($this->t, $fc, $lcb) : '';
    }

    private function parse(int $flags): array
    {
        $w = $this->w;
        if ($this->v8) {
            $csw = OfficePreview::u16($w, 32);
            $at = 34 + $csw * 2;
            $cslw = OfficePreview::u16($w, $at);
            $lw = $at + 2;
            foreach (['text' => 3, 'ftn' => 4, 'hdd' => 5, 'mcr' => 6, 'atn' => 7, 'edn' => 8, 'txbx' => 9, 'hdrtxbx' => 10] as $k => $i) {
                $this->ccp[$k] = $i < $cslw ? OfficePreview::u32($w, $lw + 4 * $i) : 0;
            }
            $this->cbFcLcb = OfficePreview::u16($w, $lw + $cslw * 4);
            $this->fcBase = $lw + $cslw * 4 + 2;
            $ix = ['clx' => 33, 'stsh' => 1, 'chpx' => 12, 'papx' => 13, 'ffn' => 15, 'hdd' => 11, 'sed' => 6, 'lst' => 73, 'lfo' => 74];
        } else {
            foreach (['text' => 0x34, 'ftn' => 0x38, 'hdd' => 0x3C, 'mcr' => 0x40, 'atn' => 0x44, 'edn' => 0x48, 'txbx' => 0x4C, 'hdrtxbx' => 0x50] as $k => $o) {
                $this->ccp[$k] = OfficePreview::u32($w, $o);
            }
            $ix = ['clx' => 0x160, 'stsh' => 0x60, 'chpx' => 0xB8, 'papx' => 0xC0, 'ffn' => 0xD0, 'hdd' => 0xB0, 'sed' => 0x88];
        }
        // the text: the piece table, or (Word 6 / 95 saved in full) one run from fcMin
        $clx = $this->part($ix['clx']);
        if ($this->v8 || ($flags & 0x0004) || $clx !== '') {
            $this->pieces($clx);
        }
        if (!$this->pieces) {
            $fcMin = OfficePreview::u32($w, 0x18);
            $n = array_sum($this->ccp) + 1;
            $this->pieces = [[0, $n, $fcMin, true, '']];
        }
        foreach ($this->pieces as $p) {
            $n = $p[1] - $p[0];
            $bytes = substr($w, $p[2], $p[3] ? $n : 2 * $n);
            $codes = $p[3] ? self::bytesToCodes($bytes) : (strlen($bytes) >= 2 ? array_values(unpack('v*', substr($bytes, 0, strlen($bytes) & ~1))) : []);
            foreach ($codes as $c) {
                $this->codes[] = $c;
            }
            for ($k = count($codes); $k < $n; $k++) {
                $this->codes[] = 13;
            }
        }
        // formats, styles, fonts, lists, drawings
        $this->fonts($this->part($ix['ffn']));
        $this->chpx = $this->bte($this->part($ix['chpx']), false);
        $this->papx = $this->bte($this->part($ix['papx']), true);
        $this->loadStyles($this->part($ix['stsh']));
        if ($this->v8) {
            // the levels of the lists follow their table (they are not counted in its length)
            [$fcLst, $lcbLst] = $this->fcLcb($ix['lst']);
            $this->loadLists($lcbLst > 0 ? (string)substr($this->t, $fcLst, 4000000) : '', $this->part($ix['lfo']));
            $this->loadDrawings();
        }
        $page = $this->page($this->part($ix['sed']));
        $main = $this->story(0, min($this->ccp['text'], count($this->codes)), 'main');
        [$header, $footer] = $this->headers($this->part($ix['hdd']));
        $base = $this->style(0)['c'];
        if (!$this->v8) {
            $this->notes[] = 'Read as Word 6.0 / 95: the text, formats, lists and tables (pictures show in the page view).';
        }
        return OfficeWord::doc($main, $header, $footer, $page, ['sz' => $base['sz'] ?? 10, 'font' => $base['font'] ?? ($this->fonts[0] ?? 'Times New Roman')], array_values(array_unique($this->notes)));
    }

    private static function bytesToCodes(string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }
        $out = [];
        foreach (unpack('C*', $bytes) as $b) {
            $out[] = self::CP1252[$b] ?? $b;
        }
        return $out;
    }

    private function pieces(string $clx): void
    {
        $pos = 0;
        $grp = [];
        $plc = '';
        $n = strlen($clx);
        while ($pos < $n) {
            $t = ord($clx[$pos]);
            if ($t === 1) {
                $cb = OfficePreview::u16($clx, $pos + 1);
                $grp[] = substr($clx, $pos + 3, $cb);
                $pos += 3 + $cb;
            } elseif ($t === 2) {
                $lcb = OfficePreview::u32($clx, $pos + 1);
                $plc = substr($clx, $pos + 5, $lcb);
                break;
            } else {
                break;
            }
        }
        $cnt = intdiv(strlen($plc) - 4, 12);
        if ($cnt <= 0) {
            return;
        }
        for ($i = 0; $i < $cnt; $i++) {
            $s = OfficePreview::u32($plc, 4 * $i);
            $e = OfficePreview::u32($plc, 4 * ($i + 1));
            $d = 4 * ($cnt + 1) + 8 * $i;
            $fc = OfficePreview::u32($plc, $d + 2);
            $prm = OfficePreview::u16($plc, $d + 6);
            if ($e <= $s) {
                continue;
            }
            if ($this->v8) {
                $comp = (bool)($fc & 0x40000000);
                $fc = $comp ? ($fc & 0x3FFFFFFF) >> 1 : $fc;
            } else {
                $comp = true;
            }
            $this->pieces[] = [$s, $e, $fc, $comp, ($prm & 1) ? ($grp[$prm >> 1] ?? '') : ''];
        }
    }

    private function piece(int $cp): ?array
    {
        $lo = 0;
        $hi = count($this->pieces) - 1;
        while ($lo <= $hi) {
            $m = ($lo + $hi) >> 1;
            $p = $this->pieces[$m];
            if ($cp < $p[0]) {
                $hi = $m - 1;
            } elseif ($cp >= $p[1]) {
                $lo = $m + 1;
            } else {
                return $p;
            }
        }
        return null;
    }

    private function fcOf(int $cp): int
    {
        $p = $this->piece($cp);
        return $p ? $p[2] + ($cp - $p[0]) * ($p[3] ? 1 : 2) : -1;
    }

    /** The runs of formatted text (CHPX) or of paragraphs (PAPX) from their pages: [fcStart, fcEnd, grpprl] / [fcStart, fcEnd, istd, grpprl] */
    private function bte(string $plc, bool $para): array
    {
        $size = $this->v8 ? 4 : 2;
        $n = intdiv(strlen($plc) - 4, 4 + $size);
        $pns = [];
        for ($i = 0; $i < $n; $i++) {
            $o = 4 * ($n + 1) + $size * $i;
            $pns[] = $size === 4 ? OfficePreview::u32($plc, $o) & 0x3FFFFF : OfficePreview::u16($plc, $o);
        }
        if (!$this->v8 && $pns) {
            // Word 6 / 95 may list only the first pages: the others follow them
            $cpn = OfficePreview::u16($this->w, $para ? 0x190 : 0x18E);
            for ($k = count($pns); $k < $cpn && $k < 4000; $k++) {
                $pns[] = $pns[count($pns) - 1] + 1;
            }
        }
        $runs = [];
        foreach (array_unique($pns) as $pn) {
            $page = substr($this->w, $pn * 512, 512);
            if (strlen($page) < 512) {
                continue;
            }
            $crun = ord($page[511]);
            if (4 * ($crun + 1) > 511) {
                continue;
            }
            for ($j = 0; $j < $crun; $j++) {
                $s = OfficePreview::u32($page, 4 * $j);
                $e = OfficePreview::u32($page, 4 * ($j + 1));
                if (!$para) {
                    $b = ord($page[4 * ($crun + 1) + $j] ?? "\0");
                    $runs[] = [$s, $e, $b ? substr($page, $b * 2 + 1, ord($page[$b * 2] ?? "\0")) : ''];
                    continue;
                }
                $bx = 4 * ($crun + 1) + ($this->v8 ? 13 : 7) * $j;
                $off = ord($page[$bx] ?? "\0") * 2;
                if ($off === 0) {
                    $runs[] = [$s, $e, 0, ''];
                    continue;
                }
                $cb = ord($page[$off] ?? "\0");
                if ($this->v8) {
                    [$len, $st] = $cb === 0 ? [2 * ord($page[$off + 1] ?? "\0"), $off + 2] : [2 * $cb - 1, $off + 1];
                } else {
                    [$len, $st] = [2 * $cb, $off + 1];
                }
                $runs[] = [$s, $e, OfficePreview::u16($page, $st), (string)substr($page, $st + 2, max(0, $len - 2))];
            }
        }
        usort($runs, fn($a, $b) => $a[0] <=> $b[0]);
        return $runs;
    }

    private static function find(array $runs, int $fc): int
    {
        $lo = 0;
        $hi = count($runs) - 1;
        while ($lo <= $hi) {
            $m = ($lo + $hi) >> 1;
            if ($fc < $runs[$m][0]) {
                $hi = $m - 1;
            } elseif ($fc >= $runs[$m][1]) {
                $lo = $m + 1;
            } else {
                return $m;
            }
        }
        return -1;
    }

    // ------------------------------------------------------------------ fonts, styles, lists, page

    private function fonts(string $s): void
    {
        if ($s === '') {
            return;
        }
        if ($this->v8) {
            $n = OfficePreview::u16($s, 0);
            $pos = 4;
            for ($i = 0; $i < $n && $pos < strlen($s); $i++) {
                $len = ord($s[$pos]) + 1;
                $name = OfficePreview::utf16(substr($s, $pos + 40, max(0, $len - 40)));
                $this->fonts[$i] = trim(explode("\0", $name)[0]);
                $pos += $len;
            }
        } else {
            $end = min(strlen($s), OfficePreview::u16($s, 0));
            $pos = 2;
            for ($i = 0; $pos < $end && $i < 500; $i++) {
                $len = ord($s[$pos]) + 1;
                $this->fonts[$i] = trim(OfficePreview::cp(explode("\0", substr($s, $pos + 6, max(0, $len - 6)))[0]));
                $pos += $len;
            }
        }
    }

    private function loadStyles(string $s): void
    {
        if (strlen($s) < 4) {
            return;
        }
        $cbStshi = OfficePreview::u16($s, 0);
        $stshi = substr($s, 2, $cbStshi);
        $cstd = OfficePreview::u16($stshi, 0);
        $cbBase = OfficePreview::u16($stshi, 2) ?: ($this->v8 ? 10 : 8);
        $pos = 2 + $cbStshi;
        for ($i = 0; $i < $cstd && $pos + 2 <= strlen($s); $i++) {
            $cb = OfficePreview::u16($s, $pos);
            $pos += 2;
            if ($cb === 0) {
                continue;
            }
            $std = substr($s, $pos, $cb);
            $pos += $cb;
            $w1 = OfficePreview::u16($std, 2);
            $p = $cbBase;
            if ($this->v8) {
                $cch = OfficePreview::u16($std, $p);
                $name = OfficePreview::utf16(substr($std, $p + 2, $cch * 2));
                $p += 2 + $cch * 2 + 2;
            } else {
                $cch = OfficePreview::u8($std, $p);
                $name = OfficePreview::cp(substr($std, $p + 1, $cch));
                $p += 1 + $cch + 1;
            }
            $upx = [];
            for ($k = 0, $cupx = OfficePreview::u16($std, 4) & 0x0F; $k < $cupx && $p + 2 <= strlen($std); $k++) {
                if ($p & 1) {
                    $p++;
                }
                $cbu = OfficePreview::u16($std, $p);
                $upx[] = substr($std, $p + 2, $cbu);
                $p += 2 + $cbu;
            }
            $stk = $w1 & 0x0F;
            $this->styles[$i] = ['sti' => OfficePreview::u16($std, 0) & 0x0FFF, 'name' => strtolower(trim($name)), 'base' => $w1 >> 4, 'type' => $stk,
                'p' => $stk === 1 ? (string)substr($upx[0] ?? '', 2) : '', 'c' => $stk === 1 ? ($upx[1] ?? '') : ($stk === 2 ? ($upx[0] ?? '') : '')];
        }
    }

    /** The properties of a style with those of the styles it is based on: ['p' => paragraph, 'c' => character, 'h' => heading level] */
    private function style(int $istd, int $depth = 0): array
    {
        if (isset($this->resolved[$istd])) {
            return $this->resolved[$istd];
        }
        $r = ['p' => [], 'c' => [], 'h' => 0];
        $this->resolved[$istd] = $r;
        $s = $this->styles[$istd] ?? null;
        if (!$s) {
            return $r;
        }
        if ($s['base'] !== 0x0FFF && $s['base'] !== $istd && $depth < 20) {
            $r = $this->style($s['base'], $depth + 1);
        }
        $r['p'] = $this->paraProps($s['p'], $r['p']);
        $r['c'] = $this->charProps($s['c'], $r['c'], $r['c']);
        if ($s['sti'] >= 1 && $s['sti'] <= 9) {
            $r['h'] = min(6, $s['sti']);
        } elseif ($s['sti'] === 62) {
            $r['h'] = 1;
        } elseif (preg_match('/^heading\s*([1-9])/', $s['name'], $m)) {
            $r['h'] = min(6, (int)$m[1]);
        }
        if (isset($r['p']['ol']) && $r['p']['ol'] < 6) {
            $r['h'] = $r['p']['ol'] + 1;
        }
        return $this->resolved[$istd] = $r;
    }

    private function loadLists(string $lst, string $lfo): void
    {
        $n = OfficePreview::u16($lst, 0);
        $defs = [];
        for ($i = 0; $i < $n && 2 + 28 * ($i + 1) <= strlen($lst); $i++) {
            $o = 2 + 28 * $i;
            $defs[] = ['lsid' => OfficePreview::i32($lst, $o), 'simple' => (bool)(OfficePreview::u8($lst, $o + 26) & 1)];
        }
        $pos = 2 + 28 * $n;
        foreach ($defs as $d) {
            $lvls = [];
            for ($l = 0, $cnt = $d['simple'] ? 1 : 9; $l < $cnt && $pos + 28 <= strlen($lst); $l++) {
                $start = OfficePreview::i32($lst, $pos);
                $nfc = OfficePreview::u8($lst, $pos + 4);
                $cbChpx = OfficePreview::u8($lst, $pos + 24);
                $cbPapx = OfficePreview::u8($lst, $pos + 25);
                $papx = substr($lst, $pos + 28, $cbPapx);
                $pos += 28 + $cbPapx + $cbChpx;
                $cch = OfficePreview::u16($lst, $pos);
                $codes = $cch > 0 ? array_values(unpack('v*', substr($lst, $pos + 2, 2 * $cch))) : [];
                $pos += 2 + 2 * $cch;
                $pp = $this->paraProps($papx, []);
                $lvls[$l] = ['start' => $start, 'nfc' => $nfc, 'codes' => $codes, 'ml' => $pp['ml'] ?? null, 'ti' => $pp['ti'] ?? null];
            }
            $this->lists[$d['lsid']] = $lvls;
        }
        $m = OfficePreview::u32($lfo, 0);
        for ($i = 0; $i < $m && $i < 5000 && 4 + 16 * ($i + 1) <= strlen($lfo); $i++) {
            $this->lfo[$i + 1] = OfficePreview::i32($lfo, 4 + 16 * $i);
        }
    }

    private function listLabel(int $ilfo, int $ilvl): ?array
    {
        $lsid = $this->lfo[$ilfo] ?? null;
        $lvls = $lsid !== null ? ($this->lists[$lsid] ?? null) : null;
        if (!$lvls) {
            return null;
        }
        $ilvl = max(0, min(8, $ilvl));
        $cur = $lvls[$ilvl] ?? $lvls[0];
        $this->counters[$lsid][$ilvl] = isset($this->counters[$lsid][$ilvl]) ? $this->counters[$lsid][$ilvl] + 1 : $cur['start'];
        for ($i = $ilvl + 1; $i < 9; $i++) {
            unset($this->counters[$lsid][$i]);
        }
        $out = '';
        foreach ($cur['codes'] as $c) {
            if ($c < 9) {
                $l = $lvls[$c] ?? $cur;
                $out .= OfficeWord::listNum(OfficeWord::NFC[$l['nfc']] ?? 'decimal', (int)($this->counters[$lsid][$c] ?? $l['start']));
            } else {
                $out .= mb_chr($c);
            }
        }
        if ($cur['nfc'] === 23) {
            $out = OfficeWord::symText($out, true);
            $out = trim($out) === '' ? '•' : $out;
        }
        return [$out, $cur['ml'], $cur['ti']];
    }

    /** The page size and margins of the first section */
    private function page(string $plc): array
    {
        $n = intdiv(strlen($plc) - 4, 16);
        if ($n <= 0) {
            return [];
        }
        $fcSepx = OfficePreview::u32($plc, 4 * ($n + 1) + 2);
        if ($fcSepx === 0xFFFFFFFF || $fcSepx + 2 > strlen($this->w)) {
            return [];
        }
        $g = substr($this->w, $fcSepx + 2, OfficePreview::u16($this->w, $fcSepx));
        $map = $this->v8 ? [0xB01F => 'w', 0xB020 => 'h', 0xB021 => 'ml', 0xB022 => 'mr', 0x9023 => 'mt', 0x9024 => 'mb'] : [164 => 'w', 165 => 'h', 166 => 'ml', 167 => 'mr', 168 => 'mt', 169 => 'mb'];
        $page = [];
        foreach ($this->sprms($g) as [$s, $o]) {
            if (isset($map[$s])) {
                $v = abs(OfficePreview::i16($o, 0));
                if ($v > 0 && $v < 32000) {
                    $page[$map[$s]] = $v;
                }
            }
        }
        return $page;
    }

    // ------------------------------------------------------------------ drawings (Word 97+)

    private function loadDrawings(): void
    {
        $dg = $this->part(50);
        if ($dg !== '') {
            $first = OfficePreview::art($dg)[0] ?? null;
            if ($first && $first['type'] === 0xF000) {
                $end = $first['pos'] + $first['len'];
                $this->pics = OfficePreview::bstore(substr($dg, 0, $end), $this->w);
                $pos = $end;
                while ($pos + 9 <= strlen($dg)) {
                    $r = OfficePreview::art($dg, $pos + 1)[0] ?? null;
                    if (!$r || $r['type'] !== 0xF002) {
                        break;
                    }
                    foreach (OfficePreview::shapes($dg, null, $pos + 1, $r['pos'] + $r['len']) as $sh) {
                        $this->shapes[$sh['spid']] = $sh;
                    }
                    $pos = $r['pos'] + $r['len'];
                }
            }
        }
        foreach ([40 => 'main', 41 => 'hdr'] as $i => $part) {
            $plc = $this->part($i);
            $n = intdiv(strlen($plc) - 4, 30);
            for ($k = 0; $k < $n; $k++) {
                $o = 4 * ($n + 1) + 26 * $k;
                $this->spa[$part][OfficePreview::u32($plc, 4 * $k)] = ['spid' => OfficePreview::u32($plc, $o), 'w' => OfficePreview::i32($plc, $o + 12) - OfficePreview::i32($plc, $o + 4),
                    'h' => OfficePreview::i32($plc, $o + 16) - OfficePreview::i32($plc, $o + 8), 'wr' => (OfficePreview::u16($plc, $o + 20) >> 5) & 0x0F];
            }
        }
        $txBase = $this->ccp['text'] + $this->ccp['ftn'] + $this->ccp['hdd'] + $this->ccp['mcr'] + $this->ccp['atn'] + $this->ccp['edn'];
        foreach ([56 => ['main', $txBase], 58 => ['hdr', $txBase + $this->ccp['txbx']]] as $i => [$part, $base]) {
            $plc = $this->part($i);
            $n = intdiv(strlen($plc) - 4, 26);
            for ($k = 0; $k < $n; $k++) {
                $lid = OfficePreview::i32($plc, 4 * ($n + 1) + 22 * $k + 14);
                $this->txbx[$part][$lid] = [$base + OfficePreview::u32($plc, 4 * $k), $base + OfficePreview::u32($plc, 4 * ($k + 1))];
            }
        }
    }

    /** A picture in line (character 1): its PICF in the Data stream, then the drawing with the picture */
    private function inlinePic(int $fcPic): string
    {
        $d = $this->data;
        if ($fcPic < 0 || $fcPic + 68 > strlen($d)) {
            return OfficePreview::picBox('width:96px;height:64px');
        }
        $lcb = OfficePreview::u32($d, $fcPic);
        $cbHeader = OfficePreview::u16($d, $fcPic + 4);
        $mm = OfficePreview::u16($d, $fcPic + 6);
        $mx = OfficePreview::u16($d, $fcPic + 32) ?: 1000;
        $my = OfficePreview::u16($d, $fcPic + 34) ?: 1000;
        $w = (int)round(OfficePreview::u16($d, $fcPic + 28) * $mx / 1000 / 15);
        $h = (int)round(OfficePreview::u16($d, $fcPic + 30) * $my / 1000 / 15);
        $w = max(8, min(1600, $w ?: 120));
        $h = max(8, min(2400, $h ?: 80));
        $start = $fcPic + $cbHeader;
        if ($mm === 0x66) {
            $start += 1 + OfficePreview::u8($d, $start);
        }
        if ($mm === 0x64 || $mm === 0x66) {
            $pics = OfficePreview::blips(substr($d, $start, max(0, $lcb - ($start - $fcPic))), 1);
            if ($pics) {
                $img = OfficePreview::img($pics[0], 'width:' . $w . 'px');
                if ($img !== '') {
                    return $img;
                }
            }
        }
        $this->notes[] = 'Pictures saved as Windows metafiles (WMF / EMF) show as grey boxes here; the page view shows them.';
        return OfficePreview::picBox('width:' . $w . 'px;height:' . $h . 'px');
    }

    // ------------------------------------------------------------------ properties

    /** [sprm, operand] of a property list */
    private function sprms(string $g): array
    {
        $out = [];
        $i = 0;
        $n = strlen($g);
        while ($i < $n) {
            if ($this->v8) {
                if ($i + 2 > $n) {
                    break;
                }
                $sprm = OfficePreview::u16($g, $i);
                $i += 2;
                $len = match ($sprm >> 13) {
                    0, 1 => 1, 2, 4, 5 => 2, 3 => 4, 7 => 3, default => -1,
                };
                if ($len < 0) {
                    if ($sprm === 0xD608 || $sprm === 0xD606) {
                        $len = OfficePreview::u16($g, $i) + 1;
                    } elseif ($sprm === 0xC615 && OfficePreview::u8($g, $i) === 255) {
                        $del = OfficePreview::u8($g, $i + 1);
                        $add = OfficePreview::u8($g, $i + 2 + 4 * $del);
                        $len = 3 + 4 * $del + 3 * $add;
                    } else {
                        $len = 1 + OfficePreview::u8($g, $i);
                    }
                }
            } else {
                $sprm = ord($g[$i]);
                $i++;
                $def = self::W6[$sprm] ?? null;
                if ($def === null) {
                    break;
                }
                $len = $def[1] === 1 ? 1 + OfficePreview::u8($g, $i) : ($def[1] === 2 ? OfficePreview::u16($g, $i) + 1 : $def[0]);
            }
            $out[] = [$sprm, (string)substr($g, $i, $len)];
            $i += $len;
        }
        return $out;
    }

    /** The tab stops after a change of tab stops (the ones deleted, then the ones added; positions in twips) */
    private static function tabStops(string $o, bool $close, array $stops): array
    {
        $p = 1;
        $del = OfficePreview::u8($o, $p++);
        $gone = [];
        for ($k = 0; $k < $del; $k++) {
            $gone[] = OfficePreview::i16($o, $p + 2 * $k);
        }
        $p += 2 * $del * ($close ? 2 : 1);
        $add = OfficePreview::u8($o, $p++);
        $stops = array_values(array_filter($stops, fn($x) => !in_array($x, $gone, true)));
        for ($k = 0; $k < $add; $k++) {
            $stops[] = OfficePreview::i16($o, $p + 2 * $k);
        }
        $stops = array_values(array_unique(array_filter($stops, fn($x) => $x > 0)));
        sort($stops);
        return $stops;
    }

    private static function toggle(int $v, bool $style): bool
    {
        return match ($v) {
            0 => false, 1 => true, 0x80 => $style, 0x81 => !$style, default => (bool)$v,
        };
    }

    private function charProps(string $g, array $p, array $style): array
    {
        if ($g === '') {
            return $p;
        }
        foreach ($this->sprms($g) as [$s, $o]) {
            $b = OfficePreview::u8($o, 0);
            if ($this->v8) {
                switch ($s) {
                    case 0x0835: $p['b'] = self::toggle($b, !empty($style['b'])); break;
                    case 0x0836: $p['i'] = self::toggle($b, !empty($style['i'])); break;
                    case 0x0837:
                    case 0x2A53: $p['s'] = self::toggle($b, !empty($style['s'])); break;
                    case 0x083A: $p['sc'] = self::toggle($b, !empty($style['sc'])); break;
                    case 0x083B: $p['caps'] = self::toggle($b, !empty($style['caps'])); break;
                    case 0x083C: $p['hide'] = self::toggle($b, !empty($style['hide'])); break;
                    case 0x0800: $p['del'] = (bool)$b; break;
                    case 0x2A3E: $p['u'] = $b !== 0; break;
                    case 0x4A43: $p['sz'] = OfficePreview::u16($o, 0) / 2; break;
                    case 0x2A42: $p['c'] = self::ICO[$b] ?? ''; break;
                    case 0x6870: $p['c'] = OfficePreview::colorref(OfficePreview::u32($o, 0)); break;
                    case 0x4A4F: $p['font'] = $this->fonts[OfficePreview::u16($o, 0)] ?? ($p['font'] ?? ''); break;
                    case 0x2A0C: $p['hl'] = self::ICO[$b] ?? ''; break;
                    case 0x2A48: $p['va'] = $b === 1 ? 'sup' : ($b === 2 ? 'sub' : ''); break;
                    case 0x0855: $p['spec'] = (bool)$b; break;
                    case 0x6A03: $p['pic'] = OfficePreview::u32($o, 0); break;
                    case 0x6A09: $p['sym'] = [OfficePreview::u16($o, 0), OfficePreview::u16($o, 2)]; break;
                    case 0x4A30:
                        $cs = $this->style(OfficePreview::u16($o, 0))['c'];
                        $p = array_merge($p, $cs);
                        break;
                    case 0xCA71:
                        $ipat = OfficePreview::u16($o, 9);
                        $c = OfficePreview::colorref(OfficePreview::u32($o, $ipat === 1 ? 1 : 5));
                        if ($ipat <= 1 && $c !== '' && $c !== '#ffffff') {
                            $p['hl'] = $c;
                        }
                        break;
                }
            } else {
                switch ($s) {
                    case 85: $p['b'] = self::toggle($b, !empty($style['b'])); break;
                    case 86: $p['i'] = self::toggle($b, !empty($style['i'])); break;
                    case 87: $p['s'] = self::toggle($b, !empty($style['s'])); break;
                    case 90: $p['sc'] = self::toggle($b, !empty($style['sc'])); break;
                    case 91: $p['caps'] = self::toggle($b, !empty($style['caps'])); break;
                    case 92: $p['hide'] = self::toggle($b, !empty($style['hide'])); break;
                    case 65: $p['del'] = (bool)$b; break;
                    case 94: $p['u'] = $b !== 0; break;
                    case 99: $p['sz'] = OfficePreview::u16($o, 0) / 2; break;
                    case 98: $p['c'] = self::ICO[$b & 0x1F] ?? ''; break;
                    case 93: $p['font'] = $this->fonts[OfficePreview::u16($o, 0)] ?? ($p['font'] ?? ''); break;
                    case 104: $p['va'] = $b === 1 ? 'sup' : ($b === 2 ? 'sub' : ''); break;
                    case 117: $p['spec'] = (bool)$b; break;
                    case 68: $p['pic'] = OfficePreview::u32($o, 1); break;
                    case 80:
                        $p = array_merge($p, $this->style(OfficePreview::u16($o, 0))['c']);
                        break;
                }
            }
        }
        return $p;
    }

    private function paraProps(string $g, array $p): array
    {
        if ($g === '') {
            return $p;
        }
        $jc = [0 => 'left', 1 => 'center', 2 => 'right', 3 => 'justify', 4 => 'justify', 5 => 'justify'];
        foreach ($this->sprms($g) as [$s, $o]) {
            $b = OfficePreview::u8($o, 0);
            if ($this->v8) {
                switch ($s) {
                    case 0x2403:
                    case 0x2461: $p['al'] = $jc[$b] ?? 'left'; break;
                    case 0x2416: $p['tbl'] = (bool)$b; break;
                    case 0x2417: $p['ttp'] = (bool)$b; break;
                    case 0x6649: $p['itap'] = OfficePreview::i32($o, 0); break;
                    case 0x244B: $p['icell'] = (bool)$b; break;
                    case 0x244C: $p['ittp'] = (bool)$b; break;
                    case 0x840F:
                    case 0x845E: $p['ml'] = OfficePreview::i16($o, 0); break;
                    case 0x8411:
                    case 0x8460: $p['ti'] = OfficePreview::i16($o, 0); break;
                    case 0xA413: $p['mt'] = OfficePreview::u16($o, 0); break;
                    case 0xA414: $p['mb'] = OfficePreview::u16($o, 0); break;
                    case 0x260A: $p['ilvl'] = $b; break;
                    case 0x460B: $p['ilfo'] = abs(OfficePreview::i16($o, 0)); break;
                    case 0x2640: $p['ol'] = $b; break;
                    case 0x2407: $p['pbb'] = (bool)$b; break;
                    case 0x442D:
                        $v = OfficePreview::u16($o, 0);
                        $ipat = $v >> 10;
                        $c = self::ICO[$ipat === 1 ? ($v & 0x1F) : (($v >> 5) & 0x1F)] ?? '';
                        $p['bg'] = $ipat <= 1 && $c !== '#ffffff' ? $c : '';
                        break;
                    case 0xC64D:
                        $ipat = OfficePreview::u16($o, 9);
                        $c = OfficePreview::colorref(OfficePreview::u32($o, $ipat === 1 ? 1 : 5));
                        $p['bg'] = $ipat <= 1 && $c !== '#ffffff' ? $c : '';
                        break;
                    case 0xC60D:
                    case 0xC615: $p['tabs'] = self::tabStops($o, $s === 0xC615, $p['tabs'] ?? []); break;
                    case 0xD608: $p['tdef'] = $o; break;
                    case 0xD605:
                    case 0xD613: $p['tbrd'] = [$s, $o]; break;
                    case 0xD612: $p['tshd'] = $o; break;
                    case 0xD609: $p['tshd80'] = $o; break;
                }
            } else {
                switch ($s) {
                    case 5: $p['al'] = $jc[$b] ?? 'left'; break;
                    case 24: $p['tbl'] = (bool)$b; break;
                    case 25: $p['ttp'] = (bool)$b; break;
                    case 17: $p['ml'] = OfficePreview::i16($o, 0); break;
                    case 19: $p['ti'] = OfficePreview::i16($o, 0); break;
                    case 21: $p['mt'] = OfficePreview::u16($o, 0); break;
                    case 22: $p['mb'] = OfficePreview::u16($o, 0); break;
                    case 9: $p['pbb'] = (bool)$b; break;
                    case 13: $p['nlvl'] = $b; break;
                    case 15: $p['tabs'] = self::tabStops($o, false, $p['tabs'] ?? []); break;
                    case 12: $p['anld'] = $o; break;
                    case 188:
                    case 190: $p['tdef6'] = $o; break;
                }
            }
        }
        return $p;
    }

    // ------------------------------------------------------------------ the text of a story

    /**
     * The blocks of a story (the main text, a header, a text box): paragraphs, tables and page breaks.
     * $plain: no formats are known (Word 1.x / 2.0).
     */
    private function story(int $a, int $b, string $part, bool $plain = false): array
    {
        $blocks = [];
        $tbl = null;
        $p0 = $a;
        $this->depth++;
        for ($cp = $a; $cp < $b; $cp++) {
            $c = $this->codes[$cp] ?? 13;
            if ($c !== 13 && $c !== 7 && $c !== 12) {
                continue;
            }
            if ($this->count++ > OfficeWord::MAX_BLOCKS) {
                $this->notes[] = 'The quick view shows the first part of this long document; the page view or the download has all of it.';
                break;
            }
            $this->paragraph($p0, $cp, $c, $part, $plain, $blocks, $tbl);
            $p0 = $cp + 1;
        }
        if ($p0 < $b && $this->count <= OfficeWord::MAX_BLOCKS) {
            $this->paragraph($p0, $b, 13, $part, $plain, $blocks, $tbl);
        }
        if ($tbl) {
            $blocks[] = $this->closeTable($tbl);
        }
        $this->depth--;
        return $blocks;
    }

    private function paragraph(int $p0, int $end, int $mark, string $part, bool $plain, array &$blocks, ?array &$tbl): void
    {
        $pp = [];
        $st = ['p' => [], 'c' => [], 'h' => 0];
        if (!$plain) {
            $fc = $this->fcOf($end < count($this->codes) ? $end : max($p0, $end - 1));
            $k = self::find($this->papx, $fc);
            if ($k >= 0) {
                $st = $this->style($this->papx[$k][2]);
                $pp = $this->paraProps($this->papx[$k][3], $st['p']);
            } else {
                $st = $this->style(0);
                $pp = $st['p'];
            }
        }
        $runs = [];
        $after = [];
        $chpxAt = -2;
        $cProps = $st['c'];
        $piece = null;
        for ($cp = $p0; $cp < $end; $cp++) {
            $c = $this->codes[$cp] ?? 0;
            if (!$plain) {
                if (!$piece || $cp < $piece[0] || $cp >= $piece[1]) {
                    $piece = $this->piece($cp);
                }
                $fc = $piece ? $piece[2] + ($cp - $piece[0]) * ($piece[3] ? 1 : 2) : -1;
                if ($chpxAt < 0 || !isset($this->chpx[$chpxAt]) || $fc < $this->chpx[$chpxAt][0] || $fc >= $this->chpx[$chpxAt][1]) {
                    $chpxAt = self::find($this->chpx, $fc);
                    $cProps = $this->charProps($chpxAt >= 0 ? $this->chpx[$chpxAt][2] : '', $st['c'], $st['c']);
                    if ($piece && $piece[4] !== '') {
                        $cProps = $this->charProps($piece[4], $cProps, $st['c']);
                    }
                }
            }
            // fields: the instructions are hidden, the results shown
            if ($c === 0x13) {
                $this->fld[] = false;
                continue;
            }
            if ($c === 0x14) {
                if ($this->fld) {
                    $this->fld[count($this->fld) - 1] = true;
                }
                continue;
            }
            if ($c === 0x15) {
                array_pop($this->fld);
                continue;
            }
            if (in_array(false, $this->fld, true) || !empty($cProps['hide']) || !empty($cProps['del'])) {
                continue;
            }
            $f = array_diff_key($cProps, ['spec' => 1, 'pic' => 1, 'sym' => 1, 'hide' => 1, 'del' => 1]);
            if (!empty($cProps['spec']) || ($plain && $c < 0x20 && $c !== 9 && $c !== 11)) {
                if ($c === 0x01 && !$plain) {
                    $runs[] = ['img' => $this->v8 ? $this->inlinePic((int)($cProps['pic'] ?? 0)) : OfficePreview::picBox('width:96px;height:64px')];
                } elseif ($c === 0x08 && !$plain) {
                    $this->drawn($cp, $part, $runs, $after);
                } elseif ($c === 0x28 && !empty($cProps['sym'])) {
                    $font = $this->fonts[$cProps['sym'][0]] ?? '';
                    $x = $cProps['sym'][1];
                    $runs[] = ['x' => $x >= 0xF000 || preg_match('/symbol|wingdings|webdings/i', $font) ? OfficePreview::sym($x) : mb_chr($x), 'f' => array_diff_key($f, ['font' => 1])];
                }
                continue;
            }
            if ($c === 9) {
                $runs[] = ['tab' => 1];
            } elseif ($c === 11 || $c === 14) {
                $runs[] = ['br' => 1];
            } elseif ($c === 0x1E) {
                $runs[] = ['x' => '‑', 'f' => $f];
            } elseif ($c < 0x20 || $c === 0x1F || ($c >= 0xD800 && $c <= 0xDFFF)) {
                continue;
            } else {
                $ch = mb_chr($c);
                if (($c >= 0xF000 && $c <= 0xF0FF) || (isset($f['font']) && preg_match('/symbol|wingdings|webdings/i', $f['font']))) {
                    $ch = OfficePreview::sym($c);
                    unset($f['font']);
                }
                $runs[] = ['x' => $ch, 'f' => $f];
            }
        }
        // the paragraph
        $h = $st['h'];
        if (isset($pp['ol']) && $pp['ol'] < 6) {
            $h = $pp['ol'] + 1;
        }
        $p = ['t' => 'p', 'h' => $h, 'al' => $pp['al'] ?? '', 'ml' => $pp['ml'] ?? 0, 'ti' => $pp['ti'] ?? 0, 'mt' => $pp['mt'] ?? null, 'mb' => $pp['mb'] ?? null,
            'bg' => $pp['bg'] ?? '', 'num' => '', 'runs' => $runs, 'tabs' => $pp['tabs'] ?? []];
        if (!empty($pp['ilfo'])) {
            $l = $this->listLabel((int)$pp['ilfo'], (int)($pp['ilvl'] ?? 0));
            if ($l) {
                $p['num'] = $l[0];
                $p['ml'] = $pp['ml'] ?? ($l[1] ?? 0);
                $p['ti'] = $pp['ti'] ?? ($l[2] ?? 0);
            }
        } elseif (!$this->v8 && (!empty($pp['nlvl']) || !empty($pp['anld']))) {
            $p['num'] = $this->anldLabel($pp);
        } elseif (!$this->v8) {
            $this->anld['on'] = false;
        }
        $out = [];
        if (!empty($pp['pbb'])) {
            $out[] = ['t' => 'pb'];
        }
        $out[] = $p;
        foreach ($after as $x) {
            $out[] = $x;
        }
        if ($mark === 12) {
            $out[] = ['t' => 'pb'];
        }
        // tables: cells end with character 7, rows with a row mark
        $inTable = !empty($pp['tbl']) || (($pp['itap'] ?? 0) > 0) || ($plain && $mark === 7);
        if ($inTable) {
            $tbl ??= ['rows' => [], 'cur' => [], 'cell' => []];
            $nested = ($pp['itap'] ?? 1) > 1;
            if ($mark === 7 && !empty($pp['ttp']) && !$nested) {
                $tbl['rows'][] = ['cells' => $tbl['cur'], 'tdef' => $pp['tdef'] ?? null, 'tdef6' => $pp['tdef6'] ?? null, 'tbrd' => $pp['tbrd'] ?? null,
                    'tshd' => $pp['tshd'] ?? null, 'tshd80' => $pp['tshd80'] ?? null];
                $tbl['cur'] = [];
                $tbl['cell'] = [];
            } elseif ($mark === 7 && !$nested) {
                array_push($tbl['cell'], ...$out);
                $tbl['cur'][] = $tbl['cell'];
                $tbl['cell'] = [];
                if ($plain && ($this->codes[$end + 1] ?? 0) === 13) {
                    // Word 2: a paragraph mark after a cell ends the row
                    $tbl['rows'][] = ['cells' => $tbl['cur']];
                    $tbl['cur'] = [];
                }
            } else {
                array_push($tbl['cell'], ...$out);
            }
            return;
        }
        if ($tbl) {
            if ($tbl['cur'] || $tbl['cell']) {
                if ($tbl['cell']) {
                    $tbl['cur'][] = $tbl['cell'];
                }
                $tbl['rows'][] = ['cells' => $tbl['cur']];
            }
            $blocks[] = $this->closeTable($tbl);
            $tbl = null;
        }
        array_push($blocks, ...$out);
    }

    /** The label of a numbered paragraph of Word 6 / 95 (its ANLD) */
    private function anldLabel(array $pp): string
    {
        $a = (string)($pp['anld'] ?? '');
        $lvl = (int)($pp['nlvl'] ?? 0);
        if ($a === '' && $lvl === 0) {
            return '';
        }
        $o = $a !== '' ? 1 : 0;
        $nfc = $a !== '' ? OfficePreview::u8($a, $o) : ($lvl === 11 ? 23 : 0);
        $before = OfficePreview::u8($a, $o + 1);
        $afterEnd = OfficePreview::u8($a, $o + 2);
        $start = $a !== '' ? max(0, OfficePreview::u16($a, $o + 10)) : 1;
        $chars = substr($a, $o + 20, 32);
        if (!$this->anld['on']) {
            $this->anld['n'] = $start ?: 1;
            $this->anld['on'] = true;
        } else {
            $this->anld['n']++;
        }
        $pre = OfficePreview::cp(substr($chars, 0, $before));
        $post = OfficePreview::cp(substr($chars, $before, max(0, $afterEnd - $before)));
        if ($nfc === 23 || $lvl === 11) {
            $t = OfficeWord::symText($pre . $post, true);
            return trim($t) === '' ? '•' : $t;
        }
        return $pre . OfficeWord::listNum(OfficeWord::NFC[$nfc] ?? 'decimal', $this->anld['n']) . $post;
    }

    /** A drawn object (character 8): a picture or a text box at its place */
    private function drawn(int $cp, string $part, array &$runs, array &$after): void
    {
        $rel = $part === 'hdr' ? $cp - $this->ccp['text'] - $this->ccp['ftn'] : $cp;
        $sp = $this->spa[$part][$rel] ?? null;
        if (!$sp) {
            return;
        }
        $spid = $sp['spid'];
        if (isset($this->txbx[$part][$spid]) && $this->depth < 4) {
            [$s, $e] = $this->txbx[$part][$spid];
            $blocks = $this->story($s, min($e, count($this->codes)), $part);
            while ($blocks && ($last = end($blocks)) && ($last['t'] ?? '') === 'p' && !$last['runs']) {
                array_pop($blocks);
            }
            if ($blocks) {
                $after[] = ['t' => 'box', 'b' => $blocks];
            }
            return;
        }
        $pib = $this->shapes[$spid]['pib'] ?? 0;
        if ($pib <= 0) {
            return;
        }
        $w = max(8, min(1600, (int)round($sp['w'] / 15)));
        $h = max(8, min(2400, (int)round($sp['h'] / 15)));
        $bytes = $this->pics[$pib] ?? '';
        $img = $bytes !== '' ? OfficePreview::img($bytes, 'width:' . $w . 'px') : '';
        if ($img === '') {
            $this->notes[] = 'Pictures saved as Windows metafiles (WMF / EMF) show as grey boxes here; the page view shows them.';
            $img = OfficePreview::picBox('width:' . $w . 'px;height:' . $h . 'px');
        }
        if ($sp['wr'] === 2 || $sp['wr'] === 4) {
            $runs[] = ['img' => '<span class="op-fl op-fl-l">' . $img . '</span>'];
        } else {
            $after[] = ['t' => 'img', 'html' => $img, 'al' => ''];
        }
    }

    /** A table from its rows: widths, merged cells, borders and colours from the row properties */
    private function closeTable(array $t): array
    {
        if ($t['cur'] || $t['cell']) {
            if ($t['cell']) {
                $t['cur'][] = $t['cell'];
            }
            $t['rows'][] = ['cells' => $t['cur']];
        }
        $rows = [];
        $border = false;
        foreach ($t['rows'] as $row) {
            $defs = $this->rowDefs($row);
            $border = $border || $defs['border'];
            $cells = [];
            $x = $defs['left'];
            foreach ($row['cells'] as $i => $blocks) {
                $d = $defs['cells'][$i] ?? ['w' => 0, 'merged' => false, 'vm' => 0, 'bg' => '', 'va' => ''];
                if ($d['merged'] && $cells) {
                    $cells[count($cells) - 1]['x1'] += $d['w'];
                    $x += $d['w'];
                    continue;
                }
                $cells[] = ['b' => $blocks, 'x0' => $x, 'x1' => $x + $d['w'], 'vm' => $d['vm'], 'bg' => $d['bg'], 'va' => $d['va'], 'bd' => false];
                $x += $d['w'];
            }
            $rows[] = $cells;
        }
        return OfficeWord::gridTable($rows, $border);
    }

    private function rowDefs(array $row): array
    {
        $out = ['border' => false, 'cells' => [], 'left' => 0];
        if (!empty($row['tdef'])) {
            $o = $row['tdef'];
            $n = OfficePreview::u8($o, 2);
            $x = [];
            for ($i = 0; $i <= $n; $i++) {
                $x[] = OfficePreview::i16($o, 3 + 2 * $i);
            }
            $out['left'] = $x[0] ?? 0;
            $tc = 3 + 2 * ($n + 1);
            for ($i = 0; $i < $n; $i++) {
                $g = $tc + 20 * $i + 20 <= strlen($o) ? OfficePreview::u16($o, $tc + 20 * $i) : 0;
                $bd = false;
                if ($tc + 20 * $i + 20 <= strlen($o)) {
                    for ($k = 0; $k < 4; $k++) {
                        $type = OfficePreview::u8($o, $tc + 20 * $i + 4 + 4 * $k + 1);
                        $bd = $bd || ($type !== 0 && $type !== 0xFF);
                    }
                }
                $out['border'] = $out['border'] || $bd;
                $va = ($g >> 7) & 3;
                $out['cells'][$i] = ['w' => max(0, ($x[$i + 1] ?? 0) - ($x[$i] ?? 0)), 'merged' => (bool)($g & 0x0002), 'vm' => ($g & 0x0020) ? (($g & 0x0040) ? 2 : 1) : 0,
                    'bg' => '', 'va' => $va === 1 ? 'middle' : ($va === 2 ? 'bottom' : '')];
            }
        } elseif (!empty($row['tdef6'])) {
            $o = $row['tdef6'];
            $n = OfficePreview::u8($o, 2);
            $x = [];
            for ($i = 0; $i <= $n; $i++) {
                $x[] = OfficePreview::i16($o, 3 + 2 * $i);
            }
            $out['left'] = $x[0] ?? 0;
            $tc = 3 + 2 * ($n + 1);
            for ($i = 0; $i < $n; $i++) {
                $g = OfficePreview::u16($o, $tc + 10 * $i);
                $bd = false;
                for ($k = 0; $k < 4; $k++) {
                    $bd = $bd || (OfficePreview::u16($o, $tc + 10 * $i + 2 + 2 * $k) & 0x1F) !== 0;
                }
                $out['border'] = $out['border'] || $bd;
                $out['cells'][$i] = ['w' => max(0, ($x[$i + 1] ?? 0) - ($x[$i] ?? 0)), 'merged' => (bool)($g & 0x0002), 'vm' => 0, 'bg' => '', 'va' => ''];
            }
        }
        if (!empty($row['tbrd'])) {
            [$s, $o] = $row['tbrd'];
            $size = $s === 0xD613 ? 8 : 4;
            $at = $s === 0xD613 ? 5 : 1;
            for ($k = 0; $k < 6; $k++) {
                $type = OfficePreview::u8($o, 1 + $size * $k + $at);
                $out['border'] = $out['border'] || ($type !== 0 && $type !== 0xFF && 1 + $size * $k + $at < strlen($o));
            }
        }
        if (!empty($row['tshd'])) {
            $o = $row['tshd'];
            for ($i = 0; 1 + 10 * $i + 10 <= strlen($o); $i++) {
                $ipat = OfficePreview::u16($o, 1 + 10 * $i + 8);
                $c = OfficePreview::colorref(OfficePreview::u32($o, 1 + 10 * $i + ($ipat === 1 ? 0 : 4)));
                if (isset($out['cells'][$i]) && $ipat <= 1 && $c !== '' && $c !== '#ffffff') {
                    $out['cells'][$i]['bg'] = $c;
                }
            }
        } elseif (!empty($row['tshd80'])) {
            $o = $row['tshd80'];
            for ($i = 0; 1 + 2 * $i + 2 <= strlen($o); $i++) {
                $v = OfficePreview::u16($o, 1 + 2 * $i);
                $ipat = $v >> 10;
                $c = self::ICO[$ipat === 1 ? ($v & 0x1F) : (($v >> 5) & 0x1F)] ?? '';
                if (isset($out['cells'][$i]) && $ipat <= 1 && $c !== '' && $c !== '#ffffff') {
                    $out['cells'][$i]['bg'] = $c;
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ headers and footers

    private function headers(string $plc): array
    {
        $n = intdiv(strlen($plc), 4);
        if ($n < 8 || !$this->ccp['hdd']) {
            return [[], []];
        }
        $cps = [];
        for ($i = 0; $i < $n; $i++) {
            $cps[] = OfficePreview::u32($plc, 4 * $i);
        }
        $base = $this->ccp['text'] + $this->ccp['ftn'];
        $pick = function (array $kinds) use ($cps, $n, $base) {
            if (!$this->v8) {
                return [];
            }
            for ($s = 0; 6 + 6 * $s + 5 < $n - 1; $s++) {
                foreach ($kinds as $k) {
                    $i = 6 + 6 * $s + $k;
                    $a = $cps[$i] ?? 0;
                    $b = $cps[$i + 1] ?? 0;
                    if ($b - $a > 1) {
                        $blocks = $this->story($base + $a, min(count($this->codes), $base + $b), 'hdr');
                        while ($blocks && ($last = end($blocks)) && ($last['t'] ?? '') === 'p' && !$last['runs'] && empty($last['num'])) {
                            array_pop($blocks);
                        }
                        if ($blocks) {
                            return $blocks;
                        }
                    }
                }
            }
            return [];
        };
        $this->fld = [];
        $header = $pick([1, 4, 0]);
        $this->fld = [];
        $footer = $pick([3, 5, 2]);
        return [$header, $footer];
    }
}
