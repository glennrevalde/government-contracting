<?php
declare(strict_types=1);

/**
 * PowerPoint binary files (.ppt, .pps, .pot):
 *  - PowerPoint 97, 2000, 2002 (XP), 2003 and the .ppt of every later PowerPoint: the persist directory of the last save,
 *    then every slide with its size, background, master objects, text boxes and placeholders (their text with paragraph
 *    and character formats: alignment, bullets, indent levels, bold / italic / underline, sizes, colours, fonts),
 *    pictures, fills and outlines, and the speaker notes;
 *  - PowerPoint 95: the text of the slides (its "Text_Content").
 * The model is the one of OfficeSlides, which draws it.
 */
final class OfficeSlidesPpt
{
    /** The default sizes of PowerPoint by text type and level: title, body (and its kinds), notes, other */
    private const SIZES = [0 => [44], 6 => [44], 1 => [32, 28, 24, 20, 20], 5 => [32, 28, 24, 20, 20], 7 => [28, 24, 20, 18, 18], 8 => [24, 20, 18, 16, 16], 2 => [12], 4 => [18]];

    private string $d = '';
    private string $pics = '';
    private array $persist = [];
    private array $fonts = [];
    private array $blips = [];
    private array $master = [];
    private int $w = 5760;
    private int $h = 4320;
    private array $notes = [];
    private array $scheme = ['#ffffff', '#000000', '#808080', '#000000', '#bbe0e3', '#333399', '#009999', '#99cc00'];

    public static function read(string $data): array
    {
        $ole = new OleFile($data);
        $doc = $ole->read('PowerPoint Document');
        $cu = (string)$ole->read('Current User');
        if ($ole->has('EncryptedSummary') || OfficePreview::u32($cu, 12) === 0xF3D1C4DF) {
            throw new OfficeLockedException('This PowerPoint file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
        }
        $me = new self();
        if ($doc !== null && OfficePreview::u16($cu, 2) === 0x0FF6) {
            $me->d = $doc;
            $me->pics = (string)$ole->read('Pictures');
            try {
                return $me->parse(OfficePreview::u32($cu, 16));
            } catch (OfficeLockedException $e) {
                throw $e;
            } catch (Throwable $e) {
                if (OfficeWordDoc::$strict) {
                    throw $e;
                }
            }
        }
        // PowerPoint 95 (and damaged files): the text of the slides
        $text = $ole->read('Text_Content');
        $blocks = [];
        if ($text !== null && $text !== '') {
            foreach (preg_split('/\r\s*\r/', OfficePreview::cp($text, 1252)) as $block) {
                $lines = array_values(array_filter(array_map('rtrim', preg_split('/\r|\n/', $block)), fn($l) => $l !== ''));
                foreach ($lines as $i => $l) {
                    $blocks[] = ['t' => 'p', 'h' => 0, 'runs' => [['x' => $l, 'f' => $i === 0 ? ['b' => true] : []]], 'mt' => $i === 0 ? 240 : 0, 'num' => $i > 0 ? '•' : '', 'ml' => $i > 0 ? 360 : 0, 'ti' => $i > 0 ? -240 : 0];
                }
            }
            $note = 'Read as PowerPoint 95: the text of the slides (the page view shows the slides themselves).';
        } else {
            $rec = OfficeWord::recover((string)$doc);
            $blocks = $rec['blocks'];
            $note = 'The quick view recovered the text of this presentation; the page view shows its slides.';
        }
        return ['w' => 5760, 'h' => 4320, 'slides' => [], 'notes' => [], 'doc' => OfficeWord::doc($blocks, [], [], [], ['sz' => 12, 'font' => 'Arial'], [$note])];
    }

    private function parse(int $offEdit): array
    {
        $d = $this->d;
        // the chain of saves, from the last back to the first; the persist directory is built from the first on
        $edits = [];
        $seen = [];
        for ($off = $offEdit; $off > 0 && $off + 36 <= strlen($d) && !isset($seen[$off]) && count($edits) < 500; $off = $edits[count($edits) - 1]['last']) {
            $seen[$off] = true;
            if (OfficePreview::u16($d, $off + 2) !== 0x0FF5) {
                break;
            }
            if (OfficePreview::u32($d, $off + 4) >= 32) {
                throw new OfficeLockedException('This PowerPoint file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
            }
            $edits[] = ['last' => OfficePreview::u32($d, $off + 16), 'dir' => OfficePreview::u32($d, $off + 20), 'doc' => OfficePreview::u32($d, $off + 24)];
        }
        if (!$edits) {
            throw new RuntimeException('no saved edit');
        }
        foreach (array_reverse($edits) as $e) {
            $p = $e['dir'] + 8;
            $end = min(strlen($d), $p + OfficePreview::u32($d, $e['dir'] + 4));
            while ($p + 4 <= $end) {
                $v = OfficePreview::u32($d, $p);
                $id = $v & 0xFFFFF;
                $n = $v >> 20;
                for ($k = 0; $k < $n; $k++) {
                    $this->persist[$id + $k] = OfficePreview::u32($d, $p + 4 + 4 * $k);
                }
                $p += 4 + 4 * $n;
            }
        }
        $docOff = $this->persist[$edits[0]['doc']] ?? null;
        if ($docOff === null || OfficePreview::u16($d, $docOff + 2) !== 0x03E8) {
            throw new RuntimeException('no document container');
        }
        $lists = [];
        foreach ($this->children($docOff) as $r) {
            switch ($r['type']) {
                case 0x03E9:
                    $this->w = max(100, OfficePreview::i32($d, $r['pos']));
                    $this->h = max(100, OfficePreview::i32($d, $r['pos'] + 4));
                    break;
                case 0x03F2:
                    foreach ($this->childrenAt($r) as $e) {
                        if ($e['type'] === 0x07D5) {
                            foreach ($this->childrenAt($e) as $f) {
                                if ($f['type'] === 0x0FB7) {
                                    $this->fonts[] = trim(explode("\0", OfficePreview::utf16(substr($d, $f['pos'], 64)))[0]);
                                }
                            }
                        }
                    }
                    break;
                case 0x040B:
                    $this->blips = OfficePreview::bstore(substr($d, $r['pos'], $r['len']), $this->pics);
                    break;
                case 0x0FF0:
                    $lists[$r['inst']] = $this->slideList($r);
                    break;
            }
        }
        // masters by their slide id; notes by theirs
        $masters = [];
        foreach ($lists[1] ?? [] as $m) {
            $masters[$m['id']] = $m['ref'];
        }
        $notesText = [];
        foreach ($lists[2] ?? [] as $n) {
            $notesText[$n['id']] = trim(implode("\n", array_map(fn($t) => $t['text'], array_filter($n['texts'], fn($t) => $t['type'] === 2))));
        }
        $slides = [];
        foreach ($lists[0] ?? [] as $s) {
            if (count($slides) >= OfficeSlides::MAX_SLIDES) {
                $this->notes[] = 'The quick view shows the first ' . OfficeSlides::MAX_SLIDES . ' slides.';
                break;
            }
            $slides[] = $this->slide($s, $masters, $notesText);
        }
        return ['w' => $this->w, 'h' => $this->h, 'wpt' => $this->w / 8, 'slides' => $slides, 'notes' => array_values(array_unique($this->notes))];
    }

    private function children(int $off): array
    {
        return OfficePreview::art($this->d, $off + 8, $off + 8 + OfficePreview::u32($this->d, $off + 4));
    }

    private function childrenAt(array $r): array
    {
        return OfficePreview::art($this->d, $r['pos'], $r['pos'] + $r['len']);
    }

    /** A list of slides, masters or notes: [['ref' => persist id, 'id' => slide id, 'texts' => [['type','text','style']]]] */
    private function slideList(array $r): array
    {
        $out = [];
        $cur = null;
        $t = null;
        foreach ($this->childrenAt($r) as $c) {
            $body = substr($this->d, $c['pos'], $c['len']);
            switch ($c['type']) {
                case 0x03F3:
                    if ($cur) {
                        if ($t) {
                            $cur['texts'][] = $t;
                        }
                        $out[] = $cur;
                    }
                    $cur = ['ref' => OfficePreview::u32($body, 0), 'id' => OfficePreview::u32($body, 12), 'texts' => []];
                    $t = null;
                    break;
                case 0x0F9F:
                    if ($cur && $t) {
                        $cur['texts'][] = $t;
                    }
                    $t = ['type' => OfficePreview::u32($body, 0), 'text' => '', 'style' => ''];
                    break;
                case 0x0FA0:
                    if ($t) {
                        $t['text'] = OfficePreview::utf16($body);
                    }
                    break;
                case 0x0FA8:
                    if ($t) {
                        $t['text'] = OfficePreview::cp($body, 1252);
                    }
                    break;
                case 0x0FA1:
                    if ($t) {
                        $t['style'] = $body;
                    }
                    break;
            }
        }
        if ($cur) {
            if ($t) {
                $cur['texts'][] = $t;
            }
            $out[] = $cur;
        }
        return $out;
    }

    // ------------------------------------------------------------------ a slide

    private function slide(array $s, array $masters, array $notesText): array
    {
        $off = $this->persist[$s['ref']] ?? null;
        $out = ['bg' => '#ffffff', 'shapes' => [], 'notes' => '', 'hidden' => false];
        if ($off === null || $off + 8 > strlen($this->d)) {
            return $out;
        }
        $flags = 0x7;
        $masterRef = 0;
        $notesRef = 0;
        $drawing = null;
        $scheme = null;
        foreach ($this->children($off) as $c) {
            if ($c['type'] === 0x03EF) {
                $masterRef = OfficePreview::u32($this->d, $c['pos'] + 12);
                $notesRef = OfficePreview::u32($this->d, $c['pos'] + 16);
                $flags = OfficePreview::u16($this->d, $c['pos'] + 20);
            } elseif ($c['type'] === 0x040C) {
                $drawing = $c;
            } elseif ($c['type'] === 0x07F0 && $c['inst'] === 1) {
                $scheme = $c;
            } elseif ($c['type'] === 0x03F9) {
                $out['hidden'] = (bool)(OfficePreview::u8($this->d, $c['pos'] + 8) & 0x04);
            }
        }
        // the master: its colours, its text styles, its background and its objects
        $m = $this->masterInfo($masters[$masterRef] ?? null);
        $this->scheme = $m['scheme'];
        if ($scheme && !($flags & 0x2)) {
            $this->scheme = $this->readScheme($scheme);
        }
        $out['bg'] = ($flags & 0x4) || $m['bg'] === null ? ($m['bg'] ?? $this->scheme[0]) : $m['bg'];
        if (($flags & 0x1) && $m['shapes']) {
            foreach ($m['shapes'] as $sh) {
                $out['shapes'][] = $sh;
            }
        }
        if ($drawing) {
            $used = [];
            $bg = null;
            foreach ($this->shapes($drawing, $s['texts'], $m['styles'], false, $used, $bg) as $sh) {
                $out['shapes'][] = $sh;
            }
            if ($bg !== null && !($flags & 0x4)) {
                $out['bg'] = $bg;
            }
        }
        $out['notes'] = $notesText[$notesRef] ?? '';
        return $out;
    }

    private function readScheme(array $c): array
    {
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $v = OfficePreview::u32($this->d, $c['pos'] + 4 * $i);
            $out[] = sprintf('#%02x%02x%02x', $v & 0xFF, ($v >> 8) & 0xFF, ($v >> 16) & 0xFF);
        }
        return $out;
    }

    private function masterInfo(?int $ref): array
    {
        if ($ref === null || isset($this->master[$ref])) {
            return $this->master[$ref ?? -1] ?? ['scheme' => $this->scheme, 'bg' => null, 'shapes' => [], 'styles' => []];
        }
        $info = ['scheme' => $this->scheme, 'bg' => null, 'shapes' => [], 'styles' => []];
        $this->master[$ref] = $info;
        $off = $this->persist[$ref] ?? null;
        if ($off === null) {
            return $info;
        }
        $drawing = null;
        foreach ($this->children($off) as $c) {
            if ($c['type'] === 0x07F0 && $c['inst'] === 1) {
                $info['scheme'] = $this->readScheme($c);
            } elseif ($c['type'] === 0x0FA3) {
                $info['styles'][$c['inst']] = $this->masterStyle($c);
            } elseif ($c['type'] === 0x040C) {
                $drawing = $c;
            }
        }
        $this->scheme = $info['scheme'];
        if ($drawing) {
            $used = [];
            $bg = null;
            $info['shapes'] = $this->shapes($drawing, [], $info['styles'], true, $used, $bg);
            $info['bg'] = $bg;
        }
        return $this->master[$ref] = $info;
    }

    /** The text styles of a master for one text type: [level => ['pf' => ..., 'cf' => ...]] */
    private function masterStyle(array $c): array
    {
        $d = $this->d;
        $p = $c['pos'];
        $end = $c['pos'] + $c['len'];
        $n = OfficePreview::u16($d, $p);
        $p += 2;
        $out = [];
        for ($l = 0; $l < $n && $l < 10 && $p < $end; $l++) {
            if ($c['inst'] >= 5) {
                $p += 2;
            }
            $pf = self::pf($d, $p);
            $cf = self::cf($d, $p);
            $out[$l] = ['pf' => $pf, 'cf' => $cf];
        }
        return $out;
    }

    // ------------------------------------------------------------------ text properties

    private static function pf(string $d, int &$p): array
    {
        $m = OfficePreview::u32($d, $p);
        $p += 4;
        $o = [];
        if ($m & 0x000F) {
            $o['bflags'] = OfficePreview::u16($d, $p);
            $p += 2;
        }
        if ($m & 0x0080) {
            $o['bchar'] = OfficePreview::u16($d, $p);
            $p += 2;
        }
        foreach ([0x0010 => 2, 0x0040 => 2, 0x0020 => 4] as $bit => $len) {
            if ($m & $bit) {
                $p += $len;
            }
        }
        if ($m & 0x0800) {
            $o['align'] = OfficePreview::u16($d, $p);
            $p += 2;
        }
        foreach ([0x1000, 0x2000, 0x4000] as $bit) {
            if ($m & $bit) {
                $p += 2;
            }
        }
        if ($m & 0x0100) {
            $o['left'] = OfficePreview::i16($d, $p);
            $p += 2;
        }
        if ($m & 0x0400) {
            $o['indent'] = OfficePreview::i16($d, $p);
            $p += 2;
        }
        if ($m & 0x8000) {
            $p += 2;
        }
        if ($m & 0x100000) {
            $p += 2 + 4 * OfficePreview::u16($d, $p);
        }
        if ($m & 0x10000) {
            $p += 2;
        }
        if ($m & 0xE0000) {
            $p += 2;
        }
        if ($m & 0x200000) {
            $p += 2;
        }
        return $o;
    }

    private static function cf(string $d, int &$p): array
    {
        $m = OfficePreview::u32($d, $p);
        $p += 4;
        $o = [];
        if ($m & 0x3EB7) {
            $st = OfficePreview::u16($d, $p);
            $p += 2;
            if ($m & 1) {
                $o['b'] = (bool)($st & 1);
            }
            if ($m & 2) {
                $o['i'] = (bool)($st & 2);
            }
            if ($m & 4) {
                $o['u'] = (bool)($st & 4);
            }
        }
        if ($m & 0x10000) {
            $o['font'] = OfficePreview::u16($d, $p);
            $p += 2;
        }
        foreach ([0x200000, 0x400000, 0x800000] as $bit) {
            if ($m & $bit) {
                $p += 2;
            }
        }
        if ($m & 0x20000) {
            $o['sz'] = OfficePreview::u16($d, $p);
            $p += 2;
        }
        if ($m & 0x40000) {
            $o['color'] = OfficePreview::u32($d, $p);
            $p += 4;
        }
        if ($m & 0x80000) {
            $o['pos'] = OfficePreview::i16($d, $p);
            $p += 2;
        }
        if ($m & 0x100000) {
            $p += 4;
        }
        foreach ([0x1000000, 0x2000000] as $bit) {
            if ($m & $bit) {
                $p += 2;
            }
        }
        if ($m & 0x4000000) {
            $p += 4;
        }
        return $o;
    }

    /** A colour of a text run (red, green, blue, index: 0xFE for the colour itself, 0 to 7 for the scheme) */
    private function textColor(int $v): string
    {
        $idx = ($v >> 24) & 0xFF;
        if ($idx === 0xFE) {
            return sprintf('#%02x%02x%02x', $v & 0xFF, ($v >> 8) & 0xFF, ($v >> 16) & 0xFF);
        }
        return $this->scheme[$idx] ?? '';
    }

    /** A colour of a drawing (OfficeArt COLORREF: scheme index or red, green, blue) */
    private function artColor(int $v): string
    {
        $flags = ($v >> 24) & 0xFF;
        if ($flags & 0x08) {
            return $this->scheme[$v & 0xFF] ?? '#000000';
        }
        if ($flags & 0x10) {
            return '#000000';
        }
        return sprintf('#%02x%02x%02x', $v & 0xFF, ($v >> 8) & 0xFF, ($v >> 16) & 0xFF);
    }

    /** Paragraphs of a text with its style atom and the master's styles for its type */
    private function paras(array $t, array $styles): array
    {
        $text = str_replace("\x0B", "\n", $t['text']);
        $type = $t['type'];
        $len = mb_strlen($text) + 1;
        $pruns = [];
        $cruns = [];
        $s = $t['style'];
        if ($s !== '') {
            $p = 0;
            $got = 0;
            while ($got < $len && $p + 6 <= strlen($s)) {
                $cnt = OfficePreview::u32($s, $p);
                $lvl = OfficePreview::u16($s, $p + 4);
                $p += 6;
                $pruns[] = [$cnt, $lvl, self::pf($s, $p)];
                $got += max(1, $cnt);
            }
            $got = 0;
            while ($got < $len && $p + 4 <= strlen($s)) {
                $cnt = OfficePreview::u32($s, $p);
                $p += 4;
                $cruns[] = [$cnt, self::cf($s, $p)];
                $got += max(1, $cnt);
            }
        }
        $title = in_array($type, [0, 6], true);
        $out = [];
        $pos = 0;
        $pi = 0;
        $pLeft = $pruns[0][0] ?? PHP_INT_MAX;
        $ci = 0;
        $cLeft = $cruns[0][0] ?? PHP_INT_MAX;
        foreach (explode("\r", $text) as $line) {
            $lvl = min(4, (int)($pruns[$pi][1] ?? 0));
            $pf = ($styles[$type][$lvl]['pf'] ?? []) + [];
            foreach ($pruns[$pi][2] ?? [] as $k => $v) {
                $pf[$k] = $v;
            }
            $baseCf = $styles[$type][$lvl]['cf'] ?? ($styles[$type][0]['cf'] ?? []);
            $defSz = self::SIZES[$type][min($lvl, count(self::SIZES[$type] ?? [18]) - 1)] ?? 18;
            $runs = [];
            foreach (mb_str_split($line) ?: [] as $ch) {
                $cf = $baseCf;
                foreach ($cruns[$ci][1] ?? [] as $k => $v) {
                    $cf[$k] = $v;
                }
                $color = isset($cf['color']) ? $this->textColor($cf['color']) : ($title ? $this->scheme[3] : $this->scheme[1]);
                $f = ['b' => $cf['b'] ?? false, 'i' => $cf['i'] ?? false, 'u' => $cf['u'] ?? false, 's' => false, 'sz' => $cf['sz'] ?? $defSz, 'c' => $color,
                    'font' => isset($cf['font']) ? ($this->fonts[$cf['font']] ?? '') : '', 'va' => isset($cf['pos']) && $cf['pos'] !== 0 ? ($cf['pos'] > 0 ? 'sup' : 'sub') : ''];
                $last = count($runs) - 1;
                $ch = (mb_ord($ch) >= 0xF000 && mb_ord($ch) <= 0xF0FF) || preg_match('/symbol|wingdings/i', $f['font']) ? OfficePreview::sym(mb_ord($ch)) : $ch;
                if ($last >= 0 && array_diff_key($runs[$last], ['t' => 1]) == $f) {
                    $runs[$last]['t'] .= $ch;
                } else {
                    $runs[] = ['t' => $ch] + $f;
                }
                if (--$cLeft <= 0) {
                    $ci++;
                    $cLeft = $cruns[$ci][0] ?? PHP_INT_MAX;
                }
            }
            $bullet = '';
            $bflags = $pf['bflags'] ?? null;
            if (($bflags !== null && ($bflags & 1)) || ($bflags === null && in_array($type, [1, 7, 8], true))) {
                $bullet = isset($pf['bchar']) && $pf['bchar'] > 0 ? OfficeWord::symText(mb_chr($pf['bchar']), true) : '•';
            }
            $al = ['left', 'center', 'right', 'justify', 'justify'][$pf['align'] ?? ($type === 6 || $type === 5 ? 1 : 0)] ?? 'left';
            // margins in master units (576 to the inch) as EMU (914400 to the inch)
            $out[] = ['al' => $al, 'ml' => (int)round(($pf['left'] ?? 0) * 1587.5), 'ti' => (int)round((($pf['indent'] ?? 0) - ($pf['left'] ?? 0)) * 1587.5), 'bu' => $runs ? $bullet : '',
                'runs' => $runs, 'sz' => $runs[0]['sz'] ?? $defSz];
            // the paragraph mark
            $n = mb_strlen($line) + 1;
            $pos += $n;
            if (--$cLeft <= 0) {
                $ci++;
                $cLeft = $cruns[$ci][0] ?? PHP_INT_MAX;
            }
            $pLeft -= $n;
            while ($pLeft <= 0 && isset($pruns[$pi + 1])) {
                $pi++;
                $pLeft += $pruns[$pi][0];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ shapes

    /** The shapes of a drawing; $bg gets the background's colour or picture */
    private function shapes(array $drawing, array $texts, array $styles, bool $isMaster, array &$used, ?string &$bg): array
    {
        $dg = null;
        foreach ($this->childrenAt($drawing) as $c) {
            if ($c['type'] === 0xF002) {
                $dg = $c;
            }
        }
        if (!$dg) {
            return [];
        }
        $anchor = function (string $a): ?array {
            if (strlen($a) >= 16) {
                return [OfficePreview::i32($a, 4), OfficePreview::i32($a, 0), OfficePreview::i32($a, 8), OfficePreview::i32($a, 12)];
            }
            if (strlen($a) >= 8) {
                return [OfficePreview::i16($a, 2), OfficePreview::i16($a, 0), OfficePreview::i16($a, 4), OfficePreview::i16($a, 6)];
            }
            return null;
        };
        $out = [];
        foreach (OfficePreview::shapes($this->d, $anchor, $dg['pos'], $dg['pos'] + $dg['len']) as $sh) {
            $props = $sh['props'];
            if ($sh['flags'] & 0x400) {
                // the background
                $type = $props[0x0180] ?? 0;
                if ($type === 3 && ($props[0x0186] ?? 0) > 0) {
                    $img = OfficePreview::img($this->blips[$props[0x0186]] ?? '');
                    $bg = preg_match('/src="([^"]+)"/', $img, $m) ? 'url(' . $m[1] . ') center/cover no-repeat' : null;
                } elseif (isset($props[0x0181])) {
                    $bg = $this->artColor($props[0x0181]);
                }
                continue;
            }
            if (($sh['flags'] & 0x1) || ($sh['flags'] & 0x4) || !$sh['rect']) {
                continue;
            }
            // placeholders: on a master they only hold prompts; on a slide they hold its text
            $phType = null;
            foreach (OfficePreview::art($sh['data']) as $r) {
                if ($r['type'] === 0x0BC3) {
                    $phType = OfficePreview::u8($sh['data'], $r['pos'] + 4);
                }
            }
            if ($isMaster && $phType !== null) {
                continue;
            }
            [$l, $t, $r, $b] = $sh['rect'];
            $shape = ['x' => $l / $this->w, 'y' => $t / $this->h, 'w' => ($r - $l) / $this->w, 'h' => ($b - $t) / $this->h, 'rot' => isset($props[0x0004]) ? OfficePreview::i32(pack('V', $props[0x0004]), 0) / 65536 : 0,
                'fill' => '', 'line' => '', 'round' => in_array($sh['type'], [3], true) ? '50%' : ($sh['type'] === 2 ? '12%' : ''), 'va' => '', 'pad' => [0.01, 0.005, 0.01, 0.005],
                'paras' => [], 'img' => '', 'table' => '', 'scale' => 1.0, 'nw' => ($props[0x0085] ?? 0) === 2];
            $auto = !in_array($sh['type'], [0, 75, 202], true) && $phType === null;
            $fb = $props[0x01BF] ?? null;
            $filled = $fb !== null ? (($fb >> 20) & 1 ? (bool)(($fb >> 4) & 1) : $auto) : ($auto && isset($props[0x0181]));
            if ($filled) {
                $shape['fill'] = isset($props[0x0181]) ? $this->artColor($props[0x0181]) : $this->scheme[4];
            }
            $lb = $props[0x01FF] ?? null;
            $lined = $lb !== null ? (($lb >> 19) & 1 ? (bool)(($lb >> 3) & 1) : $auto) : ($auto && isset($props[0x01C0]));
            if ($lined) {
                $w = max(1, min(8, (int)round(($props[0x01CB] ?? 9525) / 9525)));
                $shape['line'] = $w . 'px solid ' . (isset($props[0x01C0]) ? $this->artColor($props[0x01C0]) : $this->scheme[1]);
            }
            if ($sh['type'] === 20) {
                $shape['fill'] = $shape['line'] !== '' ? substr($shape['line'], strpos($shape['line'], '#')) : '#000000';
                $shape['line'] = '';
                if (abs($b - $t) < abs($r - $l)) {
                    $shape['h'] = max($shape['h'], 1.5 / 540);
                } else {
                    $shape['w'] = max($shape['w'], 1.5 / 960);
                }
            }
            if ($sh['pib'] > 0) {
                $bytes = $this->blips[$sh['pib']] ?? '';
                $img = $bytes !== '' ? OfficePreview::img($bytes) : '';
                if ($img === '') {
                    $this->notes[] = 'Pictures saved as Windows metafiles (WMF / EMF) show as grey boxes here; the page view shows them.';
                }
                $shape['img'] = $img !== '' ? $img : OfficePreview::picBox();
                $shape['line'] = '';
                $shape['fill'] = '';
            }
            // the text: in the shape, or in the slide's list (placeholders)
            if ($sh['textbox'] !== '') {
                $tb = $sh['textbox'];
                $t0 = null;
                foreach (OfficePreview::art($tb) as $rr) {
                    $body = substr($tb, $rr['pos'], $rr['len']);
                    if ($rr['type'] === 0x0F9F) {
                        $t0 = ['type' => OfficePreview::u32($body, 0), 'text' => '', 'style' => ''];
                    } elseif ($rr['type'] === 0x0FA0 && $t0) {
                        $t0['text'] = OfficePreview::utf16($body);
                    } elseif ($rr['type'] === 0x0FA8 && $t0) {
                        $t0['text'] = OfficePreview::cp($body, 1252);
                    } elseif ($rr['type'] === 0x0FA1 && $t0) {
                        $t0['style'] = $body;
                    } elseif ($rr['type'] === 0x0F9E) {
                        $i = OfficePreview::u32($body, 0);
                        if (isset($texts[$i])) {
                            $t0 = $texts[$i];
                            $used[$i] = true;
                        }
                    }
                }
                if ($t0 && $t0['text'] !== '') {
                    $shape['paras'] = $this->paras($t0, $styles);
                    $shape['va'] = in_array($t0['type'], [0, 6], true) ? 'm' : '';
                }
                if ($phType === null && !$filled && $sh['type'] === 202) {
                    $shape['va'] = '';
                }
            }
            if ($shape['paras'] || $shape['img'] !== '' || $shape['fill'] !== '' || $shape['line'] !== '') {
                $out[] = $shape;
            }
        }
        return $out;
    }
}
