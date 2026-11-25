<?php
declare(strict_types=1);

/**
 * Barangay Contracts > SOP Steps & Papers
 * The company's Standard Operating Procedure as the program follows it: the steps of the small value procurement and
 * of the bidding process (with its payment process flow), what each step asks, and the papers it handles. Changes
 * apply to every transaction still open (a new step or paper is added to them; nothing done is removed).
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.process';
$tab = (string)g('tab', 'svp');
if (!in_array($tab, ['svp', 'bidding', 'papers'], true)) {
    $tab = 'svp';
}
if (!BrgySop::ready()) {
    layout_start('SOP Steps & Papers', brgy_layout());
    echo '<div class="alert alert-warning">Run database/migrations/2026_11_03_barangay_sop_forms.sql first.</div>';
    layout_end();
    return;
}

if (is_post()) {
    $do = (string)p('do');
    brgy_action(function () use ($do) {
        $clip = fn(string $k, int $n) => mb_substr(trim((string)p($k)), 0, $n);
        // the types of transaction a step or paper is for: all of them (stored as none), or those ticked
        $forTypes = function (): ?string {
            $all = array_keys(BrgySop::txnTypes());
            $tt = array_values(array_intersect($all, (array)($_POST['txn_types'] ?? [])));
            if (!$tt) {
                fail('Tick the types of transaction it is for (all of them for every transaction).');
            }
            return count($tt) === count($all) ? null : implode(',', $tt);
        };
        switch ($do) {
            case 'step_save':
                $id = pint('step_id');
                Access::require('brgy.process', $id ? 'edit' : 'create');
                $process = in_array(p('process'), ['svp', 'bidding'], true) ? p('process') : 'svp';
                $papers = array_values(array_intersect(array_keys(BrgySop::papers()), (array)($_POST['papers'] ?? [])));
                $checks = array_values(array_filter(array_map(fn($x) => mb_substr(str_replace('|', '/', trim($x)), 0, 120), preg_split('/\R/', (string)p('checks')))));
                $row = ['title' => $clip('title', 160), 'section' => $clip('section', 4) ?: null, 'section_title' => $clip('section_title', 120) ?: null, 'instructions' => $clip('instructions', 3000) ?: null,
                    'papers' => $papers ? implode(',', $papers) : null, 'checks' => $checks ? mb_substr(implode('|', $checks), 0, 500) : null,
                    'action' => isset(BrgySop::ACTIONS[p('action')]) ? p('action') : 'checks', 'role_hint' => $clip('role_hint', 80) ?: null, 'step_no' => max(1, (int)p('step_no')),
                    'sort_order' => (int)p('sort_order'), 'is_active' => p('is_active') === '1' ? 1 : 0, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s'),
                    'txn_types' => $forTypes()];
                if ($row['title'] === '') {
                    fail('Enter the title of the step.');
                }
                if ($id) {
                    $old = DB::row('SELECT * FROM brgy_sop_steps WHERE id = :id', ['id' => $id]);
                    DB::update('brgy_sop_steps', $row, 'id = :id', ['id' => $id]);
                    Brgy::log('edit', 'sop_step', $id, $old['code'] . ' ' . $row['title'], ['before' => $old, 'after' => $row, 'page_key' => 'brgy.process']);
                    return 'Step saved. Open transactions get its new papers (and the types it is for) when they are opened.';
                }
                $row += ['process' => $process, 'code' => substr($process === 'svp' ? 'svpx' : 'bidx', 0, 4) . substr(bin2hex(random_bytes(4)), 0, 8)];
                $nid = DB::insert('brgy_sop_steps', $row);
                Brgy::log('create', 'sop_step', $nid, $row['code'] . ' ' . $row['title'], ['after' => $row, 'page_key' => 'brgy.process']);
                return 'Step added.';
            case 'step_delete':
                Access::require('brgy.process', 'delete');
                $st = DB::row('SELECT * FROM brgy_sop_steps WHERE id = :id', ['id' => (int)pint('step_id')]);
                if (!$st) {
                    fail('Step not found.');
                }
                if (DB::value("SELECT COUNT(*) FROM brgy_project_steps WHERE step_code = :c AND status <> 'pending'", ['c' => $st['code']])) {
                    fail('Transactions have done this step: set it inactive instead.');
                }
                DB::run('DELETE FROM brgy_project_papers WHERE step_code = :c AND document_id IS NULL AND form_id IS NULL', ['c' => $st['code']]);
                DB::run('DELETE FROM brgy_project_steps WHERE step_code = :c', ['c' => $st['code']]);
                DB::run('DELETE FROM brgy_sop_steps WHERE id = :id', ['id' => $st['id']]);
                Brgy::log('delete', 'sop_step', (int)$st['id'], $st['code'] . ' ' . $st['title'], ['snapshot' => $st, 'page_key' => 'brgy.process']);
                return 'Step deleted.';
            case 'paper_save':
                $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', trim((string)p('code'))));
                $exists = $code !== '' && DB::value('SELECT code FROM brgy_sop_papers WHERE code = :c', ['c' => $code]);
                Access::require('brgy.process', $exists ? 'edit' : 'create');
                if ($code === '' || strlen($code) > 40) {
                    fail('Enter a code for the paper (letters, digits, _).');
                }
                $row = ['name' => $clip('name', 160), 'doc_type' => isset(Brgy::docTypes()[p('doc_type')]) ? p('doc_type') : null,
                    'template_code' => p('template_code') !== '' && DB::value('SELECT id FROM brgy_templates WHERE code = :c', ['c' => p('template_code')]) ? p('template_code') : null,
                    'source' => isset(BrgySop::SOURCES[p('source')]) ? p('source') : 'prepare', 'copies' => max(1, min(10, (int)p('copies'))),
                    'needs_signature' => p('needs_signature') === '1' ? 1 : 0, 'needs_notary' => p('needs_notary') === '1' ? 1 : 0, 'needs_ctc' => p('needs_ctc') === '1' ? 1 : 0,
                    'condition_code' => p('condition_code') === 'extra_bid_papers' ? 'extra_bid_papers' : null, 'description' => $clip('description', 255) ?: null,
                    'sort_order' => (int)p('sort_order'), 'is_active' => p('is_active') === '1' ? 1 : 0, 'txn_types' => $forTypes()];
                if ($row['name'] === '') {
                    fail('Enter the name of the paper.');
                }
                if ($exists) {
                    DB::update('brgy_sop_papers', $row, 'code = :c', ['c' => $code]);
                    // open transactions that have not done anything with it take the new details
                    DB::run("UPDATE brgy_project_papers pp JOIN brgy_projects p ON p.id = pp.project_id SET pp.name = :n, pp.doc_type = :d, pp.template_code = :t, pp.source = :s, pp.copies = :c,
                                    pp.needs_signature = :sg, pp.needs_notary = :nt, pp.needs_ctc = :ct
                              WHERE pp.paper_code = :code AND pp.document_id IS NULL AND pp.form_id IS NULL AND p.status IN ('open', 'awarded')",
                        ['n' => $row['name'], 'd' => $row['doc_type'], 't' => $row['template_code'], 's' => $row['source'], 'c' => $row['copies'], 'sg' => $row['needs_signature'], 'nt' => $row['needs_notary'],
                         'ct' => $row['needs_ctc'], 'code' => $code]);
                } else {
                    DB::insert('brgy_sop_papers', $row + ['code' => $code]);
                }
                Brgy::log($exists ? 'edit' : 'create', 'sop_paper', null, $code . ' ' . $row['name'], ['after' => $row, 'page_key' => 'brgy.process']);
                return 'Paper saved.';
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['tab' => $tab]));
}

$canC = Access::can($pageKey, 'create');
$canE = Access::can($pageKey, 'edit');
$canD = Access::can($pageKey, 'delete');
$papers = BrgySop::papers();
$tpls = DB::pairs('SELECT code, name FROM brgy_templates WHERE is_active = 1 ORDER BY name');
$types = BrgySop::txnTypes();
/** "Only for Purchases, Construction" when a step or paper is not for every type of transaction */
$onlyFor = function (array $r) use ($types): string {
    $list = array_values(array_filter(array_map('trim', explode(',', (string)($r['txn_types'] ?? '')))));
    return $list ? 'Only for ' . implode(', ', array_map(fn($c) => $types[$c] ?? $c, $list)) : '';
};
/** The row for the edit box: no list means every type is ticked */
$forEdit = fn(array $r) => ['txn_types' => ($r['txn_types'] ?? '') !== '' && $r['txn_types'] !== null ? $r['txn_types'] : implode(',', array_keys($types))] + $r;
$typeBoxes = function () use ($types): string {
    $out = '';
    foreach ($types as $c => $lbl) {
        $out .= '<label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="txn_types[]" value="' . h($c) . '" checked> <span class="form-check-label small">' . h($lbl) . '</span></label>';
    }
    return $out;
};
layout_start('SOP Steps & Papers', brgy_layout(['actions' => ($canC && $tab !== 'papers' ? '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#mStep" data-step="' . h(json_encode(['txn_types' => implode(',', array_keys(BrgySop::txnTypes()))])) . '"><i class="bi bi-plus-lg"></i> New step</button> ' : '')
    . ($canC && $tab === 'papers' ? '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#mPaper" data-paper="' . h(json_encode(['txn_types' => implode(',', array_keys(BrgySop::txnTypes()))])) . '"><i class="bi bi-plus-lg"></i> New paper</button> ' : '')
    . page_link('brgy.settings', [], 'SOP settings', 'btn-outline-secondary', 'sliders')]));
?>
<ul class="nav nav-tabs proj-tabs mb-0">
  <?php foreach (['svp' => 'I. Small value procurement', 'bidding' => 'II. Bidding and payment', 'papers' => 'Papers'] as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['tab' => $k])) ?>"><?= h($lbl) ?></a></li>
  <?php endforeach; ?>
</ul>
<div class="tab-body">
<?php if ($tab === 'papers'): ?>
  <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 resp-cards">
    <thead><tr><th>Paper</th><th>How it is made</th><th>Proforma</th><th>Filed as</th><th>Copies</th><th>Signed / notarized / CTC</th><th></th></tr></thead><tbody>
    <?php foreach ($papers as $c => $r): ?>
      <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td data-label="Paper"><b><?= h($r['name']) ?></b><div class="small text-muted"><code><?= h($c) ?></code><?= $r['condition_code'] ? ', only for entities that ask for it' : '' ?></div><?= $onlyFor($r) ? '<div class="small sf-only"><i class="bi bi-funnel"></i> ' . h($onlyFor($r)) . '</div>' : '' ?><?= $r['description'] ? '<div class="small text-muted">' . h($r['description']) . '</div>' : '' ?></td>
        <td data-label="How" class="small"><?= h(BrgySop::SOURCES[$r['source']] ?? $r['source']) ?></td>
        <td data-label="Proforma" class="small"><?= $r['template_code'] ? safe_link('brgy.templates', ['a' => 'view', 'id' => (int)DB::value('SELECT id FROM brgy_templates WHERE code = :c', ['c' => $r['template_code']])], $tpls[$r['template_code']] ?? $r['template_code']) : '<span class="text-muted">attached</span>' ?></td>
        <td data-label="Filed as" class="small"><?= h(Brgy::docTypeName($r['doc_type'])) ?></td><td data-label="Copies" class="small"><?= (int)$r['copies'] ?></td>
        <td data-label="Flags" class="small"><?= h(implode(', ', array_filter([(int)$r['needs_signature'] ? 'signed' : '', (int)$r['needs_notary'] ? 'notarized' : '', (int)$r['needs_ctc'] ? 'certified true copy' : '']))) ?></td>
        <td class="text-end"><?php if ($canE): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mPaper" data-paper="<?= h(json_encode($forEdit($r))) ?>"><i class="bi bi-pencil"></i></button><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div></div>
<?php else:
    $steps = BrgySop::steps($tab, false);
    $used = DB::pairs("SELECT step_code, COUNT(*) FROM brgy_project_steps WHERE status <> 'pending' GROUP BY step_code"); ?>
  <p class="small text-muted"><?= $tab === 'svp' ? 'The SOP of a transaction below the small value limit: from the transaction form to the release of the papers. The price schedule is prepared after the canvass (step 4), and finishing it records the award.' : 'The bidding process, then the payment process flow: check cheque availability, SI, OR and 2307, monitoring, accounting, transmittal, signature, file.' ?></p>
  <div class="sop-flow">
    <?php $sec = null; foreach ($steps as $s): if ($s['section'] !== $sec): $sec = $s['section']; ?>
      <div class="sf-sec"><?= h($s['section'] . '. ' . $s['section_title']) ?></div>
    <?php endif; ?>
      <div class="sf-step<?= $s['is_active'] ? '' : ' is-off' ?>">
        <div class="sf-no"><?= (int)$s['step_no'] ?></div>
        <div class="sf-body">
          <div class="sf-title"><?= h($s['title']) ?><?= $s['is_active'] ? '' : ' <span class="small text-muted">(inactive)</span>' ?></div>
          <div class="sf-text"><?= h((string)$s['instructions']) ?></div>
          <div class="sf-meta">
            <span><i class="bi bi-gear"></i> <?= h(BrgySop::ACTIONS[$s['action']] ?? $s['action']) ?></span>
            <?php if ($s['role_hint']): ?><span><i class="bi bi-person"></i> <?= h($s['role_hint']) ?></span><?php endif; ?>
            <?php if (!empty($used[$s['code']])): ?><span><i class="bi bi-check2-all"></i> done on <?= (int)$used[$s['code']] ?> transaction(s)</span><?php endif; ?>
            <?php if ($onlyFor($s)): ?><span class="sf-only"><i class="bi bi-funnel"></i> <?= h($onlyFor($s)) ?></span><?php endif; ?>
          </div>
          <?php if ($s['papers']): ?><div class="sf-chips"><?php foreach (BrgySop::stepPapers($s) as $pc): ?><span class="sf-chip"><i class="bi bi-file-earmark-text"></i> <?= h($papers[$pc]['name'] ?? $pc) ?></span><?php endforeach; ?></div><?php endif; ?>
          <?php if ($s['checks']): ?><ul class="sf-checks"><?php foreach (BrgySop::stepChecks($s) as $ck): ?><li><?= h($ck) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <div class="sf-acts"><?php if ($canE): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mStep" data-step="<?= h(json_encode($forEdit($s))) ?>" aria-label="Edit the step"><i class="bi bi-pencil"></i></button><?php endif; ?>
          <?php if ($canD && empty($used[$s['code']])): ?><form method="post" action="<?= h(url($pageKey, ['tab' => $tab])) ?>" data-confirm="Delete this step?"><?= csrf_field() ?><input type="hidden" name="do" value="step_delete"><input type="hidden" name="step_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete the step"><i class="bi bi-trash"></i></button></form><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="modal fade" id="mStep" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['tab' => $tab])) ?>"><?= csrf_field() ?>
  <input type="hidden" name="do" value="step_save"><input type="hidden" name="step_id" value=""><input type="hidden" name="process" value="<?= h($tab) ?>">
  <div class="modal-header"><h5 class="modal-title">Step of the SOP</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body row g-2">
    <div class="col-3 col-md-2"><label class="form-label">No.</label><input class="form-control form-control-sm" type="number" min="1" name="step_no" value="1"></div>
    <div class="col-9 col-md-10"><label class="form-label">Title</label><input class="form-control form-control-sm" name="title" maxlength="160" required></div>
    <div class="col-3 col-md-2"><label class="form-label">Section</label><input class="form-control form-control-sm" name="section" maxlength="4"></div>
    <div class="col-9 col-md-6"><label class="form-label">Section title</label><input class="form-control form-control-sm" name="section_title" maxlength="120"></div>
    <div class="col-6 col-md-2"><label class="form-label">Order</label><input class="form-control form-control-sm" type="number" name="sort_order" value="0"></div>
    <div class="col-6 col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked> <span class="form-check-label">Active</span></label></div>
    <div class="col-12"><label class="form-label">What the SOP says</label><textarea class="form-control form-control-sm" name="instructions" rows="3"></textarea></div>
    <div class="col-md-7"><label class="form-label">What the program does at the step</label><select class="form-select form-select-sm" name="action" data-no-combo><?= options(BrgySop::ACTIONS) ?></select></div>
    <div class="col-md-5"><label class="form-label">Who does it</label><input class="form-control form-control-sm" name="role_hint" maxlength="80"></div>
    <div class="col-md-6"><label class="form-label">Checks (one a line)</label><textarea class="form-control form-control-sm" name="checks" rows="5"></textarea></div>
    <div class="col-md-6"><label class="form-label">Papers of the step</label><div class="paper-picks"><?php foreach ($papers as $c => $r): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="papers[]" value="<?= h($c) ?>"> <span class="form-check-label small"><?= h($r['name']) ?></span></label><?php endforeach; ?></div></div>
    <div class="col-12"><label class="form-label">For the types of transaction</label><div class="type-picks"><?= $typeBoxes() ?></div>
      <div class="form-text">A transaction of a type not ticked skips the step (with a note saying why); all ticked: every transaction. The types are listed in Contracting Settings.</div></div>
    <div class="col-12 form-text">The kind of step decides what the program asks before it can be marked done (the papers prepared or signed, the canvass, the cheque, the release ...). A new paper or step reaches each open transaction the next time it is opened.</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>

<div class="modal fade" id="mPaper" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['tab' => 'papers'])) ?>"><?= csrf_field() ?>
  <input type="hidden" name="do" value="paper_save">
  <div class="modal-header"><h5 class="modal-title">Paper</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body row g-2">
    <div class="col-5"><label class="form-label">Code</label><input class="form-control form-control-sm" name="code" maxlength="40" required></div>
    <div class="col-7"><label class="form-label">Name</label><input class="form-control form-control-sm" name="name" maxlength="160" required></div>
    <?= select_field('source', 'How it is made', BrgySop::SOURCES, 'prepare', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
    <?= select_field('template_code', 'Proforma', $tpls, '', ['blank' => '(none: attached)', 'class' => 'col-6', 'extra' => 'data-no-combo']) ?>
    <?= select_field('doc_type', 'Filed in the vault as', Brgy::docTypes(), 'other', ['class' => 'col-8', 'extra' => 'data-no-combo']) ?>
    <div class="col-4"><label class="form-label">Copies</label><input class="form-control form-control-sm" type="number" min="1" max="10" name="copies" value="1"></div>
    <div class="col-12 d-flex flex-wrap gap-3">
      <label class="form-check"><input class="form-check-input" type="checkbox" name="needs_signature" value="1"> <span class="form-check-label">Signed</span></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="needs_notary" value="1"> <span class="form-check-label">Notarized</span></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="needs_ctc" value="1"> <span class="form-check-label">Certified true copy</span></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="condition_code" value="extra_bid_papers"> <span class="form-check-label">Only for entities that ask for the extra bid papers</span></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked> <span class="form-check-label">Active</span></label>
    </div>
    <div class="col-8"><label class="form-label">Description</label><input class="form-control form-control-sm" name="description" maxlength="255"></div>
    <div class="col-4"><label class="form-label">Order</label><input class="form-control form-control-sm" type="number" name="sort_order" value="0"></div>
    <div class="col-12"><label class="form-label">For the types of transaction</label><div class="type-picks"><?= $typeBoxes() ?></div>
      <div class="form-text">For a type not ticked the paper is marked not needed (with a note saying why).</div></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
<?php
layout_end();
