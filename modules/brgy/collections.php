<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Collections & Retention
 * Payments of the procuring entities (checks, LDDAP-ADA, transfers) against billings, each tax withheld and each
 * deduction kept apart and posted, returned checks, retention released, and the BIR Forms 2307 received.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.collections';
$a = g('a', 'list');
$id = gint('id');

$loadCollection = function (int $id): array {
    $c = DB::row('SELECT c.*, b.billing_no, p.project_no, p.title AS project_title FROM brgy_collections c LEFT JOIN brgy_billings b ON b.id = c.billing_id JOIN brgy_projects p ON p.id = c.project_id WHERE c.id = :id', ['id' => $id]);
    if (!$c) {
        flash('warning', 'Collection not found.');
        redirect(url('brgy.collections'));
    }
    return $c;
};

// ------------------------------------------------------------------ actions on a collection
if ($a === 'view' && $id && is_post()) {
    $do = (string)p('do');
    $r = trim((string)p('remarks'));
    brgy_action(function () use ($do, $id, $r, $pageKey) {
        switch ($do) {
            case 'cert':
                BrgyOps::attach2307($id, brgy_file('file'), pint('doc_id'));
                return 'BIR Form 2307 recorded as received.';
            case 'bounce':
                Access::require($pageKey, 'edit');
                BrgyPost::bounce($id, (string)p('bounce_date'), $r, pnum('fee'), pint('fee_account_id'));
                return 'Returned check recorded. The billing is open again for the amount of the check; record the replacement as a new collection.';
            case 'void':
                Access::require($pageKey, 'delete');
                if ($r === '') {
                    fail('Enter the reason.');
                }
                BrgyPost::voidCollection($id, $r);
                return 'Collection voided; its payment and deductions were voided in the books.';
            case 'form':
                $c = DB::row('SELECT project_id FROM brgy_collections WHERE id = :id', ['id' => $id]);
                $fid = BrgyOps::createForm((int)pint('template_id'), (int)$c['project_id'], ['collection', $id]);
                redirect(url('brgy.forms', ['a' => 'edit', 'id' => $fid]));
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['a' => 'view', 'id' => $id]));
}

// ------------------------------------------------------------------ new collection / retention release
if ($a === 'new') {
    Access::require($pageKey, 'create');
    $kind = (string)g('kind', p('kind', 'billing')) === 'retention' ? 'retention' : 'billing';
    $billingId = gint('billing_id') ?: pint('billing_id');
    $projectId = gint('project_id') ?: pint('project_id');
    handle_post(function () use ($pageKey) {
        $d = $_POST;
        if ($f = brgy_file('cert_2307_file')) {
            $d['cert_2307_file'] = $f;
        }
        $newId = BrgyOps::saveCollection($d);
        $c = DB::row('SELECT * FROM brgy_collections WHERE id = :id', ['id' => $newId]);
        flash('success', $c['kind'] === 'retention' ? 'Retention release ' . $c['collection_no'] . ' posted (deposit to the bank, retention receivable cleared).'
            : 'Collection ' . $c['collection_no'] . ' posted: customer payment with the taxes withheld' . ((float)$c['retention_amount'] + (float)$c['ld_amount'] + (float)$c['other_deduction'] + (float)$c['bank_charges'] > 0 ? ', and a credit memo for the deductions' : '') . '.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $newId]));
    });
    layout_start($kind === 'retention' ? 'Retention released' : 'Record collection', brgy_layout(['actions' => btn_link($projectId ? url('brgy.projects', ['a' => 'view', 'id' => $projectId, 'tab' => 'billing']) : url($pageKey), 'Cancel')]));

    if ($kind === 'retention'):
        $cands = [];
        foreach (DB::all("SELECT * FROM brgy_projects WHERE status IN ('awarded', 'closed') AND job_customer_id IS NOT NULL ORDER BY project_no DESC") as $x) {
            $held = Brgy::money($x)['retention_held'];
            if ($held > 0.004) {
                $cands[$x['id']] = $x['project_no'] . ' - ' . mb_strimwidth($x['title'], 0, 60, '...') . ' (held ' . money($held) . ')';
            }
        }
        $p = $projectId ? Brgy::project($projectId) : null;
        $held = $p ? Brgy::money($p)['retention_held'] : 0.0; ?>
        <form method="post" class="brgy-form" style="max-width:860px" data-no-class-check><?= csrf_field() ?><input type="hidden" name="kind" value="retention">
          <div class="card mb-3"><div class="card-header"><i class="bi bi-piggy-bank"></i> Retention released by the entity</div><div class="card-body row g-2">
            <?= select_field('project_id', 'Contract', $cands, $projectId, ['blank' => '', 'class' => 'col-12', 'required' => true, 'extra' => 'data-combo']) ?>
            <?php if ($p): ?><div class="col-12 small text-muted">Held on <?= h($p['project_no']) ?>: <b><?= money($held) ?></b><?= $p['warranty_until'] ? ', warranty until ' . h(fdate($p['warranty_until'])) : '' ?>.</div><?php endif; ?>
            <?= input_field('collection_date', 'Date received', fv('collection_date', null, today()), ['type' => 'date', 'class' => 'col-md-4', 'required' => true]) ?>
            <?= select_field('method', 'How', Brgy::COLLECTION_METHODS, fv('method', null, 'lddap_ada'), ['class' => 'col-md-4', 'extra' => 'data-no-combo id="cMethod"']) ?>
            <?= input_field('cash_received', 'Amount released', fv('cash_received', null, $held > 0 ? number_format($held, 2, '.', '') : ''), ['class' => 'col-md-4', 'required' => true, 'extra' => 'inputmode="decimal"']) ?>
            <?= input_field('reference_no', 'LDDAP-ADA / reference no.', fv('reference_no'), ['class' => 'col-md-4']) ?>
            <div class="col-md-4" data-toggle-by="#cMethod" data-show-when="check"><label class="form-label">Check no.</label><input class="form-control form-control-sm" name="check_no" value="<?= h(fv('check_no')) ?>"></div>
            <?= select_field('deposit_account_id', 'Received in (bank)', Accts::banks(), fv('deposit_account_id'), ['blank' => '', 'class' => 'col-md-4', 'required' => true]) ?>
            <?= input_field('notes', 'Notes', fv('notes'), ['class' => 'col-12']) ?>
            <div class="col-12 form-text">Posts a deposit: debit the bank, credit Retention Receivable on the Customer:Job. Retention is released once the warranty or the entity's conditions are met.</div>
          </div></div>
          <div class="sticky-actions"><?= save_buttons('Post the release', url($pageKey), false) ?></div>
        </form>
    <?php
    else:
        $bills = [];
        foreach (DB::all("SELECT b.*, p.project_no FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.status IN ('invoiced', 'submitted', 'acknowledged') ORDER BY b.billing_date DESC") as $x) {
            $tg = BrgyPost::collectionTargets($x);
            if ($tg['open'] > 0.004 || (int)$x['id'] === (int)$billingId) {
                $bills[$x['id']] = $x['billing_no'] . ' (' . $x['project_no'] . ') ' . fdate($x['billing_date']) . ', open ' . money($tg['open']);
            }
        }
        $b = $billingId ? DB::row('SELECT b.*, p.project_no FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.id = :id', ['id' => $billingId]) : null;
        if (!$b && $projectId && count($ids = array_keys(array_filter($bills, fn($v, $k) => (int)DB::value('SELECT project_id FROM brgy_billings WHERE id = :i', ['i' => $k]) === $projectId, ARRAY_FILTER_USE_BOTH))) === 1) {
            $b = DB::row('SELECT b.*, p.project_no FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.id = :id', ['id' => $ids[0]]);
        }
        if (!$b): ?>
          <form method="get" class="card card-body" style="max-width:760px"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="new">
            <?= select_field('billing_id', 'Billing being paid', $bills, null, ['blank' => '', 'class' => 'col-12', 'required' => true, 'extra' => 'data-combo']) ?>
            <div class="mt-2 d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm">Continue</button><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['a' => 'new', 'kind' => 'retention', 'project_id' => $projectId])) ?>">Retention released instead</a></div>
            <?php if (!$bills): ?><p class="small text-muted mt-2 mb-0">No invoiced billing is open.</p><?php endif; ?>
          </form>
        <?php layout_end(); return; endif;
        $p = Brgy::mustProject((int)$b['project_id']);
        $tg = BrgyPost::collectionTargets($b);
        $chargesOpen = r2(array_sum(array_map(fn($x) => (float)$x['open_balance'], $tg['charges'])));
        $invOpen = $tg['invoice'] ? (float)$tg['invoice']['open_balance'] : 0.0;
        $exp = BrgyPost::expectedWithholding($b, $invOpen);
        $ratio = (float)$b['gross_amount'] > 0 ? $invOpen / (float)$b['gross_amount'] : 0;
        $retExp = r2((float)$b['retention_expected'] * $ratio);
        $ldExp = r2((float)$b['ld_expected'] * $ratio);
        // a transaction of the SOP: the cheque recorded on its Process tab fills the payment
        $pre = BrgySop::uses($p) && $p['payment_status'] === 'released' ? ['check_no' => (string)$p['cheque_no'], 'check_date' => (string)$p['cheque_date'], 'bank_name' => (string)$p['cheque_bank'],
            'cash_received' => $p['cheque_amount'] !== null ? number_format((float)$p['cheque_amount'], 2, '.', '') : ''] : [];
        $vf = fn($k) => is_post() ? (string)p($k) : (string)($pre[$k] ?? '');
        ?>
        <form method="post" class="brgy-form" enctype="multipart/form-data" data-no-class-check id="colForm"><?= csrf_field() ?><input type="hidden" name="kind" value="billing"><input type="hidden" name="billing_id" value="<?= (int)$b['id'] ?>">
          <div class="card mb-3"><div class="card-header"><i class="bi bi-receipt"></i> Billing <?= h($b['billing_no']) ?>, <?= h($p['project_no']) ?> <?= h(mb_strimwidth($p['title'], 0, 70, '...')) ?></div><div class="card-body">
            <?= brgy_figures(['Billed' => [money($b['gross_amount'])], 'Open on the invoice' => [money($invOpen), $invOpen > 0 ? 'warn' : ''], 'Returned checks to cover' => [money($chargesOpen), $chargesOpen > 0 ? 'bad' : ''],
                'Expected tax withheld' => [money($exp['ewt'] + $exp['vat']), '', 'income tax ' . money($exp['ewt']) . ', VAT ' . money($exp['vat'])], 'Expected net cash' => [money(r2($invOpen + $chargesOpen - $exp['ewt'] - $exp['vat'] - $retExp - $ldExp)), 'ok']]) ?>
            <button type="button" class="btn btn-sm btn-outline-primary" id="fillExpected" data-ewt="<?= h((string)$exp['ewt']) ?>" data-vat="<?= h((string)$exp['vat']) ?>" data-ret="<?= h((string)$retExp) ?>" data-ld="<?= h((string)$ldExp) ?>" data-open="<?= h((string)r2($invOpen + $chargesOpen)) ?>"><i class="bi bi-magic"></i> Fill as expected (paid in full)</button>
            <span class="small text-muted ms-2">Then change the amounts to what the check / LDDAP-ADA and the entity's 2307 actually show.</span>
          </div></div>
          <div class="row g-3">
            <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-cash"></i> Payment received</div><div class="card-body row g-2">
              <?= input_field('collection_date', 'Date received', fv('collection_date', null, today()), ['type' => 'date', 'class' => 'col-md-6', 'required' => true]) ?>
              <?= select_field('method', 'How', Brgy::COLLECTION_METHODS, fv('method', null, 'check'), ['class' => 'col-md-6', 'extra' => 'data-no-combo id="cMethod"']) ?>
              <div class="col-md-6" data-toggle-by="#cMethod" data-show-when="check"><label class="form-label">Check no.</label><input class="form-control form-control-sm" name="check_no" value="<?= h($vf('check_no')) ?>"></div>
              <div class="col-md-6" data-toggle-by="#cMethod" data-show-when="check"><label class="form-label">Check date</label><input class="form-control form-control-sm" type="date" name="check_date" value="<?= h($vf('check_date')) ?>"></div>
              <?= input_field('bank_name', 'Drawee bank', $vf('bank_name') ?: 'Land Bank of the Philippines', ['class' => 'col-md-6']) ?>
              <?= input_field('reference_no', 'LDDAP-ADA / transfer ref.', $vf('reference_no'), ['class' => 'col-md-6']) ?>
              <?= select_field('deposit_account_id', 'Received in', Accts::depositTo(), fv('deposit_account_id', null, Accts::get('undeposited_funds')), ['class' => 'col-md-6']) ?>
              <?= input_field('receipt_no', 'Receipt issued (acknowledgment / collection receipt)', $vf('receipt_no'), ['class' => 'col-md-6']) ?>
              <div class="col-12"><label class="form-label">Cash received (amount of the check / credit)</label><input class="form-control text-end fw-semibold" name="cash_received" inputmode="decimal" value="<?= h($vf('cash_received')) ?>" required data-col></div>
            </div></div></div>
            <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-scissors"></i> Deducted by the entity</div><div class="card-body row g-2">
              <?= input_field('ewt_amount', 'Income tax withheld (1% goods / 2% services)', $vf('ewt_amount'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" data-col']) ?>
              <?= input_field('vatwh_amount', 'VAT withheld (5%)', $vf('vatwh_amount'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" data-col']) ?>
              <?= input_field('retention_amount', 'Retention', $vf('retention_amount'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" data-col']) ?>
              <?= input_field('ld_amount', 'Liquidated damages', $vf('ld_amount'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" data-col']) ?>
              <?= input_field('other_deduction', 'Other deduction', $vf('other_deduction'), ['class' => 'col-md-4', 'extra' => 'inputmode="decimal" data-col']) ?>
              <?= input_field('other_note', 'What it is', $vf('other_note'), ['class' => 'col-md-4']) ?>
              <?= select_field('other_account_id', 'Charged to', Accts::ofType(['Expense', 'Other Expense', 'Cost of Goods Sold']), fv('other_account_id'), ['blank' => '', 'class' => 'col-md-4']) ?>
              <?= input_field('bank_charges', 'Bank charges', $vf('bank_charges'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" data-col']) ?>
              <div class="col-md-6"><label class="form-label">Settled on the billing</label><div class="form-control form-control-sm text-end bg-light fw-semibold" id="colSettled">0.00</div><div class="form-text" id="colLeft"></div></div>
            </div></div></div>
          </div>
          <div class="card mt-3 mb-3"><div class="card-body row g-2">
            <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="cert_2307" value="1" <?= p('cert_2307') ? 'checked' : '' ?>> <span class="form-check-label">BIR Form 2307 received with the payment</span></label></div>
            <?= brgy_file_field('cert_2307_file', 'The 2307 (scan)', '', 'col-md-8') ?>
            <?= input_field('variance_note', 'If the taxes withheld differ from the table: why', $vf('variance_note'), ['class' => 'col-md-8']) ?>
            <?= input_field('notes', 'Notes', $vf('notes'), ['class' => 'col-md-4']) ?>
            <div class="col-12 form-text">Posts a customer payment with the taxes withheld (they go to Creditable Withholding Tax and Creditable VAT Withheld and to the 2307 / BIR reports), and a credit memo for retention, liquidated damages, other deductions and bank charges. A returned check still open on this billing is covered first.</div>
          </div></div>
          <div class="sticky-actions"><?= save_buttons('Post the collection', url('brgy.billing', ['a' => 'view', 'id' => $b['id']]), false) ?></div>
        </form>
    <?php
    endif;
    layout_end();
    return;
}

// ------------------------------------------------------------------ view
if ($a === 'view' && $id) {
    $c = $loadCollection($id);
    $p = Brgy::mustProject((int)$c['project_id']);
    $canEdit = Access::can($pageKey, 'edit');
    $actions = ($c['status'] === 'posted' && $c['kind'] === 'billing' && $c['method'] === 'check' && $canEdit ? '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mBounce"><i class="bi bi-arrow-counterclockwise"></i> Check returned</button> ' : '')
        . ($c['status'] === 'posted' && Access::can($pageKey, 'delete') ? '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mVoid"><i class="bi bi-x-circle"></i> Void</button> ' : '')
        . btn_link(url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'billing']), $p['project_no'], 'btn-outline-secondary', 'briefcase');
    layout_start(($c['kind'] === 'retention' ? 'Retention release ' : 'Collection ') . $c['collection_no'], brgy_layout(['actions' => $actions]));
    $figs = ['Status' => [Brgy::st($c['status'])], 'Cash received' => [money($c['cash_received']), 'ok', h(Brgy::label(Brgy::COLLECTION_METHODS, $c['method'])) . ' ' . h($c['check_no'] ?: $c['reference_no'])]];
    if ($c['kind'] === 'billing') {
        $figs += ['Settled on the billing' => [money($c['gross_applied']), 'info'], 'Taxes withheld' => [money((float)$c['ewt_amount'] + (float)$c['vatwh_amount']), '', (float)$c['wht_variance'] != 0.0 ? 'differs from the table by ' . money($c['wht_variance']) : 'as the table'],
            'Other deductions' => [money((float)$c['retention_amount'] + (float)$c['ld_amount'] + (float)$c['other_deduction'] + (float)$c['bank_charges'])], 'BIR Form 2307' => [$c['cert_2307'] ? 'Received' : 'Pending', $c['cert_2307'] ? 'ok' : 'warn']];
    }
    echo brgy_figures($figs);
    ?>
    <div class="row g-3">
      <div class="col-xl-7">
        <?php if ($c['kind'] === 'billing'): ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-list-ol"></i> Breakdown</div><div class="table-responsive"><table class="table table-sm mb-0">
          <?php foreach (['Cash received' => $c['cash_received'], 'Income tax withheld' => $c['ewt_amount'], 'VAT withheld' => $c['vatwh_amount'], 'Retention' => $c['retention_amount'], 'Liquidated damages' => $c['ld_amount'],
              ($c['other_note'] ?: 'Other deduction') => $c['other_deduction'], 'Bank charges' => $c['bank_charges']] as $k => $v): if ((float)$v == 0.0 && $k !== 'Cash received') { continue; } ?>
            <tr><td><?= h($k) ?></td><td class="num"><?= money($v) ?></td></tr>
          <?php endforeach; ?>
          <tfoot><tr><td>Settled on billing <?= h($c['billing_no']) ?></td><td class="num"><?= money($c['gross_applied']) ?></td></tr></tfoot>
        </table></div></div>
        <?php endif; ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-journal-text"></i> In the books</div><div class="card-body small">
          <?php foreach (['payment_txn_id' => 'Payment', 'deduction_txn_id' => 'Deductions (credit memo)', 'release_txn_id' => 'Retention release (deposit)', 'bounce_txn_id' => 'Returned check'] as $k => $lbl): if (!$c[$k]) { continue; } ?>
            <div class="mb-1"><span class="text-muted"><?= h($lbl) ?>:</span> <?= brgy_txn($c[$k]) ?></div>
          <?php endforeach; ?>
          <?php if ($c['status'] === 'bounced'): ?><div class="text-danger mt-2">Check returned on <?= h(fdate($c['bounce_date'])) ?>: <?= h($c['bounce_reason']) ?></div><?php endif; ?>
        </div></div>
      </div>
      <div class="col-xl-5">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-info-circle"></i> Details</div><div class="card-body"><dl class="dl-grid">
          <dt>Contract</dt><dd><?= brgy_project_link($p) ?> <?= h(mb_strimwidth($p['title'], 0, 60, '...')) ?></dd>
          <dt>Entity</dt><dd><?= h($p['entity_name']) ?></dd>
          <?php if ($c['billing_id']): ?><dt>Billing</dt><dd><a href="<?= h(url('brgy.billing', ['a' => 'view', 'id' => $c['billing_id']])) ?>"><?= h($c['billing_no']) ?></a></dd><?php endif; ?>
          <dt>Date</dt><dd><?= h(fdate($c['collection_date'])) ?></dd>
          <?php foreach (array_filter(['Check no.' => $c['check_no'], 'Check date' => fdate($c['check_date']), 'Bank' => $c['bank_name'], 'Reference' => $c['reference_no'], 'Receipt issued' => $c['receipt_no'],
              'Received in' => $c['deposit_account_id'] ? (string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :id", ['id' => $c['deposit_account_id']]) : '',
              'Tax difference' => (float)$c['wht_variance'] != 0.0 ? money($c['wht_variance']) . ': ' . $c['variance_note'] : '', 'Notes' => $c['notes']]) as $k => $v): ?>
            <dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd>
          <?php endforeach; ?>
          <dt>Recorded</dt><dd><?= h(Brgy::userName((int)$c['created_by'])) ?>, <?= h(fdatetime($c['created_at'])) ?></dd>
        </dl></div></div>
        <?php if ($c['kind'] === 'billing' && $c['status'] === 'posted'): ?>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-file-earmark-check"></i> BIR Form 2307</div><div class="card-body">
          <?php if ($c['cert_2307_doc_id']): ?><div class="mb-2"><?= brgy_doc_link($c['cert_2307_doc_id']) ?></div><?php endif; ?>
          <?php if ($canEdit && !$c['cert_2307_doc_id']): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" data-no-class-check class="row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="cert">
              <?= brgy_file_field('file', 'The 2307 the entity issued', 'Keep it for the quarterly and annual returns.') ?>
              <div class="col-12"><button class="btn btn-sm btn-primary"><?= $c['cert_2307'] ? 'File the scan' : 'Mark as received' ?></button></div></form>
          <?php elseif (!$c['cert_2307']): ?><div class="text-warning-emphasis small">Not received yet.</div><?php endif; ?>
        </div></div>
        <?php endif; ?>
        <?php if (Access::can('brgy.forms', 'create') && $c['status'] === 'posted'): ?>
          <form class="card" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="form">
            <div class="card-header"><i class="bi bi-file-earmark-richtext"></i> Prepare a form</div><div class="card-body">
              <select class="form-select form-select-sm" name="template_id" data-no-combo><?= options(DB::pairs("SELECT id, name FROM brgy_templates WHERE is_active = 1 AND stage = 'collection' ORDER BY sort_order, name")) ?></select></div>
            <div class="card-footer"><button class="btn btn-sm btn-outline-primary">Prepare</button></div></form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    $bounceExtra = '<div class="row g-2 mb-2"><div class="col-6"><label class="form-label">Returned on</label><input class="form-control form-control-sm" type="date" name="bounce_date" value="' . h(today()) . '" required></div>'
        . '<div class="col-6"><label class="form-label">Bank fee (if any)</label><input class="form-control form-control-sm" name="fee" inputmode="decimal"></div>'
        . '<div class="col-12"><label class="form-label">Fee charged to</label><select class="form-select form-select-sm" name="fee_account_id">' . options(Accts::ofType(['Expense', 'Other Expense']), Accts::get('merchant_fees'), '') . '</select></div></div>';
    echo brgy_reason_modal('mBounce', 'Check returned', url($pageKey, ['a' => 'view', 'id' => $id]), 'bounce', [], 'btn-danger', true,
        'Not deposited yet: the payment is voided. Deposited: a returned-check charge puts the amount back on the receivable and takes it out of the bank. The reason is what the bank gave (DAIF, account closed, stale).', $bounceExtra);
    echo brgy_reason_modal('mVoid', 'Void the collection', url($pageKey, ['a' => 'view', 'id' => $id]), 'void', [], 'btn-danger', true, 'For a collection recorded by mistake. Its payment and deductions are voided in the books.');
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$fStatus = (string)g('status');
$fKind = (string)g('kind');
$f2307 = (string)g('cert');
$sql = 'SELECT c.*, b.billing_no, p.project_no, p.title, e.official_name AS entity_name FROM brgy_collections c LEFT JOIN brgy_billings b ON b.id = c.billing_id
          JOIN brgy_projects p ON p.id = c.project_id JOIN brgy_entities e ON e.id = p.entity_id WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (c.collection_no LIKE :q OR c.check_no LIKE :q OR c.reference_no LIKE :q OR b.billing_no LIKE :q OR p.project_no LIKE :q OR e.official_name LIKE :q)';
    $params['q'] = "%$q%";
}
if (in_array($fStatus, ['posted', 'bounced', 'void'], true)) {
    $sql .= ' AND c.status = :s';
    $params['s'] = $fStatus;
}
if (in_array($fKind, ['billing', 'retention'], true)) {
    $sql .= ' AND c.kind = :k';
    $params['k'] = $fKind;
}
if ($f2307 === 'pending') {
    $sql .= " AND c.kind = 'billing' AND c.status = 'posted' AND c.cert_2307 = 0 AND (c.ewt_amount + c.vatwh_amount) > 0";
}
$rows = DB::all($sql . ' ORDER BY c.collection_date DESC, c.id DESC LIMIT 500', $params);
$tot = ['cash' => 0.0, 'tax' => 0.0, 'ded' => 0.0];
foreach ($rows as $r) {
    if ($r['status'] === 'posted') {
        $tot['cash'] += (float)$r['cash_received'];
        $tot['tax'] += (float)$r['ewt_amount'] + (float)$r['vatwh_amount'];
        $tot['ded'] += (float)$r['retention_amount'] + (float)$r['ld_amount'] + (float)$r['other_deduction'] + (float)$r['bank_charges'];
    }
}
layout_start('Collections & Retention', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'Record collection', 'btn-primary', 'plus-lg', 'create') . ' '
    . page_link($pageKey, ['a' => 'new', 'kind' => 'retention'], 'Retention released', 'btn-outline-primary', 'piggy-bank', 'create')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-4"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Collection, check, LDDAP-ADA, billing, project, entity" data-live-filter="#colTable tbody tr" data-live-enter-submits></div>
  <?= select_field('kind', 'Kind', ['billing' => 'Payments of billings', 'retention' => 'Retention released'], $fKind, ['blank' => 'All', 'class' => 'col-md-2', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <?= select_field('status', 'Status', ['posted' => 'Posted', 'bounced' => 'Returned checks', 'void' => 'Void'], $fStatus, ['blank' => 'All', 'class' => 'col-md-2', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <?= select_field('cert', 'Form 2307', ['pending' => 'Not received yet'], $f2307, ['blank' => 'All', 'class' => 'col-md-2', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<?= brgy_figures(['Cash received' => [money($tot['cash']), 'ok'], 'Taxes withheld' => [money($tot['tax'])], 'Other deductions' => [money($tot['ded'])]]) ?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="colTable">
  <thead><tr><th>Collection</th><th>Contract</th><th>Date</th><th>Billing</th><th class="num">Settled</th><th class="num">Cash</th><th class="num">Taxes</th><th class="num">Deductions</th><th>2307</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] !== 'posted' ? 'text-muted' : '' ?>"><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= h($r['collection_no']) ?></b></a><div class="small text-muted"><?= h(Brgy::label(Brgy::COLLECTION_METHODS, $r['method'])) ?> <?= h($r['check_no'] ?: $r['reference_no']) ?></div></td>
      <td class="wrap-link small"><?= brgy_project_link($r + ['project_id' => $r['project_id']]) ?><div class="text-muted"><?= h($r['entity_name']) ?></div></td>
      <td class="small text-nowrap"><?= h(fdate($r['collection_date'])) ?></td><td class="small"><?= $r['kind'] === 'retention' ? 'Retention' : h($r['billing_no']) ?></td>
      <td class="num"><?= money($r['gross_applied'], true) ?></td><td class="num"><?= money($r['cash_received']) ?></td><td class="num"><?= money((float)$r['ewt_amount'] + (float)$r['vatwh_amount'], true) ?></td>
      <td class="num"><?= money((float)$r['retention_amount'] + (float)$r['ld_amount'] + (float)$r['other_deduction'] + (float)$r['bank_charges'], true) ?></td>
      <td class="small"><?= $r['kind'] === 'billing' && ((float)$r['ewt_amount'] + (float)$r['vatwh_amount']) > 0 ? ((int)$r['cert_2307'] ? 'received' : '<span class="text-warning-emphasis">pending</span>') : '' ?></td><td><?= Brgy::st($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-muted py-4 text-center">No collections.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php
layout_end();
