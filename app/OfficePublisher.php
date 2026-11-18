<?php
declare(strict_types=1);

/**
 * The quick view of Publisher files (.pub): Publisher 98, 2000, 2002, 2003, 2007, 2010 and later keep the text of every
 * text box in one text engine ("Quill") and the pictures in an OfficeArt store ("Escher"). The quick view shows the
 * text, paragraph by paragraph, and every picture; the page view (LibreOffice) shows the pages as laid out.
 * Publisher 97 and older: the readable text.
 */
final class OfficePublisher
{
    public static function render(string $file): string
    {
        return OfficeWord::page(self::read((string)file_get_contents($file)), 'op-word op-pub');
    }

    public static function read(string $data): array
    {
        if (!OleFile::is($data)) {
            return OfficeWord::recover($data, 'not a Publisher 98 or later file');
        }
        $ole = new OleFile($data);
        if ($ole->has('EncryptionInfo') || $ole->has('EncryptedPackage')) {
            throw new OfficeLockedException('This Publisher file is protected with a password, so it cannot be shown here. Download it and open it with its password.');
        }
        $q = $ole->read('Quill/QuillSub/CONTENTS');
        $text = '';
        if ($q !== null && str_starts_with($q, 'CHNKINK')) {
            $n = min(500, OfficePreview::u16($q, 0x1A));
            for ($i = 0; $i < $n; $i++) {
                $e = substr($q, 0x20 + 24 * $i, 24);
                if (substr($e, 2, 4) === 'TEXT') {
                    $off = OfficePreview::u32($e, 16);
                    $len = OfficePreview::u32($e, 20);
                    if ($off + $len <= strlen($q)) {
                        $text .= OfficePreview::utf16(substr($q, $off, $len & ~1));
                    }
                }
            }
        }
        if ($q === null || $text === '') {
            $rec = OfficeWord::recover((string)($ole->read('Contents') ?? $data), 'an early Publisher file');
            $rec['blocks'] = array_merge($rec['blocks'], self::gallery($ole));
            return $rec;
        }
        $blocks = [];
        $count = 0;
        $empty = 0;
        foreach (explode("\r", $text) as $line) {
            $line = OfficeWord::symText(rtrim($line, "\0"));
            if (trim($line) === '') {
                // one blank line between the parts of the publication
                if ($empty++ === 0 && $blocks) {
                    $blocks[] = ['t' => 'p', 'runs' => []];
                }
                continue;
            }
            $empty = 0;
            $runs = [];
            foreach (preg_split('/([\n\t\x0B])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                if ($part === "\t") {
                    $runs[] = ['tab' => 1];
                } elseif ($part === "\n" || $part === "\x0B") {
                    $runs[] = ['br' => 1];
                } else {
                    $runs[] = ['x' => preg_replace('/[\x00-\x08\x0C\x0E-\x1F]/', '', $part), 'f' => []];
                }
            }
            $blocks[] = ['t' => 'p', 'runs' => $runs];
            if (++$count > OfficeWord::MAX_BLOCKS) {
                break;
            }
        }
        $notes = ['Publisher files show their text and pictures here; the page view shows the pages as laid out.'];
        $gallery = self::gallery($ole);
        return OfficeWord::doc(array_merge($blocks, $gallery), [], [], [], ['sz' => 11, 'font' => 'Times New Roman'], $notes);
    }

    /** The pictures of the publication as a block */
    private static function gallery(OleFile $ole): array
    {
        $dgg = $ole->read('Escher/EscherStm');
        if ($dgg === null || $dgg === '') {
            return [];
        }
        $imgs = [];
        $boxes = 0;
        foreach (OfficePreview::bstore($dgg, $ole->read('Escher/EscherDelayStm')) as $bytes) {
            $img = $bytes !== '' ? OfficePreview::img($bytes) : '';
            if ($img !== '') {
                $imgs[] = $img;
            } else {
                $boxes++;
            }
            if (count($imgs) >= 80) {
                break;
            }
        }
        if ($boxes) {
            $imgs[] = OfficePreview::picBox('width:100%;height:110px');
        }
        return $imgs ? [['t' => 'gallery', 'imgs' => $imgs]] : [];
    }
}
