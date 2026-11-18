<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Contracting Dashboard
 * Where every contract stands: what needs attention, the pipeline by phase, the next dates, and the money still to come.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.dashboard';
$costs = Access::viewCosts();
$alerts = Brgy::alerts();
$queue = Brgy::queue();
$returned = Brgy::returnedToMe();

// ------------------------------------------------------------------ figures
$open = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(abc_amount), 0) AS abc,
                        SUM(CASE WHEN bid_deadline IS NOT NULL AND bid_deadline BETWEEN NOW() AND :d THEN 1 ELSE 0 END) AS due
                   FROM brgy_projects WHERE status = 'open' AND phase IN ('intake', 'eligibility', 'costing', 'approval')", ['d' => date('Y-m-d H:i:s', strtotime('+7 days'))]);
$waiting = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(bid_amount), 0) AS amt FROM brgy_projects WHERE status = 'open' AND phase IN ('submitted', 'evaluation')");
$live = DB::all("SELECT * FROM brgy_projects WHERE status = 'awarded'");
$tot = ['contract' => 0.0, 'to_deliver' => 0.0, 'unbilled' => 0.0, 'ar' => 0.0, 'retention' => 0.0, 'margin' => 0.0, 'revenue' => 0.0];
foreach ($live as $p) {
    $m = Brgy::money($p);
    $tot['contract'] += $m['contract'];
    $tot['to_deliver'] += $m['to_deliver'];
    $tot['unbilled'] += $m['unbilled_accepted'];
    $tot['ar'] += $m['ar_open'];
    $tot['retention'] += $m['retention_held'];
    $tot['margin'] += $m['margin'];
    $tot['revenue'] += $m['revenue'];
}
$since = date('Y-m-d', strtotime('-12 months'));
$wl = DB::row("SELECT SUM(CASE WHEN status IN ('awarded', 'closed') THEN 1 ELSE 0 END) AS won, SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost
                 FROM brgy_projects WHERE bid_submitted_at >= :s", ['s' => $since]);
$decided = (int)$wl['won'] + (int)$wl['lost'];

$board = BrgySop::board();
$actions = page_link('brgy.projects', ['a' => 'new'], 'New transaction', 'btn-primary', 'plus-lg', 'create') . ' '
    . (Access::page('brgy.approvals') ? btn_link(url('brgy.approvals'), 'Approval queue' . ($queue ? ' (' . count($queue) . ')' : ''), 'btn-outline-secondary', 'hourglass-split') : '');
layout_start('Contracting Dashboard', brgy_layout(['actions' => $actions]));

$figs = [
    'Opportunities in preparation' => [(string)(int)$open['n'], 'info', (int)$open['due'] ? (int)$open['due'] . ' bid(s) due within 7 days' : 'ABC ' . money($open['abc'])],
    'Bids waiting for the result' => [(string)(int)$waiting['n'], '', money($waiting['amt'])],
    'Contracts in force' => [money($tot['contract']), 'ok', count($live) . ' contract(s)'],
    'Still to be accepted' => [money($tot['to_deliver']), $tot['to_deliver'] > 0 ? 'warn' : ''],
    'Accepted, not billed' => [money($tot['unbilled']), $tot['unbilled'] > 0 ? 'warn' : ''],
    'Receivable (ledger)' => [money($tot['ar']), $tot['ar'] > 0 ? 'info' : '', 'retention held ' . money($tot['retention'])],
    'Win rate, 12 months' => [$decided ? round((int)$wl['won'] / $decided * 100) . '%' : 'n/a', '', (int)$wl['won'] . ' won, ' . (int)$wl['lost'] . ' lost'],
];
if ($costs) {
    $figs['Margin on live contracts'] = [money($tot['margin']), $tot['margin'] < 0 ? 'bad' : 'ok', $tot['revenue'] > 0 ? round($tot['margin'] / $tot['revenue'] * 100, 1) . '% of revenue so far' : 'from the ledger'];
}
echo brgy_figures($figs);
if ($board):
    $waitPay = array_filter($board, fn($b) => $b['status'] === 'awarded' && $b['payment_status'] !== 'released');
    $unreturned = array_filter($board, fn($b) => $b['released_at'] && !$b['transmittal_returned_at']);
    $groups = [];
    foreach ($board as $b) {
        $groups[$b['process']][$b['sop_step'] ?: '_done'][] = $b;
    } ?>
<div class="card mb-3"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span><i class="bi bi-signpost-split"></i> Transactions on the SOP</span>
  <span class="small text-muted ms-auto"><?= count($board) ?> open, <?= count($waitPay) ?> waiting for payment, <?= count($unreturned) ?> transmittal(s) not returned</span>
  <?= Access::page('rpt.brgy_monitoring') ? btn_link(url('rpt.brgy_monitoring'), 'Monitoring', 'btn-outline-secondary', 'table') : '' ?></div>
  <div class="card-body">
    <?php foreach (['svp' => 'Small value procurement', 'bidding' => 'Bidding'] as $proc => $plbl): if (empty($groups[$proc])) { continue; } ?>
      <div class="sop-board-head"><?= h($plbl) ?></div>
      <div class="sop-board">
        <?php foreach (BrgySop::steps($proc) as $st): $items = $groups[$proc][$st['code']] ?? []; if (!$items) { continue; } ?>
          <div class="sb-col"><div class="sb-title"><span class="sb-no"><?= (int)$st['step_no'] ?></span> <?= h($st['title']) ?> <span class="sb-n"><?= count($items) ?></span></div>
            <?php foreach ($items as $b): ?><a class="sb-card" href="<?= h(url('brgy.projects', ['a' => 'view', 'id' => $b['id'], 'tab' => 'process'])) ?>#step-<?= h($st['code']) ?>">
              <span class="sb-card-no"><?= h($b['txn_form_no'] ?: $b['project_no']) ?></span><span class="sb-card-title"><?= h(mb_strimwidth($b['title'], 0, 60, '...')) ?></span>
              <span class="sb-card-meta"><?= h($b['entity_name']) ?>, <?= money((float)$b['contract_amount'] ?: (float)$b['bid_amount'] ?: (float)$b['abc_amount']) ?><?= $b['status'] === 'awarded' && $b['payment_status'] !== 'released' ? ', <span class="text-warning-emphasis">waiting for payment</span>' : '' ?></span></a><?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-bell"></i> Needs attention</span><span class="small text-muted"><?= count($alerts) ?></span></div>
      <div class="card-body py-2 alert-list">
        <?php foreach (array_slice($alerts, 0, 40) as $x): ?>
          <div class="alert-line al-<?= h($x['level']) ?>"><i class="bi bi-<?= h($x['icon']) ?>"></i> <a href="<?= h($x['url']) ?>"><?= h($x['text']) ?></a></div>
        <?php endforeach; ?>
        <?php if (count($alerts) > 40): ?><div class="small text-muted mt-1"><?= count($alerts) - 40 ?> more. The reports Document Expiry and Missing Papers and A/R Aging by Barangay list everything.</div><?php endif; ?>
        <?php if (!$alerts): ?><div class="text-muted py-2">Nothing needs attention: no paper is expiring, no bid is due, no approval is waiting.</div><?php endif; ?>
      </div></div>

    <?php
    // pipeline by phase
    $byPhase = DB::pairs("SELECT phase, COUNT(*) FROM brgy_projects WHERE status IN ('open', 'awarded') GROUP BY phase");
    $amtPhase = DB::pairs("SELECT phase, SUM(CASE WHEN status = 'awarded' THEN contract_amount + co_amount WHEN bid_amount > 0 THEN bid_amount ELSE abc_amount END) FROM brgy_projects WHERE status IN ('open', 'awarded') GROUP BY phase");
    $maxN = max(1, ...array_map('intval', array_values($byPhase) ?: [1]));
    ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-bar-chart-steps"></i> Pipeline by phase</div><div class="card-body">
      <div class="pipe">
        <?php foreach (Brgy::PHASES as $code => [$num, $name]): if ($code === 'closeout') { continue; } $n = (int)($byPhase[$code] ?? 0); ?>
          <a class="pipe-row" href="<?= h(url('brgy.projects', ['phase' => $code])) ?>">
            <span class="pipe-name"><span class="text-muted"><?= h($num) ?></span> <?= h($name) ?></span>
            <span class="pipe-bar"><span style="width:<?= $n ? max(4, round($n / $maxN * 100)) : 0 ?>%"></span></span>
            <span class="pipe-n"><?= $n ?: '' ?></span><span class="pipe-amt"><?= $n ? money($amtPhase[$code] ?? 0) : '' ?></span></a>
        <?php endforeach; ?>
      </div>
    </div></div>
  </div>

  <div class="col-xl-5">
    <?php if ($queue || $returned): ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-hourglass-split"></i> Your approvals</div><div class="list-group list-group-flush">
      <?php foreach (array_slice($queue, 0, 8) as $q):
          $o = $q['obj'];
          [$what, $link] = match ($q['type']) {
              'bid' => ['Bid ' . $o['project_no'] . ', ' . money($o['bid_amount']), url('brgy.projects', ['a' => 'view', 'id' => $o['id'], 'tab' => 'lines'])],
              'billing' => ['Billing ' . $o['billing_no'] . ', ' . money($o['gross_amount']), url('brgy.billing', ['a' => 'view', 'id' => $o['id']])],
              'change_order' => ['Change order ' . $o['co_no'] . ', ' . money($o['amount_change']), url('brgy.projects', ['a' => 'view', 'id' => $o['project_id'], 'tab' => 'changes'])],
              default => ['Form ' . $o['form_no'] . ', ' . $o['title'], url('brgy.forms', ['a' => 'view', 'id' => $o['id']])],
          }; ?>
        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= h($link) ?>"><span class="wrap-link"><?= h($what) ?></span><span class="small text-nowrap"><?= Brgy::st($q['action'] === 'review' ? 'for_review' : 'reviewed', $q['action'] === 'review' ? 'to review' : 'to approve') ?></span></a>
      <?php endforeach; ?>
      <?php foreach ($returned as $r): $o = $r['obj']; ?>
        <a class="list-group-item list-group-item-action" href="<?= h(url('brgy.approvals', ['tab' => 'returned'])) ?>"><span class="st st-bad">Returned</span> <?= h(ucfirst(str_replace('_', ' ', $r['type'])) . ' ' . ($o['project_no'] ?? $o['billing_no'] ?? $o['co_no'] ?? $o['form_no'] ?? '')) ?>
          <?= $r['why'] ? '<div class="small text-muted">' . h($r['why']) . '</div>' : '' ?></a>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <?php
    // the next 30 days
    $to = date('Y-m-d 23:59:59', strtotime('+30 days'));
    $cal = [];
    foreach (DB::all("SELECT id, project_no, title, bid_deadline, prebid_at, clarification_deadline, site_inspection_at, bid_opening_at FROM brgy_projects WHERE status = 'open'") as $p) {
        foreach (['bid_deadline' => 'Bid deadline', 'prebid_at' => 'Pre-bid conference', 'clarification_deadline' => 'Last day for questions', 'site_inspection_at' => 'Site inspection', 'bid_opening_at' => 'Bid opening'] as $k => $lbl) {
            if ($p[$k] && $p[$k] >= date('Y-m-d 00:00:00') && $p[$k] <= $to) {
                $cal[] = [$p[$k], $lbl, $p['project_no'] . ' ' . $p['title'], url('brgy.projects', ['a' => 'view', 'id' => $p['id']]), $k === 'bid_deadline'];
            }
        }
    }
    foreach (DB::all("SELECT id, project_no, title, due_date FROM brgy_projects WHERE status = 'awarded' AND due_date BETWEEN :a AND :b", ['a' => today(), 'b' => substr($to, 0, 10)]) as $p) {
        $cal[] = [$p['due_date'], 'Delivery / completion due', $p['project_no'] . ' ' . $p['title'], url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'delivery']), true];
    }
    foreach (DB::all("SELECT e.*, p.project_no FROM brgy_events e JOIN brgy_projects p ON p.id = e.project_id WHERE e.status = 'open' AND e.due_at BETWEEN :a AND :b", ['a' => date('Y-m-d 00:00:00'), 'b' => $to]) as $e) {
        $cal[] = [$e['due_at'], 'Reply due', $e['title'] . ' (' . $e['project_no'] . ')', url('brgy.projects', ['a' => 'view', 'id' => $e['project_id'], 'tab' => 'submissions']), false];
    }
    foreach (DB::all("SELECT t.*, p.project_no FROM brgy_tasks t JOIN brgy_projects p ON p.id = t.project_id WHERE t.status IN ('open', 'in_progress') AND t.due_date BETWEEN :a AND :b", ['a' => today(), 'b' => substr($to, 0, 10)]) as $t) {
        $cal[] = [$t['due_date'], Brgy::label(Brgy::TASK_TYPES, $t['task_type']), $t['title'] . ' (' . $t['project_no'] . ')', url('brgy.projects', ['a' => 'view', 'id' => $t['project_id'], 'tab' => 'tasks']), false];
    }
    usort($cal, fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
    ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-calendar-week"></i> Next 30 days</div><div class="card-body py-2">
      <ul class="cal-list">
        <?php foreach (array_slice($cal, 0, 18) as [$at, $lbl, $txt, $link, $strong]): ?>
          <li><span class="cal-date"><span><?= h(date('M', strtotime((string)$at))) ?></span><b><?= h(date('j', strtotime((string)$at))) ?></b></span>
            <span class="cal-text"><span class="<?= $strong ? 'fw-semibold' : '' ?>"><?= h($lbl) ?></span><?= strlen((string)$at) > 10 && substr((string)$at, 11, 5) !== '00:00' ? ' <span class="text-muted">' . h(date('g:i A', strtotime((string)$at))) . '</span>' : '' ?>
              <a class="d-block small" href="<?= h($link) ?>"><?= h(mb_strimwidth($txt, 0, 90, '...')) ?></a></span></li>
        <?php endforeach; ?>
        <?php if (!$cal): ?><li class="text-muted">No dates in the next 30 days.</li><?php endif; ?>
      </ul>
    </div></div>

    <?php
    $byEntity = DB::all("SELECT e.id, e.official_name, COUNT(p.id) AS n, SUM(p.status = 'awarded') AS live FROM brgy_entities e JOIN brgy_projects p ON p.entity_id = e.id
                          WHERE p.status IN ('open', 'awarded') GROUP BY e.id, e.official_name ORDER BY live DESC, n DESC LIMIT 6");
    ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-bank"></i> Procuring entities</div><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Entity</th><th class="num">Open</th><th class="num">Contracts</th><th class="num">Receivable</th></tr></thead>
      <?php foreach ($byEntity as $e):
          $jobs = DB::column("SELECT job_customer_id FROM brgy_projects WHERE entity_id = :e AND job_customer_id IS NOT NULL", ['e' => $e['id']]);
          $ar = 0.0;
          if ($jobs) {
              [$jin, $jp] = DB::in('j', array_map('intval', $jobs));
              [$ain, $ap] = DB::in('a', Accts::arIds() ?: [0]);
              $ar = (float)DB::value("SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE customer_id IN $jin AND account_id IN $ain", $jp + $ap);
          } ?>
        <tr><td class="small wrap-link"><?= safe_link('brgy.entities', ['a' => 'view', 'id' => $e['id']], $e['official_name']) ?></td><td class="num"><?= (int)$e['n'] - (int)$e['live'] ?></td><td class="num"><?= (int)$e['live'] ?></td><td class="num"><?= money($ar, true) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$byEntity): ?><tr><td colspan="4" class="text-muted">No open projects.</td></tr><?php endif; ?>
    </table></div></div>
  </div>
</div>

<div class="card"><div class="card-header"><i class="bi bi-briefcase"></i> Contracts in force</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Contract</th><th>Due</th><th style="width:16%">Accepted</th><th class="num">Contract</th><th class="num">Billed</th><th class="num">Collected</th><th class="num">Receivable</th></tr></thead>
  <?php foreach ($live as $p): $p = Brgy::project((int)$p['id']); $m = Brgy::money($p); $pa = $m['contract'] > 0 ? min(100, $m['accepted'] / $m['contract'] * 100) : 0;
      $late = $p['due_date'] && $p['due_date'] < today() && $m['to_deliver'] > 0; ?>
    <tr><td class="wrap-link"><a href="<?= h(url('brgy.projects', ['a' => 'view', 'id' => $p['id']])) ?>"><b><?= h($p['project_no']) ?></b></a> <span class="small"><?= h(mb_strimwidth($p['title'], 0, 70, '...')) ?></span><div class="small text-muted"><?= h($p['entity_name']) ?>, <?= h(Brgy::phaseLabel($p['phase'])) ?></div></td>
      <td class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($p['due_date'])) ?></td>
      <td><div class="prog"><span style="width:<?= round($pa) ?>%"></span></div><div class="small text-muted"><?= round($pa) ?>%</div></td>
      <td class="num"><?= money($m['contract']) ?></td><td class="num"><?= money($m['billed'], true) ?></td><td class="num"><?= money($m['cash'], true) ?></td><td class="num"><?= money($m['ar_open'], true) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$live): ?><tr><td colspan="7" class="text-muted py-3">No awarded contracts in force.</td></tr><?php endif; ?>
</table></div></div>
<?php
layout_end();
