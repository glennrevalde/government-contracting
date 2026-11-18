<?php
declare(strict_types=1);

/**
 * Barangay Contracts: the document vault and the form templates.
 *
 * Vault: every file is kept in the company's own storage folder (storage/contracts, never served directly by the web
 * server), under a random name, with its SHA-256 checksum, type, validity dates, version chain (a new version
 * supersedes the old one, which is kept), review status and lock. Uploads are limited to document and picture types,
 * checked for what they really contain, and passed to a virus scanner when one is set up. Every download is logged.
 *
 * Templates: HTML with {{placeholders}} filled from the company settings, the procuring entity and its officials,
 * the project and its price schedule, and the delivery, billing or collection the form is made for. A form made from a
 * template keeps the filled-in text, can be edited, goes through review and approval, and is printed (or saved for
 * Word) with a DRAFT mark until it is final. Text the user types is cleaned to a safe list of tags and styles.
 */
final class BrgyDocs
{
    /** What the type detectors of PHP (finfo) report for the binary Office files (Word, Excel, PowerPoint, Publisher 97 and older) */
    private const OLE = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-mspublisher',
        'application/vnd.ms-publisher', 'application/CDFV2', 'application/CDFV2-corrupt', 'application/x-ole-storage', 'application/octet-stream'];
    /** ... and for the zipped ones (Office 2007 and later, OpenDocument) */
    private const ZIP = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    /** ... and for the Word and Excel files saved as a web page or as XML (Word / Excel 2003 XML) */
    private const MARKUP = ['text/html', 'text/xml', 'application/xml', 'text/plain', 'message/rfc822', 'multipart/related'];

    /** extension => content types accepted for it */
    public const TYPES = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        // Word: Word for DOS and Word 1.0 to Word 365
        'doc'  => [...self::OLE, 'application/rtf', 'text/rtf', ...self::MARKUP], 'dot' => self::OLE, 'rtf' => ['application/rtf', 'text/rtf', 'text/plain'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP],
        'docm' => ['application/vnd.ms-word.document.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP],
        'dotx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP],
        'dotm' => ['application/vnd.ms-word.template.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP],
        // Excel: Excel 2.1 to Excel 365
        'xls'  => [...self::OLE, ...self::MARKUP, 'text/csv'], 'xlt' => self::OLE,
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP],
        'xlsm' => ['application/vnd.ms-excel.sheet.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP],
        'xltx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP],
        'xltm' => ['application/vnd.ms-excel.template.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP],
        'xlsb' => ['application/vnd.ms-excel.sheet.binary.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP],
        // PowerPoint: PowerPoint 95 to PowerPoint 365
        'ppt'  => self::OLE, 'pps' => self::OLE, 'pot' => self::OLE,
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP],
        'pptm' => ['application/vnd.ms-powerpoint.presentation.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP],
        'ppsx' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP],
        'potx' => ['application/vnd.openxmlformats-officedocument.presentationml.template', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP],
        'potm' => ['application/vnd.ms-powerpoint.template.macroEnabled.12', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP],
        // Publisher 98 to Publisher 365
        'pub'  => self::OLE,
        // OpenDocument, CSV and text
        'odt'  => ['application/vnd.oasis.opendocument.text', ...self::ZIP], 'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ...self::ZIP],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', ...self::ZIP],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'], 'txt' => ['text/plain'],
    ];

    public const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'];

    /** The extensions for the accept list of a file box (".pdf,.jpg,...") */
    public static function accept(): string
    {
        return '.' . implode(',.', array_keys(self::TYPES));
    }

    public static function dir(): string
    {
        return Tenant::storage('contracts');
    }

    public static function load(int $id): ?array
    {
        return DB::row('SELECT d.*, t.name AS type_name, p.project_no, p.title AS project_title, e.official_name AS entity_name, v.name AS vendor_name, u.full_name AS uploaded_by_name
                          FROM brgy_documents d LEFT JOIN brgy_doc_types t ON t.code = d.doc_type
                          LEFT JOIN brgy_projects p ON p.id = d.project_id LEFT JOIN brgy_entities e ON e.id = d.entity_id
                          LEFT JOIN vendors v ON v.id = d.vendor_id LEFT JOIN users u ON u.id = d.uploaded_by
                         WHERE d.id = :id', ['id' => $id]);
    }

    /** A confidential paper (a financial bid, a costing) is seen by the vault's editors and the project team only */
    public static function canSee(array $d): bool
    {
        if ((int)$d['confidential'] !== 1 || Access::isAdmin() || Access::can('brgy.vault', 'edit')) {
            return true;
        }
        if (!$d['project_id']) {
            return false;
        }
        $team = DB::row('SELECT preparer_id, checker_id, approver_id, submitter_id, pm_id, collector_id, created_by FROM brgy_projects WHERE id = :id', ['id' => $d['project_id']]);
        return $team && in_array((int)Auth::id(), array_map('intval', array_values($team)), true);
    }

    public static function maxBytes(): int
    {
        return max(1, (int)Brgy::setting('brgy_upload_max_mb')) * 1048576;
    }

    /**
     * Looks inside an upload: the content has to match the extension, and must not be a program or a script.
     * When a scanner command is set (Contracting Settings, e.g. "clamscan --no-summary"), it has to report the file clean.
     * Returns what was checked, kept with the document.
     */
    public static function scan(string $tmp, string $ext, string $name): string
    {
        $head = (string)file_get_contents($tmp, false, null, 0, 4096);
        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF") || preg_match('/<\?php|<script\b|<%@|#!\/bin\//i', $head)) {
            fail($name . ' looks like a program or a script and was refused.');
        }
        $mime = '';
        if (class_exists('finfo')) {
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!in_array($mime, self::TYPES[$ext], true)) {
                fail($name . ' does not contain what its extension says (' . $mime . '). Save it again as a real .' . $ext . ' file.');
            }
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && !@getimagesize($tmp)) {
            fail($name . ' is not a readable picture.');
        }
        if ($ext === 'pdf' && !str_starts_with($head, '%PDF')) {
            fail($name . ' is not a PDF file.');
        }
        self::checkOffice($tmp, $ext, $name, $head);
        $cmd = trim(Brgy::setting('brgy_scan_command'));
        if ($cmd !== '' && function_exists('exec')) {
            $outLines = [];
            $code = 0;
            @exec($cmd . ' ' . escapeshellarg($tmp) . ' 2>&1', $outLines, $code);
            if ($code === 1) {
                fail($name . ' was refused by the virus scanner: ' . mb_substr(implode(' ', $outLines), 0, 200));
            }
            if ($code !== 0) {
                fail('The virus scanner could not check ' . $name . ' (exit code ' . $code . '). Nothing was saved.');
            }
            return 'Scanned clean, ' . date('Y-m-d H:i');
        }
        // kept in 40 characters: a long type name (the Office 2007 ones) is given as the extension
        $what = $mime !== '' && strlen($mime) <= 16 ? $mime : '.' . $ext;
        return 'Type checked (' . $what . '), no scanner';
    }

    /**
     * An Office file must be built as its kind is: a compound file (Word 97, Excel 5 and later, PowerPoint, Publisher), a
     * zip package holding the right main part (Office 2007 and later, OpenDocument), or one of the older or plain forms
     * Word and Excel also save under .doc / .xls (Word 1 / 2, Word for DOS, Excel 2.1 to 4.0, RTF, web page, XML).
     * A zip whose parts unpack too large is refused (it would fill the memory of the preview).
     */
    private static function checkOffice(string $tmp, string $ext, string $name, string $head): void
    {
        $kind = OfficePreview::kind($ext);
        if ($kind === '' || $kind === 'text' || $ext === 'csv') {
            return;
        }
        $ole = OleFile::is($head);
        $zip = str_starts_with($head, "PK\x03\x04");
        $trim = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        $w = strlen($head) >= 2 ? unpack('v', $head)[1] : 0;
        $ok = match (true) {
            in_array($ext, ['docx', 'docm', 'dotx', 'dotm', 'xlsx', 'xlsm', 'xltx', 'xltm', 'xlsb', 'pptx', 'pptm', 'ppsx', 'potx', 'potm', 'odt', 'ods', 'odp'], true) => $zip,
            $ext === 'rtf' => str_starts_with($trim, '{\\rtf'),
            $ext === 'doc' => $ole || str_starts_with($trim, '{\\rtf') || in_array($w, [0xA59B, 0xA5DB, 0xA59C, 0xBE31, 0xBE32], true) || (bool)preg_match('/^(<\?xml|<html|<!DOCTYPE|MIME-Version)/i', $trim),
            $ext === 'xls' => $ole || in_array($w, [0x0009, 0x0209, 0x0409, 0x0809], true) || (bool)preg_match('/^(<\?xml|<html|<!DOCTYPE|<table|MIME-Version)/i', $trim)
                || !preg_match('/[\x00-\x08\x0E-\x1F]/', substr($head, 0, 2048)),
            default => $ole,
        };
        if (!$ok) {
            fail($name . ' is not a real ' . OfficePreview::label($ext) . '. Open it in its program and save it again as .' . $ext . '.');
        }
        if ($zip && class_exists('ZipArchive')) {
            $z = new ZipArchive();
            if ($z->open($tmp, ZipArchive::RDONLY) !== true) {
                fail($name . ' is damaged (it cannot be opened as an Office file).');
            }
            $total = 0;
            $names = [];
            for ($i = 0, $n = $z->numFiles; $i < $n; $i++) {
                $st = $z->statIndex($i);
                $total += (int)($st['size'] ?? 0);
                $names[strtolower((string)($st['name'] ?? ''))] = true;
                if ((int)($st['size'] ?? 0) > 314572800 || $total > 1073741824 || $n > 20000) {
                    $z->close();
                    fail($name . ' unpacks to far more than its size and was refused.');
                }
            }
            $z->close();
            $needs = ['docx' => 'word/', 'docm' => 'word/', 'dotx' => 'word/', 'dotm' => 'word/', 'xlsx' => 'xl/', 'xlsm' => 'xl/', 'xltx' => 'xl/', 'xltm' => 'xl/', 'xlsb' => 'xl/',
                'pptx' => 'ppt/', 'pptm' => 'ppt/', 'ppsx' => 'ppt/', 'potx' => 'ppt/', 'potm' => 'ppt/', 'odt' => 'content.xml', 'ods' => 'content.xml', 'odp' => 'content.xml'][$ext] ?? '';
            $found = false;
            foreach (array_keys($names) as $nm) {
                if ($needs === '' || str_starts_with($nm, $needs)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                fail($name . ' is a zip file, not a real ' . OfficePreview::label($ext) . '.');
            }
        }
    }

    /**
     * Files one uploaded file ($_FILES entry for a single file). $meta holds the document fields; 'replaces' => id files it
     * as the next version of that document. Returns the new document id.
     */
    public static function store(array $file, array $meta): int
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            fail('Choose the file to upload.');
        }
        $name = (string)($file['name'] ?? 'file');
        if ($err !== UPLOAD_ERR_OK) {
            fail('The file ' . $name . ' could not be uploaded' . (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': it is too large for the server.' : '.'));
        }
        if ((int)$file['size'] > self::maxBytes()) {
            fail($name . ' is larger than ' . Brgy::setting('brgy_upload_max_mb') . ' MB.');
        }
        if ((int)$file['size'] <= 0) {
            fail($name . ' is empty.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            fail($name . ': only PDF, pictures (JPG, PNG, GIF, WEBP), Word, Excel, PowerPoint, Publisher, Rich Text, OpenDocument, CSV and text files are accepted.');
        }
        $tmp = (string)$file['tmp_name'];
        if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
            fail('The upload of ' . $name . ' was not received correctly.');
        }
        $scan = self::scan($tmp, $ext, $name);
        $sha = hash_file('sha256', $tmp);
        $old = !empty($meta['replaces']) ? self::load((int)$meta['replaces']) : null;
        if (!empty($meta['replaces']) && !$old) {
            fail('The document to replace no longer exists.');
        }
        if ($old && (int)$old['is_current'] !== 1) {
            fail('Only the current version of a document can be replaced.');
        }
        if ($old && !$old['file_name'] && self::formOf((int)$old['id'])) {
            fail('This document is a form made in the program: change it on Forms & Proformas (a final form is reopened by an approver).');
        }
        $scope = (string)($meta['scope'] ?? ($old['scope'] ?? 'project'));
        $projectId = $meta['project_id'] ?? ($old['project_id'] ?? null);
        $dup = DB::row('SELECT id, title FROM brgy_documents WHERE sha256 = :s AND is_current = 1 AND status <> \'void\' AND scope = :sc AND COALESCE(project_id, 0) = :p LIMIT 1',
            ['s' => $sha, 'sc' => $scope, 'p' => (int)$projectId]);
        if ($dup && empty($meta['allow_duplicate'])) {
            fail('This exact file is already filed as "' . $dup['title'] . '". Link that document instead of uploading it again.');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fail('The folder storage/contracts could not be created. Make the storage folder writable by the web server.');
        }
        $stored = 'd' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $ok = PHP_SAPI === 'cli' ? @copy($tmp, $dir . '/' . $stored) : move_uploaded_file($tmp, $dir . '/' . $stored);
        if (!$ok) {
            fail('The file could not be saved. Make the storage folder writable by the web server.');
        }
        $type = (string)($meta['doc_type'] ?? ($old['doc_type'] ?? 'other'));
        $row = [
            'scope' => $scope, 'project_id' => $projectId ?: null,
            'entity_id' => $meta['entity_id'] ?? ($old['entity_id'] ?? null), 'vendor_id' => $meta['vendor_id'] ?? ($old['vendor_id'] ?? null),
            'doc_type' => $type, 'title' => mb_substr(trim((string)($meta['title'] ?? '')) ?: ($old['title'] ?? pathinfo($name, PATHINFO_FILENAME)), 0, 200),
            'doc_no' => self::clean($meta['doc_no'] ?? ($old['doc_no'] ?? null), 80), 'issued_by' => self::clean($meta['issued_by'] ?? ($old['issued_by'] ?? null), 160),
            'issue_date' => valid_date($meta['issue_date'] ?? '') ? $meta['issue_date'] : null,
            'valid_until' => valid_date($meta['valid_until'] ?? '') ? $meta['valid_until'] : null,
            'file_name' => $stored, 'original_name' => mb_substr($name, 0, 190), 'mime_type' => mb_substr((string)(class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($dir . '/' . $stored) : ($file['type'] ?? '')), 0, 100),
            'file_size' => (int)$file['size'], 'sha256' => $sha, 'version_no' => $old ? (int)$old['version_no'] + 1 : 1, 'parent_id' => $old['id'] ?? null, 'is_current' => 1,
            'status' => !empty($meta['status']) ? $meta['status'] : 'for_review', 'is_locked' => 0, 'confidential' => !empty($meta['confidential']) || !empty($old['confidential']) ? 1 : 0,
            'related_type' => $meta['related_type'] ?? ($old['related_type'] ?? null), 'related_id' => $meta['related_id'] ?? ($old['related_id'] ?? null),
            'tags' => self::clean($meta['tags'] ?? ($old['tags'] ?? null), 255), 'notes' => self::clean($meta['notes'] ?? null, 255),
            'scan_status' => mb_substr($scan, 0, 40), 'uploaded_by' => Auth::id(),
        ];
        if ($row['scope'] === 'company') {
            $row['project_id'] = null;
        }
        $id = DB::insert('brgy_documents', $row);
        if ($old) {
            DB::update('brgy_documents', ['is_current' => 0, 'status' => $old['status'] === 'void' ? 'void' : 'superseded'], 'id = :id', ['id' => $old['id']]);
            // checklist items that pointed at the old version now point at the new one, and need a new review
            DB::run("UPDATE brgy_project_reqs SET document_id = :n, status = 'submitted', reviewed_by = NULL, reviewed_at = NULL, updated_at = :u WHERE document_id = :o",
                ['n' => $id, 'o' => $old['id'], 'u' => date('Y-m-d H:i:s')]);
        }
        if ($row['project_id'] && !$old) {
            Brgy::linkProjectDocs((int)$row['project_id']);
        }
        Brgy::log($old ? 'new_version' : 'upload', 'document', $id, $row['title'] . ' v' . $row['version_no'], [
            'reason' => $old ? 'Replaces version ' . $old['version_no'] : null, 'snapshot' => array_diff_key($row, ['file_name' => 1]), 'page_key' => 'brgy.vault',
        ]);
        return $id;
    }

    private static function clean($v, int $len): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $len);
    }

    /** Sends the file (download, or shown in the browser for PDFs and pictures). Every download is in the audit trail. */
    /** The prepared form a document was made from (a final form filed in the vault), or 0 */
    public static function formOf(int $docId): int
    {
        return (int)DB::value('SELECT id FROM brgy_forms WHERE document_id = :d ORDER BY id DESC LIMIT 1', ['d' => $docId]);
    }

    public static function send(array $d, bool $download = false): never
    {
        if (!self::canSee($d)) {
            Access::denyPage('brgy.vault', 'open a confidential document on');
        }
        if (!$d['file_name'] && ($formId = self::formOf((int)$d['id']))) {
            // a form made final in the program has no uploaded file: its copy is the form itself
            redirect(url('brgy.forms', ['a' => $download ? 'word' : 'print', 'id' => $formId]));
        }
        $error = '';
        $file = self::checkedFile($d, $error);
        if ($file === null) {
            exit($error);
        }
        Brgy::log($download ? 'download' : 'view_file', 'document', (int)$d['id'], (string)$d['title'], ['page_key' => 'brgy.vault']);
        $ext = strtolower(pathinfo((string)$d['file_name'], PATHINFO_EXTENSION));
        $inline = !$download && in_array($ext, self::INLINE, true);
        header('Content-Type: ' . ($d['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        // PDFs open in the browser's own viewer, which a sandboxed response would block; every other type is sandboxed
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" . ($ext === 'pdf' ? '; object-src \'self\'' : '; sandbox'));
        $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($d['original_name'] ?: $d['file_name']));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fn . '"');
        readfile($file);
        exit;
    }

    /** The path of a document's file once it is found and matches its checksum; null (with the reason and the HTTP status set) otherwise */
    public static function checkedFile(array $d, string &$error = ''): ?string
    {
        $file = self::dir() . '/' . basename((string)$d['file_name']);
        if (!$d['file_name'] || !is_file($file)) {
            http_response_code(404);
            $error = 'The file is missing from the storage folder.';
            return null;
        }
        if ($d['sha256'] && hash_file('sha256', $file) !== $d['sha256']) {
            Brgy::log('integrity_failed', 'document', (int)$d['id'], (string)$d['title'], ['reason' => 'Checksum does not match the file on disk', 'page_key' => 'brgy.vault']);
            http_response_code(409);
            $error = 'The file on disk does not match the checksum recorded when it was filed. It was not sent. Ask the administrator to check the storage folder.';
            return null;
        }
        return $file;
    }

    /**
     * The preview of an Office file: the quick view (HTML made by the program's own readers, for the page to show) or the
     * page view (a PDF made by LibreOffice on the server). Both are kept by the file's checksum, so they are made once.
     */
    public static function preview(array $d, bool $pdf): never
    {
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $msg = function (string $icon, string $text, int $code = 200) use ($pdf): never {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            if ($pdf) {
                // shown inside the page view's frame: a page of its own, with nothing active in it
                header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
                exit('<!doctype html><meta charset="utf-8"><body style="margin:0;display:grid;place-items:center;min-height:90vh;background:#f5f6f8;font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;color:#4b5563">'
                    . '<div style="max-width:520px;padding:24px;text-align:center">' . h($text) . '</div></body>');
            }
            exit('<div class="op-msg"><i class="bi bi-' . h($icon) . '"></i>' . h($text) . '</div>');
        };
        if (!self::canSee($d)) {
            $msg('shield-lock', 'This document is confidential.', 403);
        }
        if (!$d['file_name']) {
            $msg('file-earmark-text', 'This document was made in the program; it has no file to show.');
        }
        $error = '';
        $file = self::checkedFile($d, $error);
        if ($file === null) {
            $msg('file-earmark-x', $error, http_response_code() ?: 404);
        }
        $ext = strtolower(pathinfo((string)$d['file_name'], PATHINFO_EXTENSION));
        if (OfficePreview::kind($ext) === '') {
            $msg('file-earmark', 'This kind of file has no preview.');
        }
        $key = (string)($d['sha256'] ?: hash_file('sha256', $file));
        if ($pdf) {
            $err = null;
            $out = OfficePreview::pdf($file, $ext, $key, $err);
            if (!$out) {
                $msg('file-earmark-x', 'The page view could not be made: ' . $err . ' The quick view still shows the file.');
            }
            Brgy::log('view_file', 'document', (int)$d['id'], (string)$d['title'], ['reason' => 'Page view', 'page_key' => 'brgy.vault']);
            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($out));
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'");
            $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string)($d['original_name'] ?: $d['file_name']), PATHINFO_FILENAME)) . '.pdf';
            header('Content-Disposition: inline; filename="' . $fn . '"');
            readfile($out);
            exit;
        }
        $r = OfficePreview::html($file, $ext, $key);
        if ($r['error'] !== '') {
            $msg(str_contains($r['error'], 'password') ? 'lock' : 'file-earmark-x', $r['error']);
        }
        Brgy::log('view_file', 'document', (int)$d['id'], (string)$d['title'], ['reason' => 'Quick view', 'page_key' => 'brgy.vault']);
        header('Content-Type: text/html; charset=utf-8');
        echo $r['html'];
        exit;
    }

    /** All versions of a document, newest first */
    public static function versions(array $d): array
    {
        $chain = [];
        $cur = $d;
        // walk down to the first version, then everything that came after it
        $guard = 0;
        while ($cur && $cur['parent_id'] && $guard++ < 50) {
            $cur = DB::row('SELECT * FROM brgy_documents WHERE id = :id', ['id' => $cur['parent_id']]);
        }
        $ids = $cur ? [(int)$cur['id']] : [];
        $guard = 0;
        while ($ids && $guard++ < 50) {
            $last = end($ids);
            $next = DB::value('SELECT id FROM brgy_documents WHERE parent_id = :p ORDER BY id LIMIT 1', ['p' => $last]);
            if (!$next) {
                break;
            }
            $ids[] = (int)$next;
        }
        foreach (array_reverse($ids) as $id) {
            $chain[] = DB::row('SELECT d.*, u.full_name AS uploaded_by_name FROM brgy_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.id = :id', ['id' => $id]);
        }
        return $chain;
    }

    /** Review of a filed document: approved (counts on checklists), returned, or final (approved and locked) */
    public static function review(array $d, string $decision, string $remarks): void
    {
        if (!in_array($decision, ['approved', 'returned', 'final'], true)) {
            fail('Choose approve, return or final.');
        }
        if ((int)$d['is_locked'] === 1) {
            fail('The document is final and locked. File a new version instead.');
        }
        if ($decision === 'returned' && trim($remarks) === '') {
            fail('Say what has to be corrected.');
        }
        if ((int)$d['uploaded_by'] === (int)Auth::id() && Brgy::on('brgy_sod_strict') && !Access::isAdmin()) {
            fail('You filed this document; another person has to review it.');
        }
        DB::tx(function () use ($d, $decision, $remarks) {
            DB::update('brgy_documents', ['status' => $decision, 'is_locked' => $decision === 'final' ? 1 : 0, 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'),
                'review_remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null], 'id = :id', ['id' => $d['id']]);
            $reqStatus = $decision === 'returned' ? 'returned' : 'approved';
            DB::run('UPDATE brgy_project_reqs SET status = :s, reviewed_by = :u, reviewed_at = :t, remarks = :r, updated_at = :t2 WHERE document_id = :d AND status <> \'waived\'',
                ['s' => $reqStatus, 'u' => Auth::id(), 't' => date('Y-m-d H:i:s'), 'r' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null, 't2' => date('Y-m-d H:i:s'), 'd' => $d['id']]);
            if ($decision !== 'returned' && $d['scope'] === 'company') {
                foreach (DB::column("SELECT DISTINCT project_id FROM brgy_project_reqs WHERE doc_type = :t AND status IN ('missing', 'returned')", ['t' => $d['doc_type']]) as $pid) {
                    Brgy::linkCompanyDocs((int)$pid);
                }
            }
            Brgy::log($decision === 'returned' ? 'return' : 'approve', 'document', (int)$d['id'], (string)$d['title'], [
                'reason' => $remarks !== '' ? $remarks : null, 'changes' => [['field' => 'status', 'old' => $d['status'], 'new' => $decision]], 'page_key' => 'brgy.vault',
            ]);
        });
    }

    // ================================================================== templates

    /** The fields a template can use, by group: key => description */
    public const FIELDS = [
        'Company' => [
            'company.name' => 'Company name', 'company.address' => 'Address', 'company.tin' => 'TIN', 'company.phone' => 'Phone', 'company.email' => 'E-mail',
            'company.reg_no' => 'SEC / DTI / CDA registration no.', 'company.philgeps_no' => 'PhilGEPS registration no.', 'company.philgeps_class' => 'PhilGEPS membership',
            'company.vat_status' => 'VAT / non-VAT', 'company.bank_details' => 'Bank details for payment', 'company.logo' => 'Logo (picture)',
            'signatory.name' => 'Authorized representative', 'signatory.position' => 'Position of the representative',
            'signatory.name_upper' => 'Authorized representative (capitals)', 'signatory.position_upper' => 'Position of the representative (capitals)',
            'signatory.addressee' => 'Representative as addressed (MR. JUAN DELA CRUZ)', 'signatory.salutation' => 'Representative in a salutation (Mr. J. Dela Cruz)',
            'signatory.mobile' => 'Cellphone of the representative', 'company.address_upper' => 'Address (capitals)',
            'company.manager' => 'Person managing the affairs of the firm', 'company.manager_upper' => 'Person managing the affairs (capitals)', 'company.manager_position' => 'Designation of the manager',
            'company.manager_phone' => 'Telephone of the manager', 'company.manager_mobile' => 'Cellphone of the manager',
            'company.liaison' => 'Authorized liaison officer', 'company.liaison_upper' => 'Authorized liaison officer (capitals)', 'company.liaison_position' => 'Designation of the liaison officer',
            'company.liaison_phone' => 'Telephone of the liaison officer', 'company.liaison_mobile' => 'Cellphone of the liaison officer',
        ],
        'Letterhead and signatures' => [
            'letterhead' => 'Letterhead (pictures and lines set in the Format tab)', 'signatories' => 'Signatures (the signatories set in the Format tab)',
        ],
        'Procuring entity' => [
            'entity.name' => 'Official name', 'entity.address' => 'Address', 'entity.tin' => 'TIN', 'entity.province' => 'Province', 'entity.city' => 'City / municipality',
            'entity.head_name' => 'Punong Barangay / head', 'entity.head_position' => 'Position of the head', 'entity.bac_chair' => 'BAC Chairperson',
            'entity.treasurer' => 'Treasurer', 'entity.secretary' => 'Secretary', 'entity.end_user' => 'End-user / requesting office',
            'entity.title' => 'Barangay Sabang (or the official name)', 'entity.title_upper' => 'BARANGAY SABANG (capitals)', 'entity.lgu_line' => 'City of Lapu-Lapu / Municipality of ...',
            'entity.lgu_line_upper' => 'CITY OF LAPU-LAPU (capitals)', 'entity.province_upper' => 'Province (capitals)',
            'entity.bac_member_1' => 'BAC member 1', 'entity.bac_member_2' => 'BAC member 2', 'entity.bac_member_3' => 'BAC member 3', 'entity.bac_member_4' => 'BAC member 4', 'entity.bac_member_5' => 'BAC member 5',
            'entity.bac_member_1_upper' => 'BAC member 1 (capitals)', 'entity.bac_member_2_upper' => 'BAC member 2 (capitals)', 'entity.bac_member_3_upper' => 'BAC member 3 (capitals)',
            'entity.bac_member_4_upper' => 'BAC member 4 (capitals)', 'entity.bac_member_5_upper' => 'BAC member 5 (capitals)',
        ],
        'Project and contract' => [
            'project.no' => 'Project no.', 'project.title' => 'Title', 'project.reference_no' => 'PR / RFQ / ITB no.', 'project.philgeps_ref' => 'PhilGEPS reference',
            'project.mode' => 'Procurement mode', 'project.category' => 'Category', 'project.regime' => 'Procurement law', 'project.abc' => 'ABC (amount)',
            'project.abc_words' => 'ABC in words', 'project.bid_amount' => 'Bid amount', 'project.bid_amount_words' => 'Bid amount in words',
            'project.contract_amount' => 'Contract amount (with change orders)', 'project.contract_amount_words' => 'Contract amount in words',
            'project.contract_no' => 'Contract no.', 'project.po_no' => 'Purchase order no.', 'project.contract_ref' => 'Contract or PO no. (whichever there is)', 'project.noa_ref' => 'Notice of award ref.', 'project.noa_date' => 'Notice of award date',
            'project.ntp_date' => 'Notice to proceed date', 'project.contract_date' => 'Contract signing date', 'project.bid_deadline' => 'Bid deadline',
            'project.delivery_site' => 'Delivery site', 'project.delivery_days' => 'Delivery period (days)', 'project.due_date' => 'Delivery / completion due',
            'project.start_date' => 'Start date', 'project.warranty_months' => 'Warranty (months)', 'project.warranty_until' => 'Warranty until',
            'project.retention_pct' => 'Retention %', 'project.ld_rate' => 'Liquidated damages rate per day', 'project.funding_source' => 'Source of funds',
            'project.payment_terms' => 'Payment terms', 'project.end_user' => 'End-user', 'project.specs' => 'Specifications / scope (text)',
            'project.pm' => 'Project manager', 'project.preparer' => 'Prepared by', 'project.checker' => 'Checked by', 'project.approver' => 'Approved by',
            'project.contract_price_text' => 'Contract price in words and figures (One Hundred ... Pesos and 00/100 (Php 194,500.00) Only)',
            'project.abc_text' => 'ABC in words and figures', 'project.bid_amount_plain' => 'Bid amount (figures only)', 'project.contract_amount_plain' => 'Contract amount (figures only)',
            'project.delivery_days_words' => 'Delivery period in words (Thirty (30) calendar days)', 'project.warranty_words' => 'Warranty in words (three (3) years)',
            'project.duration' => 'Duration of the contract (30 Days)', 'project.location' => 'Location of the project',
            'project.law_text' => 'The procurement law in a sentence (Republic Act No. 12009, otherwise known as ...)',
        ],
        'Tables' => [
            'lines.table' => 'Price schedule (bid quantities)', 'lines.contract_table' => 'Contract lines (with change orders)', 'lines.total' => 'Total of the price schedule',
            'lines.total_words' => 'Total in words', 'costing.table' => 'Costing and margin (internal)', 'requirements.table' => 'Bid documents (eligibility and bid papers)', 'requirements.all_table' => 'Compliance checklist (every stage)',
            'soa.table' => 'Statement of account (billings, collections, balance)', 'closeout.table' => 'Closeout figures',
            'specs.items' => 'Technical specifications with the statement of compliance (Section VII)', 'sched.items' => 'Schedule of requirements (Section VI)',
            'contracts.ongoing_table' => 'Statement of all ongoing government and private contracts', 'contracts.ongoing_total' => 'Total value of the outstanding works',
            'contracts.slcc_table' => 'Statement of the single largest completed contract similar to the project (SLCC)',
        ],
        'Delivery' => [
            'delivery.no' => 'Delivery receipt no.', 'delivery.date' => 'Delivery date', 'delivery.to' => 'Delivered to', 'delivery.received_by' => 'Received by',
            'delivery.received_position' => 'Position of the receiver', 'delivery.table' => 'Delivered lines', 'delivery.acceptance_ref' => 'Acceptance report no.',
            'delivery.inspectors' => 'Inspected by',
        ],
        'Billing and collection' => [
            'billing.no' => 'Billing no.', 'billing.date' => 'Billing date', 'billing.si_no' => 'Sales invoice no.', 'billing.type' => 'Kind of billing',
            'billing.gross' => 'Amount billed', 'billing.gross_words' => 'Amount billed in words', 'billing.net' => 'Amount net of VAT', 'billing.vat' => 'VAT',
            'billing.retention' => 'Retention expected', 'billing.ewt' => 'Income tax expected to be withheld', 'billing.vatwh' => 'VAT expected to be withheld',
            'billing.net_receivable' => 'Net amount expected', 'billing.table' => 'Billed lines', 'billing.due_date' => 'Due date', 'billing.invoice_no' => 'Invoice no. in the books',
            'collection.no' => 'Collection no.', 'collection.date' => 'Collection date', 'collection.amount' => 'Cash received', 'collection.amount_words' => 'Cash received in words',
            'collection.reference' => 'Check / LDDAP-ADA no.', 'collection.table' => 'Collection breakdown',
        ],
        'Transaction form (SOP)' => [
            'txn.series' => 'Series no.', 'txn.date' => 'Date of the transaction', 'txn.tradename' => 'Tradename', 'txn.types' => 'Type boxes (ticked)', 'txn.type' => 'Type',
            'txn.barangay' => 'Barangay', 'txn.cheque_no' => 'Cheque number', 'txn.gross' => 'Gross amount', 'txn.wht' => 'Withholding tax', 'txn.cheque_amount' => 'Cheque amount',
            'txn.profit' => 'Profit (internal)', 'txn.requestor' => 'Requestor', 'txn.inspector' => 'Inspector', 'txn.purpose' => 'Purpose', 'txn.prepared_by' => 'Prepared / received by',
        ],
        'Barangay papers (SOP)' => [
            'entity.short' => 'Barangay name (short)', 'entity.short_upper' => 'Barangay name (short, capitals)', 'entity.name_upper' => 'Official name (capitals)', 'entity.phone' => 'Telephone',
            'entity.location' => 'Location (barangay, city)', 'entity.head_name_upper' => 'Punong Barangay (capitals)', 'entity.appropriations_chair' => 'Chairman, Committee on Appropriations',
            'entity.appropriations_chair_upper' => 'Chairman, Committee on Appropriations (capitals)', 'entity.treasurer_upper' => 'Treasurer (capitals)', 'entity.bac_chair_upper' => 'BAC Chairperson (capitals)',
            'entity.bookkeeper' => 'Barangay bookkeeper', 'entity.city_accountant' => 'City / municipal accountant', 'entity.requirement_notes' => 'Submission requirements of the entity',
            'entity.bac_signatures' => 'Signatures of the BAC (chair and members)', 'entity.seal' => 'Seal of the barangay (picture)', 'entity.lgu_seal' => 'Seal of the city / municipality (picture)',
            'lgu.header' => 'Letterhead of the LGU with the seals', 'company.name_upper' => 'Company name (capitals)',
            'project.txn_form_no' => 'Transaction form no.', 'project.purpose' => 'Purpose', 'project.requestor' => 'Requestor', 'project.inspector' => 'Inspector', 'project.inspector_upper' => 'Inspector (capitals)',
            'project.fund' => 'Fund', 'project.abc_plain' => 'ABC (figures only)', 'project.prebid' => 'Pre-bid conference', 'project.sales_rep' => 'Sales representative',
            'project.submitter' => 'Authorized representative (bidding)', 'project.title_upper' => 'Title (capitals)', 'project.completion_date' => 'Date of completion',
            'pr.no' => 'Purchase request no.', 'pr.date' => 'Purchase request date', 'pr.items' => 'Purchase request items (estimated costs)',
            'po.no' => 'Purchase order no.', 'po.date' => 'Purchase order date', 'po.modes' => 'Mode of procurement boxes', 'po.place' => 'Place of delivery', 'po.delivery_date' => 'Date of delivery',
            'po.delivery_term' => 'Delivery term', 'po.payment_term' => 'Payment term', 'po.items' => 'Purchase order items', 'po.total_words' => 'Purchase order total in words',
            'iar.no' => 'IAR no.', 'iar.date' => 'IAR date', 'iar.invoice_no' => 'Invoice no. (IAR)', 'iar.invoice_date' => 'Invoice date (IAR)', 'iar.items' => 'IAR items',
            'ris.no' => 'RIS no.', 'ris.date' => 'RIS date', 'ris.items' => 'RIS items',
            'rfq.date' => 'Date of the request for quotation', 'rfq.items' => 'Items for quotation (prices blank)', 'rfq.delivery_period' => 'Delivery period',
            'abstract.date' => 'Date of the abstract', 'abstract.table' => 'Abstract of the quotations (suppliers side by side)',
            'dv.no' => 'DV no.', 'dv.date' => 'DV date', 'dv.fund' => 'Fund (DV)', 'dv.particulars' => 'Particulars of the payment', 'dv.gross' => 'Gross amount (DV)',
            'dv.taxes' => 'Taxes withheld (VAT, EWT)', 'dv.net' => 'Net amount (DV)', 'dv.net_words_upper' => 'Net amount in words (capitals)', 'dv.papers' => 'Supporting papers attached',
            'dv.check_no' => 'Check no.', 'dv.bank' => 'Bank name', 'dv.or_no' => 'OR number',
            'transmittal.no' => 'Transmittal no.', 'transmittal.date' => 'Transmittal date', 'transmittal.released_by' => 'Released by', 'sop.papers_table' => 'Papers transmitted (table)',
        ],
        'Document' => ['doc.no' => 'Form no.', 'date.today' => "Today's date (as the Format tab sets the dates)", 'date.today_mdy' => "Today's date (October 14, 2026)",
            'date.today_dmy' => "Today's date (14 October 2026)", 'date.today_short' => "Today's date (10/14/2026)"],
    ];

    /** Fields whose value is a picture, a table or boxes (not placed on pre-printed paper as text) */
    public const HTML_FIELDS = ['company.logo', 'entity.seal', 'entity.lgu_seal', 'lgu.header', 'txn.types', 'po.modes', 'dv.taxes', 'dv.papers', 'abstract.table', 'entity.bac_signatures',
        'sop.papers_table', 'costing.table', 'requirements.table', 'requirements.all_table', 'soa.table', 'closeout.table', 'delivery.table', 'billing.table', 'collection.table',
        'letterhead', 'signatories', 'specs.items', 'sched.items', 'contracts.ongoing_table', 'contracts.slcc_table'];

    /** How the long dates of a form are written (the Format tab of its template): dmy 14 October 2026, mdy October 14, 2026 */
    private static string $dateStyle = 'dmy';

    private static function d(?string $date, bool $long = true): string
    {
        if (!$date) {
            return '';
        }
        $t = strtotime($date);
        return $t ? ($long ? date(self::$dateStyle === 'mdy' ? 'F j, Y' : 'j F Y', $t) : date('m/d/Y', $t)) : '';
    }

    /** 30 => "Thirty" */
    public static function numWords(int $n): string
    {
        return $n > 0 ? ucwords((string)preg_replace('/\s+and \d\d\/100 only$/', '', trim(amount_in_words($n, ''))), " -") : '';
    }

    /** "One Hundred Ninety-Four Thousand Five Hundred Pesos and 00/100 (Php 194,500.00) Only" */
    public static function priceText(float $amount, string $sign = 'Php '): string
    {
        if ($amount <= 0) {
            return '';
        }
        $w = (string)preg_replace('/\s+only$/i', '', amount_in_words($amount));
        return str_replace(' And ', ' and ', ucwords($w, " -")) . ' (' . $sign . number_format($amount, 2) . ') Only';
    }

    /** "Mr. J. Dela Cruz" from "Juan T. Dela Cruz" */
    private static function salutation(string $title, string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return '';
        }
        $parts = explode(' ', $name);
        $first = array_shift($parts);
        // the surname: the last word, with the particles before it (dela, de, del, delos, de los, san, sta.)
        $last = array_pop($parts) ?? '';
        while ($parts && preg_match('/^(de|del|dela|della|delos|de los|los|la|san|sta\.?|sto\.?|van|von)$/i', (string)end($parts))) {
            $last = array_pop($parts) . ' ' . $last;
        }
        if ($last === '') {
            return trim($title . ' ' . $first);
        }
        return trim($title . ' ' . mb_substr($first, 0, 1) . '. ' . $last);
    }

    private static function words(float $amount): string
    {
        return $amount > 0 ? amount_in_words($amount) : '';
    }

    /**
     * The values of the placeholders for a project, and for the record the form is made for ($related = [type, id]).
     * A value is a string (escaped when printed) or ['html' => ...] for a table or picture.
     */
    public static function context(?array $p, ?array $related = null, string $docNo = '', ?array $L = null): array
    {
        $L ??= BrgyLayout::layout(null);
        $s = Settings::all();
        $c = [
            'company.name' => $s['company_name'] ?? '', 'company.address' => $s['company_address'] ?? '', 'company.tin' => $s['company_tin'] ?? '',
            'company.phone' => $s['company_phone'] ?? '', 'company.email' => $s['company_email'] ?? '', 'company.reg_no' => Brgy::setting('brgy_company_reg_no'),
            'company.philgeps_no' => Brgy::setting('brgy_philgeps_no'), 'company.philgeps_class' => Brgy::setting('brgy_philgeps_class'),
            'company.vat_status' => Brgy::setting('brgy_bir_status') === 'non_vat' ? 'Non-VAT registered' : 'VAT registered',
            'company.bank_details' => Brgy::setting('brgy_bank_details'),
            'company.logo' => ['html' => Logo::path() ? '<img src="' . h(Logo::url()) . '" alt="" style="max-height:64px">' : ''],
            'signatory.name' => Brgy::setting('brgy_signatory_name'), 'signatory.position' => Brgy::setting('brgy_signatory_position'),
            'company.name_upper' => mb_strtoupper((string)($s['company_name'] ?? '')),
            'doc.no' => $docNo, 'date.today' => '', 'date.today_mdy' => date('F j, Y'), 'date.today_dmy' => date('j F Y'), 'date.today_short' => date('m/d/Y'),
        ];
        self::$dateStyle = ($L['date_style'] ?? 'dmy') === 'mdy' ? 'mdy' : 'dmy';
        $c['date.today'] = self::d(date('Y-m-d'));
        $up = fn($v) => mb_strtoupper(trim((string)$v));
        $sigName = Brgy::setting('brgy_signatory_name');
        $title = trim(Brgy::setting('brgy_signatory_title'));
        $c += [
            'signatory.name_upper' => $up($sigName), 'signatory.position_upper' => $up(Brgy::setting('brgy_signatory_position')),
            'signatory.addressee' => $sigName !== '' ? $up(trim($title . ' ' . $sigName)) : '', 'signatory.salutation' => self::salutation($title, $sigName),
            'signatory.mobile' => Brgy::setting('brgy_signatory_mobile'), 'company.address_upper' => $up($s['company_address'] ?? ''),
            'company.manager' => Brgy::setting('brgy_manager_name') ?: $sigName, 'company.manager_upper' => $up(Brgy::setting('brgy_manager_name') ?: $sigName),
            'company.manager_position' => Brgy::setting('brgy_manager_position') ?: Brgy::setting('brgy_signatory_position'),
            'company.manager_phone' => Brgy::setting('brgy_manager_phone') ?: (string)($s['company_phone'] ?? ''), 'company.manager_mobile' => Brgy::setting('brgy_manager_mobile') ?: Brgy::setting('brgy_signatory_mobile'),
            'company.liaison' => Brgy::setting('brgy_liaison_name'), 'company.liaison_upper' => $up(Brgy::setting('brgy_liaison_name')), 'company.liaison_position' => Brgy::setting('brgy_liaison_position'),
            'company.liaison_phone' => Brgy::setting('brgy_liaison_phone'), 'company.liaison_mobile' => Brgy::setting('brgy_liaison_mobile'),
        ];
        if (!$p) {
            return self::framing($c, $L, null);
        }
        $e = Brgy::entity((int)$p['entity_id']);
        $off = fn(string $r) => ($o = Brgy::official((int)$p['entity_id'], $r)) ? $o : null;
        $head = $off('punong_barangay');
        $c += [
            'entity.name' => $e['official_name'], 'entity.address' => (string)$e['address'], 'entity.tin' => (string)$e['tin'], 'entity.province' => (string)$e['province'],
            'entity.city' => (string)$e['city_municipality'], 'entity.head_name' => $head['name'] ?? '', 'entity.head_position' => $head['position'] ?? '',
            'entity.bac_chair' => $off('bac_chair')['name'] ?? '', 'entity.treasurer' => $off('treasurer')['name'] ?? '', 'entity.secretary' => $off('secretary')['name'] ?? '',
            'entity.end_user' => $p['end_user'] ?: ($off('end_user')['name'] ?? ''),
        ];
        $short = trim((string)($e['barangay'] ?? '')) ?: (string)preg_replace('/^(barangay|brgy\.?)\s+/i', '', (string)$e['official_name']);
        $etitle = $e['entity_type'] === 'barangay' ? 'Barangay ' . $short : (string)$e['official_name'];
        $lgu = self::lguLine((string)$e['city_municipality']);
        $c += ['entity.title' => $etitle, 'entity.title_upper' => $up($etitle), 'entity.lgu_line' => $lgu !== '' ? ucwords(mb_strtolower($lgu), " -") : '', 'entity.lgu_line_upper' => $lgu,
            'entity.province_upper' => $up($e['province'])];
        $c['entity.lgu_line'] = (string)preg_replace('/^(City|Municipality) Of /', '$1 of ', $c['entity.lgu_line']);
        $members = DB::all("SELECT name FROM brgy_contacts WHERE entity_id = :e AND role_code = 'bac_member' AND (valid_to IS NULL OR valid_to >= :d) ORDER BY id", ['e' => $p['entity_id'], 'd' => today()]);
        for ($i = 1; $i <= 5; $i++) {
            $c['entity.bac_member_' . $i] = (string)($members[$i - 1]['name'] ?? '');
            $c['entity.bac_member_' . $i . '_upper'] = $up($members[$i - 1]['name'] ?? '');
        }
        $total = Brgy::contractTotal($p);
        $c += [
            'project.no' => $p['project_no'], 'project.title' => $p['title'], 'project.reference_no' => (string)$p['reference_no'], 'project.philgeps_ref' => (string)$p['philgeps_ref'],
            'project.mode' => (string)($p['mode_name'] ?? ''), 'project.category' => Brgy::label(Brgy::CATEGORIES, $p['category']), 'project.regime' => Brgy::label(Brgy::REGIMES, $p['regime']),
            'project.abc' => (float)$p['abc_amount'] > 0 ? '₱' . number_format((float)$p['abc_amount'], 2) : '', 'project.abc_words' => self::words((float)$p['abc_amount']),
            'project.bid_amount' => (float)$p['bid_amount'] > 0 ? '₱' . number_format((float)$p['bid_amount'], 2) : '', 'project.bid_amount_words' => self::words((float)$p['bid_amount']),
            'project.contract_amount' => $total > 0 ? '₱' . number_format($total, 2) : '', 'project.contract_amount_words' => self::words($total),
            'project.contract_no' => (string)$p['contract_no'], 'project.po_no' => (string)$p['po_no'],
            'project.contract_ref' => trim(($p['contract_no'] ? 'Contract ' . $p['contract_no'] : '') . ($p['contract_no'] && $p['po_no'] && $p['po_no'] !== $p['contract_no'] ? ', ' : '')
                . ($p['po_no'] && $p['po_no'] !== $p['contract_no'] ? 'PO ' . $p['po_no'] : '')) ?: (string)$p['noa_ref'], 'project.noa_ref' => (string)$p['noa_ref'], 'project.noa_date' => self::d($p['noa_date']),
            'project.ntp_date' => self::d($p['ntp_date']), 'project.contract_date' => self::d($p['contract_signed_date']), 'project.bid_deadline' => $p['bid_deadline'] ? date('j F Y, g:i A', strtotime((string)$p['bid_deadline'])) : '',
            'project.delivery_site' => (string)$p['delivery_site'], 'project.delivery_days' => $p['delivery_days'] ? $p['delivery_days'] . ' calendar days' : '',
            'project.due_date' => self::d($p['due_date']), 'project.start_date' => self::d($p['start_date']), 'project.warranty_months' => $p['warranty_months'] ? $p['warranty_months'] . ' months' : '',
            'project.warranty_until' => self::d($p['warranty_until']), 'project.retention_pct' => (float)$p['retention_pct'] > 0 ? rtrim(rtrim(number_format((float)$p['retention_pct'], 2), '0'), '.') . '%' : '',
            'project.ld_rate' => (float)$p['ld_rate_pct'] > 0 ? rtrim(rtrim(number_format((float)$p['ld_rate_pct'], 4), '0'), '.') . '% per day of delay' : '',
            'project.funding_source' => (string)$p['funding_source'], 'project.payment_terms' => (string)$p['payment_terms'], 'project.end_user' => (string)$p['end_user'],
            'project.specs' => (string)$p['specs'], 'project.pm' => Brgy::userName((int)$p['pm_id']), 'project.preparer' => Brgy::userName((int)$p['preparer_id']),
            'project.checker' => Brgy::userName((int)$p['checker_id']), 'project.approver' => Brgy::userName((int)$p['approver_id']),
        ];
        $price = $total > 0 ? $total : (float)$p['bid_amount'];
        $days = (int)$p['delivery_days'];
        $wm = (int)$p['warranty_months'];
        $c += [
            'project.contract_price_text' => self::priceText($price), 'project.abc_text' => self::priceText((float)$p['abc_amount'], '₱ '),
            'project.bid_amount_plain' => (float)$p['bid_amount'] > 0 ? number_format((float)$p['bid_amount'], 2) : '', 'project.contract_amount_plain' => $total > 0 ? number_format($total, 2) : '',
            'project.delivery_days_words' => $days > 0 ? self::numWords($days) . ' (' . $days . ') calendar days' : '',
            'project.warranty_words' => $wm > 0 ? ($wm % 12 === 0 ? mb_strtolower(self::numWords(intdiv($wm, 12))) . ' (' . intdiv($wm, 12) . ') year' . ($wm === 12 ? '' : 's')
                : mb_strtolower(self::numWords($wm)) . ' (' . $wm . ') month' . ($wm === 1 ? '' : 's')) : '',
            'project.duration' => $days > 0 ? $days . ' Days' : '',
            'project.law_text' => $p['regime'] === 'ra9184' ? 'Republic Act No. 9184, otherwise known as the "Government Procurement Reform Act,"'
                : 'Republic Act No. 12009, otherwise known as the "New Government Procurement Act,"',
            'project.location' => (string)($p['delivery_site'] ?: trim($etitle . ($e['city_municipality'] ? ', ' . $e['city_municipality'] : '') . ($e['province'] ? ', ' . $e['province'] : ''), ', ')),
        ];
        $lines = Brgy::lines((int)$p['id']);
        $c['lines.table'] = ['html' => BrgyLayout::itemsTable('lines.table', $lines, $L)];
        $c['lines.contract_table'] = ['html' => BrgyLayout::itemsTable('lines.contract_table', $lines, $L)];
        $c += self::sopContext($p, $e, $lines, $L);
        $lt = Brgy::lineTotals((int)$p['id']);
        $c['lines.total'] = '₱' . number_format($lt['bid'], 2);
        $c['lines.total_words'] = self::words($lt['bid']);
        $c['costing.table'] = ['html' => self::costingTable($lines)];
        $c['requirements.table'] = ['html' => self::requirementsTable((int)$p['id'], ['eligibility', 'bid'])];
        $c['requirements.all_table'] = ['html' => self::requirementsTable((int)$p['id'])];
        $c['soa.table'] = ['html' => self::soaTable($p)];
        $c['closeout.table'] = ['html' => self::closeoutTable($p)];
        $delivery = $days > 0 ? 'Delivery of Goods shall be within <b><u>' . h(self::numWords($days) . ' (' . $days . ') Calendar days after receipt of Notice of Award/P.O.') . '</u></b>' : '';
        $c['specs.items'] = ['html' => BrgyLayout::itemsTable('specs.items', $lines, $L)];
        $c['sched.items'] = ['html' => BrgyLayout::itemsTable('sched.items', $lines, $L, ['delivery' => $delivery])];
        [$ongoing, $outstanding] = self::ongoingTable($p);
        $c['contracts.ongoing_table'] = ['html' => $ongoing];
        $c['contracts.ongoing_total'] = $outstanding > 0 ? number_format($outstanding, 2) : '0.00';
        $c['contracts.slcc_table'] = ['html' => self::slccTable($p)];
        if ($related) {
            [$rt, $rid] = $related;
            if ($rt === 'delivery' && ($d = DB::row('SELECT * FROM brgy_deliveries WHERE id = :id', ['id' => $rid]))) {
                $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d ORDER BY id', ['d' => $rid]);
                $rows = '';
                $i = 0;
                foreach ($dl as $l) {
                    $rows .= '<tr><td style="text-align:center">' . (++$i) . '</td><td>' . h($l['description']) . ($l['serial_batch'] ? '<br><small>Serial / batch: ' . h($l['serial_batch']) . '</small>' : '') . '</td><td style="text-align:center">' . h((string)$l['unit'])
                        . '</td><td style="text-align:right">' . qty($l['qty']) . '</td><td style="text-align:right">' . ((float)$l['qty_accepted'] > 0 ? qty($l['qty_accepted']) : '') . '</td><td>' . h((string)$l['remarks']) . '</td></tr>';
                }
                $c += ['delivery.no' => $d['dr_no'], 'delivery.date' => self::d($d['delivery_date']), 'delivery.to' => (string)($d['delivered_to'] ?: $p['delivery_site']),
                    'delivery.received_by' => (string)$d['received_by_name'], 'delivery.received_position' => (string)$d['received_position'], 'delivery.acceptance_ref' => (string)$d['acceptance_ref'],
                    'delivery.inspectors' => (string)$d['inspectors'],
                    'delivery.table' => ['html' => '<table class="ft"><thead><tr><th style="width:6%">No.</th><th>Description</th><th style="width:9%">Unit</th><th style="width:11%">Qty delivered</th><th style="width:11%">Qty accepted</th><th style="width:20%">Remarks</th></tr></thead><tbody>' . $rows . '</tbody></table>']];
            }
            if (in_array($rt, ['billing', 'collection'], true)) {
                $bid = $rt === 'billing' ? $rid : (int)DB::value('SELECT billing_id FROM brgy_collections WHERE id = :id', ['id' => $rid]);
                if ($b = DB::row('SELECT b.*, t.txn_no FROM brgy_billings b LEFT JOIN transactions t ON t.id = b.invoice_txn_id WHERE b.id = :id', ['id' => $bid])) {
                    $bl = DB::all('SELECT * FROM brgy_billing_lines WHERE billing_id = :b ORDER BY id', ['b' => $bid]);
                    $rows = '';
                    $i = 0;
                    foreach ($bl as $l) {
                        $rows .= '<tr><td style="text-align:center">' . (++$i) . '</td><td>' . h($l['description']) . '</td><td style="text-align:center">' . h((string)$l['unit']) . '</td><td style="text-align:right">' . qty($l['qty'])
                            . '</td><td style="text-align:right">' . number_format((float)$l['unit_price'], 2) . '</td><td style="text-align:right">' . number_format((float)$l['gross'], 2) . '</td></tr>';
                    }
                    $netRecv = r2((float)$b['gross_amount'] - (float)$b['retention_expected'] - (float)$b['ewt_expected'] - (float)$b['vatwh_expected'] - (float)$b['ld_expected']);
                    $c += ['billing.no' => $b['billing_no'], 'billing.date' => self::d($b['billing_date']), 'billing.si_no' => (string)$b['si_no'], 'billing.type' => Brgy::label(Brgy::BILLING_TYPES, $b['billing_type']),
                        'billing.gross' => '₱' . number_format((float)$b['gross_amount'], 2), 'billing.gross_words' => self::words((float)$b['gross_amount']),
                        'billing.net' => '₱' . number_format((float)$b['net_amount'], 2), 'billing.vat' => '₱' . number_format((float)$b['vat_amount'], 2),
                        'billing.retention' => '₱' . number_format((float)$b['retention_expected'], 2), 'billing.ewt' => '₱' . number_format((float)$b['ewt_expected'], 2),
                        'billing.vatwh' => '₱' . number_format((float)$b['vatwh_expected'], 2), 'billing.net_receivable' => '₱' . number_format($netRecv, 2),
                        'billing.due_date' => self::d($b['due_date']), 'billing.invoice_no' => (string)$b['txn_no'],
                        'billing.table' => ['html' => '<table class="ft"><thead><tr><th style="width:6%">No.</th><th>Description</th><th style="width:9%">Unit</th><th style="width:10%">Qty</th><th style="width:15%">Unit price</th><th style="width:16%">Amount</th></tr></thead><tbody>'
                            . $rows . '</tbody><tfoot><tr><td colspan="5" style="text-align:right"><b>Total amount billed</b></td><td style="text-align:right"><b>' . number_format((float)$b['gross_amount'], 2) . '</b></td></tr></tfoot></table>']];
                }
            }
            if ($rt === 'collection' && ($col = DB::row('SELECT * FROM brgy_collections WHERE id = :id', ['id' => $rid]))) {
                $parts = [['Amount settled', $col['gross_applied']], ['Income tax withheld', $col['ewt_amount']], ['VAT withheld', $col['vatwh_amount']], ['Retention', $col['retention_amount']],
                    ['Liquidated damages', $col['ld_amount']], [$col['other_note'] ?: 'Other deductions', $col['other_deduction']], ['Bank charges', $col['bank_charges']], ['Cash received', $col['cash_received']]];
                $rows = '';
                foreach ($parts as [$k, $v]) {
                    if ((float)$v != 0.0 || $k === 'Cash received') {
                        $rows .= '<tr><td>' . h($k) . '</td><td style="text-align:right">' . number_format((float)$v, 2) . '</td></tr>';
                    }
                }
                $c += ['collection.no' => $col['collection_no'], 'collection.date' => self::d($col['collection_date']), 'collection.amount' => '₱' . number_format((float)$col['cash_received'], 2),
                    'collection.amount_words' => self::words((float)$col['cash_received']), 'collection.reference' => trim(($col['check_no'] ? 'Check ' . $col['check_no'] : '') . ' ' . (string)$col['reference_no']),
                    'collection.table' => ['html' => '<table class="ft" style="width:60%"><tbody>' . $rows . '</tbody></table>']];
            }
        }
        return self::framing($c, $L, $e);
    }

    /** The letterhead and the signatures of the Format tab, filled from the other values */
    private static function framing(array $c, array $L, ?array $e): array
    {
        $pics = ['logo' => Logo::path() ? Logo::url() : '', 'seal' => '', 'lgu_seal' => ''];
        if ($e) {
            foreach (['seal' => 'seal', 'lgu_seal' => 'lgu'] as $k => $which) {
                $f = $which === 'lgu' ? ($e['lgu_seal_file'] ?? '') : ($e['seal_file'] ?? '');
                if ($f && is_file(self::dir() . '/' . $f)) {
                    $pics[$k] = 'index.php?' . http_build_query(['p' => 'brgy.entities', 'a' => 'seal', 'id' => $e['id'], 'k' => $which, 'v' => substr(md5($f), 0, 8)]);
                }
            }
        }
        // a field with no value leaves its place empty in a letterhead (a line of fields that are all empty is left out)
        $lh = fn(string $t) => self::render($t, $c);
        $c['letterhead'] = ['html' => BrgyLayout::letterheadHtml($L, $pics, $lh)];
        $c['signatories'] = ['html' => BrgyLayout::signatoriesHtml($L, fn(string $t) => self::render($t, $c))];
        return $c;
    }

    /** One line of the statements of contracts: "a. ...<br>b. ..." */
    private static function abc(array $parts, bool $bold = false): string
    {
        $out = [];
        foreach (array_values($parts) as $i => $v) {
            if ((string)$v !== '') {
                $out[] = '<span style="white-space:nowrap">' . chr(97 + $i) . '.</span> ' . ($bold ? '<b>' . h((string)$v) . '</b>' : h((string)$v));
            }
        }
        return implode('<br>', $out);
    }

    /** The other government contracts of the company: [owner name, address, telephone] */
    private static function owner(array $r): array
    {
        $short = trim((string)($r['barangay'] ?? '')) ?: (string)preg_replace('/^(barangay|brgy\.?)\s+/i', '', (string)$r['official_name']);
        return [$r['entity_type'] === 'barangay' ? 'Barangay ' . $short : (string)$r['official_name'], trim((string)($r['address'] ?: $r['city_municipality'] . ($r['province'] ? ', ' . $r['province'] : '')), ', '), (string)$r['phone']];
    }

    private static function statementHead(array $cols): string
    {
        return '<thead><tr>' . implode('', $cols) . '</tr></thead>';
    }

    /**
     * Statement of all ongoing government and private contracts, including contracts awarded but not yet started (Step 8): the other
     * awarded contracts of the company that are not closed. Private contracts are typed on the form ("NONE" printed when there are none).
     * Returns [html, total value of the outstanding works].
     */
    private static function ongoingTable(array $p): array
    {
        $rows = DB::all("SELECT p.*, e.official_name, e.barangay, e.entity_type, e.address, e.city_municipality, e.province, e.phone FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id
                          WHERE p.status = 'awarded' AND p.id <> :id ORDER BY COALESCE(p.noa_date, p.contract_signed_date, DATE(p.created_at)), p.id", ['id' => $p['id']]);
        $head = '<table class="fx fx-statement" style="width:100%"><colgroup><col style="width:17%"><col style="width:15%"><col style="width:10%"><col style="width:10%"><col style="width:5%">'
            . '<col style="width:14%"><col style="width:8%"><col style="width:8%"><col style="width:13%"></colgroup><thead><tr>'
            . '<th rowspan="2">Name of Contract<br>/ Location<br>Project Cost</th><th rowspan="2" style="text-align:left">a. Owner\'s Name<br>b. Address<br>c. Telephone Nos.</th>'
            . '<th rowspan="2">Nature of<br>Work</th><th colspan="2">Bidder\'s Role</th><th rowspan="2" style="text-align:left">a. Date Awarded<br>b. Date Started<br>c. Date of Completion</th>'
            . '<th colspan="2">% of<br>Accomplishment</th><th rowspan="2">Value of<br>Outstanding<br>Works</th></tr>'
            . '<tr><th>Description</th><th>%</th><th>Planned</th><th>Actual</th></tr></thead><tbody>';
        $blank = '<tr>' . str_repeat('<td>&nbsp;</td>', 9) . '</tr>';
        $html = $head . '<tr><td colspan="9" style="text-decoration:underline">Government</td></tr>';
        $total = 0.0;
        foreach ($rows as $r) {
            $m = Brgy::money($r);
            $contract = (float)$m['contract'];
            $outstanding = max(0.0, r2($contract - (float)$m['accepted']));
            $total += $outstanding;
            $start = $r['ntp_date'] ?: $r['start_date'];
            $planned = '';
            if ($start && (int)$r['delivery_days'] > 0) {
                $planned = (string)min(100, max(0, (int)round((time() - strtotime((string)$start)) / 86400 / (int)$r['delivery_days'] * 100))) . '%';
            }
            [$on, $oa, $ot] = self::owner($r);
            $html .= '<tr><td>' . h($r['title']) . ($contract > 0 ? '<br>' . number_format($contract, 2) : '') . '</td><td>' . self::abc([$on, $oa, $ot]) . '</td><td style="text-align:center">'
                . h(Brgy::label(Brgy::CATEGORIES, $r['category'])) . '</td><td style="text-align:center">Supplier</td><td style="text-align:center">100%</td><td>'
                . self::abc([self::d($r['noa_date']), self::d($start), self::d($r['completion_date'] ?: $r['due_date'])]) . '</td><td style="text-align:center">' . $planned . '</td><td style="text-align:center">'
                . ($contract > 0 ? (int)round((float)$m['accepted'] / $contract * 100) . '%' : '') . '</td><td style="text-align:right">' . number_format($outstanding, 2) . '</td></tr>';
        }
        if (!$rows) {
            $html .= $blank . '<tr><td colspan="9" style="font-weight:700">&ldquo;NONE&rdquo;</td></tr>';
        }
        $html .= $blank . '<tr><td colspan="9" style="text-decoration:underline">Private</td></tr>' . $blank . '<tr><td colspan="9" style="font-weight:700">&ldquo;NONE&rdquo;</td></tr>' . $blank
            . '</tbody><tfoot><tr><td colspan="6" style="border:0"></td><td colspan="2">Total Cost</td><td style="text-align:right">' . ($total > 0 ? number_format(r2($total), 2) : '') . '</td></tr></tfoot></table>';
        return [$html, r2($total)];
    }

    /**
     * Statement of the single largest completed contract similar to the contract to be bid (SLCC): the company's largest closed contract of the
     * same category (any category when there is none).
     */
    private static function slccTable(array $p): string
    {
        $q = "SELECT p.*, e.official_name, e.barangay, e.entity_type, e.address, e.city_municipality, e.province, e.phone FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id
               WHERE p.status = 'closed' AND p.id <> :id%s ORDER BY (p.contract_amount + p.co_amount) DESC, p.id DESC LIMIT 1";
        $r = DB::row(sprintf($q, ' AND p.category = :cat'), ['id' => $p['id'], 'cat' => $p['category']]) ?: DB::row(sprintf($q, ''), ['id' => $p['id']]);
        $html = '<table class="fx fx-statement" style="width:100%"><colgroup><col style="width:16%"><col style="width:18%"><col style="width:13%"><col style="width:12%"><col style="width:6%">'
            . '<col style="width:18%"><col style="width:17%"></colgroup><thead><tr>'
            . '<th rowspan="2" style="text-align:left">Name of Contract</th><th rowspan="2" style="text-align:left">a. Owner\'s Name<br>b. Address<br>c. Telephone Nos.</th><th rowspan="2">Nature of Work</th>'
            . '<th colspan="2">Bidder\'s Role</th><th rowspan="2" style="text-align:left">a. Amount at Award<br>b. Amount at Completion<br>c. Duration</th>'
            . '<th rowspan="2" style="text-align:left">a. Date Awarded<br>b. Contract Effectivity<br>c. Date Completed</th></tr><tr><th>Description</th><th>%</th></tr></thead><tbody>';
        $blank = '<tr>' . str_repeat('<td>&nbsp;</td>', 7) . '</tr>';
        $html .= '<tr><td colspan="7" style="text-decoration:underline">Government</td></tr>';
        if ($r) {
            [$on, $oa, $ot] = self::owner($r);
            $m = Brgy::money($r);
            $days = (int)$r['delivery_days'];
            $html .= '<tr><td style="text-align:center">' . h($on) . '</td><td style="text-align:center">' . h($oa) . ($ot !== '' ? '<br>' . h($ot) : '') . '</td><td style="text-align:center">'
                . h($r['title']) . '</td><td style="text-align:center">Supplier</td><td style="text-align:center">100%</td><td>'
                . self::abc([(float)$r['contract_amount'] > 0 ? number_format((float)$r['contract_amount'], 2) : '', (float)$m['contract'] > 0 ? number_format((float)$m['contract'], 2) : '', $days > 0 ? $days . ' Days' : ''], true)
                . '</td><td>' . self::abc([self::d($r['noa_date']), self::d($r['ntp_date'] ?: $r['contract_signed_date'] ?: $r['start_date']), self::d($r['completion_date'] ?: $r['closeout_date'])], true) . '</td></tr>';
        } else {
            $html .= $blank . '<tr><td colspan="7" style="font-weight:700">&ldquo;NONE&rdquo;</td></tr>';
        }
        return $html . $blank . '<tr><td colspan="7" style="text-decoration:underline">Private</td></tr>' . $blank . '<tr><td colspan="7" style="font-weight:700">&ldquo;NONE&rdquo;</td></tr>' . $blank . '</tbody></table>';
    }

    private static function money2(float $v, string $prefix = '₱'): string
    {
        return $v > 0 ? $prefix . number_format($v, 2) : '';
    }

    /** Amount in words, Title Case, without "only" (the forms add their own ending) */
    public static function wordsTitle(float $amount): string
    {
        if ($amount <= 0) {
            return '';
        }
        $w = preg_replace('/\s+only$/i', '', amount_in_words($amount));
        return ucwords($w, " -");
    }

    /** "CITY OF LAPU-LAPU" from "Lapu-Lapu City"; "MUNICIPALITY OF X" otherwise */
    private static function lguLine(string $city): string
    {
        $city = trim($city);
        if ($city === '') {
            return '';
        }
        if (preg_match('/^city of\s+(.+)$/i', $city, $m) || preg_match('/^(.+?)\s+city$/i', $city, $m)) {
            return 'CITY OF ' . mb_strtoupper($m[1]);
        }
        if (preg_match('/^municipality of\s+(.+)$/i', $city, $m)) {
            return 'MUNICIPALITY OF ' . mb_strtoupper($m[1]);
        }
        return 'MUNICIPALITY OF ' . mb_strtoupper($city);
    }

    /** A box, ticked or not (transaction form, purchase order) */
    public static function box(bool $on, string $label): string
    {
        return '<span class="fx-opt"><span class="fx-chk' . ($on ? ' is-on' : '') . '"></span> ' . h($label) . '</span>';
    }

    /** The picture of a seal of an entity, for the letterheads */
    public static function sealImg(array $e, string $which): string
    {
        $f = $which === 'lgu' ? $e['lgu_seal_file'] ?? '' : $e['seal_file'] ?? '';
        if (!$f || !is_file(self::dir() . '/' . $f)) {
            return '';
        }
        // relative to index.php, so a form prepared once still finds the picture wherever the program is installed
        return '<img src="' . h('index.php?' . http_build_query(['p' => 'brgy.entities', 'a' => 'seal', 'id' => $e['id'], 'k' => $which, 'v' => substr(md5($f), 0, 8)])) . '" alt="" style="width:26mm;height:26mm;object-fit:contain">';
    }

    /** The placeholders of the SOP: the transaction form and the papers the company prepares for the barangay */
    private static function sopContext(array $p, array $e, array $lines, array $L): array
    {
        $up = fn($v) => mb_strtoupper((string)$v);
        $off = fn(string $r) => Brgy::official((int)$p['entity_id'], $r);
        $short = trim((string)($e['barangay'] ?? '')) ?: (string)preg_replace('/^(barangay|brgy\.?)\s+/i', '', (string)$e['official_name']);
        $head = $off('punong_barangay');
        $approp = $off('appropriations_chair');
        $treas = $off('treasurer');
        $bacChair = $off('bac_chair');
        $t = Brgy::lineTotals((int)$p['id']);
        $gross = Brgy::contractTotal($p) ?: $t['bid'];
        $w = BrgySop::withholding($p, $gross);
        $col = DB::row("SELECT COALESCE(SUM(ewt_amount + vatwh_amount), 0) AS wht, MAX(receipt_no) AS receipt_no FROM brgy_collections WHERE project_id = :p AND kind = 'billing' AND status = 'posted'", ['p' => $p['id']]);
        $whtActual = (float)($col['wht'] ?? 0);
        $types = BrgySop::txnTypes();
        $c = [];
        // ---- transaction form
        $boxes = '';
        foreach ($types as $code => $label) {
            // the box of the paper form: CASH OUT for "Cash Out (purchases)"
            $boxes .= '<div>' . self::box($p['txn_type'] === $code, mb_strtoupper(trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $label)))) . '</div>';
            $c['txn.mark_' . $code] = $p['txn_type'] === $code ? 'X' : '';
        }
        $profit = Access::viewCosts() && $t['cost'] > 0 ? r2($gross - $t['cost']) : 0.0;
        $c += [
            'txn.series' => (string)$p['txn_form_no'], 'txn.date' => self::d($p['txn_date']), 'txn.tradename' => (string)($p['tradename'] ?: Brgy::setting('brgy_tradename') ?: Settings::get('company_name')),
            'txn.types' => ['html' => '<div class="fx-types">' . $boxes . '</div>', 'text' => $types[$p['txn_type']] ?? ''], 'txn.type' => $types[$p['txn_type']] ?? '',
            'txn.barangay' => (string)$e['official_name'], 'txn.cheque_no' => (string)$p['cheque_no'], 'txn.gross' => self::money2($gross),
            'txn.wht' => self::money2($whtActual > 0 ? $whtActual : $w['total']), 'txn.cheque_amount' => self::money2((float)$p['cheque_amount']), 'txn.profit' => self::money2($profit),
            'txn.requestor' => trim($p['requestor'] . ($p['requestor_position'] ? ', ' . $p['requestor_position'] : ''), ', '),
            'txn.inspector' => trim($p['inspector'] . ($p['inspector_position'] ? ', ' . $p['inspector_position'] : ''), ', '),
            'txn.purpose' => (string)$p['purpose'], 'txn.prepared_by' => Brgy::userName((int)$p['sales_rep_id']),
        ];
        // ---- the entity and its officials
        $bac = [];
        if ($bacChair) {
            $bac[] = [$bacChair['name'], 'BAC Chairman'];
        }
        foreach (DB::all("SELECT name FROM brgy_contacts WHERE entity_id = :e AND role_code = 'bac_member' AND (valid_to IS NULL OR valid_to >= :d) ORDER BY id", ['e' => $p['entity_id'], 'd' => today()]) as $m) {
            $bac[] = [$m['name'], 'BAC Member'];
        }
        $bacHtml = '';
        foreach (array_chunk($bac, 2) as $pair) {
            $bacHtml .= '<tr>';
            foreach ($pair as $i => [$name, $role]) {
                $bacHtml .= '<td style="width:50%;padding:0 6px 26px;text-align:center"' . (count($pair) === 1 ? ' colspan="2"' : '') . '><div class="fx-sig"><div class="sg-name">' . h($up($name)) . '</div><div class="sg-cap">' . h($role) . '</div></div></td>';
            }
            $bacHtml .= '</tr>';
        }
        $province = trim((string)$e['province']);
        $lguLines = '<div>REPUBLIC OF THE PHILIPPINES</div>' . ($province !== '' ? '<div>PROVINCE OF ' . h($up($province)) . '</div>' : '')
            . (($l = self::lguLine((string)$e['city_municipality'])) !== '' ? '<div>' . h($l) . '</div>' : '') . '<div>' . h($up($e['entity_type'] === 'barangay' ? 'Barangay ' . $short : $e['official_name'])) . '</div>';
        $seal = self::sealImg($e, 'seal');
        $lguSeal = self::sealImg($e, 'lgu');
        $c += [
            'entity.short' => $short, 'entity.short_upper' => $up($short), 'entity.name_upper' => $up($e['official_name']), 'entity.phone' => (string)$e['phone'],
            'entity.location' => trim($short . ($e['city_municipality'] ? ', ' . $e['city_municipality'] : ''), ', '), 'entity.head_name_upper' => $up($head['name'] ?? ''),
            'entity.appropriations_chair' => $approp['name'] ?? '', 'entity.appropriations_chair_upper' => $up($approp['name'] ?? ''), 'entity.treasurer_upper' => $up($treas['name'] ?? ''),
            'entity.bac_chair_upper' => $up($bacChair['name'] ?? ''), 'entity.bookkeeper' => $off('bookkeeper')['name'] ?? '', 'entity.city_accountant' => $off('city_accountant')['name'] ?? '',
            'entity.requirement_notes' => (string)$e['requirement_notes'],
            'entity.bac_signatures' => ['html' => $bacHtml !== '' ? '<table class="fx-plain" style="width:100%"><tbody>' . $bacHtml . '</tbody></table>' : ''],
            'entity.seal' => ['html' => $seal], 'entity.lgu_seal' => ['html' => $lguSeal],
            'lgu.header' => ['html' => '<table class="fx-plain fx-lgu" style="width:100%"><tbody><tr><td style="width:24%;text-align:center" data-section="seals">' . $seal . '</td>'
                . '<td style="text-align:center;font-weight:bold;line-height:1.3">' . $lguLines . '</td><td style="width:24%;text-align:center" data-section="seals">' . $lguSeal . '</td></tr></tbody></table>'],
        ];
        // ---- project details of the SOP
        $c += [
            'project.txn_form_no' => (string)$p['txn_form_no'], 'project.purpose' => (string)$p['purpose'], 'project.requestor' => (string)$p['requestor'],
            'project.inspector' => (string)$p['inspector'], 'project.inspector_upper' => $up($p['inspector']), 'project.fund' => (string)$p['fund'],
            'project.abc_plain' => (float)$p['abc_amount'] > 0 ? number_format((float)$p['abc_amount'], 2) : '', 'project.prebid' => $p['prebid_at'] ? date('j F Y, g:i A', strtotime((string)$p['prebid_at'])) : '',
            'project.sales_rep' => Brgy::userName((int)$p['sales_rep_id']), 'project.submitter' => Brgy::userName((int)$p['submitter_id']), 'project.title_upper' => $up($p['title']),
            'project.completion_date' => self::d($p['completion_date']),
        ];
        // ---- purchase request, purchase order, IAR, RIS, request for quotation
        $mode = (string)DB::value('SELECT code FROM brgy_proc_modes WHERE id = :m', ['m' => (int)$p['mode_id']]);
        $kind = str_contains($mode, 'bidding') ? 'bid' : (preg_match('/negotiated|small_value|svp/', $mode) ? 'neg' : ($mode !== '' ? 'otc' : ''));
        $billing = DB::row("SELECT si_no, billing_date FROM brgy_billings WHERE project_id = :p AND status IN ('invoiced', 'submitted', 'acknowledged') ORDER BY id LIMIT 1", ['p' => $p['id']]);
        $c += [
            'pr.no' => (string)$p['reference_no'], 'pr.date' => self::d($p['pr_date']), 'pr.items' => ['html' => BrgyLayout::itemsTable('pr.items', $lines, $L)],
            'po.no' => (string)$p['po_no'], 'po.date' => self::d($p['po_date']),
            'po.modes' => ['html' => self::box($kind === 'bid', 'Bidding') . ' &nbsp; ' . self::box($kind === 'neg', 'Negotiated') . ' &nbsp; ' . self::box($kind === 'otc', 'over the counter'),
                'text' => ['bid' => 'Bidding', 'neg' => 'Negotiated', 'otc' => 'over the counter'][$kind] ?? ''],
            'po.place' => (string)($p['delivery_site'] ?: $e['official_name'] . ($e['city_municipality'] ? ', ' . $e['city_municipality'] : '')), 'po.delivery_date' => self::d($p['delivery_date']),
            'po.delivery_term' => (string)$p['delivery_term'], 'po.payment_term' => (string)$p['payment_terms'], 'po.items' => ['html' => BrgyLayout::itemsTable('po.items', $lines, $L)],
            'po.total_words' => self::wordsTitle($t['contract'] ?: $t['bid']),
            'iar.no' => (string)$p['iar_no'], 'iar.date' => self::d($p['iar_date']), 'iar.invoice_no' => (string)($billing['si_no'] ?? ''), 'iar.invoice_date' => self::d($billing['billing_date'] ?? null),
            'iar.items' => ['html' => BrgyLayout::itemsTable('iar.items', $lines, $L)],
            'ris.no' => (string)$p['ris_no'], 'ris.date' => self::d($p['ris_date']), 'ris.items' => ['html' => BrgyLayout::itemsTable('ris.items', $lines, $L)],
            'rfq.date' => self::d($p['pr_date'] ?: $p['posting_date']), 'rfq.items' => ['html' => BrgyLayout::itemsTable('rfq.items', $lines, $L)],
            'rfq.delivery_period' => $p['delivery_days'] ? $p['delivery_days'] . ' DAYS' : '',
        ];
        // ---- abstract of canvass
        $cv = BrgySop::ready() ? BrgySop::canvass((int)$p['id']) : [];
        $cols = $cv ?: [['supplier_name' => '', 'total_amount' => 0, 'validity' => '', 'brand' => '', 'remarks' => ''], ['supplier_name' => '', 'total_amount' => 0, 'validity' => '', 'brand' => '', 'remarks' => ''],
            ['supplier_name' => '', 'total_amount' => 0, 'validity' => '', 'brand' => '', 'remarks' => '']];
        $row = fn(string $label, callable $f, string $style = '') => '<tr><td' . $style . '>' . $label . '</td>' . implode('', array_map(fn($x) => $f($x), $cols)) . '</tr>';
        $abs = '<table class="fx" style="width:100%"><thead><tr><th style="width:22%">NAME OF BIDDERS</th>' . implode('', array_map(fn($x) => '<th>' . h($up($x['supplier_name'])) . '</th>', $cols)) . '</tr></thead><tbody>'
            . $row('Total Amount of Bid', fn($x) => '<td style="text-align:right;font-weight:bold">' . ((float)$x['total_amount'] > 0 ? number_format((float)$x['total_amount'], 2) : '') . '</td>')
            . $row('Validity Period', fn($x) => '<td style="text-align:center">' . h((string)$x['validity']) . '</td>')
            . $row('Brand name (if applicable)', fn($x) => '<td style="text-align:center">' . h((string)$x['brand']) . '</td>')
            . $row('<b style="letter-spacing:.25em">REMARKS</b>', fn($x) => '<td style="text-align:center;font-weight:bold">' . h(ucfirst((string)$x['remarks'])) . '</td>', ' style="text-align:center"')
            . '</tbody></table>';
        $c += ['abstract.date' => $cv ? self::d(max(array_column($cv, 'received_date'))) : '', 'abstract.table' => ['html' => $abs]];
        // ---- disbursement voucher
        $pctLabel = fn(float $r) => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
        $taxes = '<table class="fx-plain fx-taxes" style="width:100%"><tbody>'
            . ($w['vat'] > 0 ? '<tr><td style="width:45%;text-align:right;padding-right:16px">VAT ' . $pctLabel($w['vat_rate']) . '</td><td style="text-align:right">' . number_format($w['vat'], 2) . '</td></tr>' : '')
            . ($w['ewt'] > 0 ? '<tr><td style="text-align:right;padding-right:16px">EWT ' . $pctLabel($w['ewt_rate']) . '</td><td style="text-align:right">' . number_format($w['ewt'], 2) . '</td></tr>' : '')
            . '<tr><td></td><td style="text-align:right;border-top:3px double #000">' . number_format($w['total'], 2) . '</td></tr></tbody></table>';
        $papers = '';
        foreach ([['si', 'SALES INVOICE/COLLECTION RECEIPT'], ['pr', 'PR'], ['po', 'PO'], ['iar', 'IAR'], ['ris', 'RIS'], ['abstract', 'Abstract of Canvass'], ['canvass', 'Canvass Form']] as $i => [$code, $label]) {
            $papers .= '<div style="padding-left:6px">' . ($i + 1) . ' ' . h($label) . '</div>';
        }
        $c += [
            'dv.no' => (string)$p['dv_no'], 'dv.date' => self::d($p['dv_date']), 'dv.fund' => (string)$p['fund'],
            'dv.particulars' => (string)preg_replace('/^payment for (the )?/i', '', (string)$p['title']), 'dv.gross' => self::money2($gross, 'Php'),
            'dv.taxes' => ['html' => $taxes], 'dv.net' => self::money2($w['net'], 'PhP'), 'dv.net_words_upper' => $w['net'] > 0 ? $up(self::wordsTitle($w['net'])) . '.' : '',
            'dv.papers' => ['html' => $papers], 'dv.check_no' => (string)$p['cheque_no'], 'dv.bank' => (string)$p['cheque_bank'], 'dv.or_no' => (string)($col['receipt_no'] ?? ''),
        ];
        // ---- transmittal
        $rows = '';
        $i = 0;
        if (BrgySop::ready()) {
            foreach (DB::all("SELECT name, copies, document_id, form_id FROM brgy_project_papers WHERE project_id = :p AND status = 'open' AND paper_code NOT IN ('transmittal', 'txn_form', 'bidding_form')
                               AND (document_id IS NOT NULL OR form_id IS NOT NULL) ORDER BY sort_order, id", ['p' => $p['id']]) as $pp) {
                $rows .= '<tr><td style="text-align:center">' . (++$i) . '</td><td>' . h($pp['name']) . '</td><td style="text-align:center">' . (int)$pp['copies'] . '</td><td></td></tr>';
            }
        }
        $c += [
            'transmittal.no' => (string)$p['transmittal_no'], 'transmittal.date' => self::d($p['released_at'] ? substr((string)$p['released_at'], 0, 10) : today()),
            'transmittal.released_by' => Brgy::userName((int)$p['sales_rep_id']) ?: Brgy::userName((int)Auth::id()),
            'sop.papers_table' => ['html' => '<table class="fx" style="width:100%"><thead><tr><th style="width:8%">No.</th><th>Document</th><th style="width:12%">Copies</th><th style="width:26%">Remarks</th></tr></thead><tbody>'
                . ($rows ?: '<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>') . '</tbody></table>'],
        ];
        return $c;
    }

    private static function linesTable(array $lines, string $which): string
    {
        $rows = '';
        $i = 0;
        $tot = 0.0;
        foreach ($lines as $l) {
            $q = $which === 'bid' ? (float)$l['bid_qty'] : (float)$l['qty'];
            if ($q == 0.0 && $which === 'bid' && $l['co_id']) {
                continue;
            }
            $amt = r2($q * (float)$l['unit_price']);
            $tot += $amt;
            $rows .= '<tr><td style="text-align:center">' . (++$i) . '</td><td>' . h($l['description']) . ($l['specs'] ? '<br><small>' . nl2br(h($l['specs'])) . '</small>' : '') . '</td><td style="text-align:center">' . h((string)$l['unit'])
                . '</td><td style="text-align:right">' . qty($q) . '</td><td style="text-align:right">' . number_format((float)$l['unit_price'], 2) . '</td><td style="text-align:right">' . number_format($amt, 2) . '</td></tr>';
        }
        $vat = Brgy::on('brgy_prices_vat_inclusive') ? 'VAT inclusive' : 'VAT exclusive';
        return '<table class="ft"><thead><tr><th style="width:6%">Item no.</th><th>Description / specifications</th><th style="width:9%">Unit</th><th style="width:10%">Qty</th><th style="width:15%">Unit price</th><th style="width:16%">Total price</th></tr></thead><tbody>'
            . $rows . '</tbody><tfoot><tr><td colspan="5" style="text-align:right"><b>TOTAL (' . $vat . ')</b></td><td style="text-align:right"><b>' . number_format(r2($tot), 2) . '</b></td></tr></tfoot></table>';
    }

    private static function costingTable(array $lines): string
    {
        $rows = '';
        $tc = 0.0;
        $tp = 0.0;
        foreach ($lines as $l) {
            $cost = r2((float)$l['bid_qty'] * (float)$l['unit_cost']);
            $price = r2((float)$l['bid_qty'] * (float)$l['unit_price']);
            $tc += $cost;
            $tp += $price;
            $rows .= '<tr><td>' . h($l['description']) . '</td><td style="text-align:right">' . qty($l['bid_qty']) . '</td><td style="text-align:right">' . number_format((float)$l['unit_cost'], 2)
                . '</td><td style="text-align:right">' . number_format($cost, 2) . '</td><td style="text-align:right">' . number_format($price, 2) . '</td><td style="text-align:right">'
                . ($price > 0 ? number_format(($price - $cost) / $price * 100, 1) . '%' : '') . '</td></tr>';
        }
        return '<table class="ft"><thead><tr><th>Line</th><th>Qty</th><th>Unit cost</th><th>Cost</th><th>Price</th><th>Margin</th></tr></thead><tbody>' . $rows
            . '</tbody><tfoot><tr><td colspan="3"><b>Total</b></td><td style="text-align:right"><b>' . number_format($tc, 2) . '</b></td><td style="text-align:right"><b>' . number_format($tp, 2)
            . '</b></td><td style="text-align:right"><b>' . ($tp > 0 ? number_format(($tp - $tc) / $tp * 100, 1) . '%' : '') . '</b></td></tr></tfoot></table>';
    }

    private static function requirementsTable(int $projectId, ?array $stages = null): string
    {
        $rows = '';
        $i = 0;
        foreach (Brgy::checklist($projectId) as $r) {
            if ($stages && !in_array($r['stage'], $stages, true)) {
                continue;
            }
            $rows .= '<tr><td style="text-align:center">' . (++$i) . '</td><td>' . h($r['name']) . '</td><td>' . h(Brgy::label(Brgy::REQ_TYPES, $r['requirement_type'])) . '</td><td>'
                . h($r['doc_title'] ?: (string)($r['by_record'] ?? '')) . ($r['valid_until'] ? '<br><small>valid until ' . h(fdate($r['valid_until'])) . '</small>' : '') . '</td><td>'
                . h(['ok' => 'In order', 'waived' => 'Not required (waived)', 'pending' => 'For review', 'returned' => 'Returned', 'missing' => 'Missing', 'expired' => 'Expired'][$r['state']]) . '</td></tr>';
        }
        return '<table class="ft"><thead><tr><th style="width:6%">No.</th><th>Requirement</th><th style="width:13%">Kind</th><th style="width:28%">Document</th><th style="width:14%">Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private static function soaTable(array $p): string
    {
        $rows = '';
        $bal = 0.0;
        $ev = [];
        foreach (DB::all("SELECT b.*, t.txn_no FROM brgy_billings b LEFT JOIN transactions t ON t.id = b.invoice_txn_id WHERE b.project_id = :p AND b.status IN ('invoiced', 'submitted', 'acknowledged') ORDER BY b.billing_date, b.id", ['p' => $p['id']]) as $b) {
            $ev[] = [$b['billing_date'], 'Billing ' . $b['billing_no'] . ($b['si_no'] ? ', SI ' . $b['si_no'] : ''), (float)$b['gross_amount'], 0.0];
        }
        foreach (DB::all("SELECT * FROM brgy_collections WHERE project_id = :p AND kind = 'billing' AND status IN ('posted', 'bounced') ORDER BY collection_date, id", ['p' => $p['id']]) as $c) {
            $ev[] = [$c['collection_date'], 'Payment ' . $c['collection_no'] . ($c['reference_no'] ? ' (' . $c['reference_no'] . ')' : '') . ', taxes withheld and deductions', 0.0, (float)$c['gross_applied']];
            if ($c['status'] === 'bounced' && $c['bounce_txn_id']) {
                $ev[] = [$c['bounce_date'], 'Returned check ' . ($c['check_no'] ?: $c['reference_no']), (float)$c['cash_received'], 0.0];
            }
        }
        usort($ev, fn($a, $b) => strcmp($a[0], $b[0]));
        foreach ($ev as [$d, $what, $dr, $cr]) {
            $bal = r2($bal + $dr - $cr);
            $rows .= '<tr><td>' . h(fdate($d)) . '</td><td>' . h($what) . '</td><td style="text-align:right">' . ($dr ? number_format($dr, 2) : '') . '</td><td style="text-align:right">'
                . ($cr ? number_format($cr, 2) : '') . '</td><td style="text-align:right">' . number_format($bal, 2) . '</td></tr>';
        }
        return '<table class="ft"><thead><tr><th style="width:14%">Date</th><th>Particulars</th><th style="width:16%">Billed</th><th style="width:16%">Paid / settled</th><th style="width:16%">Balance</th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="5">No billing yet.</td></tr>') . '</tbody><tfoot><tr><td colspan="4" style="text-align:right"><b>BALANCE DUE</b></td><td style="text-align:right"><b>' . number_format($bal, 2) . '</b></td></tr></tfoot></table>';
    }

    private static function closeoutTable(array $p): string
    {
        $m = Brgy::money($p);
        $rows = [
            ['Contract amount (original)', (float)$p['contract_amount']], ['Approved change orders', (float)$p['co_amount']], ['Contract amount (final)', $m['contract']],
            ['Accepted by the entity', $m['accepted']], ['Billed', $m['billed']], ['Settled (cash, taxes and deductions)', $m['settled']], ['Cash collected', $m['cash']],
            ['Taxes withheld by the entity', $m['taxes']], ['Retention deducted', $m['retention_deducted']], ['Retention released', $m['retention_released']],
            ['Open receivable (ledger)', $m['ar_open']], ['Retention still held (ledger)', $m['retention_held']], ['Revenue (ledger)', $m['revenue']], ['Costs (ledger)', $m['costs']], ['Margin', $m['margin']],
        ];
        $html = '';
        foreach ($rows as [$k, $v]) {
            $html .= '<tr><td>' . h($k) . '</td><td style="text-align:right">' . number_format((float)$v, 2) . '</td></tr>';
        }
        return '<table class="ft" style="width:70%"><tbody>' . $html . '</tbody></table>';
    }

    /**
     * Fills the placeholders. Known fields with no value are listed in $missing and shown as a marked gap, so a draft
     * shows what is still to be filled; unknown placeholders are left as typed.
     */
    public static function render(string $body, array $ctx, ?array &$missing = null): string
    {
        $missing = [];
        $known = [];
        foreach (self::FIELDS as $g) {
            $known += $g;
        }
        $html = (string)preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($ctx, $known, &$missing) {
            $k = strtolower($m[1]);
            if (!array_key_exists($k, $ctx)) {
                if (isset($known[$k])) {
                    $missing[$k] = $known[$k];
                    return '<span class="ph-missing" data-ph="' . h($k) . '">[' . h($known[$k]) . ']</span>';
                }
                return '<span class="ph-unknown">{{' . h($k) . '}}</span>';
            }
            $v = $ctx[$k];
            if (is_array($v)) {
                return (string)$v['html'];
            }
            if (trim((string)$v) === '') {
                $missing[$k] = $known[$k] ?? $k;
                return '<span class="ph-missing" data-ph="' . h($k) . '">[' . h($known[$k] ?? $k) . ']</span>';
            }
            return nl2br(h((string)$v));
        }, $body);
        // "HON. {{entity.head_name_upper}}" with an official recorded as "Hon. ...": the title once
        return (string)preg_replace('/\b(hon\.)(\s|&nbsp;)+hon\.\s+/i', '$1 ', $html);
    }

    /** Gaps left in a form's text (fields that had no value when it was made) */
    public static function gaps(string $html): array
    {
        preg_match_all('/class="ph-missing"[^>]*>\[([^\]]*)\]/', $html, $m);
        return array_values(array_unique(array_map('html_entity_decode', $m[1])));
    }

    // ================================================================== cleaning HTML typed by users

    private const TAGS = ['p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'small', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col', 'caption', 'blockquote', 'pre', 'code', 'a', 'img', 'font', 'center'];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'video', 'audio', 'source', 'frame', 'frameset', 'applet', 'noscript', 'template'];
    private const ATTRS = ['style', 'class', 'title', 'align', 'colspan', 'rowspan', 'width', 'height', 'valign', 'border', 'cellpadding', 'cellspacing', 'href', 'src', 'alt', 'span', 'start', 'type', 'data-ph', 'data-section', 'data-table'];
    private const STYLE_PROPS = ['text-align', 'font-weight', 'font-style', 'text-decoration', 'text-transform', 'font-size', 'font-family', 'color', 'background-color', 'background',
        'width', 'min-width', 'max-width', 'height', 'min-height', 'border', 'border-top', 'border-bottom', 'border-left', 'border-right', 'border-collapse', 'border-color', 'border-style',
        'border-width', 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'vertical-align', 'line-height', 'letter-spacing', 'white-space', 'page-break-before', 'page-break-after', 'page-break-inside', 'break-before', 'break-after', 'break-inside',
        'text-indent', 'list-style-type', 'display', 'float', 'clear', 'table-layout', 'object-fit', 'max-height', 'border-radius', 'border-spacing'];

    public static function cleanStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (!in_array($prop, self::STYLE_PROPS, true) || $val === '') {
                continue;
            }
            if (preg_match('/url\s*\(|expression|javascript:|behavior|@import|[<>\\\\]|-moz-binding/i', $val)) {
                continue;
            }
            $out[] = $prop . ':' . $val;
        }
        return implode(';', $out);
    }

    /** Keeps formatting tags, tables and inline styles; drops scripts, frames, forms, event handlers and unsafe links */
    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        if (!class_exists('DOMDocument')) {
            $allowed = '<' . implode('><', self::TAGS) . '>';
            $html = strip_tags($html, $allowed);
            $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
            return (string)preg_replace('/(href|src)\s*=\s*("|\')\s*(javascript|vbscript|data:text)[^"\']*\2/i', '$1="#"', (string)$html);
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if (!$root) {
            return h(strip_tags($html));
        }
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $n) {
            $out .= $doc->saveHTML($n);
        }
        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) {
                $node->removeChild($c);
                continue;
            }
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->nodeName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($c);
                continue;
            }
            self::walk($c);
            if (!in_array($tag, self::TAGS, true)) {
                // an unknown wrapper: keep what is inside it
                while ($c->firstChild) {
                    $node->insertBefore($c->firstChild, $c);
                }
                $node->removeChild($c);
                continue;
            }
            $remove = [];
            foreach ($c->attributes as $a) {
                $name = strtolower($a->nodeName);
                $val = (string)$a->nodeValue;
                if (!in_array($name, self::ATTRS, true)) {
                    $remove[] = $a->nodeName;
                    continue;
                }
                if ($name === 'style') {
                    $clean = self::cleanStyle($val);
                    if ($clean === '') {
                        $remove[] = $a->nodeName;
                    } else {
                        $c->setAttribute('style', $clean);
                    }
                } elseif ($name === 'data-section' || $name === 'data-table') {
                    $c->setAttribute($a->nodeName, (string)preg_replace('/[^a-z0-9_.-]/i', '', $val));
                } elseif ($name === 'class') {
                    $c->setAttribute('class', trim((string)preg_replace('/[^a-z0-9 _-]/i', '', $val)));
                } elseif ($name === 'href') {
                    if (!preg_match('#^(https?://|mailto:|\#)#i', trim($val))) {
                        $remove[] = $a->nodeName;
                    }
                } elseif ($name === 'src') {
                    $v = trim($val);
                    $logo = str_contains($v, 'p=file.logo') || (str_contains($v, 'p=brgy.entities') && str_contains($v, 'a=seal')) || str_contains($v, 'p=file.lhpic');
                    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v) && !$logo) {
                        $remove[] = $a->nodeName;
                    }
                }
            }
            foreach ($remove as $r) {
                $c->removeAttribute($r);
            }
        }
    }

    // ================================================================== Word

    /** A form as a document Word opens and edits (HTML in Word's own dialect, saved as .doc) */
    public static function wordDoc(string $title, string $body, string $paper = 'A4', string $orientation = 'portrait', ?array $L = null): string
    {
        $L ??= BrgyLayout::layout(['paper' => $paper, 'orientation' => $orientation]);
        [$wmm, $hmm] = BrgyLayout::size($L);
        $w = $wmm . 'mm';
        $hgt = $hmm . 'mm';
        $orientation = $L['orientation'];
        $font = BrgyLayout::FONTS[$L['font']] ?? 'Arial, sans-serif';
        $b = (float)$L['border'] . 'px solid #000';
        $body = (string)preg_replace('/<span class="ph-missing"[^>]*>(.*?)<\/span>/', '<span style="background:#fff3bf">$1</span>', BrgyLayout::stripHidden($body, $L)) . BrgyLayout::footerHtml($L);
        return '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">'
            . '<head><meta charset="utf-8"><title>' . h($title) . '</title>'
            . '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->'
            . '<style>@page Section1 { size: ' . $w . ' ' . $hgt . '; margin: ' . $L['margin_top'] . 'mm ' . $L['margin_right'] . 'mm ' . $L['margin_bottom'] . 'mm ' . $L['margin_left'] . 'mm; ' . ($orientation === 'landscape' ? 'mso-page-orientation: landscape;' : '') . ' }'
            . ' div.Section1 { page: Section1; } body { font-family: ' . $font . '; font-size: ' . $L['font_size'] . 'pt; line-height: ' . $L['line_height'] . '; }'
            . ' table { border-collapse: collapse; } table.ft { width: 100%; } table.ft td, table.ft th { border: 1px solid #000; padding: 3pt 5pt; vertical-align: top; } table.ft th { background: #eeeeee; }'
            . ' table.fx { border-collapse: collapse; } table.fx td, table.fx th { border: ' . $b . '; padding: ' . $L['cell_pad'] . 'px 4px; vertical-align: top; font-size: ' . $L['table_size'] . 'pt; }'
            . ' table.fx-plain td, table.fx-lines td { border: 0; padding: 1px 3px; vertical-align: top; } table.fx-lines td.val { border-bottom: 1px solid #000; } table.fx-lines td.lbl { text-align: right; font-weight: bold; width: 42%; }'
            . ' .fx-title { font-size: ' . $L['title_size'] . 'pt; font-weight: bold; } .sg-name { font-weight: bold; text-decoration: underline; } .fx-chk { border: 1px solid #000; padding: 0 6pt; } .fx-chk.is-on:before { content: "X"; }'
            . ' .sig-table td { border: 0; padding-top: 24pt; } .ph-unknown { color: #a00; }' . '</style></head>'
            . '<body><div class="Section1">' . $body . '</div></body></html>';
    }
}
