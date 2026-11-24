<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Forms & Proformas
 * Letters, schedules, contracts, receipts and reports made from the templates and filled from the records. A form is
 * edited as a draft, checked and approved by two other people, then made final: locked, printed without the DRAFT mark
 * and filed in the vault, where it counts for the checklist or the billing pack.
 * Forms of templates that need no approval (the barangay papers of the SOP) print clean as soon as they are prepared.
 * Each form prints with the format of its template (paper, margins, font, columns, copies), or only its values on the
 * barangay's pre-printed paper; the signed copy (PDF or JPG) is attached to it for tracing and downloading.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.forms';
$a = g('a', 'list');
$id = gint('id');

$loadForm = function (int $id): array {
    $f = DB::row('SELECT f.*, t.name AS template_name, t.code AS template_code, t.version_no AS template_current, t.layout_json, t.positions_json, t.print_mode, p.project_no, p.title AS project_title
                    FROM brgy_forms f LEFT JOIN brgy_templates t ON t.id = f.template_id LEFT JOIN brgy_projects p ON p.id = f.project_id WHERE f.id = :id', ['id' => $id]);
    if (!$f) {
        flash('warning', 'Form not found.');
        redirect(url('brgy.forms'));
    }
    return $f;
};

/** The format a form prints with: its template's, on the paper chosen on the form */
$formLayout = function (array $f): array {
    $L = BrgyLayout::layout(['layout_json' => $f['layout_json'], 'paper' => $f['paper'], 'orientation' => $f['orientation']]);
    if ($L['paper'] !== 'Custom' && isset(BrgyLayout::PAPERS[$f['paper']])) {
        $L['paper'] = $f['paper'];
        $L['orientation'] = $f['orientation'] === 'landscape' ? 'landscape' : 'portrait';
    }
    return $L;
};
$isDraft = fn(array $f) => (int)$f['needs_approval'] === 1 && $f['status'] !== 'final';

// ------------------------------------------------------------------ the formats of the templates
if ($a === 'formats') {
    $rows = DB::all('SELECT t.*, (SELECT COUNT(*) FROM brgy_forms f WHERE f.template_id = t.id) AS uses FROM brgy_templates t WHERE t.is_active = 1 ORDER BY t.sort_order, t.name');
    $canT = Access::can('brgy.templates', 'edit');
    layout_start('Formats of the forms', brgy_layout(['actions' => btn_link(url($pageKey), 'Forms', 'btn-outline-secondary', 'arrow-left')]));
    ?>
    <p class="small text-muted">Each form prints with the format of its template. <?= $canT ? 'Open a template to set its paper, margins, font, columns and copies (Format) or the place of each value on the barangay\'s pre-printed paper.' : 'Templates are changed by the people allowed to edit them.' ?></p>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 resp-cards">
      <thead><tr><th>Template</th><th>Paper</th><th>Text</th><th>Copies</th><th>Prints</th><th>Approval</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): $L = BrgyLayout::layout($r); ?>
        <tr><td data-label="Template"><b><?= h($r['name']) ?></b><div class="small text-muted"><?= h($r['code']) ?>, v<?= (int)$r['version_no'] ?><?= (int)$r['uses'] ? ', ' . (int)$r['uses'] . ' form(s)' : '' ?></div></td>
          <td data-label="Paper" class="small"><?= h(BrgyLayout::paperLabel($L)) ?><div class="text-muted">margins <?= h($L['margin_top'] . ' / ' . $L['margin_right'] . ' / ' . $L['margin_bottom'] . ' / ' . $L['margin_left']) ?> mm</div></td>
          <td data-label="Text" class="small"><?= h($L['font'] . ', ' . $L['font_size'] . ' pt') ?></td>
          <td data-label="Copies" class="small"><?= (int)$L['copies'] ?></td>
          <td data-label="Prints" class="small"><?= $r['print_mode'] === 'positions' ? 'On pre-printed paper' : 'With the layout' ?></td>
          <td data-label="Approval" class="small"><?= (int)$r['needs_approval'] ? 'Review and approval' : 'None' ?></td>
          <td class="text-end text-nowrap"><?= Access::page('brgy.templates') ? btn_link(url('brgy.templates', ['a' => 'view', 'id' => $r['id'], 'tab' => 'format']), 'Format', 'btn-outline-primary', 'sliders') . ' ' . btn_link(url('brgy.templates', ['a' => 'view', 'id' => $r['id'], 'tab' => 'positions']), 'Pre-printed', 'btn-outline-secondary', 'grid-3x3') : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ print / Word
if (in_array($a, ['print', 'word'], true) && $id) {
    Access::require($pageKey, 'print');
    $f = $loadForm($id);
    Brgy::log($a === 'word' ? 'export' : 'print', 'form', $id, $f['form_no'] . ' ' . $f['title'], ['page_key' => $pageKey, 'reason' => $f['status'] === 'final' ? 'Final copy' : 'Draft copy']);
    $L = $formLayout($f);
    if ($a === 'word') {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['form_no'] . '_' . mb_substr($f['title'], 0, 60)) . '.doc';
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: private, no-store');
        echo BrgyDocs::wordDoc($f['title'], ($isDraft($f) ? '<p style="color:#b02a37;font-weight:bold;text-align:center">DRAFT - NOT FOR SUBMISSION</p>' : '') . $f['body'], $f['paper'], $f['orientation'], $L);
        exit;
    }
    $hasPos = !empty($f['positions_json']) && count(BrgyLayout::positions(['positions_json' => $f['positions_json'], 'paper' => $f['paper']])['fields']) > 0;
    $mode = in_array(g('mode'), ['layout', 'positions'], true) ? g('mode') : ($f['print_mode'] === 'positions' && $hasPos ? 'positions' : 'layout');
    $tools = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url($pageKey, ['a' => 'word', 'id' => $id])) . '"><i class="bi bi-file-earmark-word"></i> Word</a>'
        . ($hasPos ? '<div class="btn-group btn-group-sm"><a class="btn btn-' . ($mode === 'layout' ? 'secondary' : 'outline-secondary') . '" href="' . h(url($pageKey, ['a' => 'print', 'id' => $id, 'mode' => 'layout'])) . '">With the layout</a>'
            . '<a class="btn btn-' . ($mode === 'positions' ? 'secondary' : 'outline-secondary') . '" href="' . h(url($pageKey, ['a' => 'print', 'id' => $id, 'mode' => 'positions'])) . '">On pre-printed paper</a></div>' : '');
    if ($mode === 'positions') {
        $P = BrgyLayout::positions(['positions_json' => $f['positions_json'], 'paper' => $f['paper'], 'orientation' => $f['orientation']]);
        $vals = json_decode((string)$f['values_json'], true) ?: ['f' => [], 't' => []];
        BrgyLayout::printPositions($f['form_no'] . ' ' . $f['title'], $P, $vals, ['back' => url($pageKey, ['a' => 'view', 'id' => $id]), 'tools' => $tools]);
    }
    BrgyLayout::printPage($f['form_no'] . ' ' . $f['title'], $f['body'], $L, ['draft' => $isDraft($f), 'clean' => !$isDraft($f), 'back' => url($pageKey, ['a' => 'view', 'id' => $id]), 'tools' => $tools]);
}

// ------------------------------------------------------------------ new form
if ($a === 'new') {
    Access::require($pageKey, 'create');
    handle_post(function () use ($pageKey) {
        $fid = BrgyOps::createForm((int)pint('template_id'), pint('project_id'), null, (string)p('title'));
        redirect(url($pageKey, ['a' => 'edit', 'id' => $fid]));
    });
    $tpls = [];
    foreach (DB::all('SELECT id, name, stage, is_prescribed FROM brgy_templates WHERE is_active = 1 ORDER BY sort_order, name') as $t) {
        $tpls[['bid' => 'Bid', 'award' => 'Award and contract', 'contract' => 'Contracts (proforma)', 'delivery' => 'Delivery', 'billing' => 'Billing', 'collection' => 'Collection', 'closeout' => 'Closeout', 'internal' => 'Internal'][$t['stage']] ?? 'Other'][$t['id']] = $t['name'] . ($t['is_prescribed'] ? ' [prescribed]' : '');
    }
    $projects = DB::pairs("SELECT p.id, CONCAT(p.project_no, ' - ', LEFT(p.title, 70)) FROM brgy_projects p WHERE p.status IN ('open', 'awarded', 'closed') ORDER BY p.project_no DESC");
    layout_start('Prepare a form', brgy_layout(['actions' => btn_link(url($pageKey), 'Cancel')]));
    ?>
    <form method="post" class="card" style="max-width:760px" data-no-class-check><?= csrf_field() ?><div class="card-body row g-2">
      <div class="col-12"><label class="form-label">Template</label><select class="form-select form-select-sm" name="template_id" required data-combo><option value=""></option>
        <?php foreach ($tpls as $g => $opts): ?><optgroup label="<?= h($g) ?>"><?= options($opts, gint('template_id')) ?></optgroup><?php endforeach; ?></select></div>
      <?= select_field('project_id', 'For the project (fills the project, entity and contract fields)', $projects, gint('project_id'), ['blank' => 'None: a company document', 'class' => 'col-12']) ?>
      <?= input_field('title', 'Title (optional)', '', ['class' => 'col-12']) ?>
      <div class="col-12 form-text">For a delivery, billing or collection, prepare the form from that record (its page has a "Prepare" box), so its own fields are filled too.</div>
    </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-richtext"></i> Prepare</button></div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ actions on a form (view and edit pages)
if (in_array($a, ['view', 'edit'], true) && $id && is_post() && p('do') !== 'save') {
    $do = (string)p('do');
    $r = trim((string)p('remarks'));
    brgy_action(function () use ($do, $id, $r) {
        switch ($do) {
            case 'fm_submit':
            case 'fm_review':
            case 'fm_approve':
            case 'fm_return':
            case 'fm_reopen':
                BrgyOps::formStep($id, substr($do, 3), $r);
                return ['fm_submit' => 'Sent for review.', 'fm_review' => 'Marked reviewed.', 'fm_approve' => 'Approved. Make it final to lock it and file it in the vault.', 'fm_return' => 'Returned to the preparer.', 'fm_reopen' => 'Reopened as a draft.'][$do];
            case 'refresh':
                BrgyOps::refreshForm($id);
                return 'The form was filled again from the records (and the current template).';
            case 'finalize':
                BrgyOps::finalizeForm($id, $_POST);
                return 'Final: the form is locked and filed in the Document Vault.';
            case 'signed_attach':
                Access::require('brgy.forms', 'edit');
                $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $id]);
                $paper = DB::value('SELECT id FROM brgy_project_papers WHERE form_id = :f ORDER BY id LIMIT 1', ['f' => $id]);
                if ($paper) {
                    BrgySop::attach((int)$paper, brgy_file('file'), (string)p('note'));
                    return 'Signed copy attached: the paper is done on the Process tab of the project.';
                }
                $file = brgy_file('file');
                if (!$file || !in_array(strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                    fail('Attach the signed copy as a PDF, JPG or JPEG file.');
                }
                BrgyDocs::store($file, ['scope' => $f['project_id'] ? 'project' : 'company', 'project_id' => $f['project_id'], 'doc_type' => $f['doc_type'] ?: 'other', 'title' => $f['title'] . ' (signed)',
                    'doc_no' => $f['form_no'], 'issue_date' => today(), 'related_type' => 'signed_form', 'related_id' => $id, 'status' => 'approved', 'notes' => (string)p('note') ?: 'Signed copy of ' . $f['form_no']]);
                return 'Signed copy attached and filed in the Document Vault.';
            default:
                fail('Unknown action.');
        }
    }, url('brgy.forms', ['a' => $do === 'refresh' ? 'edit' : 'view', 'id' => $id]));
}

// ------------------------------------------------------------------ edit
if ($a === 'edit' && $id) {
    Access::require($pageKey, 'edit');
    $f = $loadForm($id);
    if (!in_array($f['status'], ['draft', 'returned'], true)) {
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    handle_post(function () use ($id, $pageKey) {
        BrgyOps::saveForm($id, $_POST, p('lock_version'));
        flash('success', 'Form saved.');
        redirect(url($pageKey, ['a' => p('next') === 'view' ? 'view' : 'edit', 'id' => $id]));
    });
    $body = is_post() ? BrgyDocs::sanitize((string)p('body')) : $f['body'];
    $gaps = BrgyDocs::gaps($body);
    $why = $f['status'] === 'returned' ? (string)DB::value("SELECT remarks FROM brgy_workflow WHERE object_type = 'form' AND object_id = :id AND action = 'return' ORDER BY id DESC LIMIT 1", ['id' => $id]) : '';
    layout_start('Edit ' . $f['form_no'], brgy_layout(['actions' => btn_link(url($pageKey, ['a' => 'view', 'id' => $id]), 'Preview', 'btn-outline-secondary', 'eye')
        . ' ' . page_link($pageKey, ['a' => 'print', 'id' => $id], 'Print draft', 'btn-outline-secondary', 'printer', 'print')]));
    ?>
    <?php if ($why): ?><div class="alert alert-danger small"><b>Returned:</b> <?= h($why) ?></div><?php endif; ?>
    <?php // v37: a form made from an older version of its template (the sworn statements were shells before version 37)
    if ((int)$f['template_current'] > (int)$f['template_version']): ?>
      <div class="alert alert-info small d-flex flex-wrap align-items-center gap-2"><span class="me-auto"><b>The template has changed.</b> This form was made from version <?= (int)$f['template_version'] ?> of <?= h($f['template_name']) ?>; it is now at version <?= (int)$f['template_current'] ?>.
        Fill it again from the records to take the new text and format (what was typed on this form is replaced).</span>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mRefresh"><i class="bi bi-arrow-repeat"></i> Fill again from the records</button></div>
    <?php endif; ?>
    <?php if ((int)$f['is_prescribed']): ?><div class="alert alert-warning small"><b>Prescribed form.</b> Its wording follows a form issued by the GPPB or the procuring entity. Check it against the bidding documents of this procurement before use, and state the official version you followed; change only what the form asks you to fill.</div><?php endif; ?>
    <form method="post" id="formEdit" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="save"><?= Brgy::versionField($f['lock_version']) ?>
      <textarea name="body" id="formBody" hidden><?= h($body) ?></textarea>
      <div class="row g-3">
        <div class="col-xl-9">
          <?php $L = $formLayout($f); ?><style><?= BrgyLayout::css($L, '#formEdit', false) ?> #formEdit .formdoc [data-section] { display: revert !important; }</style>
          <div class="editor-shell">
            <div class="editor-bar no-print" role="toolbar" aria-label="Formatting">
              <select class="form-select form-select-sm w-auto" data-cmd-block aria-label="Paragraph style"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Subheading</option></select>
              <span class="eb-group"><button type="button" class="eb" data-cmd="bold" title="Bold"><i class="bi bi-type-bold"></i></button><button type="button" class="eb" data-cmd="italic" title="Italic"><i class="bi bi-type-italic"></i></button><button type="button" class="eb" data-cmd="underline" title="Underline"><i class="bi bi-type-underline"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-cmd="justifyLeft" title="Align left"><i class="bi bi-text-left"></i></button><button type="button" class="eb" data-cmd="justifyCenter" title="Center"><i class="bi bi-text-center"></i></button><button type="button" class="eb" data-cmd="justifyRight" title="Align right"><i class="bi bi-text-right"></i></button><button type="button" class="eb" data-cmd="justifyFull" title="Justify"><i class="bi bi-justify"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-cmd="insertUnorderedList" title="Bullets"><i class="bi bi-list-ul"></i></button><button type="button" class="eb" data-cmd="insertOrderedList" title="Numbering"><i class="bi bi-list-ol"></i></button><button type="button" class="eb" data-cmd="outdent" title="Decrease indent"><i class="bi bi-text-indent-right"></i></button><button type="button" class="eb" data-cmd="indent" title="Increase indent"><i class="bi bi-text-indent-left"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-insert-table title="Insert a table"><i class="bi bi-table"></i></button><button type="button" class="eb" data-insert-break title="Page break"><i class="bi bi-file-break"></i></button><button type="button" class="eb" data-cmd="removeFormat" title="Clear formatting"><i class="bi bi-eraser"></i></button></span>
              <span class="eb-group"><button type="button" class="eb" data-cmd="undo" title="Undo"><i class="bi bi-arrow-counterclockwise"></i></button><button type="button" class="eb" data-cmd="redo" title="Redo"><i class="bi bi-arrow-clockwise"></i></button></span>
            </div>
            <div class="doc-stage"><div class="formdoc paper-sheet is-editing" id="formEditor" contenteditable="true" spellcheck="true" style="<?= h(BrgyLayout::styleAttr($L)) ?>"><?= $body ?></div></div>
          </div>
        </div>
        <div class="col-xl-3">
          <div class="card mb-3"><div class="card-header"><i class="bi bi-ui-checks"></i> Fill in the blanks</div><div class="card-body" id="gapPanel">
            <?php // v34: a disbursement voucher: its gross amount, VAT and EWT; the check amount and the amount in words follow
            $dvProject = $f['project_id'] && Brgy::v34Ready() && preg_match('/data-ph="dv\.(gross|taxes|net)/', $body) ? Brgy::project((int)$f['project_id']) : null;
            if ($dvProject):
                $dv = BrgySop::dvAmounts($dvProject);
                $dvGross = is_post() ? (string)p('dv_gross') : ($dv['gross'] > 0 ? number_format($dv['gross'], 2) : '');
                $dvVat = is_post() ? (string)p('dv_vat_rate') : Brgy::rateKey($dv['vat_rate']);
                $dvEwt = is_post() ? (string)p('dv_ewt_rate') : Brgy::rateKey($dv['ewt_rate']);
                $dvOpts = function (array $list, string $cur): string {
                    $list = ['0' => 'None'] + $list;
                    if ($cur !== '' && !isset($list[$cur])) {
                        $list[$cur] = $cur . '%';
                    }
                    $o = '';
                    foreach ($list as $v => $l) {
                        $o .= '<option value="' . h((string)$v) . '"' . ((string)$v === ($cur === '' ? '0' : $cur) ? ' selected' : '') . '>' . h($l) . '</option>';
                    }
                    return $o;
                }; ?>
              <div class="dv-panel" id="dvPanel">
                <div class="row g-2">
                  <div class="col-12"><label class="form-label" for="dvGross">Gross amount</label>
                    <input class="form-control form-control-sm text-end" id="dvGross" name="dv_gross" inputmode="decimal" autocomplete="off" value="<?= h($dvGross) ?>" placeholder="0.00"></div>
                  <div class="col-6"><label class="form-label" for="dvVat">VAT</label><select class="form-select form-select-sm" id="dvVat" name="dv_vat_rate" data-no-combo><?= $dvOpts(BrgySop::DV_VAT, $dvVat) ?></select></div>
                  <div class="col-6"><label class="form-label" for="dvEwt">EWT</label><select class="form-select form-select-sm" id="dvEwt" name="dv_ewt_rate" data-no-combo><?= $dvOpts(BrgySop::DV_EWT, $dvEwt) ?></select></div>
                </div>
                <table class="dv-calc" aria-live="polite"><tbody>
                  <tr><th scope="row">VAT</th><td id="dvVatAmt">0.00</td></tr>
                  <tr class="dv-how"><td colspan="2" id="dvVatHow"></td></tr>
                  <tr><th scope="row">EWT</th><td id="dvEwtAmt">0.00</td></tr>
                  <tr class="dv-how"><td colspan="2" id="dvEwtHow"></td></tr>
                  <tr class="dv-net"><th scope="row">Check amount</th><td id="dvNetAmt">0.00</td></tr>
                </tbody></table>
                <div class="form-text">Worked out on the page as you type, and again when the DV is saved. VAT 5% and EWT 2%: gross &divide; 1.12 &times; rate. VAT 3% and EWT 1%: gross &times; rate (EWT 1% beside VAT 5%: gross &divide; 1.12 &times; 1%).</div>
              </div>
            <?php elseif (($f['template_code'] ?? '') === 'disbursement_voucher' && $f['project_id'] && Brgy::v34Ready()): ?>
              <div class="alert alert-light border small mb-2">This DV was made before the gross amount, VAT and EWT boxes. Use <b>Fill again from the records</b> (below) to have them here.</div>
            <?php endif; ?>
            <p class="small text-muted mb-2" id="gapNone" <?= $gaps ? 'hidden' : '' ?>>No blanks left.</p>
            <div id="gapList"></div>
            <div class="form-text">Blanks are fields with no value in the records when the form was made. Fill them here, or click one in the page and type. A form with blanks cannot be sent for review.</div>
          </div></div>
          <div class="card mb-3"><div class="card-body row g-2">
            <?= input_field('title', 'Title', fv('title', $f), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="200"']) ?>
            <?= input_field('signatory_name', 'Signatory', fv('signatory_name', $f), ['class' => 'col-12']) ?>
            <?= input_field('signatory_position', 'Position', fv('signatory_position', $f), ['class' => 'col-12']) ?>
            <?= select_field('paper', 'Paper', array_map(fn($x) => $x[2], array_diff_key(BrgyLayout::PAPERS, ['Custom' => 1])), fv('paper', $f), ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('orientation', 'Orientation', ['portrait' => 'Portrait', 'landscape' => 'Landscape'], fv('orientation', $f), ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?php if ((int)$f['is_prescribed']): ?><?= input_field('official_version', 'Official version followed', fv('official_version', $f), ['class' => 'col-12', 'extra' => 'placeholder="e.g. GPPB form, issuance and date"']) ?><?php endif; ?>
          </div></div>
          <?php $P = $f['positions_json'] ? BrgyLayout::positions(['positions_json' => $f['positions_json'], 'paper' => $f['paper']]) : null;
          if ($P && $P['fields']):
              $vals = json_decode((string)$f['values_json'], true) ?: ['f' => []];
              $labels = BrgyLayout::fieldList(); ?>
            <div class="card mb-3"><div class="card-header"><i class="bi bi-grid-3x3"></i> Values on the pre-printed paper</div><div class="card-body row g-2">
              <?php foreach ($P['fields'] as $fd): if (str_starts_with($fd['key'], 'text:')) { continue; } ?>
                <div class="col-12"><label class="form-label"><?= h($labels[$fd['key']] ?? $fd['key']) ?></label><input class="form-control form-control-sm" name="values[<?= h($fd['key']) ?>]" value="<?= h((string)($vals['f'][$fd['key']] ?? '')) ?>" maxlength="500"></div>
              <?php endforeach; ?>
              <div class="col-12 form-text">What prints on the barangay's own pad (<?= $f['print_mode'] === 'positions' ? 'the way this template prints' : 'when printed on pre-printed paper' ?>). Items come from the price schedule.</div>
            </div></div>
          <?php endif; ?>
          <div class="small text-muted mb-2"><?= h($f['form_no']) ?>, from <?= h($f['template_name']) ?> (template version <?= (int)$f['template_version'] ?><?= (int)$f['template_current'] > (int)$f['template_version'] ? ', now ' . (int)$f['template_current'] : '' ?>)<?= $f['project_no'] ? ', ' . brgy_project_link(['id' => $f['project_id'], 'project_no' => $f['project_no']]) : '' ?>.</div>
        </div>
      </div>
      <div class="sticky-actions d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button>
        <button class="btn btn-outline-primary btn-sm" name="next" value="view"><i class="bi bi-eye"></i> Save and preview</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>">Close without saving</a>
        <button type="button" class="btn btn-link btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#mRefresh">Fill again from the records</button>
      </div>
    </form>
    <div class="modal fade" id="mRefresh" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['a' => 'edit', 'id' => $id])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="refresh">
      <div class="modal-header"><h5 class="modal-title">Fill again from the records</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body small">The form is made again from its template (current version) with today's values of the project, the entity and the company. <b>What you typed or changed in the text is replaced.</b></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Fill again</button></div></form></div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ view
if ($a === 'view' && $id) {
    $f = $loadForm($id);
    $p = $f['project_id'] ? Brgy::project((int)$f['project_id']) : null;
    $gaps = BrgyDocs::gaps($f['body']);
    $canEdit = Access::can($pageKey, 'edit');
    $L = $formLayout($f);
    $signed = DB::all("SELECT d.* FROM brgy_documents d WHERE d.status <> 'void' AND ((d.related_type = 'signed_form' AND d.related_id = :f)
                        OR d.id IN (SELECT pp.document_id FROM brgy_project_papers pp WHERE pp.form_id = :f2 AND pp.document_id IS NOT NULL)) ORDER BY d.id DESC", ['f' => $id, 'f2' => $id]);
    $paperOf = DB::row('SELECT pp.*, s.step_no, s.title AS step_title FROM brgy_project_papers pp LEFT JOIN brgy_sop_steps s ON s.code = pp.step_code WHERE pp.form_id = :f ORDER BY pp.id LIMIT 1', ['f' => $id]);
    $actions = (in_array($f['status'], ['draft', 'returned'], true) && $canEdit ? btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-primary', 'pencil') . ' ' : '')
        . page_link($pageKey, ['a' => 'print', 'id' => $id], $isDraft($f) ? 'Print draft' : 'Print', 'btn-outline-secondary', 'printer', 'print') . ' '
        . page_link($pageKey, ['a' => 'word', 'id' => $id], 'Word', 'btn-outline-secondary', 'file-earmark-word', 'print') . ' '
        . ($p ? btn_link(url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'forms']), $p['project_no'], 'btn-outline-secondary', 'briefcase') : '');
    layout_start($f['form_no'] . ' ' . mb_strimwidth($f['title'], 0, 70, '...'), brgy_layout(['actions' => $actions]));
    ?>
    <div class="row g-3">
      <div class="col-xl-9 order-2 order-xl-1"><style><?= BrgyLayout::css($L, '.form-prev', false) ?></style><div class="doc-stage is-preview form-prev"><div class="formdoc paper-sheet<?= $isDraft($f) ? ' is-draft' : ' is-clean' ?><?= BrgyLayout::docClass($L) ?>" style="<?= h(BrgyLayout::styleAttr($L)) ?>"><?= $f['body'] . BrgyLayout::footerHtml($L) ?></div></div></div>
      <div class="col-xl-3 order-1 order-xl-2">
        <div class="card mb-3"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2"><?= (int)$f['needs_approval'] === 0 && $f['status'] !== 'final' ? Brgy::st('ok', 'Prepared') : Brgy::st($f['status']) ?><span class="small text-muted"><?= h(BrgyLayout::paperLabel($L)) ?></span></div>
          <?php if ($gaps): ?><div class="small text-danger mb-2"><?= count($gaps) ?> blank(s): <?= h(implode(', ', $gaps)) ?></div><?php endif; ?>
          <?php if ($f['status'] === 'final'): ?>
            <div class="small">Final <?= h(fdatetime($f['finalized_at'])) ?>, filed as <?= brgy_doc_link($f['document_id'], 'vault document') ?>.<?= $f['official_version'] ? '<div class="text-muted">Official version: ' . h($f['official_version']) . '</div>' : '' ?></div>
            <?php if (Access::can('brgy.approvals', 'approve')): ?><div class="mt-2"><?= brgy_step_buttons('form', $f, $p, url($pageKey, ['a' => 'view', 'id' => $id]), 'fm') ?></div><?php endif; ?>
          <?php elseif ($f['status'] === 'approved'): ?>
            <?php if ($canEdit): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" class="mb-2"><?= csrf_field() ?><input type="hidden" name="do" value="finalize">
              <?php if ((int)$f['is_prescribed']): ?><label class="form-label">Official version followed</label><input class="form-control form-control-sm mb-2" name="official_version" value="<?= h($f['official_version']) ?>" required><?php endif; ?>
              <button class="btn btn-success btn-sm w-100"><i class="bi bi-lock"></i> Make final and file in the vault</button></form>
            <?php endif; ?>
            <?= brgy_step_buttons('form', $f, $p, url($pageKey, ['a' => 'view', 'id' => $id]), 'fm') ?>
          <?php elseif ((int)$f['needs_approval'] === 0): ?>
            <div class="small text-muted mb-2">This form needs no review: it prints clean as it is. Have it signed, then attach the signed copy below.</div>
            <?php if ($canEdit && !$gaps): ?><form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="finalize">
              <button class="btn btn-outline-success btn-sm w-100"><i class="bi bi-lock"></i> Lock it and file it in the vault</button></form><?php endif; ?>
          <?php else: ?>
            <?= brgy_step_buttons('form', $f, $p, url($pageKey, ['a' => 'view', 'id' => $id]), 'fm') ?>
          <?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-paperclip"></i> Signed copy</div><div class="card-body">
          <?php if ($paperOf): ?><div class="small mb-2">Paper of the SOP: <?= h($paperOf['name']) ?>, step <?= (int)$paperOf['step_no'] ?> (<?= h((string)$paperOf['step_title']) ?>). <a href="<?= h(url('brgy.projects', ['a' => 'view', 'id' => $f['project_id'], 'tab' => 'process'])) ?>#step-<?= h($paperOf['step_code']) ?>">Process tab</a></div><?php endif; ?>
          <?php foreach ($signed as $d): ?><div class="d-flex justify-content-between gap-2 small mb-1"><a href="<?= h(url('brgy.vault', ['a' => 'file', 'id' => $d['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-check"></i> <?= h($d['original_name'] ?: $d['title']) ?></a>
            <span class="text-muted text-nowrap"><?= h(fdate(substr((string)$d['uploaded_at'], 0, 10))) ?> <a href="<?= h(url('brgy.vault', ['a' => 'download', 'id' => $d['id']])) ?>" aria-label="Download"><i class="bi bi-download"></i></a></span></div><?php endforeach; ?>
          <?php if (!$signed): ?><div class="small text-muted mb-2">No signed copy yet.</div><?php endif; ?>
          <?php if ($canEdit && $f['status'] !== 'void'): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" class="mt-2"><?= csrf_field() ?><input type="hidden" name="do" value="signed_attach">
              <?= brgy_file_field('file', 'Attach the signed copy (PDF, JPG or JPEG)', '', 'col-12', true, '.pdf,.jpg,.jpeg,.png') ?>
              <input class="form-control form-control-sm mt-2" name="note" maxlength="255" placeholder="Note (optional)">
              <button class="btn btn-sm btn-outline-primary w-100 mt-2"><i class="bi bi-upload"></i> Attach</button></form>
          <?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-body small"><dl class="dl-grid dl-stack mb-0">
          <dt>Template</dt><dd><?= h($f['template_name']) ?> v<?= (int)$f['template_version'] ?></dd>
          <?php if ($p): ?><dt>Project</dt><dd><?= brgy_project_link($p) ?></dd><?php endif; ?>
          <?php if ($f['related_type']): ?><dt>For</dt><dd><?= h(ucfirst($f['related_type'])) ?> <?= h((string)DB::value(['delivery' => 'SELECT dr_no FROM brgy_deliveries', 'billing' => 'SELECT billing_no FROM brgy_billings', 'collection' => 'SELECT collection_no FROM brgy_collections'][$f['related_type']] . ' WHERE id = :id', ['id' => $f['related_id']])) ?></dd><?php endif; ?>
          <dt>Signatory</dt><dd><?= h(trim($f['signatory_name'] . ($f['signatory_position'] ? ', ' . $f['signatory_position'] : ''))) ?: '<span class="text-danger">not entered</span>' ?></dd>
          <dt>Prepared</dt><dd><?= h(Brgy::userName((int)$f['prepared_by'])) ?>, <?= h(fdate($f['created_at'])) ?></dd>
          <?php if ($f['reviewed_by']): ?><dt>Reviewed</dt><dd><?= h(Brgy::userName((int)$f['reviewed_by'])) ?>, <?= h(fdate($f['reviewed_at'])) ?></dd><?php endif; ?>
          <?php if ($f['approved_by']): ?><dt>Approved</dt><dd><?= h(Brgy::userName((int)$f['approved_by'])) ?>, <?= h(fdate($f['approved_at'])) ?></dd><?php endif; ?>
        </dl></div></div>
        <div class="card"><div class="card-header"><i class="bi bi-diagram-3"></i> Approval trail</div><div class="card-body"><?= brgy_workflow_list('form', $id) ?></div></div>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$fStatus = (string)g('status');
$sql = "SELECT f.*, t.name AS template_name, p.project_no, u.full_name,
               (SELECT COUNT(*) FROM brgy_documents d WHERE d.status <> 'void' AND ((d.related_type = 'signed_form' AND d.related_id = f.id) OR d.id IN (SELECT pp.document_id FROM brgy_project_papers pp WHERE pp.form_id = f.id))) AS signed_n
          FROM brgy_forms f LEFT JOIN brgy_templates t ON t.id = f.template_id LEFT JOIN brgy_projects p ON p.id = f.project_id LEFT JOIN users u ON u.id = f.prepared_by WHERE 1 = 1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (f.form_no LIKE :q OR f.title LIKE :q OR t.name LIKE :q OR p.project_no LIKE :q)';
    $params['q'] = "%$q%";
}
if ($fStatus === 'working') {
    $sql .= " AND f.status IN ('draft', 'returned', 'for_review', 'reviewed', 'approved')";
} elseif (isset(Brgy::WF[$fStatus])) {
    $sql .= ' AND f.status = :s';
    $params['s'] = $fStatus;
}
$rows = DB::all($sql . ' ORDER BY f.id DESC LIMIT 500', $params);
layout_start('Forms & Proformas', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'Prepare a form', 'btn-primary', 'plus-lg', 'create') . ' ' . btn_link(url($pageKey, ['a' => 'formats']), 'Formats', 'btn-outline-secondary', 'sliders')
    . ' ' . (Access::page('brgy.templates') ? btn_link(url('brgy.templates'), 'Templates', 'btn-outline-secondary', 'files') : '')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-5"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Form no., title, template, project" data-live-filter="#formTable tbody tr" data-live-enter-submits></div>
  <?= select_field('status', 'Status', ['working' => 'Not final yet', 'draft' => 'Draft', 'returned' => 'Returned', 'for_review' => 'For review', 'reviewed' => 'Reviewed', 'approved' => 'Approved', 'final' => 'Final'], $fStatus, ['blank' => 'All', 'class' => 'col-md-3', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 resp-cards" id="formTable">
  <thead><tr><th>Form</th><th>Template</th><th>Project</th><th>Status</th><th>Prepared</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="wrap-link" data-label="Form"><a href="<?= h(url($pageKey, ['a' => in_array($r['status'], ['draft', 'returned'], true) && Access::can($pageKey, 'edit') ? 'edit' : 'view', 'id' => $r['id']])) ?>"><b><?= h($r['form_no']) ?></b></a><div class="small"><?= h($r['title']) ?></div></td>
      <td class="small" data-label="Template"><?= h($r['template_name']) ?><?= (int)$r['is_prescribed'] ? '<div class="text-warning-emphasis">prescribed</div>' : '' ?></td>
      <td class="small" data-label="Project"><?= $r['project_no'] ? brgy_project_link(['id' => $r['project_id'], 'project_no' => $r['project_no']]) : '<span class="text-muted">company</span>' ?></td>
      <td><?= (int)$r['needs_approval'] === 0 && $r['status'] !== 'final' ? Brgy::st('ok', 'Prepared') : Brgy::st($r['status']) ?><?= (int)$r['signed_n'] ? '<div class="small text-success"><i class="bi bi-paperclip"></i> signed copy</div>' : '' ?></td><td class="small"><?= h(fdate($r['created_at'])) ?><div class="text-muted"><?= h($r['full_name']) ?></div></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="text-muted py-4 text-center">No forms yet.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php
layout_end();
