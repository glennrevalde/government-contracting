<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Projects & Contracts > one project, tab by tab.
 * Included by modules/brgy/projects.php with $p (the project), $pageKey and $id.
 */

$sop = BrgySop::uses($p) && BrgySop::ready();
$tab = (string)g('tab', $sop ? 'process' : 'overview');
$tabs = ($sop ? ['process' => 'Process (SOP)'] : []) + ['overview' => 'Overview', 'lines' => 'Price schedule', 'compliance' => 'Compliance', 'documents' => 'Documents', 'forms' => 'Forms', 'submissions' => 'Submissions & letters',
    'award' => 'Award & contract', 'delivery' => 'Deliveries', 'changes' => 'Change orders', 'billing' => 'Billing & collection', 'costs' => 'Costs', 'tasks' => 'Tasks', 'timeline' => 'Timeline', 'ledger' => 'Ledger'];
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
$self = fn(array $more = []) => url($pageKey, array_merge(['a' => 'view', 'id' => $id, 'tab' => $tab], $more));
$canEdit = Access::can($pageKey, 'edit');
$awarded = in_array($p['status'], ['awarded', 'closed'], true);
$costs = Access::viewCosts();
$m = Brgy::money($p);
$cl = Brgy::checklistSummary($id);
$lines = Brgy::lines($id);
$hiddenTab = '<input type="hidden" name="tab" value="' . h($tab) . '">';

$actions = ($canEdit && $p['status'] !== 'closed' ? btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
    . (Access::can('brgy.forms', 'create') ? btn_link($self(['tab' => 'forms']), 'Prepare a form', 'btn-outline-secondary', 'file-earmark-richtext') . ' ' : '')
    . btn_link(url($pageKey), 'All projects', 'btn-outline-secondary', 'list');
layout_start($p['project_no'], brgy_layout(['actions' => $actions]));
?>
<div class="card mb-3 proj-head"><div class="card-body">
  <div class="proj-head-grid">
    <div>
      <div class="proj-title"><?= h($p['title']) ?></div>
      <div class="small text-muted"><?= safe_link('brgy.entities', ['a' => 'view', 'id' => $p['entity_id']], $p['entity_name']) ?>, <?= h(Brgy::label(Brgy::CATEGORIES, $p['category'])) ?><?= $p['mode_name'] ? ', ' . h($p['mode_name']) : '' ?><?= $p['reference_no'] ? ', ' . h($p['reference_no']) : '' ?><?= $p['regime'] === 'ra9184' ? ', <span class="text-warning-emphasis">RA 9184 (transition)</span>' : '' ?></div>
    </div>
    <div class="proj-head-status"><?= Brgy::st($p['status']) ?><div class="small text-muted"><?= h(Brgy::phaseLabel($p['phase'])) ?></div></div>
  </div>
  <?= $sop ? brgy_sop_track($p) : brgy_phase_track($p) ?>
  <?php if ($sop): ?><div class="small text-muted mt-1"><?= h(BrgySop::PROCESSES[$p['process']]) ?>; phase of the contract: <?= h(Brgy::phaseLabel($p['phase'])) ?></div><?php endif; ?>
</div></div>

<?php
// ------------------------------------------------------------------ the next step for this project
$next = '';
$modals = '';
if ($sop && in_array($p['status'], ['open', 'awarded'], true) && $tab !== 'process' && $p['sop_step']) {
    $cs = DB::row('SELECT * FROM brgy_sop_steps WHERE code = :c', ['c' => $p['sop_step']]);
    $next = $cs ? '<div class="next-step"><div><b>Step ' . (int)$cs['step_no'] . ' of the SOP: ' . h($cs['title']) . '</b><div class="small text-muted">' . h(mb_strimwidth((string)$cs['instructions'], 0, 180, '...')) . '</div></div>'
        . '<a class="btn btn-sm btn-primary" href="' . h(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => 'process'])) . '#step-' . h($cs['code']) . '"><i class="bi bi-signpost-split"></i> Go to the step</a></div>' : '';
} elseif ($sop && $tab === 'process' && in_array($p['status'], ['open', 'awarded'], true)) {
    $next = '';
} elseif ($p['status'] === 'open' && $p['go_decision'] === 'pending' && $canEdit) {
    $next = '<div class="next-step"><div><b>Go or no-go?</b><div class="small text-muted">Decide whether to bid, with the reason. Deadline: ' . h(fdatetime($p['bid_deadline']) ?: 'not entered') . '.</div></div>'
        . '<div class="d-flex gap-1"><button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#mGo"><i class="bi bi-hand-thumbs-up"></i> Go</button>'
        . '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mNoGo"><i class="bi bi-hand-thumbs-down"></i> No bid</button></div></div>';
    $modals .= brgy_reason_modal('mGo', 'Go: prepare the bid', $self(), 'go', ['decision' => 'go', 'tab' => $tab], 'btn-success');
    $modals .= brgy_reason_modal('mNoGo', 'No bid', $self(), 'go', ['decision' => 'no_go', 'tab' => $tab], 'btn-danger');
} elseif ($p['status'] === 'open' && in_array($p['phase'], ['eligibility', 'costing'], true) && in_array($p['bid_status'], ['draft', 'returned'], true)) {
    $gate = Brgy::bidGate($p);
    $next = '<div class="next-step"><div><b>Prepare the bid</b><div class="small text-muted">Bid papers ' . ($cl['ok'] + $cl['waived']) . ' of ' . ($cl['total'] - $cl['later']) . ' in order' . ($cl['blocking'] ? ', <span class="text-danger">' . $cl['blocking'] . ' mandatory not in order</span>' : '')
        . '; price schedule ' . money($p['bid_amount']) . ((float)$p['abc_amount'] > 0 ? ' of ABC ' . money($p['abc_amount']) : '') . '.</div>'
        . ($gate ? '<details class="small mt-1"><summary class="text-danger">' . count($gate) . ' thing(s) to settle before review</summary><ul class="mb-0">' . implode('', array_map(fn($g) => '<li>' . h($g) . '</li>', $gate)) . '</ul></details>' : '')
        . '</div><div>' . ($p['bid_status'] === 'returned' ? '<div class="small text-danger mb-1">Returned: ' . h((string)DB::value("SELECT remarks FROM brgy_workflow WHERE object_type = 'bid' AND object_id = :id AND action = 'return' ORDER BY id DESC LIMIT 1", ['id' => $id])) . '</div>' : '')
        . brgy_step_buttons('bid', $p, $p, $self(), 'bid', ['tab' => $tab]) . '</div></div>';
} elseif ($p['status'] === 'open' && in_array($p['bid_status'], ['for_review', 'reviewed'], true)) {
    $next = '<div class="next-step"><div><b>Bid in approval</b><div class="small text-muted">' . money($p['bid_amount']) . ', costing version ' . (int)$p['bid_version'] . '. Review, then approve, by two different people.</div></div><div>'
        . brgy_step_buttons('bid', $p, $p, $self(), 'bid', ['tab' => $tab]) . '</div></div>';
} elseif ($p['status'] === 'open' && $p['bid_status'] === 'approved') {
    $isSubmitter = (int)$p['submitter_id'] === (int)Auth::id() || Access::isAdmin();
    $next = '<div class="next-step"><div><b>Submit the approved bid</b><div class="small text-muted">' . ($p['submitter_id'] ? 'Authorized submitter: ' . h(Brgy::userName((int)$p['submitter_id'])) : '<span class="text-danger">Name the authorized submitter (Edit)</span>')
        . '. Deadline ' . h(fdatetime($p['bid_deadline'])) . ' (' . h(brgy_rel($p['bid_deadline'])) . ').</div></div><div class="d-flex gap-1 flex-wrap">'
        . ($isSubmitter && $canEdit ? '<a class="btn btn-sm btn-primary" href="' . h($self(['tab' => 'submissions', 'sub' => 'bid'])) . '#subForm"><i class="bi bi-send"></i> Record the submission</a>' : '')
        . brgy_step_buttons('bid', $p, $p, $self(), 'bid', ['tab' => $tab]) . '</div></div>';
} elseif ($p['status'] === 'open' && in_array($p['phase'], ['submitted', 'evaluation'], true) && $canEdit) {
    $next = '<div class="next-step"><div><b>Waiting for the result</b><div class="small text-muted">Submitted ' . h(fdatetime($p['bid_submitted_at'])) . ($p['result_rank'] ? ', ranked ' . (int)$p['result_rank'] : '') . '. Record the entity\'s notices as they come.</div></div>'
        . '<div class="d-flex gap-1 flex-wrap"><a class="btn btn-sm btn-success" href="' . h($self(['tab' => 'award'])) . '"><i class="bi bi-trophy"></i> Record the award</a>'
        . '<button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mLost">Not awarded</button><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mCancel">Cancelled by the entity</button></div></div>';
    $modals .= brgy_reason_modal('mCancel', 'Cancelled by the entity', $self(), 'result_cancel', ['tab' => $tab], 'btn-danger', true, 'A failed bidding, a cancelled procurement or a declaration by the entity. Attach its notice on the Submissions & letters tab.');
    $modals .= '<div class="modal fade" id="mLost" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="' . h($self()) . '">' . csrf_field()
        . '<input type="hidden" name="do" value="result_lost">' . $hiddenTab . '<div class="modal-header"><h5 class="modal-title">Not awarded</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body row g-2"><div class="col-12"><label class="form-label">Why (price, eligibility, technical ...)</label><input class="form-control" name="lost_reason" required maxlength="255"></div>'
        . '<div class="col-md-6"><label class="form-label">Winning bidder</label><input class="form-control" name="winning_bidder" maxlength="160"></div><div class="col-md-3"><label class="form-label">Winning amount</label><input class="form-control" name="winning_amount" inputmode="decimal"></div>'
        . '<div class="col-md-3"><label class="form-label">Our rank</label><input class="form-control" name="result_rank" type="number" min="1"></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Record</button></div></form></div></div>';
} elseif ($p['status'] === 'awarded' && $p['phase'] === 'award' && $canEdit) {
    $next = '<div class="next-step"><div><b>Start fulfilment</b><div class="small text-muted">Record the contract signing (or the purchase order) ' . ($p['category'] === 'works' ? 'and the notice to proceed ' : '') . 'on the Award & contract tab, then start.</div></div>'
        . '<form method="post" action="' . h($self()) . '">' . csrf_field() . '<input type="hidden" name="do" value="fulfill">' . $hiddenTab . '<button class="btn btn-sm btn-primary"><i class="bi bi-play-circle"></i> Start fulfilment</button></form></div>';
} elseif ($p['status'] === 'awarded') {
    $chk = BrgyOps::closeoutCheck($p);
    $btns = (Access::can('brgy.deliveries', 'create') ? '<a class="btn btn-sm btn-outline-primary" href="' . h(url('brgy.deliveries', ['a' => 'new', 'project_id' => $id])) . '"><i class="bi bi-truck"></i> New delivery</a>' : '')
        . (Access::can('brgy.billing', 'create') ? '<a class="btn btn-sm btn-outline-primary" href="' . h(url('brgy.billing', ['a' => 'new', 'project_id' => $id])) . '"><i class="bi bi-receipt"></i> New billing pack</a>' : '')
        . (Access::can('brgy.collections', 'create') ? '<a class="btn btn-sm btn-outline-primary" href="' . h(url('brgy.collections', ['a' => 'new', 'project_id' => $id])) . '"><i class="bi bi-cash-coin"></i> Record collection</a>' : '')
        . (Access::can('brgy.approvals', 'approve') ? '<button class="btn btn-sm ' . ($chk['blocking'] ? 'btn-outline-secondary' : 'btn-success') . '" data-bs-toggle="modal" data-bs-target="#mClose"><i class="bi bi-flag"></i> Close out</button>' : '');
    $next = '<div class="next-step"><div><b>Contract in force</b><div class="small text-muted">' . money($m['to_deliver']) . ' still to be accepted, ' . money($m['unbilled_accepted']) . ' accepted and not billed, '
        . ($m['billing_draft'] > 0 ? money($m['billing_draft']) . ' in billing packs not invoiced yet, ' : '') . money($m['ar_open']) . ' receivable'
        . ($p['due_date'] ? '. Due ' . h(fdate($p['due_date'])) . ' (' . h(brgy_rel($p['due_date'])) . ')' : '') . '.</div></div><div class="d-flex gap-1 flex-wrap">' . $btns . '</div></div>';
    $extra = ($chk['blocking'] ? '<div class="alert alert-danger small"><b>Not yet:</b><ul class="mb-0">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $chk['blocking'])) . '</ul></div>' : '')
        . ($chk['warnings'] ? '<div class="alert alert-warning small"><b>Open points:</b><ul class="mb-1">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $chk['warnings'])) . '</ul>'
            . '<label class="form-check mt-1"><input class="form-check-input" type="checkbox" name="override" value="1"> <span class="form-check-label">Close anyway; the reason is below</span></label></div>' : '')
        . (!$chk['blocking'] && !$chk['warnings'] ? '<div class="alert alert-success small">Everything is delivered, accepted, billed, collected and agrees with the ledger.</div>' : '');
    $modals .= brgy_reason_modal('mClose', 'Close out the contract', $self(), 'closeout', ['tab' => $tab], 'btn-success', (bool)$chk['warnings'], 'Closing keeps every record; the contract can be reopened by an approver.', $extra);
} elseif (in_array($p['status'], ['closed', 'lost', 'no_bid', 'cancelled'], true)) {
    $why = $p['status'] === 'lost' ? $p['lost_reason'] : ($p['status'] === 'cancelled' ? $p['cancel_reason'] : ($p['status'] === 'no_bid' ? $p['go_reason'] : $p['closeout_notes']));
    $next = '<div class="next-step is-ended"><div><b>' . h(Brgy::label(Brgy::STATUSES, $p['status'])) . '</b><div class="small text-muted">' . h((string)$why) . '</div></div>'
        . (Access::can('brgy.approvals', 'approve') ? '<button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mReopen"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button>' : '') . '</div>';
    $modals .= brgy_reason_modal('mReopen', 'Reopen the project', $self(), 'reopen', ['tab' => $tab], 'btn-primary');
}
echo $next ? '<div class="card mb-3"><div class="card-body py-2">' . $next . '</div></div>' : '';
echo $modals;

$counts = ['compliance' => $cl['blocking'] ?: null, 'documents' => (int)DB::value("SELECT COUNT(*) FROM brgy_documents WHERE project_id = :p AND is_current = 1 AND status <> 'void'", ['p' => $id]) ?: null,
    'forms' => (int)DB::value('SELECT COUNT(*) FROM brgy_forms WHERE project_id = :p', ['p' => $id]) ?: null,
    'delivery' => (int)DB::value("SELECT COUNT(*) FROM brgy_deliveries WHERE project_id = :p AND status <> 'void'", ['p' => $id]) ?: null,
    'billing' => (int)DB::value("SELECT COUNT(*) FROM brgy_billings WHERE project_id = :p AND status <> 'void'", ['p' => $id]) ?: null,
    'tasks' => (int)DB::value("SELECT COUNT(*) FROM brgy_tasks WHERE project_id = :p AND status IN ('open', 'in_progress')", ['p' => $id]) ?: null];
?>
<ul class="nav nav-tabs proj-tabs mb-0" role="tablist">
  <?php foreach ($tabs as $k => $label): if (!$costs && $k === 'costs') { continue; } ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['a' => 'view', 'id' => $id, 'tab' => $k])) ?>"><?= h($label) ?><?php if (!empty($counts[$k])): ?> <span class="tab-count<?= $k === 'compliance' ? ' is-bad' : '' ?>"><?= (int)$counts[$k] ?></span><?php endif; ?></a></li>
  <?php endforeach; ?>
</ul>
<div class="tab-body">
<?php
// ================================================================== the SOP
if ($tab === 'process' && $sop):
    require APP_ROOT . '/modules/brgy/_process_tab.php';

// ================================================================== overview
elseif ($tab === 'overview'):
    $fig = ['Approved budget (ABC)' => [money($p['abc_amount']), ''], 'Bid amount' => [money($p['bid_amount']), '']];
    if ($awarded) {
        $fig = ['Contract amount' => [money($m['contract']), 'info', (float)$p['co_amount'] != 0.0 ? 'change orders ' . money($p['co_amount']) : 'original'],
            'Accepted' => [money($m['accepted']), '', $m['contract'] > 0 ? round($m['accepted'] / $m['contract'] * 100) . '% of the contract' : ''],
            'Billed' => [money($m['billed']), '', implode(', ', array_filter([$m['unbilled_accepted'] > 0 ? money($m['unbilled_accepted']) . ' accepted, not billed' : '',
                $m['billing_draft'] > 0 ? money($m['billing_draft']) . ' in packs not invoiced yet' : '']))],
            'Cash collected' => [money($m['cash']), 'ok', 'taxes withheld ' . money($m['taxes'])],
            'Receivable (ledger)' => [money($m['ar_open']), $m['ar_open'] > 0 ? 'warn' : ''],
            'Retention held' => [money($m['retention_held']), $m['retention_held'] > 0 ? 'info' : '']];
        if ($costs) {
            $fig['Margin (ledger)'] = [money($m['margin']), $m['margin'] < 0 ? 'bad' : 'ok', $m['margin_pct'] !== null ? $m['margin_pct'] . '% of revenue' : ''];
        }
    } elseif ($costs) {
        $fig['Estimated cost'] = [money($p['est_cost']), '', (float)$p['bid_amount'] > 0 ? round(((float)$p['bid_amount'] - (float)$p['est_cost']) / (float)$p['bid_amount'] * 100, 1) . '% margin' : ''];
    }
    $fig['Checklist'] = [$cl['ok'] + $cl['waived'] . ' / ' . ($cl['total'] - $cl['later']), $cl['blocking'] ? 'bad' : 'ok', ($cl['blocking'] ? $cl['blocking'] . ' mandatory not in order' : 'papers due so far in order') . ($cl['later'] ? ', ' . $cl['later'] . ' for later stages' : '')];
    echo brgy_figures($fig);
    $dates = array_filter(['Posted' => $p['posting_date'], 'Site inspection' => $p['site_inspection_at'], 'Pre-bid conference' => $p['prebid_at'], 'Last day for questions' => $p['clarification_deadline'],
        'Bid deadline' => $p['bid_deadline'], 'Bid opening' => $p['bid_opening_at'], 'Bid submitted' => $p['bid_submitted_at'], 'Notice of award (issued)' => $p['noa_date'], 'Notice of award (received)' => $p['noa_received_date'],
        'Contract signed' => $p['contract_signed_date'], 'Notice to proceed' => $p['ntp_date'], 'Start' => $p['start_date'], 'Delivery / completion due' => $p['due_date'], 'Completed' => $p['completion_date'],
        'Warranty until' => $p['warranty_until'], 'Closed out' => $p['closeout_date']]);
    $people = array_filter(['Preparer' => $p['preparer_id'], 'Checker' => $p['checker_id'], 'Approver' => $p['approver_id'], 'Authorized submitter' => $p['submitter_id'], 'Project manager' => $p['pm_id'], 'Collector' => $p['collector_id']]);
    ?>
    <div class="row g-3">
      <div class="col-lg-5"><div class="card h-100"><div class="card-header"><i class="bi bi-calendar3"></i> Dates</div><div class="table-responsive"><table class="table table-sm mb-0">
        <?php foreach ($dates as $k => $v): $isDt = strlen((string)$v) > 10; ?><tr><td class="small text-muted"><?= h($k) ?></td><td class="text-nowrap"><?= h($isDt ? fdatetime($v) : fdate($v)) ?></td><td class="small text-muted text-nowrap"><?= h(brgy_rel($v)) ?></td></tr><?php endforeach; ?>
        <?php if (!$dates): ?><tr><td class="text-muted">No dates entered.</td></tr><?php endif; ?>
      </table></div></div></div>
      <div class="col-lg-7"><div class="card h-100"><div class="card-header"><i class="bi bi-info-circle"></i> Details</div><div class="card-body">
        <dl class="dl-grid">
          <?php foreach (array_filter(['End-user' => $p['end_user'], 'Source of funds' => $p['funding_source'], 'Delivery site' => $p['delivery_site'], 'Delivery period' => $p['delivery_days'] ? $p['delivery_days'] . ' calendar days' : '',
              'Contract / PO' => trim(($p['contract_no'] ? 'Contract ' . $p['contract_no'] : '') . ' ' . ($p['po_no'] ? 'PO ' . $p['po_no'] : '')), 'Notice of award' => $p['noa_ref'], 'Retention' => (float)$p['retention_pct'] > 0 ? pct($p['retention_pct']) : '',
              'Liquidated damages' => (float)$p['ld_rate_pct'] > 0 ? rtrim(rtrim(number_format((float)$p['ld_rate_pct'], 4), '0'), '.') . '% per day' : '', 'Warranty' => $p['warranty_months'] ? $p['warranty_months'] . ' months' : '',
              'Payment terms' => $p['payment_terms'], 'Customer:Job' => $p['job_name'], 'Class' => $p['class_name'], 'PhilGEPS reference' => $p['philgeps_ref']]) as $k => $v): ?>
            <dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd>
          <?php endforeach; ?>
          <?php if ($p['source_url']): ?><dt>Source</dt><dd><a href="<?= h($p['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h(mb_strimwidth($p['source_url'], 0, 60, '...')) ?> <i class="bi bi-box-arrow-up-right"></i></a></dd><?php endif; ?>
          <?php foreach ($people as $k => $uid): ?><dt><?= h($k) ?></dt><dd><?= h(Brgy::userName((int)$uid)) ?></dd><?php endforeach; ?>
        </dl>
        <?php if ($p['specs']): ?><div class="small text-muted mt-2">Specifications / scope</div><div class="pre-text"><?= h($p['specs']) ?></div><?php endif; ?>
        <?php if ($p['notes']): ?><div class="small text-muted mt-2">Notes</div><div class="pre-text"><?= h($p['notes']) ?></div><?php endif; ?>
      </div></div></div>
    </div>
    <?php
    $pa = array_filter(Brgy::alerts(), fn($x) => str_contains($x['text'], $p['project_no']));
    if ($pa): ?>
      <div class="card mt-3"><div class="card-header"><i class="bi bi-bell"></i> Alerts on this project</div><div class="card-body py-2"><?php foreach ($pa as $x): ?>
        <div class="alert-line al-<?= h($x['level']) ?>"><i class="bi bi-<?= h($x['icon']) ?>"></i> <a href="<?= h($x['url']) ?>"><?= h($x['text']) ?></a></div><?php endforeach; ?></div></div>
    <?php endif;

// ================================================================== price schedule
elseif ($tab === 'lines'):
    $editable = $canEdit && $p['status'] === 'open' && in_array($p['bid_status'], ['draft', 'returned'], true);
    $lt = Brgy::lineTotals($id);
    $vatIncl = Brgy::on('brgy_prices_vat_inclusive');
    if ($editable):
        $rows = is_post() ? (function () { try { return brgy_posted_lines(); } catch (Throwable $e) { return []; } })() : $lines;
        // v34: "Look up the Inventory List": the items come from the Inventory Center (inventory and assembly items),
        // with their default unit and the price of the customer (its price level), both still editable
        $lookup = is_post() ? p('lookup_inventory') === '1' : (int)($p['lines_lookup_inventory'] ?? 0) === 1;
        $custId = (int)($p['customer_id'] ?: DB::value('SELECT customer_id FROM brgy_entities WHERE id = :e', ['e' => (int)$p['entity_id']])) ?: null;
        $usedIds = array_values(array_filter(array_map(fn($l) => (int)($l['item_id'] ?? 0), $rows)));
        $invRows = DB::all("SELECT i.id, i.code, i.name, i.description, i.unit, i.cost, i.sales_price, i.tax_code_id, i.item_type,
                                   (SELECT COALESCE(SUM(s.qty_on_hand), 0) FROM item_site_stock s WHERE s.item_id = i.id) AS on_hand
                              FROM items i
                             WHERE (i.is_active = 1 AND i.item_type IN ('inventory','assembly'))" . ($usedIds ? ' OR i.id IN (' . implode(',', $usedIds) . ')' : '') . '
                             ORDER BY i.code');
        $itemOpts = [];
        $itemData = [];
        foreach ($invRows as $it) {
            $id = (int)$it['id'];
            $inv = in_array($it['item_type'], ['inventory', 'assembly'], true);
            $du = Uom::defaultUnit($id, 'sales');
            $factor = $du ? max(0.000001, (float)$du['factor']) : 1.0;
            $itemOpts[$id] = $it['code'] . ' - ' . $it['name'] . ($inv ? ' (' . qty((float)$it['on_hand']) . ' on hand)' : '');
            $itemData[$id] = ['d' => $it['description'] ?: $it['name'], 'u' => $du ? $du['code'] : (string)$it['unit'], 'c' => $costs ? round((float)$it['cost'] * $factor, 4) : 0,
                't' => $it['tax_code_id'], 'k' => $it['item_type'] === 'service' ? 'service' : 'goods',
                'p' => round(Lookup::priceFor($id, $custId) * $factor, 4)];
        }
        $taxOpts = Lookup::taxCodes();
        ?>
        <form method="post" action="<?= h($self()) ?>" id="linesForm" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="lines_save"><?= $hiddenTab ?><?= Brgy::versionField($p['lock_version']) ?>
        <p class="small text-muted mb-2"><?= $sop ? 'These are the items (complete specifications / particulars) of the transaction: the purchase request, purchase order, RIS, IAR and canvass forms print them. ' : '' ?>Prices are <?= $vatIncl ? 'VAT inclusive' : 'VAT exclusive' ?> (Contracting Settings). <?= $costs ? 'Leave the price blank to take the cost plus the markup.' : '' ?> The schedule is fixed once the bid is approved; after the award only change orders alter it.</p>
        <div class="lg-lookup form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="lookupInv" name="lookup_inventory" value="1" <?= $lookup ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="lookupInv">Look up the Inventory List</label>
          <div class="form-text mt-0">Pick each item from the Inventory Center: its default unit and <?= $custId ? 'the price of ' . h((string)DB::value('SELECT name FROM customers WHERE id = :c', ['c' => $custId])) . ' (its price level)' : 'its sales price (the barangay is not a customer yet)' ?> fill in, and both can still be changed. Off: type the lines freely.</div>
        </div>
        <div class="table-responsive"><table class="table table-sm line-free brgy-grid mb-1<?= $lookup ? '' : ' lg-lookup-off' ?>" id="linesGrid" data-items="<?= h(json_encode($itemData)) ?>" data-costs="<?= $costs ? 1 : 0 ?>" data-sop="<?= $sop ? 1 : 0 ?>">
          <thead><tr><th style="width:112px">Kind</th><th class="lg-item" style="min-width:200px">Item (Inventory List)</th><th style="min-width:220px">Description</th><th style="width:70px">Unit</th><th class="num" style="width:80px">Qty</th>
            <?php if ($costs): ?><th class="num" style="width:110px">Unit cost</th><th class="num" style="width:80px">Markup %</th><?php endif; ?><th class="num" style="width:120px">Unit price</th><?php if ($sop): ?><th class="num" style="width:110px" title="Estimated unit cost on the purchase request (blank = the unit price)">PR est. cost</th><th class="num" style="width:115px" title="Qty x estimated unit cost: the estimated amount of the purchase request">PR est. amount</th><?php endif; ?><th class="num" style="width:120px">Amount</th><th style="width:100px">Tax</th><th></th></tr></thead>
          <tbody></tbody>
          <tfoot><tr class="grid-totals"><td class="text-end" id="lgFootLabel" data-span="<?= $sop ? ($costs ? 9 : 7) : ($costs ? 7 : 5) ?>" colspan="<?= ($sop ? ($costs ? 9 : 7) : ($costs ? 7 : 5)) - ($lookup ? 0 : 1) ?>">Total</td><?php if ($sop): ?><td class="num fw-semibold" id="lgEstTotal" title="Purchase request: total estimated amount">0.00</td><?php else: ?><td></td><?php endif; ?><td class="num fw-bold" id="lgTotal">0.00</td><td colspan="2"></td></tr></tfoot>
        </table></div>
        <template id="tplLine"><tr>
          <td data-label="Kind"><select class="form-select form-select-sm" data-k="line_kind" data-no-combo><?= options(Brgy::LINE_KINDS) ?></select></td>
          <td data-label="Item (Inventory List)" class="lg-wide lg-item"><select class="form-select form-select-sm" data-k="item_id" data-combo><?= options($itemOpts, null, '') ?></select></td>
          <td data-label="Description" class="lg-wide"><input class="form-control form-control-sm" data-k="description" maxlength="255"><input type="hidden" data-k="id"></td>
          <td data-label="Unit"><input class="form-control form-control-sm" data-k="unit" maxlength="20"></td>
          <td data-label="Qty"><input class="form-control form-control-sm text-end" data-k="qty" inputmode="decimal"></td>
          <?php if ($costs): ?><td data-label="Unit cost"><input class="form-control form-control-sm text-end" data-k="unit_cost" inputmode="decimal"></td><td data-label="Markup %"><input class="form-control form-control-sm text-end" data-k="markup_pct" inputmode="decimal"></td><?php endif; ?>
          <td data-label="Unit price"><input class="form-control form-control-sm text-end" data-k="unit_price" inputmode="decimal"></td>
          <?php if ($sop): ?><td data-label="PR est. cost"><input class="form-control form-control-sm text-end" data-k="est_unit_price" inputmode="decimal"></td><td data-label="PR est. amount" class="num align-middle" data-k="est_amount">0.00</td><?php endif; ?>
          <td data-label="Amount" class="num align-middle lg-amount" data-k="amount">0.00</td>
          <td data-label="Tax"><select class="form-select form-select-sm" data-k="tax_code_id" data-no-combo><?= options($taxOpts, Brgy::defaultTaxCode()) ?></select></td>
          <td class="grid-acts lg-del"><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del aria-label="Remove the line"><i class="bi bi-x-lg"></i><span class="d-md-none"> Remove</span></button></td>
        </tr></template>
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-2">
          <div class="grid-add"><span class="small text-muted me-1">Add</span><div class="btn-group btn-group-sm" role="group" aria-label="Add lines"><button type="button" class="btn btn-outline-secondary" data-add="1"><i class="bi bi-plus"></i> 1 line</button><button type="button" class="btn btn-outline-secondary" data-add="5">5 lines</button><button type="button" class="btn btn-outline-secondary" data-add="10">10 lines</button></div><span class="small text-muted ms-2" id="lgCount"></span></div>
          <div class="abc-meter" data-abc="<?= h((string)(float)$p['abc_amount']) ?>"><div class="abc-bar"><span id="abcFill"></span></div><div class="small" id="abcText"></div></div>
        </div>
        <div class="sticky-actions mt-3"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save price schedule</button></div>
        </form>
        <?php
        // numbers as people type them: 600, 248.40, 38.5
        $nq = fn($v) => $v === '' || $v === null ? '' : rtrim(rtrim(number_format(to_num($v), 4, '.', ''), '0'), '.');
        $nm = function ($v) {
            if ($v === '' || $v === null) {
                return '';
            }
            $s = rtrim(rtrim(number_format(to_num($v), 4, '.', ''), '0'), '.');
            return str_contains($s, '.') && strlen(substr($s, strpos($s, '.') + 1)) >= 2 ? $s : number_format(to_num($v), 2, '.', '');
        };
        ?>
        <script>window.BRGY_LINES = <?= json_encode(array_map(fn($l) => ['id' => $l['id'] ?? '', 'line_kind' => $l['line_kind'] ?? 'goods', 'item_id' => $l['item_id'] ?? '', 'description' => $l['description'] ?? '', 'unit' => $l['unit'] ?? '',
            'qty' => $nq(isset($l['bid_qty']) ? $l['bid_qty'] : ($l['qty'] ?? '')), 'unit_cost' => $costs ? $nm($l['unit_cost'] ?? '') : '', 'markup_pct' => $costs ? $nq($l['markup_pct'] ?? '') : '',
            'unit_price' => $nm($l['unit_price'] ?? ''), 'est_unit_price' => $nm($l['est_unit_price'] ?? ''), 'tax_code_id' => $l['tax_code_id'] ?? Brgy::defaultTaxCode()], $rows)) ?>;</script>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>No.</th><th>Description</th><th>Unit</th><th class="num">Bid qty</th><th class="num">Contract qty</th><th class="num">Unit price</th><th class="num">Amount</th>
            <?php if ($costs): ?><th class="num">Unit cost</th><?php endif; ?><th class="num">Delivered</th><th class="num">Accepted</th><th class="num">Rejected</th><th class="num">Billed</th></tr></thead>
          <?php foreach ($lines as $l): ?><tr>
            <td><?= (int)$l['line_no'] ?></td><td class="wrap-link"><?= h($l['description']) ?><?= $l['item_code'] ? '<div class="small text-muted">' . h($l['item_code']) . '</div>' : '' ?><?= $l['co_id'] ? '<div class="small text-info">added by a change order</div>' : '' ?></td>
            <td><?= h($l['unit']) ?></td><td class="num"><?= qty($l['bid_qty']) ?></td><td class="num"><?= qty($l['qty']) ?></td><td class="num"><?= money($l['unit_price']) ?></td><td class="num"><?= money($l['amount']) ?></td>
            <?php if ($costs): ?><td class="num"><?= money($l['unit_cost']) ?></td><?php endif; ?>
            <td class="num"><?= qty($l['qty_delivered']) ?></td><td class="num"><?= qty($l['qty_accepted']) ?></td><td class="num <?= (float)$l['qty_rejected'] > 0 ? 'text-danger' : '' ?>"><?= qty($l['qty_rejected']) ?></td><td class="num"><?= qty($l['qty_billed']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$lines): ?><tr><td colspan="12" class="text-muted">No lines.</td></tr><?php endif; ?>
          <tfoot><tr><td colspan="6" class="text-end">Bid total <?= money($lt['bid']) ?>; contract total</td><td class="num"><?= money($lt['contract']) ?></td><td colspan="<?= $costs ? 5 : 4 ?>"></td></tr></tfoot>
        </table></div></div>
        <p class="small text-muted mt-2">The bid is <?= h(strtolower(Brgy::WF[$p['bid_status']] ?? $p['bid_status'])) ?>; the schedule is read-only<?= $awarded ? '. Change it with a change order' : '' ?>.</p>
    <?php endif; ?>
    <div class="d-flex gap-2 mt-2"><?= page_link($pageKey, ['a' => 'lines_csv', 'id' => $id], 'Export (CSV for Excel)', 'btn-outline-secondary', 'filetype-csv', 'print') ?></div>
    <?php if ($costs): $vers = DB::all('SELECT v.*, u.full_name FROM brgy_cost_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.project_id = :p ORDER BY v.version_no DESC', ['p' => $id]); if ($vers): ?>
      <div class="card mt-3"><div class="card-header"><i class="bi bi-clock-history"></i> Costing versions sent for review (read-only)</div><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Version</th><th>When</th><th>By</th><th class="num">Estimated cost</th><th class="num">Bid</th><th class="num">Margin</th><th>Note</th></tr></thead>
        <?php foreach ($vers as $v): ?><tr><td><?= (int)$v['version_no'] ?></td><td class="small"><?= h(fdatetime($v['created_at'])) ?></td><td class="small"><?= h($v['full_name']) ?></td><td class="num"><?= money($v['est_cost']) ?></td><td class="num"><?= money($v['bid_amount']) ?></td><td class="num"><?= pct($v['margin_pct']) ?></td><td class="small"><?= h($v['note']) ?></td></tr><?php endforeach; ?>
      </table></div></div>
    <?php endif; endif;

// ================================================================== compliance
elseif ($tab === 'compliance'):
    $rows = Brgy::checklist($id, $p);
    $vaultDocs = DB::pairs("SELECT id, CONCAT(title, ' (', doc_type, IF(valid_until IS NULL, '', CONCAT(', valid until ', DATE_FORMAT(valid_until, '%m/%d/%Y'))), ')') FROM brgy_documents
                             WHERE status NOT IN ('void', 'superseded') AND is_current = 1 AND (project_id = :p OR scope = 'company') ORDER BY scope, title", ['p' => $id]);
    $stateText = ['ok' => 'In order', 'waived' => 'Not required', 'pending' => 'For review', 'returned' => 'Returned', 'missing' => 'Missing', 'expired' => 'Expired'];
    $stateTone = ['ok' => 'ok', 'waived' => 'waived', 'pending' => 'pending', 'returned' => 'returned', 'missing' => 'missing', 'expired' => 'expired'];
    echo brgy_figures(['In order' => [(string)$cl['ok'], 'ok'], 'For review' => [(string)$cl['pending'], $cl['pending'] ? 'warn' : ''], 'Missing' => [(string)$cl['missing'], $cl['missing'] ? 'bad' : ''],
        'Expired' => [(string)$cl['expired'], $cl['expired'] ? 'bad' : ''], 'Returned' => [(string)$cl['returned'], $cl['returned'] ? 'bad' : ''], 'Not required' => [(string)$cl['waived'], ''],
        'Later stages' => [(string)$cl['later'], '', 'due as the contract goes on']]);
    ?>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <?php if ($canEdit): ?>
        <form method="post" action="<?= h($self()) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="req_refresh"><?= $hiddenTab ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Add what the library suggests</button></form>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mReqAdd"><i class="bi bi-plus-lg"></i> Add a requirement of this project</button>
      <?php endif; ?>
      <span class="small text-muted align-self-center">Eligibility and bid papers have to be valid on <?= h(fdate(Brgy::neededOn($p)['bid'])) ?> (<?= $p['bid_submitted_at'] ? 'the day the bid went in' : 'the bid deadline' ?>); contract, delivery and billing papers while the contract runs.</span>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0 checklist">
      <thead><tr><th>Stage</th><th>Requirement</th><th>Kind</th><th>Document</th><th>Status</th><th></th></tr></thead>
      <?php $lastStage = null; foreach ($rows as $r): ?>
        <tr class="cl-<?= h(!$r['due'] && !in_array($r['state'], ['ok', 'waived'], true) ? 'later' : $r['state']) ?>">
          <td class="small text-nowrap"><?= $r['stage'] !== $lastStage ? h(Brgy::label(Brgy::STAGES, $lastStage = $r['stage'])) : '' ?></td>
          <td class="wrap-link"><?= h($r['name']) ?><?php if ($r['legal_basis']): ?> <i class="bi bi-info-circle text-muted" title="<?= h($r['legal_basis']) ?>" aria-label="Source: <?= h($r['legal_basis']) ?>"></i><?php endif; ?>
            <?php if ($r['waive_reason']): ?><div class="small text-muted">Not required: <?= h($r['waive_reason']) ?></div><?php endif; ?>
            <?php if ($r['remarks'] && $r['state'] === 'returned'): ?><div class="small text-danger">Returned: <?= h($r['remarks']) ?></div><?php endif; ?></td>
          <td class="small"><?= h(Brgy::label(Brgy::REQ_TYPES, $r['requirement_type'])) ?></td>
          <td class="small wrap-link"><?= $r['by_record'] ? '<span class="text-success"><i class="bi bi-check2"></i> ' . h($r['by_record']) . '</span>' : '' ?><?= $r['document_id'] ? brgy_doc_link(['id' => $r['document_id'], 'title' => $r['doc_title'], 'confidential' => 0, 'project_id' => $id]) . ((int)$r['version_no'] > 1 ? ' v' . (int)$r['version_no'] : '') . ($r['doc_scope'] === 'company' ? ' <span class="text-muted">(company)</span>' : '') : '' ?>
            <?= $r['valid_until'] ? '<div class="' . ($r['state'] === 'expired' ? 'text-danger' : 'text-muted') . '">valid until ' . h(fdate($r['valid_until'])) . ($r['state'] === 'expired' ? ', needed on ' . h(fdate($r['need_on'])) : '') . '</div>' : '' ?></td>
          <td><?= !$r['due'] && !in_array($r['state'], ['ok', 'waived'], true) ? Brgy::st('void', 'Later') : Brgy::st($stateTone[$r['state']], $stateText[$r['state']]) ?></td>
          <td class="text-end text-nowrap"><?php if ($canEdit && $p['status'] !== 'closed'): ?>
            <?php if (!in_array($r['state'], ['ok', 'waived'], true)): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mAttach" data-req="<?= (int)$r['id'] ?>" data-req-name="<?= h($r['name']) ?>" data-validity="<?= (int)$r['needs_validity'] ?>" data-company="<?= $r['doc_type'] && (int)DB::value('SELECT is_company FROM brgy_doc_types WHERE code = :c', ['c' => $r['doc_type']]) ? 1 : 0 ?>"><i class="bi bi-paperclip"></i> File</button><?php endif; ?>
            <?php if (!in_array($r['state'], ['waived', 'ok'], true) && ($r['requirement_type'] !== 'mandatory' || Access::can('brgy.approvals', 'approve'))): ?><button class="btn btn-sm btn-link" data-bs-toggle="modal" data-bs-target="#mWaive" data-req="<?= (int)$r['id'] ?>" data-req-name="<?= h($r['name']) ?>">Not required</button><?php endif; ?>
            <?php if ($r['template_code'] && Access::can('brgy.forms', 'create') && !in_array($r['state'], ['ok', 'waived'], true) && ($tid = DB::value('SELECT id FROM brgy_templates WHERE code = :c AND is_active = 1', ['c' => $r['template_code']]))): ?>
              <form method="post" action="<?= h($self()) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="form_create"><input type="hidden" name="template_id" value="<?= (int)$tid ?>"><?= $hiddenTab ?><button class="btn btn-sm btn-link">Prepare the form</button></form>
            <?php endif; ?>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-muted py-3">No requirements yet. Add what the library suggests, or the requirements of the RFQ.</td></tr><?php endif; ?>
    </table></div></div>
    <?php if ($canEdit): ?>
    <div class="modal fade" id="mAttach" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><form class="modal-content" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?>
      <input type="hidden" name="do" value="req_attach"><input type="hidden" name="req_id" data-fill-req><?= $hiddenTab ?>
      <div class="modal-header"><h5 class="modal-title">File a document: <span data-fill-name></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <?= brgy_file_field('file', 'Upload the document', 'PDF or a picture of the signed / issued paper. It is reviewed before it counts.') ?>
        <div class="col-12 text-center small text-muted">or</div>
        <?= select_field('doc_id', 'Use a document already in the vault', $vaultDocs, null, ['blank' => '', 'class' => 'col-12']) ?>
        <?= input_field('title', 'Title (optional)', '', ['class' => 'col-md-6']) ?>
        <?= input_field('doc_no', 'Document no.', '', ['class' => 'col-md-3']) ?>
        <?= input_field('issued_by', 'Issued by', '', ['class' => 'col-md-3']) ?>
        <?= input_field('issue_date', 'Issued on', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
        <div class="col-md-3" data-validity-box><label class="form-label">Valid until</label><input class="form-control form-control-sm" type="date" name="valid_until"></div>
        <div class="col-md-6 d-flex align-items-end" data-company-box><label class="form-check"><input class="form-check-input" type="checkbox" name="as_company" value="1"> <span class="form-check-label">File it as a company paper (reused on every bid)</span></label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">File</button></div></form></div></div>
    <?= brgy_reason_modal('mWaive', 'Not required for this project', $self(), 'req_waive', ['tab' => $tab, 'req_id' => ''], 'btn-primary', true, 'Say why (e.g. "not asked in the RFQ"). A mandatory requirement is waived by an approver only, and stays in the audit trail.') ?>
    <div class="modal fade" id="mReqAdd" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?>
      <input type="hidden" name="do" value="req_add"><?= $hiddenTab ?>
      <div class="modal-header"><h5 class="modal-title">Add a requirement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <?= input_field('name', 'Requirement', '', ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="200"']) ?>
        <?= select_field('stage', 'Stage', Brgy::STAGES, 'eligibility', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
        <?= select_field('requirement_type', 'Kind', Brgy::REQ_TYPES, 'mandatory', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
        <?= select_field('doc_type', 'Document type', Brgy::docTypes(), '', ['blank' => '', 'class' => 'col-md-6']) ?>
        <?= check_field('needs_validity', 'Has a validity date', 0, 'col-md-6') ?>
        <?= input_field('legal_basis', 'Source (RFQ clause, bulletin, BAC resolution)', '', ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        <div class="col-12 form-text">For a requirement of this RFQ only. One the entity always asks for belongs in the Compliance Requirement Library (approved there).</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add</button></div></form></div></div>
    <?php endif;

// ================================================================== documents
elseif ($tab === 'documents'):
    $docs = DB::all("SELECT d.*, u.full_name FROM brgy_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.project_id = :p AND d.is_current = 1 ORDER BY d.uploaded_at DESC, d.id DESC", ['p' => $id]);
    ?>
    <div class="row g-3">
      <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead><tr><th>Document</th><th>Type</th><th>Validity</th><th>Status</th><th>Filed</th></tr></thead>
        <?php foreach ($docs as $d): ?><tr>
          <td class="wrap-link"><?= BrgyDocs::canSee($d) ? brgy_doc_link($d) : '<span class="text-muted"><i class="bi bi-lock"></i> Confidential</span>' ?><?= (int)$d['version_no'] > 1 ? ' <span class="small text-muted">v' . (int)$d['version_no'] . '</span>' : '' ?>
            <?= (int)$d['is_locked'] ? ' <i class="bi bi-lock-fill text-muted" title="Final, locked"></i>' : '' ?><?= $d['doc_no'] ? '<div class="small text-muted">' . h($d['doc_no']) . '</div>' : '' ?></td>
          <td class="small"><?= h(Brgy::docTypeName($d['doc_type'])) ?></td>
          <td class="small <?= $d['valid_until'] && $d['valid_until'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($d['valid_until'])) ?></td>
          <td><?= Brgy::st($d['status'] === 'for_review' ? 'pending' : $d['status'], Brgy::WF[$d['status']] ?? ucfirst(str_replace('_', ' ', $d['status']))) ?></td>
          <td class="small"><?= h(fdate($d['uploaded_at'])) ?><div class="text-muted"><?= h($d['full_name']) ?></div></td></tr>
        <?php endforeach; ?>
        <?php if (!$docs): ?><tr><td colspan="5" class="text-muted py-3">No documents filed under this project yet.</td></tr><?php endif; ?>
      </table></div></div></div>
      <div class="col-xl-4"><?php if (Access::can('brgy.vault', 'create')): ?>
        <form class="card" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="doc_upload"><?= $hiddenTab ?>
          <div class="card-header"><i class="bi bi-cloud-arrow-up"></i> File a document</div><div class="card-body row g-2">
            <?= brgy_file_field('file', 'File', 'PDF, pictures, Word, Excel, PowerPoint, Publisher (old and new versions). Up to ' . Brgy::setting('brgy_upload_max_mb') . ' MB.', 'col-12', true) ?>
            <?= select_field('doc_type', 'Type', Brgy::docTypes(), 'other', ['class' => 'col-12']) ?>
            <?= input_field('title', 'Title', '', ['class' => 'col-12', 'required' => true]) ?>
            <?= input_field('doc_no', 'Document no.', '', ['class' => 'col-6']) ?>
            <?= input_field('issued_by', 'Issued by', '', ['class' => 'col-6']) ?>
            <?= input_field('issue_date', 'Issued on', '', ['type' => 'date', 'class' => 'col-6']) ?>
            <?= input_field('valid_until', 'Valid until', '', ['type' => 'date', 'class' => 'col-6']) ?>
            <?= input_field('tags', 'Tags', '', ['class' => 'col-12', 'extra' => 'placeholder="e.g. lot 1, signed"']) ?>
            <?= check_field('confidential', 'Confidential (financial bid, costing)', 0, 'col-12') ?>
          </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> File</button></div></form>
      <?php endif; ?></div>
    </div>
    <?php

// ================================================================== forms
elseif ($tab === 'forms'):
    $forms = DB::all('SELECT f.*, t.name AS template_name, u.full_name FROM brgy_forms f LEFT JOIN brgy_templates t ON t.id = f.template_id LEFT JOIN users u ON u.id = f.prepared_by WHERE f.project_id = :p ORDER BY f.id DESC', ['p' => $id]);
    $tpls = DB::all('SELECT id, name, category, stage, is_prescribed FROM brgy_templates WHERE is_active = 1 ORDER BY sort_order, name');
    $groups = [];
    foreach ($tpls as $t) {
        $groups[['bid' => 'Bid', 'award' => 'Award and contract', 'contract' => 'Contracts (proforma)', 'delivery' => 'Delivery', 'billing' => 'Billing', 'collection' => 'Collection', 'closeout' => 'Closeout', 'internal' => 'Internal'][$t['stage']] ?? 'Other'][$t['id']] = $t['name'] . ($t['is_prescribed'] ? ' [prescribed]' : '');
    }
    $related = [];
    foreach (DB::all("SELECT id, dr_no, delivery_date FROM brgy_deliveries WHERE project_id = :p AND status <> 'void' ORDER BY id DESC", ['p' => $id]) as $d) {
        $related['Deliveries']['delivery:' . $d['id']] = $d['dr_no'] . ', ' . fdate($d['delivery_date']);
    }
    foreach (DB::all("SELECT id, billing_no, gross_amount FROM brgy_billings WHERE project_id = :p AND status <> 'void' ORDER BY id DESC", ['p' => $id]) as $b) {
        $related['Billings']['billing:' . $b['id']] = $b['billing_no'] . ', ' . money($b['gross_amount']);
    }
    foreach (DB::all("SELECT id, collection_no, cash_received FROM brgy_collections WHERE project_id = :p AND status = 'posted' ORDER BY id DESC", ['p' => $id]) as $c) {
        $related['Collections']['collection:' . $c['id']] = $c['collection_no'] . ', ' . money($c['cash_received']);
    }
    ?>
    <div class="row g-3">
      <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead><tr><th>Form</th><th>Template</th><th>Status</th><th>Prepared</th></tr></thead>
        <?php foreach ($forms as $f): ?><tr><td class="wrap-link"><a href="<?= h(url('brgy.forms', ['a' => in_array($f['status'], ['draft', 'returned'], true) ? 'edit' : 'view', 'id' => $f['id']])) ?>"><?= h($f['form_no']) ?></a><div class="small"><?= h($f['title']) ?></div></td>
          <td class="small"><?= h($f['template_name']) ?><?= (int)$f['is_prescribed'] ? '<div class="text-warning-emphasis">prescribed form</div>' : '' ?></td><td><?= Brgy::st($f['status']) ?></td><td class="small"><?= h(fdate($f['created_at'])) ?><div class="text-muted"><?= h($f['full_name']) ?></div></td></tr>
        <?php endforeach; ?>
        <?php if (!$forms): ?><tr><td colspan="4" class="text-muted py-3">No forms prepared for this project yet.</td></tr><?php endif; ?>
      </table></div></div></div>
      <div class="col-xl-4"><?php if (Access::can('brgy.forms', 'create')): ?>
        <form class="card" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="form_create"><?= $hiddenTab ?>
          <div class="card-header"><i class="bi bi-file-earmark-plus"></i> Prepare a form</div><div class="card-body row g-2">
            <div class="col-12"><label class="form-label">Template</label><select class="form-select form-select-sm" name="template_id" required data-combo><option value=""></option>
              <?php foreach ($groups as $g => $opts): ?><optgroup label="<?= h($g) ?>"><?= options($opts) ?></optgroup><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">For (fills the delivery, billing or collection fields)</label><select class="form-select form-select-sm" name="related" data-no-combo><option value="">The project only</option>
              <?php foreach ($related as $g => $opts): ?><optgroup label="<?= h($g) ?>"><?= options($opts) ?></optgroup><?php endforeach; ?></select></div>
            <div class="col-12 form-text">The template is filled in from the project, the entity and the company settings. The form can then be edited, reviewed, approved and printed (or saved for Word).</div>
          </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-richtext"></i> Prepare</button></div></form>
      <?php endif; ?></div>
    </div>
    <?php

// ================================================================== submissions and correspondence
elseif ($tab === 'submissions'):
    $subs = DB::all('SELECT s.*, u.full_name FROM brgy_submissions s LEFT JOIN users u ON u.id = s.submitted_by WHERE s.project_id = :p ORDER BY s.submitted_at DESC', ['p' => $id]);
    $events = DB::all("SELECT e.*, u.full_name FROM brgy_events e LEFT JOIN users u ON u.id = e.created_by WHERE e.project_id = :p ORDER BY e.status = 'open' DESC, e.event_at DESC", ['p' => $id]);
    $subType = g('sub') === 'bid' && $p['bid_status'] === 'approved' ? 'bid' : 'compliance';
    ?>
    <div class="row g-3">
      <div class="col-xl-7">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-send"></i> Submissions to the entity</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>When</th><th>What</th><th>How</th><th>Receipt</th><th>Proof</th></tr></thead>
          <?php foreach ($subs as $s): ?><tr><td class="small text-nowrap"><?= h(fdatetime($s['submitted_at'])) ?><div class="text-muted"><?= h($s['full_name']) ?></div></td>
            <td class="small"><?= h(Brgy::label(Brgy::SUB_TYPES, $s['sub_type'])) ?><?= $s['outcome'] ? '<div class="text-muted">' . h($s['outcome']) . '</div>' : '' ?></td>
            <td class="small"><?= h(Brgy::label(Brgy::SUB_METHODS, $s['method'])) ?><?= $s['tracking_no'] ? '<div class="text-muted">' . h($s['tracking_no']) . '</div>' : '' ?><?= $s['recipient_name'] ? '<div class="text-muted">to ' . h($s['recipient_name']) . '</div>' : '' ?></td>
            <td class="small"><?= $s['received_at'] || $s['received_by'] ? h(trim(($s['received_by'] ?? '') . ' ' . fdatetime($s['received_at']))) . ($s['ack_ref'] ? '<div class="text-muted">' . h($s['ack_ref']) . '</div>' : '') : '<span class="text-warning-emphasis">not confirmed</span>' ?></td>
            <td class="small"><?= brgy_doc_link($s['proof_doc_id'], 'proof') ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$subs): ?><tr><td colspan="5" class="text-muted">Nothing submitted yet.</td></tr><?php endif; ?>
        </table></div></div>
        <div class="card"><div class="card-header"><i class="bi bi-chat-left-text"></i> Correspondence, bulletins and notices</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>When</th><th>What</th><th>Reply due</th><th></th></tr></thead>
          <?php foreach ($events as $e): ?><tr class="<?= $e['status'] === 'open' ? 'ev-open' : '' ?>"><td class="small text-nowrap"><?= h(fdatetime($e['event_at'])) ?></td>
            <td class="wrap-link small"><b><?= h($e['title']) ?></b><div class="text-muted"><?= h(Brgy::label(Brgy::EVENT_TYPES, $e['event_type'])) ?><?= $e['direction'] === 'in' ? ', from the entity' : ($e['direction'] === 'out' ? ', to the entity' : '') ?><?= $e['party'] ? ', ' . h($e['party']) : '' ?></div>
              <?= $e['details'] ? '<div class="pre-text">' . h($e['details']) . '</div>' : '' ?><?= $e['doc_id'] ? '<div>' . brgy_doc_link($e['doc_id']) . '</div>' : '' ?></td>
            <td class="small <?= $e['status'] === 'open' && $e['due_at'] && strtotime((string)$e['due_at']) < time() ? 'text-danger' : '' ?>"><?= $e['status'] === 'open' ? h(fdatetime($e['due_at'])) : '' ?></td>
            <td><?php if ($e['status'] === 'open' && $canEdit): ?><button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#mEvDone" data-event="<?= (int)$e['id'] ?>">Answered</button><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$events): ?><tr><td colspan="4" class="text-muted">Nothing recorded yet.</td></tr><?php endif; ?>
        </table></div></div>
      </div>
      <div class="col-xl-5"><?php if ($canEdit || Access::can('brgy.billing', 'edit')): ?>
        <form class="card mb-3" id="subForm" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="submission"><?= $hiddenTab ?>
          <div class="card-header"><i class="bi bi-send-check"></i> Record a submission</div><div class="card-body row g-2">
            <?= select_field('sub_type', 'What was submitted', Brgy::SUB_TYPES, $subType, ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('method', 'How', Brgy::SUB_METHODS, 'physical', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
            <div class="col-md-6"><label class="form-label">Submitted at (actual)</label><input class="form-control form-control-sm" type="datetime-local" name="submitted_at" required value="<?= h(date('Y-m-d\TH:i')) ?>"></div>
            <?= input_field('tracking_no', 'Tracking / e-mail / portal ref.', '', ['class' => 'col-md-6']) ?>
            <?= input_field('recipient_name', 'Addressed to', '', ['class' => 'col-md-6']) ?>
            <?= input_field('recipient_office', 'Office', '', ['class' => 'col-md-6']) ?>
            <?= input_field('received_by', 'Received by (from the receiving copy)', '', ['class' => 'col-md-6']) ?>
            <div class="col-md-6"><label class="form-label">Received at</label><input class="form-control form-control-sm" type="datetime-local" name="received_at"></div>
            <?= input_field('ack_ref', 'Acknowledgment / logbook no.', '', ['class' => 'col-md-6']) ?>
            <?= input_field('outcome', 'Outcome / notes', '', ['class' => 'col-md-6']) ?>
            <?= brgy_file_field('proof', 'Proof (receiving copy, sent e-mail, courier receipt, portal screenshot)') ?>
            <div class="col-12 form-text">Record what actually happened, with its proof; the program never sends anything to PhilGEPS or the entity by itself. A bid is recorded by its authorized submitter, before the deadline.</div>
          </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Record</button></div></form>
      <?php endif; ?><?php if ($canEdit): ?>
        <form class="card" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="event_add"><?= $hiddenTab ?>
          <div class="card-header"><i class="bi bi-chat-left-dots"></i> Record a letter, notice or meeting</div><div class="card-body row g-2">
            <?= select_field('event_type', 'Kind', Brgy::EVENT_TYPES, 'letter', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('direction', 'Direction', ['in' => 'From the entity', 'out' => 'To the entity', 'internal' => 'Internal'], 'in', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
            <?= input_field('title', 'Subject', '', ['class' => 'col-12', 'required' => true]) ?>
            <div class="col-md-6"><label class="form-label">Date and time</label><input class="form-control form-control-sm" type="datetime-local" name="event_at" value="<?= h(date('Y-m-d\TH:i')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Reply / action due (leave blank if none)</label><input class="form-control form-control-sm" type="datetime-local" name="due_at"></div>
            <?= input_field('party', 'From / to (name, office)', '', ['class' => 'col-12']) ?>
            <div class="col-12"><label class="form-label">Details</label><textarea class="form-control form-control-sm" name="details" rows="2"></textarea></div>
            <?= brgy_file_field('file', 'The paper (optional)') ?>
          </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Record</button></div></form>
        <?= brgy_reason_modal('mEvDone', 'Mark as answered', $self(), 'event_done', ['tab' => $tab, 'event_id' => ''], 'btn-success', false, 'Note what was answered or done.') ?>
      <?php endif; ?></div>
    </div>
    <?php

// ================================================================== award and contract
elseif ($tab === 'award'):
    $secs = DB::all('SELECT * FROM brgy_securities WHERE project_id = :p ORDER BY id', ['p' => $id]);
    if ($p['status'] === 'open' && in_array($p['phase'], ['submitted', 'evaluation'], true) && $canEdit):
        $evDocs = DB::pairs("SELECT id, title FROM brgy_documents WHERE project_id = :p AND doc_type IN ('noa', 'po', 'contract') AND status <> 'void' AND is_current = 1", ['p' => $id]); ?>
      <form class="card mb-3" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="award"><?= $hiddenTab ?>
        <div class="card-header"><i class="bi bi-trophy"></i> Record the award (from the entity's notice of award, purchase order or contract)</div><div class="card-body row g-2">
          <?= input_field('noa_ref', 'Notice of award / PO no.', '', ['class' => 'col-md-3', 'required' => true]) ?>
          <?= input_field('noa_date', 'Dated (issued by the entity)', '', ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
          <?= input_field('noa_received_date', 'Received by the company', today(), ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('noa_ack_date', 'Acknowledged (conforme)', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('contract_amount', 'Amount awarded', number_format((float)$p['bid_amount'], 2, '.', ''), ['class' => 'col-md-3', 'extra' => 'inputmode="decimal"']) ?>
          <?= input_field('po_no', 'Purchase order no.', '', ['class' => 'col-md-3']) ?>
          <?= input_field('contract_no', 'Contract no.', '', ['class' => 'col-md-3']) ?>
          <?= input_field('contract_signed_date', 'Contract signed', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('ntp_date', 'Notice to proceed (dated)', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('start_date', 'Start', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('due_date', 'Due (blank: start + delivery days)', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= select_field('evidence_type', 'The paper is a', ['noa' => 'Notice of award', 'po' => 'Purchase order / job order', 'contract' => 'Contract'], 'noa', ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
          <?= brgy_file_field('evidence', 'The entity\'s paper (required)', 'Scan or photo of the notice / PO / contract as received.', 'col-md-8') ?>
          <?= select_field('evidence_doc_id', 'or one already filed', $evDocs, null, ['blank' => '', 'class' => 'col-md-4']) ?>
          <div class="col-12 form-text">The award is the entity's act: it is recorded from its paper, never from an internal approval. The amount has to agree with the approved price schedule. A Customer:Job and a class are opened; nothing is posted to the ledger.</div>
        </div><div class="card-footer"><button class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Record the award</button></div></form>
    <?php elseif ($awarded): ?>
      <div class="row g-3">
        <div class="col-xl-7"><form class="card mb-3" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="contract_dates"><?= $hiddenTab ?>
          <div class="card-header"><i class="bi bi-file-earmark-check"></i> Contract</div><div class="card-body row g-2">
            <div class="col-12 small">Notice of award <b><?= h($p['noa_ref']) ?></b> dated <?= h(fdate($p['noa_date'])) ?>, received <?= h(fdate($p['noa_received_date'])) ?>.
              <?= brgy_doc_link(DB::value("SELECT id FROM brgy_documents WHERE project_id = :p AND related_type = 'award' AND doc_type IN ('noa','po','contract') ORDER BY id LIMIT 1", ['p' => $id]), 'Evidence') ?>
              Amount awarded <?= money($p['contract_amount']) ?><?= (float)$p['co_amount'] != 0.0 ? ', change orders ' . money($p['co_amount']) . ', now ' . money($m['contract']) : '' ?>.
              Customer:Job <?= $p['job_customer_id'] ? safe_link('cust.center', ['id' => $p['job_customer_id']], (string)$p['job_name']) : '' ?><?= $p['class_name'] ? ', class ' . h($p['class_name']) : '' ?>.</div>
            <?php $ro = !$canEdit || $p['status'] === 'closed' ? 'disabled' : ''; ?>
            <?= input_field('contract_no', 'Contract no.', $p['contract_no'], ['class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('po_no', 'Purchase order no.', $p['po_no'], ['class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('noa_ack_date', 'Acknowledged (conforme)', $p['noa_ack_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('contract_signed_date', 'Contract signed', $p['contract_signed_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('ntp_date', 'Notice to proceed', $p['ntp_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('ntp_received_date', 'NTP received', $p['ntp_received_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('start_date', 'Start', $p['start_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('due_date', 'Due', $p['due_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('completion_date', 'Completed', $p['completion_date'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('warranty_months', 'Warranty (months)', $p['warranty_months'], ['type' => 'number', 'step' => '1', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('warranty_until', 'Warranty until', $p['warranty_until'], ['type' => 'date', 'class' => 'col-md-4', 'extra' => $ro]) ?>
            <?= input_field('payment_terms', 'Payment terms', $p['payment_terms'], ['class' => 'col-md-4', 'extra' => $ro]) ?>
          </div><?php if (!$ro): ?><div class="card-footer d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save contract details</button>
            <?php if ($p['status'] === 'awarded'): ?><button type="button" class="btn btn-outline-danger btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#mAwardCancel">Award cancelled by the entity</button><?php endif; ?></div><?php endif; ?></form></div>
        <div class="col-xl-5"><div class="card mb-3"><div class="card-header"><i class="bi bi-shield-check"></i> Securities</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Security</th><th class="num">Amount</th><th>Valid until</th><th>Status</th></tr></thead>
          <?php foreach ($secs as $s): ?><tr><td class="small wrap-link"><?= h(Brgy::label(Brgy::SECURITY_TYPES, $s['security_type'])) ?> <?= h($s['security_no']) ?><div class="text-muted"><?= h(Brgy::label(Brgy::SECURITY_FORMS, $s['form'])) ?><?= $s['issuer'] ? ', ' . h($s['issuer']) : '' ?><?= $s['reference_no'] ? ' ' . h($s['reference_no']) : '' ?></div>
            <?= $s['posted_txn_id'] ? '<div>' . brgy_txn($s['posted_txn_id']) . '</div>' : '' ?><?= $s['doc_id'] ? '<div>' . brgy_doc_link($s['doc_id'], 'paper') . '</div>' : '' ?></td>
            <td class="num"><?= money($s['amount'], true) ?></td><td class="small <?= $s['status'] === 'active' && $s['valid_until'] && $s['valid_until'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($s['valid_until'])) ?></td>
            <td><?= Brgy::st($s['status']) ?><?php if ($s['status'] === 'active' && $canEdit): ?><div><button class="btn btn-sm btn-link p-0" data-bs-toggle="modal" data-bs-target="#mSecClose" data-security="<?= (int)$s['id'] ?>">Returned / forfeited</button></div><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$secs): ?><tr><td colspan="4" class="text-muted">None recorded.</td></tr><?php endif; ?>
        </table></div><?php if ($canEdit && $p['status'] !== 'closed'): ?><div class="card-footer"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mSec"><i class="bi bi-plus-lg"></i> Record a security</button></div><?php endif; ?></div></div>
      </div>
      <?= brgy_reason_modal('mAwardCancel', 'Award cancelled by the entity', $self(), 'award_cancel', ['tab' => $tab], 'btn-danger', true, 'Only while nothing has been delivered, billed or collected.', brgy_file_field('evidence', 'The entity\'s notice (optional)')) ?>
    <?php else: ?>
      <div class="card mb-3"><div class="card-body text-muted">The award is recorded once the bid has been submitted and the entity issues its notice of award.</div></div>
      <?php if ($secs): ?><div class="card mb-3"><div class="card-header">Securities</div><div class="table-responsive"><table class="table table-sm mb-0"><?php foreach ($secs as $s): ?><tr><td><?= h(Brgy::label(Brgy::SECURITY_TYPES, $s['security_type']) . ' ' . $s['security_no']) ?></td><td><?= h(Brgy::label(Brgy::SECURITY_FORMS, $s['form'])) ?></td><td class="num"><?= money($s['amount'], true) ?></td><td><?= Brgy::st($s['status']) ?></td></tr><?php endforeach; ?></table></div></div><?php endif; ?>
      <?php if ($canEdit && $p['status'] === 'open'): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mSec"><i class="bi bi-plus-lg"></i> Record a bid security / declaration</button><?php endif; ?>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <div class="modal fade" id="mSec" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><form class="modal-content" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?>
        <input type="hidden" name="do" value="security_add"><?= $hiddenTab ?>
        <div class="modal-header"><h5 class="modal-title">Record a security</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-2">
          <?= select_field('security_type', 'Security', Brgy::SECURITY_TYPES, $awarded ? 'performance' : 'bid', ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
          <?= select_field('form', 'Form', Brgy::SECURITY_FORMS, 'managers_check', ['class' => 'col-md-4', 'extra' => 'data-no-combo id="secForm"']) ?>
          <?= input_field('amount', 'Amount', '', ['class' => 'col-md-4', 'extra' => 'inputmode="decimal"']) ?>
          <?= input_field('issuer', 'Issuer (bank, surety)', '', ['class' => 'col-md-4']) ?>
          <?= input_field('reference_no', 'Reference / check no.', '', ['class' => 'col-md-4']) ?>
          <?= input_field('issue_date', 'Issued', today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
          <?= input_field('valid_until', 'Valid until', '', ['type' => 'date', 'class' => 'col-md-2']) ?>
          <div class="col-md-6" data-toggle-by="#secForm" data-show-when="<?= h(implode(',', Brgy::SECURITY_CASH_FORMS)) ?>"><label class="form-label">Paid from (posts to Bid and Performance Security Deposits)</label>
            <select class="form-select form-select-sm" name="pay_account_id"><?= options(Accts::banks(), null, '') ?></select></div>
          <?= input_field('notes', 'Notes', '', ['class' => 'col-md-6']) ?>
          <?= brgy_file_field('file', 'The security / declaration (copy)') ?>
        </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Record</button></div></form></div></div>
      <div class="modal fade" id="mSecClose" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?>
        <input type="hidden" name="do" value="security_close"><input type="hidden" name="security_id" data-fill-security><?= $hiddenTab ?>
        <div class="modal-header"><h5 class="modal-title">Security returned or forfeited</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-2">
          <?= select_field('outcome', 'Outcome', ['released' => 'Returned by the entity', 'forfeited' => 'Forfeited'], 'released', ['class' => 'col-md-6', 'extra' => 'data-no-combo id="secOut"']) ?>
          <?= input_field('date', 'Date', today(), ['type' => 'date', 'class' => 'col-md-6']) ?>
          <div class="col-12" data-toggle-by="#secOut" data-show-when="released"><label class="form-label">Money back to (cash securities)</label><select class="form-select form-select-sm" name="bank_id"><?= options(Accts::banks(), null, '') ?></select></div>
          <div class="col-12"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" rows="2"></textarea></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
    <?php endif;

// ================================================================== deliveries
elseif ($tab === 'delivery'):
    $dels = DB::all('SELECT d.*, s.name AS site_name FROM brgy_deliveries d LEFT JOIN sites s ON s.id = d.from_site_id WHERE d.project_id = :p ORDER BY d.delivery_date DESC, d.id DESC', ['p' => $id]);
    ?>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-boxes"></i> Contract lines</span>
      <?= $p['status'] === 'awarded' ? page_link('brgy.deliveries', ['a' => 'new', 'project_id' => $id], 'New delivery / accomplishment', 'btn-primary', 'truck', 'create') : '' ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Line</th><th class="num">Contract</th><th class="num">Delivered</th><th class="num">Rejected</th><th class="num">Accepted</th><th class="num">Left to deliver</th><th class="num">Billed</th><th style="width:22%">Progress</th></tr></thead>
      <?php foreach ($lines as $l): $left = max(0, (float)$l['qty'] - ((float)$l['qty_delivered'] - (float)$l['qty_rejected'])); $pctA = (float)$l['qty'] > 0 ? min(100, (float)$l['qty_accepted'] / (float)$l['qty'] * 100) : 0; ?>
        <tr><td class="wrap-link small"><?= (int)$l['line_no'] ?>. <?= h($l['description']) ?> <span class="text-muted"><?= h($l['unit']) ?></span></td><td class="num"><?= qty($l['qty']) ?></td><td class="num"><?= qty($l['qty_delivered']) ?></td>
          <td class="num <?= (float)$l['qty_rejected'] > 0 ? 'text-danger' : '' ?>"><?= qty($l['qty_rejected']) ?></td><td class="num"><?= qty($l['qty_accepted']) ?></td><td class="num"><?= qty($left) ?></td><td class="num"><?= qty($l['qty_billed']) ?></td>
          <td><div class="prog"><span style="width:<?= round($pctA) ?>%"></span></div><div class="small text-muted"><?= round($pctA) ?>% accepted</div></td></tr>
      <?php endforeach; ?>
    </table></div></div>
    <div class="card"><div class="card-header"><i class="bi bi-truck"></i> Deliveries, accomplishments and progress reports</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>No.</th><th>Date</th><th>Kind</th><th>Received by</th><th>Acceptance</th><th>Status</th><th>Stock</th></tr></thead>
      <?php foreach ($dels as $d): ?><tr class="<?= $d['status'] === 'void' ? 'text-muted' : '' ?>"><td><a href="<?= h(url('brgy.deliveries', ['a' => 'view', 'id' => $d['id']])) ?>"><?= h($d['dr_no']) ?></a></td><td class="small"><?= h(fdate($d['delivery_date'])) ?><?= (int)$d['days_late'] > 0 ? '<div class="text-danger">' . (int)$d['days_late'] . ' day(s) late</div>' : '' ?></td>
        <td class="small"><?= h(ucfirst($d['kind'])) ?></td><td class="small"><?= h($d['received_by_name']) ?></td><td class="small"><?= h($d['acceptance_ref']) ?><?= $d['acceptance_date'] ? '<div class="text-muted">' . h(fdate($d['acceptance_date'])) . '</div>' : '' ?></td>
        <td><?= Brgy::st($d['status']) ?></td><td class="small"><?= brgy_txn($d['transfer_txn_id']) ?><?= $d['return_txn_id'] ? '<div>' . brgy_txn($d['return_txn_id']) . '</div>' : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$dels): ?><tr><td colspan="7" class="text-muted py-3">No deliveries yet.</td></tr><?php endif; ?>
    </table></div></div>
    <?php

// ================================================================== change orders
elseif ($tab === 'changes'):
    $cos = DB::all('SELECT * FROM brgy_change_orders WHERE project_id = :p ORDER BY id DESC', ['p' => $id]);
    foreach ($cos as $co):
        $coLines = DB::all('SELECT * FROM brgy_co_lines WHERE co_id = :c ORDER BY id', ['c' => $co['id']]); ?>
      <div class="card mb-3"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><span><b><?= h($co['co_no']) ?></b> <?= h($co['title']) ?> <span class="small text-muted"><?= h(fdate($co['co_date'])) ?></span></span><span><?= Brgy::st($co['status']) ?></span></div>
        <div class="card-body">
          <?php if ($co['reason']): ?><p class="small mb-2"><?= nl2br(h($co['reason'])) ?></p><?php endif; ?>
          <div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Line</th><th class="num">Qty change</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
            <?php foreach ($coLines as $l): ?><tr><td class="small"><?= $l['project_line_id'] ? '' : '<span class="text-info">new:</span> ' ?><?= h($l['description']) ?> <span class="text-muted"><?= h($l['unit']) ?></span></td><td class="num"><?= ($l['qty_change'] > 0 ? '+' : '') . qty($l['qty_change']) ?></td><td class="num"><?= money($l['unit_price']) ?></td><td class="num"><?= money($l['amount']) ?></td></tr><?php endforeach; ?>
            <tfoot><tr><td class="text-end" colspan="3">Change in the contract amount<?= (int)$co['days_extension'] ? ', ' . (int)$co['days_extension'] . ' day(s) extension' : '' ?></td><td class="num"><?= money($co['amount_change']) ?></td></tr></tfoot></table></div>
          <?php if ($co['status'] === 'applied'): ?><div class="small">Applied <?= h(fdatetime($co['applied_at'])) ?>, entity approval <b><?= h($co['external_ref']) ?></b> of <?= h(fdate($co['external_date'])) ?> <?= brgy_doc_link($co['evidence_doc_id'], 'evidence') ?></div>
          <?php else: ?>
            <?= brgy_step_buttons('change_order', $co, $p, $self(), 'co', ['tab' => $tab, 'co_id' => $co['id']]) ?>
            <?php if ($co['status'] === 'approved' && $canEdit): ?>
              <form class="row g-2 mt-2 apply-co" method="post" action="<?= h($self()) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="co_apply"><input type="hidden" name="co_id" value="<?= (int)$co['id'] ?>"><?= $hiddenTab ?>
                <div class="col-12 small">Approved internally. It changes the contract only once the entity's own approval (variation order, amended PO, extension) is on file:</div>
                <?= input_field('external_ref', 'Entity\'s approval no.', '', ['class' => 'col-md-3', 'required' => true]) ?>
                <?= input_field('external_date', 'Dated', '', ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
                <?= brgy_file_field('evidence', 'The entity\'s approval', '', 'col-md-6') ?>
                <div class="col-12"><button class="btn btn-sm btn-success"><i class="bi bi-check2-all"></i> Apply to the contract</button></div></form>
            <?php endif; ?>
          <?php endif; ?>
        </div></div>
    <?php endforeach; ?>
    <?php if (!$cos): ?><div class="card mb-3"><div class="card-body text-muted">No change orders.</div></div><?php endif; ?>
    <?php if ($canEdit && $p['status'] === 'awarded'): ?>
      <form class="card" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="co_save"><?= $hiddenTab ?>
        <div class="card-header"><i class="bi bi-plus-lg"></i> New change order</div><div class="card-body row g-2">
          <?= input_field('title', 'Title', '', ['class' => 'col-md-6', 'required' => true]) ?>
          <?= input_field('co_date', 'Date', today(), ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('days_extension', 'Days of extension', '0', ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
          <div class="col-12"><label class="form-label">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2"></textarea></div>
          <div class="col-12"><div class="table-responsive"><table class="table table-sm line-free mb-1" id="coGrid"><thead><tr><th style="min-width:240px">Contract line (blank = a new line)</th><th style="min-width:200px">New line description</th><th style="width:80px">Unit</th><th class="num" style="width:110px">Qty change (+/-)</th><th class="num" style="width:120px">Unit price (new line)</th><th></th></tr></thead><tbody>
            <?php for ($i = 0; $i < 3; $i++): ?><tr><td><select class="form-select form-select-sm" name="co_lines[<?= $i ?>][project_line_id]" data-no-combo><option value=""></option><?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['line_no'] . '. ' . mb_strimwidth($l['description'], 0, 60, '...') . ' (' . qty($l['qty']) . ' ' . $l['unit'] . ' at ' . money($l['unit_price']) . ')') ?></option><?php endforeach; ?></select></td>
              <td><input class="form-control form-control-sm" name="co_lines[<?= $i ?>][description]" maxlength="255"></td><td><input class="form-control form-control-sm" name="co_lines[<?= $i ?>][unit]" maxlength="20"></td>
              <td><input class="form-control form-control-sm text-end" name="co_lines[<?= $i ?>][qty_change]" inputmode="decimal"></td><td><input class="form-control form-control-sm text-end" name="co_lines[<?= $i ?>][unit_price]" inputmode="decimal"></td><td></td></tr><?php endfor; ?>
          </tbody></table></div><div class="form-text">An existing line keeps its contract price; a new line needs its description, unit, quantity and price.</div></div>
        </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save as draft</button></div></form>
    <?php endif;

// ================================================================== billing and collection
elseif ($tab === 'billing'):
    $bills = DB::all('SELECT b.*, t.txn_no, t.total AS inv_total, t.is_voided AS inv_void, t.is_pending, t.id AS tid, t.due_date AS inv_due FROM brgy_billings b LEFT JOIN transactions t ON t.id = b.invoice_txn_id WHERE b.project_id = :p ORDER BY b.billing_date DESC, b.id DESC', ['p' => $id]);
    $cols = DB::all('SELECT c.*, b.billing_no FROM brgy_collections c LEFT JOIN brgy_billings b ON b.id = c.billing_id WHERE c.project_id = :p ORDER BY c.collection_date DESC, c.id DESC', ['p' => $id]);
    echo brgy_figures(['Accepted, not billed' => [money($m['unbilled_accepted']), $m['unbilled_accepted'] > 0 ? 'warn' : ''], 'Billed' => [money($m['billed']), ''], 'Cash collected' => [money($m['cash']), 'ok'],
        'Taxes withheld' => [money($m['taxes']), ''], 'Receivable (ledger)' => [money($m['ar_open']), $m['ar_open'] > 0 ? 'warn' : ''], 'Retention held' => [money($m['retention_held']), $m['retention_held'] > 0 ? 'info' : '']]);
    ?>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-receipt"></i> Billing packs</span>
      <span class="d-flex gap-1"><?= $p['status'] === 'awarded' ? page_link('brgy.billing', ['a' => 'new', 'project_id' => $id], 'New billing pack', 'btn-primary', 'plus-lg', 'create') : '' ?></span></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Billing</th><th>Date</th><th class="num">Amount</th><th>Status</th><th>Invoice</th><th class="num">Open</th><th>Pack</th></tr></thead>
      <?php foreach ($bills as $b): $open = $b['tid'] && !(int)$b['inv_void'] ? Balances::open(['id' => $b['tid'], 'total' => $b['inv_total'], 'is_voided' => $b['inv_void'], 'is_pending' => $b['is_pending']]) : null;
          $pack = $b['status'] !== 'void' ? BrgyOps::packStatus($b) : []; $okN = count(array_filter($pack, fn($x) => $x['ok'])); ?>
        <tr class="<?= $b['status'] === 'void' ? 'text-muted' : '' ?>"><td><a href="<?= h(url('brgy.billing', ['a' => 'view', 'id' => $b['id']])) ?>"><?= h($b['billing_no']) ?></a><div class="small text-muted"><?= h(Brgy::label(Brgy::BILLING_TYPES, $b['billing_type'])) ?><?= $b['si_no'] ? ', SI ' . h($b['si_no']) : '' ?></div></td>
          <td class="small"><?= h(fdate($b['billing_date'])) ?></td><td class="num"><?= money($b['gross_amount']) ?></td><td><?= Brgy::st($b['status']) ?></td>
          <td class="small"><?= $b['tid'] ? brgy_txn($b['tid']) . ($b['inv_due'] ? '<div class="text-muted">due ' . h(fdate($b['inv_due'])) . '</div>' : '') : '' ?></td>
          <td class="num <?= $open && $b['inv_due'] && $b['inv_due'] < today() ? 'text-danger' : '' ?>"><?= $open !== null ? money($open) : '' ?></td>
          <td class="small"><?= $pack ? $okN . ' of ' . count($pack) : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$bills): ?><tr><td colspan="7" class="text-muted py-3">No billings yet. A billing pack is made for quantities the entity accepted.</td></tr><?php endif; ?>
    </table></div></div>
    <div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-cash-coin"></i> Collections and retention</span>
      <span class="d-flex gap-1"><?= $p['status'] === 'awarded' || $m['retention_held'] > 0 ? page_link('brgy.collections', ['a' => 'new', 'project_id' => $id], 'Record collection', 'btn-primary', 'plus-lg', 'create')
          . ($m['retention_held'] > 0 ? ' ' . page_link('brgy.collections', ['a' => 'new', 'project_id' => $id, 'kind' => 'retention'], 'Retention released', 'btn-outline-primary', 'piggy-bank', 'create') : '') : '' ?></span></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Collection</th><th>Date</th><th>Billing</th><th class="num">Settled</th><th class="num">Cash</th><th class="num">Taxes</th><th class="num">Retention</th><th class="num">Other</th><th>2307</th><th>Status</th></tr></thead>
      <?php foreach ($cols as $c): ?><tr class="<?= $c['status'] !== 'posted' ? 'text-muted' : '' ?>"><td><a href="<?= h(url('brgy.collections', ['a' => 'view', 'id' => $c['id']])) ?>"><?= h($c['collection_no']) ?></a><div class="small text-muted"><?= h(Brgy::label(Brgy::COLLECTION_METHODS, $c['method'])) ?> <?= h($c['check_no'] ?: $c['reference_no']) ?></div></td>
        <td class="small"><?= h(fdate($c['collection_date'])) ?></td><td class="small"><?= $c['kind'] === 'retention' ? 'Retention release' : h($c['billing_no']) ?></td><td class="num"><?= money($c['gross_applied'], true) ?></td><td class="num"><?= money($c['cash_received']) ?></td>
        <td class="num"><?= money((float)$c['ewt_amount'] + (float)$c['vatwh_amount'], true) ?></td><td class="num"><?= money($c['retention_amount'], true) ?></td><td class="num"><?= money((float)$c['ld_amount'] + (float)$c['other_deduction'] + (float)$c['bank_charges'], true) ?></td>
        <td class="small"><?= $c['kind'] === 'billing' ? ((int)$c['cert_2307'] ? 'received' : '<span class="text-warning-emphasis">pending</span>') : '' ?></td><td><?= Brgy::st($c['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$cols): ?><tr><td colspan="10" class="text-muted py-3">No collections yet.</td></tr><?php endif; ?>
    </table></div></div>
    <?php

// ================================================================== costs (job costing)
elseif ($tab === 'costs' && $costs):
    $docs = BrgyPost::costDocuments($p);
    echo brgy_figures(['Revenue (ledger)' => [money($m['revenue']), ''], 'Costs (ledger)' => [money($m['costs']), ''], 'Margin' => [money($m['margin']), $m['margin'] < 0 ? 'bad' : 'ok', $m['margin_pct'] !== null ? $m['margin_pct'] . '%' : ''],
        'Estimated cost (schedule)' => [money($m['est_cost']), '', 'cost to complete ' . money(max(0, $m['est_cost'] - $m['costs']))]]);
    ?>
    <p class="small text-muted">From the ledger: everything posted on the Customer:Job <b><?= h((string)$p['job_name']) ?></b>, and lines with the class <b><?= h((string)$p['class_name']) ?></b> and no customer. Purchases for the contract (bills, checks, cash vouchers) count when their lines name this job.</p>
    <div class="row g-3">
      <div class="col-xl-8"><div class="card"><div class="card-header"><i class="bi bi-bag"></i> Purchases and costs for this contract</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead><tr><th>Document</th><th>Date</th><th>Supplier / payee</th><th class="num">Total</th><th>Status</th></tr></thead>
        <?php foreach ($docs as $t): ?><tr class="<?= (int)$t['is_voided'] ? 'text-muted' : '' ?>"><td><?= Txn::link($t, Txn::label($t['txn_type']) . ' ' . $t['txn_no']) ?></td><td class="small"><?= h(fdate($t['txn_date'])) ?></td><td class="small"><?= h($t['vendor_name'] ?: $t['payee_name']) ?></td>
          <td class="num"><?= money($t['total']) ?></td><td><?= status_badge((int)$t['is_voided'] ? 'void' : $t['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$docs): ?><tr><td colspan="5" class="text-muted py-3">No purchases on this contract yet.</td></tr><?php endif; ?>
      </table></div></div></div>
      <div class="col-xl-4"><?php if ($canEdit && $p['status'] === 'awarded' && (Access::can('vend.purchase_orders', 'create') || Access::isAdmin())):
          $items = DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM items WHERE is_active = 1 AND item_type IN ('inventory','non_inventory','service','assembly','other_charge') ORDER BY code"); ?>
        <form class="card" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="po_create"><?= $hiddenTab ?>
          <div class="card-header"><i class="bi bi-cart-plus"></i> Purchase order for this contract</div><div class="card-body row g-2">
            <?= select_field('vendor_id', 'Supplier / subcontractor', Lookup::vendors(), null, ['blank' => '', 'class' => 'col-12', 'required' => true]) ?>
            <?= input_field('po_date', 'Date', today(), ['type' => 'date', 'class' => 'col-6']) ?>
            <?= input_field('memo', 'Memo', '', ['class' => 'col-6']) ?>
            <?php foreach ($lines as $i => $l): if ($i > 7) { break; } ?>
              <div class="col-12 po-line"><div class="small text-muted"><?= h($l['description']) ?></div><div class="row g-1">
                <div class="col-6"><select class="form-select form-select-sm" name="po_lines[<?= $i ?>][item_id]"><?= options($items, $l['item_id'], 'item') ?></select></div>
                <div class="col-3"><input class="form-control form-control-sm text-end" name="po_lines[<?= $i ?>][qty]" placeholder="qty" inputmode="decimal"></div>
                <div class="col-3"><input class="form-control form-control-sm text-end" name="po_lines[<?= $i ?>][rate]" placeholder="cost" value="<?= (float)$l['unit_cost'] > 0 ? h(number_format((float)$l['unit_cost'], 2, '.', '')) : '' ?>" inputmode="decimal"></div>
                <input type="hidden" name="po_lines[<?= $i ?>][description]" value="<?= h($l['description']) ?>"></div></div>
            <?php endforeach; ?>
            <div class="col-12 form-text">Lines with a quantity become the purchase order, each on this contract's job and class. Receive and bill it in Vendors as usual; costs post when it is billed.</div>
          </div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Make the purchase order</button></div></form>
      <?php endif; ?></div>
    </div>
    <?php

// ================================================================== tasks
elseif ($tab === 'tasks'):
    $tasks = DB::all("SELECT t.*, u.full_name FROM brgy_tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.project_id = :p ORDER BY FIELD(t.status, 'open', 'in_progress', 'done', 'cancelled'), t.due_date IS NULL, t.due_date, t.id DESC", ['p' => $id]);
    $edit = gint('task') ? DB::row('SELECT * FROM brgy_tasks WHERE id = :id AND project_id = :p', ['id' => gint('task'), 'p' => $id]) : null;
    ?>
    <div class="row g-3">
      <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead><tr><th>Kind</th><th>Task</th><th>Assigned</th><th>Due</th><th>Progress</th><th>Status</th></tr></thead>
        <?php foreach ($tasks as $t): $late = in_array($t['status'], ['open', 'in_progress'], true) && $t['due_date'] && $t['due_date'] < today(); ?>
          <tr><td class="small"><?= h(Brgy::label(Brgy::TASK_TYPES, $t['task_type'])) ?></td><td class="wrap-link small"><?= $canEdit ? '<a href="' . h($self(['task' => $t['id']])) . '">' . h($t['title']) . '</a>' : h($t['title']) ?><?= $t['details'] ? '<div class="text-muted">' . h(mb_strimwidth($t['details'], 0, 140, '...')) . '</div>' : '' ?></td>
            <td class="small"><?= h($t['full_name']) ?></td><td class="small <?= $late ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($t['due_date'])) ?></td>
            <td><?php if ($t['pct_complete'] !== null): ?><div class="prog"><span style="width:<?= (float)$t['pct_complete'] ?>%"></span></div><?php endif; ?></td><td><?= Brgy::st($t['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$tasks): ?><tr><td colspan="6" class="text-muted py-3">No tasks, milestones, risks or defects yet.</td></tr><?php endif; ?>
      </table></div></div></div>
      <div class="col-xl-4"><?php if ($canEdit): ?>
        <form class="card" method="post" action="<?= h($self()) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="task_save"><?= $hiddenTab ?><?= $edit ? '<input type="hidden" name="task_id" value="' . (int)$edit['id'] . '">' : '' ?>
          <div class="card-header"><i class="bi bi-list-task"></i> <?= $edit ? 'Edit' : 'Add' ?> a task, milestone, risk or defect</div><div class="card-body row g-2">
            <?= select_field('task_type', 'Kind', Brgy::TASK_TYPES, $edit['task_type'] ?? 'task', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= select_field('status', 'Status', ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'], $edit['status'] ?? 'open', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
            <?= input_field('title', 'Title', $edit['title'] ?? '', ['class' => 'col-12', 'required' => true]) ?>
            <div class="col-12"><label class="form-label">Details</label><textarea class="form-control form-control-sm" name="details" rows="2"><?= h($edit['details'] ?? '') ?></textarea></div>
            <?= brgy_user_select('assigned_to', 'Assigned to', $edit['assigned_to'] ?? null, 'col-6', '') ?>
            <?= input_field('due_date', 'Due', $edit['due_date'] ?? '', ['type' => 'date', 'class' => 'col-6']) ?>
            <?= input_field('pct_complete', '% complete', $edit['pct_complete'] ?? '', ['class' => 'col-6', 'extra' => 'inputmode="decimal"']) ?>
          </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button><?= $edit ? btn_link($self(['task' => null]), 'Cancel') : '' ?></div></form>
      <?php endif; ?></div>
    </div>
    <?php

// ================================================================== timeline
elseif ($tab === 'timeline'):
    $tl = Brgy::timeline($id); ?>
    <div class="card"><div class="card-body"><ol class="timeline">
      <?php foreach ($tl as $t): ?><li class="tl-<?= h($t['kind']) ?>"><span class="tl-ico"><i class="bi bi-<?= h($t['icon']) ?>"></i></span><div class="tl-body">
        <div class="tl-when"><?= h(fdatetime($t['at'])) ?><?= $t['by'] ? ', ' . h($t['by']) : '' ?></div><div class="tl-title"><?= h($t['title']) ?></div>
        <?= $t['sub'] ? '<div class="small text-muted">' . h($t['sub']) . '</div>' : '' ?><?= $t['text'] ? '<div class="small pre-text">' . h($t['text']) . '</div>' : '' ?><?= $t['doc'] ? '<div class="small">' . brgy_doc_link($t['doc']) . '</div>' : '' ?></div></li>
      <?php endforeach; ?>
      <?php if (!$tl): ?><li class="text-muted">Nothing yet.</li><?php endif; ?>
    </ol></div></div>
    <?php

// ================================================================== ledger
elseif ($tab === 'ledger'):
    $posts = BrgyPost::forProject($id);
    $rec = $awarded ? (BrgyPost::reconcile($id)[0] ?? null) : null; ?>
    <?php if ($rec): ?><div class="alert <?= $rec['issues'] ? 'alert-danger' : 'alert-success' ?> small"><?= $rec['issues'] ? '<b>Differences with the ledger:</b><ul class="mb-0">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $rec['issues'])) . '</ul>'
        : 'The contract agrees with the ledger: billed ' . money($rec['billed_ledger']) . ', applied ' . money($rec['applied_ledger']) . ', retention ' . money($rec['retention_ledger']) . '.' ?></div><?php endif; ?>
    <p class="small text-muted">Every entry the contract put in the books, by the record and event it came from. Each source and event has one live entry; posting it twice is refused.</p>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Posted</th><th>Source</th><th>Event</th><th>Transaction</th><th>Date</th><th class="num">Amount</th><th>Status</th></tr></thead>
      <?php foreach ($posts as $x):
          $src = ['delivery' => ['brgy_deliveries', 'dr_no', 'brgy.deliveries'], 'billing' => ['brgy_billings', 'billing_no', 'brgy.billing'], 'collection' => ['brgy_collections', 'collection_no', 'brgy.collections'], 'security' => ['brgy_securities', 'security_no', null], 'purchase' => [null, null, null]][$x['source_type']] ?? [null, null, null];
          $srcNo = $src[0] ? (string)DB::value("SELECT `{$src[1]}` FROM `{$src[0]}` WHERE id = :id", ['id' => $x['source_id']]) : ''; ?>
        <tr class="<?= $x['status'] !== 'posted' ? 'text-muted' : '' ?>"><td class="small"><?= h(fdatetime($x['posted_at'])) ?></td>
          <td class="small"><?= h(ucfirst($x['source_type'])) ?> <?= $src[2] && $srcNo ? '<a href="' . h(url($src[2], ['a' => 'view', 'id' => $x['source_id']])) . '">' . h($srcNo) . '</a>' : h($srcNo) ?></td>
          <td class="small"><?= h($x['event']) ?></td><td class="small"><?= brgy_txn($x['txn_id']) ?></td><td class="small"><?= h(fdate($x['txn_date'])) ?></td><td class="num"><?= money($x['amount'], true) ?></td>
          <td><?= Brgy::st($x['status'] === 'posted' ? ((int)$x['is_voided'] ? 'void' : 'posted') : 'void', $x['status'] === 'posted' ? ((int)$x['is_voided'] ? 'Voided in accounting' : 'Posted') : 'Reversed') ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$posts): ?><tr><td colspan="7" class="text-muted py-3">Nothing posted yet. Winning a bid or receiving a notice of award posts nothing; the first entries come with deliveries (stock moves) and billing.</td></tr><?php endif; ?>
    </table></div></div>
    <?php
endif;
?>
</div>
<?php
layout_end();
