<?php
declare(strict_types=1);

/**
 * The quick view of PowerPoint files, from the oldest to the newest:
 *  - PowerPoint 95 (.ppt): the text of its slides;
 *  - PowerPoint 97 to 2003 and the .ppt of every later PowerPoint (.ppt, .pps, .pot): every slide in its size with its
 *    background, text boxes and placeholders (titles, bullets, sizes, bold / italic / underline, colours, alignment),
 *    pictures, fills and outlines, and the shapes of its master;
 *  - PowerPoint 2007 to 365 (.pptx, .pptm, .ppsx, .potx, .potm): the same and more (layouts and masters, theme colours,
 *    groups, tables, rotated shapes, SmartArt text) and the speaker notes.
 * Slides keep their proportions at any width: places are in percent and font sizes follow the width of the slide.
 */
final class OfficeSlides
{
    public const MAX_SLIDES = 300;
    private const SCHEME = ['bg1' => 'lt1', 'tx1' => 'dk1', 'bg2' => 'lt2', 'tx2' => 'dk2'];
    private const PRST = ['black' => '000000', 'white' => 'ffffff', 'red' => 'ff0000', 'green' => '008000', 'blue' => '0000ff', 'yellow' => 'ffff00', 'gray' => '808080',
        'darkBlue' => '00008b', 'darkRed' => '8b0000', 'darkGreen' => '006400', 'orange' => 'ffa500', 'navy' => '000080', 'silver' => 'c0c0c0', 'lightGray' => 'd3d3d3'];

    private ?ZipArchive $zip = null;
    private array $theme = [];
    private array $fonts = ['major' => '', 'minor' => ''];
    private array $clrMap = [];
    private int $cx = 9144000;
    private int $cy = 6858000;
    private array $notes = [];
    private array $cache = [];

    public static function render(string $file, string $ext): string
    {
        return self::html(self::read($file, strtolower($ext)));
    }

    public static function read(string $file, string $ext): array
    {
        $head = (string)file_get_contents($file, false, null, 0, 8);
        if (str_starts_with($head, "PK\x03\x04")) {
            $me = new self();
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('the PowerPoint file is damaged');
            }
            $me->zip = $zip;
            try {
                return $me->pptx();
            } finally {
                $zip->close();
            }
        }
        if (OleFile::is($head)) {
            return OfficeSlidesPpt::read((string)file_get_contents($file));
        }
        throw new RuntimeException('it is not a PowerPoint file');
    }

    // ================================================================== PowerPoint 2007 to 365

    private function get(string $p): ?string
    {
        $s = $this->zip?->getFromName($p);
        return $s === false || $s === null ? null : $s;
    }

    private function dom(string $p): ?DOMDocument
    {
        if (array_key_exists($p, $this->cache)) {
            return $this->cache[$p];
        }
        $x = $this->get($p);
        return $this->cache[$p] = $x === null ? null : OfficeWord::loadXml($x);
    }

    private function rels(string $part): array
    {
        $dir = str_contains($part, '/') ? substr($part, 0, strrpos($part, '/')) : '';
        $d = $this->dom(($dir !== '' ? $dir . '/' : '') . '_rels/' . basename($part) . '.rels');
        $out = [];
        if ($d) {
            foreach ($d->documentElement->childNodes as $r) {
                if ($r instanceof DOMElement) {
                    $ext = strcasecmp(OfficeWord::attr($r, 'TargetMode'), 'External') === 0;
                    $out[OfficeWord::attr($r, 'Id')] = ['type' => OfficeWord::attr($r, 'Type'), 'path' => $ext ? '' : OfficeWord::resolve($dir, OfficeWord::attr($r, 'Target'))];
                }
            }
        }
        return $out;
    }

    private function relOf(string $part, string $suffix): ?string
    {
        foreach ($this->rels($part) as $r) {
            if (str_ends_with($r['type'], $suffix) && $r['path'] !== '') {
                return $r['path'];
            }
        }
        return null;
    }

    private static function kid(?DOMElement $e, string ...$path): ?DOMElement
    {
        foreach ($path as $local) {
            if (!$e) {
                return null;
            }
            $found = null;
            foreach ($e->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === $local) {
                    $found = $c;
                    break;
                }
            }
            $e = $found;
        }
        return $e;
    }

    /** The relationship id of an element (r:id; the element may also have an id of its own) */
    private static function rid(DOMElement $e): string
    {
        foreach ($e->attributes as $a) {
            if ($a->localName === 'id' && str_contains((string)$a->namespaceURI, 'relationships')) {
                return $a->value;
            }
        }
        return '';
    }

    private static function kids(?DOMElement $e, string $local): array
    {
        $out = [];
        if ($e) {
            foreach ($e->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === $local) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    private function pptx(): array
    {
        $pres = 'ppt/presentation.xml';
        foreach ($this->rels('') as $r) {
            if (str_ends_with($r['type'], '/officeDocument')) {
                $pres = $r['path'];
            }
        }
        $d = $this->dom($pres);
        if (!$d) {
            throw new RuntimeException('it holds no presentation');
        }
        $root = $d->documentElement;
        if ($sz = self::kid($root, 'sldSz')) {
            $this->cx = max(100000, (int)OfficeWord::attr($sz, 'cx'));
            $this->cy = max(100000, (int)OfficeWord::attr($sz, 'cy'));
        }
        $rels = $this->rels($pres);
        $slides = [];
        foreach (self::kids(self::kid($root, 'sldIdLst'), 'sldId') as $sid) {
            $rel = $rels[self::rid($sid)] ?? null;
            if (!$rel || $rel['path'] === '') {
                continue;
            }
            if (count($slides) >= self::MAX_SLIDES) {
                $this->notes[] = 'The quick view shows the first ' . self::MAX_SLIDES . ' slides.';
                break;
            }
            $slides[] = $this->slide($rel['path']);
        }
        return ['w' => $this->cx, 'h' => $this->cy, 'slides' => $slides, 'notes' => array_values(array_unique($this->notes))];
    }

    private function loadTheme(string $master): void
    {
        $this->theme = [];
        $t = $this->relOf($master, '/theme');
        $d = $t ? $this->dom($t) : null;
        if ($d) {
            $cs = $d->getElementsByTagNameNS('*', 'clrScheme')->item(0);
            if ($cs instanceof DOMElement) {
                foreach ($cs->childNodes as $c) {
                    if ($c instanceof DOMElement) {
                        foreach ($c->childNodes as $v) {
                            if ($v instanceof DOMElement) {
                                $this->theme[$c->localName] = strtolower($v->localName === 'sysClr' ? (OfficeWord::attr($v, 'lastClr') ?: '000000') : OfficeWord::attr($v, 'val'));
                            }
                        }
                    }
                }
            }
            foreach (['major' => 'majorFont', 'minor' => 'minorFont'] as $k => $tag) {
                $f = $d->getElementsByTagNameNS('*', $tag)->item(0);
                if ($f instanceof DOMElement && ($lat = self::kid($f, 'latin'))) {
                    $this->fonts[$k] = OfficeWord::attr($lat, 'typeface');
                }
            }
        }
        $md = $this->dom($master);
        $this->clrMap = [];
        if ($md && ($cm = self::kid($md->documentElement, 'clrMap'))) {
            foreach ($cm->attributes as $a) {
                $this->clrMap[$a->localName] = $a->value;
            }
        }
    }

    /** A DrawingML colour (with its changes: lumMod, lumOff, tint, shade, alpha) as CSS */
    private function color(?DOMElement $holder): string
    {
        if (!$holder) {
            return '';
        }
        foreach ($holder->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            $hex = match ($c->localName) {
                'srgbClr' => strtolower(OfficeWord::attr($c, 'val')),
                'schemeClr' => $this->scheme(OfficeWord::attr($c, 'val')),
                'sysClr' => strtolower(OfficeWord::attr($c, 'lastClr') ?: '000000'),
                'prstClr' => self::PRST[OfficeWord::attr($c, 'val')] ?? '000000',
                'scrgbClr' => sprintf('%02x%02x%02x', (int)OfficeWord::attr($c, 'r') * 255 / 100000, (int)OfficeWord::attr($c, 'g') * 255 / 100000, (int)OfficeWord::attr($c, 'b') * 255 / 100000),
                default => null,
            };
            if ($hex === null) {
                continue;
            }
            if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
                return '';
            }
            return self::modify($hex, $c);
        }
        return '';
    }

    private function scheme(string $v): string
    {
        if ($v === 'phClr') {
            $v = 'tx1';
        }
        $k = $this->clrMap[$v] ?? (self::SCHEME[$v] ?? $v);
        return $this->theme[$k] ?? ($this->theme[self::SCHEME[$k] ?? $k] ?? '000000');
    }

    private static function modify(string $hex, DOMElement $c): string
    {
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $alpha = 1.0;
        $lumMod = 1.0;
        $lumOff = 0.0;
        $lum = false;
        foreach ($c->childNodes as $m) {
            if (!$m instanceof DOMElement) {
                continue;
            }
            $v = (int)OfficeWord::attr($m, 'val') / 100000;
            switch ($m->localName) {
                case 'lumMod': $lumMod = $v; $lum = true; break;
                case 'lumOff': $lumOff = $v; $lum = true; break;
                case 'tint': [$r, $g, $b] = [$r + (255 - $r) * (1 - $v), $g + (255 - $g) * (1 - $v), $b + (255 - $b) * (1 - $v)]; break;
                case 'shade': [$r, $g, $b] = [$r * $v, $g * $v, $b * $v]; break;
                case 'alpha': $alpha = max(0, min(1, $v)); break;
            }
        }
        $out = sprintf('#%02x%02x%02x', (int)round(min(255, $r)), (int)round(min(255, $g)), (int)round(min(255, $b)));
        if ($lum) {
            $out = self::hsl($out, $lumMod, $lumOff);
        }
        if ($alpha < 1) {
            return sprintf('rgba(%d,%d,%d,%.2f)', hexdec(substr($out, 1, 2)), hexdec(substr($out, 3, 2)), hexdec(substr($out, 5, 2)), $alpha);
        }
        return $out;
    }

    /** Luminance changed as PowerPoint does (L × mod + off) */
    private static function hsl(string $hex, float $mod, float $off): string
    {
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $h = $s = 0.0;
        if ($max != $min) {
            $d = $max - $min;
            $s = $l > .5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            $h = ($max == $r ? ($g - $b) / $d + ($g < $b ? 6 : 0) : ($max == $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4)) / 6;
        }
        $l = max(0, min(1, $l * $mod + $off));
        $f = function ($p, $q, $t) {
            $t += $t < 0 ? 1 : ($t > 1 ? -1 : 0);
            return $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < .5 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));
        };
        if ($s == 0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            [$r, $g, $b] = [$f($p, $q, $h + 1 / 3), $f($p, $q, $h), $f($p, $q, $h - 1 / 3)];
        }
        return sprintf('#%02x%02x%02x', (int)round($r * 255), (int)round($g * 255), (int)round($b * 255));
    }

    /** A fill (solid, the first stop of a gradient, a picture) as CSS background */
    private function fill(?DOMElement $holder, string $part): ?string
    {
        if (!$holder) {
            return null;
        }
        foreach ($holder->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 'noFill':
                    return '';
                case 'solidFill':
                    return $this->color($c);
                case 'gradFill':
                    $stops = [];
                    foreach ($c->getElementsByTagNameNS('*', 'gs') as $gs) {
                        $col = $this->color($gs);
                        if ($col !== '') {
                            $stops[] = $col . ' ' . round((int)OfficeWord::attr($gs, 'pos') / 1000) . '%';
                        }
                    }
                    $lin = self::kid($c, 'lin');
                    $ang = $lin ? (int)OfficeWord::attr($lin, 'ang') / 60000 + 90 : 180;
                    return count($stops) >= 2 ? 'linear-gradient(' . (int)$ang . 'deg,' . implode(',', $stops) . ')' : ($stops ? explode(' ', $stops[0])[0] : '');
                case 'blipFill':
                    $img = $this->blip($c, $part);
                    return $img !== '' ? 'url(' . $img . ') center/cover no-repeat' : '';
                case 'grpFill':
                    return null;
            }
        }
        return null;
    }

    /** A picture of a part as a data: URL ('' when the browser cannot show it) */
    private function blip(DOMElement $holder, string $part): string
    {
        $b = $holder->getElementsByTagNameNS('*', 'blip')->item(0);
        if (!$b instanceof DOMElement) {
            return '';
        }
        $rels = $this->rels($part);
        $rel = $rels[OfficeWord::attr($b, 'embed')] ?? null;
        $bytes = $rel && $rel['path'] !== '' ? $this->get($rel['path']) : null;
        if ($bytes === null) {
            return '';
        }
        $tag = OfficePreview::img($bytes);
        return preg_match('/src="([^"]+)"/', $tag, $m) ? $m[1] : '';
    }

    // ------------------------------------------------------------------ one slide

    private function slide(string $path): array
    {
        $layout = $this->relOf($path, '/slideLayout');
        $master = $layout ? $this->relOf($layout, '/slideMaster') : null;
        if ($master) {
            $this->loadTheme($master);
        }
        $sd = $this->dom($path);
        $out = ['bg' => '#ffffff', 'shapes' => [], 'notes' => '', 'hidden' => false];
        if (!$sd) {
            return $out;
        }
        $sroot = $sd->documentElement;
        $out['hidden'] = in_array(OfficeWord::attr($sroot, 'show'), ['0', 'false'], true);
        $parts = array_values(array_filter([$master, $layout, $path]));
        // the background: of the slide, else of its layout, else of its master
        foreach (array_reverse($parts) as $p) {
            $bg = $this->background($p);
            if ($bg !== null) {
                $out['bg'] = $bg;
                break;
            }
        }
        // the placeholders of the layout and master give the places and text styles of the slide's
        $ph = ['layout' => $layout ? $this->placeholders($layout) : [], 'master' => $master ? $this->placeholders($master) : []];
        $txStyles = $master ? self::kid($this->dom($master)?->documentElement, 'txStyles') : null;
        $ctx = ['ph' => $ph, 'tx' => $txStyles];
        // shapes of the master and of the layout (not their placeholders), unless hidden by the slide
        $showMaster = !in_array(OfficeWord::attr($sroot, 'showMasterSp'), ['0', 'false'], true);
        $layoutRoot = $layout ? $this->dom($layout)?->documentElement : null;
        if ($showMaster && $master && !($layoutRoot && in_array(OfficeWord::attr($layoutRoot, 'showMasterSp'), ['0', 'false'], true))) {
            $this->tree(self::kid($this->dom($master)?->documentElement, 'cSld', 'spTree'), $master, $ctx, $out['shapes'], null, true);
        }
        if ($showMaster && $layout) {
            $this->tree(self::kid($layoutRoot, 'cSld', 'spTree'), $layout, $ctx, $out['shapes'], null, true);
        }
        $this->tree(self::kid($sroot, 'cSld', 'spTree'), $path, $ctx, $out['shapes'], null, false);
        // speaker notes
        $np = $this->relOf($path, '/notesSlide');
        if ($np && ($nd = $this->dom($np))) {
            $texts = [];
            foreach ($nd->getElementsByTagNameNS('*', 'sp') as $sp) {
                $phEl = $sp->getElementsByTagNameNS('*', 'ph')->item(0);
                if ($phEl instanceof DOMElement && OfficeWord::attr($phEl, 'type') === 'body') {
                    foreach ($sp->getElementsByTagNameNS('*', 'p') as $p) {
                        $t = '';
                        foreach ($p->getElementsByTagNameNS('*', 't') as $te) {
                            $t .= $te->textContent;
                        }
                        $texts[] = $t;
                    }
                }
            }
            $out['notes'] = trim(implode("\n", $texts));
        }
        return $out;
    }

    private function background(string $part): ?string
    {
        $d = $this->dom($part);
        $bg = $d ? self::kid($d->documentElement, 'cSld', 'bg') : null;
        if (!$bg) {
            return null;
        }
        if ($pr = self::kid($bg, 'bgPr')) {
            $f = $this->fill($pr, $part);
            return $f === null || $f === '' ? '#ffffff' : $f;
        }
        if ($ref = self::kid($bg, 'bgRef')) {
            $c = $this->color($ref);
            return $c !== '' ? $c : '#ffffff';
        }
        return null;
    }

    /** The placeholders of a layout or master: ['type:x' / 'idx:n' => the shape element] */
    private function placeholders(string $part): array
    {
        $d = $this->dom($part);
        $out = [];
        if (!$d) {
            return $out;
        }
        foreach ($d->getElementsByTagNameNS('*', 'sp') as $sp) {
            $ph = $sp->getElementsByTagNameNS('*', 'ph')->item(0);
            if (!$ph instanceof DOMElement) {
                continue;
            }
            $type = OfficeWord::attr($ph, 'type') ?: 'body';
            $idx = OfficeWord::attr($ph, 'idx');
            $out['type:' . $type] ??= $sp;
            if ($idx !== '') {
                $out['idx:' . $idx] ??= $sp;
            }
        }
        return $out;
    }

    private static function phOf(DOMElement $sp): ?array
    {
        $ph = self::kid($sp, 'nvSpPr', 'nvPr', 'ph') ?? self::kid($sp, 'nvPicPr', 'nvPr', 'ph') ?? self::kid($sp, 'nvGraphicFramePr', 'nvPr', 'ph');
        return $ph ? ['type' => OfficeWord::attr($ph, 'type') ?: 'body', 'idx' => OfficeWord::attr($ph, 'idx')] : null;
    }

    /** The shapes like a placeholder: of the layout, then of the master */
    private static function inherit(array $ctx, ?array $ph): array
    {
        if (!$ph) {
            return [];
        }
        $out = [];
        $alt = ['ctrTitle' => 'title', 'subTitle' => 'body', 'obj' => 'body'];
        foreach (['layout', 'master'] as $lv) {
            $map = $ctx['ph'][$lv];
            $el = $lv === 'layout' && $ph['idx'] !== '' ? ($map['idx:' . $ph['idx']] ?? null) : null;
            $el ??= $map['type:' . $ph['type']] ?? null;
            if (!$el && isset($alt[$ph['type']])) {
                $el = $map['type:' . $alt[$ph['type']]] ?? null;
            }
            if (!$el && $lv === 'master' && !in_array($ph['type'], ['title', 'ctrTitle', 'dt', 'ftr', 'sldNum', 'pic'], true)) {
                $el = $map['type:body'] ?? null;
            }
            if ($el) {
                $out[] = $el;
            }
        }
        return $out;
    }

    private static function xfrm(?DOMElement $holder): ?array
    {
        $x = self::kid($holder, 'xfrm');
        if (!$x) {
            return null;
        }
        $off = self::kid($x, 'off');
        $ext = self::kid($x, 'ext');
        if (!$off || !$ext) {
            return null;
        }
        $out = ['x' => (int)OfficeWord::attr($off, 'x'), 'y' => (int)OfficeWord::attr($off, 'y'), 'w' => (int)OfficeWord::attr($ext, 'cx'), 'h' => (int)OfficeWord::attr($ext, 'cy'),
            'rot' => (int)OfficeWord::attr($x, 'rot') / 60000];
        $co = self::kid($x, 'chOff');
        $ce = self::kid($x, 'chExt');
        if ($co && $ce) {
            $out['cx'] = (int)OfficeWord::attr($co, 'x');
            $out['cy'] = (int)OfficeWord::attr($co, 'y');
            $out['cw'] = max(1, (int)OfficeWord::attr($ce, 'cx'));
            $out['ch'] = max(1, (int)OfficeWord::attr($ce, 'cy'));
        }
        return $out;
    }

    /** The shapes of a tree, with the place of each in the units of the slide ($map: the frame of the group they are in) */
    private function tree(?DOMElement $tree, string $part, array $ctx, array &$out, ?array $map, bool $decorOnly, int $depth = 0): void
    {
        if (!$tree || $depth > 12) {
            return;
        }
        foreach ($tree->childNodes as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            if ($el->localName === 'AlternateContent') {
                $ch = self::kid($el, 'Choice') ?? self::kid($el, 'Fallback');
                if ($ch) {
                    $this->tree($ch, $part, $ctx, $out, $map, $decorOnly, $depth + 1);
                }
                continue;
            }
            if (!in_array($el->localName, ['sp', 'pic', 'graphicFrame', 'grpSp', 'cxnSp'], true)) {
                continue;
            }
            $nv = self::kid($el, 'nvSpPr') ?? self::kid($el, 'nvPicPr') ?? self::kid($el, 'nvGraphicFramePr') ?? self::kid($el, 'nvGrpSpPr') ?? self::kid($el, 'nvCxnSpPr');
            $cnv = $nv ? (self::kid($nv, 'cNvPr')) : null;
            if ($cnv && in_array(OfficeWord::attr($cnv, 'hidden'), ['1', 'true'], true)) {
                continue;
            }
            $ph = self::phOf($el);
            if ($decorOnly && $ph) {
                continue;
            }
            $spPr = self::kid($el, 'spPr') ?? self::kid($el, 'grpSpPr');
            $xf = self::xfrm($el->localName === 'graphicFrame' ? $el : $spPr);
            if (!$xf && $ph) {
                foreach (self::inherit($ctx, $ph) as $base) {
                    $xf = self::xfrm(self::kid($base, 'spPr'));
                    if ($xf) {
                        break;
                    }
                }
            }
            if (!$xf) {
                continue;
            }
            if ($map) {
                $sx = $map['w'] / $map['cw'];
                $sy = $map['h'] / $map['ch'];
                $xf['x'] = (int)($map['x'] + ($xf['x'] - $map['cx']) * $sx);
                $xf['y'] = (int)($map['y'] + ($xf['y'] - $map['cy']) * $sy);
                $xf['w'] = (int)($xf['w'] * $sx);
                $xf['h'] = (int)($xf['h'] * $sy);
            }
            if ($el->localName === 'grpSp') {
                $g = $xf + ['cx' => $xf['x'], 'cy' => $xf['y'], 'cw' => max(1, $xf['w']), 'ch' => max(1, $xf['h'])];
                $raw = self::xfrm($spPr);
                if ($raw && isset($raw['cw'])) {
                    $g['cx'] = $raw['cx'];
                    $g['cy'] = $raw['cy'];
                    $g['cw'] = $raw['cw'];
                    $g['ch'] = $raw['ch'];
                }
                $this->tree($el, $part, $ctx, $out, $g, $decorOnly, $depth + 1);
                continue;
            }
            $shape = ['x' => $xf['x'] / $this->cx, 'y' => $xf['y'] / $this->cy, 'w' => $xf['w'] / $this->cx, 'h' => $xf['h'] / $this->cy, 'rot' => $xf['rot'],
                'fill' => '', 'line' => '', 'round' => '', 'va' => '', 'pad' => [0, 0, 0, 0], 'paras' => [], 'img' => '', 'table' => '', 'scale' => 1.0, 'nw' => false];
            // fill and outline (the shape's own, else its style)
            if ($spPr) {
                $f = $this->fill($spPr, $part);
                if ($f === null && ($st = self::kid($el, 'style')) && ($fr = self::kid($st, 'fillRef')) && (int)OfficeWord::attr($fr, 'idx') > 0) {
                    $f = $this->color($fr);
                }
                $shape['fill'] = (string)$f;
                $ln = self::kid($spPr, 'ln');
                $lc = '';
                if ($ln && !self::kid($ln, 'noFill')) {
                    $lc = $this->color(self::kid($ln, 'solidFill'));
                } elseif (!$ln && ($st = self::kid($el, 'style')) && ($lr = self::kid($st, 'lnRef')) && (int)OfficeWord::attr($lr, 'idx') > 0) {
                    $lc = $this->color($lr);
                }
                if ($lc !== '') {
                    $w = $ln ? max(1, (int)round((int)OfficeWord::attr($ln, 'w') / 12700)) : 1;
                    $shape['line'] = min(12, $w) . 'px solid ' . $lc;
                }
                $geom = self::kid($spPr, 'prstGeom');
                $prst = $geom ? OfficeWord::attr($geom, 'prst') : '';
                $shape['round'] = match ($prst) { 'ellipse', 'flowChartConnector' => '50%', 'roundRect', 'flowChartAlternateProcess' => '12%', default => '' };
                if ($el->localName === 'cxnSp' || $prst === 'line') {
                    // a line: a thin box along its frame
                    $shape['fill'] = $lc !== '' ? $lc : '#000000';
                    $shape['line'] = '';
                    if ($xf['h'] < $xf['w']) {
                        $shape['h'] = max($shape['h'], 1.5 / 540);
                    } else {
                        $shape['w'] = max($shape['w'], 1.5 / 960);
                    }
                }
            }
            if ($el->localName === 'pic') {
                $src = $this->blip(self::kid($el, 'blipFill') ?? $el, $part);
                $shape['img'] = $src !== '' ? '<img src="' . h($src) . '" alt="">' : OfficePreview::picBox();
                if ($src === '') {
                    $this->notes[] = 'Pictures saved as Windows metafiles (WMF / EMF) show as grey boxes here; the page view shows them.';
                }
            } elseif ($el->localName === 'graphicFrame') {
                $this->frame($el, $part, $ctx, $shape);
            }
            if ($tx = self::kid($el, 'txBody')) {
                $this->text($tx, $part, $ctx, $ph, $shape);
            }
            if ($shape['paras'] || $shape['img'] !== '' || $shape['table'] !== '' || $shape['fill'] !== '' || $shape['line'] !== '') {
                $out[] = $shape;
            }
        }
    }

    /** A table, a chart or SmartArt in a frame */
    private function frame(DOMElement $el, string $part, array $ctx, array &$shape): void
    {
        $gd = $el->getElementsByTagNameNS('*', 'graphicData')->item(0);
        if (!$gd instanceof DOMElement) {
            return;
        }
        $tbl = self::kid($gd, 'tbl');
        if ($tbl) {
            $tp = self::kid($tbl, 'tblPr');
            $styled = $tp && self::kid($tp, 'tableStyleId');
            $firstRow = $tp && in_array(OfficeWord::attr($tp, 'firstRow'), ['1', 'true'], true);
            $band = $tp && in_array(OfficeWord::attr($tp, 'bandRow'), ['1', 'true'], true);
            $accent = '#' . ($this->theme['accent1'] ?? '4472c4');
            $html = '<table>';
            $r = 0;
            foreach (self::kids($tbl, 'tr') as $tr) {
                $html .= '<tr>';
                $c = 0;
                foreach (self::kids($tr, 'tc') as $tc) {
                    if (in_array(OfficeWord::attr($tc, 'hMerge'), ['1', 'true'], true) || in_array(OfficeWord::attr($tc, 'vMerge'), ['1', 'true'], true)) {
                        $c++;
                        continue;
                    }
                    $cs = max(1, (int)OfficeWord::attr($tc, 'gridSpan') ?: 1);
                    $rs = max(1, (int)OfficeWord::attr($tc, 'rowSpan') ?: 1);
                    $tcPr = self::kid($tc, 'tcPr');
                    $own = $tcPr ? $this->fill($tcPr, $part) : null;
                    $bg = (string)$own;
                    $head = $styled && $firstRow && $r === 0;
                    if ($own === null && $styled) {
                        // PowerPoint's default table style: a header row in the accent colour, banded rows in its tints
                        $bg = $head ? $accent : ($band && ($r - ($firstRow ? 1 : 0)) % 2 === 0 ? self::hsl($accent, 0.2, 0.8) : self::hsl($accent, 0.1, 0.9));
                    }
                    $cell = ['paras' => [], 'scale' => 1.0, 'pad' => [0, 0, 0, 0], 'va' => ''];
                    if ($tb = self::kid($tc, 'txBody')) {
                        $this->text($tb, $part, $ctx, null, $cell, 18);
                    }
                    if ($head && $own === null) {
                        foreach ($cell['paras'] as &$pp) {
                            foreach ($pp['runs'] as &$rr) {
                                if (isset($rr['t'])) {
                                    $rr['b'] = true;
                                    $rr['c'] = ($rr['c'] ?? '') === '' || $rr['c'] === '#000000' ? '#ffffff' : $rr['c'];
                                }
                            }
                            unset($rr);
                        }
                        unset($pp);
                    }
                    $html .= '<td' . ($cs > 1 ? ' colspan="' . $cs . '"' : '') . ($rs > 1 ? ' rowspan="' . $rs . '"' : '') . ($bg !== '' ? ' style="background:' . h($bg) . '"' : '') . '>'
                        . $this->parasHtml($cell['paras'], 1.0) . '</td>';
                    $c += $cs;
                }
                $html .= '</tr>';
                $r++;
            }
            $shape['table'] = $html . '</table>';
            return;
        }
        $uri = OfficeWord::attr($gd, 'uri');
        if (str_contains($uri, '/diagram')) {
            // SmartArt: its text, as a list
            $rid = '';
            foreach ($gd->getElementsByTagNameNS('*', 'relIds') as $ri) {
                $rid = OfficeWord::attr($ri, 'dm');
            }
            $rels = $this->rels($part);
            $dp = $rels[$rid]['path'] ?? '';
            $dd = $dp !== '' ? $this->dom($dp) : null;
            if ($dd) {
                foreach ($dd->getElementsByTagNameNS('*', 'pt') as $pt) {
                    if (in_array(OfficeWord::attr($pt, 'type'), ['parTrans', 'sibTrans', 'pres', 'doc'], true)) {
                        continue;
                    }
                    $t = '';
                    foreach ($pt->getElementsByTagNameNS('*', 't') as $te) {
                        $t .= $te->textContent;
                    }
                    if (trim($t) !== '') {
                        $shape['paras'][] = ['al' => 'left', 'ml' => 0, 'ti' => 0, 'bu' => '•', 'runs' => [['t' => $t, 'sz' => 16]]];
                    }
                }
            }
            $shape['line'] = '1px dashed #b9c1cb';
            return;
        }
        $shape['img'] = OfficePreview::picBox();
        $this->notes[] = 'Charts and embedded objects show as grey boxes in the quick view; the page view draws them.';
    }

    /** The defaults of a text level from the placeholders it follows and the master's text styles */
    private function levelDefaults(array $ctx, ?array $ph, int $lvl): array
    {
        $d = ['sz' => $ph ? (in_array($ph['type'], ['title', 'ctrTitle'], true) ? 44 : 28 - min(3, $lvl) * 4) : 18, 'c' => '', 'b' => false, 'i' => false, 'al' => '', 'bu' => null, 'font' => ''];
        $chain = [];
        if ($ph) {
            foreach (array_reverse(self::inherit($ctx, $ph)) as $base) {
                $chain[] = self::kid($base, 'txBody', 'lstStyle');
            }
        }
        $styleName = !$ph ? 'otherStyle' : (in_array($ph['type'], ['title', 'ctrTitle'], true) ? 'titleStyle' : (in_array($ph['type'], ['body', 'subTitle', 'obj'], true) ? 'bodyStyle' : 'otherStyle'));
        array_unshift($chain, $ctx['tx'] ? self::kid($ctx['tx'], $styleName) : null);
        foreach ($chain as $ls) {
            $lp = $ls ? self::kid($ls, 'lvl' . ($lvl + 1) . 'pPr') : null;
            if (!$lp) {
                continue;
            }
            $this->applyPara($lp, $d);
        }
        if ($ph && $ph['type'] === 'subTitle') {
            $d['bu'] = $d['bu'] ?? '';
        }
        return $d;
    }

    private function applyPara(DOMElement $pPr, array &$d): void
    {
        $al = OfficeWord::attr($pPr, 'algn');
        if ($al !== '') {
            $d['al'] = ['l' => 'left', 'ctr' => 'center', 'r' => 'right', 'just' => 'justify', 'dist' => 'justify'][$al] ?? '';
        }
        if (($ml = OfficeWord::attr($pPr, 'marL')) !== '') {
            $d['ml'] = (int)$ml;
        }
        if (($ti = OfficeWord::attr($pPr, 'indent')) !== '') {
            $d['ti'] = (int)$ti;
        }
        foreach ($pPr->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 'buNone': $d['bu'] = ''; break;
                case 'buChar': $d['bu'] = OfficeWord::symText(OfficeWord::attr($c, 'char'), true) ?: '•'; break;
                case 'buAutoNum': $d['bu'] = '#' . OfficeWord::attr($c, 'type'); $d['start'] = (int)OfficeWord::attr($c, 'startAt') ?: 1; break;
                case 'defRPr': $this->applyRun($c, $d); break;
            }
        }
    }

    private function applyRun(DOMElement $r, array &$d): void
    {
        if (($sz = OfficeWord::attr($r, 'sz')) !== '') {
            $d['sz'] = (int)$sz / 100;
        }
        foreach (['b', 'i'] as $k) {
            $v = OfficeWord::attr($r, $k);
            if ($v !== '') {
                $d[$k] = in_array($v, ['1', 'true'], true);
            }
        }
        $u = OfficeWord::attr($r, 'u');
        if ($u !== '') {
            $d['u'] = $u !== 'none';
        }
        $st = OfficeWord::attr($r, 'strike');
        if ($st !== '') {
            $d['s'] = $st !== 'noStrike';
        }
        $bl = (int)OfficeWord::attr($r, 'baseline');
        if ($bl !== 0) {
            $d['va'] = $bl > 0 ? 'sup' : 'sub';
        }
        foreach ($r->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'solidFill') {
                $d['c'] = $this->color($c);
            } elseif ($c->localName === 'latin') {
                $tf = OfficeWord::attr($c, 'typeface');
                $d['font'] = $tf === '+mj-lt' ? $this->fonts['major'] : ($tf === '+mn-lt' ? $this->fonts['minor'] : $tf);
            }
        }
    }

    private function text(DOMElement $tx, string $part, array $ctx, ?array $ph, array &$shape, int $defaultSz = 0): void
    {
        $bp = self::kid($tx, 'bodyPr');
        // the anchor and insets: the shape's own, else of the placeholder it follows
        $look = [$bp];
        foreach ($ph ? self::inherit($ctx, $ph) : [] as $base) {
            $look[] = self::kid($base, 'txBody', 'bodyPr');
        }
        $attr = function (string $a) use ($look): ?string {
            foreach ($look as $b) {
                if ($b && OfficeWord::attr($b, $a) !== '') {
                    return OfficeWord::attr($b, $a);
                }
            }
            return null;
        };
        $anchor = (string)$attr('anchor');
        $shape['nw'] = $attr('wrap') === 'none';
        $shape['va'] = $anchor === 'ctr' ? 'm' : ($anchor === 'b' ? 'b' : '');
        $shape['pad'] = [(int)($attr('lIns') ?? 91440) / $this->cx, (int)($attr('tIns') ?? 45720) / $this->cx, (int)($attr('rIns') ?? 91440) / $this->cx, (int)($attr('bIns') ?? 45720) / $this->cx];
        if ($bp && ($fit = self::kid($bp, 'normAutofit')) && OfficeWord::attr($fit, 'fontScale') !== '') {
            $shape['scale'] = max(0.25, min(1, (int)OfficeWord::attr($fit, 'fontScale') / 100000));
        }
        $own = self::kid($tx, 'lstStyle');
        $counters = [];
        foreach (self::kids($tx, 'p') as $p) {
            $pPr = self::kid($p, 'pPr');
            $lvl = $pPr ? min(8, (int)OfficeWord::attr($pPr, 'lvl')) : 0;
            $d = $this->levelDefaults($ctx, $ph, $lvl);
            if ($defaultSz) {
                $d['sz'] = $defaultSz;
            }
            if ($own && ($lp = self::kid($own, 'lvl' . ($lvl + 1) . 'pPr'))) {
                $this->applyPara($lp, $d);
            }
            if ($pPr) {
                $this->applyPara($pPr, $d);
            }
            $runs = [];
            foreach ($p->childNodes as $r) {
                if (!$r instanceof DOMElement) {
                    continue;
                }
                if ($r->localName === 'r' || $r->localName === 'fld') {
                    $f = $d;
                    if ($rp = self::kid($r, 'rPr')) {
                        $this->applyRun($rp, $f);
                    }
                    $t = (string)self::kid($r, 't')?->textContent;
                    if ($t !== '') {
                        $runs[] = ['t' => OfficeWord::symText($t, (bool)preg_match('/wingdings|symbol/i', $f['font'] ?? '')), 'b' => $f['b'] ?? false, 'i' => $f['i'] ?? false,
                            'u' => $f['u'] ?? false, 's' => $f['s'] ?? false, 'sz' => $f['sz'], 'c' => $f['c'] ?? '', 'font' => $f['font'] ?? '', 'va' => $f['va'] ?? ''];
                    }
                } elseif ($r->localName === 'br') {
                    $runs[] = ['br' => 1];
                } elseif ($r->localName === 'endParaRPr' && !$runs) {
                    $f = $d;
                    $this->applyRun($r, $f);
                    $d['sz'] = $f['sz'];
                }
            }
            $bu = $d['bu'] ?? null;
            if ($bu === null) {
                $bu = $ph && in_array($ph['type'], ['body', 'obj'], true) ? '•' : '';
            }
            if ($bu !== '' && $bu[0] === '#') {
                $counters[$lvl] = ($counters[$lvl] ?? (($d['start'] ?? 1) - 1)) + 1;
                $type = substr($bu, 1);
                $n = $counters[$lvl];
                $bu = match (true) {
                    str_starts_with($type, 'alphaLc') => strtolower(self::letters($n)), str_starts_with($type, 'alphaUc') => self::letters($n),
                    str_starts_with($type, 'romanLc') => strtolower(self::roman($n)), str_starts_with($type, 'romanUc') => self::roman($n), default => (string)$n,
                } . (str_ends_with($type, 'ParenR') ? ')' : (str_ends_with($type, 'ParenBoth') ? ')' : '.'));
                if (str_ends_with($type, 'ParenBoth')) {
                    $bu = '(' . $bu;
                }
            }
            if (!$runs) {
                $bu = '';
            }
            $shape['paras'][] = ['al' => $d['al'], 'ml' => $d['ml'] ?? 0, 'ti' => $d['ti'] ?? 0, 'bu' => $bu, 'runs' => $runs, 'sz' => $d['sz']];
        }
        // a shape whose paragraphs are all empty shows nothing
        if (!array_filter($shape['paras'], fn($p) => $p['runs'])) {
            $shape['paras'] = [];
        }
    }

    private static function letters(int $n): string
    {
        $s = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    private static function roman(int $n): string
    {
        $out = '';
        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $r => $v) {
            while ($n >= $v) {
                $out .= $r;
                $n -= $v;
            }
        }
        return $out;
    }

    // ================================================================== the slides as HTML

    private static float $slideWPt = 720;

    public static function html(array $deck): string
    {
        if (isset($deck['doc'])) {
            return OfficeWord::page($deck['doc']);
        }
        $w = max(1, (int)$deck['w']);
        $h = max(1, (int)$deck['h']);
        self::$slideWPt = $deck['wpt'] ?? $w / 12700;
        $out = '<div class="op-deck" style="--ar:' . $w . ' / ' . $h . '">';
        foreach ($deck['notes'] ?? [] as $n) {
            $out .= '<div class="op-note"><i class="bi bi-info-circle"></i> ' . h($n) . '</div>';
        }
        if (empty($deck['slides'])) {
            return $out . '<div class="op-msg"><i class="bi bi-easel"></i>This presentation has no slides.</div></div>';
        }
        foreach ($deck['slides'] as $i => $s) {
            $out .= '<div class="op-slide-wrap"><div class="op-slide-no"><span>Slide ' . ($i + 1) . '</span>' . (!empty($s['hidden']) ? '<span><i class="bi bi-eye-slash"></i> Hidden in the slide show</span>' : '') . '</div>'
                . '<div class="op-slide" style="background:' . h(self::safeBg((string)($s['bg'] ?? '#ffffff'))) . '">';
            foreach ($s['shapes'] as $sh) {
                $out .= self::shapeHtml($sh);
            }
            $out .= '</div>';
            if (($s['notes'] ?? '') !== '') {
                $out .= '<div class="op-notes"><strong>Notes:</strong> ' . h($s['notes']) . '</div>';
            }
            $out .= '</div>';
        }
        return $out . '</div>';
    }

    /** Only colours, gradients and data: pictures go into a style */
    private static function safeBg(string $bg): string
    {
        if (preg_match('/^(#[0-9a-f]{6}|rgba\(\d+,\d+,\d+,[\d.]+\))$/i', $bg)) {
            return $bg;
        }
        if (preg_match('/^linear-gradient\(-?\d+deg,(\s*(#[0-9a-f]{6}|rgba\(\d+,\d+,\d+,[\d.]+\)) \d+%,?)+\)$/i', $bg)) {
            return $bg;
        }
        if (preg_match('#^url\(data:image/(png|jpeg|gif|bmp|webp);base64,[A-Za-z0-9+/=]+\) center/cover no-repeat$#', $bg)) {
            return $bg;
        }
        return '#ffffff';
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v * 100, 3, '.', ''), '0'), '.') . '%';
    }

    private static function shapeHtml(array $sh): string
    {
        $st = 'left:' . self::pct($sh['x']) . ';top:' . self::pct($sh['y']) . ';width:' . self::pct(max(0, $sh['w'])) . ';height:' . self::pct(max(0, $sh['h'])) . ';';
        if ($sh['fill'] !== '') {
            $st .= 'background:' . self::safeBg($sh['fill']) . ';';
        }
        if ($sh['line'] !== '' && preg_match('/^\d+px solid (#[0-9a-f]{6}|rgba\(\d+,\d+,\d+,[\d.]+\))$/i', $sh['line'])) {
            $st .= 'border:' . $sh['line'] . ';';
        }
        if ($sh['round'] !== '') {
            $st .= 'border-radius:' . ($sh['round'] === '50%' ? '50%' : '12%') . ';';
        }
        if (!empty($sh['rot'])) {
            $st .= 'transform:rotate(' . round((float)$sh['rot'], 2) . 'deg);';
        }
        $pad = $sh['pad'] ?? [0, 0, 0, 0];
        if ($sh['paras']) {
            $st .= 'padding:' . implode(' ', array_map(fn($v) => round($v * 100, 3) . 'cqw', [$pad[1], $pad[2], $pad[3], $pad[0]])) . ';';
        }
        $inner = $sh['img'] !== '' ? $sh['img'] : ($sh['table'] !== '' ? $sh['table'] : '');
        if ($sh['paras']) {
            $inner .= self::parasHtml($sh['paras'], (float)($sh['scale'] ?? 1));
        }
        return '<div class="op-sh' . ($sh['va'] === 'm' ? ' v-m' : ($sh['va'] === 'b' ? ' v-b' : '')) . (!empty($sh['nw']) ? ' nw' : '') . ($sh['img'] !== '' ? ' pic' : '') . '" style="' . h($st) . '">' . $inner . '</div>';
    }

    private static function cqw(float $pt, float $scale): string
    {
        return round(max(1, min(200, $pt * $scale)) / self::$slideWPt * 100, 3) . 'cqw';
    }

    private static function parasHtml(array $paras, float $scale): string
    {
        $out = '';
        foreach ($paras as $p) {
            $st = '';
            if (in_array($p['al'] ?? '', ['left', 'center', 'right', 'justify'], true)) {
                $st .= 'text-align:' . $p['al'] . ';';
            }
            $ml = (int)($p['ml'] ?? 0);
            $ti = (int)($p['ti'] ?? 0);
            if ($ml > 0) {
                $st .= 'padding-left:' . round($ml / 12700 / self::$slideWPt * 100, 3) . 'cqw;';
            }
            if ($ti !== 0) {
                $st .= 'text-indent:' . round($ti / 12700 / self::$slideWPt * 100, 3) . 'cqw;';
            }
            $st .= 'font-size:' . self::cqw((float)($p['sz'] ?? 18), $scale) . ';';
            $inner = '';
            if (($p['bu'] ?? '') !== '' && $p['runs']) {
                $first = $p['runs'][0];
                $inner .= '<span class="op-bu"' . (($first['c'] ?? '') !== '' ? ' style="color:' . h(self::safeBg($first['c'])) . '"' : '') . '>' . h($p['bu']) . '</span> ';
            }
            foreach ($p['runs'] as $r) {
                if (isset($r['br'])) {
                    $inner .= '<br>';
                    continue;
                }
                $rs = 'font-size:' . self::cqw((float)($r['sz'] ?? 18) * (!empty($r['va']) ? 0.65 : 1), $scale) . ';';
                if (!empty($r['b'])) {
                    $rs .= 'font-weight:700;';
                }
                if (!empty($r['i'])) {
                    $rs .= 'font-style:italic;';
                }
                $dec = array_keys(array_filter(['underline' => !empty($r['u']), 'line-through' => !empty($r['s'])]));
                if ($dec) {
                    $rs .= 'text-decoration:' . implode(' ', $dec) . ';';
                }
                if (($r['c'] ?? '') !== '') {
                    $rs .= 'color:' . self::safeBg($r['c']) . ';';
                }
                if (($r['font'] ?? '') !== '') {
                    $rs .= 'font-family:' . OfficePreview::font($r['font']) . ';';
                }
                if (!empty($r['va'])) {
                    $rs .= 'vertical-align:' . ($r['va'] === 'sup' ? 'super' : 'sub') . ';';
                }
                $inner .= '<span style="' . h($rs) . '">' . h($r['t']) . '</span>';
            }
            $out .= '<p style="' . h($st) . '">' . ($inner !== '' ? $inner : '<br>') . '</p>';
        }
        return $out;
    }
}
