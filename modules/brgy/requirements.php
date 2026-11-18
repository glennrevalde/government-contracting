<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Compliance Requirement Library
 * What the procuring entities ask for, by procurement law, mode, category, amount band and date. A project's checklist
 * is made from here. A new or changed requirement is proposed and counts only once an approver approves it, so the
 * checklists never follow an unverified rule. A requirement of one entity only is kept with that entity.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.requirements';
$a = g('a', 'list');
$id = gint('id');

function brgy_save_requirement(array $d, ?int $id): int
{
    Access::require('brgy.requirements', $id ? 'edit' : 'create');
    $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 200);
    if ($name === '') {
        fail('Enter the requirement.');
    }
    $csv = fn($k, array $allowed) => implode(',', array_values(array_intersect($allowed, (array)($d[$k] ?? [])))) ?: null;
    $num = fn($k) => trim((string)($d[$k] ?? '')) === '' ? null : r2(to_num($d[$k]));
    $row = ['name' => $name, 'description' => trim((string)($d['description'] ?? '')) ?: null, 'doc_group' => isset(Brgy::DOC_GROUPS[$d['doc_group'] ?? '']) ? $d['doc_group'] : 'legal',
        'stage' => isset(Brgy::STAGES[$d['stage'] ?? '']) ? $d['stage'] : 'eligibility', 'requirement_type' => isset(Brgy::REQ_TYPES[$d['requirement_type'] ?? '']) ? $d['requirement_type'] : 'mandatory',
        'regimes' => $csv('regimes', array_keys(Brgy::REGIMES)), 'categories' => $csv('categories', array_keys(Brgy::CATEGORIES)),
        'mode_codes' => $csv('mode_codes', array_map('strval', DB::column('SELECT code FROM brgy_proc_modes'))), 'min_abc' => $num('min_abc'), 'max_abc' => $num('max_abc'),
        'needs_validity' => !empty($d['needs_validity']) ? 1 : 0, 'doc_type' => ($d['doc_type'] ?? '') !== '' ? (string)$d['doc_type'] : null, 'template_code' => ($d['template_code'] ?? '') !== '' ? (string)$d['template_code'] : null,
        'is_prescribed' => !empty($d['is_prescribed']) ? 1 : 0, 'legal_basis' => mb_substr(trim((string)($d['legal_basis'] ?? '')), 0, 255) ?: null,
        'source_url' => mb_substr(trim((string)($d['source_url'] ?? '')), 0, 255) ?: null, 'entity_id' => !empty($d['entity_id']) ? (int)$d['entity_id'] : null,
        'effective_from' => valid_date($d['effective_from'] ?? '') ? $d['effective_from'] : null, 'effective_to' => valid_date($d['effective_to'] ?? '') ? $d['effective_to'] : null,
        'sort_order' => (int)($d['sort_order'] ?? 0), 'is_active' => isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1, 'updated_at' => date('Y-m-d H:i:s')];
    if ($row['source_url'] && !preg_match('#^https?://#i', $row['source_url'])) {
        fail('The source link has to start with http:// or https://.');
    }
    if ($row['min_abc'] !== null && $row['max_abc'] !== null && $row['max_abc'] < $row['min_abc']) {
        fail('The upper amount is below the lower one.');
    }
    if ($row['effective_from'] && $row['effective_to'] && $row['effective_to'] < $row['effective_from']) {
        fail('"Effective to" is before "effective from".');
    }
    // a change by someone who cannot approve goes back to "proposed"
    $approver = Access::can('brgy.requirements', 'approve');
    return DB::tx(function () use ($row, $id, $d, $approver) {
        if ($id) {
            $old = DB::row('SELECT * FROM brgy_requirements WHERE id = :id', ['id' => $id]);
            if (!$old) {
                fail('Requirement not found.');
            }
            if (!$approver) {
                $row += ['status' => 'proposed', 'approved_by' => null, 'approved_at' => null];
            }
            DB::update('brgy_requirements', $row, 'id = :id', ['id' => $id]);
            Brgy::log('edit', 'requirement', $id, $old['code'] . ' ' . $row['name'], ['before' => $old, 'after' => $row, 'page_key' => 'brgy.requirements']);
            return $id;
        }
        $code = strtoupper(preg_replace('/[^A-Z0-9-]+/i', '-', trim((string)($d['code'] ?? '')))) ?: 'REQ-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        while (DB::value('SELECT id FROM brgy_requirements WHERE code = :c', ['c' => $code])) {
            $code .= '-2';
        }
        $row += ['code' => mb_substr($code, 0, 40), 'status' => $approver ? 'approved' : 'proposed', 'approved_by' => $approver ? Auth::id() : null, 'approved_at' => $approver ? date('Y-m-d H:i:s') : null, 'created_by' => Auth::id()];
        $id = DB::insert('brgy_requirements', $row);
        Brgy::log('create', 'requirement', $id, $row['code'] . ' ' . $row['name'], ['after' => $row, 'page_key' => 'brgy.requirements']);
        return $id;
    });
}

if ($a === 'approve' && $id && is_post()) {
    brgy_action(function () use ($id) {
        Access::require('brgy.requirements', 'approve');
        $r = DB::row('SELECT * FROM brgy_requirements WHERE id = :id', ['id' => $id]);
        if (!$r || $r['status'] !== 'proposed') {
            fail('Only a proposed requirement is approved.');
        }
        if ((int)$r['created_by'] === (int)Auth::id() && Brgy::on('brgy_sod_strict') && !Access::isAdmin()) {
            fail('You proposed it; another approver has to approve it.');
        }
        DB::update('brgy_requirements', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Brgy::log('approve', 'requirement', $id, $r['code'] . ' ' . $r['name'], ['reason' => trim((string)p('remarks')) ?: null, 'page_key' => 'brgy.requirements']);
        return 'Approved: new checklists include it ("Add what the library suggests" adds it to open projects).';
    }, url($pageKey, ['status' => 'proposed']));
}

if (in_array($a, ['new', 'edit'], true)) {
    $r = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $r = DB::row('SELECT * FROM brgy_requirements WHERE id = :id', ['id' => (int)$id]);
        if (!$r) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    handle_post(function () use ($r, $pageKey) {
        brgy_save_requirement($_POST, $r ? (int)$r['id'] : null);
        flash('success', Access::can('brgy.requirements', 'approve') ? 'Saved.' : 'Saved as proposed: it counts once an approver approves it.');
        redirect(url($pageKey));
    });
    $sel = function (string $k) use ($r): array {
        return is_post() ? (array)($_POST[$k] ?? []) : array_filter(explode(',', (string)($r[$k] ?? '')));
    };
    $boxes = function (string $name, array $opts, array $checked): string {
        $html = '<div class="d-flex flex-wrap gap-2">';
        foreach ($opts as $k => $lbl) {
            $html .= '<label class="form-check me-2"><input class="form-check-input" type="checkbox" name="' . h($name) . '[]" value="' . h($k) . '"' . (in_array((string)$k, array_map('strval', $checked), true) ? ' checked' : '') . '> <span class="form-check-label small">' . h($lbl) . '</span></label>';
        }
        return $html . '</div>';
    };
    layout_start($r ? 'Edit requirement' : 'New requirement', brgy_layout(['actions' => btn_link(url($pageKey), 'Cancel')]));
    ?>
    <form method="post" class="brgy-form" data-no-class-check><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-list-check"></i> The requirement</div><div class="card-body row g-2">
      <?= input_field('name', 'Requirement (as the entity words it)', fv('name', $r), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="200"']) ?>
      <?= $r ? '<div class="col-md-4 small text-muted d-flex align-items-end">Code <code class="ms-1">' . h($r['code']) . '</code>, ' . Brgy::st($r['status'], $r['status'] === 'proposed' ? 'Proposed' : 'Approved') . '</div>' : input_field('code', 'Code', fv('code'), ['class' => 'col-md-4', 'extra' => 'maxlength="40" placeholder="e.g. REQ-PERMIT"']) ?>
      <?= select_field('stage', 'Stage', Brgy::STAGES, fv('stage', $r, 'eligibility'), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('requirement_type', 'Kind', Brgy::REQ_TYPES, fv('requirement_type', $r, 'mandatory'), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('doc_group', 'Group', Brgy::DOC_GROUPS, fv('doc_group', $r, 'legal'), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= check_field('needs_validity', 'Has a validity date', fv('needs_validity', $r, 0), 'col-md-3') ?>
      <?= select_field('doc_type', 'Document that satisfies it', Brgy::docTypes(), fv('doc_type', $r), ['blank' => '', 'class' => 'col-md-6']) ?>
      <?= select_field('template_code', 'Form that prepares it', DB::pairs('SELECT code, name FROM brgy_templates WHERE is_active = 1 ORDER BY sort_order, name'), fv('template_code', $r), ['blank' => '', 'class' => 'col-md-6']) ?>
      <div class="col-12"><label class="form-label">Description / what to check</label><textarea class="form-control form-control-sm" name="description" rows="2"><?= h(fv('description', $r)) ?></textarea></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-funnel"></i> When it applies (blank = always)</div><div class="card-body row g-3">
      <div class="col-md-6"><label class="form-label">Procurement law</label><?= $boxes('regimes', Brgy::REGIMES, $sel('regimes')) ?></div>
      <div class="col-md-6"><label class="form-label">Category</label><?= $boxes('categories', Brgy::CATEGORIES, $sel('categories')) ?></div>
      <div class="col-12"><label class="form-label">Procurement modes</label><?= $boxes('mode_codes', DB::pairs("SELECT code, CONCAT(name, IF(regime = 'ra9184', ' (RA 9184)', '')) FROM brgy_proc_modes ORDER BY sort_order"), $sel('mode_codes')) ?></div>
      <?= input_field('min_abc', 'ABC from', fv('min_abc', $r), ['class' => 'col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('max_abc', 'ABC up to', fv('max_abc', $r), ['class' => 'col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('effective_from', 'Effective from', fv('effective_from', $r), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('effective_to', 'Effective to', fv('effective_to', $r), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= select_field('entity_id', 'Only for this procuring entity', Brgy::entities(false), fv('entity_id', $r, gint('entity_id')), ['blank' => 'Every entity', 'class' => 'col-md-6']) ?>
      <?= check_field('is_prescribed', 'Uses a prescribed form', fv('is_prescribed', $r, 0), 'col-md-3') ?>
      <?= $r ? check_field('is_active', 'Active', fv('is_active', $r, 1), 'col-md-3') : '' ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-journal-bookmark"></i> Source</div><div class="card-body row g-2">
      <?= input_field('legal_basis', 'Legal basis (law, IRR section, circular, RFQ clause)', fv('legal_basis', $r), ['class' => 'col-md-7', 'extra' => 'maxlength="255"']) ?>
      <?= input_field('source_url', 'Link to the official source', fv('source_url', $r), ['class' => 'col-md-5', 'type' => 'url']) ?>
      <?= input_field('sort_order', 'Order', fv('sort_order', $r, 100), ['type' => 'number', 'step' => '1', 'class' => 'col-md-2']) ?>
      <div class="col-12 form-text">Verify a requirement against the current law, IRR and GPPB issuances (Regulatory References) before it is approved. Amounts, thresholds and forms change; the program never assumes them.</div>
    </div></div>
    <div class="sticky-actions"><?= save_buttons($r ? 'Save' : 'Add requirement', url($pageKey), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$fStage = (string)g('stage');
$fStatus = (string)g('status');
$q = trim((string)g('q'));
$sql = 'SELECT r.*, e.official_name AS entity_name FROM brgy_requirements r LEFT JOIN brgy_entities e ON e.id = r.entity_id WHERE 1 = 1';
$params = [];
if (isset(Brgy::STAGES[$fStage])) {
    $sql .= ' AND r.stage = :st';
    $params['st'] = $fStage;
}
if ($fStatus === 'proposed') {
    $sql .= " AND r.status = 'proposed'";
} elseif ($fStatus === 'inactive') {
    $sql .= ' AND r.is_active = 0';
} elseif ($fStatus !== 'all') {
    $sql .= ' AND r.is_active = 1';
}
if ($q !== '') {
    $sql .= ' AND (r.name LIKE :q OR r.code LIKE :q OR r.legal_basis LIKE :q)';
    $params['q'] = "%$q%";
}
$rows = DB::all($sql . " ORDER BY FIELD(r.stage, 'eligibility', 'bid', 'post_qual', 'contract', 'delivery', 'billing'), r.sort_order, r.name", $params);
$modeNames = DB::pairs('SELECT code, name FROM brgy_proc_modes');
$proposed = (int)DB::value("SELECT COUNT(*) FROM brgy_requirements WHERE status = 'proposed'");
layout_start('Compliance Requirement Library', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'New requirement', 'btn-primary', 'plus-lg', 'create')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-4"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Requirement, code, legal basis" data-live-filter="#reqTable tbody tr" data-live-enter-submits></div>
  <?= select_field('stage', 'Stage', Brgy::STAGES, $fStage, ['blank' => 'All stages', 'class' => 'col-md-3', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <?= select_field('status', 'Show', ['' => 'Active', 'proposed' => 'Proposed, waiting for approval' . ($proposed ? ' (' . $proposed . ')' : ''), 'inactive' => 'Inactive', 'all' => 'All'], $fStatus, ['class' => 'col-md-3', 'extra' => 'data-autosubmit data-no-combo']) ?>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="reqTable">
  <thead><tr><th>Requirement</th><th>Stage</th><th>Kind</th><th>Applies to</th><th>Basis</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $applies = array_filter([
          $r['regimes'] ? implode(', ', array_map(fn($x) => strtoupper(str_replace('ra', 'RA ', $x)), explode(',', $r['regimes']))) : '',
          $r['categories'] ? implode(', ', array_map(fn($x) => Brgy::label(Brgy::CATEGORIES, $x), explode(',', $r['categories']))) : '',
          $r['mode_codes'] ? count(explode(',', $r['mode_codes'])) . ' mode(s): ' . implode(', ', array_map(fn($x) => $modeNames[$x] ?? $x, array_slice(explode(',', $r['mode_codes']), 0, 3))) . (count(explode(',', $r['mode_codes'])) > 3 ? '...' : '') : '',
          $r['min_abc'] !== null || $r['max_abc'] !== null ? 'ABC ' . ($r['min_abc'] !== null ? 'from ' . money($r['min_abc']) : '') . ($r['max_abc'] !== null ? ' up to ' . money($r['max_abc']) : '') : '',
          $r['entity_name'] ? 'only ' . $r['entity_name'] : '',
      ]); ?>
    <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="wrap-link"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) . '">' . h($r['name']) . '</a>' : h($r['name']) ?>
        <div class="small text-muted"><?= h($r['code']) ?><?= $r['doc_type'] ? ', ' . h(Brgy::docTypeName($r['doc_type'])) : '' ?><?= (int)$r['needs_validity'] ? ', checks validity' : '' ?><?= $r['template_code'] ? ', form ' . h($r['template_code']) : '' ?></div></td>
      <td class="small"><?= h(Brgy::label(Brgy::STAGES, $r['stage'])) ?></td><td class="small"><?= h(Brgy::label(Brgy::REQ_TYPES, $r['requirement_type'])) ?></td>
      <td class="small"><?= h(implode('; ', $applies) ?: 'Always') ?></td>
      <td class="small"><?= h($r['legal_basis']) ?><?= $r['source_url'] ? ' <a href="' . h($r['source_url']) . '" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i></a>' : '' ?></td>
      <td><?= Brgy::st($r['status'], $r['status'] === 'proposed' ? 'Proposed' : 'Approved') ?></td>
      <td class="text-end"><?php if ($r['status'] === 'proposed' && Access::can($pageKey, 'approve')): ?><form method="post" action="<?= h(url($pageKey, ['a' => 'approve', 'id' => $r['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success">Approve</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted py-4 text-center">No requirements for this view.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php
layout_end();
