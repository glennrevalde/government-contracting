<?php
declare(strict_types=1);

/**
 * Version 39: files are made smaller before they are kept (the Document Vault of Barangay Contracts and the attachments of
 * the transactions, which are kept in the database). The browser does most of it before sending (assets/js/upload.js:
 * pictures and PDFs); this is the server's own pass on a picture that still arrives big (a browser without the script):
 *   - a file of 2 MB up to 6 MB is brought under 1 MB;
 *   - a file of 6 MB and above is brought under 2 MB.
 * A picture keeps its kind (a JPG stays a JPG, a PNG a PNG), so its name and extension stay as they are.
 */
final class Upload
{
    public const MB = 1048576;

    /** The size a file of $bytes is brought under, or 0 when it stays as it is */
    public static function target(int $bytes): int
    {
        if ($bytes >= 6 * self::MB) {
            return 2 * self::MB - 48 * 1024;
        }
        return $bytes >= 2 * self::MB ? self::MB - 24 * 1024 : 0;
    }

    /** The PHP memory limit in bytes (0 = none) */
    private static function memoryLimit(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    /**
     * Makes the picture at $path smaller in place when it is 2 MB or more (GD): drawn again at a smaller size and quality
     * until it is under the target. Returns the new size, or 0 when the file stays as it was (not a picture, no GD, too big
     * to open in the memory PHP has, or no smaller).
     */
    public static function shrinkImage(string $path, string $name = ''): int
    {
        $size = (int)@filesize($path);
        $target = self::target($size);
        if (!$target || !function_exists('imagecreatetruecolor')) {
            return 0;
        }
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return 0;
        }
        [$w, $h, $type] = $info;
        $limit = self::memoryLimit();
        if ($limit && $w * $h * 5 + memory_get_usage() > $limit * 0.8) {
            return 0;
        }
        $src = match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$src) {
            return 0;
        }
        // a photo taken sideways is turned upright (the picture written again keeps no EXIF)
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $o = (int)(@exif_read_data($path)['Orientation'] ?? 1);
            $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
            if ($rot) {
                $r = imagerotate($src, $rot, 0);
                if ($r) {
                    imagedestroy($src);
                    $src = $r;
                    [$w, $h] = [imagesx($src), imagesy($src)];
                }
            }
        }
        $alpha = $type !== IMAGETYPE_JPEG;
        $maxDim = min(3200, max($w, $h));
        $q = 84;
        $best = null;
        $tmp = $path . '.shrink';
        for ($i = 0; $i < 8; $i++) {
            $s = min(1, $maxDim / max($w, $h));
            $nw = max(1, (int)round($w * $s));
            $nh = max(1, (int)round($h * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($alpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($dst, $tmp, $q),
                IMAGETYPE_PNG => imagepng($dst, $tmp, 9),
                IMAGETYPE_WEBP => imagewebp($dst, $tmp, $q),
            };
            imagedestroy($dst);
            clearstatcache(true, $tmp);
            $n = $ok ? (int)@filesize($tmp) : 0;
            if ($n > 0 && ($best === null || $n < $best)) {
                $best = $n;
                @copy($tmp, $path . '.best');
            }
            if ($n > 0 && $n <= $target) {
                break;
            }
            if ($type !== IMAGETYPE_PNG && $q > 64) {
                $q -= 10;
            } else {
                $maxDim = max(320, (int)round($maxDim * max(0.55, min(0.9, sqrt($target / max(1, $n)) * 0.97))));
            }
        }
        imagedestroy($src);
        @unlink($tmp);
        if ($best !== null && $best < $size && @copy($path . '.best', $path)) {
            @unlink($path . '.best');
            clearstatcache(true, $path);
            return (int)filesize($path);
        }
        @unlink($path . '.best');
        return 0;
    }
}
