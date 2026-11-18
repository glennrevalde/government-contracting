<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Projects & Contracts
 * The one record of each job: opportunity, compliance, costing, bid approval and submission, award, fulfilment,
 * deliveries, change orders, billing, collection and closeout. See app/Brgy.php and app/BrgyOps.php.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.projects';
$a = g('a', 'list');
$id = gint('id');

// ------------------------------------------------------------------ CSV of the price schedule
if ($a === 'lines_csv' && $id) {
    Access::require($pageKey, 'print');
    $p = Brgy::mustProject($id);
    $costs = Access::viewCosts();
    Brgy::log('export', 'project', $id, $p['project_no'] . ' price schedule', ['page_key' => $pageKey]);
    csv_download($p['project_no'] . '_price_schedule.csv', array_merge(['No.', 'Kind', 'Item', 'Description', 'Unit', 'Bid qty', 'Contract qty'], $costs ? ['Unit cost', 'Markup %'] : [], ['Unit price', 'Amount', 'Tax', 'Delivered', 'Accepted', 'Billed']),
        array_map(fn($l) => array_merge([$l['line_no'], $l['line_kind'], $l['item_code'], $l['description'], $l['unit'], $l['bid_qty'], $l['qty']], $costs ? [$l['unit_cost'], $l['markup_pct']] : [],
            [$l['unit_price'], $l['amount'], $l['tax_code'], $l['qty_delivered'], $l['qty_accepted'], $l['qty_billed']]), Brgy::lines($id)));
}

// ------------------------------------------------------------------ the signed copies of a transaction, in one ZIP
if ($a === 'papers_zip' && $id) {
    Access::require($pageKey, 'print');
    $p = Brgy::mustProject($id);
    if (!class_exists('ZipArchive')) {
        flash('warning', 'The ZIP extension of PHP is not enabled on this server: download the copies one by one.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'process']));
    }
    $docs = DB::all("SELECT d.*, pp.name AS paper_name, s.step_no FROM brgy_project_papers pp JOIN brgy_documents d ON d.id = pp.document_id LEFT JOIN brgy_sop_steps s ON s.code = pp.step_code
                      WHERE pp.project_id = :p ORDER BY s.sort_order, pp.sort_order", ['p' => $id]);
    foreach (DB::all('SELECT d.*, c.supplier_name FROM brgy_canvass c JOIN brgy_documents d ON d.id = c.document_id WHERE c.project_id = :p ORDER BY c.sort_order', ['p' => $id]) as $d) {
        $docs[] = $d + ['paper_name' => 'Canvass form ' . $d['supplier_name'], 'step_no' => 0];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $n = 0;
    foreach ($docs as $d) {
        $file = BrgyDocs::dir() . '/' . $d['file_name'];
        if ($d['file_name'] && is_file($file) && BrgyDocs::canSee($d)) {
            $ext = strtolower(pathinfo((string)$d['original_name'], PATHINFO_EXTENSION)) ?: 'pdf';
            $zip->addFile($file, sprintf('%02d', ++$n) . ' ' . trim((string)preg_replace('/[^A-Za-z0-9 ._()-]+/', ' ', ($d['step_no'] ? 'Step ' . $d['step_no'] . ' ' : '') . $d['paper_name'])) . '.' . $ext);
        }
    }
    $zip->close();
    Brgy::log('export', 'project', $id, $p['project_no'] . ' signed copies (' . $n . ')', ['page_key' => $pageKey]);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $p['project_no']) . '_signed_copies.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ------------------------------------------------------------------ actions on a project (view page)
if ($a === 'view' && $id && is_post()) {
    $tab = (string)p('tab', g('tab', 'overview'));
    $back = url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => $tab]);
    $do = (string)p('do');
    $r = trim((string)p('remarks'));
    brgy_action(function () use ($do, $id, $r) {
        switch ($do) {
            case 'go':
                BrgyOps::goDecision($id, (string)p('decision'), $r);
                return p('decision') === 'go' ? 'Go: the project moves to eligibility and compliance.' : 'No bid: the opportunity is closed (an approver can reopen it).';
            case 'phase':
                BrgyOps::movePhase($id, (string)p('phase'), $r);
                return 'Phase changed.';
            case 'bid_submit':
                BrgyOps::submitBid($id, $r);
                return 'The bid was sent for review. A copy of the costing was kept.';
            case 'bid_review':
            case 'bid_approve':
            case 'bid_return':
            case 'bid_reopen':
                BrgyOps::bidStep($id, substr($do, 4), $r);
                return ['bid_review' => 'Bid marked reviewed.', 'bid_approve' => 'Bid approved: the price schedule is now read-only.', 'bid_return' => 'Bid returned to the preparer.', 'bid_reopen' => 'Bid reopened.'][$do];
            case 'lines_save':
                BrgyOps::saveLines($id, array_values(array_filter((array)($_POST['lines'] ?? []), 'is_array')), p('lock_version'));
                return 'Price schedule saved.';
            case 'req_attach':
                BrgyOps::attachRequirement((int)pint('req_id'), brgy_file('file'), pint('doc_id'), ['title' => p('title') ?: null, 'doc_no' => p('doc_no'), 'issued_by' => p('issued_by'),
                    'issue_date' => p('issue_date'), 'valid_until' => p('valid_until'), 'as_company' => p('as_company') === '1']);
                return 'Document filed for the requirement.';
            case 'req_waive':
                BrgyOps::waiveRequirement((int)pint('req_id'), $r);
                return 'Requirement marked as not required.';
            case 'req_add':
                BrgyOps::addProjectRequirement($id, $_POST);
                return 'Requirement added to the checklist.';
            case 'req_refresh':
                Access::require('brgy.projects', 'edit');
                $n = Brgy::buildChecklist($id);
                return $n ? $n . ' requirement(s) added from the library.' : 'The checklist already has every requirement the library suggests.';
            case 'doc_upload':
                Access::require('brgy.vault', 'create');
                $doc = BrgyDocs::store(brgy_file('file') ?? ['error' => UPLOAD_ERR_NO_FILE], ['scope' => 'project', 'project_id' => $id, 'doc_type' => p('doc_type') ?: 'other', 'title' => p('title'),
                    'doc_no' => p('doc_no'), 'issued_by' => p('issued_by'), 'issue_date' => p('issue_date'), 'valid_until' => p('valid_until'), 'tags' => p('tags'),
                    'confidential' => p('confidential') === '1', 'notes' => p('notes')]);
                return 'Document filed (#' . $doc . '). It waits for review in the Document Vault.';
            case 'submission':
                BrgyOps::recordSubmission($id, $_POST, brgy_file('proof'));
                return 'Submission recorded.';
            case 'event_add':
                BrgyOps::addEvent($id, $_POST, brgy_file('file'));
                return 'Recorded.';
            case 'event_done':
                BrgyOps::closeEvent((int)pint('event_id'), $r);
                return 'Marked as answered.';
            case 'result_lost':
                BrgyOps::recordResult($id, 'lost', $_POST);
                return 'Recorded as not awarded.';
            case 'result_cancel':
                BrgyOps::recordResult($id, 'cancelled', ['cancel_reason' => $r]);
                return 'Recorded as cancelled by the entity.';
            case 'result_rank':
                BrgyOps::recordResult($id, 'rank', $_POST);
                return 'Ranking recorded.';
            case 'award':
                BrgyOps::recordAward($id, $_POST, brgy_file('evidence'));
                return 'Award recorded from the entity\'s notice. A Customer:Job and a class were opened for job costing; nothing was posted.';
            case 'contract_dates':
                BrgyOps::saveContractDates($id, $_POST);
                return 'Contract details saved.';
            case 'fulfill':
                BrgyOps::startFulfillment($id);
                return 'Fulfilment started.';
            case 'award_cancel':
                BrgyOps::cancelAward($id, $r, brgy_file('evidence'));
                return 'The award is recorded as cancelled by the entity.';
            case 'security_add':
                BrgyOps::saveSecurity($id, $_POST, brgy_file('file'));
                return 'Security recorded' . (in_array(p('form'), Brgy::SECURITY_CASH_FORMS, true) ? ' and posted (journal entry).' : '.');
            case 'security_close':
                BrgyOps::closeSecurity((int)pint('security_id'), (string)p('outcome'), (string)p('date'), pint('bank_id'), $r);
                return 'Security updated.';
            case 'co_save':
                $cid = BrgyOps::saveChangeOrder($id, $_POST + ['lines' => array_values(array_filter((array)($_POST['co_lines'] ?? []), 'is_array'))], pint('co_id'), p('lock_version'));
                return 'Change order saved as a draft. Send it for review when it is complete.';
            case 'co_submit':
            case 'co_review':
            case 'co_approve':
            case 'co_return':
            case 'co_reopen':
                BrgyOps::changeOrderStep((int)pint('co_id'), substr($do, 3), $r);
                return 'Change order: ' . substr($do, 3) . ' done.';
            case 'co_apply':
                BrgyOps::applyChangeOrder((int)pint('co_id'), $_POST, brgy_file('evidence'));
                return 'Change order applied: the contract lines, amount and due date changed.';
            case 'task_save':
                BrgyOps::saveTask($id, $_POST, pint('task_id'));
                return 'Saved.';
            case 'po_create':
                Access::require('brgy.projects', 'edit');
                if (!Access::can('vend.purchase_orders', 'create') && !Access::isAdmin()) {
                    fail('Your role cannot create purchase orders (Vendors > Create Purchase Orders).');
                }
                $po = BrgyPost::purchaseOrder(Brgy::mustProject($id), (int)pint('vendor_id'), pdate('po_date', today()), array_values(array_filter((array)($_POST['po_lines'] ?? []), 'is_array')), (string)p('memo'));
                return 'Purchase order ' . Txn::load($po)['txn_no'] . ' made, its lines on the job of this contract. Receive it in Vendors as usual.';
            case 'form_create':
                $rel = p('related') !== '' ? explode(':', (string)p('related')) : null;
                $fid = BrgyOps::createForm((int)pint('template_id'), $id, $rel && count($rel) === 2 ? [$rel[0], (int)$rel[1]] : null);
                redirect(url('brgy.forms', ['a' => 'edit', 'id' => $fid]));
            case 'closeout':
                BrgyOps::closeout($id, $r, p('override') === '1');
                return 'Contract closed out.';
            case 'reopen':
                BrgyOps::reopenProject($id, $r);
                return 'Project reopened.';
            // ---- the steps of the SOP (Process tab)
            case 'sop_done':
                return BrgySop::complete($id, (string)p('step'), (array)($_POST['checks'] ?? []), $r);
            case 'sop_save':
                BrgySop::saveStepData($id, (string)p('step'), $_POST);
                return 'Saved.';
            case 'sop_skip':
                BrgySop::skip($id, (string)p('step'), $r);
                return 'Step skipped.';
            case 'sop_reopen':
                BrgySop::reopenStep($id, (string)p('step'), $r);
                return 'The step is open again.';
            case 'sop_details_request':
                BrgySop::requestDetails($id, $r);
                return 'Details requested: a task went to the sales representative.';
            case 'paper_prepare':
                $fid = BrgySop::prepare((int)pint('paper_id'));
                return 'Prepared: ' . DB::value('SELECT form_no FROM brgy_forms WHERE id = :f', ['f' => $fid]) . '. Print it, have it signed, then attach the signed copy.';
            case 'paper_prepare_all':
                $n = BrgySop::prepareStep($id, (string)p('step'));
                return $n ? $n . ' paper(s) prepared from the proformas.' : 'Every paper of the step is already prepared.';
            case 'paper_attach':
                BrgySop::attach((int)pint('paper_id'), brgy_file('file'), (string)p('note'));
                return 'Signed copy attached and filed in the Document Vault.';
            case 'paper_sign':
                BrgySop::markSigned((int)pint('paper_id'), p('signed') === '1');
                return p('signed') === '1' ? 'Marked as signed by the proprietor.' : 'Signature mark removed.';
            case 'paper_waive':
                BrgySop::waive((int)pint('paper_id'), $r);
                return 'Marked as not needed for this transaction.';
            case 'paper_unwaive':
                BrgySop::waive((int)pint('paper_id'), '', true);
                return 'The paper is needed again.';
            case 'paper_add':
                BrgySop::addPaper($id, (string)p('step'), $_POST);
                return 'Paper added to the step.';
            case 'canvass_save':
                BrgySop::saveCanvass($id, $_POST, brgy_file('file'), pint('canvass_id') ?: null);
                return 'Quotation saved.';
            case 'canvass_delete':
                BrgySop::deleteCanvass($id, (int)pint('canvass_id'));
                return 'Quotation removed.';
            case 'pay_release':
                BrgySop::releasePayment($id, $_POST);
                return 'Payment released: the SI, OR and BIR Form 2307 can now be prepared.';
            case 'pay_followup':
                BrgySop::followUp($id, $r, (string)p('next_date'));
                return 'Follow-up logged on the timeline.';
            case 'deliver_all':
                BrgySop::deliverAll($id, $_POST);
                return 'Delivery recorded and accepted in full from the signed IAR.';
            default:
                fail('Unknown action.');
        }
    }, $back);
}

// ------------------------------------------------------------------ new / edit
if (in_array($a, ['new', 'edit'], true)) {
    $p = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $p = Brgy::mustProject((int)$id);
    } else {
        Access::require($pageKey, 'create');
    }
    handle_post(function () use ($p, $pageKey) {
        $newId = BrgyOps::saveProject($_POST, $p ? (int)$p['id'] : null, p('lock_version'));
        $np = Brgy::mustProject($newId);
        flash('success', $p ? 'Project saved.' : (BrgySop::uses($np) ? 'Transaction ' . $np['txn_form_no'] . ' recorded. It follows the ' . BrgySop::PROCESSES[$np['process']] . ': start with step 1 on the Process tab.'
            : 'Opportunity recorded. Its compliance checklist was made from the requirement library; record the go / no-go decision next.'));
        redirect(url($pageKey, ['a' => 'view', 'id' => $newId]));
    });
    $locked = $p && (!in_array($p['bid_status'], ['draft', 'returned'], true) || $p['status'] !== 'open');
    $dis = fn(string $k) => $locked && in_array($k, ['abc_amount', 'mode_id', 'category', 'regime', 'entity_id', 'bid_deadline'], true) ? 'disabled' : '';
    layout_start($p ? 'Edit ' . $p['project_no'] : 'New transaction', brgy_layout(['actions' => btn_link($p ? url($pageKey, ['a' => 'view', 'id' => $p['id']]) : url($pageKey), 'Cancel')]));
    $modes = DB::all('SELECT id, name, regime, max_abc, default_retention_pct FROM brgy_proc_modes WHERE is_active = 1 OR id = :k ORDER BY sort_order', ['k' => (int)($p['mode_id'] ?? 0)]);
    ?>
    <form method="post" class="brgy-form"><?= csrf_field() ?><?= $p ? Brgy::versionField($p['lock_version']) : '' ?>
    <?php if ($locked): ?><div class="alert alert-info small">The bid is <?= h(Brgy::WF[$p['bid_status']] ?? $p['bid_status']) ?>: the entity, ABC, mode, category and deadline are fixed. An approver can reopen the bid to change them.</div>
      <?php foreach (['abc_amount', 'mode_id', 'category', 'regime', 'entity_id', 'bid_deadline'] as $k): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($k === 'bid_deadline' ? brgy_dtl($p[$k]) : (string)$p[$k]) ?>"><?php endforeach; ?>
    <?php endif; ?>
    <?php $proc = (string)fv('process', $p, g('process', BrgySop::ready() ? 'svp' : 'general'));
          $procLocked = $p && ($p['status'] !== 'open' || $p['bid_status'] === 'released' || DB::value("SELECT COUNT(*) FROM brgy_project_steps WHERE project_id = :p AND status <> 'pending'", ['p' => $p['id']])); ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-signpost-split"></i> Process</div><div class="card-body">
      <div class="proc-pick" role="radiogroup" aria-label="Process">
        <?php foreach (BrgySop::PROCESSES as $k => $lbl): ?>
          <label class="proc-opt<?= $procLocked && $proc !== $k ? ' is-off' : '' ?>"><input type="radio" name="process" value="<?= h($k) ?>" <?= $proc === $k ? 'checked' : '' ?> <?= $procLocked && $proc !== $k ? 'disabled' : '' ?> data-process-pick>
            <span class="po-body"><span class="po-title"><?= h($lbl) ?></span><span class="po-text"><?= h(['svp' => 'Transaction form, PR, PO, IAR, RIS, canvass of three suppliers, abstract, DV, then SI, OR and 2307 once paid. Below ' . money(Brgy::setting('brgy_svp_limit')) . '.',
                'bidding' => 'Bidding form, PhilGEPS posting, pre-bid papers, SPA, notarized and eligibility papers, signatures, submission, post-bidding papers, completion, payment.',
                'general' => 'The twelve phases with the compliance checklist, without the SOP steps.'][$k]) ?></span></span></label>
        <?php endforeach; ?>
      </div>
      <?php if ($procLocked): ?><div class="form-text">The process cannot change once steps of the SOP are done or the offer is out.</div><?php endif; ?>
    </div></div>

    <div class="card mb-3" data-process-only="svp bidding"><div class="card-header"><i class="bi bi-ui-checks"></i> Transaction form</div><div class="card-body row g-2">
      <?= input_field('txn_form_no', 'Series no.', fv('txn_form_no', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="30" placeholder="' . ($p ? '' : 'Given on saving') . '"']) ?>
      <?= input_field('txn_date', 'Date', fv('txn_date', $p, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('tradename', 'Tradename', fv('tradename', $p, Brgy::setting('brgy_tradename')), ['class' => 'col-md-3', 'extra' => 'maxlength="160"']) ?>
      <?= select_field('txn_type', 'Type', BrgySop::txnTypes(), fv('txn_type', $p, 'purchases'), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= input_field('requestor', 'Requestor', fv('requestor', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="120"']) ?>
      <?= input_field('requestor_position', 'Position of the requestor', fv('requestor_position', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="120" placeholder="Requisitioner"']) ?>
      <?= input_field('inspector', 'Inspector', fv('inspector', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="120"']) ?>
      <?= input_field('inspector_position', 'Position of the inspector', fv('inspector_position', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="120"']) ?>
      <?= input_field('purpose', 'Purpose', fv('purpose', $p), ['class' => 'col-md-6', 'extra' => 'maxlength="255" placeholder="For Barangay Office Use"']) ?>
      <?= brgy_user_select('sales_rep_id', 'Sales representative', fv('sales_rep_id', $p), 'col-md-3', 'Choose') ?>
      <?= input_field('fund', 'Fund', fv('fund', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="60" placeholder="General Fund"']) ?>
    </div></div>

    <div class="card mb-3" data-process-only="svp bidding"><div class="card-header"><i class="bi bi-files"></i> Numbers and dates of the barangay's papers</div><div class="card-body row g-2">
      <?php foreach ([['reference_no', 'pr_date', 'Purchase request (PR)'], ['po_no', 'po_date', 'Purchase order (PO)'], ['ris_no', 'ris_date', 'Requisition and issue slip (RIS)'], ['iar_no', 'iar_date', 'Inspection and acceptance report (IAR)'], ['dv_no', 'dv_date', 'Disbursement voucher (DV)']] as [$no, $dt, $lbl]): ?>
        <div class="col-md-6 col-xl-4"><label class="form-label"><?= h($lbl) ?></label><div class="input-group input-group-sm">
          <input class="form-control" name="<?= h($no) ?>" maxlength="<?= $no === 'reference_no' ? 80 : 40 ?>" placeholder="No." value="<?= h((string)fv($no, $p)) ?>" <?= $no === 'po_no' && $p && $p['status'] !== 'open' ? 'readonly' : '' ?> <?= $no === 'reference_no' ? 'data-sync="reference_no"' : '' ?>>
          <input class="form-control" type="date" name="<?= h($dt) ?>" value="<?= h((string)fv($dt, $p)) ?>" aria-label="Date"></div></div>
      <?php endforeach; ?>
      <?= input_field('delivery_date', 'Date of delivery (PO)', fv('delivery_date', $p), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('delivery_term', 'Delivery term (PO)', fv('delivery_term', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="120"']) ?>
      <div class="col-12 form-text">These numbers and dates print on the forms the company prepares for the barangay. Blank ones print as blank lines.</div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-megaphone"></i> The opportunity</div><div class="card-body row g-2">
      <div class="col-md-8"><label class="form-label">Title (as in the notice / RFQ)</label><input class="form-control form-control-sm" name="title" maxlength="255" required value="<?= h(fv('title', $p)) ?>"></div>
      <?= select_field('entity_id', 'Procuring entity', Brgy::entities(), fv('entity_id', $p, gint('entity_id')), ['blank' => '', 'class' => 'col-md-4', 'required' => true, 'extra' => 'data-combo ' . $dis('entity_id')]) ?>
      <?= select_field('category', 'Category', Brgy::CATEGORIES, fv('category', $p, 'goods'), ['class' => 'col-md-3', 'extra' => $dis('category')]) ?>
      <div class="col-md-5"><label class="form-label">Procurement mode</label><select class="form-select form-select-sm" name="mode_id" id="pMode" <?= $dis('mode_id') ?>><option value=""></option>
        <?php foreach ($modes as $m): ?><option value="<?= (int)$m['id'] ?>" data-regime="<?= h($m['regime']) ?>" data-ret="<?= h((string)$m['default_retention_pct']) ?>" <?= (int)fv('mode_id', $p) === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['name'] . ($m['regime'] === 'ra9184' ? ' (RA 9184)' : '') . ($m['max_abc'] !== null ? ', up to ' . money($m['max_abc']) : '')) ?></option><?php endforeach; ?>
      </select></div>
      <?= select_field('regime', 'Procurement law that governs it', Brgy::REGIMES, fv('regime', $p, 'ra12009'), ['class' => 'col-md-4', 'extra' => 'id="pRegime" ' . $dis('regime')]) ?>
      <?= input_field('reference_no', 'PR / RFQ / ITB no.', fv('reference_no', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="80" data-sync="reference_no"']) ?>
      <?= input_field('philgeps_ref', 'PhilGEPS reference', fv('philgeps_ref', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="40"']) ?>
      <?= input_field('source_url', 'Source link (notice posting)', fv('source_url', $p), ['class' => 'col-md-6', 'type' => 'url', 'extra' => 'placeholder="https://"']) ?>
      <?= input_field('posting_date', 'Posting date', fv('posting_date', $p), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('abc_amount', 'Approved budget (ABC)', fv('abc_amount', $p), ['class' => 'col-md-3', 'extra' => 'inputmode="decimal" ' . $dis('abc_amount')]) ?>
      <?= input_field('funding_source', 'Source of funds', fv('funding_source', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="160"']) ?>
      <?= input_field('end_user', 'End-user / requesting office', fv('end_user', $p), ['class' => 'col-md-3', 'extra' => 'maxlength="120"']) ?>
      <?= input_field('delivery_site', 'Delivery site', fv('delivery_site', $p), ['class' => 'col-md-6', 'extra' => 'maxlength="255"']) ?>
      <?= input_field('delivery_days', 'Delivery / completion (calendar days)', fv('delivery_days', $p), ['class' => 'col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= select_field('site_id', 'Goods leave from (site)', Lookup::sites(), fv('site_id', $p), ['blank' => '', 'class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
    </div></div>

    <div class="card mb-3" data-process-hide="svp"><div class="card-header"><i class="bi bi-calendar-event"></i> Key dates</div><div class="card-body row g-2">
      <?php foreach (['site_inspection_at' => 'Site inspection', 'prebid_at' => 'Pre-bid conference', 'clarification_deadline' => 'Last day for questions', 'bid_deadline' => 'Bid / quotation deadline', 'bid_opening_at' => 'Bid opening'] as $k => $lbl): ?>
        <div class="col-md-4 col-lg"><label class="form-label"><?= h($lbl) ?></label><input class="form-control form-control-sm" type="datetime-local" name="<?= h($k) ?>" value="<?= h(is_post() ? (string)p($k) : brgy_dtl($p[$k] ?? null)) ?>" <?= $dis($k) ?>></div>
      <?php endforeach; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-file-text"></i> Terms</div><div class="card-body row g-2">
      <?= input_field('retention_pct', 'Retention %', fv('retention_pct', $p, Brgy::setting('brgy_default_retention_pct')), ['class' => 'col-md-2', 'extra' => 'inputmode="decimal" id="pRet"']) ?>
      <?= input_field('ld_rate_pct', 'Liquidated damages % per day', fv('ld_rate_pct', $p, Brgy::setting('brgy_default_ld_rate')), ['class' => 'col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('warranty_months', 'Warranty (months)', fv('warranty_months', $p), ['class' => 'col-md-2', 'type' => 'number', 'step' => '1']) ?>
      <?= select_field('terms_id', 'Payment terms (days)', Lookup::terms(), fv('terms_id', $p), ['blank' => '', 'class' => 'col-md-2', 'extra' => 'data-no-combo']) ?>
      <?= input_field('payment_terms', 'Payment terms (as written in the RFQ)', fv('payment_terms', $p), ['class' => 'col-md-4', 'extra' => 'maxlength="255"']) ?>
      <div class="col-12 form-text">Retention and liquidated damages are what this contract states; the program never assumes a rate. The defaults come from Contracting Settings and the procurement mode.</div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-people"></i> People accountable for the project</div><div class="card-body row g-2">
      <?= brgy_user_select('preparer_id', 'Preparer (maker)', fv('preparer_id', $p, Auth::id()), 'col-md-4') ?>
      <?= brgy_user_select('checker_id', 'Checker / reviewer', fv('checker_id', $p), 'col-md-4', 'Anyone with the review right') ?>
      <?= brgy_user_select('approver_id', 'Approver', fv('approver_id', $p), 'col-md-4', 'Anyone with the approve right') ?>
      <?= brgy_user_select('submitter_id', 'Authorized submitter', fv('submitter_id', $p), 'col-md-4', 'Not named yet') ?>
      <?= brgy_user_select('pm_id', 'Project manager', fv('pm_id', $p), 'col-md-4', '') ?>
      <?= brgy_user_select('collector_id', 'Collector', fv('collector_id', $p), 'col-md-4', '') ?>
      <div class="col-12 form-text">The preparer, checker and approver have to be three different people<?= Brgy::on('brgy_sod_strict') ? '' : ' (switched off on Contracting Settings)' ?>. Only the authorized submitter records the bid submission.</div>
    </div></div>

    <div class="card mb-3"><div class="card-body row g-2">
      <div class="col-md-6"><label class="form-label">Specifications / scope (summary)</label><textarea class="form-control form-control-sm" name="specs" rows="4"><?= h(fv('specs', $p)) ?></textarea></div>
      <div class="col-md-6"><label class="form-label">Eligibility notes</label><textarea class="form-control form-control-sm" name="eligibility_notes" rows="4"><?= h(fv('eligibility_notes', $p)) ?></textarea></div>
      <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h(fv('notes', $p)) ?></textarea></div>
    </div></div>
    <div class="sticky-actions"><?= save_buttons($p ? 'Save project' : 'Record the transaction', $p ? url($pageKey, ['a' => 'view', 'id' => $p['id']]) : url($pageKey), false) ?></div>
    </form>
    <script>
    (function () {
      const m = document.getElementById('pMode'), r = document.getElementById('pRegime'), ret = document.getElementById('pRet');
      if (!m) return;
      m.addEventListener('change', () => { const o = m.options[m.selectedIndex]; if (o && o.dataset.regime && !r.disabled) r.value = o.dataset.regime; if (o && o.dataset.ret !== '' && ret && (ret.value === '' || parseFloat(ret.value) === 0)) ret.value = o.dataset.ret; });
    })();
    (function () {
      // the cards of a process show only for it; the PR no. is the same box twice (transaction papers and the opportunity)
      const picks = document.querySelectorAll('[data-process-pick]');
      const apply = () => {
        const v = (document.querySelector('[data-process-pick]:checked') || {}).value || 'general';
        document.querySelectorAll('[data-process-only]').forEach((c) => { const on = c.dataset.processOnly.split(' ').includes(v); c.hidden = !on; c.querySelectorAll('input,select,textarea').forEach((i) => { i.disabled = !on || i.dataset.lockedDisabled === '1'; }); });
        document.querySelectorAll('[data-process-hide]').forEach((c) => { c.hidden = c.dataset.processHide.split(' ').includes(v); });
        const refs = document.querySelectorAll('[data-sync="reference_no"]');
        refs.forEach((x) => { x.disabled = false; });
        if (refs.length === 2) { const shown = [...refs].find((x) => !x.closest('[hidden]')); refs.forEach((x) => { if (x !== shown) x.disabled = true; }); }
      };
      picks.forEach((x) => x.addEventListener('change', apply));
      document.querySelectorAll('[data-sync="reference_no"]').forEach((x) => x.addEventListener('input', () => document.querySelectorAll('[data-sync="reference_no"]').forEach((y) => { if (y !== x) y.value = x.value; })));
      apply();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ view
if ($a === 'view' && $id) {
    $p = Brgy::project($id);
    if (!$p) {
        flash('warning', 'Project not found.');
        redirect(url($pageKey));
    }
    require APP_ROOT . '/modules/brgy/_project_view.php';
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$fEntity = gint('entity_id');
$fPhase = (string)g('phase');
$fStatus = (string)g('status', 'live');
$fCat = (string)g('category');
$sql = 'SELECT p.*, e.official_name AS entity_name, m.name AS mode_name, s.step_no, s.title AS step_title FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id LEFT JOIN brgy_proc_modes m ON m.id = p.mode_id
          LEFT JOIN brgy_sop_steps s ON s.code = p.sop_step WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (p.project_no LIKE :q OR p.title LIKE :q OR p.reference_no LIKE :q OR p.contract_no LIKE :q OR p.po_no LIKE :q OR p.txn_form_no LIKE :q OR p.cheque_no LIKE :q OR e.official_name LIKE :q)';
    $params['q'] = "%$q%";
}
if ($fEntity) {
    $sql .= ' AND p.entity_id = :e';
    $params['e'] = $fEntity;
}
if (isset(Brgy::PHASES[$fPhase])) {
    $sql .= ' AND p.phase = :ph';
    $params['ph'] = $fPhase;
}
if ($fStatus === 'live') {
    $sql .= " AND p.status IN ('open', 'awarded')";
} elseif (isset(Brgy::STATUSES[$fStatus])) {
    $sql .= ' AND p.status = :s';
    $params['s'] = $fStatus;
}
$fProc = (string)g('process');
if (isset(BrgySop::PROCESSES[$fProc])) {
    $sql .= ' AND p.process = :pr';
    $params['pr'] = $fProc;
}
if (isset(Brgy::CATEGORIES[$fCat])) {
    $sql .= ' AND p.category = :c';
    $params['c'] = $fCat;
}
$rows = DB::all($sql . ' ORDER BY FIELD(p.status, \'open\', \'awarded\', \'closed\', \'lost\', \'no_bid\', \'cancelled\'), COALESCE(p.bid_deadline, p.due_date, p.created_at) DESC, p.id DESC', $params);

if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    csv_download('barangay_projects.csv', ['Project no.', 'Transaction form', 'Title', 'Procuring entity', 'Category', 'Mode', 'Process', 'SOP step', 'Phase', 'Status', 'Payment', 'ABC', 'Bid amount', 'Contract amount', 'Bid deadline', 'Due'],
        array_map(fn($r) => [$r['project_no'], $r['txn_form_no'], $r['title'], $r['entity_name'], $r['category'], $r['mode_name'], $r['process'], $r['step_no'] ? $r['step_no'] . ' ' . $r['step_title'] : '', Brgy::phaseLabel($r['phase']), $r['status'],
            $r['process'] !== 'general' ? $r['payment_status'] : '', $r['abc_amount'], $r['bid_amount'], r2((float)$r['contract_amount'] + (float)$r['co_amount']), $r['bid_deadline'], $r['due_date']], $rows));
}

layout_start('Projects & Contracts', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'New transaction', 'btn-primary', 'plus-lg', 'create')
    . (Access::can($pageKey, 'print') ? ' ' . btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Project no., title, PR, PO, cheque, entity" data-live-filter="#projTable tbody tr" data-live-count="#projCount" data-live-enter-submits></div>
  <?= select_field('entity_id', 'Procuring entity', Brgy::entities(false), $fEntity, ['blank' => 'All', 'class' => 'col-md-2']) ?>
  <?= select_field('status', 'Status', ['live' => 'Open and awarded'] + Brgy::STATUSES + ['all' => 'All'], $fStatus, ['class' => 'col-md-2', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <?= select_field('process', 'Process', BrgySop::PROCESSES, $fProc, ['blank' => 'All processes', 'class' => 'col-md-2', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <?= select_field('phase', 'Phase', array_map(fn($x) => $x[0] . ' ' . $x[1], Brgy::PHASES), $fPhase, ['blank' => 'All phases', 'class' => 'col-md-2', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 proj-table" id="projTable">
  <thead><tr><th>Project</th><th>Procuring entity</th><th>Process / phase</th><th>Status</th><th>Next date</th><th class="num">ABC</th><th class="num">Bid / contract</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $next = in_array($r['phase'], ['intake', 'eligibility', 'costing', 'approval'], true) && $r['status'] === 'open' ? ['Bid due', $r['bid_deadline']] : ($r['status'] === 'awarded' && $r['due_date'] ? ['Due', $r['due_date']] : null);
      $late = $next && strtotime((string)$next[1]) < time() && !in_array($r['phase'], ['billing', 'collection', 'closeout'], true); ?>
    <tr>
      <td class="wrap-link"><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= h($r['project_no']) ?></b></a><div class="small"><?= h($r['title']) ?></div>
        <div class="small text-muted"><?= h(Brgy::label(Brgy::CATEGORIES, $r['category'])) ?><?= $r['mode_name'] ? ', ' . h($r['mode_name']) : '' ?><?= $r['reference_no'] ? ', ' . h($r['reference_no']) : '' ?></div></td>
      <td class="small"><?= h($r['entity_name']) ?></td>
      <td class="small"><?php if (in_array($r['process'], ['svp', 'bidding'], true)): ?><?= h($r['process'] === 'svp' ? 'Small value' : 'Bidding') ?><?= $r['step_no'] && in_array($r['status'], ['open', 'awarded'], true) ? '<div class="text-muted">Step ' . (int)$r['step_no'] . ': ' . h($r['step_title']) . '</div>' : '' ?><?= $r['process'] === 'svp' && $r['status'] === 'awarded' && $r['payment_status'] !== 'released' ? '<div class="text-warning-emphasis">Waiting for payment</div>' : '' ?>
        <?php else: ?><span class="text-nowrap"><?= h(Brgy::phaseLabel($r['phase'])) ?></span><?php endif; ?></td>
      <td><?= Brgy::st($r['status']) ?><?php if ($r['status'] === 'open' && $r['bid_status'] !== 'draft'): ?><div class="small"><?= Brgy::st($r['bid_status'], 'Bid ' . strtolower(Brgy::WF[$r['bid_status']] ?? '')) ?></div><?php endif; ?></td>
      <td class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : '' ?>"><?= $next ? h($next[0]) . ' ' . h(fdate($next[1])) . '<div class="text-muted">' . h(brgy_rel($next[1])) . '</div>' : '' ?></td>
      <td class="num"><?= money($r['abc_amount'], true) ?></td>
      <td class="num"><?= $r['status'] === 'awarded' || $r['status'] === 'closed' ? money((float)$r['contract_amount'] + (float)$r['co_amount']) : money($r['bid_amount'], true) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted py-4 text-center">No projects for these filters. <?= Access::can($pageKey, 'create') ? '<a href="' . h(url($pageKey, ['a' => 'new'])) . '">Record a new opportunity</a>.' : '' ?></td></tr><?php endif; ?>
  </tbody>
</table></div><div class="card-body py-2 small text-muted"><span id="projCount"><?= count($rows) ?></span> project(s)</div></div>
<?php
layout_end();
