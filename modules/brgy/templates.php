<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Proforma & Form Templates
 * The company's reusable proformas (quotations, proposals, contracts, letters, receipts, reports): edited here, with
 * fields such as {{entity.name}} or {{lines.table}} filled from each project when a form is prepared. Every save keeps
 * the version before; an older version can be looked at or put back. Prescribed forms (GPPB, entity) are marked.
 * Each template has its format (paper, margins, font, columns of the item tables, parts shown, copies, offsets) and,
 * for forms printed on the barangay's pre-printed pads, the position of each value in millimetres (app/BrgyLayout.php).
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.templates';
$a = g('a', 'list');
$id = gint('id');

const BRGY_TPL_STAGES = ['bid' => 'Bid', 'award' => 'Award', 'contract' => 'Contract', 'delivery' => 'Delivery', 'billing' => 'Billing', 'collection' => 'Collection', 'closeout' => 'Closeout', 'internal' => 'Internal'];
const BRGY_TPL_CATEGORIES = ['proforma' => 'Proforma', 'contract' => 'Contract', 'letter' => 'Letter', 'internal' => 'Internal', 'prescribed' => 'Prescribed form', 'receipt' => 'Receipt'];

/** Saves a template (new or changed): the body is cleaned, the version goes up and the previous text is kept */
function brgy_save_template(array $d, ?int $id): int
{
    Access::require('brgy.templates', $id ? 'edit' : 'create');
    return DB::tx(function () use ($d, $id) {
        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 160);
        $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim((string)($d['code'] ?? ''))));
        $body = BrgyDocs::sanitize((string)($d['body'] ?? ''));
        if ($name === '') {
            fail('Enter the name of the template.');
        }
        if (trim(strip_tags($body)) === '' && !str_contains($body, '{{')) {
            fail('The template has no text.');
        }
        $row = ['name' => $name, 'category' => isset(BRGY_TPL_CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'proforma', 'stage' => isset(BRGY_TPL_STAGES[$d['stage'] ?? '']) ? $d['stage'] : 'bid',
            'doc_type' => ($d['doc_type'] ?? '') !== '' ? mb_substr((string)$d['doc_type'], 0, 40) : null, 'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 255) ?: null,
            'body' => $body, 'paper' => isset(BrgyLayout::PAPERS[$d['paper'] ?? '']) && $d['paper'] !== 'Custom' ? $d['paper'] : 'A4', 'orientation' => ($d['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
            'needs_approval' => isset($d['needs_approval']) ? (int)(bool)$d['needs_approval'] : 1,
            'is_prescribed' => !empty($d['is_prescribed']) ? 1 : 0, 'official_source' => mb_substr(trim((string)($d['official_source'] ?? '')), 0, 255) ?: null,
            'official_version' => mb_substr(trim((string)($d['official_version'] ?? '')), 0, 80) ?: null, 'required_fields' => mb_substr(trim((string)($d['required_fields'] ?? '')), 0, 255) ?: null,
            'sort_order' => (int)($d['sort_order'] ?? 0), 'is_active' => isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
        $note = mb_substr(trim((string)($d['change_note'] ?? '')), 0, 255);
        if ($id) {
            Brgy::checkVersion('brgy_templates', $id, $d['lock_version'] ?? null);
            $old = DB::row('SELECT * FROM brgy_templates WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($old['body'] !== $row['body'] || $old['name'] !== $row['name']) {
                $row['version_no'] = (int)$old['version_no'] + 1;
                DB::insert('brgy_template_versions', ['template_id' => $id, 'version_no' => $row['version_no'], 'name' => $row['name'], 'body' => $row['body'], 'layout_json' => $old['layout_json'],
                    'positions_json' => $old['positions_json'], 'change_note' => $note ?: null, 'changed_by' => Auth::id()]);
            }
            if ($old['paper'] !== $row['paper'] || $old['orientation'] !== $row['orientation']) {
                // the paper of the Format tab follows the paper chosen here
                $L = json_decode((string)$old['layout_json'], true) ?: [];
                $L['paper'] = $row['paper'];
                $L['orientation'] = $row['orientation'];
                unset($L['width_mm'], $L['height_mm']);
                $row['layout_json'] = json_encode($L);
            }
            DB::update('brgy_templates', $row, 'id = :id', ['id' => $id]);
            Brgy::bumpVersion('brgy_templates', $id);
            Brgy::log('edit', 'template', $id, $old['code'] . ' ' . $row['name'], ['reason' => $note ?: null, 'before' => array_diff_key($old, ['body' => 1]), 'after' => array_diff_key($row, ['body' => 1]) + ['body_length' => strlen($body)], 'page_key' => 'brgy.templates']);
            return $id;
        }
        if ($code === '' || $code === '_') {
            $code = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)), '_');
        }
        $base = mb_substr($code, 0, 34);
        $n = 1;
        while (DB::value('SELECT id FROM brgy_templates WHERE code = :c', ['c' => $code])) {
            $code = $base . '_' . (++$n);
        }
        $row += ['code' => $code, 'version_no' => 1, 'created_by' => Auth::id(), 'layout_json' => $d['layout_json'] ?? null, 'positions_json' => $d['positions_json'] ?? null, 'print_mode' => ($d['print_mode'] ?? '') === 'positions' ? 'positions' : 'layout'];
        $id = DB::insert('brgy_templates', $row);
        DB::insert('brgy_template_versions', ['template_id' => $id, 'version_no' => 1, 'name' => $name, 'body' => $body, 'layout_json' => $row['layout_json'], 'positions_json' => $row['positions_json'],
            'change_note' => $note ?: 'First version', 'changed_by' => Auth::id()]);
        Brgy::log('create', 'template', $id, $code . ' ' . $name, ['page_key' => 'brgy.templates']);
        return $id;
    });
}

/** Saves the format or the positions of a template as a new version (forms already prepared keep their text) */
function brgy_save_format(array $t, ?array $layout, ?array $positions, ?string $mode, string $note): void
{
    Access::require('brgy.templates', 'edit');
    DB::tx(function () use ($t, $layout, $positions, $mode, $note) {
        $set = ['version_no' => (int)$t['version_no'] + 1, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
        if ($layout !== null) {
            $set['layout_json'] = json_encode($layout, JSON_UNESCAPED_UNICODE);
            if ($layout['paper'] !== 'Custom') {
                $set['paper'] = $layout['paper'];
            }
            $set['orientation'] = $layout['orientation'];
        }
        if ($positions !== null) {
            $set['positions_json'] = json_encode($positions, JSON_UNESCAPED_UNICODE);
        }
        if ($mode !== null) {
            $set['print_mode'] = $mode === 'positions' ? 'positions' : 'layout';
        }
        DB::update('brgy_templates', $set, 'id = :id', ['id' => $t['id']]);
        $n = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $t['id']]);
        DB::insert('brgy_template_versions', ['template_id' => $t['id'], 'version_no' => $set['version_no'], 'name' => $n['name'], 'body' => $n['body'], 'layout_json' => $n['layout_json'],
            'positions_json' => $n['positions_json'], 'change_note' => $note, 'changed_by' => Auth::id()]);
        Brgy::bumpVersion('brgy_templates', (int)$t['id']);
        Brgy::log('edit', 'template', (int)$t['id'], $t['code'] . ' ' . $t['name'], ['reason' => $note, 'page_key' => 'brgy.templates']);
    });
}

/** A signatory of the Format tab: its fields named sig[$i][...] ($i = __i__ in the pattern for new ones) */
function brgy_fmt_sig_card($i, array $b, string $ro): string
{
    $b = BrgyLayout::sigBlock($b);
    $n = 'sig[' . $i . ']';
    $chk = fn(string $k, string $label) => '<label class="form-check form-check-inline m-0"><input class="form-check-input" type="checkbox" name="' . $n . '[' . $k . ']" value="1"' . ($b[$k] ? ' checked' : '') . $ro . '> <span class="form-check-label">' . h($label) . '</span></label>';
    return '<div class="sig-card" data-sig-card>'
        . '<div class="sig-card-head"><span class="sig-no" data-sig-no></span><span class="sig-card-title" data-sig-title>' . h(strip_tags($b['name']) ?: ($b['lead'] ?: 'Signatory')) . '</span>'
        . ($ro === '' ? '<span class="ms-auto btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-sig-move="-1" title="Move up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>'
            . '<button type="button" class="btn btn-outline-secondary" data-sig-move="1" title="Move down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>'
            . '<button type="button" class="btn btn-outline-danger" data-sig-remove title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></span>' : '') . '</div>'
        . '<div class="row g-1">'
        . '<div class="col-12"><textarea class="form-control form-control-sm" rows="1" name="' . $n . '[lead]" placeholder="Words above: Very truly yours, / Conforme: / Received by:" aria-label="Words above the signature"' . $ro . '>' . h($b['lead']) . '</textarea></div>'
        . '<div class="col-12"><input class="form-control form-control-sm fw-semibold" name="' . $n . '[name]" value="' . h($b['name']) . '" placeholder="Name, e.g. {{signatory.name}} or HON. {{entity.bac_chair_upper}}" aria-label="Name" data-sig-name' . $ro . '></div>'
        . '<div class="col-12"><textarea class="form-control form-control-sm" rows="1" name="' . $n . '[pos]" placeholder="Position, e.g. BAC Chairman" aria-label="Position"' . $ro . '>' . h($b['pos']) . '</textarea></div>'
        . '<div class="col-12"><textarea class="form-control form-control-sm" rows="1" name="' . $n . '[extra]" placeholder="More lines (company, address, Date: ____)" aria-label="More lines"' . $ro . '>' . h($b['extra']) . '</textarea></div>'
        . '<div class="col-12 d-flex flex-wrap gap-2 align-items-center small"><select class="form-select form-select-sm w-auto" name="' . $n . '[align]" data-no-combo aria-label="Alignment"' . $ro . '>'
        . options(BrgyLayout::SIG_ALIGN, $b['align']) . '</select>'
        . $chk('bold', 'Bold') . $chk('underline', 'Underlined') . $chk('upper', 'Capitals') . $chk('italic', 'Position in italics') . $chk('line', 'Signature line') . $chk('newrow', 'Starts a new row') . '</div>'
        . '</div></div>';
}

/** Puts {{letterhead}} at the top or {{signatories}} at the end of a template's text (a new version) */
function brgy_tpl_put_field(array $t, string $field): string
{
    if (!in_array($field, ['letterhead', 'signatories'], true)) {
        fail('Unknown field.');
    }
    if (preg_match('/\{\{\s*' . $field . '\s*\}\}/', (string)$t['body'])) {
        return 'The text already has {{' . $field . '}}.';
    }
    $body = $field === 'letterhead' ? '<div>{{letterhead}}</div>' . $t['body'] : $t['body'] . '<div>{{signatories}}</div>';
    brgy_save_template(['body' => $body, 'change_note' => '{{' . $field . '}} put in the text', 'lock_version' => $t['lock_version']] + $t, (int)$t['id']);
    return '{{' . $field . '}} is now ' . ($field === 'letterhead' ? 'at the top of' : 'at the end of') . ' the text (a new version). Move it with Edit the text if it belongs elsewhere.';
}

/** The project a template is shown with (the one asked for, or the newest transaction) */
function brgy_tpl_project(): ?array
{
    $pid = gint('project_id') ?: (int)DB::value("SELECT id FROM brgy_projects WHERE status IN ('awarded', 'closed') ORDER BY (process <> 'general') DESC, id DESC LIMIT 1");
    return $pid ? Brgy::project($pid) : null;
}

// ------------------------------------------------------------------ the format: live preview, printing a sample, the scan of a pre-printed paper
if ($a === 'format_preview' && $id && is_post()) {
    Access::require($pageKey, 'view');
    $t = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $id]);
    $L = BrgyLayout::fromPost($_POST);
    $p = gint('project_id') ? Brgy::project(gint('project_id')) : null;
    $html = BrgyDocs::render((string)$t['body'], BrgyDocs::context($p, null, 'BFM-PREVIEW', $L));
    header('Content-Type: application/json');
    echo json_encode(['css' => BrgyLayout::css($L, '#fmtPreview', false), 'style' => BrgyLayout::styleAttr($L), 'html' => $html . BrgyLayout::footerHtml($L), 'cls' => trim(BrgyLayout::docClass($L)),
        'paper' => BrgyLayout::paperLabel($L)]);
    exit;
}
if (in_array($a, ['print', 'test'], true) && $id) {
    Access::require($pageKey, 'print');
    $t = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $id]);
    if (!$t) {
        redirect(url($pageKey));
    }
    $p = brgy_tpl_project();
    $L = BrgyLayout::layout($t);
    $ctx = BrgyDocs::context($p, null, 'BFM-SAMPLE', $L);
    $back = url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => $a === 'test' ? 'positions' : 'format']);
    if ($a === 'test' || g('mode') === 'positions') {
        BrgyLayout::printPositions($t['name'] . ($a === 'test' ? ' (test page)' : ''), BrgyLayout::positions($t), BrgyLayout::values($ctx, $p ? Brgy::lines((int)$p['id']) : [], $L), ['test' => $a === 'test', 'back' => $back]);
    }
    BrgyLayout::printPage($t['name'] . ' (sample)', BrgyDocs::render($t['body'], $ctx), $L, ['draft' => (int)$t['needs_approval'] === 1, 'clean' => (int)$t['needs_approval'] === 0, 'back' => $back]);
}
if ($a === 'bg' && $id) {
    Access::require($pageKey, 'view');
    $f = (string)DB::value('SELECT bg_file FROM brgy_templates WHERE id = :id', ['id' => $id]);
    $path = BrgyDocs::dir() . '/' . $f;
    if ($f === '' || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ------------------------------------------------------------------ actions
if (in_array($a, ['view', 'versions'], true) && $id && is_post()) {
    $do = (string)p('do');
    brgy_action(function () use ($do, $id, $pageKey) {
        $t = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $id]);
        if (!$t) {
            fail('Template not found.');
        }
        switch ($do) {
            case 'restore':
                $v = DB::row('SELECT * FROM brgy_template_versions WHERE id = :v AND template_id = :t', ['v' => (int)pint('version_id'), 't' => $id]);
                if (!$v) {
                    fail('Version not found.');
                }
                $note = 'Version ' . $v['version_no'] . ' put back';
                $L = $v['layout_json'] !== null ? json_decode((string)$v['layout_json'], true) : null;
                $P = $v['positions_json'] !== null ? json_decode((string)$v['positions_json'], true) : null;
                $L = is_array($L) ? $L : null;
                $P = is_array($P) ? $P : null;
                if ($v['body'] !== $t['body'] || $v['name'] !== $t['name']) {
                    // the text comes back as a new version, and that same version takes the format put back with it
                    brgy_save_template(['body' => $v['body'], 'name' => $v['name'], 'change_note' => $note, 'lock_version' => $t['lock_version']] + $t, $id);
                    $n = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $id]);
                    $fmt = [];
                    if ($L !== null) {
                        $fmt['layout_json'] = json_encode($L, JSON_UNESCAPED_UNICODE);
                    }
                    if ($P !== null) {
                        $fmt['positions_json'] = json_encode($P, JSON_UNESCAPED_UNICODE);
                    }
                    if ($fmt) {
                        $paper = $L !== null && ($L['paper'] ?? 'Custom') !== 'Custom' ? ['paper' => $L['paper']] : [];
                        $orient = $L !== null && isset($L['orientation']) ? ['orientation' => $L['orientation']] : [];
                        DB::update('brgy_templates', $fmt + $paper + $orient, 'id = :id', ['id' => $id]);
                        DB::update('brgy_template_versions', $fmt, 'template_id = :t AND version_no = :n', ['t' => $id, 'n' => (int)$n['version_no']]);
                    }
                } else {
                    // the same text: only the format comes back, as a new version
                    brgy_save_format($t, $L, $P, null, $note);
                }
                return 'Version ' . $v['version_no'] . ' is the current text and format again (saved as a new version).';
            case 'format_save':
                $L = BrgyLayout::fromPost($_POST);
                if (in_array($L['lh_style'], ['company', 'barangay'], true)) {
                    // the shared letterhead is kept in Contracting Settings, for every template that uses it
                    $old = BrgyLayout::layout($t);
                    $keep = ['lh_pic_a' => $old['lh_style'] === $L['lh_style'] ? $old['lh_pic_a'] : BrgyLayout::sharedLetterhead($L['lh_style'])['lh_pic_a'],
                        'lh_pic_b' => $old['lh_style'] === $L['lh_style'] ? $old['lh_pic_b'] : BrgyLayout::sharedLetterhead($L['lh_style'])['lh_pic_b']];
                    if ($ch = BrgyLayout::saveSharedLetterhead($L['lh_style'], array_merge($L, $keep))) {
                        Brgy::log('edit', 'settings', 0, 'The ' . $L['lh_style'] . ' letterhead', ['before' => $ch[0], 'after' => $ch[1], 'reason' => 'Changed from the template ' . $t['code'], 'page_key' => 'brgy.settings']);
                    }
                    $L = array_diff_key($L, array_flip(BrgyLayout::LH_KEYS));
                }
                brgy_save_format($t, $L, null, null, 'Format: ' . BrgyLayout::paperLabel($L) . ', ' . $L['font'] . ' ' . $L['font_size'] . ' pt');
                redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'format', 'saved' => 1]));
            case 'format_reset':
                // the paper, margins, fonts, tables and copies back to the defaults; who signs and the letterhead stay
                $cur = BrgyLayout::layout($t);
                $keep = array_intersect_key($cur, array_flip(array_merge(['lh_style', 'signatories', 'sig_cols', 'sig_space', 'sig_gap', 'sig_top'], $cur['lh_style'] === 'own' ? BrgyLayout::LH_KEYS : [])));
                brgy_save_format($t, array_merge(BrgyLayout::layout(['paper' => $t['paper'], 'orientation' => $t['orientation']]), $keep), null, null, 'Format put back to the defaults');
                redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'format']));
            case 'positions_save':
                $P = BrgyLayout::positionsFromPost($_POST, (string)p('positions_json'));
                brgy_save_format($t, null, $P, (string)p('print_mode', $t['print_mode']), 'Positions on pre-printed paper: ' . count($P['fields']) . ' field(s), ' . count($P['tables']) . ' table(s)');
                redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'positions', 'saved' => 1]));
            case 'mode':
                brgy_save_format($t, null, null, (string)p('print_mode'), p('print_mode') === 'positions' ? 'Prints on pre-printed paper' : 'Prints with the layout');
                return p('print_mode') === 'positions' ? 'Forms of this template now print only the values, on the pre-printed paper.' : 'Forms of this template now print with the layout.';
            case 'bg_upload':
                Access::require('brgy.templates', 'edit');
                $f = brgy_file('bg');
                $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
                if (!$f || !in_array($ext, ['jpg', 'jpeg', 'png'], true) || $f['error'] !== UPLOAD_ERR_OK || !@getimagesize($f['tmp_name'])) {
                    fail('Choose a scan or photo of the pre-printed paper (JPG or PNG).');
                }
                if ((int)$f['size'] > BrgyDocs::maxBytes()) {
                    fail('The picture is too large.');
                }
                $name = 'tplbg_' . $id . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'png' ? 'png' : 'jpg');
                if (!move_uploaded_file($f['tmp_name'], BrgyDocs::dir() . '/' . $name) && !(PHP_SAPI === 'cli' && copy($f['tmp_name'], BrgyDocs::dir() . '/' . $name))) {
                    fail('The picture could not be saved (storage/contracts must be writable).');
                }
                if ($t['bg_file'] && is_file(BrgyDocs::dir() . '/' . $t['bg_file'])) {
                    @unlink(BrgyDocs::dir() . '/' . $t['bg_file']);
                }
                DB::update('brgy_templates', ['bg_file' => $name], 'id = :id', ['id' => $id]);
                Brgy::log('edit', 'template', $id, $t['code'], ['reason' => 'Scan of the pre-printed paper', 'page_key' => 'brgy.templates']);
                redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'positions']));
            case 'lhpic_upload':
            case 'lhpic_remove':
                Access::require('brgy.templates', 'edit');
                $slot = p('slot') === 'b' ? 'lh_pic_b' : 'lh_pic_a';
                $L = BrgyLayout::layout($t);
                $style = in_array(p('lh_style'), array_keys(BrgyLayout::LH_STYLES), true) ? (string)p('lh_style') : $L['lh_style'];
                $old = in_array($style, ['company', 'barangay'], true) ? (string)BrgyLayout::sharedLetterhead($style)[$slot] : (string)(json_decode((string)$t['layout_json'], true)[$slot] ?? '');
                $name = '';
                if ($do === 'lhpic_upload') {
                    $f = brgy_file('lhpic');
                    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
                    if (!$f || !in_array($ext, ['jpg', 'jpeg', 'png'], true) || $f['error'] !== UPLOAD_ERR_OK || !@getimagesize($f['tmp_name'])) {
                        fail('Choose a picture for the letterhead (PNG or JPG; PNG keeps a transparent background).');
                    }
                    if ((int)$f['size'] > BrgyDocs::maxBytes()) {
                        fail('The picture is too large.');
                    }
                    $name = 'lhpic_' . ($style === 'company' || $style === 'barangay' ? $style : 't' . $id) . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'png' ? 'png' : 'jpg');
                    if (!move_uploaded_file($f['tmp_name'], BrgyDocs::dir() . '/' . $name) && !(PHP_SAPI === 'cli' && copy($f['tmp_name'], BrgyDocs::dir() . '/' . $name))) {
                        fail('The picture could not be saved (storage/contracts must be writable).');
                    }
                }
                if (in_array($style, ['company', 'barangay'], true)) {
                    $sh = BrgyLayout::sharedLetterhead($style);
                    $sh[$slot] = $name;
                    if ($name !== '' && $sh[$slot === 'lh_pic_a' ? 'lh_left' : 'lh_right'] === 'none') {
                        $sh[$slot === 'lh_pic_a' ? 'lh_left' : 'lh_right'] = $slot === 'lh_pic_a' ? 'pic_a' : 'pic_b';
                    }
                    BrgyLayout::saveSharedLetterhead($style, $sh);
                    Brgy::log('edit', 'settings', 0, 'The ' . $style . ' letterhead', ['reason' => ($name ? 'Picture ' : 'Picture removed: ') . strtoupper(substr($slot, -1)), 'page_key' => 'brgy.settings']);
                    if ($old !== '' && preg_match('/^lhpic_[a-z0-9_]+\.(png|jpg)$/', $old) && is_file(BrgyDocs::dir() . '/' . $old)) {
                        @unlink(BrgyDocs::dir() . '/' . $old);
                    }
                } else {
                    $J = $L;      // the whole format of the template (a shared letterhead chosen before is the start of its own)
                    $J['lh_style'] = 'own';
                    $J[$slot] = $name;
                    if ($name !== '' && ($J[$slot === 'lh_pic_a' ? 'lh_left' : 'lh_right'] ?? 'none') === 'none') {
                        $J[$slot === 'lh_pic_a' ? 'lh_left' : 'lh_right'] = $slot === 'lh_pic_a' ? 'pic_a' : 'pic_b';
                    }
                    // the picture before stays on disk: an older version of the format may still show it
                    brgy_save_format($t, $J, null, null, $name ? 'Letterhead picture ' . strtoupper(substr($slot, -1)) : 'Letterhead picture ' . strtoupper(substr($slot, -1)) . ' removed');
                }
                redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'format', 'saved' => 1]));
            case 'put_field':
                Access::require('brgy.templates', 'edit');
                return brgy_tpl_put_field($t, (string)p('field'));
            case 'bg_remove':
                Access::require('brgy.templates', 'edit');
                if ($t['bg_file'] && is_file(BrgyDocs::dir() . '/' . $t['bg_file'])) {
                    @unlink(BrgyDocs::dir() . '/' . $t['bg_file']);
                }
                DB::update('brgy_templates', ['bg_file' => null], 'id = :id', ['id' => $id]);
                redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'positions']));
            case 'duplicate':
                $nid = brgy_save_template(['name' => $t['name'] . ' (copy)', 'code' => $t['code'] . '_copy', 'is_prescribed' => 0, 'change_note' => 'Copied from ' . $t['code'] . ' v' . $t['version_no']] + $t, null);
                redirect(url($pageKey, ['a' => 'edit', 'id' => $nid]));
            case 'active':
                Access::require('brgy.templates', 'edit');
                DB::update('brgy_templates', ['is_active' => $t['is_active'] ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                Brgy::log('edit', 'template', $id, $t['code'], ['reason' => $t['is_active'] ? 'Deactivated' : 'Activated', 'page_key' => 'brgy.templates']);
                return $t['is_active'] ? 'Template set aside (no new forms; existing forms stay).' : 'Template active again.';
            case 'delete':
                Access::require('brgy.templates', 'delete');
                if ((int)DB::value('SELECT COUNT(*) FROM brgy_forms WHERE template_id = :t', ['t' => $id])) {
                    fail('Forms were made from this template; set it aside instead of deleting it.');
                }
                DB::run('DELETE FROM brgy_templates WHERE id = :id', ['id' => $id]);
                Brgy::log('delete', 'template', $id, $t['code'] . ' ' . $t['name'], ['snapshot' => array_diff_key($t, ['body' => 1]), 'page_key' => 'brgy.templates']);
                redirect(url($pageKey));
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['a' => 'view', 'id' => $id]));
}

// ------------------------------------------------------------------ new / edit
if (in_array($a, ['new', 'edit'], true)) {
    $t = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $t = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => (int)$id]);
        if (!$t) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    handle_post(function () use ($t, $pageKey) {
        $nid = brgy_save_template($_POST, $t ? (int)$t['id'] : null);
        flash('success', $t ? 'Template saved' . ((int)DB::value('SELECT version_no FROM brgy_templates WHERE id = :id', ['id' => $nid]) > (int)$t['version_no'] ? ' as version ' . DB::value('SELECT version_no FROM brgy_templates WHERE id = :id', ['id' => $nid]) : '') . '. Forms already prepared keep their text.' : 'Template created.');
        redirect(url($pageKey, ['a' => p('next') === 'edit' ? 'edit' : 'view', 'id' => $nid]));
    });
    $body = is_post() ? BrgyDocs::sanitize((string)p('body')) : ($t['body'] ?? '<p>{{date.today}}</p><p>{{entity.head_name}}<br>{{entity.head_position}}<br>{{entity.name}}</p><p>Dear Sir / Madam:</p><p></p><p>Very truly yours,</p><p><b>{{signatory.name}}</b><br>{{signatory.position}}<br>{{company.name}}</p>');
    layout_start($t ? 'Edit template: ' . $t['name'] : 'New template', brgy_layout(['actions' => btn_link($t ? url($pageKey, ['a' => 'view', 'id' => $t['id']]) : url($pageKey), 'Cancel')]));
    ?>
    <?php if ($t && (int)$t['is_prescribed']): ?><div class="alert alert-warning small"><b>Prescribed form.</b> The wording follows a form issued by the GPPB or the procuring entity. Change it only to match the official version (and record that version below); a wrong prescribed form can disqualify a bid.</div><?php endif; ?>
    <form method="post" id="tplEdit" data-no-class-check><?= csrf_field() ?><?= $t ? Brgy::versionField($t['lock_version']) : '' ?>
      <textarea name="body" id="formBody" hidden><?= h($body) ?></textarea>
      <div class="row g-3">
        <div class="col-xl-9">
          <div class="editor-shell">
            <div class="editor-bar no-print" role="toolbar" aria-label="Formatting">
              <select class="form-select form-select-sm w-auto" data-cmd-block aria-label="Paragraph style"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Subheading</option></select>
              <span class="eb-group"><button type="button" class="eb" data-cmd="bold" title="Bold"><i class="bi bi-type-bold"></i></button><button type="button" class="eb" data-cmd="italic" title="Italic"><i class="bi bi-type-italic"></i></button><button type="button" class="eb" data-cmd="underline" title="Underline"><i class="bi bi-type-underline"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-cmd="justifyLeft" title="Align left"><i class="bi bi-text-left"></i></button><button type="button" class="eb" data-cmd="justifyCenter" title="Center"><i class="bi bi-text-center"></i></button><button type="button" class="eb" data-cmd="justifyRight" title="Align right"><i class="bi bi-text-right"></i></button><button type="button" class="eb" data-cmd="justifyFull" title="Justify"><i class="bi bi-justify"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-cmd="insertUnorderedList" title="Bullets"><i class="bi bi-list-ul"></i></button><button type="button" class="eb" data-cmd="insertOrderedList" title="Numbering"><i class="bi bi-list-ol"></i></button><button type="button" class="eb" data-cmd="outdent" title="Decrease indent"><i class="bi bi-text-indent-right"></i></button><button type="button" class="eb" data-cmd="indent" title="Increase indent"><i class="bi bi-text-indent-left"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-insert-table title="Insert a table"><i class="bi bi-table"></i></button><button type="button" class="eb" data-insert-break title="Page break"><i class="bi bi-file-break"></i></button><button type="button" class="eb" data-cmd="removeFormat" title="Clear formatting"><i class="bi bi-eraser"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-cmd="undo" title="Undo"><i class="bi bi-arrow-counterclockwise"></i></button><button type="button" class="eb" data-cmd="redo" title="Redo"><i class="bi bi-arrow-clockwise"></i></button></span>
              <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="offcanvas" data-bs-target="#fieldPanel"><i class="bi bi-braces"></i> Insert a field</button>
            </div>
            <div class="doc-stage"><div class="formdoc paper-sheet is-editing is-template" id="formEditor" contenteditable="true" spellcheck="true" style="<?= h(BrgyLayout::styleAttr(BrgyLayout::layout($t), false)) ?>"><?= $body ?></div></div>
          </div>
          <p class="small text-muted mt-2">Fields appear as <code>{{entity.name}}</code> and are filled from the project when a form is prepared. A field with no value at that time is left as a blank to fill. Tables such as <code>{{lines.table}}</code> become the price schedule of the project. Paper, margins, font, the columns of the tables, copies and printer offsets are set on the Format tab of the template.</p>
        </div>
        <div class="col-xl-3">
          <div class="card mb-3"><div class="card-body row g-2">
            <?= input_field('name', 'Name', fv('name', $t), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="160"']) ?>
            <?= $t ? '<div class="col-12 small text-muted">Code <code>' . h($t['code']) . '</code>, version ' . (int)$t['version_no'] . '</div>' : input_field('code', 'Code (letters, digits, _)', fv('code'), ['class' => 'col-12', 'extra' => 'maxlength="40"']) ?>
            <?= select_field('category', 'Kind', BRGY_TPL_CATEGORIES, fv('category', $t, 'proforma'), ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('stage', 'Used at', BRGY_TPL_STAGES, fv('stage', $t, 'bid'), ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('doc_type', 'A final copy counts as', Brgy::docTypes(), fv('doc_type', $t), ['blank' => 'Nothing on the checklists', 'class' => 'col-12']) ?>
            <?= select_field('paper', 'Paper', array_map(fn($x) => $x[2], array_diff_key(BrgyLayout::PAPERS, ['Custom' => 1])), fv('paper', $t, 'A4'), ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('orientation', 'Orientation', ['portrait' => 'Portrait', 'landscape' => 'Landscape'], fv('orientation', $t, 'portrait'), ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= input_field('description', 'Description', fv('description', $t), ['class' => 'col-12']) ?>
            <?= check_field('needs_approval', 'Forms need review and approval (DRAFT mark until final)', fv('needs_approval', $t, 1), 'col-12') ?>
            <?= check_field('is_prescribed', 'Prescribed form (GPPB / entity)', fv('is_prescribed', $t, 0), 'col-12') ?>
            <?= input_field('official_source', 'Official source', fv('official_source', $t), ['class' => 'col-12']) ?>
            <?= input_field('official_version', 'Official version', fv('official_version', $t), ['class' => 'col-12']) ?>
            <?= input_field('sort_order', 'Order in lists', fv('sort_order', $t, 100), ['type' => 'number', 'step' => '1', 'class' => 'col-6']) ?>
            <?= $t ? check_field('is_active', 'Active', fv('is_active', $t, 1), 'col-6') : '' ?>
            <?= input_field('change_note', 'What changed', '', ['class' => 'col-12', 'extra' => 'placeholder="kept with the version"']) ?>
          </div></div>
        </div>
      </div>
      <div class="sticky-actions d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button><button class="btn btn-outline-primary btn-sm" name="next" value="edit">Save and keep editing</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h($t ? url($pageKey, ['a' => 'view', 'id' => $t['id']]) : url($pageKey)) ?>">Close without saving</a></div>
    </form>
    <div class="offcanvas offcanvas-end field-panel" tabindex="-1" id="fieldPanel"><div class="offcanvas-header"><h5 class="offcanvas-title">Insert a field</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div>
      <div class="offcanvas-body"><input class="form-control form-control-sm mb-2" type="search" placeholder="Find a field" data-field-filter>
        <?php foreach (BrgyDocs::FIELDS as $g => $fields): ?><div class="fp-group"><div class="fp-head"><?= h($g) ?></div>
          <?php foreach ($fields as $k => $lbl): ?><button type="button" class="fp-item" data-insert-field="<?= h($k) ?>"><span><?= h($lbl) ?></span><code><?= h('{{' . $k . '}}') ?></code></button><?php endforeach; ?></div>
        <?php endforeach; ?>
        <p class="small text-muted mt-2">The field goes where the cursor was in the page.</p></div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ view: preview, format, positions on pre-printed paper, versions
if ($a === 'view' && $id) {
    $t = DB::row('SELECT t.*, u.full_name AS updated_name FROM brgy_templates t LEFT JOIN users u ON u.id = t.updated_by WHERE t.id = :id', ['id' => $id]);
    if (!$t) {
        redirect(url($pageKey));
    }
    $vt = (string)g('tab', 'preview');
    if (!in_array($vt, ['preview', 'format', 'positions', 'versions'], true)) {
        $vt = 'preview';
    }
    $p = brgy_tpl_project();
    $pid = $p ? (int)$p['id'] : 0;
    $L = BrgyLayout::layout($t);
    $missing = [];
    $ctx = BrgyDocs::context($p, null, 'BFM-PREVIEW', $L);
    $preview = BrgyDocs::render($t['body'], $ctx, $missing);
    $versions = DB::all('SELECT v.*, u.full_name FROM brgy_template_versions v LEFT JOIN users u ON u.id = v.changed_by WHERE v.template_id = :t ORDER BY v.version_no DESC', ['t' => $id]);
    $uses = (int)DB::value('SELECT COUNT(*) FROM brgy_forms WHERE template_id = :t', ['t' => $id]);
    $canEditT = Access::can($pageKey, 'edit');
    $self = fn(array $more = []) => url($pageKey, array_merge(['a' => 'view', 'id' => $id, 'tab' => $vt, 'project_id' => $pid ?: null], $more));
    $actions = page_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit the text', 'btn-primary', 'pencil', 'edit') . ' '
        . page_link('brgy.forms', ['a' => 'new', 'template_id' => $id, 'project_id' => $pid], 'Prepare a form', 'btn-outline-primary', 'file-earmark-plus', 'create') . ' '
        . (Access::can($pageKey, 'print') ? '<a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="' . h(url($pageKey, ['a' => 'print', 'id' => $id, 'project_id' => $pid, 'mode' => $t['print_mode']])) . '"><i class="bi bi-printer"></i> Print a sample</a> ' : '')
        . (Access::can($pageKey, 'create') ? '<form method="post" action="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="do" value="duplicate"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-copy"></i> Duplicate</button></form> ' : '')
        . ($canEditT ? '<form method="post" action="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '" class="d-inline">' . csrf_field() . '<input type="hidden" name="do" value="active"><button class="btn btn-sm btn-outline-secondary">' . ($t['is_active'] ? 'Set aside' : 'Make active') . '</button></form> ' : '')
        . (!$uses && Access::can($pageKey, 'delete') ? '<form method="post" action="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '" class="d-inline" data-confirm="Delete this template and its versions?">' . csrf_field() . '<input type="hidden" name="do" value="delete"><button class="btn btn-sm btn-outline-danger">Delete</button></form>' : '');
    layout_start($t['name'], brgy_layout(['actions' => $actions]));
    if (g('saved')) {
        echo '<div class="alert alert-success small brgy-msg">Saved as version ' . (int)$t['version_no'] . '. Forms prepared from now on use it; forms already prepared print with it too, and keep their text.</div>';
    }
    $projSel = '<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-2"><input type="hidden" name="p" value="' . h($pageKey) . '"><input type="hidden" name="a" value="view"><input type="hidden" name="id" value="' . (int)$id . '"><input type="hidden" name="tab" value="' . h($vt) . '">'
        . '<div><label class="form-label">Show with the project</label><select class="form-select form-select-sm" name="project_id" data-autosubmit data-no-combo>' . options(DB::pairs("SELECT id, CONCAT(project_no, ' - ', LEFT(title, 60)) FROM brgy_projects ORDER BY id DESC"), $pid, '(none: company fields only)') . '</select></div>'
        . ($missing && $vt === 'preview' ? '<div class="small text-muted pb-1">Blank for this project: ' . h(implode(', ', $missing)) . '</div>' : '') . '</form>';
    ?>
    <ul class="nav nav-tabs proj-tabs mb-0">
      <?php foreach (['preview' => 'Preview', 'format' => 'Format', 'positions' => 'Pre-printed paper', 'versions' => 'Versions'] as $k => $lbl): ?>
        <li class="nav-item"><a class="nav-link <?= $vt === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => $k, 'project_id' => $pid ?: null])) ?>"><?= h($lbl) ?><?= $k === 'positions' && $t['print_mode'] === 'positions' ? ' <span class="tab-count">on</span>' : '' ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="tab-body">
    <?php if ($vt === 'preview'): ?>
      <div class="row g-3">
        <div class="col-xl-9 order-2 order-xl-1"><?= $projSel ?>
          <style><?= BrgyLayout::css($L, '.tpl-prev', false) ?></style>
          <div class="doc-stage is-preview tpl-prev"><div class="formdoc paper-sheet<?= (int)$t['needs_approval'] ? '' : ' is-clean' ?><?= BrgyLayout::docClass($L) ?>" style="<?= h(BrgyLayout::styleAttr($L)) ?>"><?= $preview . BrgyLayout::footerHtml($L) ?></div></div>
        </div>
        <div class="col-xl-3 order-1 order-xl-2">
          <div class="card mb-3"><div class="card-body small"><dl class="dl-grid dl-stack mb-0">
            <dt>Code</dt><dd><code><?= h($t['code']) ?></code>, version <?= (int)$t['version_no'] ?></dd>
            <dt>Kind</dt><dd><?= h(BRGY_TPL_CATEGORIES[$t['category']] ?? $t['category']) ?>, used at <?= h(strtolower(BRGY_TPL_STAGES[$t['stage']] ?? (string)$t['stage'])) ?></dd>
            <dt>Counts as</dt><dd><?= h($t['doc_type'] ? Brgy::docTypeName($t['doc_type']) : 'nothing') ?></dd>
            <dt>Prints</dt><dd><?= h(BrgyLayout::paperLabel($L)) ?>, <?= h($L['font'] . ' ' . $L['font_size'] . ' pt') ?><?= (int)$L['copies'] > 1 ? ', ' . (int)$L['copies'] . ' copies' : '' ?><div class="text-muted"><?= $t['print_mode'] === 'positions' ? 'On pre-printed paper (values only)' : 'With the layout' ?></div></dd>
            <dt>Approval</dt><dd><?= (int)$t['needs_approval'] ? 'Review and approval; DRAFT until final' : 'None: forms print clean as soon as they are prepared' ?></dd>
            <dt>Status</dt><dd><?= Brgy::st($t['is_active'] ? 'ok' : 'void', $t['is_active'] ? 'Active' : 'Set aside') ?></dd>
            <?php if ((int)$t['is_prescribed']): ?><dt>Prescribed</dt><dd><?= h($t['official_source'] ?: 'yes') ?><?= $t['official_version'] ? '<div class="text-muted">' . h($t['official_version']) . '</div>' : '' ?></dd><?php endif; ?>
            <dt>Forms made</dt><dd><?= $uses ?></dd>
            <?php if ($t['description']): ?><dt>About</dt><dd><?= h($t['description']) ?></dd><?php endif; ?>
          </dl></div></div>
        </div>
      </div>

    <?php elseif ($vt === 'format'):
        $tables = BrgyLayout::tablesIn($t['body']);
        $sections = BrgyLayout::sections($t['body']);
        $ro = $canEditT ? '' : ' disabled'; ?>
      <?= $projSel ?>
      <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" id="fmtForm" class="fmt-grid" data-preview-url="<?= h(url($pageKey, ['a' => 'format_preview', 'id' => $id, 'project_id' => $pid ?: null])) ?>">
        <?= csrf_field() ?><input type="hidden" name="do" value="format_save">
        <div class="fmt-side">
          <details class="fmt-box" open><summary><i class="bi bi-file-earmark"></i> Paper</summary><div class="row g-2">
            <?= select_field('paper', 'Size', array_map(fn($x) => $x[2], BrgyLayout::PAPERS), $L['paper'], ['class' => 'col-7', 'extra' => 'data-no-combo id="fPaper"' . $ro]) ?>
            <?= select_field('orientation', 'Orientation', ['portrait' => 'Portrait', 'landscape' => 'Landscape'], $L['orientation'], ['class' => 'col-5', 'extra' => 'data-no-combo' . $ro]) ?>
            <div class="col-6" data-custom-size><label class="form-label">Width (mm)</label><input class="form-control form-control-sm" name="width_mm" inputmode="decimal" value="<?= h((string)$L['width_mm']) ?>"<?= $ro ?>></div>
            <div class="col-6" data-custom-size><label class="form-label">Height (mm)</label><input class="form-control form-control-sm" name="height_mm" inputmode="decimal" value="<?= h((string)$L['height_mm']) ?>"<?= $ro ?>></div>
            <div class="col-12"><label class="form-label">Margins (mm): top, right, bottom, left</label><div class="input-group input-group-sm">
              <?php foreach (['margin_top', 'margin_right', 'margin_bottom', 'margin_left'] as $k): ?><input class="form-control" name="<?= $k ?>" inputmode="decimal" value="<?= h((string)$L[$k]) ?>" aria-label="<?= h(str_replace('_', ' ', $k)) ?>"<?= $ro ?>><?php endforeach; ?></div></div>
          </div></details>
          <details class="fmt-box" open><summary><i class="bi bi-fonts"></i> Text</summary><div class="row g-2">
            <?= select_field('font', 'Font', array_combine(array_keys(BrgyLayout::FONTS), array_keys(BrgyLayout::FONTS)), $L['font'], ['class' => 'col-12', 'extra' => 'data-no-combo' . $ro]) ?>
            <?php foreach (['font_size' => 'Size (pt)', 'line_height' => 'Line spacing', 'title_size' => 'Titles (pt)', 'table_size' => 'Tables (pt)'] as $k => $lbl): ?>
              <div class="col-6"><label class="form-label"><?= h($lbl) ?></label><input class="form-control form-control-sm" name="<?= $k ?>" inputmode="decimal" value="<?= h((string)$L[$k]) ?>"<?= $ro ?>></div>
            <?php endforeach; ?>
            <div class="col-12"><label class="form-label">Dates</label><select class="form-select form-select-sm" name="date_style" data-no-combo<?= $ro ?>><?= options(['dmy' => date('j F Y') . ' (day month year)', 'mdy' => date('F j, Y') . ' (month day, year)'], $L['date_style']) ?></select></div>
          </div></details>
          <?php $hasLh = (bool)preg_match('/\{\{\s*letterhead\s*\}\}/', (string)$t['body']); $shared = in_array($L['lh_style'], ['company', 'barangay'], true);
            // the settings of each letterhead, so that choosing another one shows its own settings
            $raw = json_decode((string)$t['layout_json'], true) ?: [];
            $lhSets = ['company' => BrgyLayout::sharedLetterhead('company'), 'barangay' => BrgyLayout::sharedLetterhead('barangay'),
                'own' => array_intersect_key(($raw['lh_style'] ?? '') === 'own' ? $raw + $L : $L, array_flip(BrgyLayout::LH_KEYS))];
            foreach ($lhSets as &$set) {
                $set['pic_a_url'] = BrgyLayout::picUrl((string)$set['lh_pic_a']);
                $set['pic_b_url'] = BrgyLayout::picUrl((string)$set['lh_pic_b']);
            }
            unset($set); ?>
          <script type="application/json" id="lhSets"><?= json_encode($lhSets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
          <details class="fmt-box" <?= $hasLh ? 'open' : '' ?> id="fmtLetterhead"><summary><i class="bi bi-card-heading"></i> Letterhead and logos</summary>
            <?php if (!$hasLh): ?><div class="fmt-note mb-2">This template has no <code>{{letterhead}}</code> in its text, so nothing below prints yet.<?php if ($canEditT): ?> <button type="submit" class="btn btn-sm btn-outline-primary mt-1" form="fmtPutLh">Put it at the top of the text</button><?php endif; ?></div><?php endif; ?>
            <div class="row g-2">
              <div class="col-12"><label class="form-label" for="fLhStyle">Letterhead</label><select class="form-select form-select-sm" name="lh_style" id="fLhStyle" data-no-combo<?= $ro ?>><?= options(BrgyLayout::LH_STYLES, $L['lh_style']) ?></select>
                <div class="form-text" data-lh-shared<?= $shared ? '' : ' hidden' ?>>Shared: a change here changes every template that uses this letterhead (kept in Contracting Settings).</div></div>
              <div class="col-12" data-lh-fields<?= $L['lh_style'] === 'none' ? ' hidden' : '' ?>><div class="row g-2">
                <div class="col-6"><label class="form-label">Picture on the left</label><select class="form-select form-select-sm" name="lh_left" data-no-combo<?= $ro ?>><?= options(BrgyLayout::LH_PICS, $L['lh_left']) ?></select></div>
                <div class="col-6"><label class="form-label">Picture on the right</label><select class="form-select form-select-sm" name="lh_right" data-no-combo<?= $ro ?>><?= options(BrgyLayout::LH_PICS, $L['lh_right']) ?></select></div>
                <?php foreach (['a' => 'lh_pic_a', 'b' => 'lh_pic_b'] as $slot => $k): $u = BrgyLayout::picUrl((string)$L[$k]); ?>
                  <div class="col-6"><div class="lh-pic"><div class="lh-pic-img" data-lhpic-img="<?= $slot ?>"><?= $u ? '<img src="' . h($u) . '" alt="">' : '<i class="bi bi-image"></i>' ?></div>
                    <div class="lh-pic-body"><div class="small fw-semibold">Picture <?= strtoupper($slot) ?></div><input type="hidden" name="<?= $k ?>" value="<?= h((string)$L[$k]) ?>">
                      <?php if ($canEditT): ?><label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-upload"></i> <?= $u ? 'Change' : 'Upload' ?><input type="file" name="lhpic" accept=".png,.jpg,.jpeg" form="fmtPic<?= $slot ?>" hidden data-lhpic-file></label>
                        <?php if ($u): ?><button type="submit" class="btn btn-sm btn-link text-danger p-0 ms-1" form="fmtPicDel<?= $slot ?>" data-confirm="Remove picture <?= strtoupper($slot) ?> from the letterhead?">Remove</button><?php endif; ?><?php endif; ?></div></div></div>
                <?php endforeach; ?>
                <div class="col-12 form-text mt-0">Pictures A and B are your own (a logo, a banner, a seal). The company logo is set in Company Information; the seals in each procuring entity.</div>
                <div class="col-12"><label class="form-label">Lines of text</label><textarea class="form-control form-control-sm font-monospace" name="lh_lines" rows="4"<?= $ro ?>><?= h((string)$L['lh_lines']) ?></textarea>
                  <div class="form-text"><code># </code> at the start makes a big line (the name), <code>**text**</code> bold. Fields such as <code>{{company.address}}</code> are filled; a line whose fields are all blank is left out.</div></div>
                <div class="col-4"><label class="form-label">Alignment</label><select class="form-select form-select-sm" name="lh_align" data-no-combo<?= $ro ?>><?= options(['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], $L['lh_align']) ?></select></div>
                <div class="col-8"><label class="form-label">Font</label><select class="form-select form-select-sm" name="lh_font" data-no-combo<?= $ro ?>><?= options(['' => 'As the text'] + array_combine(array_keys(BrgyLayout::FONTS), array_keys(BrgyLayout::FONTS)), $L['lh_font']) ?></select></div>
                <div class="col-4"><label class="form-label">Text (pt)</label><input class="form-control form-control-sm" name="lh_size" inputmode="decimal" value="<?= h((string)$L['lh_size']) ?>"<?= $ro ?>></div>
                <div class="col-4"><label class="form-label">Big line (pt)</label><input class="form-control form-control-sm" name="lh_big" inputmode="decimal" value="<?= h((string)$L['lh_big']) ?>"<?= $ro ?>></div>
                <div class="col-4"><label class="form-label">Pictures (mm)</label><input class="form-control form-control-sm" name="lh_pic_mm" inputmode="decimal" value="<?= h((string)$L['lh_pic_mm']) ?>"<?= $ro ?>></div>
                <div class="col-12"><label class="form-check m-0"><input class="form-check-input" type="checkbox" name="lh_box" value="1" <?= $L['lh_box'] ? 'checked' : '' ?><?= $ro ?>> <span class="form-check-label small">Text in a coloured banner</span></label></div>
                <div class="col-6"><label class="form-label">Banner</label><input class="form-control form-control-sm form-control-color w-100" type="color" name="lh_box_bg" value="<?= h((string)$L['lh_box_bg']) ?>"<?= $ro ?>></div>
                <div class="col-6"><label class="form-label">Banner text</label><input class="form-control form-control-sm form-control-color w-100" type="color" name="lh_box_fg" value="<?= h((string)$L['lh_box_fg']) ?>"<?= $ro ?>></div>
                <div class="col-4"><label class="form-label">Line under (px)</label><input class="form-control form-control-sm" name="lh_rule" inputmode="decimal" value="<?= h((string)$L['lh_rule']) ?>"<?= $ro ?>></div>
                <div class="col-4"><label class="form-label">Its colour</label><input class="form-control form-control-sm form-control-color w-100" type="color" name="lh_rule_color" value="<?= h((string)$L['lh_rule_color']) ?>"<?= $ro ?>></div>
                <div class="col-4"><label class="form-label">Space after (mm)</label><input class="form-control form-control-sm" name="lh_space" inputmode="decimal" value="<?= h((string)$L['lh_space']) ?>"<?= $ro ?>></div>
              </div></div>
            </div>
          </details>
          <?php $hasSig = (bool)preg_match('/\{\{\s*signatories\s*\}\}/', (string)$t['body']); ?>
          <details class="fmt-box" <?= $hasSig ? 'open' : '' ?> id="fmtSigs"><summary><i class="bi bi-pen"></i> Signatories</summary>
            <?php if (!$hasSig): ?><div class="fmt-note mb-2">This template has no <code>{{signatories}}</code> in its text, so the signatures below do not print yet.<?php if ($canEditT): ?> <button type="submit" class="btn btn-sm btn-outline-primary mt-1" form="fmtPutSig">Put them at the end of the text</button><?php endif; ?></div><?php endif; ?>
            <div class="row g-2 mb-2">
              <div class="col-3"><label class="form-label">Side by side</label><input class="form-control form-control-sm" type="number" min="1" max="4" name="sig_cols" value="<?= (int)$L['sig_cols'] ?>"<?= $ro ?>></div>
              <div class="col-3"><label class="form-label">To sign (mm)</label><input class="form-control form-control-sm" name="sig_space" inputmode="decimal" value="<?= h((string)$L['sig_space']) ?>"<?= $ro ?>></div>
              <div class="col-3"><label class="form-label">Between rows</label><input class="form-control form-control-sm" name="sig_gap" inputmode="decimal" value="<?= h((string)$L['sig_gap']) ?>"<?= $ro ?>></div>
              <div class="col-3"><label class="form-label">Space before</label><input class="form-control form-control-sm" name="sig_top" inputmode="decimal" value="<?= h((string)$L['sig_top']) ?>"<?= $ro ?>></div>
            </div>
            <div class="sig-list" data-sig-list><?php foreach ($L['signatories'] as $i => $b) { echo brgy_fmt_sig_card($i, $b, $ro); } ?></div>
            <div class="form-text mb-2" data-sig-empty<?= $L['signatories'] ? ' hidden' : '' ?>>No signatures: <code>{{signatories}}</code> prints nothing.</div>
            <?php if ($canEditT): ?>
              <div class="input-group input-group-sm"><select class="form-select" data-sig-preset data-no-combo aria-label="Signatory to add"><?= options(array_combine(array_keys(BrgyLayout::SIG_PRESETS), array_keys(BrgyLayout::SIG_PRESETS))) ?></select>
                <button type="button" class="btn btn-outline-primary" data-sig-add><i class="bi bi-plus-lg"></i> Add</button></div>
              <template id="sigCardTpl"><?= brgy_fmt_sig_card('__i__', ['bold' => 1], '') ?></template>
              <script type="application/json" id="sigPresets"><?= json_encode(array_map(fn($b) => BrgyLayout::sigBlock($b + ['bold' => 1]), BrgyLayout::SIG_PRESETS), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
            <?php endif; ?>
            <div class="form-text">Names and positions may hold fields: <code>{{signatory.name}}</code>, <code>HON. {{entity.bac_chair_upper}}</code>, <code>{{entity.bac_member_1_upper}}</code> ... A name whose fields are blank prints as a line to sign on.</div>
          </details>
          <details class="fmt-box"><summary><i class="bi bi-table"></i> Tables</summary><div class="row g-2">
            <div class="col-6"><label class="form-label">Lines (px)</label><input class="form-control form-control-sm" name="border" inputmode="decimal" value="<?= h((string)$L['border']) ?>"<?= $ro ?>></div>
            <div class="col-6"><label class="form-label">Cell padding (px)</label><input class="form-control form-control-sm" name="cell_pad" inputmode="decimal" value="<?= h((string)$L['cell_pad']) ?>"<?= $ro ?>></div>
            <div class="col-6"><label class="form-label">Blank rows after the items</label><input class="form-control form-control-sm" type="number" min="0" max="40" name="blank_rows" value="<?= (int)$L['blank_rows'] ?>"<?= $ro ?>></div>
            <div class="col-6 d-flex flex-column justify-content-end gap-1">
              <label class="form-check"><input class="form-check-input" type="checkbox" name="nothing_follows" value="1" <?= $L['nothing_follows'] ? 'checked' : '' ?><?= $ro ?>> <span class="form-check-label small">***nothing follows***</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="show_specs" value="1" <?= $L['show_specs'] ? 'checked' : '' ?><?= $ro ?>> <span class="form-check-label small">Specifications under the items</span></label></div>
            <div class="col-6"><label class="form-label">Heading colour</label><div class="input-group input-group-sm"><span class="input-group-text"><input class="form-check-input m-0" type="checkbox" data-th-on <?= $L['th_bg'] !== '' ? 'checked' : '' ?> aria-label="Colour the headings"<?= $ro ?>></span>
              <input class="form-control form-control-color" type="color" data-th-color value="<?= h($L['th_bg'] ?: '#fff2cc') ?>" aria-label="Colour"<?= $ro ?>><input type="hidden" name="th_bg" value="<?= h($L['th_bg']) ?>" data-th-value></div></div>
            <div class="col-6"><label class="form-label">Statement of compliance</label><input class="form-control form-control-sm" name="comply_text" maxlength="40" value="<?= h((string)$L['comply_text']) ?>"<?= $ro ?>></div>
          </div>
          <?php foreach ($tables as $tk => $tl): ?>
            <div class="fmt-cols"><div class="fmt-cols-head"><?= h($tl) ?> <code>{{<?= h($tk) ?>}}</code></div>
              <?php foreach (BrgyLayout::columns($tk, $L) as $ck => $c): ?>
                <div class="fmt-col">
                  <label class="form-check m-0"><input class="form-check-input" type="checkbox" name="col[<?= h($tk) ?>][<?= h($ck) ?>][show]" value="1" <?= $c['show'] ? 'checked' : '' ?><?= $ro ?> aria-label="Show"></label>
                  <input class="form-control form-control-sm" name="col[<?= h($tk) ?>][<?= h($ck) ?>][label]" value="<?= h(str_replace("\n", ' / ', $c['label'])) ?>" aria-label="Title"<?= $ro ?>>
                  <input class="form-control form-control-sm fc-w" name="col[<?= h($tk) ?>][<?= h($ck) ?>][width]" type="number" min="0" max="90" value="<?= (int)$c['width'] ?>" aria-label="Width %" title="Width % (0 = the rest)"<?= $ro ?>>
                  <select class="form-select form-select-sm fc-a" name="col[<?= h($tk) ?>][<?= h($ck) ?>][align]" data-no-combo aria-label="Alignment"<?= $ro ?>><?= options(['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], $c['align']) ?></select>
                </div>
              <?php endforeach; ?>
              <div class="form-text">Tick to print a column; its title, its width in % (0 takes the rest) and its alignment. "/" in a title starts a new line.</div></div>
          <?php endforeach; ?>
          <?php if (!$tables): ?><div class="form-text">This template has no item table.</div><?php endif; ?>
          </details>
          <?php if ($sections): ?>
          <details class="fmt-box"><summary><i class="bi bi-eye"></i> Parts printed</summary>
            <?php foreach ($sections as $sk => $sl): ?><label class="form-check"><input class="form-check-input" type="checkbox" data-section-toggle value="<?= h($sk) ?>" <?= in_array($sk, $L['hidden'], true) ? '' : 'checked' ?><?= $ro ?>> <span class="form-check-label"><?= h($sl) ?></span></label><?php endforeach; ?>
            <?php foreach ($L['hidden'] as $hk): ?><input type="hidden" name="hidden[]" value="<?= h($hk) ?>" data-hidden-sec="<?= h($hk) ?>"><?php endforeach; ?>
          </details>
          <?php endif; ?>
          <details class="fmt-box"><summary><i class="bi bi-printer"></i> Copies and printer</summary><div class="row g-2">
            <div class="col-4"><label class="form-label">Copies</label><input class="form-control form-control-sm" type="number" min="1" max="6" name="copies" value="<?= (int)$L['copies'] ?>"<?= $ro ?>></div>
            <div class="col-8"><label class="form-label">Label of each copy (one per line)</label><textarea class="form-control form-control-sm" name="copy_labels" rows="3" placeholder="Original&#10;Duplicate&#10;Triplicate"<?= $ro ?>><?= h((string)$L['copy_labels']) ?></textarea></div>
            <div class="col-6"><label class="form-label">Offset right (mm)</label><input class="form-control form-control-sm" name="offset_x" inputmode="decimal" value="<?= h((string)$L['offset_x']) ?>"<?= $ro ?>></div>
            <div class="col-6"><label class="form-label">Offset down (mm)</label><input class="form-control form-control-sm" name="offset_y" inputmode="decimal" value="<?= h((string)$L['offset_y']) ?>"<?= $ro ?>></div>
            <div class="col-12 form-text">When a printer prints everything a little off, move it here (negative goes left / up).</div>
            <div class="col-12"><label class="form-label">Footer</label><input class="form-control form-control-sm" name="footer" maxlength="200" value="<?= h((string)$L['footer']) ?>" placeholder="e.g. OFFICE OF THE BARANGAY, or Form BRGY-PR rev. 1"<?= $ro ?>></div>
            <div class="col-6"><label class="form-label">Where</label><select class="form-select form-select-sm" name="footer_pos" data-no-combo<?= $ro ?>><?= options(['end' => 'After the text', 'bottom' => 'At the bottom of the page'], $L['footer_pos']) ?></select></div>
            <div class="col-6"><label class="form-label">Alignment</label><select class="form-select form-select-sm" name="footer_align" data-no-combo<?= $ro ?>><?= options(['left' => 'Left', 'center' => 'Center', 'right' => 'Right'], $L['footer_align']) ?></select></div>
            <div class="col-6"><label class="form-label">Size (pt)</label><input class="form-control form-control-sm" name="footer_size" inputmode="decimal" value="<?= h((string)$L['footer_size']) ?>"<?= $ro ?>></div>
            <div class="col-6 d-flex align-items-end"><label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="footer_bold" value="1" <?= $L['footer_bold'] ? 'checked' : '' ?><?= $ro ?>> <span class="form-check-label small">Bold</span></label></div>
          </div></details>
          <?php if ($canEditT): ?><div class="d-flex flex-wrap gap-2 mt-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save the format</button>
            <button class="btn btn-outline-secondary btn-sm" name="do" value="format_reset" data-confirm="Put the format back to the defaults (a new version keeps the current one)?">Defaults</button></div><?php endif; ?>
        </div>
        <div class="fmt-main">
          <div class="d-flex align-items-center gap-2 mb-2 small text-muted"><span id="fmtPaper"><?= h(BrgyLayout::paperLabel($L)) ?></span><span class="ms-auto" id="fmtState">Live preview</span></div>
          <div class="doc-stage is-preview" id="fmtPreview"><style data-fmt-css><?= BrgyLayout::css($L, '#fmtPreview', false) ?></style>
            <div class="formdoc paper-sheet<?= (int)$t['needs_approval'] ? '' : ' is-clean' ?><?= BrgyLayout::docClass($L) ?>" style="<?= h(BrgyLayout::styleAttr($L)) ?>" data-fmt-doc data-base-class="formdoc paper-sheet<?= (int)$t['needs_approval'] ? '' : ' is-clean' ?>"><?= $preview . BrgyLayout::footerHtml($L) ?></div></div>
        </div>
      </form>
      <?php if ($canEditT): $postTo = h(url($pageKey, ['a' => 'view', 'id' => $id])); ?>
        <div hidden>
          <?php foreach (['a', 'b'] as $slot): ?>
            <form method="post" action="<?= $postTo ?>" enctype="multipart/form-data" id="fmtPic<?= $slot ?>"><?= csrf_field() ?><input type="hidden" name="do" value="lhpic_upload"><input type="hidden" name="slot" value="<?= $slot ?>"><input type="hidden" name="lh_style" value="<?= h($L['lh_style']) ?>" data-lh-style-copy></form>
            <form method="post" action="<?= $postTo ?>" id="fmtPicDel<?= $slot ?>"><?= csrf_field() ?><input type="hidden" name="do" value="lhpic_remove"><input type="hidden" name="slot" value="<?= $slot ?>"><input type="hidden" name="lh_style" value="<?= h($L['lh_style']) ?>" data-lh-style-copy></form>
          <?php endforeach; ?>
          <?php if (!$hasLh): ?><form method="post" action="<?= $postTo ?>" id="fmtPutLh"><?= csrf_field() ?><input type="hidden" name="do" value="put_field"><input type="hidden" name="field" value="letterhead"></form><?php endif; ?>
          <?php if (!$hasSig): ?><form method="post" action="<?= $postTo ?>" id="fmtPutSig"><?= csrf_field() ?><input type="hidden" name="do" value="put_field"><input type="hidden" name="field" value="signatories"></form><?php endif; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($vt === 'positions'):
        $P = BrgyLayout::positions($t);
        $vals = BrgyLayout::values($ctx, $p ? Brgy::lines((int)$p['id']) : [], $L);
        $tableDefs = [];
        foreach (BrgyLayout::TABLES as $tk => $def) {
            $tableDefs[$tk] = ['label' => $def[0], 'cols' => array_map(fn($c) => str_replace("\n", ' ', $c[0]), $def[1])];
        }
        $data = ['P' => $P, 'fields' => BrgyLayout::fieldList(), 'tables' => $tableDefs, 'vals' => $vals, 'papers' => array_map(fn($x) => [$x[0], $x[1]], BrgyLayout::PAPERS),
            'bg' => $t['bg_file'] ? url($pageKey, ['a' => 'bg', 'id' => $id, 'v' => substr(md5((string)$t['bg_file']), 0, 8)]) : '', 'fonts' => BrgyLayout::FONTS]; ?>
      <div class="card mb-3"><div class="card-body">
        <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="do" value="mode">
          <span class="fw-semibold me-1">Forms of this template print</span>
          <div class="btn-group btn-group-sm" role="group">
            <input type="radio" class="btn-check" name="print_mode" id="pmL" value="layout" <?= $t['print_mode'] !== 'positions' ? 'checked' : '' ?> <?= $canEditT ? 'onchange="this.form.submit()"' : 'disabled' ?>><label class="btn btn-outline-primary" for="pmL"><i class="bi bi-file-text"></i> With the layout</label>
            <input type="radio" class="btn-check" name="print_mode" id="pmP" value="positions" <?= $t['print_mode'] === 'positions' ? 'checked' : '' ?> <?= $canEditT ? 'onchange="this.form.submit()"' : 'disabled' ?>><label class="btn btn-outline-primary" for="pmP"><i class="bi bi-grid-3x3"></i> On pre-printed paper</label>
          </div>
          <span class="small text-muted">On pre-printed paper only the values print, each where it is placed below, on the barangay's own pad.</span>
        </form>
      </div></div>
      <?= $projSel ?>
      <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" id="posForm"><?= csrf_field() ?><input type="hidden" name="do" value="positions_save"><input type="hidden" name="print_mode" value="<?= h($t['print_mode']) ?>">
        <input type="hidden" name="positions_json" id="posJson" value="">
        <div class="pos-bar">
          <?= select_field('paper', 'Paper', array_map(fn($x) => $x[2], BrgyLayout::PAPERS), $P['paper'], ['class' => 'pb-item', 'extra' => 'data-no-combo data-pos-set="paper"']) ?>
          <?= select_field('orientation', 'Orientation', ['portrait' => 'Portrait', 'landscape' => 'Landscape'], $P['orientation'], ['class' => 'pb-item', 'extra' => 'data-no-combo data-pos-set="orientation"']) ?>
          <div class="pb-item pb-sm" data-pos-custom><label class="form-label">W x H (mm)</label><div class="input-group input-group-sm"><input class="form-control" name="width_mm" value="<?= h((string)$P['width_mm']) ?>" data-pos-set="width_mm" aria-label="Width"><input class="form-control" name="height_mm" value="<?= h((string)$P['height_mm']) ?>" data-pos-set="height_mm" aria-label="Height"></div></div>
          <?= select_field('font', 'Font', array_combine(array_keys(BrgyLayout::FONTS), array_keys(BrgyLayout::FONTS)), $P['font'], ['class' => 'pb-item', 'extra' => 'data-no-combo data-pos-set="font"']) ?>
          <div class="pb-item pb-xs"><label class="form-label">Size (pt)</label><input class="form-control form-control-sm" name="font_size" value="<?= h((string)$P['font_size']) ?>" data-pos-set="font_size"></div>
          <div class="pb-item pb-sm"><label class="form-label">Offset right / down (mm)</label><div class="input-group input-group-sm"><input class="form-control" name="offset_x" value="<?= h((string)$P['offset_x']) ?>" aria-label="Offset right"><input class="form-control" name="offset_y" value="<?= h((string)$P['offset_y']) ?>" aria-label="Offset down"></div></div>
        </div>
        <div class="pos-wrap">
          <div class="pos-main">
            <div class="pos-tools">
              <div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-zoom="-1" aria-label="Smaller"><i class="bi bi-zoom-out"></i></button><button type="button" class="btn btn-outline-secondary" data-zoom="1" aria-label="Larger"><i class="bi bi-zoom-in"></i></button></div>
              <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" id="posShowBg" checked> <span class="form-check-label">Scan behind</span></label>
              <label class="form-check form-switch m-0 small"><input class="form-check-input" type="checkbox" id="posShowVals"> <span class="form-check-label">Values of the project</span></label>
              <span class="small text-muted ms-auto" id="posInfo">Drag a field to move it; the arrow keys move the chosen field by 0.5 mm.</span>
            </div>
            <div class="pos-scroll"><div class="pos-canvas" id="posCanvas"></div></div>
          </div>
          <aside class="pos-side">
            <div class="pos-add"><label class="form-label">Add a field</label><div class="input-group input-group-sm"><select class="form-select" id="posAddField" data-no-combo></select><button type="button" class="btn btn-primary" id="posAddBtn">Add</button></div>
              <div class="d-flex gap-1 mt-1"><button type="button" class="btn btn-sm btn-outline-secondary" id="posAddText">Fixed text</button><select class="form-select form-select-sm" id="posAddTable" data-no-combo><option value="">Add an item table</option></select></div></div>
            <div id="posProps" class="pos-props"><div class="small text-muted">Choose a field on the page to set its place, width, size and alignment.</div></div>
            <div class="pos-list" id="posList"></div>
          </aside>
        </div>
        <div class="sticky-actions d-flex flex-wrap gap-2">
          <?php if ($canEditT): ?><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save the positions</button><?php endif; ?>
          <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="<?= h(url($pageKey, ['a' => 'test', 'id' => $id, 'project_id' => $pid ?: null])) ?>"><i class="bi bi-rulers"></i> Test print (grid)</a>
          <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="<?= h(url($pageKey, ['a' => 'print', 'id' => $id, 'project_id' => $pid ?: null, 'mode' => 'positions'])) ?>"><i class="bi bi-printer"></i> Print with the project</a>
          <span class="small text-muted align-self-center">Save before printing: the test print uses the saved positions.</span>
        </div>
      </form>
      <?php if ($canEditT): ?>
      <div class="card mt-3"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="do" value="bg_upload">
          <div><label class="form-label">Scan of the pre-printed paper (JPG or PNG)</label><input class="form-control form-control-sm" type="file" name="bg" accept=".jpg,.jpeg,.png" required></div><button class="btn btn-sm btn-outline-primary">Upload</button></form>
        <?php if ($t['bg_file']): ?><form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="bg_remove"><button class="btn btn-sm btn-outline-danger">Remove the scan</button></form><?php endif; ?>
        <div class="small text-muted">Scan the blank form at 100% (no scaling). It shows only here, behind the fields, and never prints.</div>
      </div></div>
      <?php endif; ?>
      <script type="application/json" id="posData"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <?php else: ?>
      <div class="card"><ul class="list-group list-group-flush">
        <?php foreach ($versions as $v): ?><li class="list-group-item small"><div class="d-flex justify-content-between gap-2"><span><b>v<?= (int)$v['version_no'] ?></b> <?= h(fdatetime($v['changed_at'])) ?></span>
          <?php if ((int)$v['version_no'] !== (int)$t['version_no'] && $canEditT): ?><form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" data-confirm="Put version <?= (int)$v['version_no'] ?> back as the current text and format?"><?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>"><button class="btn btn-link btn-sm p-0">Put back</button></form><?php elseif ((int)$v['version_no'] === (int)$t['version_no']): ?><span class="text-muted">current</span><?php endif; ?></div>
          <div class="text-muted"><?= h($v['full_name']) ?><?= $v['change_note'] ? ': ' . h($v['change_note']) : '' ?></div></li><?php endforeach; ?>
        <?php if (!$versions): ?><li class="list-group-item small text-muted">Version <?= (int)$t['version_no'] ?>.</li><?php endif; ?>
      </ul></div>
    <?php endif; ?>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$rows = DB::all("SELECT t.*, (SELECT COUNT(*) FROM brgy_forms f WHERE f.template_id = t.id) AS uses FROM brgy_templates t"
    . ($q !== '' ? ' WHERE t.name LIKE :q OR t.code LIKE :q OR t.description LIKE :q' : '') . ' ORDER BY t.is_active DESC, t.sort_order, t.name', $q !== '' ? ['q' => "%$q%"] : []);
$byStage = [];
foreach ($rows as $r) {
    $byStage[$r['stage'] ?: 'internal'][] = $r;
}
layout_start('Proforma & Form Templates', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'New template', 'btn-primary', 'plus-lg', 'create')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-6"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, code, description" data-live-filter=".tpl-card" data-live-enter-submits></div>
</div></form>
<?php foreach (BRGY_TPL_STAGES as $st => $stLabel): if (empty($byStage[$st])) { continue; } ?>
  <h2 class="section-title"><?= h($stLabel) ?></h2>
  <div class="tpl-grid mb-3">
    <?php foreach ($byStage[$st] as $r): ?>
      <a class="tpl-card<?= $r['is_active'] ? '' : ' is-off' ?>" href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>">
        <span class="tpl-ico"><i class="bi bi-<?= h(['contract' => 'file-earmark-ruled', 'letter' => 'envelope-paper', 'internal' => 'clipboard-data', 'prescribed' => 'patch-check', 'receipt' => 'receipt'][$r['category']] ?? 'file-earmark-richtext') ?>"></i></span>
        <span class="tpl-body"><span class="tpl-name"><?= h($r['name']) ?></span>
          <span class="tpl-meta"><?= h(BRGY_TPL_CATEGORIES[$r['category']] ?? $r['category']) ?>, v<?= (int)$r['version_no'] ?>, <?= h(BrgyLayout::paperLabel(BrgyLayout::layout($r))) ?><?= $r['print_mode'] === 'positions' ? ', pre-printed paper' : '' ?><?= (int)$r['uses'] ? ', ' . (int)$r['uses'] . ' form(s)' : '' ?><?= $r['is_active'] ? '' : ', set aside' ?></span>
          <?php if ((int)$r['is_prescribed']): ?><span class="tpl-meta text-warning-emphasis">Prescribed form: check against the official version</span><?php elseif ($r['description']): ?><span class="tpl-meta"><?= h(mb_strimwidth($r['description'], 0, 110, '...')) ?></span><?php endif; ?></span></a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card"><div class="card-body text-muted">No templates.</div></div><?php endif; ?>
<?php
layout_end();
