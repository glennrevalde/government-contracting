<?php
declare(strict_types=1);

/**
 * Shows Microsoft Office files in the browser: Word (Word for DOS and Word 2.0 to Word 365: .doc, .dot, .docx, .docm,
 * .dotx, .dotm, .rtf), Excel (Excel 2.1 to Excel 365: .xls, .xlt, .xlsx, .xlsm, .xltx, .xltm, .xlsb, and .csv),
 * PowerPoint (PowerPoint 95 to 365: .ppt, .pps, .pot, .pptx, .pptm, .ppsx, .potx, .potm) and Publisher (98 to 365: .pub),
 * and the OpenDocument files of LibreOffice (.odt, .ods, .odp).
 *
 * Two ways, the best one available:
 *  - Page view: when LibreOffice is on the server (found by itself, or its program set in Contracting Settings), the
 *    file is turned into a PDF once and shown as it prints: the exact pages, fonts, pictures and layout.
 *  - Quick view: built in, nothing to install. The program reads the file itself (OfficeWord, OfficeSheet, OfficeSlides,
 *    OfficePublisher) and shows its text, headings, tables, lists, pictures, sheets and slides.
 * Both are made once per file (by its SHA-256) and kept in storage/contracts/previews; the original is never changed.
 */
final class OfficePreview
{
    /** The version of the built-in readers: a new one makes the quick views again */
    public const VERSION = 1;
    public const WORD = ['doc', 'dot', 'docx', 'docm', 'dotx', 'dotm', 'rtf', 'odt'];
    public const EXCEL = ['xls', 'xlt', 'xlsx', 'xlsm', 'xltx', 'xltm', 'xlsb', 'ods', 'csv'];
    public const POWERPOINT = ['ppt', 'pps', 'pot', 'pptx', 'pptm', 'ppsx', 'potx', 'potm', 'odp'];
    public const PUBLISHER = ['pub'];
    /** The quick view reads these by itself (the others need LibreOffice) */
    public const BUILT_IN = ['doc', 'dot', 'docx', 'docm', 'dotx', 'dotm', 'rtf', 'xls', 'xlt', 'xlsx', 'xlsm', 'xltx', 'xltm', 'xlsb', 'csv', 'txt',
        'ppt', 'pps', 'pot', 'pptx', 'pptm', 'ppsx', 'potx', 'potm', 'pub'];
    /** Largest file read by the quick view, and the time LibreOffice is given */
    public const MAX_BYTES = 52428800;
    public const TIMEOUT = 120;

    /** word, excel, powerpoint, publisher, text, or '' for a file that is not an Office file */
    public static function kind(string $ext): string
    {
        $ext = strtolower($ext);
        return match (true) {
            in_array($ext, self::WORD, true) => 'word',
            in_array($ext, self::EXCEL, true) => 'excel',
            in_array($ext, self::POWERPOINT, true) => 'powerpoint',
            in_array($ext, self::PUBLISHER, true) => 'publisher',
            $ext === 'txt' => 'text',
            default => '',
        };
    }

    public static function label(string $ext): string
    {
        $ext = strtolower($ext);
        return match ($ext) {
            'doc', 'dot' => 'Word 97-2003 document (or older)', 'docx', 'docm' => 'Word document', 'dotx', 'dotm' => 'Word template', 'rtf' => 'Rich Text document',
            'odt' => 'OpenDocument text', 'xls', 'xlt' => 'Excel 97-2003 workbook (or older)', 'xlsx', 'xlsm' => 'Excel workbook', 'xltx', 'xltm' => 'Excel template',
            'xlsb' => 'Excel binary workbook', 'ods' => 'OpenDocument spreadsheet', 'csv' => 'CSV spreadsheet', 'ppt' => 'PowerPoint 97-2003 presentation (or older)',
            'pps', 'ppsx' => 'PowerPoint slide show', 'pot', 'potx', 'potm' => 'PowerPoint template', 'pptx', 'pptm' => 'PowerPoint presentation', 'odp' => 'OpenDocument presentation',
            'pub' => 'Publisher publication', 'txt' => 'Text file', default => strtoupper($ext) . ' file',
        };
    }

    public static function icon(string $ext): string
    {
        return match (self::kind($ext)) {
            'word' => 'file-earmark-word', 'excel' => 'file-earmark-spreadsheet', 'powerpoint' => 'file-earmark-slides', 'publisher' => 'file-earmark-richtext', 'text' => 'file-earmark-text',
            default => 'file-earmark',
        };
    }

    public static function cacheDir(): string
    {
        $dir = BrgyDocs::dir() . '/previews';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    // ================================================================== LibreOffice: the page view

    /**
     * The LibreOffice program (soffice) to make the page view, or null: the one set in Contracting Settings ("off" to
     * use none), otherwise the usual places on Windows, Linux and macOS.
     */
    public static function converter(): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $set = trim((string)Brgy::setting('brgy_office_converter'));
        if (strtolower($set) === 'off') {
            return null;
        }
        $list = $set !== '' ? [$set] : [];
        if (!$list) {
            if (DIRECTORY_SEPARATOR === '\\') {
                foreach ([getenv('ProgramFiles') ?: 'C:\\Program Files', getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)', 'C:\\Program Files', 'C:\\Program Files (x86)'] as $pf) {
                    $list[] = $pf . '\\LibreOffice\\program\\soffice.exe';
                }
                foreach (glob('C:\\Program Files*\\LibreOffice*\\program\\soffice.exe') ?: [] as $g) {
                    $list[] = $g;
                }
            } else {
                $list = ['/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/usr/local/bin/libreoffice', '/snap/bin/libreoffice',
                    '/Applications/LibreOffice.app/Contents/MacOS/soffice', '/opt/homebrew/bin/soffice'];
                foreach (glob('/opt/libreoffice*/program/soffice') ?: [] as $g) {
                    $list[] = $g;
                }
            }
        }
        foreach ($list as $p) {
            // only the LibreOffice program itself: the setting can never name another command
            if (preg_match('/(^|[\\\\\/])(soffice|libreoffice)(\.exe|\.com|\.bin)?$/i', $p) && @is_file($p)) {
                // on Windows soffice.com waits until the file is made
                $com = preg_replace('/\.exe$/i', '.com', $p);
                return $com !== $p && @is_file($com) ? $com : $p;
            }
        }
        return null;
    }

    /** The cached PDF of the page view (made now when there is none yet), or null when it cannot be made */
    public static function pdf(string $file, string $ext, string $key, ?string &$error = null): ?string
    {
        $ext = strtolower($ext);
        if (self::kind($ext) === '' || self::kind($ext) === 'text') {
            $error = 'Not an Office file.';
            return null;
        }
        $out = self::cacheDir() . '/' . $key . '.pdf';
        if (is_file($out) && filesize($out) > 0) {
            return $out;
        }
        $fail = self::cacheDir() . '/' . $key . '.pdf-failed';
        if (is_file($fail) && filemtime($fail) > time() - 3600) {
            $error = (string)file_get_contents($fail);
            return null;
        }
        $soffice = self::converter();
        @set_time_limit(self::TIMEOUT + 30);
        if (!$soffice) {
            $error = 'LibreOffice is not installed on the server.';
            return null;
        }
        $tmp = self::cacheDir() . '/tmp_' . bin2hex(random_bytes(6));
        @mkdir($tmp, 0775, true);
        $src = $tmp . '/document.' . $ext;
        if (!@copy($file, $src)) {
            $error = 'The file could not be copied for LibreOffice.';
            self::rmdir($tmp);
            return null;
        }
        // one conversion at a time: LibreOffice uses one profile folder
        $lock = @fopen(self::cacheDir() . '/libreoffice.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            if (is_file($out) && filesize($out) > 0) {
                return $out;
            }
            $profile = self::cacheDir() . '/libreoffice-profile';
            @mkdir($profile, 0775, true);
            // a file URL: C:/xampp/... or /var/www/..., each part encoded (a folder name may hold spaces)
            $parts = explode('/', ltrim(str_replace('\\', '/', (string)realpath($profile)), '/'));
            $profileUrl = 'file:///' . implode('/', array_map(fn($x) => preg_match('/^[A-Za-z]:$/', $x) ? $x : rawurlencode($x), $parts));
            $cmd = [$soffice, '--headless', '--invisible', '--norestore', '--nolockcheck', '--nodefault', '--nofirststartwizard', '-env:UserInstallation=' . $profileUrl,
                '--convert-to', 'pdf', '--outdir', $tmp, $src];
            $log = '';
            $code = self::run($cmd, $tmp, $log);
            $made = $tmp . '/document.pdf';
            if ($code === 0 && is_file($made) && filesize($made) > 0) {
                rename($made, $out);
                @unlink($fail);
                return $out;
            }
            $error = $code === -9 ? 'LibreOffice took longer than ' . self::TIMEOUT . ' seconds.' : 'LibreOffice could not open the file' . ($log !== '' ? ': ' . mb_substr(trim($log), 0, 200) : '.');
            @file_put_contents($fail, $error);
            return null;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            self::rmdir($tmp);
        }
    }

    /**
     * Runs a program without a shell; -9 when it ran out of time. Its output goes to files (reading the pipes of a program
     * without waiting does not work on Windows), so the time limit holds on every system.
     */
    private static function run(array $cmd, string $cwd, string &$log): int
    {
        $env = null;
        if (DIRECTORY_SEPARATOR !== '\\') {
            $env = ['HOME' => self::cacheDir(), 'PATH' => (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'), 'LANG' => 'C.UTF-8'];
        }
        $outFile = $cwd . '/output.txt';
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $outFile, 'a']], $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (!is_resource($p)) {
            $log = 'The program could not be started.';
            return 1;
        }
        fclose($pipes[0]);
        $start = time();
        $code = 1;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) {
                $code = (int)$st['exitcode'];
                break;
            }
            if (time() - $start > self::TIMEOUT) {
                proc_terminate($p, 9);
                $code = -9;
                break;
            }
            usleep(150000);
        }
        proc_close($p);
        $log = is_file($outFile) ? (string)file_get_contents($outFile, false, null, 0, 4000) : '';
        return $code;
    }

    private static function rmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    // ================================================================== the quick view (built in)

    /**
     * The quick view of a file as HTML (kept once made): ['html' => ..., 'error' => '' or why it cannot be shown].
     * The HTML holds only what the readers write: every text escaped, styles from checked numbers and colours,
     * pictures as data: URLs of PNG, JPEG, GIF or BMP.
     */
    public static function html(string $file, string $ext, string $key): array
    {
        $ext = strtolower($ext);
        $cache = self::cacheDir() . '/' . $key . '.v' . self::VERSION . '.html';
        if (is_file($cache)) {
            $c = (string)file_get_contents($cache);
            return str_starts_with($c, '<!--error:') ? ['html' => '', 'error' => substr($c, 10, -3)] : ['html' => $c, 'error' => ''];
        }
        if (!in_array($ext, self::BUILT_IN, true)) {
            return ['html' => '', 'error' => 'The quick view does not read ' . strtoupper($ext) . ' files; LibreOffice on the server shows them.'];
        }
        if (!is_file($file)) {
            return ['html' => '', 'error' => 'The file is missing from the storage folder.'];
        }
        if (filesize($file) > self::MAX_BYTES) {
            return ['html' => '', 'error' => 'The file is too large for the quick view (over ' . (self::MAX_BYTES >> 20) . ' MB).'];
        }
        if (!class_exists('ZipArchive') && (string)file_get_contents($file, false, null, 0, 4) === "PK\x03\x04") {
            return ['html' => '', 'error' => 'The quick view of Office 2007 and later files needs the zip extension of PHP. In C:\\xampp\\php\\php.ini remove the ; before extension=zip, then restart Apache.'];
        }
        if (self::tooBig($file)) {
            return ['html' => '', 'error' => 'The parts of this file unpack too large for the quick view.'];
        }
        $prevLimit = ini_get('memory_limit');
        if ((int)$prevLimit > 0 && (int)$prevLimit < 512) {
            @ini_set('memory_limit', '512M');
        }
        @set_time_limit(120);
        try {
            $html = match (self::kind($ext)) {
                'word' => OfficeWord::render($file, $ext),
                'excel' => OfficeSheet::render($file, $ext),
                'powerpoint' => OfficeSlides::render($file, $ext),
                'publisher' => OfficePublisher::render($file),
                'text' => '<div class="op-doc op-text"><pre>' . h(self::utf8((string)file_get_contents($file, false, null, 0, 2000000))) . '</pre></div>',
                default => throw new RuntimeException('Not an Office file.'),
            };
            @file_put_contents($cache, $html);
            return ['html' => $html, 'error' => ''];
        } catch (OfficeLockedException $e) {
            $msg = $e->getMessage();
        } catch (Throwable $e) {
            $msg = 'The quick view could not read this file (' . mb_substr($e->getMessage(), 0, 160) . ').';
        }
        @file_put_contents($cache, '<!--error:' . str_replace('-->', '', $msg) . '-->');
        return ['html' => '', 'error' => $msg];
    }

    /** A zip package whose parts unpack far larger than a document's would (a "zip bomb") */
    private static function tooBig(string $file): bool
    {
        if (!class_exists('ZipArchive') || (string)file_get_contents($file, false, null, 0, 4) !== "PK\x03\x04") {
            return false;
        }
        $z = new ZipArchive();
        if ($z->open($file, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $total = 0;
        $big = $z->numFiles > 20000;
        for ($i = 0, $n = $z->numFiles; $i < $n && !$big; $i++) {
            $size = (int)($z->statIndex($i)['size'] ?? 0);
            $total += $size;
            $big = $size > 314572800 || $total > 1073741824;
        }
        $z->close();
        return $big;
    }

    // ================================================================== helpers of the readers

    /** Text of unknown encoding as UTF-8 (UTF-8 kept, Windows-1252 otherwise) */
    public static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        } elseif (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) {
            return (string)mb_convert_encoding(substr($s, 2), 'UTF-8', str_starts_with($s, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : self::cp($s, 1252);
    }

    /** 8-bit text of a code page (1252 when unknown) as UTF-8 */
    public static function cp(string $s, int $codepage = 1252): string
    {
        if ($s === '') {
            return '';
        }
        if ($codepage === 1200) {
            return (string)mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
        }
        if ($codepage === 65001) {
            return mb_check_encoding($s, 'UTF-8') ? $s : self::cp($s, 1252);
        }
        $enc = in_array($codepage, [874, 932, 936, 949, 950, 1250, 1251, 1252, 1253, 1254, 1255, 1256, 1257, 1258], true) ? 'CP' . $codepage : ($codepage === 10000 ? 'MACINTOSH' : 'CP1252');
        $out = function_exists('iconv') ? @iconv($enc, 'UTF-8//IGNORE', $s) : false;
        if ($out === false) {
            $out = (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $out;
    }

    /** UTF-16LE bytes as UTF-8 */
    public static function utf16(string $s): string
    {
        return $s === '' ? '' : (string)mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
    }

    /** "#rrggbb" from a hex colour (6 or 8 digits, ARGB), or '' */
    public static function color(?string $v): string
    {
        $v = strtoupper(trim((string)$v));
        if (preg_match('/^[0-9A-F]{8}$/', $v)) {
            $v = substr($v, 2);
        }
        return preg_match('/^[0-9A-F]{6}$/', $v) ? '#' . strtolower($v) : '';
    }

    /** A font name safe for CSS, with a fallback by its kind */
    public static function font(string $name): string
    {
        $name = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $name));
        $serif = preg_match('/times|cambria|georgia|garamond|book|roman|serif|palatino|century|bodoni|minion/i', $name);
        $mono = preg_match('/courier|mono|consol/i', $name);
        $stack = $mono ? '"Courier New", monospace' : ($serif ? '"Times New Roman", Cambria, Georgia, serif' : 'Calibri, Carlito, Arial, "Segoe UI", sans-serif');
        return ($name !== '' ? '"' . $name . '", ' : '') . $stack;
    }

    /** A picture as an <img> with a data: URL (PNG, JPEG, GIF, BMP, WebP), or '' for a kind a browser cannot show (EMF, WMF, TIFF) */
    public static function img(string $bytes, string $style = '', string $alt = ''): string
    {
        if ($bytes === '' || strlen($bytes) > 15728640) {
            return '';
        }
        $ok = ['image/png', 'image/jpeg', 'image/gif', 'image/bmp', 'image/x-ms-bmp', 'image/webp'];
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, $ok, true)) {
            // a metafile that holds a bitmap shows the bitmap
            $bytes = self::metafileBitmap($bytes);
            $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
            $mime = $info['mime'] ?? '';
            if (!in_array($mime, $ok, true)) {
                return '';
            }
        }
        if ($mime === 'image/x-ms-bmp') {
            $mime = 'image/bmp';
        }
        return '<img src="data:' . $mime . ';base64,' . base64_encode($bytes) . '" alt="' . h($alt) . '"' . ($style !== '' ? ' style="' . $style . '"' : '') . ' loading="lazy">';
    }

    // ------------------------------------------------------------------ bytes

    public static function u8(string $s, int $o): int
    {
        return isset($s[$o]) ? ord($s[$o]) : 0;
    }

    public static function u16(string $s, int $o): int
    {
        return $o >= 0 && $o + 2 <= strlen($s) ? unpack('v', $s, $o)[1] : 0;
    }

    public static function u32(string $s, int $o): int
    {
        return $o >= 0 && $o + 4 <= strlen($s) ? unpack('V', $s, $o)[1] : 0;
    }

    public static function i16(string $s, int $o): int
    {
        $v = self::u16($s, $o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    public static function i32(string $s, int $o): int
    {
        $v = self::u32($s, $o);
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    public static function f64(string $s, int $o): float
    {
        return $o >= 0 && $o + 8 <= strlen($s) ? (float)unpack('e', $s, $o)[1] : 0.0;
    }

    /** A colour of Windows (COLORREF: red, green, blue, flags) as "#rrggbb", '' when it is "automatic" */
    public static function colorref(int $v): string
    {
        if (($v >> 24) & 0xFF) {
            return '';
        }
        return sprintf('#%02x%02x%02x', $v & 0xFF, ($v >> 8) & 0xFF, ($v >> 16) & 0xFF);
    }

    /** The characters of the Symbol and Wingdings fonts (also kept as U+F0xx) as the Unicode signs they show */
    public static function sym(int $code): string
    {
        static $map = [0xA8 => '☐', 0xFE => '☑', 0xFD => '☒', 0xFC => '✓', 0xFB => '✗', 0x6F => '☐', 0x71 => '❑', 0x72 => '❒', 0x6E => '■', 0xA7 => '▪', 0xB7 => '•',
            0xD8 => '➢', 0x76 => '❖', 0xE8 => '➔', 0x9F => '•', 0x6C => '●', 0x77 => '⬥', 0x75 => '◆', 0x4A => '☺', 0x2A => '✉', 0x28 => '☎', 0xF0 => '⇨', 0xA1 => '○',
            0xD2 => '®', 0xD3 => '©', 0xD4 => '™', 0xE2 => '®', 0xE3 => '©', 0xE4 => '™', 0xB0 => '°', 0xB1 => '±', 0xB4 => '×', 0xB8 => '÷', 0x61 => 'α', 0x62 => 'β', 0x70 => 'π',
            0x53 => 'Σ', 0x6D => 'μ', 0xA5 => '∞', 0xA3 => '≤', 0xB3 => '≥', 0xB9 => '≠', 0xBB => '≈', 0xAE => '→', 0xAC => '←', 0xAD => '↑', 0xAF => '↓', 0xD6 => '√',
            0x20 => ' ', 0x2D => '−', 0x2B => '+', 0x3D => '=', 0x25 => '%', 0x2E => '.', 0x2C => ','];
        $c = $code & 0xFF;
        if ($code >= 0xF000) {
            $c = $code - 0xF000;
        }
        return $map[$c] ?? (($c >= 0x30 && $c <= 0x39) ? chr($c) : '•');
    }

    // ------------------------------------------------------------------ Office drawings (OfficeArt: .doc, .xls, .ppt, .pub)

    /**
     * The records of an OfficeArt / PowerPoint stream part, one level: [['ver','inst','type','pos' (of the body),'len'], ...].
     * Both share the same 8-byte record header.
     */
    public static function art(string $d, int $pos = 0, ?int $end = null): array
    {
        $end = min($end ?? strlen($d), strlen($d));
        $out = [];
        $guard = 0;
        while ($pos + 8 <= $end && $guard++ < 500000) {
            $vi = self::u16($d, $pos);
            $len = self::u32($d, $pos + 4);
            $out[] = ['ver' => $vi & 0x0F, 'inst' => $vi >> 4, 'type' => self::u16($d, $pos + 2), 'pos' => $pos + 8, 'len' => (int)min($len, $end - $pos - 8)];
            if ($len > $end - $pos - 8) {
                break;
            }
            $pos += 8 + $len;
        }
        return $out;
    }

    /**
     * The pictures of a drawing group's store (BStore), by their number (from 1): bytes, or '' for a kind a browser cannot
     * show (EMF, WMF, PICT, TIFF). $delay is the stream the large pictures are kept in (Word: WordDocument; PowerPoint: Pictures).
     */
    public static function bstore(string $dgg, ?string $delay = null, int $depth = 0): array
    {
        foreach (self::art($dgg) as $r) {
            if ($r['type'] === 0xF001) {
                $pics = [];
                $n = 0;
                foreach (self::art($dgg, $r['pos'], $r['pos'] + $r['len']) as $e) {
                    $pics[++$n] = '';
                    if ($e['type'] !== 0xF007 || $e['len'] < 36) {
                        continue;
                    }
                    $size = self::u32($dgg, $e['pos'] + 20);
                    $foDelay = self::u32($dgg, $e['pos'] + 28);
                    $cbName = self::u8($dgg, $e['pos'] + 33);
                    if ($e['len'] >= 36 + $cbName + 8) {
                        $b = self::art($dgg, $e['pos'] + 36 + $cbName, $e['pos'] + $e['len'])[0] ?? null;
                        if ($b) {
                            $pics[$n] = self::blipData(substr($dgg, $b['pos'], $b['len']), $b['type'], $b['inst']);
                        }
                    } elseif ($delay !== null && $foDelay !== 0xFFFFFFFF && $foDelay + 8 <= strlen($delay)) {
                        $b = self::art($delay, $foDelay, min(strlen($delay), $foDelay + 8 + max($size, 8)))[0] ?? null;
                        if ($b && $b['type'] >= 0xF018 && $b['type'] <= 0xF117) {
                            $pics[$n] = self::blipData(substr($delay, $b['pos'], $b['len']), $b['type'], $b['inst']);
                        }
                    }
                }
                return $pics;
            }
            if ($r['ver'] === 0x0F && $depth < 4) {
                $in = self::bstore(substr($dgg, $r['pos'], $r['len']), $delay, $depth + 1);
                if ($in) {
                    return $in;
                }
            }
        }
        return [];
    }

    /**
     * The shapes of a drawing, in drawing order: ['spid', 'flags', 'pib' (picture number in the store, 0 none), 'props' (id => value),
     * 'anchor' (client anchor bytes), 'child' (child anchor bytes), 'group' (group frame bytes), 'textbox' and 'data' (client bytes),
     * 'rect' => [left, top, right, bottom] in the drawing's own units when $anchor reads the client anchors, or null].
     * Shapes in groups get their place in the units of the page through the frames of their groups.
     */
    public static function shapes(string $d, ?callable $anchor = null, int $pos = 0, ?int $end = null, ?array $map = null, int $depth = 0): array
    {
        $out = [];
        $first = true;
        foreach (self::art($d, $pos, $end) as $r) {
            if ($r['type'] === 0xF004) {
                $sh = ['spid' => 0, 'flags' => 0, 'type' => 0, 'pib' => 0, 'props' => [], 'anchor' => '', 'child' => '', 'group' => '', 'textbox' => '', 'data' => '', 'rect' => null, 'depth' => $depth];
                foreach (self::art($d, $r['pos'], $r['pos'] + $r['len']) as $c) {
                    $body = substr($d, $c['pos'], $c['len']);
                    switch ($c['type']) {
                        case 0xF00A:
                            $sh['type'] = $c['inst'];
                            $sh['spid'] = self::u32($body, 0);
                            $sh['flags'] = self::u32($body, 4);
                            break;
                        case 0xF00B:
                        case 0xF121:
                        case 0xF122:
                            for ($i = 0, $n = $c['inst']; $i < $n && $i * 6 + 6 <= strlen($body); $i++) {
                                $sh['props'][self::u16($body, $i * 6) & 0x3FFF] = self::u32($body, $i * 6 + 2);
                            }
                            break;
                        case 0xF010:
                            $sh['anchor'] = $body;
                            break;
                        case 0xF00F:
                            $sh['child'] = $body;
                            break;
                        case 0xF009:
                            $sh['group'] = $body;
                            break;
                        case 0xF00D:
                            $sh['textbox'] = $body;
                            break;
                        case 0xF011:
                            $sh['data'] = $body;
                            break;
                    }
                }
                $sh['pib'] = (int)($sh['props'][0x0104] ?? 0);
                if ($sh['child'] !== '' && strlen($sh['child']) >= 16) {
                    $c = [self::i32($sh['child'], 0), self::i32($sh['child'], 4), self::i32($sh['child'], 8), self::i32($sh['child'], 12)];
                    $sh['rect'] = $map ? [$map[0] + ($c[0] - $map[2]) * $map[4], $map[1] + ($c[1] - $map[3]) * $map[5], $map[0] + ($c[2] - $map[2]) * $map[4], $map[1] + ($c[3] - $map[3]) * $map[5]] : null;
                } elseif ($anchor && $sh['anchor'] !== '') {
                    $sh['rect'] = $anchor($sh['anchor']);
                }
                $out[] = $sh;
                // the first shape of a group is the group itself: its frame places the shapes in it
                if ($first && $depth > 1 && $sh['group'] !== '' && $sh['rect']) {
                    $g = [self::i32($sh['group'], 0), self::i32($sh['group'], 4), self::i32($sh['group'], 8), self::i32($sh['group'], 12)];
                    $gw = ($g[2] - $g[0]) ?: 1;
                    $gh = ($g[3] - $g[1]) ?: 1;
                    $map = [$sh['rect'][0], $sh['rect'][1], $g[0], $g[1], ($sh['rect'][2] - $sh['rect'][0]) / $gw, ($sh['rect'][3] - $sh['rect'][1]) / $gh];
                }
            } elseif ($r['ver'] === 0x0F && $depth < 12) {
                foreach (self::shapes($d, $anchor, $r['pos'], $r['pos'] + $r['len'], $map, $depth + 1) as $s) {
                    $out[] = $s;
                }
            }
            $first = false;
        }
        return $out;
    }

    /** A box where a picture of a kind the browser cannot show (EMF, WMF) stands */
    public static function picBox(string $style = ''): string
    {
        return '<span class="op-pic-missing"' . ($style !== '' ? ' style="' . $style . '"' : '') . ' title="A picture the quick view cannot show"><i class="bi bi-image"></i></span>';
    }

    /**
     * The pictures kept in Office drawing records (OfficeArt: Word .doc, PowerPoint .ppt, Publisher .pub):
     * PNG, JPEG and device-independent bitmaps, in the order they are stored. [bytes, ...]
     */
    public static function blips(string $data, int $max = 60): array
    {
        $out = [];
        self::walkArt($data, 0, strlen($data), $out, $max, 0);
        return $out;
    }

    private static function walkArt(string $d, int $pos, int $end, array &$out, int $max, int $depth): void
    {
        $guard = 0;
        while ($pos + 8 <= $end && count($out) < $max && $guard++ < 200000) {
            $h = unpack('vvi/vtype/Vlen', substr($d, $pos, 8));
            $ver = $h['vi'] & 0x0F;
            $inst = $h['vi'] >> 4;
            $type = $h['type'];
            $len = $h['len'];
            if ($type < 0xF000 || $type > 0xF200 || $pos + 8 + $len > $end + 8) {
                // not an OfficeArt record here: look for the next picture by its signature
                $next = self::nextBlip($d, $pos + 1, $end);
                if ($next < 0) {
                    return;
                }
                $pos = $next;
                continue;
            }
            $body = $pos + 8;
            if ($ver === 0x0F && $depth < 12) {
                self::walkArt($d, $body, min($end, $body + $len), $out, $max, $depth + 1);
            } elseif ($type === 0xF007 && $len > 36) {
                // a picture in the store: 36 bytes, its name, then the picture record itself
                $cbName = ord($d[$body + 33]);
                self::walkArt($d, $body + 36 + $cbName, min($end, $body + $len), $out, $max, $depth + 1);
            } elseif ($type >= 0xF018 && $type <= 0xF117) {
                $pic = self::blipData(substr($d, $body, $len), $type, $inst);
                if ($pic !== '') {
                    $out[] = $pic;
                }
            }
            $pos = $body + $len;
        }
    }

    /** The position of the next picture record header after $from (PNG, JPEG or DIB), or -1 */
    private static function nextBlip(string $d, int $from, int $end): int
    {
        $best = -1;
        foreach (["\x1E\xF0", "\x1D\xF0", "\x2A\xF0", "\x1F\xF0"] as $t) {
            $p = strpos($d, $t, $from + 2);
            if ($p !== false && $p - 2 < $end && ($best < 0 || $p - 2 < $best)) {
                $best = $p - 2;
            }
        }
        return $best;
    }

    private static function blipData(string $b, int $type, int $inst): string
    {
        if (in_array($type, [0xF01D, 0xF01E, 0xF02A], true)) {
            // JPEG / PNG: after one or two 16-byte ids and a tag byte; found by its own signature
            foreach (["\x89PNG\r\n\x1A\n", "\xFF\xD8\xFF"] as $sig) {
                $p = strpos(substr($b, 0, 80), $sig);
                if ($p !== false) {
                    return substr($b, $p);
                }
            }
            return '';
        }
        if ($type === 0xF01F) {
            // a device-independent bitmap: made a BMP file
            return self::dibToBmp(substr($b, in_array($inst, [0x7A9], true) ? 33 : 17));
        }
        if ($type === 0xF01A || $type === 0xF01B || $type === 0xF01C) {
            // a metafile (EMF, WMF, PICT): one or two ids, a 34-byte header, the data (deflated unless 0xFE)
            $off = in_array($inst, [0x3D5, 0x217, 0x543], true) ? 32 : 16;
            $h = substr($b, $off, 34);
            $data = substr($b, $off + 34);
            if (strlen($h) === 34 && ord($h[32]) === 0) {
                $u = @gzuncompress($data);
                $data = $u === false ? (string)@gzinflate($data) : $u;
            }
            return $data;
        }
        return '';
    }

    /** A device-independent bitmap (header, colours, pixels) as a BMP file */
    public static function dibToBmp(string $dib): string
    {
        if (strlen($dib) < 12) {
            return '';
        }
        $hs = self::u32($dib, 0);
        if ($hs < 12 || $hs > 124) {
            return '';
        }
        if ($hs === 12) {
            $bits = self::u16($dib, 10);
            $palette = $bits <= 8 ? (1 << $bits) * 3 : 0;
        } else {
            $bits = self::u16($dib, 14);
            $comp = self::u32($dib, 16);
            if ($comp === 4 || $comp === 5) {
                // the pixels are a JPEG or PNG file
                return (string)substr($dib, $hs);
            }
            $clr = $hs >= 36 ? self::u32($dib, 32) : 0;
            $palette = $bits <= 8 ? ($clr ?: (1 << $bits)) * 4 : $clr * 4;
            if ($comp === 3 && $hs === 40) {
                $palette += 12;
            }
        }
        return 'BM' . pack('V', 14 + strlen($dib)) . "\0\0\0\0" . pack('V', 14 + $hs + $palette) . $dib;
    }

    /**
     * The bitmap kept in a metafile (EMF or WMF), the largest one, as a BMP / JPEG / PNG file; '' when the metafile is
     * drawn with lines and shapes only (the page view draws those).
     */
    public static function metafileBitmap(string $m): string
    {
        $best = '';
        $n = strlen($m);
        if ($n > 88 && self::u32($m, 0) === 1 && substr($m, 40, 4) === ' EMF') {
            $p = 0;
            for ($k = 0; $p + 8 <= $n && $k < 200000; $k++) {
                $type = self::u32($m, $p);
                $size = self::u32($m, $p + 4);
                if ($size < 8 || $p + $size > $n) {
                    break;
                }
                $at = match ($type) { 81, 80 => 48, 76, 77 => 84, default => 0 };
                if ($at) {
                    $offBmi = self::u32($m, $p + $at);
                    $cbBmi = self::u32($m, $p + $at + 4);
                    $offBits = self::u32($m, $p + $at + 8);
                    $cbBits = self::u32($m, $p + $at + 12);
                    if ($cbBmi > 0 && $cbBits > 0 && $offBmi + $cbBmi <= $size && $offBits + $cbBits <= $size && $cbBits > strlen($best)) {
                        $bmi = substr($m, $p + $offBmi, $cbBmi);
                        $bmp = self::dibToBmp($bmi . substr($m, $p + $offBits, $cbBits));
                        if ($bmp !== '' && strlen($bmp) > strlen($best)) {
                            $best = $bmp;
                        }
                    }
                }
                if ($type === 14) {
                    break;
                }
                $p += $size;
            }
            return $best;
        }
        // WMF: an optional placeable header, the header, then the records (sizes in 16-bit words)
        $p = self::u32($m, 0) === 0x9AC6CDD7 ? 22 : 0;
        if ($n < $p + 18 || !in_array(self::u16($m, $p), [1, 2], true)) {
            return '';
        }
        $p += self::u16($m, $p + 2) * 2;
        for ($k = 0; $p + 6 <= $n && $k < 200000; $k++) {
            $size = self::u32($m, $p) * 2;
            $fn = self::u16($m, $p + 4);
            if ($size < 6 || $p + $size > $n || $fn === 0) {
                break;
            }
            $at = match ($fn) { 0x0F43 => 28, 0x0B41 => 26, 0x0940 => 22, default => 0 };
            if ($at && $size > $at + 40) {
                $bmp = self::dibToBmp(substr($m, $p + $at, $size - $at));
                if (strlen($bmp) > strlen($best)) {
                    $best = $bmp;
                }
            }
            $p += $size;
        }
        return $best;
    }

    // ================================================================== numbers of Excel

    /** A number as Excel shows it with a number format ("#,##0.00", "0%", "mm/dd/yyyy", "General" ...) */
    public static function number(float $v, string $code, bool $date1904 = false): string
    {
        $code = trim($code);
        if ($code === '' || strcasecmp($code, 'General') === 0) {
            return self::general($v);
        }
        $sections = self::sections($code);
        $sec = $sections[0];
        $neg = false;
        if ($v < 0 && count($sections) >= 2) {
            $sec = $sections[1];
            $v = -$v;
            $neg = true;
        } elseif ($v == 0 && count($sections) >= 3) {
            $sec = $sections[2];
        }
        // colours and conditions in brackets, except elapsed time and currency
        $sec = (string)preg_replace('/\[(?!h+\]|m+\]|s+\]|\$)[^\]]*\]/i', '', $sec);
        $plain = (string)preg_replace('/"[^"]*"|\\\\.|_.|\*./', '', $sec);
        if (preg_match('/[dy]|h|(?<![0#?])s|AM\/PM|A\/P/i', $plain) && !preg_match('/^[#0?,.%E+\-]*$/i', $plain)) {
            return self::date($v, $sec, $date1904);
        }
        if (str_contains($plain, '@') && !preg_match('/[0#?]/', $plain)) {
            return self::literal($sec, self::general($v));
        }
        if (!preg_match('/[0#?]/', $plain)) {
            return self::literal($sec, '');
        }
        $pct = substr_count($plain, '%');
        $x = $v * (10 ** (2 * min($pct, 1)));
        if (preg_match('/E[+-]/i', $plain)) {
            preg_match('/\.([0#?]*)/', $plain, $m);
            $s = sprintf('%.' . strlen($m[1] ?? '') . 'E', $x);
            $s = preg_replace('/E([+-])(\d)$/', 'E${1}0$2', $s);
            return ($neg && count($sections) < 2 ? '-' : '') . $s;
        }
        // digits after the point; thousands; a comma at the end divides by 1,000
        $numPart = preg_match('/[0#?,.]+/', $plain, $mm) ? $mm[0] : '0';
        $dec = preg_match('/\.([0#?]*)/', $numPart, $m) ? strlen($m[1]) : 0;
        $trail = preg_match('/(,+)$/', rtrim($numPart, '.'), $m2) ? strlen($m2[1]) : 0;
        $x /= 1000 ** $trail;
        $thousands = str_contains(rtrim($numPart, ','), ',');
        $optional = $dec > 0 ? strlen($m[1]) - strlen(rtrim($m[1], '#?')) : 0;
        $s = number_format(abs($x), $dec, '.', $thousands ? ',' : '');
        if ($optional > 0) {
            $s = (string)preg_replace('/\.?0{1,' . $optional . '}$/', '', $s);
        }
        $intDigits = preg_match('/^[^.]*/', str_replace(',', '', $numPart), $m3) ? substr_count($m3[0], '0') : 1;
        if ($intDigits === 0 && str_starts_with($s, '0.')) {
            $s = substr($s, 1);
        }
        $out = self::literal($sec, $s);
        if ($x < 0 && !$neg) {
            $out = '-' . $out;
        }
        return $out;
    }

    /** Excel's "General": up to 11 significant digits */
    public static function general(float $v): string
    {
        if ($v == floor($v) && abs($v) < 1e15) {
            return number_format($v, 0, '.', '');
        }
        $a = abs($v);
        if ($a >= 1e11 || ($a < 1e-9 && $a > 0)) {
            return str_replace('E+', 'E+', sprintf('%.5E', $v));
        }
        $digits = max(0, 10 - (int)floor(log10($a)));
        return rtrim(rtrim(number_format($v, min($digits, 10), '.', ''), '0'), '.');
    }

    /** The sections of a format code (positive;negative;zero;text), not split inside quotes */
    private static function sections(string $code): array
    {
        $out = [];
        $cur = '';
        $q = false;
        for ($i = 0, $n = strlen($code); $i < $n; $i++) {
            $c = $code[$i];
            if ($c === '"') {
                $q = !$q;
            } elseif ($c === '\\' && $i + 1 < $n) {
                $cur .= $c . $code[++$i];
                continue;
            } elseif ($c === ';' && !$q) {
                $out[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }

    /** The text around the number of a format section ("Php "#,##0.00, (#,##0)) with the number put in */
    private static function literal(string $sec, string $num): string
    {
        $out = '';
        $placed = false;
        for ($i = 0, $n = strlen($sec); $i < $n; $i++) {
            $c = $sec[$i];
            if ($c === '"') {
                $j = strpos($sec, '"', $i + 1);
                $j = $j === false ? $n : $j;
                $out .= substr($sec, $i + 1, $j - $i - 1);
                $i = $j;
            } elseif ($c === '\\' && $i + 1 < $n) {
                $out .= $sec[++$i];
            } elseif ($c === '_') {
                $out .= ' ';
                $i++;
            } elseif ($c === '*') {
                $i++;
            } elseif ($c === '[') {
                $j = strpos($sec, ']', $i);
                $inner = $j === false ? '' : substr($sec, $i + 1, $j - $i - 1);
                if (str_starts_with($inner, '$')) {
                    $out .= (string)preg_replace('/-.*$/', '', substr($inner, 1));
                }
                $i = $j === false ? $n : $j;
            } elseif (strpbrk($c, '0#?,.') !== false || $c === '@' || ($c === 'E' && $placed === false && preg_match('/E[+-]/', substr($sec, $i, 2)))) {
                if (!$placed) {
                    $out .= $num;
                    $placed = true;
                }
            } elseif ($c === '%') {
                $out .= '%';
            } else {
                $out .= $c;
            }
        }
        return $placed ? $out : $out . $num;
    }

    /** A serial date of Excel with a date / time format */
    private static function date(float $v, string $sec, bool $date1904): string
    {
        if ($v < 0) {
            return self::general($v);
        }
        $days = (int)floor($v);
        $secs = (int)round(($v - $days) * 86400);
        if ($secs >= 86400) {
            $days++;
            $secs -= 86400;
        }
        if ($date1904) {
            $days += 1462;
        }
        // Excel counts 1900-02-29 that never was
        $ts = $days >= 61 ? ($days - 25569) * 86400 : ($days - 25568) * 86400;
        $ts += $secs;
        $ampm = (bool)preg_match('/AM\/PM|A\/P/i', $sec);
        $tok = preg_split('/("[^"]*"|\\\\.|yyyy|yy|mmmmm|mmmm|mmm|mm|m|dddd|ddd|dd|d|hh|h|ss|s|AM\/PM|A\/P|\[h+\]|\[m+\]|\.0+)/i', $sec, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        $prevHour = false;
        foreach ($tok as $i => $t) {
            $l = strtolower($t);
            $nextIsSec = false;
            for ($j = $i + 1; $j < count($tok); $j++) {
                $lj = strtolower($tok[$j]);
                if (preg_match('/^(s|ss)$/', $lj)) {
                    $nextIsSec = true;
                    break;
                }
                if (preg_match('/^[a-z\[]/', $lj) && !preg_match('/^[:\s]/', $lj)) {
                    break;
                }
            }
            $out .= match (true) {
                $l === 'yyyy' => gmdate('Y', $ts), $l === 'yy' => gmdate('y', $ts),
                $l === 'mmmmm' => substr(gmdate('F', $ts), 0, 1), $l === 'mmmm' => gmdate('F', $ts), $l === 'mmm' => gmdate('M', $ts),
                ($l === 'mm' || $l === 'm') && ($prevHour || $nextIsSec) => $l === 'mm' ? gmdate('i', $ts) : (string)(int)gmdate('i', $ts),
                $l === 'mm' => gmdate('m', $ts), $l === 'm' => gmdate('n', $ts),
                $l === 'dddd' => gmdate('l', $ts), $l === 'ddd' => gmdate('D', $ts), $l === 'dd' => gmdate('d', $ts), $l === 'd' => gmdate('j', $ts),
                $l === 'hh' => $ampm ? gmdate('h', $ts) : gmdate('H', $ts), $l === 'h' => $ampm ? gmdate('g', $ts) : gmdate('G', $ts),
                $l === 'ss' => gmdate('s', $ts), $l === 's' => (string)(int)gmdate('s', $ts),
                $l === 'am/pm' => gmdate('A', $ts), $l === 'a/p' => substr(gmdate('A', $ts), 0, 1),
                (bool)preg_match('/^\[h+\]$/', $l) => (string)(int)floor($v * 24), (bool)preg_match('/^\[m+\]$/', $l) => (string)(int)floor($v * 1440),
                str_starts_with($t, '"') => substr($t, 1, -1), str_starts_with($t, '\\') => substr($t, 1),
                str_starts_with($t, '.0') => '', default => $t,
            };
            if (preg_match('/^(h|hh|\[h+\])$/', $l)) {
                $prevHour = true;
            } elseif (preg_match('/^[a-z]/', $l)) {
                $prevHour = false;
            }
        }
        return $out;
    }
}
