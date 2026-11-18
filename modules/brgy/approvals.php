<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Approval Queue
 * What waits for the user's review or approval (bids, billing packs, change orders, forms), what came back to them,
 * the alternates who act while a checker or approver is away, and the decisions taken.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.approvals';
$tab = (string)g('tab', 'queue');

if (is_post()) {
    $do = (string)p('do');
    $r = trim((string)p('remarks'));
    brgy_action(function () use ($do, $r) {
        if (preg_match('/^(bid|billing|co|form)_(review|approve|return|reopen)$/', $do, $mm)) {
            $id = (int)pint('obj_id');
            match ($mm[1]) {
                'bid' => BrgyOps::bidStep($id, $mm[2], $r),
                'billing' => BrgyOps::billingStep($id, $mm[2], $r),
                'co' => BrgyOps::changeOrderStep($id, $mm[2], $r),
                'form' => BrgyOps::formStep($id, $mm[2], $r),
            };
            return ['review' => 'Marked reviewed.', 'approve' => 'Approved.', 'return' => 'Returned to the preparer.', 'reopen' => 'Reopened.'][$mm[2]];
        }
        if ($do === 'delegate') {
            $from = Access::isAdmin() && pint('from_user_id') ? (int)pint('from_user_id') : (int)Auth::id();
            $to = (int)pint('to_user_id');
            $scope = in_array(p('scope'), ['review', 'approve', 'both'], true) ? (string)p('scope') : 'both';
            $vf = pdate('valid_from');
            $vt = pdate('valid_to');
            if (!Access::can('brgy.approvals', 'edit') && !Access::can('brgy.approvals', 'approve', $from)) {
                fail('Only a checker or an approver names an alternate.');
            }
            if (!$to || $to === $from) {
                fail('Choose the alternate: another user.');
            }
            if (!$vf || !$vt || $vt < $vf) {
                fail('Enter the period: from and to (the end on or after the start).');
            }
            if (!Access::can('brgy.approvals', 'view', $to)) {
                fail(Brgy::userName($to) . ' cannot open the Approval Queue; give their role the page first.');
            }
            $id = DB::insert('brgy_delegations', ['from_user_id' => $from, 'to_user_id' => $to, 'scope' => $scope, 'valid_from' => $vf, 'valid_to' => $vt,
                'reason' => $r !== '' ? mb_substr($r, 0, 255) : null, 'is_active' => 1, 'created_by' => Auth::id()]);
            Brgy::log('delegate', 'delegation', $id, Brgy::userName($from) . ' to ' . Brgy::userName($to), ['reason' => $scope . ', ' . fdate($vf) . ' to ' . fdate($vt) . ($r !== '' ? ': ' . $r : ''), 'page_key' => 'brgy.approvals']);
            return Brgy::userName($to) . ' acts for ' . Brgy::userName($from) . ' from ' . fdate($vf) . ' to ' . fdate($vt) . '. Their steps are recorded as done for ' . Brgy::userName($from) . '.';
        }
        if ($do === 'delegate_end') {
            $d = DB::row('SELECT * FROM brgy_delegations WHERE id = :id', ['id' => (int)pint('delegation_id')]);
            if (!$d || ((int)$d['from_user_id'] !== (int)Auth::id() && (int)$d['created_by'] !== (int)Auth::id() && !Access::isAdmin())) {
                fail('Only the person who handed over the authority (or an administrator) ends it.');
            }
            DB::update('brgy_delegations', ['is_active' => 0, 'valid_to' => min((string)$d['valid_to'], today())], 'id = :id', ['id' => $d['id']]);
            Brgy::log('delegate_end', 'delegation', (int)$d['id'], Brgy::userName((int)$d['from_user_id']) . ' to ' . Brgy::userName((int)$d['to_user_id']), ['page_key' => 'brgy.approvals']);
            return 'The alternate no longer acts.';
        }
        fail('Unknown action.');
    }, url($pageKey, ['tab' => $tab]));
}

$queue = Brgy::queue();
$returned = Brgy::returnedToMe();
layout_start('Approval Queue', brgy_layout());
$tabs = ['queue' => 'Waiting for me', 'returned' => 'Returned to me', 'all' => 'Everything in approval', 'delegations' => 'Alternates', 'log' => 'Decisions'];
$counts = ['queue' => count($queue), 'returned' => count($returned)];
?>
<ul class="nav nav-tabs mb-0">
  <?php foreach ($tabs as $k => $lbl): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['tab' => $k])) ?>"><?= h($lbl) ?><?= !empty($counts[$k]) ? ' <span class="tab-count' . ($k === 'returned' ? ' is-bad' : '') . '">' . (int)$counts[$k] . '</span>' : '' ?></a></li><?php endforeach; ?>
</ul>
<div class="tab-body">
<?php
/** One row of the queue: what it is, where it belongs, the amount, who prepared it, and its buttons */
$describe = function (string $type, array $o): array {
    return match ($type) {
        'bid' => ['Bid', $o['project_no'] . ' ' . $o['title'], (float)$o['bid_amount'], url('brgy.projects', ['a' => 'view', 'id' => $o['id'], 'tab' => 'lines']), 'bid'],
        'billing' => ['Billing pack', $o['billing_no'] . ' (' . ($o['project_no'] ?? '') . ')', (float)$o['gross_amount'], url('brgy.billing', ['a' => 'view', 'id' => $o['id']]), 'billing'],
        'change_order' => ['Change order', $o['co_no'] . ' ' . $o['title'] . ' (' . ($o['project_no'] ?? '') . ')', (float)$o['amount_change'], url('brgy.projects', ['a' => 'view', 'id' => $o['project_id'], 'tab' => 'changes']), 'co'],
        default => ['Form', $o['form_no'] . ' ' . $o['title'], null, url('brgy.forms', ['a' => 'view', 'id' => $o['id']]), 'form'],
    };
};

if ($tab === 'queue' || $tab === 'all'):
    $rows = $queue;
    if ($tab === 'all') {
        $rows = [];
        foreach (DB::all("SELECT p.*, e.official_name AS entity_name FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id WHERE p.bid_status IN ('for_review', 'reviewed')") as $o) {
            $rows[] = ['type' => 'bid', 'obj' => $o, 'project' => $o];
        }
        foreach (DB::all("SELECT b.*, p.project_no FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.status IN ('for_review', 'reviewed')") as $o) {
            $rows[] = ['type' => 'billing', 'obj' => $o, 'project' => Brgy::project((int)$o['project_id'])];
        }
        foreach (DB::all("SELECT c.*, p.project_no FROM brgy_change_orders c JOIN brgy_projects p ON p.id = c.project_id WHERE c.status IN ('for_review', 'reviewed')") as $o) {
            $rows[] = ['type' => 'change_order', 'obj' => $o, 'project' => Brgy::project((int)$o['project_id'])];
        }
        foreach (DB::all("SELECT f.*, p.project_no FROM brgy_forms f LEFT JOIN brgy_projects p ON p.id = f.project_id WHERE f.status IN ('for_review', 'reviewed')") as $o) {
            $rows[] = ['type' => 'form', 'obj' => $o, 'project' => $o['project_id'] ? Brgy::project((int)$o['project_id']) : null];
        }
    }
    ?>
    <?php if ($tab === 'queue'): ?><p class="small text-muted">Records waiting for your review or approval: as the named checker or approver of the project, as an alternate, or by your role. Nothing you prepared or reviewed yourself shows here.</p><?php endif; ?>
    <div class="queue-list">
    <?php foreach ($rows as $q):
        [$kind, $title, $amount, $link, $prefix] = $describe($q['type'], $q['obj']);
        $o = $q['obj'];
        $col = $q['type'] === 'bid' ? 'bid_status' : 'status';
        $when = DB::value("SELECT created_at FROM brgy_workflow WHERE object_type = :t AND object_id = :id AND action = 'submit' ORDER BY id DESC LIMIT 1", ['t' => $q['type'], 'id' => $o['id']]); ?>
      <div class="card queue-item mb-2"><div class="card-body py-2">
        <div class="qi-head">
          <div class="wrap-link"><span class="text-muted small"><?= h($kind) ?></span> <a href="<?= h($link) ?>"><b><?= h($title) ?></b></a>
            <div class="small text-muted"><?= $q['project'] ? h($q['project']['entity_name'] ?? '') : '' ?><?= $when ? ', sent ' . h(fdatetime($when)) . ' (' . h(brgy_rel($when)) . ')' : '' ?></div></div>
          <div class="text-end"><?= $amount !== null ? '<div class="fw-semibold num">' . money($amount) . '</div>' : '' ?><?= Brgy::st($o[$col]) ?></div>
        </div>
        <?php if ($q['type'] === 'bid'): $p = Brgy::project((int)$o['id']); $cl = Brgy::checklistSummary((int)$o['id']); ?>
          <div class="small mt-1">ABC <?= money($p['abc_amount']) ?><?= Access::viewCosts() && (float)$p['bid_amount'] > 0 ? ', estimated cost ' . money($p['est_cost']) . ' (margin ' . round(((float)$p['bid_amount'] - (float)$p['est_cost']) / (float)$p['bid_amount'] * 100, 1) . '%)' : '' ?>,
            bid papers <?= $cl['ok'] + $cl['waived'] ?> of <?= $cl['total'] - $cl['later'] ?> in order<?= $cl['blocking'] ? ', <span class="text-danger">' . $cl['blocking'] . ' mandatory not in order</span>' : '' ?>, deadline <?= h(fdatetime($p['bid_deadline'])) ?>.</div>
        <?php elseif ($q['type'] === 'billing'): $pack = BrgyOps::packStatus($o); $miss = array_filter($pack, fn($x) => $x['required'] && !$x['ok']); ?>
          <div class="small mt-1">Amount <?= money($o['net_amount']) ?> + VAT <?= money($o['vat_amount']) ?>; expected deductions: income tax <?= money($o['ewt_expected']) ?>, VAT withheld <?= money($o['vatwh_expected']) ?>, retention <?= money($o['retention_expected']) ?>.
            <?= $miss ? '<span class="text-warning-emphasis">Pack still missing: ' . h(implode(', ', array_column($miss, 'name'))) . '.</span>' : 'Pack complete.' ?></div>
        <?php endif; ?>
        <div class="mt-2"><?= brgy_step_buttons($q['type'], $o, $q['project'], url($pageKey, ['tab' => $tab]), $prefix, ['obj_id' => $q['type'] === 'bid' ? $o['id'] : $o['id'], 'tab' => $tab]) ?></div>
      </div></div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="card"><div class="card-body text-muted"><?= $tab === 'queue' ? 'Nothing waits for you.' : 'Nothing is in approval.' ?></div></div><?php endif; ?>
    </div>
<?php
elseif ($tab === 'returned'): ?>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Record</th><th>Why it came back</th><th></th></tr></thead>
      <?php foreach ($returned as $r): [$kind, $title, $amount, $link] = $describe($r['type'], $r['obj'] + ['project_no' => $r['obj']['project_no'] ?? (string)DB::value('SELECT project_no FROM brgy_projects WHERE id = :p', ['p' => $r['obj']['project_id'] ?? 0])]); ?>
        <tr><td class="wrap-link"><span class="small text-muted"><?= h($kind) ?></span> <a href="<?= h($link) ?>"><?= h($title) ?></a></td><td class="small text-danger"><?= h($r['why']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h($link) ?>">Correct it</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$returned): ?><tr><td colspan="3" class="text-muted py-3">Nothing was returned to you.</td></tr><?php endif; ?>
    </table></div></div>
<?php
elseif ($tab === 'delegations'):
    $dels = DB::all('SELECT d.*, f.full_name AS from_name, t.full_name AS to_name FROM brgy_delegations d JOIN users f ON f.id = d.from_user_id JOIN users t ON t.id = d.to_user_id ORDER BY d.is_active DESC, d.valid_to DESC, d.id DESC');
    $canDelegate = Access::can('brgy.approvals', 'edit') || Access::can('brgy.approvals', 'approve'); ?>
    <div class="row g-3">
      <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Who is away</th><th>Alternate</th><th>Acts as</th><th>Period</th><th>Status</th><th></th></tr></thead>
        <?php foreach ($dels as $d): $now = (int)$d['is_active'] === 1 && $d['valid_from'] <= today() && $d['valid_to'] >= today(); ?>
          <tr class="<?= (int)$d['is_active'] ? '' : 'text-muted' ?>"><td><?= h($d['from_name']) ?></td><td><?= h($d['to_name']) ?></td><td class="small"><?= h(['review' => 'Checker', 'approve' => 'Approver', 'both' => 'Checker and approver'][$d['scope']] ?? $d['scope']) ?></td>
            <td class="small"><?= h(fdate($d['valid_from'])) ?> to <?= h(fdate($d['valid_to'])) ?><?= $d['reason'] ? '<div class="text-muted">' . h($d['reason']) . '</div>' : '' ?></td>
            <td><?= (int)$d['is_active'] === 0 ? Brgy::st('void', 'Ended') : ($now ? Brgy::st('ok', 'Acting now') : ($d['valid_from'] > today() ? Brgy::st('pending', 'Scheduled') : Brgy::st('void', 'Lapsed'))) ?></td>
            <td class="text-end"><?php if ((int)$d['is_active'] && ((int)$d['from_user_id'] === (int)Auth::id() || (int)$d['created_by'] === (int)Auth::id() || Access::isAdmin())): ?>
              <form method="post" action="<?= h(url($pageKey, ['tab' => $tab])) ?>" class="d-inline" data-confirm="End this delegation now?"><?= csrf_field() ?><input type="hidden" name="do" value="delegate_end"><input type="hidden" name="delegation_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-link text-danger">End</button></form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$dels): ?><tr><td colspan="6" class="text-muted py-3">No alternates named.</td></tr><?php endif; ?>
      </table></div></div></div>
      <div class="col-xl-4"><?php if ($canDelegate): ?>
        <form class="card" method="post" action="<?= h(url($pageKey, ['tab' => $tab])) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="delegate">
          <div class="card-header"><i class="bi bi-person-check"></i> Name an alternate</div><div class="card-body row g-2">
            <?php if (Access::isAdmin()): ?><?= brgy_user_select('from_user_id', 'Who is away', Auth::id(), 'col-12') ?><?php else: ?><div class="col-12 small">You hand your authority over while you are away.</div><?php endif; ?>
            <?= brgy_user_select('to_user_id', 'Alternate', null, 'col-12', '') ?>
            <?= select_field('scope', 'Acts as', ['both' => 'Checker and approver', 'review' => 'Checker only', 'approve' => 'Approver only'], 'both', ['class' => 'col-12', 'extra' => 'data-no-combo']) ?>
            <?= input_field('valid_from', 'From', today(), ['type' => 'date', 'class' => 'col-6']) ?>
            <?= input_field('valid_to', 'To', date('Y-m-d', strtotime('+7 days')), ['type' => 'date', 'class' => 'col-6']) ?>
            <div class="col-12"><label class="form-label">Reason</label><input class="form-control form-control-sm" name="remarks" maxlength="255" placeholder="e.g. on leave"></div>
            <div class="col-12 form-text">The alternate's steps are recorded as done for you; your approval limit applies, and they still cannot approve what they prepared.</div>
          </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button></div></form>
      <?php endif; ?></div>
    </div>
<?php
else:
    $from = gdate('from', date('Y-m-d', strtotime('-60 days')));
    $log = DB::all("SELECT w.*, u.full_name, p.project_no FROM brgy_workflow w LEFT JOIN users u ON u.id = w.user_id LEFT JOIN brgy_projects p ON p.id = w.project_id
                     WHERE w.created_at >= :f AND w.object_type IN ('bid', 'billing', 'change_order', 'form', 'closeout') ORDER BY w.id DESC LIMIT 300", ['f' => $from . ' 00:00:00']); ?>
    <form method="get" class="d-flex gap-2 align-items-end mb-2"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="tab" value="log">
      <div><label class="form-label">Since</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>" data-autosubmit></div>
      <?= page_link('rpt.brgy_approval_log', [], 'Approval Log report', 'btn-outline-secondary', 'printer') ?></form>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>When</th><th>Who</th><th>Record</th><th>Step</th><th class="num">Amount</th><th>Remarks</th></tr></thead>
      <?php foreach ($log as $w): ?><tr><td class="small text-nowrap"><?= h(fdatetime($w['created_at'])) ?></td><td class="small"><?= h($w['full_name']) ?><?= $w['on_behalf_of'] ? '<div class="text-muted">for ' . h(Brgy::userName((int)$w['on_behalf_of'])) . '</div>' : '' ?></td>
        <td class="small"><?= h(ucfirst(str_replace('_', ' ', $w['object_type']))) ?> <?= $w['project_no'] ? brgy_project_link(['id' => $w['project_id'], 'project_no' => $w['project_no']]) : '' ?></td>
        <td class="small"><?= h(str_replace('_', ' ', $w['action'])) ?><?= $w['to_status'] ? ' &rarr; ' . Brgy::st($w['to_status']) : '' ?></td><td class="num"><?= $w['amount'] !== null ? money($w['amount']) : '' ?></td><td class="small"><?= h($w['remarks']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$log): ?><tr><td colspan="6" class="text-muted py-3">No decisions in this period.</td></tr><?php endif; ?>
    </table></div></div>
<?php endif; ?>
</div>
<?php
layout_end();
