<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Barangay Project Monitoring Board (v39): a snapshot of the barangays' projects, as the monitoring sheet
 * keeps them: check date, transaction code, barangay, particulars, gross and cheque amounts, supplier (tradename), tax %,
 * sales or service invoice, collection receipt, delivery receipt, check no., completion of the process and status.
 * Filtered by process (small value procurement, bidding, twelve phases), barangay, amount, supplier, cheque amount, C.R.,
 * D.R., invoice and status. Read only: each row opens its project.
 */

$pageKey = 'brgy.board';
require_once APP_ROOT . '/modules/brgy/_ui.php';

if (!Brgy::ready()) {
    layout_start('Barangay Project Monitoring Board', brgy_layout());
    echo '<div class="alert alert-warning">Barangay Contracts is not set up on this database yet.</div>';
    layout_end();
    return;
}

$num = fn(string $k) => trim((string)g($k)) === '' ? null : to_num((string)g($k));
$f = [
    'process' => (string)g('process'), 'entity_id' => gint('entity_id'), 'tradename' => trim((string)g('tradename')), 'status' => (string)g('status', 'all'),
    'gross_min' => $num('gross_min'), 'gross_max' => $num('gross_max'), 'cheque_min' => $num('cheque_min'), 'cheque_max' => $num('cheque_max'),
    'cr' => trim((string)g('cr')), 'dr' => trim((string)g('dr')), 'si' => trim((string)g('si')), 'q' => trim((string)g('q')),
];
$STATUS = ['ongoing' => 'Ongoing', 'waiting' => 'Waiting for the cheque', 'cheque' => 'Cheque released', 'completed' => 'Process completed', 'closed' => 'Closed', 'ended' => 'Lost, no bid or cancelled', 'all' => 'All'];
if (!isset($STATUS[$f['status']])) {
    $f['status'] = 'all';
}

// the gross amount: the contract once awarded, else the gross of the transaction form, the bid or the ABC
$grossSql = "CASE WHEN p.status IN ('awarded', 'closed') THEN p.contract_amount + p.co_amount
                  ELSE COALESCE(NULLIF(p.est_gross, 0), NULLIF(p.bid_amount, 0), p.abc_amount) END";
$sql = "SELECT p.*, e.official_name AS entity_name, e.barangay AS entity_short, e.entity_type, s.step_no, s.title AS step_title, $grossSql AS gross,
               (SELECT GROUP_CONCAT(DISTINCT b.si_no ORDER BY b.id SEPARATOR ', ') FROM brgy_billings b
                 WHERE b.project_id = p.id AND b.status <> 'void' AND b.si_no IS NOT NULL AND b.si_no <> '') AS si_nos,
               (SELECT GROUP_CONCAT(DISTINCT c.receipt_no ORDER BY c.id SEPARATOR ', ') FROM brgy_collections c
                 WHERE c.project_id = p.id AND c.status = 'posted' AND c.receipt_no IS NOT NULL AND c.receipt_no <> '') AS cr_nos,
               (SELECT GROUP_CONCAT(DISTINCT d.dr_no ORDER BY d.id SEPARATOR ', ') FROM brgy_deliveries d WHERE d.project_id = p.id AND d.status <> 'void') AS dr_nos,
               (SELECT COUNT(*) FROM brgy_project_steps ps WHERE ps.project_id = p.id AND ps.status = 'pending') AS steps_left,
               (SELECT COUNT(*) FROM brgy_project_steps ps WHERE ps.project_id = p.id) AS steps_all,
               (SELECT MAX(ps.done_at) FROM brgy_project_steps ps WHERE ps.project_id = p.id AND ps.status <> 'pending') AS last_done
          FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id LEFT JOIN brgy_sop_steps s ON s.code = p.sop_step
         WHERE 1 = 1";
$params = [];
if (isset(BrgySop::PROCESSES[$f['process']])) {
    $sql .= ' AND p.process = :pr';
    $params['pr'] = $f['process'];
}
if ($f['entity_id']) {
    $sql .= ' AND p.entity_id = :e';
    $params['e'] = $f['entity_id'];
}
if ($f['tradename'] !== '') {
    $sql .= ' AND COALESCE(NULLIF(p.cheque_tradename, \'\'), p.tradename) = :tn';
    $params['tn'] = $f['tradename'];
}
foreach (['gross_min' => [$grossSql, '>='], 'gross_max' => [$grossSql, '<='], 'cheque_min' => ['p.cheque_amount', '>='], 'cheque_max' => ['p.cheque_amount', '<=']] as $k => [$col, $op]) {
    if ($f[$k] !== null) {
        $sql .= " AND $col $op :$k";
        $params[$k] = $f[$k];
    }
}
foreach (['cr' => "EXISTS (SELECT 1 FROM brgy_collections c WHERE c.project_id = p.id AND c.status = 'posted' AND c.receipt_no LIKE :cr)",
          'dr' => "EXISTS (SELECT 1 FROM brgy_deliveries d WHERE d.project_id = p.id AND d.status <> 'void' AND d.dr_no LIKE :dr)",
          'si' => "EXISTS (SELECT 1 FROM brgy_billings b WHERE b.project_id = p.id AND b.status <> 'void' AND b.si_no LIKE :si)"] as $k => $cond) {
    if ($f[$k] !== '') {
        $sql .= ' AND ' . $cond;
        $params[$k] = '%' . $f[$k] . '%';
    }
}
if ($f['q'] !== '') {
    $sql .= ' AND (p.title LIKE :q OR p.project_no LIKE :q OR p.txn_form_no LIKE :q OR p.cheque_no LIKE :q OR e.official_name LIKE :q OR p.purpose LIKE :q)';
    $params['q'] = '%' . $f['q'] . '%';
}
$sql .= match ($f['status']) {
    'ongoing' => " AND p.status IN ('open', 'awarded')",
    'waiting' => " AND p.status IN ('open', 'awarded') AND p.payment_status <> 'released'",
    'cheque' => " AND p.payment_status = 'released'",
    'completed' => " AND p.status IN ('open', 'awarded', 'closed') AND p.process <> 'general' AND NOT EXISTS (SELECT 1 FROM brgy_project_steps ps WHERE ps.project_id = p.id AND ps.status = 'pending')
                     AND EXISTS (SELECT 1 FROM brgy_project_steps ps WHERE ps.project_id = p.id)",
    'closed' => " AND p.status = 'closed'",
    'ended' => " AND p.status IN ('lost', 'no_bid', 'cancelled')",
    default => '',
};
$rows = DB::all($sql . ' ORDER BY COALESCE(p.cheque_date, p.txn_date, DATE(p.created_at)) DESC, p.id DESC', $params);

/** what a row shows: the values of the monitoring sheet */
$tn0 = Brgy::defaultTradename();
$view = function (array $r) use ($tn0): array {
    $sop = in_array($r['process'], ['svp', 'bidding'], true);
    $done = $sop && (int)$r['steps_all'] > 0 && (int)$r['steps_left'] === 0;
    $completed = $r['closeout_date'] ?: ($done && $r['last_done'] ? substr((string)$r['last_done'], 0, 10) : '');
    $tax = $r['est_wt_rate'] !== null && $r['est_wt_rate'] !== '' ? (float)$r['est_wt_rate']
        : ((float)$r['dv_vat_rate'] + (float)$r['dv_ewt_rate'] > 0 ? (float)$r['dv_vat_rate'] + (float)$r['dv_ewt_rate'] : null);
    if (in_array($r['status'], ['lost', 'no_bid', 'cancelled'], true)) {
        $status = [Brgy::label(Brgy::STATUSES, $r['status']), 'bad'];
    } elseif ($r['status'] === 'closed') {
        $status = ['Closed', 'muted'];
    } elseif ($done) {
        $status = ['Process completed', 'ok'];
    } elseif ($sop && $r['step_no']) {
        $status = ['Step ' . (int)$r['step_no'] . ': ' . $r['step_title'], 'info'];
    } else {
        $status = [Brgy::label(Brgy::STATUSES, $r['status']) . ($r['process'] === 'general' ? ', ' . Brgy::phaseLabel($r['phase']) : ''), 'info'];
    }
    $pay = $r['payment_status'] === 'released' ? 'Cheque released' : (in_array($r['status'], ['open', 'awarded'], true) && $sop ? 'Waiting for the cheque' : '');
    $short = trim((string)$r['entity_short']) !== '' ? (string)$r['entity_short'] : (string)preg_replace('/^(barangay|brgy\.?)\s+/i', '', (string)$r['entity_name']);
    return [
        'check_date' => (string)$r['cheque_date'], 'code' => (string)($r['txn_form_no'] ?: $r['project_no']), 'barangay' => $r['entity_type'] === 'barangay' ? $short : (string)$r['entity_name'],
        'title' => (string)$r['title'], 'gross' => (float)$r['gross'], 'cheque' => $r['cheque_amount'] !== null ? (float)$r['cheque_amount'] : null,
        'supplier' => (string)(($r['cheque_tradename'] ?? '') ?: ($r['tradename'] ?: $tn0)), 'tax' => $tax, 'si' => (string)$r['si_nos'], 'cr' => (string)$r['cr_nos'], 'dr' => (string)$r['dr_nos'],
        'check_no' => (string)$r['cheque_no'], 'completed' => $completed, 'status' => $status, 'pay' => $pay,
        'process' => ['svp' => 'Small value procurement', 'bidding' => 'Bidding', 'general' => 'Twelve phases'][$r['process']] ?? $r['process'],
    ];
};
$pct = fn(?float $v) => $v === null ? '' : rtrim(rtrim(number_format($v, 2), '0'), '.') . '%';

if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    csv_download('barangay_project_monitoring_board.csv',
        ['Check date', 'Trans. code', 'Barangay', 'Particulars', 'Process', 'Gross amount', 'Cheque amount', 'Supplier (tradename)', 'Tax %', 'Sales invoice / service invoice', 'C.R. no.', 'D.R. no.', 'Check no.', 'Process completed', 'Status', 'Payment'],
        array_map(function ($r) use ($view, $pct) {
            $v = $view($r);
            return [$v['check_date'], $v['code'], $v['barangay'], $v['title'], $v['process'], number_format($v['gross'], 2, '.', ''), $v['cheque'] === null ? '' : number_format($v['cheque'], 2, '.', ''),
                $v['supplier'], $pct($v['tax']), $v['si'], $v['cr'], $v['dr'], $v['check_no'], $v['completed'], $v['status'][0], $v['pay']];
        }, $rows));
}

$views = array_map($view, $rows);
$totGross = array_sum(array_column($views, 'gross'));
$totCheque = array_sum(array_map(fn($v) => (float)$v['cheque'], $views));
$waiting = count(array_filter($rows, fn($r) => in_array($r['status'], ['open', 'awarded'], true) && in_array($r['process'], ['svp', 'bidding'], true) && $r['payment_status'] !== 'released'));
$doneN = count(array_filter($views, fn($v) => $v['status'][0] === 'Process completed' || $v['status'][0] === 'Closed'));
$active = count(array_filter($f, fn($v, $k) => !in_array($k, ['process', 'status'], true) && $v !== null && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH)) + ($f['status'] !== 'all' ? 1 : 0);
$qs = fn(array $over) => url($pageKey, array_filter(array_merge($f, $over), fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 'all'));

layout_start('Barangay Project Monitoring Board', brgy_layout(['actions' => (Access::can($pageKey, 'print') ? btn_link($qs(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download')
    . ' <button type="button" class="btn btn-outline-secondary btn-sm" data-print-board><i class="bi bi-printer"></i> Print</button>' : '')]));
?>
<div class="board-proc" role="tablist" aria-label="Process">
  <?php foreach (['' => 'All processes', 'svp' => 'Small value procurement', 'bidding' => 'Bidding', 'general' => 'Twelve phases'] as $k => $lbl): ?>
    <a role="tab" aria-selected="<?= $f['process'] === $k ? 'true' : 'false' ?>" class="board-proc-tab<?= $f['process'] === $k ? ' is-on' : '' ?>" href="<?= h($qs(['process' => $k])) ?>"><?= h($lbl) ?></a>
  <?php endforeach; ?>
</div>

<details class="card mb-3 board-filters" <?= $active ? 'open' : '' ?>>
  <summary class="card-header d-flex align-items-center gap-2"><i class="bi bi-funnel"></i><span class="me-auto">Filters</span><?= $active ? '<span class="small text-primary">' . $active . ' in use</span>' : '' ?></summary>
  <form method="get" class="card-body"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><?php if ($f['process'] !== ''): ?><input type="hidden" name="process" value="<?= h($f['process']) ?>"><?php endif; ?>
    <div class="row g-2 align-items-end">
      <div class="col-sm-6 col-lg-3"><label class="form-label" for="bSearch">Search</label><input class="form-control form-control-sm" type="search" id="bSearch" name="q" value="<?= h($f['q']) ?>" placeholder="Particulars, trans. code, check no."></div>
      <?= select_field('entity_id', 'Barangay', Brgy::entities(false), $f['entity_id'], ['blank' => 'All barangays', 'class' => 'col-sm-6 col-lg-3']) ?>
      <?= select_field('tradename', 'Supplier (tradename)', array_combine(array_values(Brgy::tradenames(false)), array_values(Brgy::tradenames(false))), $f['tradename'], ['blank' => 'All suppliers', 'class' => 'col-sm-6 col-lg-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('status', 'Status', $STATUS, $f['status'], ['class' => 'col-sm-6 col-lg-3', 'extra' => 'data-no-combo']) ?>
      <div class="col-sm-6 col-lg-3"><span class="form-label d-block" id="bGrossLab">Gross amount</span><div class="input-group input-group-sm" role="group" aria-labelledby="bGrossLab">
        <input class="form-control" name="gross_min" inputmode="decimal" placeholder="From" aria-label="Gross amount from" value="<?= h($f['gross_min'] === null ? '' : (string)$f['gross_min']) ?>">
        <input class="form-control" name="gross_max" inputmode="decimal" placeholder="To" aria-label="Gross amount to" value="<?= h($f['gross_max'] === null ? '' : (string)$f['gross_max']) ?>"></div></div>
      <div class="col-sm-6 col-lg-3"><span class="form-label d-block" id="bChqLab">Cheque amount</span><div class="input-group input-group-sm" role="group" aria-labelledby="bChqLab">
        <input class="form-control" name="cheque_min" inputmode="decimal" placeholder="From" aria-label="Cheque amount from" value="<?= h($f['cheque_min'] === null ? '' : (string)$f['cheque_min']) ?>">
        <input class="form-control" name="cheque_max" inputmode="decimal" placeholder="To" aria-label="Cheque amount to" value="<?= h($f['cheque_max'] === null ? '' : (string)$f['cheque_max']) ?>"></div></div>
      <div class="col-4 col-lg-1"><label class="form-label" for="bCr">C.R. no.</label><input class="form-control form-control-sm" id="bCr" name="cr" value="<?= h($f['cr']) ?>"></div>
      <div class="col-4 col-lg-1"><label class="form-label" for="bDr">D.R. no.</label><input class="form-control form-control-sm" id="bDr" name="dr" value="<?= h($f['dr']) ?>"></div>
      <div class="col-4 col-lg-1"><label class="form-label" for="bSi">Invoice no.</label><input class="form-control form-control-sm" id="bSi" name="si" value="<?= h($f['si']) ?>" title="Sales invoice / service invoice no."></div>
      <div class="col-12 col-lg-3 d-flex gap-2"><button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Show</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey, $f['process'] !== '' ? ['process' => $f['process']] : [])) ?>">Clear</a></div>
    </div>
  </form>
</details>

<?= brgy_figures([
    'Projects' => [(string)count($rows), '', $f['process'] !== '' ? h(['svp' => 'Small value procurement', 'bidding' => 'Bidding', 'general' => 'Twelve phases'][$f['process']]) : 'every process'],
    'Gross amount' => [money($totGross), '', 'total of the projects listed'],
    'Cheque amount' => [money($totCheque), $totCheque > 0 ? 'good' : '', 'cheques released'],
    'Waiting for the cheque' => [(string)$waiting, $waiting ? 'warn' : '', $doneN . ' completed or closed'],
]) ?>

<div class="card board-card"><div class="table-responsive board-scroll"><table class="table table-sm table-hover mb-0 align-middle board-table" id="boardTable">
  <thead><tr>
    <th scope="col">Check date</th><th scope="col">Trans. code</th><th scope="col">Barangay</th><th scope="col" class="b-title">Particulars</th>
    <th scope="col" class="num">Gross amount</th><th scope="col" class="num">Cheque amount</th><th scope="col">Supplier</th><th scope="col" class="num">Tax %</th>
    <th scope="col">Sales / service inv.</th><th scope="col">C.R.</th><th scope="col">D.R.</th><th scope="col">Check no.</th><th scope="col">Process completed</th><th scope="col">Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): $v = $views[$i]; ?>
    <tr>
      <td data-label="Check date" class="text-nowrap"><?= $v['check_date'] ? h(fdate($v['check_date'])) : '<span class="text-muted">none yet</span>' ?></td>
      <td data-label="Trans. code" class="text-nowrap"><a href="<?= h(url('brgy.projects', ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= h($v['code']) ?></b></a></td>
      <td data-label="Barangay"><?= h($v['barangay']) ?></td>
      <td data-label="Particulars" class="b-title"><?= h($v['title']) ?><div class="small text-muted"><?= h($v['process']) ?></div></td>
      <td data-label="Gross amount" class="num"><?= money($v['gross']) ?></td>
      <td data-label="Cheque amount" class="num"><?= $v['cheque'] === null ? '' : money($v['cheque']) ?></td>
      <td data-label="Supplier"><?= h($v['supplier']) ?></td>
      <td data-label="Tax %" class="num"><?= h($pct($v['tax'])) ?></td>
      <td data-label="Sales / service inv."><?= $v['si'] !== '' ? h($v['si']) : '<span class="text-muted">none</span>' ?></td>
      <td data-label="C.R."><?= $v['cr'] !== '' ? h($v['cr']) : '<span class="text-muted">none</span>' ?></td>
      <td data-label="D.R."><?= $v['dr'] !== '' ? h($v['dr']) : '<span class="text-muted">none</span>' ?></td>
      <td data-label="Check no."><?= h($v['check_no']) ?></td>
      <td data-label="Process completed" class="text-nowrap"><?= $v['completed'] ? h(fdate($v['completed'])) : '' ?></td>
      <td data-label="Status" class="b-status"><span class="st st-<?= h($v['status'][1]) ?>"><?= h($v['status'][0]) ?></span><?= $v['pay'] !== '' ? '<div class="small text-muted">' . h($v['pay']) . '</div>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="14" class="text-muted py-4 text-center">No project for these filters.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($rows): ?><tfoot><tr><td colspan="4" class="text-end fw-semibold">Total (<?= count($rows) ?>)</td><td class="num fw-semibold"><?= money($totGross) ?></td><td class="num fw-semibold"><?= money($totCheque) ?></td><td colspan="8"></td></tr></tfoot><?php endif; ?>
</table></div></div>
<p class="small text-muted mt-2 mb-0">Gross amount: the contract once awarded, else the gross of the transaction form (or the bid, or the ABC). Tax %: the withholding tax rate of the transaction form, else the VAT and EWT of the DV. Supplier: the tradename of the transaction (the one the cheque is payable to once released). Process completed: the day the last step of the SOP was done, or the closeout.</p>
<script>
(function () {
  const b = document.querySelector('[data-print-board]');
  if (b) b.addEventListener('click', () => window.print());
})();
</script>
<?php
layout_end();
