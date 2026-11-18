<?php
declare(strict_types=1);

/**
 * Rich Text Format (.rtf, and .doc files that are RTF inside), as written by every Word since Word 1.0 and by WordPad:
 * text in its code page and Unicode, bold / italic / underline / strike, sizes, colours, fonts, highlight, super- and
 * subscript, alignment, indents, spacing, styles and headings, list labels, tables (widths, merged cells, borders,
 * cell colours), fields (their results), page breaks, headers and footers, PNG / JPEG / bitmap pictures and text boxes.
 */
final class OfficeRtf
{
    private const DEST_SKIP = ['info', 'listtable', 'listoverridetable', 'revtbl', 'rsidtbl', 'generator', 'xmlnstbl', 'themedata', 'colorschememapping', 'datastore',
        'latentstyles', 'pgdsctbl', 'objdata', 'nonshppict', 'fldinst', 'bkmkstart', 'bkmkend', 'footnote', 'annotation', 'atnid', 'atnauthor', 'atndate', 'atnref',
        'xe', 'tc', 'tcn', 'txe', 'docvar', 'userprops', 'shprslt', 'sn', 'pn', 'nesttableprops', 'nonesttables', 'mmathPr', 'fchars', 'lchars', 'headerl', 'footerl',
        'pnseclvl', 'pntxta', 'pntxtb', 'template', 'operator', 'author', 'title', 'subject', 'keywords', 'comment', 'doccomm', 'company', 'category', 'manager',
        'hlinkbase', 'falt', 'panose', 'fontemb', 'fontfile', 'filetbl', 'stylesheet_skip', 'wgrffmtfilter', 'ftnsep', 'ftnsepc', 'ftncn', 'aftnsep', 'aftnsepc',
        'aftncn', 'blipuid', 'passwordhash', 'protusertbl', 'mhtmltag', 'htmltag', 'factoidname', 'ud', 'background', 'listpicture', 'oleclsid', 'picprop',
        'formfield', 'datafield', 'ffdeftext', 'ffname', 'ffentrymcr', 'ffexitmcr', 'ffformat', 'ffhelptext', 'ffl', 'ffstattext', 'ebcstart', 'ebcend', 'bookmark'];
    private const CHARSET = [0 => 1252, 77 => 10000, 128 => 932, 129 => 949, 134 => 936, 136 => 950, 161 => 1253, 162 => 1254, 163 => 1258, 177 => 1255, 178 => 1256,
        186 => 1257, 204 => 1251, 222 => 874, 238 => 1250];

    private string $s;
    private int $i = 0;
    private int $n;
    private array $st;
    private array $stack = [];
    private int $codepage = 1252;
    private int $deff = 0;
    private array $fonts = [];
    private array $colors = [];
    private array $cur = [];
    private array $styleH = [];
    private string $styleName = '';
    private int $styleId = -1;
    private int $styleLvl = -1;
    private array $out = [];
    private string $buf = '';
    private array $page = [];
    private int $count = 0;
    private array $notes = [];
    private array $pict = [];

    public static function read(string $data): array
    {
        $me = new self();
        $me->s = $data;
        $me->n = strlen($data);
        return $me->parse();
    }

    private function parse(): array
    {
        $this->st = ['dest' => 'text', 'target' => 'main', 'f' => [], 'p' => self::pard(), 'uc' => 1, 'font' => -1];
        foreach (['main', 'header', 'footer', 'headerf', 'footerf'] as $t) {
            $this->out[$t] = ['blocks' => [], 'runs' => [], 'tbl' => null, 'def' => self::trowd(), 'used' => false];
        }
        $s = $this->s;
        $n = $this->n;
        $skipChars = 0;
        while ($this->i < $n) {
            $c = $s[$this->i];
            if ($c === '{') {
                $this->flush();
                $this->stack[] = $this->st;
                $this->i++;
                if (substr($s, $this->i, 2) === '\\*') {
                    // an ignorable destination: read only when it is one this reader knows
                    $this->i += 2;
                    if (preg_match('/\G\\\\([a-zA-Z]+)/', $s, $m, 0, $this->i)) {
                        if (!in_array($m[1], ['shppict', 'shpinst', 'fldinst', 'listtext', 'pntext', 'shptxt'], true) || $this->st['dest'] === 'skip') {
                            $this->skipGroup();
                        }
                    } else {
                        $this->skipGroup();
                    }
                } elseif ($this->st['dest'] === 'skip') {
                    $this->skipGroup();
                }
                continue;
            }
            if ($c === '}') {
                $this->flush();
                $this->endGroup();
                $this->i++;
                if (count($this->stack) === 0 && $this->i > 2) {
                    break;
                }
                continue;
            }
            if ($c === '\\') {
                $this->control($skipChars);
                continue;
            }
            if ($c === "\r" || $c === "\n") {
                $this->i++;
                continue;
            }
            // text
            if ($skipChars > 0) {
                $skipChars--;
                $this->i++;
                continue;
            }
            $this->text($c);
            $this->i++;
        }
        $this->flush();
        foreach (array_keys($this->out) as $t) {
            $this->endPara($t, false);
            $this->closeTable($t);
        }
        $header = $this->out['header']['blocks'] ?: $this->out['headerf']['blocks'];
        $footer = $this->out['footer']['blocks'] ?: $this->out['footerf']['blocks'];
        return OfficeWord::doc($this->out['main']['blocks'], $header, $footer, $this->page, ['sz' => 12, 'font' => $this->fonts[$this->deff]['name'] ?? ''], array_values(array_unique($this->notes)));
    }

    private static function pard(): array
    {
        return ['al' => '', 'ml' => 0, 'ti' => 0, 'mt' => null, 'mb' => null, 'tbl' => false, 'itap' => 0, 'h' => 0, 'bg' => '', 'pbb' => false, 'tabs' => []];
    }

    private static function trowd(): array
    {
        return ['x' => [], 'cells' => [], 'left' => 0, 'pend' => ['merge' => 0, 'vm' => 0, 'bg' => '', 'bd' => false], 'border' => false, 'brdSide' => ''];
    }

    private function skipGroup(): void
    {
        // from inside a group: to its closing brace (the brace is left for the main loop)
        $depth = 1;
        $s = $this->s;
        while ($this->i < $this->n) {
            $c = $s[$this->i];
            if ($c === '\\') {
                if (preg_match('/\G\\\\bin(-?\d+) ?/', $s, $m, 0, $this->i)) {
                    $this->i += strlen($m[0]) + max(0, (int)$m[1]);
                    continue;
                }
                $this->i += 2;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return;
                }
            }
            $this->i++;
        }
    }

    private function endGroup(): void
    {
        $st = $this->st;
        $prev = array_pop($this->stack);
        // a picture ends with its own group (not with the groups inside it)
        if ($st['dest'] === 'pict' && ($prev['dest'] ?? '') !== 'pict' && $this->pict) {
            $this->picture();
            $this->pict = [];
        }
        if ($st['dest'] === 'stylesheet' && $this->styleId >= 0) {
            $this->addStyle();
        }
        if ($prev === null) {
            return;
        }
        // a header or footer ends with its group
        if ($st['target'] !== $prev['target']) {
            $this->endPara($st['target'], false);
            $this->closeTable($st['target']);
        }
        $this->st = $prev;
    }

    private function text(string $c): void
    {
        $d = $this->st['dest'];
        if ($d === 'text') {
            $this->buf .= $c;
        } elseif ($d === 'pict') {
            $this->pict['hex'] = ($this->pict['hex'] ?? '') . $c;
        } elseif ($d === 'fonttbl') {
            if ($c === ';') {
                if ($this->st['font'] >= 0) {
                    $this->fonts[$this->st['font']]['name'] = trim($this->fonts[$this->st['font']]['name'] ?? '');
                }
            } elseif ($this->st['font'] >= 0) {
                $this->fonts[$this->st['font']]['name'] = ($this->fonts[$this->st['font']]['name'] ?? '') . $c;
            }
        } elseif ($d === 'colortbl') {
            if ($c === ';') {
                $this->colors[] = isset($this->cur['r']) || isset($this->cur['g']) || isset($this->cur['b'])
                    ? sprintf('#%02x%02x%02x', min(255, $this->cur['r'] ?? 0), min(255, $this->cur['g'] ?? 0), min(255, $this->cur['b'] ?? 0)) : '';
                $this->cur = [];
            }
        } elseif ($d === 'stylesheet') {
            if ($c === ';') {
                $this->addStyle();
            } else {
                $this->styleName .= $c;
            }
        }
    }

    private function addStyle(): void
    {
        if ($this->styleId >= 0) {
            $name = strtolower(trim($this->styleName));
            $h = $this->styleLvl >= 0 && $this->styleLvl < 6 ? $this->styleLvl + 1 : (preg_match('/^heading\s*([1-9])/', $name, $m) ? min(6, (int)$m[1]) : ($name === 'title' ? 1 : 0));
            $this->styleH[$this->styleId] = $h;
        }
        $this->styleId = -1;
        $this->styleName = '';
        $this->styleLvl = -1;
    }

    /** The bytes of text read so far, in the code page of their font, as a run */
    private function flush(): void
    {
        if ($this->buf === '') {
            return;
        }
        $b = $this->buf;
        $this->buf = '';
        $font = $this->fonts[$this->st['f']['font'] ?? $this->deff] ?? [];
        $f = $this->st['f'];
        if (!empty($f['hide'])) {
            return;
        }
        if (($font['charset'] ?? 0) === 2 || preg_match('/symbol|wingdings|webdings/i', $font['name'] ?? '')) {
            $t = '';
            foreach (str_split($b) as $ch) {
                $t .= ord($ch) > 0x20 ? OfficePreview::sym(ord($ch)) : $ch;
            }
            unset($f['font']);
        } else {
            $cp = isset($font['charset']) && isset(self::CHARSET[$font['charset']]) ? self::CHARSET[$font['charset']] : $this->codepage;
            $t = OfficePreview::cp($b, $cp);
        }
        $this->addRun(['x' => $t, 'f' => $this->fmt($f)]);
    }

    private function fmt(array $f): array
    {
        $out = array_intersect_key($f, ['b' => 1, 'i' => 1, 'u' => 1, 's' => 1, 'sz' => 1, 'va' => 1, 'caps' => 1, 'sc' => 1]);
        if (isset($f['cf'])) {
            $out['c'] = $this->colors[$f['cf']] ?? '';
        }
        if (isset($f['hl'])) {
            $out['hl'] = $this->colors[$f['hl']] ?? '';
        }
        if (isset($f['font'])) {
            $name = $this->fonts[$f['font']]['name'] ?? '';
            if ($name !== '' && $name !== ($this->fonts[$this->deff]['name'] ?? '')) {
                $out['font'] = $name;
            }
        }
        if (isset($out['sz']) && $out['sz'] == 12) {
            unset($out['sz']);
        }
        return $out;
    }

    private function addRun(array $r): void
    {
        $t = $this->st['target'];
        if (in_array($this->st['dest'], ['text'], true)) {
            $this->out[$t]['runs'][] = $r;
            $this->out[$t]['used'] = true;
        }
    }

    private function control(int &$skipChars): void
    {
        $s = $this->s;
        $next = $s[$this->i + 1] ?? '';
        if ($next === "'") {
            $hex = substr($s, $this->i + 2, 2);
            $this->i += 4;
            if ($skipChars > 0) {
                $skipChars--;
                return;
            }
            if ($this->st['dest'] === 'text') {
                $this->buf .= chr((int)hexdec($hex));
            } elseif ($this->st['dest'] === 'fonttbl' && $this->st['font'] >= 0) {
                $this->fonts[$this->st['font']]['name'] = ($this->fonts[$this->st['font']]['name'] ?? '') . OfficePreview::cp(chr((int)hexdec($hex)), $this->codepage);
            } elseif ($this->st['dest'] === 'stylesheet') {
                $this->styleName .= chr((int)hexdec($hex));
            }
            return;
        }
        if (!ctype_alpha($next)) {
            // control symbols
            $this->i += 2;
            $map = ['\\' => '\\', '{' => '{', '}' => '}', '~' => "\u{00A0}", '_' => '‑', '-' => '', '|' => '', ':' => ''];
            if ($next === "\n" || $next === "\r") {
                $this->flush();
                $this->word('par', null);
                return;
            }
            if (isset($map[$next]) && $map[$next] !== '') {
                if ($this->st['dest'] === 'text') {
                    $this->flush();
                    $this->addRun(['x' => $map[$next], 'f' => $this->fmt($this->st['f'])]);
                } else {
                    $this->text($map[$next]);
                }
            }
            return;
        }
        if (!preg_match('/\G\\\\([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $s, $m, 0, $this->i)) {
            $this->i++;
            return;
        }
        $this->i += strlen($m[0]);
        $word = $m[1];
        $param = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null;
        if ($word === 'bin') {
            $len = max(0, (int)$param);
            if ($this->st['dest'] === 'pict') {
                $this->pict['bin'] = substr($s, $this->i, $len);
            }
            $this->i += $len;
            return;
        }
        if ($word === 'u') {
            $this->flush();
            $cp = (int)$param;
            if ($cp < 0) {
                $cp += 65536;
            }
            if ($this->st['dest'] === 'text' && empty($this->st['f']['hide'])) {
                $ch = $cp >= 0xD800 && $cp <= 0xDFFF ? '' : mb_chr($cp);
                $font = $this->fonts[$this->st['f']['font'] ?? $this->deff] ?? [];
                if (($cp >= 0xF000 && $cp <= 0xF0FF) || ($cp < 0x100 && ($font['charset'] ?? 0) === 2)) {
                    $ch = OfficePreview::sym($cp);
                }
                $this->addRun(['x' => $ch, 'f' => $this->fmt($this->st['f'])]);
            } elseif ($this->st['dest'] === 'fonttbl' && $this->st['font'] >= 0) {
                $this->fonts[$this->st['font']]['name'] = ($this->fonts[$this->st['font']]['name'] ?? '') . mb_chr($cp);
            }
            $skipChars = $this->st['uc'];
            return;
        }
        $skipChars = 0;
        $this->flush();
        $this->word($word, $param);
    }

    private function word(string $w, ?int $p): void
    {
        $t = $this->st['target'];
        $on = $p === null || $p !== 0;
        // destinations
        if ($this->st['dest'] !== 'skip') {
            if (in_array($w, self::DEST_SKIP, true)) {
                $this->st['dest'] = 'skip';
                $this->skipGroup();
                return;
            }
            switch ($w) {
                case 'fonttbl': $this->st['dest'] = 'fonttbl'; return;
                case 'colortbl': $this->st['dest'] = 'colortbl'; $this->cur = []; return;
                case 'stylesheet': $this->st['dest'] = 'stylesheet'; return;
                case 'pict': $this->st['dest'] = 'pict'; $this->pict = ['type' => '', 'hex' => '', 'w' => 0, 'h' => 0, 'gw' => 0, 'gh' => 0, 'sx' => 100, 'sy' => 100]; return;
                case 'header':
                case 'headerr':
                case 'footer':
                case 'footerr':
                case 'headerf':
                case 'footerf':
                    $target = in_array($w, ['header', 'headerr'], true) ? 'header' : (in_array($w, ['footer', 'footerr'], true) ? 'footer' : $w);
                    if ($this->out[$target]['used']) {
                        $this->st['dest'] = 'skip';
                        $this->skipGroup();
                        return;
                    }
                    $this->st['target'] = $target;
                    $this->st['p'] = self::pard();
                    $this->out[$target]['used'] = true;
                    return;
                case 'sp':
                case 'sv':
                    $this->st['dest'] = 'notext';
                    return;
                case 'shptxt':
                case 'fldrslt':
                case 'result':
                case 'shppict':
                case 'listtext':
                case 'pntext':
                    $this->st['dest'] = 'text';
                    return;
            }
        }
        // tables of the head
        if ($this->st['dest'] === 'fonttbl') {
            if ($w === 'f') {
                $this->st['font'] = (int)$p;
            } elseif ($w === 'fcharset' && $this->st['font'] >= 0) {
                $this->fonts[$this->st['font']]['charset'] = (int)$p;
            }
            return;
        }
        if ($this->st['dest'] === 'colortbl') {
            if (in_array($w, ['red', 'green', 'blue'], true)) {
                $this->cur[$w[0]] = (int)$p;
            }
            return;
        }
        if ($this->st['dest'] === 'stylesheet') {
            if ($w === 's') {
                $this->styleId = (int)$p;
            } elseif ($w === 'outlinelevel') {
                $this->styleLvl = (int)$p;
            } elseif (in_array($w, ['cs', 'ds', 'ts'], true)) {
                $this->styleId = -1;
            }
            return;
        }
        if ($this->st['dest'] === 'pict') {
            switch ($w) {
                case 'pngblip': $this->pict['type'] = 'png'; break;
                case 'jpegblip': $this->pict['type'] = 'jpeg'; break;
                case 'dibitmap': $this->pict['type'] = 'dib'; break;
                case 'emfblip':
                case 'wmetafile':
                case 'macpict':
                case 'wbitmap':
                case 'pmmetafile': $this->pict['type'] = 'meta'; break;
                case 'picw': $this->pict['w'] = (int)$p; break;
                case 'pich': $this->pict['h'] = (int)$p; break;
                case 'picwgoal': $this->pict['gw'] = (int)$p; break;
                case 'pichgoal': $this->pict['gh'] = (int)$p; break;
                case 'picscalex': $this->pict['sx'] = (int)$p ?: 100; break;
                case 'picscaley': $this->pict['sy'] = (int)$p ?: 100; break;
            }
            return;
        }
        if ($this->st['dest'] !== 'text' && $this->st['dest'] !== 'notext') {
            return;
        }
        switch ($w) {
            // document
            case 'ansicpg': $this->codepage = (int)$p ?: 1252; break;
            case 'mac': $this->codepage = 10000; break;
            case 'deff': $this->deff = (int)$p; break;
            case 'paperw': $this->page['w'] = (int)$p; break;
            case 'paperh': $this->page['h'] = (int)$p; break;
            case 'margl': $this->page['ml'] = (int)$p; break;
            case 'margr': $this->page['mr'] = (int)$p; break;
            case 'margt': $this->page['mt'] = (int)$p; break;
            case 'margb': $this->page['mb'] = (int)$p; break;
            case 'uc': $this->st['uc'] = max(0, (int)$p); break;
            // characters
            case 'plain': $this->st['f'] = []; break;
            case 'b': $this->st['f']['b'] = $on; break;
            case 'i': $this->st['f']['i'] = $on; break;
            case 'ul':
            case 'uld':
            case 'uldb':
            case 'uldash':
            case 'uldashd':
            case 'uldashdd':
            case 'ulth':
            case 'ulw':
            case 'ulwave':
            case 'uldb0': $this->st['f']['u'] = $on; break;
            case 'ulnone': $this->st['f']['u'] = false; break;
            case 'strike':
            case 'striked': $this->st['f']['s'] = $on; break;
            case 'fs': $this->st['f']['sz'] = max(1, (int)$p) / 2; break;
            case 'cf': $this->st['f']['cf'] = (int)$p; break;
            case 'highlight':
            case 'cb':
            case 'chcbpat': if ((int)$p > 0) { $this->st['f']['hl'] = (int)$p; } else { unset($this->st['f']['hl']); } break;
            case 'f': $this->st['f']['font'] = (int)$p; break;
            case 'super': $this->st['f']['va'] = 'sup'; break;
            case 'sub': $this->st['f']['va'] = 'sub'; break;
            case 'nosupersub': $this->st['f']['va'] = ''; break;
            case 'caps': $this->st['f']['caps'] = $on; break;
            case 'scaps': $this->st['f']['sc'] = $on; break;
            case 'v': $this->st['f']['hide'] = $on; break;
            // paragraphs
            case 'pard': $this->st['p'] = self::pard(); break;
            case 'ql': $this->st['p']['al'] = 'left'; break;
            case 'qc': $this->st['p']['al'] = 'center'; break;
            case 'qr': $this->st['p']['al'] = 'right'; break;
            case 'qj':
            case 'qd': $this->st['p']['al'] = 'justify'; break;
            case 'li':
            case 'lin': $this->st['p']['ml'] = (int)$p; break;
            case 'fi': $this->st['p']['ti'] = (int)$p; break;
            case 'sb': $this->st['p']['mt'] = (int)$p; break;
            case 'sa': $this->st['p']['mb'] = (int)$p; break;
            case 'intbl': $this->st['p']['tbl'] = true; break;
            case 'itap': $this->st['p']['itap'] = (int)$p; $this->st['p']['tbl'] = (int)$p > 0; break;
            case 's': $this->st['p']['h'] = $this->styleH[(int)$p] ?? 0; break;
            case 'outlinelevel': $this->st['p']['h'] = (int)$p < 6 ? (int)$p + 1 : 0; break;
            case 'pagebb': $this->st['p']['pbb'] = true; break;
            case 'tx': $this->st['p']['tabs'][] = (int)$p; break;
            case 'deftab': $this->page['tab'] = (int)$p; break;
            case 'cbpat': $this->st['p']['bg'] = $this->colors[(int)$p] ?? ''; break;
            // breaks and special characters
            case 'par': $this->endPara($t, true); break;
            case 'line':
            case 'column': $this->addRun(['br' => 1]); break;
            case 'tab': $this->addRun(['tab' => 1]); break;
            case 'page': $this->endPara($t, false); $this->out[$t]['blocks'][] = ['t' => 'pb']; break;
            case 'sect': $this->endPara($t, false); break;
            case 'emdash': $this->addRun(['x' => '—', 'f' => $this->fmt($this->st['f'])]); break;
            case 'endash': $this->addRun(['x' => '–', 'f' => $this->fmt($this->st['f'])]); break;
            case 'bullet': $this->addRun(['x' => '•', 'f' => $this->fmt($this->st['f'])]); break;
            case 'lquote': $this->addRun(['x' => '‘', 'f' => $this->fmt($this->st['f'])]); break;
            case 'rquote': $this->addRun(['x' => '’', 'f' => $this->fmt($this->st['f'])]); break;
            case 'ldblquote': $this->addRun(['x' => '“', 'f' => $this->fmt($this->st['f'])]); break;
            case 'rdblquote': $this->addRun(['x' => '”', 'f' => $this->fmt($this->st['f'])]); break;
            case 'emspace':
            case 'enspace':
            case 'qmspace': $this->addRun(['x' => ' ', 'f' => $this->fmt($this->st['f'])]); break;
            // tables
            case 'trowd': $this->out[$t]['def'] = self::trowd(); break;
            case 'trleft': $this->out[$t]['def']['left'] = (int)$p; break;
            case 'cellx':
                $d = &$this->out[$t]['def'];
                $d['x'][] = (int)$p;
                $d['cells'][] = $d['pend'];
                $d['pend'] = ['merge' => 0, 'vm' => 0, 'bg' => '', 'bd' => false];
                unset($d);
                break;
            case 'clmgf': $this->out[$t]['def']['pend']['merge'] = 1; break;
            case 'clmrg': $this->out[$t]['def']['pend']['merge'] = 2; break;
            case 'clvmgf': $this->out[$t]['def']['pend']['vm'] = 2; break;
            case 'clvmrg': $this->out[$t]['def']['pend']['vm'] = 1; break;
            case 'clcbpat': $this->out[$t]['def']['pend']['bg'] = $this->colors[(int)$p] ?? ''; break;
            case 'clbrdrt':
            case 'clbrdrl':
            case 'clbrdrb':
            case 'clbrdrr': $this->out[$t]['def']['brdSide'] = 'cell'; break;
            case 'trbrdrt':
            case 'trbrdrl':
            case 'trbrdrb':
            case 'trbrdrr':
            case 'trbrdrh':
            case 'trbrdrv': $this->out[$t]['def']['brdSide'] = 'row'; break;
            case 'brdrs':
            case 'brdrth':
            case 'brdrdb':
            case 'brdrdot':
            case 'brdrdash':
            case 'brdrsh':
            case 'brdrw':
                if ($this->out[$t]['def']['brdSide'] === 'cell') {
                    $this->out[$t]['def']['pend']['bd'] = true;
                } elseif ($this->out[$t]['def']['brdSide'] === 'row') {
                    $this->out[$t]['def']['border'] = true;
                }
                break;
            case 'brdrnone':
            case 'brdrnil':
                break;
            case 'cell': $this->cell($t); break;
            case 'nestcell': $this->endPara($t, false); break;
            case 'row': $this->row($t); break;
        }
    }

    // ------------------------------------------------------------------ paragraphs and tables

    private function endPara(string $t, bool $force): void
    {
        $o = &$this->out[$t];
        if (!$force && !$o['runs']) {
            unset($o);
            return;
        }
        $pp = $this->st['p'];
        if ($this->st['target'] !== $t) {
            $pp = self::pard();
        }
        if ($this->count++ > OfficeWord::MAX_BLOCKS) {
            $this->notes[] = 'The quick view shows the first part of this long document; the page view or the download has all of it.';
            $o['runs'] = [];
            unset($o);
            return;
        }
        $runs = $o['runs'];
        // a list label ends with a tab: one space is enough
        $p = ['t' => 'p', 'h' => $pp['h'], 'al' => $pp['al'], 'ml' => $pp['ml'], 'ti' => $pp['ti'], 'mt' => $pp['mt'], 'mb' => $pp['mb'], 'bg' => $pp['bg'], 'num' => '', 'runs' => $runs, 'tabs' => $pp['tabs']];
        $o['runs'] = [];
        if ($pp['tbl']) {
            $o['tbl'] ??= ['rows' => [], 'cur' => [], 'cell' => []];
            $o['tbl']['cell'][] = $p;
        } else {
            unset($o);
            $this->closeTable($t);
            $o = &$this->out[$t];
            if ($pp['pbb']) {
                $o['blocks'][] = ['t' => 'pb'];
            }
            $o['blocks'][] = $p;
        }
        unset($o);
    }

    private function cell(string $t): void
    {
        $o = &$this->out[$t];
        if ($o['runs']) {
            $saved = $this->st['p']['tbl'];
            $this->st['p']['tbl'] = true;
            unset($o);
            $this->endPara($t, true);
            $this->st['p']['tbl'] = $saved;
            $o = &$this->out[$t];
        }
        $o['tbl'] ??= ['rows' => [], 'cur' => [], 'cell' => []];
        $o['tbl']['cur'][] = $o['tbl']['cell'];
        $o['tbl']['cell'] = [];
        unset($o);
    }

    private function row(string $t): void
    {
        $o = &$this->out[$t];
        if ($o['runs']) {
            unset($o);
            $this->cell($t);
            $o = &$this->out[$t];
        }
        $o['tbl'] ??= ['rows' => [], 'cur' => [], 'cell' => []];
        $o['tbl']['rows'][] = ['cells' => $o['tbl']['cur'], 'def' => $o['def']];
        $o['tbl']['cur'] = [];
        $o['tbl']['cell'] = [];
        unset($o);
    }

    private function closeTable(string $t): void
    {
        $o = &$this->out[$t];
        $tb = $o['tbl'];
        if (!$tb) {
            unset($o);
            return;
        }
        if ($tb['cur'] || $tb['cell']) {
            if ($tb['cell']) {
                $tb['cur'][] = $tb['cell'];
            }
            $tb['rows'][] = ['cells' => $tb['cur'], 'def' => $o['def']];
        }
        $rows = [];
        $border = false;
        foreach ($tb['rows'] as $r) {
            $def = $r['def'];
            $border = $border || $def['border'];
            $cells = [];
            $prevX = $def['left'];
            foreach ($r['cells'] as $i => $blocks) {
                $cd = $def['cells'][$i] ?? ['merge' => 0, 'vm' => 0, 'bg' => '', 'bd' => false];
                $x = $def['x'][$i] ?? $prevX;
                $border = $border || $cd['bd'];
                if ($cd['merge'] === 2 && $cells) {
                    $cells[count($cells) - 1]['x1'] = max($cells[count($cells) - 1]['x1'], $x);
                    $prevX = $x;
                    continue;
                }
                $cells[] = ['b' => $blocks, 'x0' => $prevX, 'x1' => $x, 'vm' => $cd['vm'], 'bg' => $cd['bg'], 'va' => '', 'bd' => false];
                $prevX = $x;
            }
            $rows[] = $cells;
        }
        $o['blocks'][] = OfficeWord::gridTable($rows, $border);
        $o['tbl'] = null;
        unset($o);
    }

    // ------------------------------------------------------------------ pictures

    private function picture(): void
    {
        $p = $this->pict + ['type' => '', 'hex' => '', 'w' => 0, 'h' => 0, 'gw' => 0, 'gh' => 0, 'sx' => 100, 'sy' => 100];
        $bytes = $p['bin'] ?? (string)@hex2bin(preg_replace('/[^0-9a-fA-F]/', '', (string)$p['hex']) . (strlen(preg_replace('/[^0-9a-fA-F]/', '', (string)$p['hex'])) % 2 ? '0' : ''));
        $w = $p['gw'] ? $p['gw'] * $p['sx'] / 100 / 15 : $p['w'];
        $h = $p['gh'] ? $p['gh'] * $p['sy'] / 100 / 15 : $p['h'];
        $w = (int)max(8, min(1600, $w ?: 120));
        $h = (int)max(8, min(2400, $h ?: 80));
        if ($p['type'] === 'dib') {
            $bytes = OfficePreview::dibToBmp($bytes);
        }
        // PNG, JPEG, bitmaps, and the bitmaps kept in metafiles
        $html = $p['type'] !== '' ? OfficePreview::img($bytes, 'width:' . $w . 'px') : '';
        if ($html === '') {
            $this->notes[] = 'Pictures saved as Windows metafiles (WMF / EMF) show as grey boxes here; the page view shows them.';
            $html = OfficePreview::picBox('width:' . $w . 'px;height:' . $h . 'px');
        }
        $t = $this->st['target'];
        $this->out[$t]['runs'][] = ['img' => $html];
        $this->out[$t]['used'] = true;
    }
}
