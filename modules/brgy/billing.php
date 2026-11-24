<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Billing Packs
 * A billing is made for what the entity accepted, checked and approved by two other people, then invoiced once (the
 * sales invoice is posted to the Customer:Job of the contract), completed with its papers, submitted and acknowledged.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.billing';
$a = g('a', 'list');
$id = gint('id');

$loadBilling = function (int $id): array {
    $b = DB::row('SELECT b.*, p.project_no, p.title AS project_title FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.id = :id', ['id' => $id]);
    if (!$b) {
        flash('warning', 'Billing not found.');
        redirect(url('brgy.billing'));
    }
    return $b;
};

// ------------------------------------------------------------------ actions on a billing
if ($a === 'view' && $id && is_post()) {
    $do = (string)p('do');
    $r = trim((string)p('remarks'));
    brgy_action(function () use ($do, $id, $r) {
        switch ($do) {
            case 'bl_submit':
            case 'bl_review':
            case 'bl_approve':
            case 'bl_return':
            case 'bl_reopen':
                BrgyOps::billingStep($id, substr($do, 3), $r);
                return ['bl_submit' => 'Sent for review.', 'bl_review' => 'Marked reviewed.', 'bl_approve' => 'Approved: the invoice can now be issued.', 'bl_return' => 'Returned to the preparer.', 'bl_reopen' => 'Reopened as a draft.'][$do];
            case 'invoice':
                $tid = BrgyOps::invoiceBilling($id);
                return 'Invoice ' . Txn::load($tid)['txn_no'] . ' posted to the Customer:Job of the contract (accounts receivable and sales). Complete the pack and submit it.';
            case 'pack_upload':
                Access::require('brgy.vault', 'create');
                $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $id]);
                $type = (string)p('doc_type');
                $wide = in_array($type, ['po', 'contract', 'noa'], true);
                BrgyDocs::store(brgy_file('file') ?? ['error' => UPLOAD_ERR_NO_FILE], ['scope' => 'project', 'project_id' => $b['project_id'], 'doc_type' => $type ?: 'other',
                    'title' => trim((string)p('title')) ?: Brgy::docTypeName($type) . ' (' . $b['billing_no'] . ')', 'related_type' => $wide ? null : 'billing', 'related_id' => $wide ? null : $id]);
                return 'Filed in the pack.';
            case 'submit':
                BrgyOps::submitBilling($id, $_POST, brgy_file('proof'));
                return 'The pack is recorded as submitted to the entity.';
            case 'ack':
                BrgyOps::acknowledgeBilling($id, $_POST, brgy_file('proof'));
                return 'Acknowledgment recorded.';
            case 'void':
                BrgyOps::voidBilling($id, $r);
                return 'Billing voided; its invoice was voided in the books.';
            case 'form':
                $b = DB::row('SELECT project_id FROM brgy_billings WHERE id = :id', ['id' => $id]);
                $fid = BrgyOps::createForm((int)pint('template_id'), (int)$b['project_id'], ['billing', $id]);
                redirect(url('brgy.forms', ['a' => 'edit', 'id' => $fid]));
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['a' => 'view', 'id' => $id]));
}

// ------------------------------------------------------------------ new / edit
if (in_array($a, ['new', 'edit'], true)) {
    $b = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $b = $loadBilling((int)$id);
        if (!in_array($b['status'], ['draft', 'returned'], true)) {
            flash('warning', 'Only a draft or returned billing can be changed.');
            redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $projectId = $b ? (int)$b['project_id'] : (gint('project_id') ?: (int)p('project_id'));
    handle_post(function () use ($b, $pageKey) {
        $newId = BrgyOps::saveBilling($_POST + ['lines' => (array)($_POST['lines'] ?? [])], $b ? (int)$b['id'] : null, p('lock_version'));
        flash('success', $b ? 'Billing saved.' : 'Billing pack prepared as a draft. Check it, then send it for review.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $newId]));
    });
    $projects = DB::pairs("SELECT p.id, CONCAT(p.project_no, ' - ', LEFT(p.title, 70), ' (', e.official_name, ')') FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id WHERE p.status = 'awarded' ORDER BY p.project_no DESC");
    $p = $projectId ? Brgy::project($projectId) : null;
    layout_start($b ? 'Edit ' . $b['billing_no'] : 'New billing pack', brgy_layout(['actions' => btn_link($b ? url($pageKey, ['a' => 'view', 'id' => $b['id']]) : url($pageKey), 'Cancel')]));
    if (!$p): ?>
      <form method="get" class="card card-body" style="max-width:720px"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="new">
        <?= select_field('project_id', 'Contract to bill', $projects, null, ['blank' => '', 'class' => 'col-12', 'required' => true, 'extra' => 'data-combo']) ?>
        <div class="mt-2"><button class="btn btn-primary btn-sm">Continue</button></div></form>
    <?php layout_end(); return; endif;
    Brgy::refreshLines((int)$p['id']);
    $mine = $b ? DB::pairs('SELECT project_line_id, qty FROM brgy_billing_lines WHERE billing_id = :b', ['b' => $b['id']]) : [];
    $lines = Brgy::lines((int)$p['id']);
    $m = Brgy::money($p);
    $vatIncl = Brgy::on('brgy_prices_vat_inclusive');
    // v36: what each line has left to bill, and the other packs not invoiced yet that hold the rest
    $availAll = 0.0;
    foreach ($lines as $k => $l) {
        $own = (float)($mine[$l['id']] ?? 0);
        $lines[$k]['_own'] = $own;
        $lines[$k]['_others'] = (float)$l['qty_billed'] - $own;
        $lines[$k]['_avail'] = max(0, round((float)$l['qty_accepted'] - $lines[$k]['_others'], 4));
        $availAll += $lines[$k]['_avail'];
    }
    $pending = Brgy::pendingBillings((int)$p['id'], $b ? (int)$b['id'] : null);
    $canBill = $availAll > 0;
    if ($pending): ?>
      <div class="alert alert-warning mb-3"><div class="d-flex gap-2"><i class="bi bi-exclamation-circle"></i><div>
        <b><?= count($pending) === 1 ? 'Billing pack ' . h($pending[0]['billing_no']) . ' is not invoiced yet' : count($pending) . ' billing packs are not invoiced yet' ?></b>
        <div class="small">The quantities in <?= count($pending) === 1 ? 'it' : 'them' ?> show under "In other packs" and are not billed twice. Open the pack, take it through review and approval, then issue its invoice. To change what it bills, edit it while it is a draft or returned, or void it.</div>
        <ul class="small mb-0 mt-1 ps-3"><?php foreach ($pending as $x): ?><li><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $x['id']])) ?>"><b><?= h($x['billing_no']) ?></b></a>, <?= h(Brgy::WF[$x['status']] ?? $x['status']) ?>, <?= money($x['gross_amount']) ?>: <?= h(Brgy::billingNext($x['status'])) ?></li><?php endforeach; ?></ul>
      </div></div></div>
    <?php elseif (!$canBill): ?>
      <div class="alert alert-info mb-3 small"><i class="bi bi-info-circle"></i> Nothing accepted is left to bill on <?= h($p['project_no']) ?>. A quantity can be billed once its delivery is recorded and the entity accepts it (Deliveries &amp; Acceptance).</div>
    <?php endif; ?>
    <form method="post" class="brgy-form" data-no-class-check><?= csrf_field() ?><input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>"><?= $b ? Brgy::versionField($b['lock_version']) : '' ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-receipt"></i> <?= h($p['project_no']) ?> <?= h(mb_strimwidth($p['title'], 0, 80, '...')) ?></div><div class="card-body row g-2">
      <div class="col-12 small text-muted"><?= h($p['entity_name']) ?>. Contract <?= money($m['contract']) ?>, invoiced so far <?= money($m['billed']) ?><?= $m['billing_draft'] > 0 ? ', in billing packs not invoiced yet ' . money($m['billing_draft']) : '' ?>, accepted not billed <?= money($m['unbilled_accepted']) ?>.
        Retention <?= pct($p['retention_pct']) ?><?= (float)$p['ld_rate_pct'] > 0 ? ', liquidated damages ' . h(rtrim(rtrim(number_format((float)$p['ld_rate_pct'], 4), '0'), '.')) . '% a day' : '' ?>.</div>
      <?= input_field('billing_date', 'Billing date', fv('billing_date', $b, today()), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
      <?= select_field('billing_type', 'Kind', Brgy::BILLING_TYPES, fv('billing_type', $b, $p['category'] === 'works' ? 'progress' : 'partial'), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= input_field('period_from', 'Period from', fv('period_from', $b), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('period_to', 'Period to', fv('period_to', $b), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('si_no', 'Sales invoice no. (BIR-registered booklet / system)', fv('si_no', $b), ['class' => 'col-md-4', 'extra' => 'maxlength="40"']) ?>
      <?= input_field('ld_expected', 'Liquidated damages expected (blank: worked out)', fv('ld_expected', $b) !== '' && (float)fv('ld_expected', $b) == 0.0 && !is_post() ? '' : fv('ld_expected', $b), ['class' => 'col-md-4', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('notes', 'Notes', fv('notes', $b), ['class' => 'col-md-4']) ?>
    </div></div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-list-check"></i> Accepted quantities to bill</span>
      <?= $canBill ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-fill-all="#billLines"><i class="bi bi-arrow-down-square"></i> Everything accepted</button>' : '' ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0" id="billLines" data-sum="#billTotal">
        <thead><tr><th>Line</th><th class="num">Contract</th><th class="num">Accepted</th><th class="num">In other packs</th><th class="num">Available</th><th class="num">Unit price</th><th class="num" style="width:120px">Bill now</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $own = $l['_own'];
            $others = $l['_others'];
            $avail = $l['_avail'];
            $val = is_post() ? (string)($_POST['lines'][$l['id']] ?? '') : ($own ? rtrim(rtrim(number_format($own, 4, '.', ''), '0'), '.') : ''); ?>
          <tr class="<?= $avail <= 0 ? 'text-muted' : '' ?>"><td class="wrap-link small"><?= (int)$l['line_no'] ?>. <?= h($l['description']) ?> <span class="text-muted"><?= h($l['unit']) ?></span></td>
            <td class="num"><?= qty($l['qty']) ?></td><td class="num"><?= qty($l['qty_accepted']) ?></td><td class="num"><?= qty($others) ?></td><td class="num"><?= qty($avail) ?></td><td class="num"><?= money($l['unit_price']) ?></td>
            <td><input class="form-control form-control-sm text-end" name="lines[<?= (int)$l['id'] ?>]" value="<?= h($val) ?>" inputmode="decimal" data-max="<?= h((string)$avail) ?>" data-price="<?= h((string)(float)$l['unit_price']) ?>" <?= $avail <= 0 ? 'disabled' : '' ?>></td>
            <td class="num" data-amount>0.00</td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="7" class="text-end">Total billed (<?= $vatIncl ? 'VAT inclusive' : 'before VAT' ?>)</td><td class="num fw-bold" id="billTotal">0.00</td></tr></tfoot>
      </table></div>
      <div class="card-body py-2 small text-muted">Only what the entity accepted can be billed, and the billings together never exceed the contract with its applied change orders. The expected taxes withheld, retention and liquidated damages are worked out when the billing is saved.</div></div>
    <div class="sticky-actions"><?= $canBill ? save_buttons($b ? 'Save billing' : 'Prepare billing', $b ? url($pageKey, ['a' => 'view', 'id' => $b['id']]) : url($pageKey), false)
        : '<div class="d-flex gap-1 flex-wrap align-items-center">' . (count($pending) === 1 ? btn_link(url($pageKey, ['a' => 'view', 'id' => $pending[0]['id']]), 'Open ' . $pending[0]['billing_no'], 'btn-primary', 'receipt') . ' ' : '')
          . btn_link($b ? url($pageKey, ['a' => 'view', 'id' => $b['id']]) : url($pageKey), 'Cancel') . '</div>' ?></div>
    </form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ view
if ($a === 'view' && $id) {
    $b = $loadBilling($id);
    $p = Brgy::mustProject((int)$b['project_id']);
    $bl = DB::all('SELECT bl.*, pl.line_no FROM brgy_billing_lines bl JOIN brgy_project_lines pl ON pl.id = bl.project_line_id WHERE bl.billing_id = :b ORDER BY pl.line_no', ['b' => $id]);
    $inv = $b['invoice_txn_id'] ? Txn::load((int)$b['invoice_txn_id']) : null;
    $open = $inv && !(int)$inv['is_voided'] ? Balances::open($inv) : null;
    $pack = $b['status'] !== 'void' ? BrgyOps::packStatus($b) : [];
    $cols = DB::all('SELECT * FROM brgy_collections WHERE billing_id = :b ORDER BY collection_date, id', ['b' => $id]);
    $canEdit = Access::can($pageKey, 'edit');
    $actions = (in_array($b['status'], ['draft', 'returned'], true) && $canEdit ? btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
        . (in_array($b['status'], ['invoiced', 'submitted', 'acknowledged'], true) && $open > 0.004 ? page_link('brgy.collections', ['a' => 'new', 'billing_id' => $id], 'Record collection', 'btn-outline-primary', 'cash-coin', 'create') . ' ' : '')
        . ($b['status'] !== 'void' && Access::can($pageKey, 'delete') ? '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mVoid"><i class="bi bi-x-circle"></i> Void</button> ' : '')
        . btn_link(url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'billing']), $p['project_no'], 'btn-outline-secondary', 'briefcase');
    layout_start('Billing ' . $b['billing_no'], brgy_layout(['actions' => $actions]));
    $netRecv = r2((float)$b['gross_amount'] - (float)$b['retention_expected'] - (float)$b['ewt_expected'] - (float)$b['vatwh_expected'] - (float)$b['ld_expected']);
    echo brgy_figures(['Status' => [Brgy::st($b['status'])], 'Amount billed' => [money($b['gross_amount']), 'info', money($b['net_amount']) . ' + VAT ' . money($b['vat_amount'])],
        'Expected deductions' => [money(r2((float)$b['retention_expected'] + (float)$b['ewt_expected'] + (float)$b['vatwh_expected'] + (float)$b['ld_expected'])), '', 'tax ' . money((float)$b['ewt_expected'] + (float)$b['vatwh_expected']) . ', retention ' . money($b['retention_expected'])],
        'Net cash expected' => [money($netRecv), 'ok'], 'Open on the invoice' => [$open !== null ? money($open) : 'no invoice yet', $open > 0.004 ? 'warn' : ($open !== null ? 'ok' : ''), $inv && $inv['due_date'] ? 'due ' . h(fdate($inv['due_date'])) : '']]);
    ?>
    <?php
    // what comes next
    $next = '';
    if (in_array($b['status'], ['draft', 'returned', 'for_review', 'reviewed'], true)) {
        $next = '<b>' . ($b['status'] === 'returned' ? 'Returned: correct and send again' : 'Maker, checker, approver') . '</b><div class="mt-1">' . brgy_step_buttons('billing', $b, $p, url($pageKey, ['a' => 'view', 'id' => $id]), 'bl') . '</div>';
    } elseif ($b['status'] === 'approved') {
        $next = '<div class="d-flex flex-wrap justify-content-between gap-2 align-items-center"><div><b>Approved: issue the invoice</b><div class="small text-muted">Posts one sales invoice to ' . h((string)$p['job_name']) . ' (debit accounts receivable, credit sales and output VAT; stocked goods leave the "delivered, awaiting acceptance" site at cost). A second invoice is refused.</div></div>'
            . (Access::can($pageKey, 'create') ? '<form method="post" action="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '" data-confirm="Post the sales invoice of ' . h(money($b['gross_amount'])) . ' now?">' . csrf_field() . '<input type="hidden" name="do" value="invoice"><button class="btn btn-success btn-sm"><i class="bi bi-receipt-cutoff"></i> Issue the invoice</button></form>' : '')
            . '</div>' . brgy_step_buttons('billing', $b, $p, url($pageKey, ['a' => 'view', 'id' => $id]), 'bl');
    } elseif ($b['status'] === 'invoiced') {
        $next = '<b>Invoiced: complete the pack and submit it to the entity</b><div class="small text-muted">Record the submission below with its proof (receiving copy, e-mail).</div>';
    } elseif ($b['status'] === 'submitted') {
        $next = '<b>Submitted ' . h(fdatetime($b['submitted_at'])) . '</b><div class="small text-muted">Record the entity\'s acknowledgment (receiving copy or reference) when it comes.</div>';
    } elseif ($b['status'] === 'acknowledged') {
        $next = '<b>Acknowledged ' . h(fdatetime($b['acknowledged_at'])) . ' by ' . h($b['received_by']) . '</b>' . ($open > 0.004 ? '<div class="small text-muted">Waiting for payment: ' . money($open) . ' open.</div>' : '<div class="small text-success">Fully settled.</div>');
    }
    if ($next): ?><div class="card mb-3"><div class="card-body py-2"><?= $next ?></div></div><?php endif; ?>

    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-list-ul"></i> Billed lines</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Line</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th><th class="num">Net of VAT</th><th class="num">VAT</th></tr></thead>
          <?php foreach ($bl as $l): ?><tr><td class="wrap-link small"><?= (int)$l['line_no'] ?>. <?= h($l['description']) ?> <span class="text-muted"><?= h($l['unit']) ?>, <?= h(Brgy::label(Brgy::LINE_KINDS, $l['line_kind'])) ?></span></td>
            <td class="num"><?= qty($l['qty']) ?></td><td class="num"><?= money($l['unit_price']) ?></td><td class="num"><?= money($l['gross']) ?></td><td class="num"><?= money($l['net']) ?></td><td class="num"><?= money($l['tax']) ?></td></tr><?php endforeach; ?>
          <tfoot><tr><td colspan="3" class="text-end">Total</td><td class="num"><?= money($b['gross_amount']) ?></td><td class="num"><?= money($b['net_amount']) ?></td><td class="num"><?= money($b['vat_amount']) ?></td></tr></tfoot>
        </table></div></div>

        <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span><i class="bi bi-folder-check"></i> Billing pack</span><span class="small text-muted"><?= count(array_filter($pack, fn($x) => $x['ok'])) ?> of <?= count($pack) ?> on file</span></div>
          <div class="table-responsive"><table class="table table-sm mb-0">
          <?php foreach ($pack as $x): ?><tr><td style="width:32px"><i class="bi <?= $x['ok'] ? 'bi-check-circle-fill text-success' : ($x['required'] ? 'bi-circle text-danger' : 'bi-circle text-muted') ?>"></i></td>
            <td class="small"><?= h($x['name']) ?><?= $x['required'] ? '' : ' <span class="text-muted">(if any)</span>' ?></td>
            <td class="small"><?= $x['doc'] ? ($x['doc']['id'] ? brgy_doc_link($x['doc']) : h($x['doc']['title'])) : ($x['form'] ? '<a href="' . h(url('brgy.forms', ['a' => 'view', 'id' => $x['form']['id']])) . '">' . h($x['form']['form_no'] . ' ' . $x['form']['title']) . '</a>' : '') ?></td>
            <td class="text-end text-nowrap"><?php if (!$x['ok'] && Access::can('brgy.vault', 'create') && !in_array($b['status'], ['void'], true)): ?><button class="btn btn-sm btn-link p-0" data-bs-toggle="modal" data-bs-target="#mPack" data-pack-type="<?= h($x['type']) ?>" data-pack-name="<?= h($x['name']) ?>">File it</button><?php endif; ?></td></tr>
          <?php endforeach; ?>
          </table></div>
          <?php if (Access::can('brgy.forms', 'create') && $b['status'] !== 'void'): ?>
          <form class="card-footer d-flex flex-wrap gap-2 align-items-center" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="form">
            <span class="small text-muted">Prepare</span>
            <select class="form-select form-select-sm w-auto" name="template_id" data-no-combo><?= options(DB::pairs("SELECT id, name FROM brgy_templates WHERE is_active = 1 AND stage IN ('billing', 'delivery') ORDER BY stage = 'billing' DESC, sort_order, name")) ?></select>
            <button class="btn btn-sm btn-outline-primary">Prepare the form</button></form>
          <?php endif; ?>
        </div>

        <?php if ($cols): ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-cash-coin"></i> Collections on this billing</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Collection</th><th>Date</th><th class="num">Settled</th><th class="num">Cash</th><th class="num">Taxes</th><th class="num">Retention</th><th>Status</th></tr></thead>
          <?php foreach ($cols as $c): ?><tr class="<?= $c['status'] !== 'posted' ? 'text-muted' : '' ?>"><td><a href="<?= h(url('brgy.collections', ['a' => 'view', 'id' => $c['id']])) ?>"><?= h($c['collection_no']) ?></a></td><td class="small"><?= h(fdate($c['collection_date'])) ?></td>
            <td class="num"><?= money($c['gross_applied']) ?></td><td class="num"><?= money($c['cash_received']) ?></td><td class="num"><?= money((float)$c['ewt_amount'] + (float)$c['vatwh_amount'], true) ?></td><td class="num"><?= money($c['retention_amount'], true) ?></td><td><?= Brgy::st($c['status']) ?></td></tr><?php endforeach; ?>
        </table></div></div>
        <?php endif; ?>
      </div>

      <div class="col-xl-4">
        <?php if ($b['status'] === 'invoiced' && $canEdit): ?>
          <form class="card mb-3" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="submit">
            <div class="card-header"><i class="bi bi-send"></i> Submit the pack to the entity</div><div class="card-body row g-2">
              <?= select_field('method', 'How', Brgy::SUB_METHODS, 'physical', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
              <div class="col-6"><label class="form-label">When</label><input class="form-control form-control-sm" type="datetime-local" name="submitted_at" value="<?= h(date('Y-m-d\TH:i')) ?>" required></div>
              <?= input_field('recipient_name', 'Addressed to', (string)(Brgy::official((int)$p['entity_id'], 'treasurer')['name'] ?? ''), ['class' => 'col-6']) ?>
              <?= input_field('recipient_office', 'Office', 'Office of the Treasurer', ['class' => 'col-6']) ?>
              <?= input_field('tracking_no', 'Tracking / e-mail ref.', '', ['class' => 'col-6']) ?>
              <?= input_field('received_by', 'Received by (if signed at once)', '', ['class' => 'col-6']) ?>
              <?= brgy_file_field('proof', 'Proof of submission') ?>
              <?php $miss = array_filter($pack, fn($x) => $x['required'] && !$x['ok']); if ($miss): ?><div class="col-12 small text-danger">Still missing: <?= h(implode(', ', array_column($miss, 'name'))) ?>.<?= Brgy::on('brgy_require_pack_complete') ? ' The pack has to be complete before it is submitted.' : '' ?></div><?php endif; ?>
            </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-send-check"></i> Record the submission</button></div></form>
        <?php elseif ($b['status'] === 'submitted' && $canEdit): ?>
          <form class="card mb-3" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="ack">
            <div class="card-header"><i class="bi bi-envelope-check"></i> Entity's acknowledgment</div><div class="card-body row g-2">
              <div class="col-6"><label class="form-label">Received on</label><input class="form-control form-control-sm" type="datetime-local" name="acknowledged_at" value="<?= h(date('Y-m-d\TH:i')) ?>" required></div>
              <?= input_field('received_by', 'Received by', '', ['class' => 'col-6', 'required' => true]) ?>
              <?= input_field('ack_ref', 'Logbook / reference no.', '', ['class' => 'col-12']) ?>
              <?= brgy_file_field('proof', 'Receiving copy') ?>
            </div><div class="card-footer"><button class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Record</button></div></form>
        <?php endif; ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-info-circle"></i> Details</div><div class="card-body"><dl class="dl-grid">
          <dt>Contract</dt><dd><?= brgy_project_link($p) ?> <?= h(mb_strimwidth($p['title'], 0, 60, '...')) ?></dd>
          <dt>Entity</dt><dd><?= h($p['entity_name']) ?></dd>
          <dt>Kind</dt><dd><?= h(Brgy::label(Brgy::BILLING_TYPES, $b['billing_type'])) ?></dd>
          <dt>Billing date</dt><dd><?= h(fdate($b['billing_date'])) ?></dd>
          <?php if ($b['period_from'] || $b['period_to']): ?><dt>Period</dt><dd><?= h(fdate($b['period_from'])) ?> to <?= h(fdate($b['period_to'])) ?></dd><?php endif; ?>
          <dt>Sales invoice</dt><dd><?= h($b['si_no'] ?: 'not entered') ?></dd>
          <dt>In the books</dt><dd><?= $inv ? brgy_txn($inv['id']) : '<span class="text-muted">not invoiced</span>' ?></dd>
          <dt>Income tax expected</dt><dd><?= money($b['ewt_expected']) ?></dd>
          <dt>VAT withheld expected</dt><dd><?= money($b['vatwh_expected']) ?></dd>
          <dt>Retention</dt><dd><?= money($b['retention_expected']) ?></dd>
          <?php if ((float)$b['ld_expected'] > 0): ?><dt>Liquidated damages</dt><dd><?= money($b['ld_expected']) ?></dd><?php endif; ?>
          <?php if ($b['submitted_at']): ?><dt>Submitted</dt><dd><?= h(fdatetime($b['submitted_at'])) ?></dd><?php endif; ?>
          <?php if ($b['acknowledged_at']): ?><dt>Acknowledged</dt><dd><?= h(fdatetime($b['acknowledged_at'])) ?>, <?= h($b['received_by']) ?> <?= h($b['ack_ref']) ?></dd><?php endif; ?>
          <?php if ($b['notes']): ?><dt>Notes</dt><dd><?= h($b['notes']) ?></dd><?php endif; ?>
        </dl></div></div>
        <div class="card"><div class="card-header"><i class="bi bi-diagram-3"></i> Approval trail</div><div class="card-body"><?= brgy_workflow_list('billing', $id) ?></div></div>
      </div>
    </div>
    <?php if (Access::can('brgy.vault', 'create')): ?>
    <div class="modal fade" id="mPack" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?>
      <input type="hidden" name="do" value="pack_upload"><input type="hidden" name="doc_type" data-fill-pack-type>
      <div class="modal-header"><h5 class="modal-title">File: <span data-fill-pack-name></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2"><?= brgy_file_field('file', 'Document', 'Scan or photo of the signed paper.', 'col-12', true) ?><?= input_field('title', 'Title (optional)', '', ['class' => 'col-12']) ?></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">File</button></div></form></div></div>
    <?php endif; ?>
    <?= brgy_reason_modal('mVoid', 'Void the billing', url($pageKey, ['a' => 'view', 'id' => $id]), 'void', [], 'btn-danger', true, 'Its invoice is voided in the books and the quantities can be billed again. A billing with collections cannot be voided: void them first.') ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$fStatus = (string)g('status', 'live');
$sql = 'SELECT b.*, p.project_no, p.title, e.official_name AS entity_name, t.total AS inv_total, t.is_voided AS inv_void, t.is_pending, t.due_date AS inv_due, t.id AS tid, t.txn_type
          FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id JOIN brgy_entities e ON e.id = p.entity_id LEFT JOIN transactions t ON t.id = b.invoice_txn_id WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (b.billing_no LIKE :q OR b.si_no LIKE :q OR p.project_no LIKE :q OR p.title LIKE :q OR e.official_name LIKE :q)';
    $params['q'] = "%$q%";
}
if ($fStatus === 'live') {
    $sql .= " AND b.status <> 'void'";
} elseif ($fStatus === 'approval') {
    $sql .= " AND b.status IN ('draft', 'for_review', 'reviewed', 'approved', 'returned')";
} elseif (isset(Brgy::WF[$fStatus])) {
    $sql .= ' AND b.status = :s';
    $params['s'] = $fStatus;
}
$rows = DB::all($sql . ' ORDER BY b.billing_date DESC, b.id DESC LIMIT 500', $params);
layout_start('Billing Packs', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'New billing pack', 'btn-primary', 'plus-lg', 'create')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-5"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Billing no., sales invoice, project, entity" data-live-filter="#billTable tbody tr" data-live-enter-submits></div>
  <?= select_field('status', 'Status', ['live' => 'All but void', 'approval' => 'Being prepared / in approval', 'invoiced' => 'Invoiced, not submitted', 'submitted' => 'Submitted, not acknowledged', 'acknowledged' => 'Acknowledged', 'void' => 'Void', 'all' => 'All'], $fStatus, ['class' => 'col-md-3', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="billTable">
  <thead><tr><th>Billing</th><th>Contract</th><th>Date</th><th class="num">Amount</th><th>Status</th><th>Invoice</th><th class="num">Open</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $open = $r['tid'] && !(int)$r['inv_void'] ? Balances::open(['id' => $r['tid'], 'total' => $r['inv_total'], 'is_voided' => $r['inv_void'], 'is_pending' => $r['is_pending'], 'txn_type' => $r['txn_type']]) : null; ?>
    <tr class="<?= $r['status'] === 'void' ? 'text-muted' : '' ?>"><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= h($r['billing_no']) ?></b></a><div class="small text-muted"><?= h(Brgy::label(Brgy::BILLING_TYPES, $r['billing_type'])) ?><?= $r['si_no'] ? ', SI ' . h($r['si_no']) : '' ?></div></td>
      <td class="wrap-link small"><?= brgy_project_link($r + ['project_id' => $r['project_id']]) ?> <?= h(mb_strimwidth($r['title'], 0, 60, '...')) ?><div class="text-muted"><?= h($r['entity_name']) ?></div></td>
      <td class="small text-nowrap"><?= h(fdate($r['billing_date'])) ?></td><td class="num"><?= money($r['gross_amount']) ?></td><td><?= Brgy::st($r['status']) ?></td>
      <td class="small"><?= $r['tid'] ? brgy_txn($r['tid']) : '' ?><?= $r['inv_due'] ? '<div class="text-muted">due ' . h(fdate($r['inv_due'])) . '</div>' : '' ?></td>
      <td class="num <?= $open > 0.004 && $r['inv_due'] && $r['inv_due'] < today() ? 'text-danger fw-semibold' : '' ?>"><?= $open !== null ? money($open, true) : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted py-4 text-center">No billings.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php
layout_end();
