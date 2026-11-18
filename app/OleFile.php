<?php
declare(strict_types=1);

/**
 * Reads the streams of an OLE2 compound file (Compound File Binary): the container of the Office files before 2007
 * (Word .doc, Excel .xls, PowerPoint .ppt, Publisher .pub). Version 3 (512-byte sectors) and 4 (4096-byte sectors).
 * Read only; every chain is guarded against loops, so a damaged file gives an error instead of hanging.
 */
final class OleFile
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const FREE = 0xFFFFFFFF;
    private const END = 0xFFFFFFFE;

    private string $d;
    private int $ss;
    private int $mss;
    private int $cutoff;
    /** @var int[] */
    private array $fat = [];
    /** @var int[] */
    private array $miniFat = [];
    private string $mini = '';
    /** @var array<int, array{name: string, type: int, left: int, right: int, child: int, start: int, size: int}> */
    private array $dir = [];
    /** @var array<string, int> lower-case path => entry */
    private array $paths = [];

    public static function is(string $data): bool
    {
        return strncmp($data, self::SIGNATURE, 8) === 0;
    }

    public function __construct(string $data)
    {
        if (!self::is($data) || strlen($data) < 512) {
            throw new RuntimeException('Not an OLE2 compound file.');
        }
        $this->d = $data;
        $h = unpack('vminor/vmajor/vorder/vss/vmss', substr($data, 0x18, 10));
        $this->ss = 1 << $h['ss'];
        $this->mss = 1 << $h['mss'];
        if ($this->ss < 128 || $this->ss > 65536 || $this->mss > $this->ss) {
            throw new RuntimeException('Damaged compound file header.');
        }
        $v = unpack('VnDir/VnFat/VdirStart/Vsig/Vcutoff/VminiFatStart/VnMiniFat/VdifatStart/VnDifat', substr($data, 0x28, 36));
        $this->cutoff = $v['cutoff'] ?: 4096;
        // the sectors of the FAT: 109 in the header, the rest in the DIFAT chain
        $fatSectors = [];
        foreach (array_values(unpack('V109', substr($data, 0x4C, 436))) as $s) {
            if ($s !== self::FREE && $s !== self::END) {
                $fatSectors[] = $s;
            }
        }
        $per = intdiv($this->ss, 4) - 1;
        $s = $v['difatStart'];
        $guard = 0;
        while ($s !== self::END && $s !== self::FREE && $guard++ < 100000) {
            $sec = $this->sector($s);
            $vals = array_values(unpack('V' . ($per + 1), $sec));
            for ($i = 0; $i < $per; $i++) {
                if ($vals[$i] !== self::FREE && $vals[$i] !== self::END) {
                    $fatSectors[] = $vals[$i];
                }
            }
            $s = $vals[$per];
        }
        foreach ($fatSectors as $fs) {
            foreach (unpack('V*', $this->sector($fs)) as $e) {
                $this->fat[] = $e;
            }
        }
        // the directory
        $dirData = $this->chain($v['dirStart']);
        for ($o = 0, $n = intdiv(strlen($dirData), 128); $o < $n; $o++) {
            $e = substr($dirData, $o * 128, 128);
            $len = unpack('v', substr($e, 64, 2))[1];
            $type = ord($e[66]);
            $f = unpack('Vleft/Vright/Vchild', substr($e, 68, 12));
            $start = unpack('V', substr($e, 116, 4))[1];
            $size = unpack('V', substr($e, 120, 4))[1];
            $name = $len >= 2 ? (string)@mb_convert_encoding(substr($e, 0, min(64, $len - 2)), 'UTF-8', 'UTF-16LE') : '';
            $this->dir[$o] = ['name' => $name, 'type' => $type, 'left' => $f['left'], 'right' => $f['right'], 'child' => $f['child'], 'start' => $start, 'size' => $size];
        }
        if (!$this->dir || $this->dir[0]['type'] !== 5) {
            throw new RuntimeException('Damaged compound file directory.');
        }
        // the mini stream (kept in the root entry's chain) and its FAT
        $this->mini = $this->chain($this->dir[0]['start'], $this->dir[0]['size']);
        if ($v['nMiniFat'] > 0) {
            foreach (unpack('V*', $this->chain($v['miniFatStart'])) as $e) {
                $this->miniFat[] = $e;
            }
        }
        $this->walk($this->dir[0]['child'], '', 0);
    }

    private function sector(int $n): string
    {
        $off = ($n + 1) * $this->ss;
        if ($off < 0 || $off >= strlen($this->d)) {
            throw new RuntimeException('Damaged compound file (sector out of range).');
        }
        return str_pad(substr($this->d, $off, $this->ss), $this->ss, "\0");
    }

    /** The bytes of a chain of sectors (cut to $size when given) */
    private function chain(int $start, ?int $size = null): string
    {
        $out = '';
        $s = $start;
        $guard = 0;
        $max = count($this->fat) + 1;
        while ($s !== self::END && $s !== self::FREE && $s < 0xFFFFFFF0) {
            if ($guard++ > $max) {
                throw new RuntimeException('Damaged compound file (a chain loops).');
            }
            $out .= $this->sector($s);
            if ($size !== null && strlen($out) >= $size) {
                break;
            }
            $s = $this->fat[$s] ?? self::END;
        }
        return $size !== null ? substr($out, 0, $size) : $out;
    }

    private function miniChain(int $start, int $size): string
    {
        $out = '';
        $s = $start;
        $guard = 0;
        $max = count($this->miniFat) + 1;
        while ($s !== self::END && $s !== self::FREE && $s < 0xFFFFFFF0 && strlen($out) < $size) {
            if ($guard++ > $max) {
                throw new RuntimeException('Damaged compound file (a mini chain loops).');
            }
            $out .= str_pad(substr($this->mini, $s * $this->mss, $this->mss), $this->mss, "\0");
            $s = $this->miniFat[$s] ?? self::END;
        }
        return substr($out, 0, $size);
    }

    /** The red-black tree of a storage, in any order: every entry gets its path */
    private function walk(int $i, string $prefix, int $depth): void
    {
        $stack = [$i];
        $seen = [];
        while ($stack) {
            $n = array_pop($stack);
            if ($n === self::FREE || !isset($this->dir[$n]) || isset($seen[$n]) || $depth > 32) {
                continue;
            }
            $seen[$n] = true;
            $e = $this->dir[$n];
            $path = $prefix . $e['name'];
            $this->paths[mb_strtolower($path)] = $n;
            if ($e['type'] === 1) {
                $this->walk($e['child'], $path . '/', $depth + 1);
            }
            $stack[] = $e['left'];
            $stack[] = $e['right'];
        }
    }

    /** @return string[] the paths of the streams (storages end with "/") */
    public function paths(): array
    {
        $out = [];
        foreach ($this->paths as $p => $n) {
            $out[] = $p . ($this->dir[$n]['type'] === 1 ? '/' : '');
        }
        sort($out);
        return $out;
    }

    public function has(string $path): bool
    {
        $n = $this->paths[mb_strtolower($path)] ?? null;
        return $n !== null && $this->dir[$n]['type'] === 2;
    }

    /** The bytes of a stream, or null when there is none of that name ("Quill/QuillSub/CONTENTS": a stream in storages) */
    public function read(string $path): ?string
    {
        $n = $this->paths[mb_strtolower($path)] ?? null;
        if ($n === null || $this->dir[$n]['type'] !== 2) {
            return null;
        }
        $e = $this->dir[$n];
        return $e['size'] < $this->cutoff ? $this->miniChain($e['start'], $e['size']) : $this->chain($e['start'], $e['size']);
    }
}
