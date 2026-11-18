<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Deliveries & Acceptance
 * Goods delivered, services rendered and works accomplished, then the entity's inspection and acceptance from its own
 * report. Stocked goods move to the "delivered, awaiting acceptance" site when they leave, and rejected goods come back.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.deliveries';
$a = g('a', 'list');
$id = gint('id');

$loadDelivery = function (int $id): array {
    $d = DB::row('SELECT d.*, s.name AS site_name FROM brgy_deliveries d LEFT JOIN sites s ON s.id = d.from_site_id WHERE d.id = :id', ['id' => $id]);
    if (!$d) {
        flash('warning', 'Delivery not found.');
        redirect(url('brgy.deliveries'));
    }
    return $d;
};

// ------------------------------------------------------------------ print the delivery receipt / accomplishment report
if ($a === 'print' && $id) {
    Access::require($pageKey, 'print');
    $d = $loadDelivery($id);
    $p = Brgy::mustProject((int)$d['project_id']);
    $code = $d['kind'] === 'goods' ? 'delivery_receipt' : 'accomplishment_report';
    $t = DB::row('SELECT * FROM brgy_templates WHERE code = :c', ['c' => $code]) ?: DB::row("SELECT * FROM brgy_templates WHERE code = 'delivery_receipt'");
    if (!$t) {
        fail('The delivery receipt template is missing (Proforma & Form Templates).');
    }
    Brgy::log('print', 'delivery', $id, $d['dr_no'], ['page_key' => $pageKey]);
    $L = BrgyLayout::layout($t);
    brgy_print_page($d['dr_no'] . ' ' . $t['name'], BrgyDocs::render($t['body'], BrgyDocs::context($p, ['delivery', $id], $d['dr_no'], $L)), $L['paper'], $L['orientation'], false, url($pageKey, ['a' => 'view', 'id' => $id]), '', $L);
}

// ------------------------------------------------------------------ actions on a delivery
if ($a === 'view' && $id && is_post()) {
    $do = (string)p('do');
    brgy_action(function () use ($do, $id) {
        switch ($do) {
            case 'inspect':
                BrgyOps::recordInspection($id, $_POST + ['lines' => (array)($_POST['lines'] ?? [])], brgy_file('evidence'), p('lock_version'));
                return 'Inspection and acceptance recorded. Rejected stocked goods were moved back to the warehouse.';
            case 'void':
                BrgyOps::voidDelivery($id, trim((string)p('remarks')));
                return 'Delivery voided; its stock movements were reversed.';
            case 'form':
                $d = DB::row('SELECT project_id, kind FROM brgy_deliveries WHERE id = :id', ['id' => $id]);
                $tid = (int)DB::value('SELECT id FROM brgy_templates WHERE code = :c AND is_active = 1', ['c' => p('template', 'delivery_receipt')]);
                $fid = BrgyOps::createForm($tid, (int)$d['project_id'], ['delivery', $id]);
                redirect(url('brgy.forms', ['a' => 'edit', 'id' => $fid]));
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['a' => 'view', 'id' => $id]));
}

// ------------------------------------------------------------------ new / edit
if (in_array($a, ['new', 'edit'], true)) {
    $d = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $d = $loadDelivery((int)$id);
        if ($d['status'] !== 'delivered') {
            flash('warning', 'Only a delivery that has not been inspected yet can be changed.');
            redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $projectId = $d ? (int)$d['project_id'] : (gint('project_id') ?: (int)p('project_id'));
    handle_post(function () use ($d, $pageKey) {
        $newId = BrgyOps::saveDelivery($_POST + ['lines' => (array)($_POST['lines'] ?? [])], $d ? (int)$d['id'] : null, p('lock_version'));
        flash('success', $d ? 'Delivery saved.' : 'Delivery recorded' . (DB::value("SELECT transfer_txn_id FROM brgy_deliveries WHERE id = :id", ['id' => $newId]) ? '; the goods were moved to the "delivered, awaiting acceptance" site' : '') . '. Record the entity\'s inspection when its report comes.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $newId]));
    });
    $projects = DB::pairs("SELECT p.id, CONCAT(p.project_no, ' - ', LEFT(p.title, 70), ' (', e.official_name, ')') FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id
                            WHERE p.status = 'awarded' AND p.phase IN ('award', 'fulfillment', 'delivery', 'billing', 'collection') ORDER BY p.project_no DESC");
    $p = $projectId ? Brgy::project($projectId) : null;
    layout_start($d ? 'Edit ' . $d['dr_no'] : 'New delivery / accomplishment', brgy_layout(['actions' => btn_link($d ? url($pageKey, ['a' => 'view', 'id' => $d['id']]) : ($p ? url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'delivery']) : url($pageKey)), 'Cancel')]));
    if (!$p): ?>
      <form method="get" class="card card-body" style="max-width:720px"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="new">
        <?= select_field('project_id', 'Contract delivered on', $projects, null, ['blank' => '', 'class' => 'col-12', 'required' => true, 'extra' => 'data-combo']) ?>
        <div class="mt-2"><button class="btn btn-primary btn-sm">Continue</button></div>
        <?php if (!$projects): ?><p class="small text-muted mt-2 mb-0">No awarded contract is in fulfilment. Deliveries are recorded once the award is in and fulfilment has started.</p><?php endif; ?>
      </form>
    <?php layout_end(); return; endif;
    Brgy::refreshLines((int)$p['id']);
    $lines = Brgy::lines((int)$p['id']);
    $mine = [];
    if ($d) {
        foreach (DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $d['id']]) as $x) {
            $mine[(int)$x['project_line_id']] = $x;
        }
    }
    $kindDefault = $p['category'] === 'goods' ? 'goods' : ($p['category'] === 'works' ? 'works' : 'service');
    ?>
    <form method="post" class="brgy-form" data-no-class-check><?= csrf_field() ?><input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>"><?= $d ? Brgy::versionField($d['lock_version']) : '' ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-truck"></i> <?= h($p['project_no']) ?> <?= h(mb_strimwidth($p['title'], 0, 80, '...')) ?></div><div class="card-body row g-2">
      <div class="col-12 small text-muted"><?= h($p['entity_name']) ?>. Due <?= h(fdate($p['due_date']) ?: 'not set') ?><?= $p['due_date'] && $p['due_date'] < today() ? ' <span class="text-danger">(' . h(brgy_rel($p['due_date'])) . ')</span>' : '' ?>.</div>
      <?= input_field('delivery_date', 'Date delivered / accomplished', fv('delivery_date', $d, today()), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
      <?= select_field('kind', 'Kind', ['goods' => 'Goods delivered', 'service' => 'Services rendered', 'works' => 'Works accomplished (progress)'], fv('kind', $d, $kindDefault), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('from_site_id', 'Goods leave from', Lookup::sites(), fv('from_site_id', $d, $p['site_id']), ['blank' => '', 'class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= input_field('vehicle', 'Vehicle / plate no.', fv('vehicle', $d), ['class' => 'col-md-3']) ?>
      <?= input_field('delivered_to', 'Delivered to', fv('delivered_to', $d, $p['delivery_site']), ['class' => 'col-md-6']) ?>
      <?= input_field('received_by_name', 'Received by (entity)', fv('received_by_name', $d), ['class' => 'col-md-3']) ?>
      <?= input_field('received_position', 'Position', fv('received_position', $d), ['class' => 'col-md-3']) ?>
      <?= input_field('notes', 'Notes', fv('notes', $d), ['class' => 'col-12']) ?>
    </div></div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-boxes"></i> What was delivered</span>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-fill-all="#dlvLines"><i class="bi bi-arrow-down-square"></i> Everything left</button></div>
      <div class="table-responsive"><table class="table table-sm mb-0 dlv-grid" id="dlvLines">
        <thead><tr><th>Line</th><th class="num">Contract</th><th class="num">Out (net of rejections)</th><th class="num">Left</th><th class="num" style="width:120px">This delivery</th><th style="min-width:160px">Serial / batch</th><th style="min-width:140px">Remarks</th></tr></thead>
        <?php foreach ($lines as $l):
            $own = isset($mine[(int)$l['id']]) ? (float)$mine[(int)$l['id']]['qty'] : 0.0;
            $out = (float)$l['qty_delivered'] - (float)$l['qty_rejected'] - $own;
            $left = max(0, round((float)$l['qty'] - $out, 4));
            $val = is_post() ? (string)($_POST['lines'][$l['id']]['qty'] ?? '') : ($own ? rtrim(rtrim(number_format($own, 4, '.', ''), '0'), '.') : ''); ?>
          <tr class="<?= $left <= 0 && !$own ? 'text-muted' : '' ?>"><td class="wrap-link small"><?= (int)$l['line_no'] ?>. <?= h($l['description']) ?> <span class="text-muted"><?= h($l['unit']) ?></span><?= $l['item_code'] ? '<div class="text-muted">' . h($l['item_code']) . '</div>' : '' ?></td>
            <td class="num"><?= qty($l['qty']) ?></td><td class="num"><?= qty($out) ?></td><td class="num"><?= qty($left) ?></td>
            <td><input class="form-control form-control-sm text-end" name="lines[<?= (int)$l['id'] ?>][qty]" value="<?= h($val) ?>" inputmode="decimal" data-max="<?= h((string)$left) ?>" <?= $left <= 0 && !$own ? 'disabled' : '' ?>></td>
            <td><input class="form-control form-control-sm" name="lines[<?= (int)$l['id'] ?>][serial_batch]" maxlength="255" value="<?= h(is_post() ? (string)($_POST['lines'][$l['id']]['serial_batch'] ?? '') : (string)($mine[(int)$l['id']]['serial_batch'] ?? '')) ?>"></td>
            <td><input class="form-control form-control-sm" name="lines[<?= (int)$l['id'] ?>][remarks]" maxlength="255" value="<?= h(is_post() ? (string)($_POST['lines'][$l['id']]['remarks'] ?? '') : (string)($mine[(int)$l['id']]['remarks'] ?? '')) ?>"></td></tr>
        <?php endforeach; ?>
      </table></div>
      <div class="card-body py-2 small text-muted">A line cannot go beyond what is left of the contract; more needs an approved change order. Stocked items leave the chosen site now and are recorded as sold only when billed.</div></div>
    <div class="sticky-actions"><?= save_buttons($d ? 'Save delivery' : 'Record delivery', $d ? url($pageKey, ['a' => 'view', 'id' => $d['id']]) : url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'delivery']), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ view
if ($a === 'view' && $id) {
    $d = $loadDelivery($id);
    $p = Brgy::mustProject((int)$d['project_id']);
    $dl = DB::all('SELECT dl.*, pl.line_no, pl.qty AS contract_qty FROM brgy_delivery_lines dl JOIN brgy_project_lines pl ON pl.id = dl.project_line_id WHERE dl.delivery_id = :d ORDER BY pl.line_no, dl.id', ['d' => $id]);
    $canEdit = Access::can($pageKey, 'edit');
    $actions = ($d['status'] === 'delivered' && $canEdit ? btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
        . page_link($pageKey, ['a' => 'print', 'id' => $id], $d['kind'] === 'goods' ? 'Print delivery receipt' : 'Print accomplishment report', 'btn-outline-secondary', 'printer', 'print') . ' '
        . ($d['status'] !== 'void' && Access::can($pageKey, 'delete') ? '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mVoid"><i class="bi bi-x-circle"></i> Void</button> ' : '')
        . btn_link(url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'delivery']), $p['project_no'], 'btn-outline-secondary', 'briefcase');
    layout_start($d['dr_no'] . ' ' . ($d['kind'] === 'goods' ? 'Delivery' : ($d['kind'] === 'works' ? 'Works accomplishment' : 'Service accomplishment')), brgy_layout(['actions' => $actions]));
    echo brgy_figures(['Status' => [Brgy::st($d['status'])], 'Delivered' => [h(fdate($d['delivery_date'])), (int)$d['days_late'] > 0 ? 'bad' : '', (int)$d['days_late'] > 0 ? (int)$d['days_late'] . ' day(s) after the due date' : 'on time'],
        'Value delivered' => [money(array_sum(array_map(fn($l) => r2((float)$l['qty'] * (float)DB::value('SELECT unit_price FROM brgy_project_lines WHERE id = :i', ['i' => $l['project_line_id']])), $dl)))],
        'Acceptance' => [h($d['acceptance_ref'] ?: 'not yet'), $d['acceptance_ref'] ? 'ok' : 'warn', $d['acceptance_date'] ? h(fdate($d['acceptance_date'])) : ($d['status'] === 'delivered' ? 'waiting ' . h(brgy_rel($d['delivery_date'])) : '')]]);
    ?>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-boxes"></i> Lines</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Line</th><th class="num">Delivered</th><th class="num">Accepted</th><th class="num">Rejected</th><th>Serial / batch</th><th>Remarks</th></tr></thead>
          <?php foreach ($dl as $l): ?><tr><td class="wrap-link small"><?= (int)$l['line_no'] ?>. <?= h($l['description']) ?> <span class="text-muted"><?= h($l['unit']) ?></span></td><td class="num"><?= qty($l['qty']) ?></td>
            <td class="num"><?= $d['status'] === 'delivered' ? '' : qty($l['qty_accepted']) ?></td><td class="num <?= (float)$l['qty_rejected'] > 0 ? 'text-danger' : '' ?>"><?= $d['status'] === 'delivered' ? '' : qty($l['qty_rejected']) ?></td>
            <td class="small"><?= h($l['serial_batch']) ?></td><td class="small"><?= h($l['remarks']) ?></td></tr><?php endforeach; ?>
        </table></div></div>

        <?php if ($d['status'] !== 'void' && $canEdit): ?>
        <form class="card mb-3" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="inspect"><?= Brgy::versionField($d['lock_version']) ?>
          <div class="card-header d-flex justify-content-between flex-wrap gap-2"><span><i class="bi bi-clipboard-check"></i> <?= $d['status'] === 'delivered' ? 'Record the entity\'s inspection and acceptance' : 'Inspection and acceptance (correct it if the report changed)' ?></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-accept-all="#inspLines">All accepted</button></div>
          <div class="card-body row g-2">
            <?= input_field('inspection_date', 'Inspected on', fv('inspection_date', $d, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
            <?= input_field('acceptance_date', 'Accepted on (report date)', fv('acceptance_date', $d, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
            <?= input_field('acceptance_ref', 'Inspection & acceptance report no.', fv('acceptance_ref', $d), ['class' => 'col-md-3']) ?>
            <?= input_field('inspectors', 'Inspected by', fv('inspectors', $d), ['class' => 'col-md-3']) ?>
            <div class="col-12"><div class="table-responsive"><table class="table table-sm mb-0" id="inspLines"><thead><tr><th>Line</th><th class="num">Delivered</th><th class="num" style="width:120px">Accepted</th><th class="num" style="width:120px">Rejected</th><th style="min-width:160px">Remarks</th></tr></thead>
              <?php foreach ($dl as $l): ?><tr><td class="small wrap-link"><?= (int)$l['line_no'] ?>. <?= h($l['description']) ?></td><td class="num" data-delivered="<?= h((string)(float)$l['qty']) ?>"><?= qty($l['qty']) ?></td>
                <td><input class="form-control form-control-sm text-end" name="lines[<?= (int)$l['id'] ?>][accepted]" inputmode="decimal" value="<?= $d['status'] === 'delivered' ? '' : h(rtrim(rtrim(number_format((float)$l['qty_accepted'], 4, '.', ''), '0'), '.')) ?>" data-acc></td>
                <td><input class="form-control form-control-sm text-end" name="lines[<?= (int)$l['id'] ?>][rejected]" inputmode="decimal" value="<?= $d['status'] === 'delivered' ? '' : h(rtrim(rtrim(number_format((float)$l['qty_rejected'], 4, '.', ''), '0'), '.')) ?>"></td>
                <td><input class="form-control form-control-sm" name="lines[<?= (int)$l['id'] ?>][remarks]" value="<?= h($l['remarks']) ?>" maxlength="255"></td></tr><?php endforeach; ?>
            </table></div></div>
            <?= brgy_file_field('evidence', 'The signed inspection and acceptance report (or the signed delivery receipt)', $d['acceptance_doc_id'] ? 'Already on file; upload only to replace it.' : 'Required for any quantity accepted.', 'col-md-7') ?>
            <div class="col-md-5"><?php if ($d['acceptance_doc_id']): ?><label class="form-label">On file</label><div><?= brgy_doc_link($d['acceptance_doc_id']) ?></div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label">Inspection notes</label><textarea class="form-control form-control-sm" name="inspection_notes" rows="2"><?= h(fv('inspection_notes', $d)) ?></textarea></div>
            <div class="col-md-6"><label class="form-label">Punch list / for replacement</label><textarea class="form-control form-control-sm" name="punch_list" rows="2"><?= h(fv('punch_list', $d)) ?></textarea></div>
            <div class="col-12 form-text">Acceptance is the entity's act, recorded from its report. Only accepted quantities can be billed; rejected stocked goods return to the warehouse they left from.</div>
          </div><div class="card-footer"><button class="btn btn-success btn-sm"><i class="bi bi-check2-all"></i> Save inspection result</button></div></form>
        <?php endif; ?>
      </div>
      <div class="col-xl-4">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-info-circle"></i> Details</div><div class="card-body"><dl class="dl-grid">
          <dt>Contract</dt><dd><?= brgy_project_link($p) ?> <?= h(mb_strimwidth($p['title'], 0, 60, '...')) ?></dd>
          <dt>Entity</dt><dd><?= h($p['entity_name']) ?></dd>
          <?php foreach (array_filter(['Delivered to' => $d['delivered_to'], 'From site' => $d['site_name'], 'Vehicle' => $d['vehicle'], 'Received by' => trim($d['received_by_name'] . ($d['received_position'] ? ', ' . $d['received_position'] : '')),
              'Inspected' => $d['inspection_date'] ? fdate($d['inspection_date']) . ($d['inspectors'] ? ' by ' . $d['inspectors'] : '') : '', 'Notes' => $d['notes']]) as $k => $v): ?>
            <dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd>
          <?php endforeach; ?>
          <?php if ($d['inspection_notes']): ?><dt>Inspection notes</dt><dd class="pre-text"><?= h($d['inspection_notes']) ?></dd><?php endif; ?>
          <?php if ($d['punch_list']): ?><dt>Punch list</dt><dd class="pre-text"><?= h($d['punch_list']) ?></dd><?php endif; ?>
          <dt>Stock</dt><dd><?= brgy_txn($d['transfer_txn_id']) ?: '<span class="text-muted">no stocked items</span>' ?><?= $d['return_txn_id'] ? '<div>' . brgy_txn($d['return_txn_id']) . ' (rejected, back)</div>' : '' ?></dd>
          <dt>Recorded</dt><dd><?= h(Brgy::userName((int)$d['created_by'])) ?>, <?= h(fdatetime($d['created_at'])) ?></dd>
        </dl></div></div>
        <?php if (Access::can('brgy.forms', 'create') && $d['status'] !== 'void'): ?>
          <form class="card" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="form">
            <div class="card-header"><i class="bi bi-file-earmark-richtext"></i> Prepare as a form</div><div class="card-body">
              <select class="form-select form-select-sm mb-2" name="template" data-no-combo><?= options(DB::pairs("SELECT code, name FROM brgy_templates WHERE is_active = 1 AND stage = 'delivery' ORDER BY sort_order, name"), $d['kind'] === 'goods' ? 'delivery_receipt' : 'accomplishment_report') ?></select>
              <div class="form-text">A form can be edited, approved and filed in the vault; the print button above prints the receipt as it is.</div></div>
            <div class="card-footer"><button class="btn btn-sm btn-outline-primary">Prepare</button></div></form>
        <?php endif; ?>
      </div>
    </div>
    <?= brgy_reason_modal('mVoid', 'Void the delivery', url($pageKey, ['a' => 'view', 'id' => $id]), 'void', [], 'btn-danger', true, 'The stock movements are reversed. A delivery already billed cannot be voided: void the billing first.') ?>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$fStatus = (string)g('status');
$fProject = gint('project_id');
$sql = 'SELECT d.*, p.project_no, p.title, e.official_name AS entity_name,
               (SELECT COALESCE(SUM(ROUND(dl.qty * pl.unit_price, 2)), 0) FROM brgy_delivery_lines dl JOIN brgy_project_lines pl ON pl.id = dl.project_line_id WHERE dl.delivery_id = d.id) AS value_delivered
          FROM brgy_deliveries d JOIN brgy_projects p ON p.id = d.project_id JOIN brgy_entities e ON e.id = p.entity_id WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (d.dr_no LIKE :q OR p.project_no LIKE :q OR p.title LIKE :q OR d.acceptance_ref LIKE :q OR e.official_name LIKE :q)';
    $params['q'] = "%$q%";
}
if ($fStatus === 'waiting') {
    $sql .= " AND d.status = 'delivered'";
} elseif (in_array($fStatus, ['delivered', 'accepted', 'partial', 'rejected', 'void'], true)) {
    $sql .= ' AND d.status = :s';
    $params['s'] = $fStatus;
}
if ($fProject) {
    $sql .= ' AND d.project_id = :p';
    $params['p'] = $fProject;
}
$rows = DB::all($sql . ' ORDER BY d.delivery_date DESC, d.id DESC LIMIT 500', $params);
layout_start('Deliveries & Acceptance', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'New delivery', 'btn-primary', 'plus-lg', 'create')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-5"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="DR no., project, acceptance report, entity" data-live-filter="#dlvTable tbody tr" data-live-enter-submits></div>
  <?= select_field('status', 'Status', ['waiting' => 'Waiting for acceptance', 'accepted' => 'Accepted', 'partial' => 'Partly accepted', 'rejected' => 'Rejected', 'void' => 'Void'], $fStatus, ['blank' => 'All', 'class' => 'col-md-3', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="dlvTable">
  <thead><tr><th>No.</th><th>Contract</th><th>Date</th><th>Received by</th><th>Acceptance</th><th class="num">Value</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] === 'void' ? 'text-muted' : '' ?>"><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= h($r['dr_no']) ?></b></a><div class="small text-muted"><?= h(ucfirst($r['kind'])) ?></div></td>
      <td class="wrap-link small"><?= brgy_project_link($r + ['project_id' => $r['project_id']]) ?> <?= h(mb_strimwidth($r['title'], 0, 60, '...')) ?><div class="text-muted"><?= h($r['entity_name']) ?></div></td>
      <td class="small text-nowrap"><?= h(fdate($r['delivery_date'])) ?><?= (int)$r['days_late'] > 0 ? '<div class="text-danger">' . (int)$r['days_late'] . ' day(s) late</div>' : '' ?></td>
      <td class="small"><?= h($r['received_by_name']) ?></td>
      <td class="small"><?= h($r['acceptance_ref']) ?><?= $r['acceptance_date'] ? '<div class="text-muted">' . h(fdate($r['acceptance_date'])) . '</div>' : ($r['status'] === 'delivered' ? '<div class="text-warning-emphasis">waiting ' . h(brgy_rel($r['delivery_date'])) . '</div>' : '') ?></td>
      <td class="num"><?= money($r['value_delivered']) ?></td><td><?= Brgy::st($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted py-4 text-center">No deliveries.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php
layout_end();
