<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Document Vault
 * Every paper of the contracting work: the company's eligibility papers (reused on every bid), and the papers of each
 * project. Each file keeps its type, validity, checksum and versions; it is reviewed before it counts, and a final
 * copy is locked. Confidential papers (financial bids, costing) open only for those who see costs.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.vault';
$a = g('a', 'list');
$id = gint('id');
$tab = (string)g('tab', 'company');

// ------------------------------------------------------------------ the file itself
if (in_array($a, ['file', 'download'], true) && $id) {
    $d = BrgyDocs::load($id);
    if (!$d) {
        http_response_code(404);
        exit('Document not found.');
    }
    BrgyDocs::send($d, $a === 'download');
}

// ------------------------------------------------------------------ the preview of a Word, Excel, PowerPoint or Publisher file
// quick view: HTML made by the program's own readers (loaded into the document page); page view: a PDF made by LibreOffice
if (in_array($a, ['preview', 'pdfview'], true) && $id) {
    $d = BrgyDocs::load($id);
    if (!$d) {
        http_response_code(404);
        exit('Document not found.');
    }
    if ($a === 'preview' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    BrgyDocs::preview($d, $a === 'pdfview');
}

// ------------------------------------------------------------------ upload (company papers and loose documents)
if ($a === 'upload' && is_post()) {
    $back = url($pageKey, ['tab' => in_array(p('back'), ['company', 'all'], true) ? p('back') : 'company']);
    brgy_action(function () {
        Access::require('brgy.vault', 'create');
        $scope = p('scope') === 'company' ? 'company' : (pint('project_id') ? 'project' : 'company');
        $docId = BrgyDocs::store(brgy_file('file') ?? ['error' => UPLOAD_ERR_NO_FILE], ['scope' => $scope, 'project_id' => $scope === 'project' ? pint('project_id') : null, 'doc_type' => p('doc_type') ?: 'other',
            'title' => p('title'), 'doc_no' => p('doc_no'), 'issued_by' => p('issued_by'), 'issue_date' => p('issue_date'), 'valid_until' => p('valid_until'), 'tags' => p('tags'),
            'confidential' => p('confidential') === '1', 'notes' => p('notes'), 'replaces' => pint('replaces')]);
        if ($scope === 'company') {
            // a company paper is linked at once to the checklists still waiting for one of its type (after its review)
            return 'Filed (#' . $docId . '). It counts on the checklists once it is reviewed.';
        }
        return 'Filed (#' . $docId . ').';
    }, $back);
}

// ------------------------------------------------------------------ actions on one document
if ($a === 'view' && $id && is_post()) {
    $do = (string)p('do');
    $r = trim((string)p('remarks'));
    brgy_action(function () use ($do, $id, $r) {
        $d = BrgyDocs::load($id);
        if (!$d) {
            fail('Document not found.');
        }
        if (!BrgyDocs::canSee($d)) {
            fail('This document is confidential.');
        }
        switch ($do) {
            case 'approved':
            case 'returned':
            case 'final':
                Access::require('brgy.vault', 'edit');
                BrgyDocs::review($d, $do, $r);
                return ['approved' => 'Approved: it counts on the checklists.', 'returned' => 'Returned: the person who filed it sees your remarks.', 'final' => 'Final: approved and locked.'][$do];
            case 'version':
                Access::require('brgy.vault', 'create');
                $nid = BrgyDocs::store(brgy_file('file') ?? ['error' => UPLOAD_ERR_NO_FILE], ['replaces' => $id, 'valid_until' => p('valid_until'), 'issue_date' => p('issue_date'), 'doc_no' => p('doc_no') ?: null, 'notes' => $r]);
                redirect(url('brgy.vault', ['a' => 'view', 'id' => $nid]));
            case 'meta':
                Access::require('brgy.vault', 'edit');
                if ((int)$d['is_locked'] === 1) {
                    fail('The document is final and locked. File a new version instead.');
                }
                $set = ['title' => mb_substr(trim((string)p('title')) ?: $d['title'], 0, 200), 'doc_type' => p('doc_type') ?: $d['doc_type'], 'doc_no' => mb_substr(trim((string)p('doc_no')), 0, 80) ?: null,
                    'issued_by' => mb_substr(trim((string)p('issued_by')), 0, 160) ?: null, 'issue_date' => pdate('issue_date'), 'valid_until' => pdate('valid_until'),
                    'tags' => mb_substr(trim((string)p('tags')), 0, 255) ?: null, 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null];
                if (Access::viewCosts()) {
                    $set['confidential'] = p('confidential') === '1' ? 1 : 0;
                }
                $changes = [];
                foreach ($set as $k => $v) {
                    if ((string)$d[$k] !== (string)$v) {
                        $changes[] = ['field' => $k, 'old' => $d[$k], 'new' => $v];
                    }
                }
                DB::update('brgy_documents', $set, 'id = :id', ['id' => $id]);
                Brgy::log('edit', 'document', $id, (string)$set['title'], ['changes' => $changes, 'page_key' => 'brgy.vault']);
                return 'Details saved.';
            case 'void':
                Access::require('brgy.vault', 'delete');
                if ($r === '') {
                    fail('Enter the reason.');
                }
                if ((int)DB::value("SELECT COUNT(*) FROM brgy_forms WHERE document_id = :d AND status = 'final'", ['d' => $id])) {
                    fail('This is the final copy of a prepared form; reopen the form instead.');
                }
                DB::update('brgy_documents', ['status' => 'void', 'is_current' => 0, 'review_remarks' => mb_substr($r, 0, 255)], 'id = :id', ['id' => $id]);
                DB::run("UPDATE brgy_project_reqs SET document_id = NULL, status = 'missing', updated_at = :u WHERE document_id = :d", ['d' => $id, 'u' => date('Y-m-d H:i:s')]);
                Brgy::log('void', 'document', $id, (string)$d['title'], ['reason' => $r, 'page_key' => 'brgy.vault']);
                return 'Document voided. Checklist items that used it are missing again.';
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['a' => 'view', 'id' => $id]));
}

$uploadForm = function (string $scope, ?int $projectId = null, string $back = '') use ($pageKey): string {
    $types = $scope === 'company' ? Brgy::docTypes(true) + ['other' => 'Other'] : Brgy::docTypes();
    $projects = DB::pairs("SELECT id, CONCAT(project_no, ' - ', LEFT(title, 60)) FROM brgy_projects WHERE status IN ('open', 'awarded') ORDER BY project_no DESC");
    return '<form class="card" method="post" action="' . h(url($pageKey, ['a' => 'upload'])) . '" enctype="multipart/form-data" data-no-class-check>' . csrf_field()
        . '<input type="hidden" name="scope" value="' . h($scope) . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<div class="card-header"><i class="bi bi-cloud-arrow-up"></i> ' . ($scope === 'company' ? 'File a company paper' : 'File a document') . '</div><div class="card-body row g-2">'
        . brgy_file_field('file', 'File', 'PDF, pictures, Word, Excel, PowerPoint, Publisher (old and new versions). Up to ' . Brgy::setting('brgy_upload_max_mb') . ' MB.', 'col-12', true)
        . select_field('doc_type', 'Type', $types, $scope === 'company' ? 'mayors_permit' : 'other', ['class' => 'col-12'])
        . ($scope !== 'company' ? select_field('project_id', 'Project', $projects, $projectId, ['blank' => '', 'class' => 'col-12']) : '')
        . input_field('title', 'Title', '', ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="e.g. Mayor\'s Permit 2026"'])
        . input_field('doc_no', 'Document no.', '', ['class' => 'col-6']) . input_field('issued_by', 'Issued by', '', ['class' => 'col-6'])
        . input_field('issue_date', 'Issued on', '', ['type' => 'date', 'class' => 'col-6']) . input_field('valid_until', 'Valid until', '', ['type' => 'date', 'class' => 'col-6'])
        . (Access::viewCosts() ? check_field('confidential', 'Confidential (financial bid, costing)', 0, 'col-12') : '')
        . '<div class="col-12 form-text">' . ($scope === 'company' ? 'Company papers (registrations, permits, tax clearance, PhilGEPS, financial statements) are filed once and linked to every bid that asks for them while they are valid.' : '') . '</div>'
        . '</div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> File</button></div></form>';
};

// ------------------------------------------------------------------ one document
if ($a === 'view' && $id) {
    $d = DB::row('SELECT d.*, u.full_name AS uploader, r.full_name AS reviewer, p.project_no FROM brgy_documents d LEFT JOIN users u ON u.id = d.uploaded_by LEFT JOIN users r ON r.id = d.reviewed_by
                   LEFT JOIN brgy_projects p ON p.id = d.project_id WHERE d.id = :id', ['id' => $id]);
    if (!$d) {
        flash('warning', 'Document not found.');
        redirect(url($pageKey));
    }
    if (!BrgyDocs::canSee($d)) {
        Access::denyPage($pageKey, 'open a confidential document on');
    }
    Brgy::log('view', 'document', $id, (string)$d['title'], ['page_key' => $pageKey]);
    $ext = strtolower(pathinfo((string)$d['file_name'], PATHINFO_EXTENSION));
    $versions = BrgyDocs::versions($d);
    $usedBy = DB::all('SELECT r.name, r.project_id, p.project_no FROM brgy_project_reqs r JOIN brgy_projects p ON p.id = r.project_id WHERE r.document_id = :d ORDER BY p.project_no', ['d' => $id]);
    $canReview = Access::can($pageKey, 'edit') && (int)$d['is_locked'] === 0 && in_array($d['status'], ['for_review', 'approved', 'returned'], true) && (int)$d['is_current'] === 1;
    $actions = btn_link(url($pageKey, ['a' => 'file', 'id' => $id]), 'Open', 'btn-outline-secondary', 'box-arrow-up-right') . ' '
        . btn_link(url($pageKey, ['a' => 'download', 'id' => $id]), 'Download', 'btn-outline-secondary', 'download') . ' '
        . ($d['status'] !== 'void' && Access::can($pageKey, 'delete') ? '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mVoid"><i class="bi bi-x-circle"></i> Void</button> ' : '')
        . ($d['project_id'] ? btn_link(url('brgy.projects', ['a' => 'view', 'id' => $d['project_id'], 'tab' => 'documents']), (string)$d['project_no'], 'btn-outline-secondary', 'briefcase') : btn_link(url($pageKey, ['tab' => 'company']), 'Company papers', 'btn-outline-secondary', 'building'));
    layout_start(mb_strimwidth((string)$d['title'], 0, 80, '...'), brgy_layout(['actions' => $actions]));
    $exp = $d['valid_until'] && $d['valid_until'] < today();
    echo brgy_figures(['Status' => [Brgy::st($d['status'] === 'for_review' ? 'pending' : $d['status'], Brgy::WF[$d['status']] ?? ucfirst($d['status'])), '', (int)$d['is_locked'] ? 'locked' : ''],
        'Type' => [h(Brgy::docTypeName($d['doc_type']))], 'Valid until' => [h(fdate($d['valid_until']) ?: 'no expiry'), $exp ? 'bad' : ($d['valid_until'] && $d['valid_until'] <= date('Y-m-d', strtotime('+30 days')) ? 'warn' : ''), $d['valid_until'] ? h(brgy_rel($d['valid_until'])) : ''],
        'Version' => [(string)(int)$d['version_no'], '', (int)$d['is_current'] ? 'current' : 'replaced by a newer version']]);
    ?>
    <div class="row g-3">
      <div class="col-xl-7">
        <?php
        $kind = OfficePreview::kind($ext);
        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        $formId = !$d['file_name'] ? BrgyDocs::formOf($id) : null;
        $conv = $kind !== '' ? OfficePreview::converter() : null;
        $quickOk = in_array($ext, OfficePreview::BUILT_IN, true);
        $fileUrl = url($pageKey, ['a' => 'file', 'id' => $id]);
        $pageUrl = url($pageKey, ['a' => 'pdfview', 'id' => $id]);
        [$icoKind, $ico, $what] = match (true) {
            (bool)$formId => ['form', 'file-earmark-text', 'Form made in the program'],
            $kind !== '' => [$kind, OfficePreview::icon($ext), OfficePreview::label($ext)],
            $ext === 'pdf' => ['pdf', 'file-earmark-pdf', 'PDF document'],
            $isImg => ['image', 'file-earmark-image', 'Picture (' . strtoupper($ext) . ')'],
            default => ['', 'file-earmark', strtoupper($ext) . ' file'],
        };
        ?>
        <div class="card mb-3 op-card"<?php if ($kind !== '' && $d['file_name']): ?> data-op-preview data-quick="<?= h(url($pageKey, ['a' => 'preview', 'id' => $id])) ?>" data-page="<?= h($pageUrl) ?>"
             data-mode="<?= $quickOk || !$conv ? 'quick' : 'page' ?>" data-page-ok="<?= $conv ? '1' : '0' ?>"<?php endif; ?>><div class="card-body">
          <div class="op-bar">
            <div class="op-what"><span class="op-ico k-<?= h($icoKind) ?>"><i class="bi bi-<?= h($ico) ?>"></i></span>
              <div class="min-w-0"><div class="op-name"><?= h($d['original_name'] ?: $d['title']) ?></div>
                <div class="op-sub"><?= h($what) ?><?= $d['file_name'] ? ', ' . number_format(max(1, (int)$d['file_size'] / 1024), 0) . ' KB' : '' ?></div></div></div>
            <?php if ($kind !== '' && $d['file_name']): ?>
            <div class="op-modes" role="group" aria-label="How the file is shown">
              <button type="button" class="btn" data-op-mode="page" aria-pressed="false"<?= $conv ? '' : ' disabled title="Needs LibreOffice on the server (Contracting Settings)"' ?>><i class="bi bi-file-earmark-richtext"></i> Page view</button>
              <button type="button" class="btn" data-op-mode="quick" aria-pressed="false"><i class="bi bi-lightning-charge"></i> Quick view</button>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($formId): ?>
            <div class="op-stage"><div class="op-msg"><i class="bi bi-file-earmark-text"></i>Made in the program: the final copy is the prepared form itself.
              <div class="d-flex flex-wrap gap-2 justify-content-center mt-3"><?= page_link('brgy.forms', ['a' => 'print', 'id' => $formId], 'Print', 'btn-outline-primary', 'printer', 'print') ?> <?= page_link('brgy.forms', ['a' => 'view', 'id' => $formId], 'Open the form', 'btn-outline-secondary', 'file-earmark-text') ?></div></div></div>
          <?php elseif ($isImg): ?>
            <div class="op-stage op-picture"><img src="<?= h($fileUrl) ?>" alt="<?= h($d['title']) ?>"></div>
          <?php elseif ($ext === 'pdf'): ?>
            <div class="op-stage op-pdf"><object data="<?= h($fileUrl) ?>" type="application/pdf"><div class="op-msg"><i class="bi bi-file-earmark-pdf"></i>This browser does not show PDFs inside the page. <a href="<?= h($fileUrl) ?>" target="_blank" rel="noopener">Open the PDF</a></div></object></div>
          <?php elseif ($kind !== '' && $d['file_name']): ?>
            <div class="op-stage" data-op-stage aria-live="polite"><div class="op-loading"><span class="spinner-border spinner-border-sm" role="status"></span> Opening the file</div></div>
            <div class="op-hint" data-op-hint="quick" hidden><i class="bi bi-info-circle"></i><span>Quick view: the program reads the file itself.
              <?= $conv ? 'The layout can differ a little from ' . h(ucfirst($kind === 'powerpoint' ? 'PowerPoint' : $kind)) . '; the page view shows the exact pages.' : 'The layout can differ a little; for the exact pages, install LibreOffice on the server (see Contracting Settings).' ?></span></div>
            <div class="op-hint" data-op-hint="page" hidden><i class="bi bi-info-circle"></i><span>Page view: the file as it prints, made by LibreOffice (the first opening takes a few seconds).
              <a href="<?= h($pageUrl) ?>" target="_blank" rel="noopener">Open the pages in a new tab</a></span></div>
            <noscript><div class="op-msg">Turn on JavaScript to see the file here, or <a href="<?= h(url($pageKey, ['a' => 'download', 'id' => $id])) ?>">download it</a>.</div></noscript>
          <?php else: ?>
            <div class="op-stage"><div class="op-msg"><i class="bi bi-file-earmark"></i>This kind of file has no preview.<div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['a' => 'download', 'id' => $id])) ?>"><i class="bi bi-download"></i> Download</a></div></div></div>
          <?php endif; ?>
        </div></div>
        <?php if ($canReview): // v39: the person who filed it may review it (two roles) ?>
        <form class="card mb-3" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>"><?= csrf_field() ?>
          <div class="card-header"><i class="bi bi-clipboard-check"></i> Review</div><div class="card-body">
            <label class="form-label">Remarks (required to return it)</label><textarea class="form-control form-control-sm mb-2" name="remarks" rows="2"></textarea>
            <div class="d-flex flex-wrap gap-2"><button class="btn btn-sm btn-success" name="do" value="approved"><i class="bi bi-check2"></i> Approve</button>
              <button class="btn btn-sm btn-outline-success" name="do" value="final"><i class="bi bi-lock"></i> Approve as final (lock)</button>
              <button class="btn btn-sm btn-outline-danger" name="do" value="returned"><i class="bi bi-arrow-return-left"></i> Return</button></div>
            <div class="form-text">Check it is the right paper, legible, signed where needed, and that the validity date matches the paper.</div></div></form>
        <?php elseif ($d['status'] === 'for_review'): ?>
          <div class="alert alert-info small">Waiting for review by another person with the Document Vault Edit right.</div>
        <?php endif; ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-clock-history"></i> Versions</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Version</th><th>Filed</th><th>Valid until</th><th>Status</th><th>Checksum (SHA-256)</th></tr></thead>
          <?php foreach ($versions as $v): ?><tr class="<?= (int)$v['id'] === $id ? 'table-active' : '' ?>"><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $v['id']])) ?>">v<?= (int)$v['version_no'] ?></a></td>
            <td class="small"><?= h(fdatetime($v['uploaded_at'])) ?><div class="text-muted"><?= h($v['uploaded_by_name']) ?></div></td><td class="small"><?= h(fdate($v['valid_until'])) ?></td><td><?= Brgy::st($v['status'] === 'for_review' ? 'pending' : $v['status'], Brgy::WF[$v['status']] ?? ucfirst($v['status'])) ?></td>
            <td class="small text-muted text-break"><?= $v['sha256'] ? '<code>' . h(substr((string)$v['sha256'], 0, 16)) . '...</code>' : 'made in the program' ?></td></tr><?php endforeach; ?>
        </table></div>
          <?php if ((int)$d['is_current'] === 1 && $d['status'] !== 'void' && $d['file_name'] && Access::can($pageKey, 'create')): ?>
          <form class="card-footer row g-2" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="version">
            <div class="col-12 small fw-semibold">File a new version (a renewed permit, a corrected copy)</div>
            <?= brgy_file_field('file', 'New file', '', 'col-md-6', true) ?>
            <?= input_field('issue_date', 'Issued on', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
            <?= input_field('valid_until', 'Valid until', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
            <?= input_field('doc_no', 'Document no.', '', ['class' => 'col-md-6']) ?>
            <div class="col-md-6"><label class="form-label">What changed</label><input class="form-control form-control-sm" name="remarks"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload"></i> File the new version</button> <span class="small text-muted">Checklists that used this version move to the new one and need its review.</span></div></form>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-xl-5">
        <form class="card mb-3" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="meta">
          <?php $ro = !Access::can($pageKey, 'edit') || (int)$d['is_locked'] === 1 || $d['status'] === 'void' ? 'disabled' : ''; ?>
          <div class="card-header"><i class="bi bi-info-circle"></i> Details</div><div class="card-body row g-2">
            <?= input_field('title', 'Title', $d['title'], ['class' => 'col-12', 'extra' => $ro]) ?>
            <?= select_field('doc_type', 'Type', Brgy::docTypes(), $d['doc_type'], ['class' => 'col-12', 'extra' => $ro]) ?>
            <?= input_field('doc_no', 'Document no.', $d['doc_no'], ['class' => 'col-6', 'extra' => $ro]) ?>
            <?= input_field('issued_by', 'Issued by', $d['issued_by'], ['class' => 'col-6', 'extra' => $ro]) ?>
            <?= input_field('issue_date', 'Issued on', $d['issue_date'], ['type' => 'date', 'class' => 'col-6', 'extra' => $ro]) ?>
            <?= input_field('valid_until', 'Valid until', $d['valid_until'], ['type' => 'date', 'class' => 'col-6', 'extra' => $ro]) ?>
            <?= input_field('tags', 'Tags', $d['tags'], ['class' => 'col-12', 'extra' => $ro]) ?>
            <?= input_field('notes', 'Notes', $d['notes'], ['class' => 'col-12', 'extra' => $ro]) ?>
            <?php if (Access::viewCosts()): ?><div class="col-12"><label class="form-check"><input type="hidden" name="confidential" value="0"><input class="form-check-input" type="checkbox" name="confidential" value="1" <?= (int)$d['confidential'] ? 'checked' : '' ?> <?= $ro ?>> <span class="form-check-label">Confidential</span></label></div><?php endif; ?>
            <div class="col-12 small text-muted">
              <?= h(ucfirst($d['scope'])) ?> document<?= $d['project_no'] ? ' of ' . brgy_project_link(['id' => $d['project_id'], 'project_no' => $d['project_no']]) : '' ?>. File <?= h($d['original_name']) ?> (<?= number_format((int)$d['file_size'] / 1024, 0) ?> KB), filed <?= h(fdatetime($d['uploaded_at'])) ?> by <?= h($d['uploader']) ?>.
              <?= $d['reviewer'] ? 'Reviewed by ' . h($d['reviewer']) . ' ' . h(fdatetime($d['reviewed_at'])) . ($d['review_remarks'] ? ': ' . h($d['review_remarks']) : '') . '.' : '' ?>
              <?= $d['scan_status'] ? 'Scan: ' . h($d['scan_status']) . '.' : '' ?></div>
          </div><?php if (!$ro): ?><div class="card-footer"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save details</button></div><?php endif; ?></form>
        <?php if ($usedBy): ?><div class="card"><div class="card-header"><i class="bi bi-link-45deg"></i> Used on checklists</div><ul class="list-group list-group-flush">
          <?php foreach ($usedBy as $u): ?><li class="list-group-item small"><?= brgy_project_link(['id' => $u['project_id'], 'project_no' => $u['project_no']]) ?>: <?= h($u['name']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      </div>
    </div>
    <?= brgy_reason_modal('mVoid', 'Void the document', url($pageKey, ['a' => 'view', 'id' => $id]), 'void', [], 'btn-danger', true, 'The file stays in storage and in the audit trail; checklist items that used it become missing again.') ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ lists
$tabs = ['company' => 'Company papers', 'signed' => 'Signed copies', 'review' => 'For review', 'expiring' => 'Expiring and expired', 'all' => 'All documents'];
$counts = ['review' => (int)DB::value("SELECT COUNT(*) FROM brgy_documents WHERE status = 'for_review' AND is_current = 1"),
    'expiring' => (int)DB::value("SELECT COUNT(*) FROM brgy_documents WHERE is_current = 1 AND status <> 'void' AND valid_until IS NOT NULL AND valid_until <= :d", ['d' => date('Y-m-d', strtotime('+' . max(1, (int)Brgy::setting('brgy_alert_days')) . ' days'))])];
layout_start('Document Vault', brgy_layout());
?>
<ul class="nav nav-tabs mb-0">
  <?php foreach ($tabs as $k => $lbl): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['tab' => $k])) ?>"><?= h($lbl) ?><?= !empty($counts[$k]) ? ' <span class="tab-count' . ($k === 'expiring' ? ' is-bad' : '') . '">' . (int)$counts[$k] . '</span>' : '' ?></a></li><?php endforeach; ?>
</ul>
<div class="tab-body">
<?php
$docRow = function (array $d, bool $showProject = true): string {
    $exp = $d['valid_until'] && $d['valid_until'] < today();
    $soon = !$exp && $d['valid_until'] && $d['valid_until'] <= date('Y-m-d', strtotime('+' . max(1, (int)Brgy::setting('brgy_alert_days')) . ' days'));
    return '<tr class="' . ($d['status'] === 'void' ? 'text-muted' : '') . '"><td class="wrap-link">' . (BrgyDocs::canSee($d) ? brgy_doc_link($d) : '<span class="text-muted"><i class="bi bi-lock"></i> Confidential</span>')
        . ((int)$d['version_no'] > 1 ? ' <span class="small text-muted">v' . (int)$d['version_no'] . '</span>' : '') . ((int)$d['is_locked'] ? ' <i class="bi bi-lock-fill text-muted" title="Final"></i>' : '')
        . ($d['doc_no'] ? '<div class="small text-muted">' . h($d['doc_no']) . ($d['issued_by'] ? ', ' . h($d['issued_by']) : '') . '</div>' : '') . '</td>'
        . '<td class="small">' . h(Brgy::docTypeName($d['doc_type'])) . '</td>'
        . ($showProject ? '<td class="small">' . ($d['project_no'] ?? null ? brgy_project_link(['id' => $d['project_id'], 'project_no' => $d['project_no']]) : '<span class="text-muted">' . h(ucfirst($d['scope'])) . '</span>') . '</td>' : '')
        . '<td class="small text-nowrap ' . ($exp ? 'text-danger fw-semibold' : ($soon ? 'text-warning-emphasis' : '')) . '">' . h(fdate($d['valid_until'])) . ($d['valid_until'] ? '<div class="' . ($exp ? '' : 'text-muted') . '">' . h(brgy_rel($d['valid_until'])) . '</div>' : '') . '</td>'
        . '<td>' . Brgy::st($d['status'] === 'for_review' ? 'pending' : $d['status'], Brgy::WF[$d['status']] ?? ucfirst($d['status'])) . '</td>'
        . '<td class="small text-nowrap">' . h(fdate($d['uploaded_at'])) . '</td></tr>';
};

if ($tab === 'company'):
    $types = DB::all('SELECT * FROM brgy_doc_types WHERE is_company = 1 AND is_active = 1 ORDER BY sort_order, name');
    $docs = DB::all("SELECT d.*, NULL AS project_no FROM brgy_documents d WHERE d.scope = 'company' AND d.is_current = 1 AND d.status <> 'void' ORDER BY d.doc_type, d.valid_until DESC, d.id DESC");
    $byType = [];
    foreach ($docs as $d) {
        $byType[$d['doc_type']][] = $d;
    }
    ?>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="paper-grid">
        <?php foreach ($types as $t): $list = $byType[$t['code']] ?? []; $best = $list[0] ?? null;
            $state = !$best ? 'missing' : ($best['valid_until'] && $best['valid_until'] < today() ? 'expired' : ($best['status'] === 'for_review' ? 'pending' : ($best['status'] === 'returned' ? 'returned' : ($best['valid_until'] && $best['valid_until'] <= date('Y-m-d', strtotime('+30 days')) ? 'soon' : 'ok')))); ?>
          <div class="paper-card ps-<?= h($state) ?>">
            <div class="paper-top"><span class="paper-name"><?= h($t['name']) ?></span><?= Brgy::st(['missing' => 'missing', 'expired' => 'expired', 'pending' => 'pending', 'returned' => 'returned', 'soon' => 'pending', 'ok' => 'ok'][$state],
                ['missing' => 'Not on file', 'expired' => 'Expired', 'pending' => 'For review', 'returned' => 'Returned', 'soon' => 'Expires soon', 'ok' => 'Valid'][$state]) ?></div>
            <?php if ($best): ?><div class="small wrap-link"><?= brgy_doc_link($best) ?></div>
              <div class="small text-muted"><?= $best['valid_until'] ? 'Valid until ' . h(fdate($best['valid_until'])) . ' (' . h(brgy_rel($best['valid_until'])) . ')' : ((int)$t['needs_validity'] ? '<span class="text-danger">validity date not entered</span>' : 'No expiry') ?></div>
              <?php if (count($list) > 1): ?><div class="small text-muted"><?= count($list) - 1 ?> other on file</div><?php endif; ?>
            <?php else: ?><div class="small text-muted">File it once; every bid that asks for it links it while it is valid.</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <?php $other = array_filter($docs, fn($d) => !in_array($d['doc_type'], array_column($types, 'code'), true)); if ($other): ?>
          <div class="card mt-3"><div class="card-header">Other company documents</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Document</th><th>Type</th><th>Valid until</th><th>Status</th><th>Filed</th></tr></thead>
            <?php foreach ($other as $d) { echo $docRow($d, false); } ?></table></div></div>
        <?php endif; ?>
      </div>
      <div class="col-xl-4"><?= Access::can($pageKey, 'create') ? $uploadForm('company', null, 'company') : '' ?></div>
    </div>
<?php
else:
    $q = trim((string)g('q'));
    $fType = (string)g('doc_type');
    $sql = 'SELECT d.*, p.project_no FROM brgy_documents d LEFT JOIN brgy_projects p ON p.id = d.project_id WHERE 1 = 1';
    $params = [];
    if ($tab === 'signed') {
        // the signed papers of the SOP, the signed copies of forms and the canvass forms signed by the suppliers
        $sql .= " AND d.related_type IN ('paper', 'signed_form', 'canvass') AND d.is_current = 1 AND d.status <> 'void'";
    } elseif ($tab === 'review') {
        $sql .= " AND d.status = 'for_review' AND d.is_current = 1";
    } elseif ($tab === 'expiring') {
        $sql .= " AND d.is_current = 1 AND d.status <> 'void' AND d.valid_until IS NOT NULL AND d.valid_until <= :lim";
        $params['lim'] = date('Y-m-d', strtotime('+' . max(1, (int)Brgy::setting('brgy_alert_days')) . ' days'));
    } elseif (g('show') !== 'old') {
        $sql .= ' AND d.is_current = 1';
    }
    if ($q !== '') {
        $sql .= ' AND (d.title LIKE :q OR d.doc_no LIKE :q OR d.issued_by LIKE :q OR d.tags LIKE :q OR d.original_name LIKE :q OR p.project_no LIKE :q)';
        $params['q'] = "%$q%";
    }
    if ($fType !== '') {
        $sql .= ' AND d.doc_type = :t';
        $params['t'] = $fType;
    }
    $rows = DB::all($sql . ($tab === 'expiring' ? ' ORDER BY d.valid_until, d.id' : ' ORDER BY d.uploaded_at DESC, d.id DESC') . ' LIMIT 600', $params);
    ?>
    <form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>"><div class="row g-2 align-items-end">
      <div class="col-md-5"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Title, number, issuer, tag, file name, project" data-live-filter="#docTable tbody tr" data-live-enter-submits></div>
      <?= select_field('doc_type', 'Type', Brgy::docTypes(), $fType, ['blank' => 'All types', 'class' => 'col-md-4', 'extra' => 'data-autosubmit']) ?>
      <?php if ($tab === 'signed'): ?><div class="col-12 small text-muted">The signed papers attached on the Process tab of each transaction and on the forms, newest first. Each project's Process tab downloads them all as one ZIP.</div><?php endif; ?>
      <?php if ($tab === 'all'): ?><div class="col-md-2"><label class="form-check"><input class="form-check-input" type="checkbox" name="show" value="old" <?= g('show') === 'old' ? 'checked' : '' ?> data-autosubmit> <span class="form-check-label small">Older versions too</span></label></div><?php endif; ?>
      <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
    </div></form>
    <div class="row g-3"><div class="<?= $tab === 'all' && Access::can($pageKey, 'create') ? 'col-xl-8' : 'col-12' ?>">
      <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="docTable">
        <thead><tr><th>Document</th><th>Type</th><th>Project</th><th>Valid until</th><th>Status</th><th>Filed</th></tr></thead>
        <tbody><?php foreach ($rows as $d) { echo $docRow($d); } ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-muted py-4 text-center"><?= $tab === 'review' ? 'Nothing waits for review.' : ($tab === 'expiring' ? 'No paper expires in the next ' . (int)Brgy::setting('brgy_alert_days') . ' days.' : 'No documents.') ?></td></tr><?php endif; ?></tbody>
      </table></div></div></div>
      <?php if ($tab === 'all' && Access::can($pageKey, 'create')): ?><div class="col-xl-4"><?= $uploadForm('project', null, 'all') ?></div><?php endif; ?>
    </div>
<?php endif; ?>
</div>
<?php
layout_end();
