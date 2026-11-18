<?php
declare(strict_types=1);

/**
 * Barangay Contracts: pieces shared by the pages of the module (phase track, figures, file boxes, workflow buttons ...).
 */

if (!Brgy::ready()) {
    layout_start('Barangay Contracts');
    echo '<div class="alert alert-warning">The Barangay Contracts tables are not in this database yet. Run database/migrations/2026_10_29_barangay_contracting.sql.</div>';
    layout_end();
    exit;
}

/** Options every page of the module passes to layout_start */
function brgy_layout(array $opt = []): array
{
    $opt['body_class'] = trim(($opt['body_class'] ?? '') . ' brgy-page');
    $opt['js'] = array_merge((array)($opt['js'] ?? []), ['js/brgy.js']);
    return $opt;
}

/** A POST action of a view page: runs it, then goes back with the message (errors keep their line breaks) */
function brgy_action(callable $fn, string $back): never
{
    try {
        $msg = $fn();
        flash('success brgy-msg', is_string($msg) && $msg !== '' ? $msg : 'Saved.');
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        flash('danger brgy-msg', $e->getMessage());
    }
    redirect($back);
}

/** One uploaded file of a form field, or null */
function brgy_file(string $field): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return $f;
}

/** A file box: drop a file on it or tap to choose */
function brgy_file_field(string $name, string $label, string $hint = '', string $cls = 'col-12', bool $required = false, ?string $accept = null): string
{
    // every type the vault accepts (PDF, pictures, Word, Excel, PowerPoint, Publisher, OpenDocument, CSV, text) unless narrowed
    $accept ??= BrgyDocs::accept();
    $id = 'f_' . preg_replace('/\W/', '', $name) . '_' . bin2hex(random_bytes(2));
    return '<div class="' . h($cls) . '"><label class="form-label" for="' . h($id) . '">' . h($label) . '</label>'
        . '<label class="dropzone" data-dropzone><input type="file" id="' . h($id) . '" name="' . h($name) . '"' . ($required ? ' required' : '')
        . ' accept="' . h($accept) . '">'
        . '<i class="bi bi-cloud-arrow-up"></i><span class="dz-text">Drop a file here or tap to choose</span><span class="dz-name"></span></label>'
        . ($hint !== '' ? '<div class="form-text">' . h($hint) . '</div>' : '') . '</div>';
}

function brgy_user_select(string $name, string $label, $value, string $cls = 'col-md-4', string $blank = ''): string
{
    return select_field($name, $label, Brgy::users(), $value, ['blank' => $blank, 'class' => $cls, 'extra' => 'data-no-combo']);
}

/** Link to a vault document (shown as text when the user cannot open the vault) */
function brgy_doc_link($doc, ?string $text = null): string
{
    if (!$doc) {
        return '';
    }
    if (!is_array($doc)) {
        $doc = DB::row('SELECT id, title, confidential, project_id FROM brgy_documents WHERE id = :id', ['id' => (int)$doc]);
        if (!$doc) {
            return '';
        }
    }
    $text ??= (string)$doc['title'];
    if (!Access::page('brgy.vault')) {
        return h($text);
    }
    return '<a href="' . h(url('brgy.vault', ['a' => 'view', 'id' => $doc['id']])) . '"><i class="bi bi-file-earmark-text"></i> ' . h($text) . '</a>';
}

function brgy_project_link(array $p, ?string $text = null): string
{
    return '<a href="' . h(url('brgy.projects', ['a' => 'view', 'id' => $p['project_id'] ?? $p['id']])) . '">' . h($text ?? ($p['project_no'] ?? '')) . '</a>';
}

/** Ledger transaction link with its number (and "void" when it was voided) */
function brgy_txn($txnId, ?string $label = null): string
{
    if (!$txnId) {
        return '';
    }
    $t = Txn::load((int)$txnId);
    if (!$t) {
        return '<span class="text-muted">#' . (int)$txnId . ' (deleted)</span>';
    }
    return Txn::link($t, $label ?? (Txn::label($t['txn_type']) . ' ' . $t['txn_no'])) . ((int)$t['is_voided'] ? ' <span class="st st-muted">void</span>' : '');
}

/** The 12 phases as a track, the current one marked */
function brgy_phase_track(array $p): string
{
    $cur = Brgy::phaseIndex($p['phase']);
    $ended = in_array($p['status'], ['lost', 'no_bid', 'cancelled'], true);
    $html = '<ol class="phase-track' . ($ended ? ' is-ended' : '') . '" aria-label="Phases">';
    $i = 0;
    foreach (Brgy::PHASES as $code => [$num, $name]) {
        $state = $i < $cur || ($p['status'] === 'closed' && $i <= $cur) ? 'done' : ($i === $cur ? 'now' : 'next');
        $html .= '<li class="pt-' . $state . '"' . ($state === 'now' ? ' aria-current="step"' : '') . '><span class="pt-dot">' . ($state === 'done' ? '<i class="bi bi-check-lg"></i>' : (int)$num) . '</span><span class="pt-name">' . h($name) . '</span></li>';
        $i++;
    }
    return $html . '</ol>';
}

/** A row of figures (label => [value, tone, hint]) */
function brgy_figures(array $items): string
{
    $html = '<div class="fig-row fig-n' . count($items) . '">';
    foreach ($items as $label => $x) {
        [$value, $tone, $hint] = array_pad((array)$x, 3, '');
        $html .= '<div class="fig' . ($tone ? ' fig-' . h($tone) : '') . '"><div class="fig-label">' . h($label) . '</div><div class="fig-value">' . $value . '</div>' . ($hint !== '' ? '<div class="fig-hint">' . $hint . '</div>' : '') . '</div>';
    }
    return $html . '</div>';
}

/** A small modal with a reason box and a submit button for one action */
function brgy_reason_modal(string $id, string $title, string $action, string $do, array $hidden = [], string $btnClass = 'btn-primary', bool $required = true, string $help = '', string $extra = ''): string
{
    $fields = '';
    foreach ($hidden as $k => $v) {
        $fields .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    return '<div class="modal fade" id="' . h($id) . '" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="' . h($action) . '" enctype="multipart/form-data" data-no-class-check>'
        . csrf_field() . '<input type="hidden" name="do" value="' . h($do) . '">' . $fields
        . '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">' . ($help !== '' ? '<p class="small text-muted">' . h($help) . '</p>' : '') . $extra
        . '<label class="form-label">' . ($required ? 'Reason / remarks (required)' : 'Remarks (optional)') . '</label><textarea class="form-control" name="remarks" rows="2"' . ($required ? ' required' : '') . '></textarea></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn ' . h($btnClass) . '">' . h($title) . '</button></div></form></div></div>';
}

/**
 * The maker / checker / approver buttons of a controlled record, with what blocks the ones the user cannot press.
 * Each button opens a small form posting do=<prefix>_<action>.
 */
function brgy_step_buttons(string $type, array $obj, ?array $project, string $action, string $prefix, array $hidden = []): string
{
    $col = ['bid' => 'bid_status', 'billing' => 'status', 'change_order' => 'status', 'form' => 'status'][$type];
    $status = (string)$obj[$col];
    $labels = ['submit' => ['Send for review', 'btn-primary', 'send'], 'review' => ['Mark reviewed', 'btn-success', 'check2-square'], 'approve' => ['Approve', 'btn-success', 'patch-check'],
        'return' => ['Return for correction', 'btn-outline-danger', 'arrow-return-left'], 'reopen' => ['Reopen', 'btn-outline-secondary', 'arrow-counterclockwise']];
    $html = '';
    $modals = '';
    $why = [];
    foreach ($labels as $act => [$label, $cls, $icon]) {
        [$ok, $reason] = Brgy::canStep($type, $obj, $act, $project);
        if (!$ok) {
            if (in_array($act, ['review', 'approve'], true) && $reason !== '' && !str_starts_with($reason, 'Not possible')) {
                $why[] = $reason;
            }
            continue;
        }
        $mid = 'm_' . $prefix . '_' . $act . '_' . (int)$obj['id'];
        $html .= '<button type="button" class="btn btn-sm ' . $cls . '" data-bs-toggle="modal" data-bs-target="#' . $mid . '"><i class="bi bi-' . $icon . '"></i> ' . h($label) . '</button> ';
        $modals .= brgy_reason_modal($mid, $label, $action, $prefix . '_' . $act, $hidden, str_contains($cls, 'danger') ? 'btn-danger' : 'btn-primary', in_array($act, ['return', 'reopen'], true),
            ['submit' => 'The record goes to the checker; you cannot review or approve it yourself.', 'review' => 'You confirm you checked it. The approval has to come from another person.',
                'approve' => 'Approving fixes the record. The approver must not be the preparer or the checker.', 'return' => 'The preparer sees your reason and corrects the record.',
                'reopen' => 'The record goes back to draft and has to be approved again.'][$act]);
    }
    $who = Brgy::makers($type, (int)$obj['id']);
    $line = '<div class="small text-muted mt-1">' . h(Brgy::WF[$status] ?? $status)
        . ($who['maker'] ? ', prepared by ' . h(Brgy::userName($who['maker'])) : '') . ($who['checker'] ? ', reviewed by ' . h(Brgy::userName($who['checker'])) : '') . '</div>';
    if ($why && in_array($status, ['for_review', 'reviewed'], true)) {
        $line .= '<div class="small text-muted">' . h(implode(' ', array_unique($why))) . '</div>';
    }
    return '<div class="d-flex flex-wrap gap-1 align-items-center">' . $html . '</div>' . $line . $modals;
}

/** History of the maker / checker / approver steps of a record */
function brgy_workflow_list(string $type, int $id): string
{
    $rows = DB::all('SELECT w.*, u.full_name FROM brgy_workflow w LEFT JOIN users u ON u.id = w.user_id WHERE w.object_type = :t AND w.object_id = :id ORDER BY w.id', ['t' => $type, 'id' => $id]);
    if (!$rows) {
        return '<div class="small text-muted">No steps yet.</div>';
    }
    $html = '<ul class="wf-list">';
    foreach ($rows as $r) {
        $html .= '<li><span class="wf-when">' . h(fdatetime($r['created_at'])) . '</span> <b>' . h($r['full_name']) . '</b> '
            . h(str_replace('_', ' ', $r['action'])) . ($r['to_status'] ? ' &rarr; ' . h(Brgy::WF[$r['to_status']] ?? $r['to_status']) : '')
            . ($r['on_behalf_of'] ? ' <span class="text-muted">(for ' . h(Brgy::userName((int)$r['on_behalf_of'])) . ')</span>' : '')
            . ($r['remarks'] ? '<div class="small text-muted">' . h($r['remarks']) . '</div>' : '') . '</li>';
    }
    return $html . '</ul>';
}

/**
 * A printable document page (delivery receipt, form, proforma): the paper size and orientation go to the printer,
 * a draft carries the DRAFT mark across the page.
 */
function brgy_print_page(string $title, string $body, string $paper = 'A4', string $orientation = 'portrait', bool $draft = false, string $back = '', string $extraTools = '', ?array $L = null): never
{
    $L ??= BrgyLayout::layout(['paper' => $paper, 'orientation' => $orientation]);
    BrgyLayout::printPage($title, $body, $L, ['draft' => $draft, 'back' => $back, 'tools' => $extraTools]);
}

/** The steps of the SOP as a track (the head of a transaction that follows the SOP) */
function brgy_sop_track(array $p): string
{
    if (!BrgySop::uses($p) || !BrgySop::ready()) {
        return '';
    }
    $S = BrgySop::state($p);
    $html = '<ol class="phase-track sop-track' . ($S['ended'] ? ' is-ended' : '') . '" aria-label="Steps of the SOP">';
    foreach ($S['steps'] as $code => $s) {
        $state = $s['state'] !== 'pending' ? 'done' : ($code === $S['current'] ? 'now' : 'next');
        $html .= '<li class="pt-' . $state . '"' . ($state === 'now' ? ' aria-current="step"' : '') . '><a href="' . h(url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'process'])) . '#step-' . h($code) . '">'
            . '<span class="pt-dot">' . ($state === 'done' ? '<i class="bi bi-check-lg"></i>' : (int)$s['step_no']) . '</span><span class="pt-name">' . h($s['title']) . '</span></a></li>';
    }
    return $html . '</ol>';
}

/** "3 days ago", "in 5 days", "today" */
function brgy_rel(?string $date): string
{
    if (!$date) {
        return '';
    }
    $days = (int)round((strtotime(substr($date, 0, 10)) - strtotime(today())) / 86400);
    return $days === 0 ? 'today' : ($days > 0 ? 'in ' . $days . ' day' . ($days === 1 ? '' : 's') : abs($days) . ' day' . ($days === -1 ? '' : 's') . ' ago');
}

/** Value for a datetime-local box */
function brgy_dtl($v): string
{
    return $v ? date('Y-m-d\TH:i', strtotime((string)$v)) : '';
}
