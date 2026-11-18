<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Procurement Modes
 * The modes of procurement under RA 12009 (and RA 9184 for procurements started before it), with the amount ceilings
 * and securities the current rules set. Ceilings are left blank until verified against the IRR and GPPB issuances.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.modes';
$editId = gint('edit');

if (is_post()) {
    brgy_action(function () {
        $id = pint('id');
        Access::require('brgy.modes', $id ? 'edit' : 'create');
        $name = mb_substr(trim((string)p('name')), 0, 120);
        if ($name === '') {
            fail('Enter the name of the mode.');
        }
        $num = fn($k) => trim((string)p($k)) === '' ? null : r2(pnum($k));
        $row = ['name' => $name, 'regime' => isset(Brgy::REGIMES[p('regime')]) ? (string)p('regime') : 'ra12009', 'legal_basis' => mb_substr(trim((string)p('legal_basis')), 0, 255) ?: null,
            'source_url' => mb_substr(trim((string)p('source_url')), 0, 255) ?: null, 'max_abc' => $num('max_abc'), 'needs_bid_security' => p('needs_bid_security') === '1' ? 1 : 0,
            'needs_perf_security' => p('needs_perf_security') === '1' ? 1 : 0, 'default_retention_pct' => $num('default_retention_pct'),
            'effective_from' => pdate('effective_from'), 'effective_to' => pdate('effective_to'), 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
            'sort_order' => (int)p('sort_order'), 'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        if ($row['source_url'] && !preg_match('#^https?://#i', $row['source_url'])) {
            fail('The source link has to start with http:// or https://.');
        }
        if ($id) {
            $old = DB::row('SELECT * FROM brgy_proc_modes WHERE id = :id', ['id' => $id]);
            DB::update('brgy_proc_modes', $row, 'id = :id', ['id' => $id]);
            Brgy::log('edit', 'mode', $id, $old['code'] . ' ' . $name, ['before' => $old, 'after' => $row, 'page_key' => 'brgy.modes']);
            return 'Saved.';
        }
        $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim((string)p('code')))) ?: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
        if (DB::value('SELECT id FROM brgy_proc_modes WHERE code = :c', ['c' => $code])) {
            fail('The code ' . $code . ' is already used.');
        }
        $id = DB::insert('brgy_proc_modes', $row + ['code' => mb_substr($code, 0, 30)]);
        Brgy::log('create', 'mode', $id, $code . ' ' . $name, ['after' => $row, 'page_key' => 'brgy.modes']);
        return 'Mode added.';
    }, url($pageKey));
}

$rows = DB::all('SELECT m.*, (SELECT COUNT(*) FROM brgy_projects p WHERE p.mode_id = m.id) AS uses FROM brgy_proc_modes m ORDER BY m.regime, m.sort_order, m.name');
$e = $editId ? DB::row('SELECT * FROM brgy_proc_modes WHERE id = :id', ['id' => $editId]) : null;
layout_start('Procurement Modes', brgy_layout());
?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Mode</th><th>Law</th><th class="num">ABC ceiling</th><th>Securities</th><th class="num">Retention</th><th class="num">Projects</th></tr></thead>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="wrap-link"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['edit' => $r['id']])) . '">' . h($r['name']) . '</a>' : h($r['name']) ?><div class="small text-muted"><?= h($r['code']) ?><?= $r['legal_basis'] ? ', ' . h($r['legal_basis']) : '' ?></div></td>
        <td class="small"><?= $r['regime'] === 'ra9184' ? 'RA 9184 (transition)' : 'RA 12009' ?></td>
        <td class="num small"><?= $r['max_abc'] !== null ? money($r['max_abc']) : '<span class="text-muted">verify</span>' ?></td>
        <td class="small"><?= h(implode(', ', array_filter([(int)$r['needs_bid_security'] ? 'bid' : '', (int)$r['needs_perf_security'] ? 'performance' : '']))) ?></td>
        <td class="num small"><?= $r['default_retention_pct'] !== null ? pct($r['default_retention_pct']) : '' ?></td><td class="num"><?= (int)$r['uses'] ?: '' ?></td></tr>
    <?php endforeach; ?>
  </table></div></div>
  <p class="small text-muted mt-2">The ceilings, securities and retention of each mode come from the IRR of RA 12009 and the GPPB issuances in force; enter them only after checking the current text (Regulatory References). Blank means not set, and nothing is checked against it.</p></div>
  <div class="col-xl-4"><?php if (Access::can($pageKey, $e ? 'edit' : 'create')): ?>
    <form class="card" method="post" action="<?= h(url($pageKey)) ?>" data-no-class-check><?= csrf_field() ?><?= $e ? '<input type="hidden" name="id" value="' . (int)$e['id'] . '">' : '' ?>
      <div class="card-header"><i class="bi bi-diagram-2"></i> <?= $e ? 'Edit ' . h($e['name']) : 'Add a mode' ?></div><div class="card-body row g-2">
        <?= input_field('name', 'Name', $e['name'] ?? '', ['class' => 'col-12', 'required' => true]) ?>
        <?= $e ? '' : input_field('code', 'Code', '', ['class' => 'col-6']) ?>
        <?= select_field('regime', 'Law', Brgy::REGIMES, $e['regime'] ?? 'ra12009', ['class' => $e ? 'col-12' : 'col-6', 'extra' => 'data-no-combo']) ?>
        <?= input_field('legal_basis', 'Legal basis', $e['legal_basis'] ?? '', ['class' => 'col-12']) ?>
        <?= input_field('source_url', 'Link to the official source', $e['source_url'] ?? '', ['class' => 'col-12', 'type' => 'url']) ?>
        <?= input_field('max_abc', 'ABC ceiling (verified)', $e['max_abc'] ?? '', ['class' => 'col-6', 'extra' => 'inputmode="decimal"']) ?>
        <?= input_field('default_retention_pct', 'Usual retention %', $e['default_retention_pct'] ?? '', ['class' => 'col-6', 'extra' => 'inputmode="decimal"']) ?>
        <?= check_field('needs_bid_security', 'Bid security / declaration', $e['needs_bid_security'] ?? 0, 'col-6') ?>
        <?= check_field('needs_perf_security', 'Performance security', $e['needs_perf_security'] ?? 0, 'col-6') ?>
        <?= input_field('effective_from', 'Effective from', $e['effective_from'] ?? '', ['type' => 'date', 'class' => 'col-6']) ?>
        <?= input_field('effective_to', 'Effective to', $e['effective_to'] ?? '', ['type' => 'date', 'class' => 'col-6']) ?>
        <?= input_field('notes', 'Notes', $e['notes'] ?? '', ['class' => 'col-12']) ?>
        <?= input_field('sort_order', 'Order', $e['sort_order'] ?? 100, ['type' => 'number', 'step' => '1', 'class' => 'col-6']) ?>
        <?= check_field('is_active', 'Active', $e['is_active'] ?? 1, 'col-6') ?>
      </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button><?= $e ? btn_link(url($pageKey), 'Cancel') : '' ?></div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
