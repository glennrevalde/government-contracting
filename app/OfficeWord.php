<?php
declare(strict_types=1);

/**
 * The quick view of Word files, from the oldest to the newest:
 *  - Word for DOS, Windows Write, Word 1.x / 2.0 (.doc): the text;
 *  - Word 6.0 / 95 and Word 97 to 2003 (.doc, .dot): text, bold / italic / underline, sizes, colours, fonts, headings,
 *    alignment, indents, lists, tables, headers and footers, pictures and text boxes (OfficeWordDoc);
 *  - Rich Text Format (.rtf) of every Word (OfficeRtf);
 *  - Word 2003 XML, Word 2007 to Word 365 (.docx, .docm, .dotx, .dotm) and their flat XML: the same and more;
 *  - a Word file saved as a web page (.doc holding HTML or MHT) and plain text.
 * Every reader fills one model (paragraphs, runs, tables, page breaks, boxes) that page() turns into safe HTML.
 */
final class OfficeWord
{
    /** The quick view stops after this many paragraphs and tables (the page view shows all) */
    public const MAX_BLOCKS = 30000;
    private const HL = ['yellow' => '#ffff00', 'green' => '#00ff00', 'cyan' => '#00ffff', 'magenta' => '#ff00ff', 'blue' => '#0000ff', 'red' => '#ff0000',
        'darkBlue' => '#000080', 'darkCyan' => '#008080', 'darkGreen' => '#008000', 'darkMagenta' => '#800080', 'darkRed' => '#800000', 'darkYellow' => '#808000',
        'darkGray' => '#808080', 'lightGray' => '#c0c0c0', 'black' => '#000000', 'white' => '#ffffff'];
    /** The number formats of Word 97 (nfc) by their names in Word 2007 */
    public const NFC = [0 => 'decimal', 1 => 'upperRoman', 2 => 'lowerRoman', 3 => 'upperLetter', 4 => 'lowerLetter', 5 => 'ordinal', 6 => 'cardinalText', 7 => 'ordinalText',
        22 => 'decimalZero', 23 => 'bullet', 255 => 'none'];

    private ?ZipArchive $zip = null;
    /** parts of a flat XML file, and the pictures of a Word 2003 XML file */
    private array $parts = [];
    private array $rels = [];
    private array $rawStyles = [];
    private array $styles = [];
    private string $defPara = '';
    private array $defP = [];
    private array $defR = [];
    private array $base = [];
    private array $nums = [];
    private array $abs = [];
    private array $counters = [];
    private array $fld = [];
    private array $theme = ['minor' => '', 'major' => ''];
    private int $count = 0;
    private array $notes = [];

    public static function render(string $file, string $ext): string
    {
        return self::page(self::read($file, strtolower($ext)));
    }

    /** The model of a Word file: ['blocks', 'header', 'footer', 'page' => twips, 'base' => ['sz','font'], 'notes'] */
    public static function read(string $file, string $ext): array
    {
        $data = (string)file_get_contents($file);
        if ($data === '') {
            return self::doc([['t' => 'p', 'runs' => [['x' => '(The file is empty.)', 'f' => ['i' => true]]]]]);
        }
        if (str_starts_with($data, "PK\x03\x04")) {
            return (new self())->docx($file);
        }
        if (OleFile::is($data)) {
            return OfficeWordDoc::read($data);
        }
        $head = substr($data, 0, 4096);
        $trim = ltrim($head, "\xEF\xBB\xBF \t\r\n\0");
        if (str_starts_with($trim, '{\\rtf')) {
            return OfficeRtf::read($data);
        }
        $id = OfficePreview::u16($data, 0);
        if (in_array($id, [0xA59B, 0xA5DB, 0xA59C, 0xA5DC, 0xA5EC], true) && strlen($data) > 0x40) {
            return OfficeWordDoc::readPlain($data);
        }
        if (in_array($id, [0xBE31, 0xBE32], true) && OfficePreview::u16($data, 2) === 0) {
            return self::dos($data);
        }
        if (preg_match('/^MIME-Version:/im', substr($trim, 0, 300))) {
            return self::mht($data);
        }
        // Word 2003 XML and flat XML start with their root (a Word web page also names w:WordDocument, in its head)
        if (preg_match('/^(<\?xml[^>]*>\s*)?(<\?mso-application[^>]*>\s*)?(<!--.*?-->\s*)?<(w:wordDocument|pkg:package|w:document)\b/is', $trim)) {
            try {
                return (new self())->xml($data);
            } catch (RuntimeException $e) {
                return self::recover($data, $e->getMessage());
            }
        }
        if (preg_match('/<html|<!DOCTYPE\s+html|<body|<table|<p[\s>]/i', $head)) {
            return self::htmlDoc($data);
        }
        return self::plain($data);
    }

    /** A model with defaults */
    public static function doc(array $blocks, array $header = [], array $footer = [], array $page = [], array $base = [], array $notes = []): array
    {
        return ['blocks' => $blocks, 'header' => $header, 'footer' => $footer, 'page' => $page, 'base' => $base, 'notes' => $notes];
    }

    // ================================================================== Word 2007 to 365 (.docx), flat XML, Word 2003 XML

    private function docx(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('the Word file is damaged');
        }
        $this->zip = $zip;
        try {
            $main = 'word/document.xml';
            $root = $this->relsOf('');
            foreach ($root as $r) {
                if (str_ends_with($r['type'], '/officeDocument')) {
                    $main = $r['path'];
                }
            }
            if ($this->get($main) === null) {
                throw new RuntimeException('it holds no Word document (' . $main . ')');
            }
            return $this->package($main);
        } finally {
            $zip->close();
            $this->zip = null;
        }
    }

    /** The parts of a Word package (a .docx, or a flat XML file read into $parts) */
    private function package(string $main): array
    {
        $this->rels = $this->relsOf($main);
        foreach ($this->rels as $r) {
            $t = $r['type'];
            if (str_ends_with($t, '/styles')) {
                $this->loadStyles($this->dom($r['path']));
            } elseif (str_ends_with($t, '/numbering')) {
                $this->loadNumbering($this->dom($r['path']));
            } elseif (str_ends_with($t, '/theme')) {
                $this->loadTheme($this->dom($r['path']));
            }
        }
        $dom = $this->dom($main);
        $body = $dom ? $this->find($dom->documentElement, 'body') : null;
        if (!$body) {
            throw new RuntimeException('the document has no body');
        }
        $blocks = $this->container($body);
        $sect = null;
        foreach ($body->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === 'sectPr') {
                $sect = $c;
            }
        }
        [$header, $footer, $page] = $this->section($sect, $main);
        foreach ($this->rels as $r) {
            if (str_ends_with($r['type'], '/settings') && ($sd = $this->dom($r['path']))) {
                $dt = $sd->getElementsByTagNameNS('*', 'defaultTabStop')->item(0);
                if ($dt instanceof DOMElement) {
                    $page['tab'] = self::twips(self::attr($dt, 'val'));
                }
            }
        }
        return self::doc($blocks, $header, $footer, $page, ['sz' => $this->base['sz'] ?? 11, 'font' => $this->base['font'] ?? ''], $this->notes);
    }

    /** Flat XML of Word 2007+ (pkg:package) and Word 2003 XML (w:wordDocument) */
    private function xml(string $data): array
    {
        $dom = self::loadXml($data);
        if (!$dom) {
            throw new RuntimeException('the XML of the document is damaged');
        }
        $root = $dom->documentElement;
        if ($root->localName === 'package') {
            foreach ($root->childNodes as $part) {
                if (!$part instanceof DOMElement || $part->localName !== 'part') {
                    continue;
                }
                $name = ltrim(self::attr($part, 'name'), '/');
                foreach ($part->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->localName === 'xmlData') {
                        foreach ($c->childNodes as $x) {
                            if ($x instanceof DOMElement) {
                                $this->parts[$name] = $dom->saveXML($x);
                                break;
                            }
                        }
                    } elseif ($c instanceof DOMElement && $c->localName === 'binaryData') {
                        $this->parts[$name] = (string)base64_decode(preg_replace('/\s+/', '', $c->textContent));
                    }
                }
            }
            $main = 'word/document.xml';
            foreach ($this->relsOf('') as $r) {
                if (str_ends_with($r['type'], '/officeDocument')) {
                    $main = $r['path'];
                }
            }
            return $this->package($main);
        }
        // Word 2003 XML: styles, lists and pictures are in the one file
        foreach ($root->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'styles') {
                $this->loadStyles(null, $c);
            } elseif ($c->localName === 'lists') {
                $this->loadLists2003($c);
            }
        }
        foreach ($dom->getElementsByTagNameNS('*', 'binData') as $b) {
            $this->parts[self::attr($b, 'name')] = (string)base64_decode(preg_replace('/\s+/', '', $b->textContent));
        }
        $body = $this->find($root, 'body');
        if (!$body) {
            throw new RuntimeException('the document has no body');
        }
        $blocks = $this->container($body);
        $sect = null;
        foreach ($body->getElementsByTagNameNS('*', 'sectPr') as $s) {
            $sect = $s;
        }
        [$header, $footer, $page] = $this->section($sect, '');
        return self::doc($blocks, $header, $footer, $page, ['sz' => $this->base['sz'] ?? 12, 'font' => $this->base['font'] ?? ''], $this->notes);
    }

    // ------------------------------------------------------------------ parts and relationships

    private function get(string $path): ?string
    {
        if ($this->zip) {
            $s = $this->zip->getFromName($path);
            if ($s === false) {
                // names are compared without case by Word
                for ($i = 0, $n = $this->zip->numFiles; $i < $n; $i++) {
                    $nm = (string)$this->zip->getNameIndex($i);
                    if (strcasecmp($nm, $path) === 0) {
                        $s = $this->zip->getFromIndex($i);
                        break;
                    }
                }
            }
            return $s === false ? null : $s;
        }
        return $this->parts[$path] ?? null;
    }

    private function dom(string $path): ?DOMDocument
    {
        $x = $this->get($path);
        return $x === null ? null : self::loadXml($x);
    }

    public static function loadXml(string $x): ?DOMDocument
    {
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $d->loadXML($x, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok && $d->documentElement ? $d : null;
    }

    /** The relationships of a part: Id => ['type', 'path', 'external'] ('' for the package's own) */
    private function relsOf(string $part): array
    {
        $dir = $part === '' ? '' : (str_contains($part, '/') ? substr($part, 0, strrpos($part, '/')) : '');
        $relPath = ($dir !== '' ? $dir . '/' : '') . '_rels/' . ($part === '' ? '' : basename($part)) . '.rels';
        $dom = $this->dom($relPath);
        $out = [];
        if (!$dom) {
            return $out;
        }
        foreach ($dom->documentElement->childNodes as $r) {
            if (!$r instanceof DOMElement) {
                continue;
            }
            $target = self::attr($r, 'Target');
            $ext = strcasecmp(self::attr($r, 'TargetMode'), 'External') === 0;
            $path = $ext ? $target : self::resolve($dir, $target);
            $out[self::attr($r, 'Id')] = ['type' => self::attr($r, 'Type'), 'path' => $path, 'external' => $ext];
        }
        return $out;
    }

    public static function resolve(string $dir, string $target): string
    {
        $target = rawurldecode($target);
        $parts = str_starts_with($target, '/') ? [] : ($dir === '' ? [] : explode('/', $dir));
        foreach (explode('/', ltrim($target, '/')) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return implode('/', $parts);
    }

    // ------------------------------------------------------------------ DOM helpers (the namespace of Word 2003, 2007 and Strict differ: names are matched alone)

    public static function attr(DOMElement $e, string $local): string
    {
        foreach ($e->attributes as $a) {
            if ($a->localName === $local) {
                return $a->value;
            }
        }
        return '';
    }

    private static function child(?DOMElement $e, string $local): ?DOMElement
    {
        if (!$e) {
            return null;
        }
        foreach ($e->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $local) {
                return $c;
            }
        }
        return null;
    }

    private static function val(?DOMElement $e): string
    {
        return $e ? self::attr($e, 'val') : '';
    }

    private static function on(DOMElement $e): bool
    {
        $v = strtolower(self::attr($e, 'val'));
        return !in_array($v, ['0', 'false', 'off', 'none'], true);
    }

    private function find(DOMElement $e, string $local): ?DOMElement
    {
        if ($e->localName === $local) {
            return $e;
        }
        $l = $e->getElementsByTagNameNS('*', $local);
        return $l->length ? $l->item(0) : null;
    }

    private static function twips(string $v): int
    {
        $v = trim($v);
        if (preg_match('/^(-?[\d.]+)(mm|cm|in|pt|pc|pi)$/', $v, $m)) {
            $f = ['mm' => 56.6929, 'cm' => 566.929, 'in' => 1440, 'pt' => 20, 'pc' => 240, 'pi' => 240][$m[2]];
            return (int)round((float)$m[1] * $f);
        }
        return (int)$v;
    }

    // ------------------------------------------------------------------ styles, lists, theme

    private function loadStyles(?DOMDocument $dom, ?DOMElement $root = null): void
    {
        $root ??= $dom?->documentElement;
        if (!$root) {
            return;
        }
        foreach ($root->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'docDefaults') {
                $r = self::child(self::child($c, 'rPrDefault'), 'rPr');
                $p = self::child(self::child($c, 'pPrDefault'), 'pPr');
                $this->defR = $r ? $this->rPr($r) : [];
                $this->defP = $p ? $this->pPr($p) : [];
            } elseif ($c->localName === 'style') {
                $id = self::attr($c, 'styleId');
                $this->rawStyles[$id] = $c;
                $type = self::attr($c, 'type');
                if (($type === 'paragraph' || $type === '') && in_array(strtolower(self::attr($c, 'default')), ['1', 'true', 'on'], true)) {
                    $this->defPara = $id;
                }
            }
        }
        if (!isset($this->defR['sz'])) {
            $this->defR['sz'] = $dom ? 10 : 12;
        }
        $this->base = $this->style($this->defPara)['r'];
    }

    private function style(string $id): array
    {
        if (isset($this->styles[$id])) {
            return $this->styles[$id];
        }
        $empty = ['p' => $this->defP, 'r' => $this->defR, 'h' => 0, 'num' => null, 'border' => null, 'name' => ''];
        $this->styles[$id] = $empty;
        $el = $this->rawStyles[$id] ?? null;
        if (!$el) {
            return $empty;
        }
        $baseId = self::val(self::child($el, 'basedOn'));
        $res = $baseId !== '' && $baseId !== $id ? $this->style($baseId) : $empty;
        $name = strtolower(trim(self::val(self::child($el, 'name'))));
        $res['name'] = $name;
        if ($p = self::child($el, 'pPr')) {
            $pp = $this->pPr($p);
            if (isset($pp['num'])) {
                $res['num'] = $pp['num'];
                unset($pp['num']);
            }
            if (isset($pp['ol'])) {
                $res['h'] = $pp['ol'] < 6 ? $pp['ol'] + 1 : 0;
            }
            $res['p'] = array_merge($res['p'], $pp);
        }
        if ($r = self::child($el, 'rPr')) {
            $res['r'] = array_merge($res['r'], $this->rPr($r));
        }
        if (preg_match('/^heading\s*([1-9])$/', $name, $m)) {
            $res['h'] = min(6, (int)$m[1]);
        } elseif ($name === 'title') {
            $res['h'] = 1;
        }
        if ($t = self::child($el, 'tblPr')) {
            if ($b = self::child($t, 'tblBorders')) {
                $res['border'] = self::hasBorder($b);
            }
        }
        return $this->styles[$id] = $res;
    }

    private function loadNumbering(?DOMDocument $dom): void
    {
        if (!$dom) {
            return;
        }
        foreach ($dom->documentElement->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'abstractNum') {
                $lv = [];
                foreach ($c->childNodes as $l) {
                    if ($l instanceof DOMElement && $l->localName === 'lvl') {
                        $lv[(int)self::attr($l, 'ilvl')] = $this->lvl($l);
                    }
                }
                $this->abs[self::attr($c, 'abstractNumId')] = $lv;
            } elseif ($c->localName === 'num') {
                $n = ['abs' => self::val(self::child($c, 'abstractNumId')), 'start' => [], 'lvls' => []];
                foreach ($c->childNodes as $o) {
                    if ($o instanceof DOMElement && $o->localName === 'lvlOverride') {
                        $il = (int)self::attr($o, 'ilvl');
                        if ($s = self::child($o, 'startOverride')) {
                            $n['start'][$il] = (int)self::val($s);
                        }
                        if ($l = self::child($o, 'lvl')) {
                            $n['lvls'][$il] = $this->lvl($l);
                        }
                    }
                }
                $this->nums[self::attr($c, 'numId')] = $n;
            }
        }
    }

    private function loadLists2003(DOMElement $lists): void
    {
        foreach ($lists->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'listDef') {
                $lv = [];
                foreach ($c->childNodes as $l) {
                    if ($l instanceof DOMElement && $l->localName === 'lvl') {
                        $lv[(int)self::attr($l, 'ilvl')] = $this->lvl($l);
                    }
                }
                $this->abs['2003:' . self::attr($c, 'listDefId')] = $lv;
            } elseif ($c->localName === 'list') {
                $this->nums['2003:' . self::attr($c, 'ilfo')] = ['abs' => '2003:' . self::val(self::child($c, 'ilst')), 'start' => [], 'lvls' => []];
            }
        }
    }

    private function lvl(DOMElement $l): array
    {
        $fmt = self::val(self::child($l, 'numFmt'));
        if ($fmt === '' && ($nfc = self::child($l, 'nfc'))) {
            $fmt = self::NFC[(int)self::val($nfc)] ?? 'decimal';
        }
        $p = self::child($l, 'pPr');
        $pp = $p ? $this->pPr($p) : [];
        return ['fmt' => $fmt ?: 'decimal', 'text' => self::val(self::child($l, 'lvlText')), 'start' => ($s = self::child($l, 'start')) ? (int)self::val($s) : 1,
            'ml' => $pp['ml'] ?? null, 'ti' => $pp['ti'] ?? null];
    }

    private function loadTheme(?DOMDocument $dom): void
    {
        if (!$dom) {
            return;
        }
        foreach (['major' => 'majorFont', 'minor' => 'minorFont'] as $k => $tag) {
            $l = $dom->getElementsByTagNameNS('*', $tag);
            if ($l->length && ($lat = self::child($l->item(0), 'latin'))) {
                $this->theme[$k] = self::attr($lat, 'typeface');
            }
        }
    }

    /** The label of a list paragraph ("1.", "a)", "•") and its indents: [label, ml, ti] or null */
    private function listLabel(string $numId, int $ilvl): ?array
    {
        $n = $this->nums[$numId] ?? null;
        if (!$n || $numId === '0') {
            return null;
        }
        $abs = $this->abs[$n['abs']] ?? [];
        $lvl = fn(int $i) => $n['lvls'][$i] ?? $abs[$i] ?? null;
        $cur = $lvl($ilvl);
        if (!$cur) {
            return null;
        }
        $key = $n['abs'];
        if (!isset($this->counters[$key]['_' . $numId]) && isset($n['start'][$ilvl])) {
            unset($this->counters[$key]);
        }
        $this->counters[$key]['_' . $numId] = 1;
        $start = $n['start'][$ilvl] ?? $cur['start'];
        $this->counters[$key][$ilvl] = isset($this->counters[$key][$ilvl]) ? $this->counters[$key][$ilvl] + 1 : $start;
        for ($i = $ilvl + 1; $i < 10; $i++) {
            unset($this->counters[$key][$i]);
        }
        if ($cur['fmt'] === 'bullet') {
            $t = self::symText($cur['text']);
            $label = trim($t) === '' ? '•' : $t;
        } else {
            $label = preg_replace_callback('/%([1-9])/', function ($m) use ($key, $lvl, $n) {
                $i = (int)$m[1] - 1;
                $l = $lvl($i);
                $v = $this->counters[$key][$i] ?? ($n['start'][$i] ?? ($l['start'] ?? 1));
                return self::listNum($l['fmt'] ?? 'decimal', (int)$v);
            }, $cur['text']);
        }
        return [$label, $cur['ml'], $cur['ti']];
    }

    /** A list number in a Word number format */
    public static function listNum(string $fmt, int $n): string
    {
        return match ($fmt) {
            'upperRoman' => self::roman($n), 'lowerRoman' => strtolower(self::roman($n)),
            'upperLetter' => self::letter($n), 'lowerLetter' => strtolower(self::letter($n)),
            'ordinal' => $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th')),
            'decimalZero' => sprintf('%02d', $n), 'none' => '', 'bullet' => '•', 'decimalEnclosedParen' => '(' . $n . ')',
            default => (string)$n,
        };
    }

    private static function roman(int $n): string
    {
        if ($n <= 0 || $n > 3999) {
            return (string)$n;
        }
        $out = '';
        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $r => $v) {
            while ($n >= $v) {
                $out .= $r;
                $n -= $v;
            }
        }
        return $out;
    }

    private static function letter(int $n): string
    {
        if ($n <= 0) {
            return (string)$n;
        }
        // Word repeats the letter: A ... Z, AA ... ZZ
        return str_repeat(chr(65 + ($n - 1) % 26), intdiv($n - 1, 26) + 1);
    }

    /** Text with the signs of the Symbol / Wingdings fonts (U+F0xx) as Unicode */
    public static function symText(string $t, bool $symbolFont = false): string
    {
        if ($symbolFont) {
            $out = '';
            foreach (mb_str_split($t) as $ch) {
                $c = mb_ord($ch);
                $out .= ($c < 0x100 || ($c >= 0xF000 && $c <= 0xF0FF)) && $c > 0x20 ? OfficePreview::sym($c) : $ch;
            }
            return $out;
        }
        return (string)preg_replace_callback('/[\x{F000}-\x{F0FF}]/u', fn($m) => OfficePreview::sym(mb_ord($m[0])), $t);
    }

    // ------------------------------------------------------------------ properties

    private function rPr(DOMElement $e): array
    {
        $f = [];
        foreach ($e->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 'b':
                    $f['b'] = self::on($c);
                    break;
                case 'i':
                    $f['i'] = self::on($c);
                    break;
                case 'u':
                    $v = self::val($c);
                    $f['u'] = $v !== '' && $v !== 'none';
                    break;
                case 'strike':
                case 'dstrike':
                    $f['s'] = self::on($c);
                    break;
                case 'caps':
                    $f['caps'] = self::on($c);
                    break;
                case 'smallCaps':
                    $f['sc'] = self::on($c);
                    break;
                case 'vanish':
                case 'webHidden':
                    $f['hide'] = self::on($c);
                    break;
                case 'color':
                    $v = self::val($c);
                    $f['c'] = $v === 'auto' ? '' : OfficePreview::color($v);
                    break;
                case 'sz':
                    $v = (int)self::val($c);
                    if ($v > 0) {
                        $f['sz'] = $v / 2;
                    }
                    break;
                case 'rFonts':
                case 'rFont':
                    $n = self::attr($c, 'ascii') ?: self::attr($c, 'hAnsi');
                    if ($n === '') {
                        $t = self::attr($c, 'asciiTheme') ?: self::attr($c, 'hAnsiTheme');
                        $n = $t === '' ? '' : (str_starts_with($t, 'major') ? ($this->theme['major'] ?: 'Calibri Light') : ($this->theme['minor'] ?: 'Calibri'));
                    }
                    if ($n === '' && $c->localName === 'rFont') {
                        $n = self::val($c);
                    }
                    if ($n !== '') {
                        $f['font'] = $n;
                    }
                    break;
                case 'highlight':
                    $f['hl'] = self::HL[self::val($c)] ?? '';
                    break;
                case 'shd':
                    $fill = self::attr($c, 'fill');
                    if ($fill !== '' && $fill !== 'auto' && empty($f['hl'])) {
                        $f['hl'] = OfficePreview::color($fill);
                    }
                    break;
                case 'vertAlign':
                    $v = self::val($c);
                    $f['va'] = $v === 'superscript' ? 'sup' : ($v === 'subscript' ? 'sub' : '');
                    break;
            }
        }
        return $f;
    }

    private function pPr(DOMElement $e): array
    {
        $p = [];
        foreach ($e->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 'jc':
                    $v = self::val($c);
                    $p['al'] = match ($v) {
                        'center' => 'center', 'right', 'end' => 'right', 'both', 'distribute', 'lowKashida', 'mediumKashida', 'highKashida', 'thaiDistribute' => 'justify',
                        default => 'left',
                    };
                    break;
                case 'ind':
                    $l = self::attr($c, 'left') ?: self::attr($c, 'start');
                    if ($l !== '') {
                        $p['ml'] = self::twips($l);
                    }
                    if (($h = self::attr($c, 'hanging')) !== '') {
                        $p['ti'] = -self::twips($h);
                    } elseif (($fl = self::attr($c, 'firstLine') ?: self::attr($c, 'first-line')) !== '') {
                        $p['ti'] = self::twips($fl);
                    }
                    break;
                case 'spacing':
                    if (($b = self::attr($c, 'before')) !== '') {
                        $p['mt'] = self::twips($b);
                    }
                    if (($a = self::attr($c, 'after')) !== '') {
                        $p['mb'] = self::twips($a);
                    }
                    break;
                case 'numPr':
                    $id = self::val(self::child($c, 'numId'));
                    if ($id !== '') {
                        $p['num'] = [$id, (int)self::val(self::child($c, 'ilvl'))];
                    }
                    break;
                case 'listPr':
                    $id = self::val(self::child($c, 'ilfo'));
                    if ($id !== '') {
                        $p['num'] = ['2003:' . $id, (int)self::val(self::child($c, 'ilvl'))];
                    }
                    break;
                case 'outlineLvl':
                    $p['ol'] = (int)self::val($c);
                    break;
                case 'tabs':
                    $stops = [];
                    foreach ($c->childNodes as $t) {
                        if ($t instanceof DOMElement && $t->localName === 'tab' && !in_array(self::val($t), ['clear', 'bar'], true)) {
                            $stops[] = self::twips(self::attr($t, 'pos'));
                        }
                    }
                    sort($stops);
                    $p['tabs'] = $stops;
                    break;
                case 'shd':
                    $fill = self::attr($c, 'fill');
                    if ($fill !== '' && $fill !== 'auto') {
                        $p['bg'] = OfficePreview::color($fill);
                    }
                    break;
                case 'pageBreakBefore':
                    $p['pbb'] = self::on($c);
                    break;
                case 'sectPr':
                    $t = self::val(self::child($c, 'type'));
                    $p['sect'] = $t === '' ? 'nextPage' : $t;
                    break;
            }
        }
        return $p;
    }

    private static function hasBorder(DOMElement $b): bool
    {
        foreach ($b->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $v = self::val($c);
                if ($v !== '' && !in_array($v, ['nil', 'none'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ body

    private function container(DOMNode $node, int $depth = 0): array
    {
        $out = [];
        foreach ($node->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($this->count > self::MAX_BLOCKS) {
                $this->notes['cut'] = 'The quick view shows the first part of this long document; the page view or the download has all of it.';
                break;
            }
            switch ($c->localName) {
                case 'p':
                    foreach ($this->para($c) as $b) {
                        $out[] = $b;
                    }
                    break;
                case 'tbl':
                    $out[] = $this->table($c, $depth);
                    break;
                case 'sdt':
                    if ($s = self::child($c, 'sdtContent')) {
                        array_push($out, ...$this->container($s, $depth));
                    }
                    break;
                case 'customXml':
                case 'sdtContent':
                case 'sect':
                case 'sub-section':
                case 'ins':
                case 'moveTo':
                case 'smartTag':
                    array_push($out, ...$this->container($c, $depth));
                    break;
                case 'AlternateContent':
                    if ($ch = self::child($c, 'Choice') ?? self::child($c, 'Fallback')) {
                        array_push($out, ...$this->container($ch, $depth));
                    }
                    break;
            }
        }
        return $out;
    }

    private function para(DOMElement $p): array
    {
        $this->count++;
        $pPr = self::child($p, 'pPr');
        $sid = $pPr ? self::val(self::child($pPr, 'pStyle')) : '';
        $st = $this->style($sid !== '' ? $sid : $this->defPara);
        $pp = array_merge($st['p'], $pPr ? $this->pPr($pPr) : []);
        $num = $pp['num'] ?? $st['num'];
        $runs = [];
        $after = [];
        $pb = false;
        $this->inline($p, $st['r'], $runs, $after, $pb);
        $out = [];
        if (!empty($pp['pbb'])) {
            $out[] = ['t' => 'pb'];
        }
        $b = ['t' => 'p', 'h' => isset($pp['ol']) && $pp['ol'] < 6 ? $pp['ol'] + 1 : $st['h'], 'al' => $pp['al'] ?? '', 'ml' => $pp['ml'] ?? 0, 'ti' => $pp['ti'] ?? 0,
            'mt' => $pp['mt'] ?? null, 'mb' => $pp['mb'] ?? null, 'bg' => $pp['bg'] ?? '', 'num' => '', 'runs' => $runs, 'tabs' => $pp['tabs'] ?? []];
        if ($num && $num[0] !== '' && $num[0] !== '0') {
            $l = $this->listLabel((string)$num[0], (int)$num[1]);
            if ($l) {
                $b['num'] = $l[0];
                if (!isset($pPr) || !self::child($pPr, 'ind')) {
                    $b['ml'] = $l[1] ?? $b['ml'];
                    $b['ti'] = $l[2] ?? $b['ti'];
                }
            }
        }
        $out[] = $b;
        foreach ($after as $a) {
            $out[] = $a;
        }
        if ($pb || (isset($pp['sect']) && $pp['sect'] !== 'continuous' && $pPr && self::child($pPr, 'sectPr'))) {
            $out[] = ['t' => 'pb'];
        }
        return $out;
    }

    private function showing(): bool
    {
        foreach ($this->fld as $f) {
            if (!$f) {
                return false;
            }
        }
        return true;
    }

    private function inline(DOMElement $node, array $base, array &$runs, array &$after, bool &$pb): void
    {
        foreach ($node->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            switch ($c->localName) {
                case 'r':
                    $this->run($c, $base, $runs, $after, $pb);
                    break;
                case 'hyperlink':
                case 'ins':
                case 'smartTag':
                case 'customXml':
                case 'fldSimple':
                case 'moveTo':
                case 'dir':
                case 'bdo':
                case 'sdtContent':
                case 'annotation':
                    $this->inline($c, $base, $runs, $after, $pb);
                    break;
                case 'sdt':
                    if ($s = self::child($c, 'sdtContent')) {
                        $this->inline($s, $base, $runs, $after, $pb);
                    }
                    break;
                case 'AlternateContent':
                    if ($ch = self::child($c, 'Choice') ?? self::child($c, 'Fallback')) {
                        $this->inline($ch, $base, $runs, $after, $pb);
                    }
                    break;
                case 'oMath':
                case 'oMathPara':
                    $t = '';
                    foreach ($c->getElementsByTagNameNS('*', 't') as $mt) {
                        $t .= $mt->textContent;
                    }
                    if ($t !== '' && $this->showing()) {
                        $runs[] = ['x' => $t, 'f' => ['i' => true]];
                    }
                    break;
                case 'pict':
                case 'drawing':
                    $this->runContent($node, $c, $base, $runs, $after, $pb);
                    break;
            }
        }
    }

    private function run(DOMElement $r, array $base, array &$runs, array &$after, bool &$pb): void
    {
        $f = $base;
        if ($rPr = self::child($r, 'rPr')) {
            $rs = self::val(self::child($rPr, 'rStyle'));
            if ($rs !== '') {
                foreach ($this->style($rs)['r'] as $k => $v) {
                    if (!array_key_exists($k, $this->defR) || $this->defR[$k] !== $v) {
                        $f[$k] = $v;
                    }
                }
            }
            $f = array_merge($f, $this->rPr($rPr));
        }
        foreach ($r->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $this->runContent($r, $c, $f, $runs, $after, $pb);
            }
        }
    }

    private function runContent(DOMElement $parent, DOMElement $c, array $f, array &$runs, array &$after, bool &$pb): void
    {
        $name = $c->localName;
        if ($name === 'fldChar') {
            $t = self::attr($c, 'fldCharType');
            if ($t === 'begin') {
                $this->fld[] = false;
            } elseif ($t === 'separate' && $this->fld) {
                $this->fld[count($this->fld) - 1] = true;
            } elseif ($t === 'end' && $this->fld) {
                array_pop($this->fld);
            }
            return;
        }
        if (!empty($f['hide']) || !$this->showing()) {
            return;
        }
        // the size and font of the page are not written again on every run
        foreach (['sz', 'font'] as $k) {
            if (isset($f[$k], $this->base[$k]) && $f[$k] === $this->base[$k]) {
                unset($f[$k]);
            }
        }
        unset($f['hide']);
        switch ($name) {
            case 't':
                $t = $c->textContent;
                if ($t !== '') {
                    $runs[] = ['x' => self::symText($t, (bool)preg_match('/wingdings|symbol|webdings/i', $f['font'] ?? '')), 'f' => $f];
                }
                break;
            case 'tab':
            case 'ptab':
                $runs[] = ['tab' => 1];
                break;
            case 'br':
                if (self::attr($c, 'type') === 'page') {
                    $pb = true;
                } else {
                    $runs[] = ['br' => 1];
                }
                break;
            case 'cr':
                $runs[] = ['br' => 1];
                break;
            case 'noBreakHyphen':
                $runs[] = ['x' => '‑', 'f' => $f];
                break;
            case 'sym':
                $code = hexdec(self::attr($c, 'char'));
                $runs[] = ['x' => $code >= 0xF000 || preg_match('/wingdings|symbol|webdings/i', self::attr($c, 'font')) ? OfficePreview::sym((int)$code) : mb_chr((int)$code ?: 32), 'f' => $f];
                break;
            case 'drawing':
                $this->drawing($c, $runs, $after);
                break;
            case 'pict':
            case 'object':
                $this->vml($c, $runs, $after);
                break;
            case 'AlternateContent':
                if ($ch = self::child($c, 'Choice') ?? self::child($c, 'Fallback')) {
                    foreach ($ch->childNodes as $x) {
                        if ($x instanceof DOMElement) {
                            $this->runContent($parent, $x, $f, $runs, $after, $pb);
                        }
                    }
                }
                break;
            case 'ruby':
                if ($rb = self::child($c, 'rubyBase')) {
                    $runs[] = ['x' => $rb->textContent, 'f' => $f];
                }
                break;
        }
    }

    /** Pictures and text boxes of a DrawingML drawing */
    private function drawing(DOMElement $d, array &$runs, array &$after): void
    {
        $anchor = null;
        foreach ($d->childNodes as $c) {
            if ($c instanceof DOMElement && in_array($c->localName, ['inline', 'anchor'], true)) {
                $anchor = $c;
            }
        }
        if (!$anchor) {
            return;
        }
        $ext = self::child($anchor, 'extent');
        $w = $ext ? (int)round((int)self::attr($ext, 'cx') / 9525) : 0;
        $h = $ext ? (int)round((int)self::attr($ext, 'cy') / 9525) : 0;
        $float = '';
        if ($anchor->localName === 'anchor') {
            $ph = self::child($anchor, 'positionH');
            $al = $ph ? trim((string)self::child($ph, 'align')?->textContent) : '';
            $wrap = '';
            foreach ($anchor->childNodes as $c) {
                if ($c instanceof DOMElement && str_starts_with($c->localName, 'wrap')) {
                    $wrap = $c->localName;
                }
            }
            $float = in_array($wrap, ['wrapSquare', 'wrapTight', 'wrapThrough'], true) ? ($al === 'right' ? 'r' : 'l') : ($al === 'center' ? 'c' : 'b');
        }
        $items = [];
        $this->collect($anchor, $items);
        $pics = array_values(array_filter($items, fn($i) => $i[0] === 'pic'));
        foreach ($items as $it) {
            if ($it[0] === 'box') {
                $blocks = $this->container($it[1]);
                if ($blocks) {
                    $after[] = ['t' => 'box', 'b' => $blocks];
                }
            } elseif ($it[0] === 'chart') {
                $runs[] = ['img' => OfficePreview::picBox('width:' . max(40, min(900, $w)) . 'px;height:' . max(30, min(900, $h)) . 'px'), 'chart' => 1];
            } else {
                [$pw, $ph] = count($pics) > 1 ? $this->picSize($it[1]) : [$w, $h];
                $html = $this->picture(self::attr($it[1], 'embed') ?: self::attr($it[1], 'link'), '', $pw ?: $w, $ph ?: $h);
                if ($html === '') {
                    continue;
                }
                if ($float === 'l' || $float === 'r') {
                    $runs[] = ['img' => '<span class="op-fl op-fl-' . $float . '">' . $html . '</span>'];
                } elseif ($float === 'c' || $float === 'b') {
                    $after[] = ['t' => 'img', 'html' => $html, 'al' => $float === 'c' ? 'center' : ''];
                } else {
                    $runs[] = ['img' => $html];
                }
            }
        }
    }

    private function picSize(DOMElement $blip): array
    {
        for ($n = $blip->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->localName === 'pic') {
                $sp = self::child($n, 'spPr');
                $x = self::child(self::child($sp, 'xfrm'), 'ext');
                return $x ? [(int)round((int)self::attr($x, 'cx') / 9525), (int)round((int)self::attr($x, 'cy') / 9525)] : [0, 0];
            }
        }
        return [0, 0];
    }

    private function collect(DOMElement $e, array &$items): void
    {
        foreach ($e->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'txbxContent') {
                $items[] = ['box', $c];
            } elseif ($c->localName === 'blip') {
                $items[] = ['pic', $c];
            } elseif ($c->localName === 'chart' || $c->localName === 'relIds') {
                $items[] = ['chart', $c];
            } else {
                $this->collect($c, $items);
            }
        }
    }

    /** Pictures and text boxes of VML (Word 2000 to 2003, and the fallbacks of later Words) */
    private function vml(DOMElement $p, array &$runs, array &$after): void
    {
        $style = '';
        foreach ($p->getElementsByTagNameNS('*', '*') as $e) {
            if (in_array($e->localName, ['shape', 'rect', 'roundrect'], true) && $style === '') {
                $style = self::attr($e, 'style');
            }
        }
        $w = preg_match('/(?:^|;)\s*width:\s*([\d.]+)(pt|px|in|cm|mm)?/i', $style, $m) ? self::px((float)$m[1], $m[2] ?? 'pt') : 0;
        $h = preg_match('/(?:^|;)\s*height:\s*([\d.]+)(pt|px|in|cm|mm)?/i', $style, $m) ? self::px((float)$m[1], $m[2] ?? 'pt') : 0;
        $boxes = [];
        foreach ($p->getElementsByTagNameNS('*', 'txbxContent') as $t) {
            $boxes[] = $t;
        }
        foreach ($boxes as $t) {
            $blocks = $this->container($t);
            if ($blocks) {
                $after[] = ['t' => 'box', 'b' => $blocks];
            }
        }
        foreach ($p->getElementsByTagNameNS('*', 'imagedata') as $img) {
            $html = $this->picture(self::attr($img, 'id') ?: self::attr($img, 'pict'), self::attr($img, 'src'), $w, $h);
            if ($html !== '') {
                $runs[] = ['img' => $html];
            }
        }
    }

    private static function px(float $v, string $unit): int
    {
        return (int)round($v * ['pt' => 4 / 3, 'px' => 1, 'in' => 96, 'cm' => 37.795, 'mm' => 3.7795][strtolower($unit)] ?? 4 / 3);
    }

    /** A picture by its relationship (or a Word 2003 picture name) as HTML; '' when there is none */
    private function picture(string $rid, string $src, int $w, int $h): string
    {
        $bytes = null;
        if ($rid !== '' && isset($this->rels[$rid]) && !$this->rels[$rid]['external']) {
            $bytes = $this->get($this->rels[$rid]['path']);
        } elseif ($src !== '') {
            $bytes = $this->parts[$src] ?? null;
        }
        if ($bytes === null) {
            return '';
        }
        $w = max(0, min(1600, $w));
        $h = max(0, min(2400, $h));
        $img = OfficePreview::img($bytes, $w > 0 ? 'width:' . $w . 'px' : '');
        if ($img === '') {
            $this->notes['wmf'] = 'Pictures saved as Windows metafiles (WMF / EMF) show as grey boxes here; the page view shows them.';
            return OfficePreview::picBox('width:' . ($w ?: 120) . 'px;height:' . ($h ?: 80) . 'px');
        }
        return $img;
    }

    private function table(DOMElement $tbl, int $depth): array
    {
        $this->count++;
        $tblPr = self::child($tbl, 'tblPr');
        $border = null;
        $width = 0;
        if ($tblPr) {
            if ($bd = self::child($tblPr, 'tblBorders')) {
                $border = self::hasBorder($bd);
            }
            $sid = self::val(self::child($tblPr, 'tblStyle'));
            if ($border === null && $sid !== '') {
                $border = $this->style($sid)['border'];
            }
            if (($tw = self::child($tblPr, 'tblW')) && in_array(self::attr($tw, 'type'), ['dxa', ''], true)) {
                $width = self::twips(self::attr($tw, 'w'));
            }
        }
        $cols = [];
        if ($g = self::child($tbl, 'tblGrid')) {
            foreach ($g->childNodes as $c) {
                if ($c instanceof DOMElement && $c->localName === 'gridCol') {
                    $cols[] = max(0, self::twips(self::attr($c, 'w')));
                }
            }
        }
        $rows = [];
        $open = [];
        foreach ($this->kids($tbl, 'tr') as $tr) {
            $cells = [];
            $col = 0;
            if ($trPr = self::child($tr, 'trPr')) {
                $col = (int)self::val(self::child($trPr, 'gridBefore'));
            }
            foreach ($this->kids($tr, 'tc') as $tc) {
                $tcPr = self::child($tc, 'tcPr');
                $span = max(1, (int)self::val(self::child($tcPr, 'gridSpan')) ?: (int)self::val(self::child($tcPr, 'hmerge')) ?: 1);
                $vm = self::child($tcPr, 'vMerge') ?? self::child($tcPr, 'vmerge');
                $vmv = $vm ? self::val($vm) : null;
                if ($vm && $vmv !== 'restart' && isset($open[$col])) {
                    [$ri, $ci] = $open[$col];
                    $rows[$ri][$ci]['rs']++;
                    $col += $span;
                    continue;
                }
                $bg = '';
                if ($shd = self::child($tcPr, 'shd')) {
                    $fill = self::attr($shd, 'fill');
                    $bg = $fill !== '' && $fill !== 'auto' ? OfficePreview::color($fill) : '';
                }
                $va = self::val(self::child($tcPr, 'vAlign'));
                $tcb = self::child($tcPr, 'tcBorders');
                $cells[] = ['b' => $this->container($tc, $depth + 1), 'cs' => $span, 'rs' => 1, 'bg' => $bg, 'va' => in_array($va, ['center', 'bottom'], true) ? ($va === 'center' ? 'middle' : 'bottom') : '',
                    'bd' => $tcb ? self::hasBorder($tcb) : false];
                if ($vmv === 'restart') {
                    $open[$col] = [count($rows), count($cells) - 1];
                } else {
                    unset($open[$col]);
                }
                $col += $span;
            }
            $rows[] = $cells;
        }
        return ['t' => 'table', 'border' => (bool)$border, 'cols' => $cols, 'rows' => $rows, 'w' => $width];
    }

    /** The children of a kind, also those wrapped in content controls and custom XML */
    private function kids(DOMElement $e, string $local): array
    {
        $out = [];
        foreach ($e->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === $local) {
                $out[] = $c;
            } elseif (in_array($c->localName, ['sdt', 'sdtContent', 'customXml', 'ins', 'moveTo'], true)) {
                array_push($out, ...$this->kids($c, $local));
            }
        }
        return $out;
    }

    /** [header blocks, footer blocks, page] of the last section */
    private function section(?DOMElement $s, string $main): array
    {
        $page = [];
        $header = $footer = [];
        if (!$s) {
            return [$header, $footer, $page];
        }
        if ($sz = self::child($s, 'pgSz')) {
            $page['w'] = self::twips(self::attr($sz, 'w'));
            $page['h'] = self::twips(self::attr($sz, 'h'));
        }
        if ($mg = self::child($s, 'pgMar')) {
            foreach (['left' => 'ml', 'right' => 'mr', 'top' => 'mt', 'bottom' => 'mb'] as $a => $k) {
                $v = self::attr($mg, $a);
                if ($v !== '') {
                    $page[$k] = abs(self::twips($v));
                }
            }
        }
        $refs = ['header' => [], 'footer' => []];
        foreach ($s->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            if ($c->localName === 'headerReference' || $c->localName === 'footerReference') {
                $refs[$c->localName === 'headerReference' ? 'header' : 'footer'][self::attr($c, 'type') ?: 'default'] = self::attr($c, 'id');
            } elseif ($c->localName === 'hdr' || $c->localName === 'ftr') {
                // Word 2003 XML keeps them in the section
                $refs[$c->localName === 'hdr' ? 'header' : 'footer'][self::attr($c, 'type') === 'odd' ? 'default' : self::attr($c, 'type')] = $c;
            }
        }
        foreach (['header', 'footer'] as $k) {
            $ref = $refs[$k]['default'] ?? $refs[$k]['first'] ?? null;
            if ($ref instanceof DOMElement) {
                $blocks = $this->container($ref);
            } elseif (is_string($ref) && isset($this->rels[$ref])) {
                $saved = $this->rels;
                $path = $this->rels[$ref]['path'];
                $this->rels = $this->relsOf($path);
                $d = $this->dom($path);
                $blocks = $d ? $this->container($d->documentElement) : [];
                $this->rels = $saved;
            } else {
                $blocks = [];
            }
            if ($k === 'header') {
                $header = $blocks;
            } else {
                $footer = $blocks;
            }
        }
        return [$header, $footer, $page];
    }

    // ================================================================== other inputs

    /** Word for DOS and Windows Write: the text between the header (128 bytes) and the end of the text */
    private static function dos(string $data): array
    {
        $end = OfficePreview::u32($data, 0x0E);
        $end = $end > 128 && $end <= strlen($data) ? $end : strlen($data);
        $txt = substr($data, 128, $end - 128);
        $txt = preg_replace('/[\x00-\x08\x0B\x0E-\x1F]/', '', $txt);
        return self::plainBlocks(OfficePreview::cp((string)$txt, 1252), ['Read as Word for DOS / Windows Write: the text only.']);
    }

    public static function plain(string $data): array
    {
        $t = OfficePreview::utf8($data);
        if (preg_match('/[\x00-\x08\x0E-\x1F]/', substr($t, 0, 4000))) {
            // not text: what can be read of it
            return self::recover($data);
        }
        return self::plainBlocks($t);
    }

    public static function plainBlocks(string $t, array $notes = []): array
    {
        $blocks = [];
        foreach (preg_split('/\r\n|\r|\n/', $t) as $line) {
            $blocks[] = ['t' => 'p', 'runs' => $line === '' ? [] : [['x' => $line, 'f' => []]]];
            if (count($blocks) > self::MAX_BLOCKS) {
                $notes[] = 'The quick view shows the first part of this long file.';
                break;
            }
        }
        return self::doc($blocks, [], [], [], [], $notes);
    }

    /** The readable text of a file the readers do not know (or of a damaged one) */
    public static function recover(string $data, string $why = ''): array
    {
        $found = [];
        if (preg_match_all('/(?:[\x20-\x7E\xA0-\xFF][\x00]){5,}/', $data, $m)) {
            foreach ($m[0] as $s) {
                $found[] = OfficePreview::utf16($s);
            }
        }
        if (preg_match_all('/[\x20-\x7E\x91-\x97\xA0-\xFF\r\n\t]{8,}/', $data, $m)) {
            foreach ($m[0] as $s) {
                if (preg_match('/[a-z]{3}/i', $s)) {
                    $found[] = OfficePreview::cp($s, 1252);
                }
            }
        }
        $blocks = [];
        foreach ($found as $s) {
            foreach (preg_split('/\r\n|\r|\n/', trim($s)) as $line) {
                if (trim($line) !== '') {
                    $blocks[] = ['t' => 'p', 'runs' => [['x' => $line, 'f' => []]]];
                }
            }
            if (count($blocks) > 5000) {
                break;
            }
        }
        if (!$blocks) {
            $blocks[] = ['t' => 'p', 'runs' => [['x' => 'No readable text was found in this file.', 'f' => ['i' => true]]]];
        }
        return self::doc($blocks, [], [], [], [], ['The quick view recovered the readable text of this file' . ($why !== '' ? ' (' . $why . ')' : '') . '; the page view shows it as made.']);
    }

    /** A Word file saved as a single-file web page (MHT) */
    private static function mht(string $data): array
    {
        if (!preg_match('/boundary="?([^"\r\n;]+)"?/i', $data, $m)) {
            return self::htmlDoc($data);
        }
        $html = '';
        $files = [];
        foreach (explode('--' . $m[1], $data) as $part) {
            $sep = strpos($part, "\r\n\r\n");
            $sepLen = 4;
            if ($sep === false) {
                $sep = strpos($part, "\n\n");
                $sepLen = 2;
            }
            if ($sep === false) {
                continue;
            }
            $head = substr($part, 0, $sep);
            $body = substr($part, $sep + $sepLen);
            if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $head)) {
                $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
            } elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $head)) {
                $body = quoted_printable_decode($body);
            }
            if ($html === '' && preg_match('/Content-Type:\s*text\/html/i', $head)) {
                $cs = preg_match('/charset="?([\w-]+)/i', $head, $c) ? $c[1] : '';
                $html = $cs !== '' && strcasecmp($cs, 'utf-8') !== 0 ? (string)@mb_convert_encoding($body, 'UTF-8', $cs) : $body;
            } elseif (preg_match('/Content-Location:\s*(\S+)/i', $head, $loc)) {
                $files[basename(trim($loc[1]))] = $body;
            }
        }
        return self::htmlDoc($html, $files);
    }

    /** A Word file saved as a web page: its paragraphs, headings, lists, tables and pictures, rebuilt (no script or style of it is kept) */
    public static function htmlDoc(string $html, array $files = []): array
    {
        $html = OfficePreview::utf8($html);
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $d->getElementsByTagName('body')->item(0) ?? $d->documentElement;
        $blocks = [];
        $runs = [];
        if ($body) {
            self::htmlWalk($body, [], $blocks, $runs, $files);
        }
        if ($runs) {
            $blocks[] = ['t' => 'p', 'runs' => $runs];
        }
        return self::doc($blocks, [], [], [], [], ['This Word file was saved as a web page; the quick view shows its text, tables and pictures.']);
    }

    private static function htmlWalk(DOMNode $n, array $f, array &$blocks, array &$runs, array $files, int $depth = 0): void
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) {
                $t = preg_replace('/\s+/u', ' ', $c->nodeValue ?? '');
                if ($t !== '' && $t !== null && !($t === ' ' && !$runs)) {
                    $runs[] = ['x' => $t, 'f' => $f];
                }
                continue;
            }
            if (!$c instanceof DOMElement || $depth > 60) {
                continue;
            }
            $tag = strtolower($c->tagName);
            if (in_array($tag, ['script', 'style', 'head', 'title', 'meta', 'link', 'xml', 'object', 'iframe', 'noscript', 'template'], true)) {
                continue;
            }
            $g = $f;
            $st = strtolower($c->getAttribute('style'));
            if (in_array($tag, ['b', 'strong'], true) || preg_match('/font-weight:\s*(bold|[6-9]00)/', $st)) {
                $g['b'] = true;
            }
            if (in_array($tag, ['i', 'em'], true) || str_contains($st, 'italic')) {
                $g['i'] = true;
            }
            if ($tag === 'u' || str_contains($st, 'underline')) {
                $g['u'] = true;
            }
            if (in_array($tag, ['s', 'strike', 'del'], true)) {
                $g['s'] = true;
            }
            if (preg_match('/(?:^|;)\s*color:\s*#([0-9a-f]{6})/', $st, $m) || ($tag === 'font' && preg_match('/^#?([0-9a-f]{6})$/i', $c->getAttribute('color'), $m))) {
                $g['c'] = '#' . strtolower($m[1]);
            }
            if ($tag === 'sup' || $tag === 'sub') {
                $g['va'] = $tag;
            }
            $block = in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'pre', 'center', 'address', 'dt', 'dd'], true);
            if ($tag === 'br') {
                $runs[] = ['br' => 1];
            } elseif ($tag === 'img') {
                $src = $c->getAttribute('src');
                $bytes = null;
                if (preg_match('/^data:image\/(png|jpe?g|gif|bmp|webp);base64,(.+)$/is', $src, $m)) {
                    $bytes = base64_decode($m[2]);
                } elseif ($src !== '' && isset($files[basename($src)])) {
                    $bytes = $files[basename($src)];
                }
                $w = (int)$c->getAttribute('width');
                $img = $bytes ? OfficePreview::img((string)$bytes, $w > 0 ? 'width:' . min(1600, $w) . 'px' : '') : '';
                if ($img !== '') {
                    $runs[] = ['img' => $img];
                }
            } elseif ($tag === 'table') {
                if ($runs) {
                    $blocks[] = ['t' => 'p', 'runs' => $runs];
                    $runs = [];
                }
                $blocks[] = self::htmlTable($c, $files, $depth);
            } elseif ($block) {
                if ($runs) {
                    $blocks[] = ['t' => 'p', 'runs' => $runs];
                    $runs = [];
                }
                $inner = [];
                $innerRuns = [];
                self::htmlWalk($c, $g, $inner, $innerRuns, $files, $depth + 1);
                $al = strtolower($c->getAttribute('align'));
                if (preg_match('/text-align:\s*(left|center|right|justify)/', $st, $m)) {
                    $al = $m[1];
                }
                if ($tag === 'center') {
                    $al = 'center';
                }
                $h = preg_match('/^h([1-6])$/', $tag, $m) ? (int)$m[1] : 0;
                if ($innerRuns || !$inner) {
                    $blocks[] = ['t' => 'p', 'h' => $h, 'al' => in_array($al, ['left', 'center', 'right', 'justify'], true) ? $al : '', 'num' => $tag === 'li' ? '•' : '', 'ml' => $tag === 'li' ? 540 : 0,
                        'ti' => $tag === 'li' ? -270 : 0, 'runs' => $innerRuns];
                }
                foreach ($inner as $b) {
                    $blocks[] = $b;
                }
            } else {
                self::htmlWalk($c, $g, $blocks, $runs, $files, $depth + 1);
            }
        }
    }

    private static function htmlTable(DOMElement $t, array $files, int $depth): array
    {
        $rows = [];
        $lined = fn(string $style) => (bool)preg_match('/border(-(top|bottom|left|right))?(-style)?\s*:[^;]*\b(solid|double|dashed|dotted|groove|ridge|inset|outset|windowtext)\b/i', $style);
        $border = (int)$t->getAttribute('border') > 0 || $lined($t->getAttribute('style')) || str_contains(strtolower($t->getAttribute('class')), 'grid');
        foreach ($t->getElementsByTagName('tr') as $tr) {
            // only the rows of this table, not of the tables in its cells
            for ($p = $tr->parentNode; $p && !($p instanceof DOMElement && strtolower($p->tagName) === 'table'); $p = $p->parentNode) {
            }
            if ($p !== $t) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if (!$td instanceof DOMElement || !in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                    continue;
                }
                $blocks = [];
                $runs = [];
                self::htmlWalk($td, strtolower($td->tagName) === 'th' ? ['b' => true] : [], $blocks, $runs, $files, $depth + 1);
                if ($runs) {
                    $blocks[] = ['t' => 'p', 'runs' => $runs];
                }
                $bg = preg_match('/^#?([0-9a-f]{6})$/i', $td->getAttribute('bgcolor'), $m) ? '#' . strtolower($m[1]) : (preg_match('/background(?:-color)?:\s*#([0-9a-f]{6})/i', $td->getAttribute('style'), $m) ? '#' . strtolower($m[1]) : '');
                $cells[] = ['b' => $blocks, 'cs' => max(1, min(100, (int)$td->getAttribute('colspan') ?: 1)), 'rs' => max(1, min(1000, (int)$td->getAttribute('rowspan') ?: 1)), 'bg' => $bg, 'va' => '',
                    'bd' => $lined($td->getAttribute('style'))];
            }
            $rows[] = $cells;
        }
        return ['t' => 'table', 'border' => $border, 'cols' => [], 'rows' => $rows, 'w' => 0];
    }

    /**
     * A table from rows of cells placed by their edges (twips): the columns are the edges of all rows, as Word lays them;
     * a cell over several columns gets its span, and cells merged down (vm 2 starts, 1 continues) get their row span.
     * Cell: ['b' => blocks, 'x0', 'x1', 'vm', 'bg', 'va', 'bd']
     */
    public static function gridTable(array $rows, bool $border): array
    {
        $edges = [];
        $known = true;
        foreach ($rows as $r) {
            foreach ($r as $c) {
                if ($c['x1'] <= $c['x0']) {
                    $known = false;
                }
                $edges[] = (int)round($c['x0'] / 20) * 20;
                $edges[] = (int)round($c['x1'] / 20) * 20;
            }
        }
        $edges = array_values(array_unique($edges));
        sort($edges);
        $out = [];
        $open = [];
        foreach ($rows as $r) {
            $cells = [];
            foreach ($r as $i => $c) {
                $a = (int)round($c['x0'] / 20) * 20;
                $b = (int)round($c['x1'] / 20) * 20;
                $key = $known ? $a : $i;
                if (($c['vm'] ?? 0) === 1 && isset($open[$key])) {
                    [$ri, $ci] = $open[$key];
                    $out[$ri][$ci]['rs']++;
                    continue;
                }
                $cs = 1;
                if ($known) {
                    $cs = 0;
                    foreach ($edges as $e) {
                        if ($e > $a && $e <= $b) {
                            $cs++;
                        }
                    }
                    $cs = max(1, $cs);
                }
                $cells[] = ['b' => $c['b'], 'cs' => $cs, 'rs' => 1, 'bg' => $c['bg'] ?? '', 'va' => $c['va'] ?? '', 'bd' => $c['bd'] ?? false];
                if (($c['vm'] ?? 0) === 2) {
                    $open[$key] = [count($out), count($cells) - 1];
                } else {
                    unset($open[$key]);
                }
            }
            $out[] = $cells;
        }
        $cols = [];
        if ($known && count($edges) > 1) {
            for ($k = 1; $k < count($edges); $k++) {
                $cols[] = $edges[$k] - $edges[$k - 1];
            }
        }
        return ['t' => 'table', 'border' => $border, 'cols' => $cols, 'rows' => $out, 'w' => $cols ? array_sum($cols) : 0];
    }

    // ================================================================== the model as HTML

    private static int $contentW = 9360;
    private static int $budget = 0;
    /** The default tab stops of the document being drawn (twips) */
    private static int $defTab = 720;

    /** The page of a model: header, body, footer; every text escaped, every style from checked values */
    public static function page(array $doc, string $class = 'op-word'): string
    {
        $pg = ($doc['page'] ?? []) + ['w' => 12240, 'ml' => 1440, 'mr' => 1440, 'mt' => 1440, 'mb' => 1440];
        $pw = max(4000, min(24000, (int)$pg['w']));
        $ml = max(0, min(4320, (int)$pg['ml']));
        $mr = max(0, min(4320, (int)$pg['mr']));
        self::$contentW = max(2000, $pw - $ml - $mr);
        self::$budget = 6000000;
        self::$defTab = (int)($doc['page']['tab'] ?? 720) ?: 720;
        $base = $doc['base'] ?? [];
        $mt = max(0, min(4320, (int)$pg['mt']));
        $mb = max(0, min(4320, (int)$pg['mb']));
        // the margins in pixels, and in percent of the paper's width (for a page shown narrower than the paper)
        $style = sprintf('--pw:%dpx;--pml:%dpx;--pmr:%dpx;--pmt:%dpx;--pmb:%dpx;--pmlr:%.3f;--pmrr:%.3f;--pmtr:%.3f;--pmbr:%.3f;', $pw / 15, $ml / 15, $mr / 15, $mt / 15, $mb / 15,
            $ml / $pw * 100, $mr / $pw * 100, $mt / $pw * 100, $mb / $pw * 100);
        if (!empty($base['sz'])) {
            $style .= 'font-size:' . max(6, min(28, (float)$base['sz'])) . 'pt;';
        }
        if (!empty($base['font'])) {
            $style .= 'font-family:' . OfficePreview::font((string)$base['font']) . ';';
        }
        $out = '<div class="op-doc ' . h($class) . '">';
        foreach (array_unique($doc['notes'] ?? []) as $n) {
            $out .= '<div class="op-note"><i class="bi bi-info-circle"></i> ' . h($n) . '</div>';
        }
        $out .= '<article class="op-page" style="' . h($style) . '">';
        if (!empty($doc['header'])) {
            $out .= '<header class="op-hdr">' . self::blocks($doc['header']) . '</header>';
        }
        $body = self::blocks($doc['blocks'] ?? []);
        $out .= '<div class="op-body">' . ($body !== '' ? $body : '<p class="op-p op-empty">(This document has no text.)</p>') . '</div>';
        if (!empty($doc['footer'])) {
            $out .= '<footer class="op-ftr">' . self::blocks($doc['footer']) . '</footer>';
        }
        if (self::$budget < 0) {
            $out .= '<div class="op-note"><i class="bi bi-scissors"></i> The quick view stops here; the page view or the download has the rest.</div>';
        }
        return $out . '</article></div>';
    }

    public static function blocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $b) {
            if (self::$budget < 0) {
                break;
            }
            $html = match ($b['t'] ?? '') {
                'p' => self::paraHtml($b),
                'table' => self::tableHtml($b),
                'pb' => '<hr class="op-pagebreak">',
                'box' => '<div class="op-box">' . self::blocks($b['b'] ?? []) . '</div>',
                'img' => '<div class="op-imgblock"' . (($b['al'] ?? '') === 'center' ? ' style="text-align:center"' : '') . '>' . $b['html'] . '</div>',
                'gallery' => '<div class="op-section-title">Pictures in this file</div><div class="op-gallery">' . implode('', array_map(fn($i) => '<figure>' . $i . '</figure>', $b['imgs'] ?? [])) . '</div>',
                default => '',
            };
            self::$budget -= strlen($html);
            $out .= $html;
        }
        return $out;
    }

    private static function paraHtml(array $b): string
    {
        $style = '';
        if (!empty($b['al']) && in_array($b['al'], ['left', 'center', 'right', 'justify'], true)) {
            $style .= 'text-align:' . $b['al'] . ';';
        }
        $ml = (int)($b['ml'] ?? 0);
        $ti = (int)($b['ti'] ?? 0);
        if ($ml > 0) {
            $style .= 'margin-left:' . round(min(6000, $ml) / 20, 1) . 'pt;';
        }
        if ($ti !== 0) {
            $style .= 'text-indent:' . round(max(-3000, min(3000, $ti)) / 20, 1) . 'pt;';
        }
        if (isset($b['mt']) && $b['mt'] !== null) {
            $style .= 'margin-top:' . round(max(0, min(1440, (int)$b['mt'])) / 20, 1) . 'pt;';
        }
        if (isset($b['mb']) && $b['mb'] !== null) {
            $style .= 'margin-bottom:' . round(max(0, min(1440, (int)$b['mb'])) / 20, 1) . 'pt;';
        }
        if (!empty($b['bg']) && preg_match('/^#[0-9a-f]{6}$/', $b['bg'])) {
            $style .= 'background:' . $b['bg'] . ';';
        }
        // tab stops: the paragraph's first stop (from where its text starts), else the document's default stops
        $hasTab = false;
        foreach ($b['runs'] ?? [] as $r) {
            if (isset($r['tab'])) {
                $hasTab = true;
                break;
            }
        }
        if ($hasTab) {
            $stop = 0;
            foreach ($b['tabs'] ?? [] as $t) {
                if ((int)$t - max(0, $ml) > 60) {
                    $stop = (int)$t - max(0, $ml);
                    break;
                }
            }
            $stop = $stop ?: max(180, min(4320, (int)self::$defTab));
            $style .= 'tab-size:' . round(min(8640, $stop) / 20, 1) . 'pt;';
        }
        $h = (int)($b['h'] ?? 0);
        $tag = $h >= 1 && $h <= 6 ? 'h' . $h : 'p';
        $inner = '';
        if (($b['num'] ?? '') !== '') {
            $inner .= '<span class="op-num">' . h($b['num']) . '</span>';
        }
        $inner .= self::runs($b['runs'] ?? []);
        if ($inner === '') {
            $inner = '<br>';
        }
        return '<' . $tag . ' class="op-p' . ($h ? ' op-h' : '') . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '') . '>' . $inner . '</' . $tag . '>';
    }

    public static function runs(array $runs): string
    {
        $out = '';
        $buf = '';
        $cur = null;
        $flush = function () use (&$out, &$buf, &$cur) {
            if ($buf !== '') {
                $css = self::css($cur ?? []);
                $out .= $css !== '' ? '<span style="' . h($css) . '">' . h($buf) . '</span>' : h($buf);
            }
            $buf = '';
        };
        foreach ($runs as $r) {
            if (isset($r['x'])) {
                $f = $r['f'] ?? [];
                if ($cur !== null && $f != $cur) {
                    $flush();
                }
                $cur = $f;
                $buf .= $r['x'];
                continue;
            }
            $flush();
            if (isset($r['br'])) {
                $out .= '<br>';
            } elseif (isset($r['tab'])) {
                // a real tab: the browser moves it to the next tab stop (tab-size of the paragraph), as Word does
                $out .= "\t";
            } elseif (isset($r['img'])) {
                $out .= $r['img'];
            }
        }
        $flush();
        return $out;
    }

    public static function css(array $f): string
    {
        $s = '';
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
        if (!empty($f['c']) && preg_match('/^#[0-9a-f]{6}$/', $f['c'])) {
            $s .= 'color:' . $f['c'] . ';';
        }
        $sz = isset($f['sz']) ? max(4, min(96, (float)$f['sz'])) : 0;
        if (!empty($f['va'])) {
            $s .= 'vertical-align:' . ($f['va'] === 'sup' ? 'super' : 'sub') . ';font-size:' . ($sz ? round($sz * 0.65, 1) . 'pt' : 'smaller') . ';';
        } elseif ($sz) {
            $s .= 'font-size:' . $sz . 'pt;';
        }
        if (!empty($f['font'])) {
            $s .= 'font-family:' . OfficePreview::font((string)$f['font']) . ';';
        }
        if (!empty($f['hl']) && preg_match('/^#[0-9a-f]{6}$/', $f['hl'])) {
            $s .= 'background-color:' . $f['hl'] . ';';
        }
        if (!empty($f['caps'])) {
            $s .= 'text-transform:uppercase;';
        }
        if (!empty($f['sc'])) {
            $s .= 'font-variant:small-caps;';
        }
        return $s;
    }

    private static function tableHtml(array $t): string
    {
        $cols = array_map('intval', $t['cols'] ?? []);
        $total = array_sum($cols);
        $w = (int)($t['w'] ?? 0) ?: $total;
        $pct = $w > 0 ? max(20, min(100, round($w / self::$contentW * 100, 1))) : 100;
        $out = '<div class="op-tablewrap"><table class="op-table' . (empty($t['border']) ? ' op-nob' : '') . '" style="width:' . $pct . '%">';
        $ncols = 0;
        foreach ($t['rows'] ?? [] as $r) {
            $n = 0;
            foreach ($r as $c) {
                $n += max(1, (int)($c['cs'] ?? 1));
            }
            $ncols = max($ncols, $n);
        }
        if ($total > 0 && count($cols) >= $ncols) {
            $out .= '<colgroup>';
            foreach ($cols as $c) {
                $out .= '<col style="width:' . round($c / $total * 100, 2) . '%">';
            }
            $out .= '</colgroup>';
        }
        $out .= '<tbody>';
        foreach ($t['rows'] ?? [] as $r) {
            $out .= '<tr>';
            foreach ($r as $c) {
                $st = '';
                if (!empty($c['bg']) && preg_match('/^#[0-9a-f]{6}$/', $c['bg'])) {
                    $st .= 'background:' . $c['bg'] . ';';
                }
                if (!empty($c['va']) && in_array($c['va'], ['middle', 'bottom'], true)) {
                    $st .= 'vertical-align:' . $c['va'] . ';';
                }
                $cs = max(1, min(200, (int)($c['cs'] ?? 1)));
                $rs = max(1, min(5000, (int)($c['rs'] ?? 1)));
                $out .= '<td' . ($cs > 1 ? ' colspan="' . $cs . '"' : '') . ($rs > 1 ? ' rowspan="' . $rs . '"' : '') . (!empty($c['bd']) ? ' class="op-bd"' : '')
                    . ($st !== '' ? ' style="' . h($st) . '"' : '') . '>' . self::blocks($c['b'] ?? []) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }
}
