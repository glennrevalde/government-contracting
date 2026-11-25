<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Projects & Contracts > a transaction > Process (the company's SOP, step by step).
 * Included by modules/brgy/_project_view.php with $p, $id, $pageKey, $self, $canEdit, $hiddenTab.
 */

BrgySop::ensure($p);
$p = Brgy::mustProject($id);
$S = BrgySop::state($p);
$isApprover = Access::can('brgy.projects', 'approve') || Access::can('brgy.approvals', 'approve');
$canForms = Access::can('brgy.forms', 'create');
$canAttach = $canEdit || Access::can('brgy.vault', 'create');
$cur = $S['current'];
$allPapers = [];
foreach ($S['steps'] as $s) {
    foreach ($s['papers_list'] as $pp) {
        $allPapers[] = $pp + ['step_no' => $s['step_no']];
    }
}
$signedCount = count(array_filter($allPapers, fn($x) => $x['state'] === 'done'));
$stateText = ['done' => ['check-circle-fill', 'is-done'], 'onfile' => ['archive', 'is-done'], 'prepared' => ['file-earmark-check', 'is-prep'], 'todo' => ['circle', 'is-todo'],
    'waived' => ['dash-circle', 'is-muted'], 'held' => ['pause-circle', 'is-held']];
$post = fn(string $do, string $inner, string $cls = '', array $hidden = []) => '<form method="post" action="' . h($self()) . '" class="' . h($cls) . '" enctype="multipart/form-data">' . csrf_field()
    . '<input type="hidden" name="do" value="' . h($do) . '">' . $hiddenTab . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">', array_keys($hidden), $hidden)) . $inner . '</form>';

/** One paper: its state and what can be done with it */
$paperRow = function (array $pp, array $step) use ($self, $canEdit, $canAttach, $canForms, $isApprover, $stateText, $post, $p): string {
    [$icon, $cls] = $stateText[$pp['state']];
    $flags = [];
    if ((int)$pp['copies'] > 1) {
        $flags[] = (int)$pp['copies'] . ' copies';
    }
    if ($pp['needs_notary']) {
        $flags[] = 'notarized';
    }
    if ($pp['needs_ctc']) {
        $flags[] = 'certified true copy';
    }
    if ($pp['signed_at'] && !$pp['document_id']) {
        $flags[] = 'signed by the proprietor';
    }
    $btn = [];
    $live = in_array($p['status'], ['open', 'awarded'], true);
    if ($pp['form_id']) {
        $btn[] = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('brgy.forms', ['a' => 'print', 'id' => $pp['form_id']])) . '" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Print</a>';
        $btn[] = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('brgy.forms', ['a' => 'view', 'id' => $pp['form_id']])) . '"><i class="bi bi-pencil-square"></i> ' . h((string)$pp['form_no']) . '</a>';
    } elseif ($pp['template_code'] && $canForms && $live && $pp['status'] === 'open' && !$pp['document_id'] && $pp['state'] !== 'held') {
        $btn[] = $post('paper_prepare', '<button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-plus"></i> Prepare</button>', 'd-inline', ['paper_id' => $pp['id']]);
    }
    if ($pp['document_id']) {
        $btn[] = '<a class="btn btn-sm btn-outline-success" href="' . h(url('brgy.vault', ['a' => 'file', 'id' => $pp['document_id']])) . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Signed copy</a>';
    } elseif (!empty($pp['file_doc'])) {
        $btn[] = '<a class="btn btn-sm btn-outline-success" href="' . h(url('brgy.vault', ['a' => 'file', 'id' => $pp['file_doc']])) . '" target="_blank" rel="noopener"><i class="bi bi-archive"></i> On file</a>';
    }
    if ($canAttach && $live && $pp['status'] === 'open' && $pp['state'] !== 'held') {
        $btn[] = '<button type="button" class="btn btn-sm ' . ($pp['document_id'] ? 'btn-outline-secondary' : 'btn-outline-primary') . '" data-bs-toggle="modal" data-bs-target="#mPaperAttach" data-paper-id="' . (int)$pp['id']
            . '" data-paper-name="' . h($pp['name']) . '"><i class="bi bi-paperclip"></i> ' . ($pp['document_id'] ? 'Replace' : ($pp['source'] === 'company' ? 'Attach a copy' : 'Attach signed')) . '</button>';
    }
    if ($step['action'] === 'signatures' || ($pp['needs_signature'] && $pp['source'] === 'prepare' && $step['state'] === 'pending' && $canEdit && $live && !$pp['document_id'] && $pp['form_id'] && $p['process'] === 'bidding')) {
        if ($canEdit && $live && !$pp['document_id'] && $pp['status'] === 'open') {
            $btn[] = $post('paper_sign', '<button class="btn btn-sm ' . ($pp['signed_at'] ? 'btn-success' : 'btn-outline-success') . '"><i class="bi bi-pen"></i> ' . ($pp['signed_at'] ? 'Signed' : 'Mark signed') . '</button>',
                'd-inline', ['paper_id' => $pp['id'], 'signed' => $pp['signed_at'] ? 0 : 1]);
        }
    }
    if ($isApprover && $live && !$pp['document_id'] && $pp['state'] !== 'held') {
        $btn[] = $pp['status'] === 'waived'
            ? $post('paper_unwaive', '<button class="btn btn-sm btn-link">Needed again</button>', 'd-inline', ['paper_id' => $pp['id']])
            : '<button type="button" class="btn btn-sm btn-link text-secondary" data-bs-toggle="modal" data-bs-target="#mPaperWaive" data-paper-id="' . (int)$pp['id'] . '" data-paper-name="' . h($pp['name']) . '">Not needed</button>';
    }
    return '<div class="paper-row ' . $cls . '"><div class="paper-main"><i class="bi bi-' . $icon . ' paper-ico"></i><div><div class="paper-name">' . h($pp['name']) . '</div>'
        . '<div class="paper-meta">' . h($pp['why']) . ($flags ? '<span class="paper-flags">' . h(implode(', ', $flags)) . '</span>' : '') . '</div></div></div>'
        . '<div class="paper-btns">' . implode('', $btn) . '</div></div>';
};

$money = Brgy::money($p);
$w = BrgySop::dvAmounts($p);   // v34: the amounts of the DV (its gross, VAT and EWT once entered on it)
$lt = Brgy::lineTotals($id);
// v39: the price schedule is a step of its own (step 4 of the small value procurement); the payment (cheque) has a box of
// its own below the steps when no step of the process records it
$pricingStep = BrgySop::hasPricing((string)$p['process']);
$payStep = (bool)array_filter($S['steps'], fn($x) => in_array($x['action'], ['payment', 'billing_docs'], true));
/** The payment: the cheque released or awaited, the follow-ups, and once released the delivery, invoice and receipt */
$payBox = function (string $action) use ($p, $id, $w, $canEdit): string {
    $live = in_array($p['status'], ['open', 'awarded'], true);
    $released = $p['payment_status'] === 'released';
    ob_start(); ?>
              <div class="pay-box <?= $released ? 'is-released' : 'is-waiting' ?>">
                <div class="pay-state"><i class="bi bi-<?= $released ? 'cash-coin' : 'hourglass-split' ?>"></i>
                  <div><b><?= $released ? 'Payment released' : 'Payment not released yet' ?></b>
                    <div class="small"><?= $released ? 'Cheque ' . h($p['cheque_no']) . ' dated ' . h(fdate($p['cheque_date'])) . ($p['cheque_bank'] ? ', ' . h($p['cheque_bank']) : '') . ', ' . money($p['cheque_amount'])
                        . (!empty($p['cheque_tradename']) ? ', payable to ' . h($p['cheque_tradename']) : '')
                        : ($action === 'billing_docs' ? 'Hold the SI, OR and 2307; follow up with the barangay and wait.' : 'Follow up with the barangay until the cheque is ready.') . ' Expected cheque: ' . money($w['net']) . ' (' . money($w['gross']) . ' less ' . money($w['vat']) . ' VAT and ' . money($w['ewt']) . ' income tax withheld).' ?></div></div></div>
                <?php if (!$released && ($canEdit || Access::can('brgy.collections', 'create')) && $live): ?>
                  <div class="d-flex flex-wrap gap-1 mt-2"><button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#mPayRelease"><i class="bi bi-check2-circle"></i> Cheque ready</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mFollow"><i class="bi bi-telephone-outbound"></i> Log a follow-up</button></div>
                <?php endif; ?>
                <?php $fu = DB::all("SELECT * FROM brgy_events WHERE project_id = :p AND event_type = 'payment' ORDER BY event_at DESC LIMIT 4", ['p' => $id]);
                if ($fu): ?><ul class="small mb-0 mt-2 ps-3"><?php foreach ($fu as $e): ?><li><?= h(fdate(substr((string)$e['event_at'], 0, 10))) ?>: <?= h($e['title']) ?></li><?php endforeach; ?></ul><?php endif; ?>
              </div>
              <?php if ($action !== 'payment' && $released && $p['status'] === 'awarded'):
                  Brgy::refreshLines($id);
                  $m2 = Brgy::money(Brgy::mustProject($id)); ?>
                <div class="link-list mt-2">
                  <div class="ll-row"><span><b>Delivery and acceptance (IAR)</b><span class="small text-muted d-block"><?= money($m2['accepted']) ?> of <?= money($m2['contract']) ?> accepted</span></span>
                    <span><?php if ($m2['accepted'] < $m2['contract'] - 0.004 && Access::can('brgy.deliveries', 'create')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mDeliverAll"><i class="bi bi-truck"></i> Delivered and accepted</button><?php else: ?><span class="small text-success"><i class="bi bi-check-lg"></i> Accepted</span><?php endif; ?></span></div>
                  <?php // v36: a pack not invoiced yet is opened from here; another pack is offered only for accepted quantities in no pack
                  $pend2 = Brgy::pendingBillings($id);
                  $newPack = $m2['accepted'] > $m2['billed'] + $m2['billing_draft'] + 0.004 && $m2['billed'] + $m2['billing_draft'] < $m2['contract'] - 0.004; ?>
                  <div class="ll-row"><span><b>Sales invoice</b><span class="small text-muted d-block"><?= money($m2['billed']) ?> invoiced<?= $pend2 ? ', ' . money($m2['billing_draft']) . ' in billing pack' . (count($pend2) > 1 ? 's ' : ' ') . h(implode(', ', array_map(fn($x) => $x['billing_no'] . ' (' . (Brgy::WF[$x['status']] ?? $x['status']) . ')', $pend2))) . ', not invoiced yet' : '' ?></span></span>
                    <span class="d-flex flex-wrap gap-1 justify-content-end"><?php foreach ($pend2 as $x): ?><?= page_link('brgy.billing', ['a' => 'view', 'id' => $x['id']], 'Open ' . $x['billing_no'], 'btn-outline-primary', 'receipt') ?><?php endforeach; ?>
                      <?= $newPack ? page_link('brgy.billing', ['a' => 'new', 'project_id' => $id], 'Make the billing pack', 'btn-outline-primary', 'receipt', 'create') : '' ?>
                      <?= !$pend2 && !$newPack ? '<span class="small text-success"><i class="bi bi-check-lg"></i> Invoiced</span>' : '' ?></span></div>
                  <div class="ll-row"><span><b>Official receipt / payment received</b><span class="small text-muted d-block"><?= money($m2['cash']) ?> collected, <?= money($m2['taxes']) ?> taxes withheld</span></span>
                    <span><?= $m2['ar_open'] > 0.004 ? page_link('brgy.collections', ['a' => 'new', 'project_id' => $id, 'check_no' => $p['cheque_no']], 'Record the payment', 'btn-outline-primary', 'cash-coin', 'create') : ($m2['cash'] > 0 ? '<span class="small text-success"><i class="bi bi-check-lg"></i> Recorded</span>' : '') ?></span></div>
                </div>
              <?php endif; ?>
<?php
    return (string)ob_get_clean();
};
?>
<div class="sop-head">
  <div class="sop-head-main">
    <div class="sop-head-title"><?= h(BrgySop::PROCESSES[$p['process']]) ?></div>
    <div class="small text-muted">Transaction form <?= h($p['txn_form_no'] ?: 'not numbered') ?><?= $p['txn_type'] ? ', ' . h(BrgySop::txnTypes()[$p['txn_type']] ?? $p['txn_type']) : '' ?><?= $p['sales_rep_id'] ? ', sales representative ' . h(Brgy::userName((int)$p['sales_rep_id'])) : '' ?></div>
    <div class="sop-progress" role="progressbar" aria-valuenow="<?= $S['pct'] ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $S['pct'] ?>%"></span></div>
    <div class="small"><?php if ($S['ended']): ?><span class="text-danger"><?= h(Brgy::label(Brgy::STATUSES, $p['status'])) ?>: the process ends here.</span>
      <?php elseif ($cur): ?><b><?= $S['done'] ?> of <?= $S['total'] ?> steps done.</b> Now: step <?= (int)$S['steps'][$cur]['step_no'] ?>, <?= h($S['steps'][$cur]['title']) ?>
      <?php else: ?><b class="text-success">Every step is done.</b><?php endif; ?></div>
  </div>
  <div class="sop-head-side">
    <?= brgy_figures(['Payment' => [$p['payment_status'] === 'released' ? 'Released' : 'Waiting', $p['payment_status'] === 'released' ? 'good' : 'warn', $p['cheque_no'] ? 'Cheque ' . h($p['cheque_no']) : 'No cheque yet'],
        'Signed copies' => [$signedCount . ' of ' . count($allPapers), '', 'papers attached']]) ?>
    <div class="d-flex flex-wrap gap-1 mt-2 justify-content-end">
      <?php if ($signedCount): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['a' => 'papers_zip', 'id' => $id])) ?>"><i class="bi bi-file-zip"></i> Download signed copies</a><?php endif; ?>
      <a class="btn btn-sm btn-outline-secondary" href="#allPapers"><i class="bi bi-list-check"></i> All papers</a>
    </div>
  </div>
</div>

<div class="sop-grid">
  <nav class="sop-nav" aria-label="Steps of the SOP">
    <details class="sop-nav-box" open>
      <summary>Steps</summary>
      <?php $sec = null; foreach ($S['steps'] as $code => $s): if ($s['section'] !== $sec): $sec = $s['section']; ?>
        <div class="sop-nav-sec"><?= h($s['section'] . '. ' . $s['section_title']) ?></div>
      <?php endif; $st = $code === $cur ? 'now' : ($s['state'] === 'done' ? 'done' : ($s['state'] === 'skipped' ? 'skip' : 'next')); ?>
        <a class="sop-nav-item is-<?= $st ?>" href="#step-<?= h($code) ?>" data-open-step="<?= h($code) ?>"><span class="sn-dot"><?= $st === 'done' ? '<i class="bi bi-check-lg"></i>' : ($st === 'skip' ? '<i class="bi bi-dash"></i>' : (int)$s['step_no']) ?></span><span class="sn-name"><?= h($s['title']) ?></span></a>
      <?php endforeach; ?>
    </details>
  </nav>

  <div class="sop-steps">
  <?php foreach ($S['steps'] as $code => $s):
      $isCur = $code === $cur && !$S['ended'];
      $st = $s['state'];
      $checks = BrgySop::stepChecks($s);
      $ticked = array_filter(explode('|', (string)$s['row']['checks_done']));
      $gate = $isCur ? BrgySop::gate($p, $s, $checks) : [];
      $live = in_array($p['status'], ['open', 'awarded'], true); ?>
    <details class="step-card is-<?= $isCur ? 'now' : $st ?>" id="step-<?= h($code) ?>" <?= $isCur ? 'open' : '' ?>>
      <summary class="step-head">
        <span class="step-no"><?= $st === 'done' ? '<i class="bi bi-check-lg"></i>' : ($st === 'skipped' ? '<i class="bi bi-dash"></i>' : (int)$s['step_no']) ?></span>
        <span class="step-title"><?= h($s['title']) ?><span class="step-sub"><?= h($s['section'] . '. ' . $s['section_title']) ?></span></span>
        <span class="step-state"><?= $st === 'done' ? 'Done ' . h(fdate(substr((string)$s['row']['done_at'], 0, 10))) . ' by ' . h(Brgy::userName((int)$s['row']['done_by'])) : ($st === 'skipped' ? 'Skipped' : ($isCur ? 'Now' : 'To do')) ?></span>
      </summary>
      <div class="step-body">
        <p class="step-instr"><?= h((string)$s['instructions']) ?></p>
        <?php if ($s['role_hint']): ?><div class="small text-muted mb-2"><i class="bi bi-person"></i> <?= h($s['role_hint']) ?></div><?php endif; ?>
        <?php if ($st !== 'pending' && $s['row']['remarks']): ?><div class="small mb-2"><b>Remarks:</b> <?= h($s['row']['remarks']) ?></div><?php endif; ?>

        <?php // ------------------------------------------------ what the step works on
        switch ($s['action']):
            case 'details': ?>
              <dl class="dl-grid small mb-2">
                <dt>Project title</dt><dd><?= h($p['title']) ?></dd>
                <dt>Approved budget</dt><dd><?= (float)$p['abc_amount'] > 0 ? money($p['abc_amount']) : ($p['process'] === 'svp' ? '<span class="text-muted">not entered</span>' : '<span class="text-danger">not entered</span>') ?></dd>
                <dt>Requestor</dt><dd><?= h(trim((string)$p['requestor'])) ?: '<span class="text-danger">not entered</span>' ?></dd>
                <dt>Inspector</dt><dd><?= h(trim((string)$p['inspector'])) ?: '<span class="text-danger">not entered</span>' ?></dd>
                <?php if (!$pricingStep): // v39: with the price schedule as a step of its own (step 4), the items are worked on there ?>
                <dt>Items / particulars</dt><dd><?= $lt['lines'] ? $lt['lines'] . ' line(s), ' . money($lt['bid']) : '<span class="text-danger">none yet</span>' ?> <a href="<?= h($self(['tab' => 'lines'])) ?>">Price schedule</a></dd>
                <?php endif; ?>
                <dt>Purpose</dt><dd><?= h((string)$p['purpose']) ?></dd>
                <?php $est = Brgy::v34Ready() ? Brgy::estimates($p) : null;
                if ($est && $est['any']): $pc = fn(?float $r) => $r === null ? '' : ' (' . Brgy::rateKey($r) . '%)'; ?>
                  <dt>Estimates and targets</dt><dd><div class="est-sum">
                    <span>Gross <b><?= money($est['gross']) ?></b></span>
                    <span>Withholding tax<?= h($pc($est['wt_rate'])) ?> <b><?= money($est['wt']) ?></b></span>
                    <span>Cheque amount <b><?= money($est['cheque']) ?></b></span>
                    <?php if ($est['sop'] !== null): ?><span>SOP<?= h($pc($est['sop_rate'])) ?> <b><?= money($est['sop']) ?></b></span><?php endif; ?>
                    <?php if ($est['profit_rate'] !== null): ?><span>Profit<?= h($pc($est['profit_rate'])) ?> <b><?= money($est['profit']) ?></b></span><?php endif; ?>
                    <?php if ($est['receipt_rate'] !== null): ?><span>Receipt deduction<?= h($pc($est['receipt_rate'])) ?> <b><?= money($est['receipt']) ?></b></span><?php endif; ?>
                    <span>Amount to release <b><?= money($est['release']) ?></b></span>
                  </div><div class="text-muted">Estimates only: no accounting entry. Amount to release = gross less the withholding tax, SOP, profit and receipt deduction.</div></dd>
                <?php endif; ?>
              </dl>
              <?php if ($isCur && $canEdit): ?><div class="d-flex flex-wrap gap-1 mb-2"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> Edit the details</a>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#mDetails"><i class="bi bi-question-circle"></i> Details not complete: request / prepare details</button></div><?php endif; ?>
              <?php break;
            case 'philgeps': ?>
              <?= $isCur && $canEdit ? $post('sop_save', '<div class="row g-2 align-items-end"><div class="col-sm-5"><label class="form-label">PhilGEPS reference no.</label><input class="form-control form-control-sm" name="philgeps_ref" maxlength="40" value="' . h((string)$p['philgeps_ref']) . '"></div>'
                  . '<div class="col-sm-4"><label class="form-label">Posted on</label><input class="form-control form-control-sm" type="date" name="posting_date" value="' . h((string)$p['posting_date']) . '"></div><div class="col-sm-3"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div></div>', 'mb-2', ['step' => $code])
                  : '<div class="small mb-2">PhilGEPS ' . h($p['philgeps_ref'] ?: 'not posted') . ($p['posting_date'] ? ', ' . h(fdate($p['posting_date'])) : '') . '</div>' ?>
              <?php break;
            case 'representative': ?>
              <?= $isCur && $canEdit ? $post('sop_save', '<div class="row g-2 align-items-end">' . brgy_user_select('submitter_id', 'Authorized representative', $p['submitter_id'], 'col-sm-8', 'Choose', 'submitter') . '<div class="col-sm-4"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div></div>', 'mb-2', ['step' => $code])
                  : '<div class="small mb-2">Representative: ' . h(Brgy::userName((int)$p['submitter_id']) ?: 'not named') . '</div>' ?>
              <?php break;
            case 'canvass':
              $cv = BrgySop::canvass($id);
              $best = BrgySop::lowest($cv); ?>
              <div class="table-responsive"><table class="table table-sm align-middle canvass-table mb-2">
                <thead><tr><th>Supplier</th><th class="num">Total quoted</th><th>Validity</th><th>Brand</th><th>Remarks</th><th>Signed form</th><th></th></tr></thead><tbody>
                <?php foreach ($cv as $c): ?>
                  <tr class="<?= $best && (int)$best['id'] === (int)$c['id'] ? 'is-best' : '' ?>">
                    <td data-label="Supplier"><b><?= h($c['supplier_name']) ?></b><?= (int)$c['is_company'] ? ' <span class="small text-primary">(the company)</span>' : '' ?><div class="small text-muted"><?= h(trim(($c['philgeps_no'] ? 'PhilGEPS ' . $c['philgeps_no'] : '') . ' ' . (string)$c['contact_no'])) ?></div></td>
                    <td class="num" data-label="Total"><?= money($c['total_amount']) ?><?= $best && (int)$best['id'] === (int)$c['id'] ? '<div class="small text-success">lowest that passed</div>' : '' ?></td>
                    <td data-label="Validity"><?= h((string)$c['validity']) ?></td><td data-label="Brand"><?= h((string)$c['brand']) ?></td>
                    <td data-label="Remarks"><?= $c['remarks'] ? Brgy::st($c['remarks'] === 'passed' ? 'approved' : 'returned', ucfirst($c['remarks'])) : '<span class="text-danger small">enter</span>' ?></td>
                    <td data-label="Signed form"><?= $c['document_id'] ? '<a href="' . h(url('brgy.vault', ['a' => 'file', 'id' => $c['document_id']])) . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> View</a>' : '<span class="text-danger small">not attached</span>' ?></td>
                    <td class="text-end text-nowrap"><?php if ($canEdit && $p['status'] === 'open'): ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mCanvass" data-canvass="<?= h(json_encode($c)) ?>"><i class="bi bi-pencil"></i></button>
                      <?= $post('canvass_delete', '<button class="btn btn-sm btn-outline-danger" data-confirm="Remove this quotation?"><i class="bi bi-trash"></i></button>', 'd-inline', ['canvass_id' => $c['id']]) ?>
                    <?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$cv): ?><tr><td colspan="7" class="text-muted small">No quotation yet. Record the three suppliers canvassed, the company's own quotation among them, each with its signed canvass form.</td></tr><?php endif; ?>
              </tbody></table></div>
              <?php if ($canEdit && $p['status'] === 'open'): ?><div class="mb-2 d-flex flex-wrap gap-1"><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mCanvass" data-canvass="{}"><i class="bi bi-plus-lg"></i> Add a quotation</button>
                <?php if (!array_filter($cv, fn($c) => (int)$c['is_company'] === 1)): ?><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mCanvass" data-canvass='<?= h(json_encode(['is_company' => 1, 'supplier_name' => Brgy::setting('brgy_tradename') ?: Settings::get('company_name'), 'total_amount' => $lt['lines'] ? $lt['bid'] : '', 'remarks' => 'passed'])) ?>'><i class="bi bi-building"></i> Add the company's quotation</button><?php endif; ?></div>
                <div class="small text-muted mb-2"><?= $pricingStep ? 'When the step is done and the company\'s quotation is the lowest that passed, the price schedule is next (step 4), where the purchase order is recorded as the award; otherwise the transaction is recorded as lost.'
                    : 'When the step is done, the company\'s quotation being the lowest that passed, the purchase order is recorded as the award (' . money($lt['bid']) . '); otherwise the transaction is recorded as lost.' ?></div><?php endif; ?>
              <?php break;
            case 'price_schedule':
              // v39: step 4 of the small value procurement: the price schedule, agreeing with the company's quotation; done, the award
              $coQ = current(array_filter(BrgySop::canvass($id), fn($c) => (int)$c['is_company'] === 1)) ?: null;
              $agree = $coQ && $lt['lines'] && abs((float)$coQ['total_amount'] - $lt['bid']) <= 0.004;
              $schedOpen = $p['status'] === 'open' && in_array($p['bid_status'], ['draft', 'returned'], true);
              $prIt = $schedOpen && !$lt['lines'] ? BrgyDocs::prItems($id) : []; ?>
              <dl class="dl-grid small mb-2">
                <dt>Items</dt><dd><?= $lt['lines'] ? $lt['lines'] . ' line' . ($lt['lines'] === 1 ? '' : 's') . ', ' . money($lt['bid']) : '<span class="text-danger">none yet</span>' ?></dd>
                <?php if ($coQ): ?><dt>Company's quotation</dt><dd><?= money($coQ['total_amount']) ?> in the canvass<?= $lt['lines'] ? ($agree ? ' <span class="text-success"><i class="bi bi-check-lg"></i> agrees with the price schedule</span>' : ' <span class="text-danger">does not agree with the price schedule</span>') : '' ?></dd><?php endif; ?>
                <?php if ($p['status'] === 'awarded' || $p['status'] === 'closed'): ?><dt>Award</dt><dd>Purchase order <?= h((string)($p['po_no'] ?: $p['noa_ref'])) ?>, <?= money(Brgy::contractTotal($p)) ?></dd>
                <?php elseif ($p['status'] === 'open'): ?><dt>Award</dt><dd class="text-muted">Recorded when this step is done: the purchase order at the price schedule's total.</dd><?php endif; ?>
              </dl>
              <div class="d-flex flex-wrap gap-1 mb-2">
                <a class="btn btn-sm btn-outline-primary" href="<?= h($self(['tab' => 'lines'])) ?>"><i class="bi bi-table"></i> <?= $lt['lines'] ? 'Open the price schedule' : 'Prepare the price schedule' ?></a>
                <?php if ($prIt && $canEdit && $live): ?><?= $post('lines_from_pr', '<input type="hidden" name="lock_version" value="' . (int)$p['lock_version'] . '"><button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-down"></i> Take the ' . count($prIt['items']) . ' item' . (count($prIt['items']) === 1 ? '' : 's') . ' of the purchase request ' . h($prIt['form_no']) . '</button>', 'd-inline') ?><?php endif; ?>
                <?php if ($coQ && $lt['lines'] && !$agree && $p['status'] === 'open' && $canEdit && $live): ?><?= $post('quote_from_schedule', '<button class="btn btn-sm btn-outline-secondary" data-confirm="Make the company\'s quotation in the canvass ' . h(money($lt['bid'])) . ', the total of the price schedule?"><i class="bi bi-arrow-left-right"></i> Make the company\'s quotation ' . money($lt['bid']) . '</button>', 'd-inline') ?><?php endif; ?>
              </div>
              <?php break;
            case 'payment':
            case 'billing_docs':
              echo $payBox($s['action']);
              break;
            case 'forward': ?>
              <?= $isCur && $canEdit ? $post('sop_save', '<div class="row g-2 align-items-end"><div class="col-sm-5"><label class="form-label">Forwarded to</label><input class="form-control form-control-sm" name="forwarded_to" maxlength="120" value="' . h($p['forwarded_to'] ?: Brgy::setting('brgy_accounting_dept')) . '"></div>'
                  . '<div class="col-sm-4"><label class="form-label">On</label><input class="form-control form-control-sm" type="date" name="forwarded_date" value="' . h($p['forwarded_at'] ? substr((string)$p['forwarded_at'], 0, 10) : today()) . '"></div><div class="col-sm-3"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div></div>', 'mb-2', ['step' => $code])
                  : '<div class="small mb-2">' . ($p['forwarded_at'] ? 'Forwarded to ' . h((string)$p['forwarded_to']) . ' on ' . h(fdate(substr((string)$p['forwarded_at'], 0, 10))) : 'Not forwarded yet') . '</div>' ?>
              <?php break;
            case 'transmittal':
              $trForm = current(array_filter($s['papers_list'], fn($x) => $x['paper_code'] === 'transmittal')); ?>
              <?= $isCur && $canEdit ? $post('sop_save', '<div class="row g-2 align-items-end"><div class="col-sm-6"><label class="form-label">Transmittal no.</label><input class="form-control form-control-sm" name="transmittal_no" maxlength="40" value="' . h($p['transmittal_no'] ?: ($trForm['form_no'] ?? '')) . '"></div>'
                  . '<div class="col-sm-3"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div></div>', 'mb-2', ['step' => $code])
                  : '<div class="small mb-2">Transmittal ' . h($p['transmittal_no'] ?: 'not prepared') . '</div>' ?>
              <?php break;
            case 'release': ?>
              <?php if ($isCur && $canEdit): ?>
                <?= $post('sop_save', '<div class="row g-2 align-items-end"><div class="col-12"><div class="btn-group btn-group-sm flex-wrap" role="group">'
                  . '<input type="radio" class="btn-check" name="release_method" id="rmRep" value="sales_rep"' . ($p['release_method'] === 'sales_rep' ? ' checked' : '') . '><label class="btn btn-outline-primary" for="rmRep"><i class="bi bi-person-walking"></i> Delivered by the sales representative</label>'
                  . '<input type="radio" class="btn-check" name="release_method" id="rmPick" value="pickup"' . ($p['release_method'] === 'pickup' ? ' checked' : '') . '><label class="btn btn-outline-primary" for="rmPick"><i class="bi bi-building"></i> Picked up at the office</label></div></div>'
                  . '<div class="col-sm-5"><label class="form-label">Released to</label><input class="form-control form-control-sm" name="released_to" maxlength="120" value="' . h((string)$p['released_to']) . '"></div>'
                  . '<div class="col-sm-4"><label class="form-label">On</label><input class="form-control form-control-sm" type="date" name="released_date" value="' . h($p['released_at'] ? substr((string)$p['released_at'], 0, 10) : today()) . '"></div><div class="col-sm-3"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div></div>', 'mb-2', ['step' => $code]) ?>
              <?php else: ?><div class="small mb-2"><?= $p['released_at'] ? h(['sales_rep' => 'Delivered by the sales representative', 'pickup' => 'Picked up at the office'][$p['release_method']] ?? '') . ' to ' . h((string)$p['released_to']) . ', ' . h(fdate(substr((string)$p['released_at'], 0, 10))) : 'Not released yet' ?></div><?php endif; ?>
              <?php $tr = DB::row("SELECT pp.* FROM brgy_project_papers pp WHERE pp.project_id = :p AND pp.paper_code = 'transmittal' ORDER BY id LIMIT 1", ['p' => $id]);
              if ($tr): ?><div class="mb-2"><?= $paperRow($tr + BrgySop::paperState($tr, $p) + ['form_no' => DB::value('SELECT form_no FROM brgy_forms WHERE id = :f', ['f' => (int)$tr['form_id']])], $s) ?></div><?php endif; ?>
              <?php break;
            case 'signatures':
              $toSign = DB::all("SELECT pp.*, f.form_no FROM brgy_project_papers pp JOIN brgy_sop_steps x ON x.code = pp.step_code LEFT JOIN brgy_forms f ON f.id = pp.form_id
                                  WHERE pp.project_id = :p AND pp.needs_signature = 1 AND pp.source = 'prepare' AND x.sort_order < :o ORDER BY x.sort_order, pp.sort_order", ['p' => $id, 'o' => $s['sort_order']]); ?>
              <div class="paper-list mb-2"><?php foreach ($toSign as $pp): echo $paperRow($pp + BrgySop::paperState($pp, $p), $s); endforeach; ?></div>
              <?php break;
            case 'verify': ?>
              <div class="mb-2"><div class="small mb-1">Bid <?= h(money($p['bid_amount'])) ?>, <?= h(Brgy::WF[$p['bid_status']] ?? $p['bid_status']) ?>.</div>
                <?php if ($live && $p['status'] === 'open'): $bg = Brgy::bidGate($p); if ($bg && in_array($p['bid_status'], ['draft', 'returned'], true)): ?><ul class="small text-danger mb-1"><?php foreach ($bg as $g): ?><li><?= h($g) ?></li><?php endforeach; ?></ul><?php endif;
                  echo brgy_step_buttons('bid', $p, $p, $self(), 'bid', ['tab' => 'process']); endif; ?></div>
              <?php break;
            case 'submit': ?>
              <div class="mb-2 small"><?= $p['bid_endorsed_at'] ? 'Endorsed to ' . h(Brgy::userName((int)$p['submitter_id'])) . ' on ' . h(fdate(substr((string)$p['bid_endorsed_at'], 0, 10))) . '. ' : '' ?><?= $p['bid_status'] === 'released' ? 'Submitted ' . h(fdatetime($p['bid_submitted_at'])) . '.' : 'Not submitted yet.' ?></div>
              <?php if ($isCur && $canEdit): ?><div class="d-flex flex-wrap gap-1 mb-2"><?php if (!$p['bid_endorsed_at']): ?><?= $post('sop_save', '<input type="hidden" name="endorsed_date" value="' . today() . '"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-right"></i> Endorsed to ' . h(Brgy::userName((int)$p['submitter_id']) ?: 'the representative') . ' today</button>', 'd-inline', ['step' => $code]) ?><?php endif; ?>
                <?php if ($p['bid_status'] === 'approved'): ?><a class="btn btn-sm btn-primary" href="<?= h($self(['tab' => 'submissions', 'sub' => 'bid'])) ?>#subForm"><i class="bi bi-send"></i> Record the submission</a><?php endif; ?></div><?php endif; ?>
              <?php break;
            case 'post_bid': ?>
              <div class="mb-2 small"><?= $p['status'] === 'awarded' || $p['status'] === 'closed' ? 'Awarded: ' . h((string)$p['noa_ref']) . ', ' . money(Brgy::contractTotal($p)) . '.' : 'The award is not recorded yet.' ?>
                <?php if ($p['status'] === 'open' && $canEdit): ?> <a href="<?= h($self(['tab' => 'award'])) ?>">Record the award</a> (attach the signed notice of award below first, then use it as the evidence) or record the result on the Overview tab.<?php endif; ?></div>
              <?php break;
            case 'completion':
              Brgy::refreshLines($id);
              $m2 = Brgy::money(Brgy::mustProject($id)); ?>
              <div class="mb-2 small"><?= money($m2['accepted']) ?> of <?= money($m2['contract']) ?> accepted. <?= $p['status'] === 'awarded' && $m2['accepted'] < $m2['contract'] - 0.004 ? page_link('brgy.deliveries', ['a' => 'new', 'project_id' => $id], 'New delivery', 'btn-outline-primary', 'truck', 'create') : '' ?></div>
              <?php break;
        endswitch; ?>

        <?php if ($s['papers_list']): ?>
          <div class="paper-list mb-2"><?php foreach ($s['papers_list'] as $pp): echo $paperRow($pp, $s); endforeach; ?></div>
          <?php if ($isCur && $canForms && count(array_filter($s['papers_list'], fn($x) => $x['state'] === 'todo' && $x['template_code'])) > 1): ?>
            <?= $post('paper_prepare_all', '<button class="btn btn-sm btn-outline-primary mb-2"><i class="bi bi-files"></i> Prepare every paper of this step</button>', '', ['step' => $code]) ?>
          <?php endif; ?>
          <?php if ($isCur && $canEdit && $s['action'] === 'prepare'): ?><button type="button" class="btn btn-sm btn-link px-0 mb-2" data-bs-toggle="modal" data-bs-target="#mPaperAdd" data-step="<?= h($code) ?>">Add another paper to this step</button><?php endif; ?>
        <?php endif; ?>

        <?php if ($st === 'pending' && $isCur && $live): ?>
          <form method="post" action="<?= h($self()) ?>" class="step-done"><?= csrf_field() ?><input type="hidden" name="do" value="sop_done"><input type="hidden" name="step" value="<?= h($code) ?>"><?= $hiddenTab ?>
            <?php if ($checks): ?><div class="step-checks"><?php foreach ($checks as $i => $ck): ?>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="checks[]" value="<?= h($ck) ?>"> <span class="form-check-label"><?= h($ck) ?></span></label>
            <?php endforeach; ?></div><?php endif; ?>
            <?php if ($gate): ?><div class="step-gate"><b>Still to do</b><ul><?php foreach ($gate as $g): ?><li><?= h($g) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($canEdit): ?><div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <input class="form-control form-control-sm step-remarks" name="remarks" maxlength="255" placeholder="Remarks (optional)">
              <button class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> <?= $s['action'] === 'details' ? 'Yes, the details are complete' : 'Step ' . (int)$s['step_no'] . ' done' ?></button>
              <?php if ($isApprover): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mSkip" data-step="<?= h($code) ?>">Skip</button><?php endif; ?>
            </div><?php endif; ?>
          </form>
        <?php elseif ($st !== 'pending'): ?>
          <?php if ($ticked): ?><div class="small text-muted">Checked: <?= h(implode(', ', $ticked)) ?></div><?php endif; ?>
          <?php if ($isApprover && $live): ?><button type="button" class="btn btn-sm btn-link px-0" data-bs-toggle="modal" data-bs-target="#mReopenStep" data-step="<?= h($code) ?>">Open this step again</button><?php endif; ?>
        <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
</div>

<?php if (!$payStep && $p['process'] !== 'general'): ?>
<div class="card mt-3" id="paymentBox"><div class="card-header"><i class="bi bi-cash-coin"></i> Payment</div><div class="card-body"><?= $payBox('standalone') ?></div></div>
<?php endif; ?>
<div class="card mt-3" id="allPapers"><div class="card-header d-flex align-items-center"><span><i class="bi bi-folder2-open"></i> All papers of this transaction</span>
  <?php if ($signedCount): ?><a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= h(url($pageKey, ['a' => 'papers_zip', 'id' => $id])) ?>"><i class="bi bi-file-zip"></i> ZIP of the signed copies</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle papers-table">
    <thead><tr><th>Step</th><th>Paper</th><th>State</th><th>Form</th><th>Signed copy</th></tr></thead><tbody>
    <?php foreach ($allPapers as $pp): [$icon, $cls] = $stateText[$pp['state']]; ?>
      <tr><td data-label="Step"><?= (int)$pp['step_no'] ?></td><td data-label="Paper"><?= h($pp['name']) ?></td>
        <td data-label="State" class="<?= $cls ?>"><i class="bi bi-<?= $icon ?>"></i> <?= h(BrgySop::PAPER_STATES[$pp['state']]) ?></td>
        <td data-label="Form"><?= $pp['form_id'] ? '<a href="' . h(url('brgy.forms', ['a' => 'view', 'id' => $pp['form_id']])) . '">' . h((string)$pp['form_no']) . '</a>' : '' ?></td>
        <td data-label="Signed copy"><?= $pp['document_id'] ? '<a href="' . h(url('brgy.vault', ['a' => 'file', 'id' => $pp['document_id']])) . '" target="_blank" rel="noopener"><i class="bi bi-download"></i> ' . h((string)($pp['original_name'] ?: 'Open')) . '</a>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php foreach (BrgySop::canvass($id) as $c): if ($c['document_id']): ?>
      <tr><td data-label="Step"><?= (int)(current(array_filter($S['steps'], fn($x) => $x['action'] === 'canvass'))['step_no'] ?? 0) ?></td><td data-label="Paper">Canvass form: <?= h($c['supplier_name']) ?></td><td data-label="State" class="is-done"><i class="bi bi-check-circle-fill"></i> Signed copy attached</td><td></td>
        <td data-label="Signed copy"><a href="<?= h(url('brgy.vault', ['a' => 'file', 'id' => $c['document_id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-download"></i> Open</a></td></tr>
    <?php endif; endforeach; ?>
    </tbody></table></div></div>

<?php // ------------------------------------------------------------------ the dialogs of the tab
$hidden = '<input type="hidden" name="tab" value="process">';
$modal = fn(string $mid, string $title, string $do, string $body, string $btn, string $btnCls = 'btn-primary') => '<div class="modal fade" id="' . $mid . '" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="' . h($self()) . '" enctype="multipart/form-data">'
    . csrf_field() . '<input type="hidden" name="do" value="' . $do . '">' . $hidden . '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
    . '<div class="modal-body">' . $body . '</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn ' . $btnCls . '">' . h($btn) . '</button></div></form></div></div>';
echo $modal('mPaperAttach', 'Attach the signed copy', 'paper_attach', '<input type="hidden" name="paper_id" value=""><p class="small mb-2" data-paper-label></p>'
    . brgy_file_field('file', 'Signed copy (PDF, JPG or JPEG)', 'A scan or a clear photo of the signed paper. It is filed in the Document Vault under this project.', 'col-12', true, '.pdf,.jpg,.jpeg,.png')
    . '<label class="form-label mt-2">Note</label><input class="form-control form-control-sm" name="note" maxlength="255" placeholder="e.g. signed by the Punong Barangay on 5 Nov">', 'Attach');
echo $modal('mPaperWaive', 'Not needed for this transaction', 'paper_waive', '<input type="hidden" name="paper_id" value=""><p class="small mb-2" data-paper-label></p><label class="form-label">Why</label><textarea class="form-control" name="remarks" rows="2" required></textarea>', 'Mark not needed', 'btn-warning');
echo $modal('mPaperAdd', 'Add a paper', 'paper_add', '<input type="hidden" name="step" value=""><div class="row g-2"><div class="col-12"><label class="form-label">Name of the paper</label><input class="form-control" name="name" maxlength="160" required></div>'
    . select_field('doc_type', 'Kind of document', Brgy::docTypes(), 'other', ['class' => 'col-sm-8']) . '<div class="col-sm-4"><label class="form-label">Copies</label><input class="form-control" type="number" min="1" max="10" name="copies" value="1"></div>'
    . check_field('needs_signature', 'Needs signatures', 1, 'col-12') . '</div>', 'Add');
echo $modal('mSkip', 'Skip this step', 'sop_skip', '<input type="hidden" name="step" value=""><p class="small text-muted">Only when the step does not apply to this transaction. The reason is kept on the timeline and in the audit trail.</p><label class="form-label">Why</label><textarea class="form-control" name="remarks" rows="2" required></textarea>', 'Skip', 'btn-warning');
echo $modal('mReopenStep', 'Open the step again', 'sop_reopen', '<input type="hidden" name="step" value=""><label class="form-label">Why</label><textarea class="form-control" name="remarks" rows="2" required></textarea>', 'Open again');
echo $modal('mDetails', 'Request / prepare details', 'sop_details_request', '<p class="small text-muted">The details are not complete. Additional research and price validation are done before processing; a task goes to the sales representative.</p><label class="form-label">What is missing or has to be validated</label><textarea class="form-control" name="remarks" rows="3" required></textarea>', 'Request the details', 'btn-warning');
// v34: the tradename the cheque is payable to, from every active tradename (the transaction's own first chosen)
$chequeTn = '';
if (Brgy::v34Ready()) {
    $tnAll = Brgy::tradenames();
    $tnNow = (string)($p['cheque_tradename'] ?: ($p['tradename'] ?: Brgy::defaultTradename()));
    if ($tnNow !== '' && !isset($tnAll[$tnNow])) {
        $tnAll = [$tnNow => $tnNow] + $tnAll;
    }
    $chequeTn = '<div class="col-sm-6"><label class="form-label" for="f_cheque_tradename">Tradename (payee of the cheque)</label><select class="form-select" id="f_cheque_tradename" name="cheque_tradename" data-no-combo>'
        . ($tnAll ? '' : '<option value="">' . h((string)Settings::get('company_name')) . '</option>');
    foreach ($tnAll as $tn) {
        $chequeTn .= '<option value="' . h($tn) . '"' . ($tn === $tnNow ? ' selected' : '') . '>' . h($tn) . '</option>';
    }
    $chequeTn .= '</select></div>';
}
echo $modal('mPayRelease', 'Cheque ready: payment released', 'pay_release', '<div class="row g-2"><div class="col-sm-6"><label class="form-label">Cheque no.</label><input class="form-control" name="cheque_no" maxlength="40" required></div>'
    . '<div class="col-sm-6"><label class="form-label">Cheque date</label><input class="form-control" type="date" name="cheque_date" value="' . today() . '" required></div>'
    . '<div class="col-sm-6"><label class="form-label">Bank</label><input class="form-control" name="cheque_bank" maxlength="80" value="Landbank"></div>'
    . '<div class="col-sm-6"><label class="form-label">Amount of the cheque</label><input class="form-control" name="cheque_amount" inputmode="decimal" value="' . h(number_format($w['net'], 2, '.', '')) . '" required></div>'
    . '<div class="col-sm-6"><label class="form-label">DV no. (if known)</label><input class="form-control" name="dv_no" maxlength="40" value="' . h((string)$p['dv_no']) . '"></div>'
    . $chequeTn
    . '<div class="col-12 form-text">Expected: ' . money($w['gross']) . ' less ' . money($w['vat']) . ' VAT and ' . money($w['ewt']) . ' income tax withheld = ' . money($w['net']) . '.' . (BrgySop::hasAction((string)$p['process'], 'billing_docs') ? ' The SI, OR and 2307 can now be prepared.' : '') . '</div></div>', 'Record the cheque', 'btn-success');
echo $modal('mFollow', 'Payment follow-up', 'pay_followup', '<label class="form-label">What the barangay said</label><textarea class="form-control" name="remarks" rows="2" required></textarea><label class="form-label mt-2">Follow up again on</label><input class="form-control" type="date" name="next_date" value="' . date('Y-m-d', strtotime('+3 days')) . '">', 'Log it');
echo $modal('mDeliverAll', 'Delivered and accepted', 'deliver_all', '<p class="small text-muted">Everything still to deliver on the contract is recorded as one delivery, accepted in full from the signed inspection and acceptance report (IAR ' . h($p['iar_no'] ?: 'no.') . '). For partial deliveries or rejections use Deliveries & Acceptance instead.</p>'
    . '<div class="row g-2"><div class="col-sm-6"><label class="form-label">Delivered on</label><input class="form-control" type="date" name="delivery_date" value="' . h($p['delivery_date'] ?: today()) . '"></div>'
    . '<div class="col-sm-6"><label class="form-label">Accepted on (IAR date)</label><input class="form-control" type="date" name="acceptance_date" value="' . h($p['iar_date'] ?: today()) . '"></div>'
    . select_field('from_site_id', 'Goods leave from (site)', Lookup::sites(), $p['site_id'], ['blank' => '', 'class' => 'col-12', 'extra' => 'data-no-combo']) . '</div>', 'Record');
$ccBody = '<input type="hidden" name="canvass_id" value=""><input type="hidden" name="is_company" value="0"><div class="row g-2">'
    . '<div class="col-12"><label class="form-label">Supplier</label><input class="form-control" name="supplier_name" maxlength="160" required></div>'
    . '<div class="col-sm-6"><label class="form-label">Total amount quoted</label><input class="form-control" name="total_amount" inputmode="decimal"></div>'
    . '<div class="col-sm-6"><label class="form-label">Remarks</label><select class="form-select" name="remarks"><option value="passed">Passed</option><option value="failed">Failed</option></select></div>'
    . '<div class="col-sm-6"><label class="form-label">Validity period</label><input class="form-control" name="validity" maxlength="60"></div>'
    . '<div class="col-sm-6"><label class="form-label">Brand (if applicable)</label><input class="form-control" name="brand" maxlength="120"></div>'
    . '<div class="col-sm-6"><label class="form-label">PhilGEPS no.</label><input class="form-control" name="philgeps_no" maxlength="40"></div>'
    . '<div class="col-sm-6"><label class="form-label">Contact no.</label><input class="form-control" name="contact_no" maxlength="60"></div>'
    . '<div class="col-12"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255"></div>'
    . '<div class="col-sm-6"><label class="form-label">Received on</label><input class="form-control" type="date" name="received_date" value="' . today() . '"></div>'
    . brgy_file_field('file', 'Canvass form signed by the supplier (PDF, JPG or JPEG)', 'Leave empty to keep the one attached.', 'col-12', false, '.pdf,.jpg,.jpeg,.png')
    . '<div class="col-12 form-text" data-company-note data-lines="' . (int)$lt['lines'] . '" hidden>' . ($lt['lines'] ? 'The company\'s quotation is the total of the price schedule (' . money($lt['bid']) . ').'
        : 'Enter the company\'s quoted total. The price schedule is prepared ' . ($pricingStep ? 'at step 4' : 'on its tab') . ' and has to agree with it.') . '</div></div>';
echo $modal('mCanvass', 'Quotation of a supplier', 'canvass_save', $ccBody, 'Save');
