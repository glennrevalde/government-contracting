<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Regulatory References
 * The laws, rules and issuances the module's requirements, modes, forms and tax treatment rest on, with their dates,
 * official links and when someone last checked them. A reference not reviewed for a year is flagged.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.references';
$editId = gint('edit');
$types = ['law' => 'Law', 'irr' => 'Implementing rules', 'resolution' => 'Resolution', 'circular' => 'Circular', 'regulation' => 'Regulation', 'memorandum' => 'Memorandum', 'other' => 'Other'];

if (is_post()) {
    brgy_action(function () use ($types) {
        $id = pint('id');
        if (p('do') === 'reviewed') {
            Access::require('brgy.references', 'edit');
            $r = DB::row('SELECT * FROM brgy_references WHERE id = :id', ['id' => (int)$id]);
            DB::update('brgy_references', ['last_reviewed' => today(), 'reviewed_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Brgy::log('reviewed', 'reference', (int)$id, (string)$r['code'], ['reason' => trim((string)p('remarks')) ?: 'Checked against the official source', 'page_key' => 'brgy.references']);
            return 'Marked as checked today.';
        }
        Access::require('brgy.references', $id ? 'edit' : 'create');
        $title = mb_substr(trim((string)p('title')), 0, 255);
        $code = strtoupper(preg_replace('/[^A-Z0-9-]+/i', '-', trim((string)p('code'))));
        if ($title === '' || (!$id && $code === '')) {
            fail('Enter the code and the title.');
        }
        $row = ['title' => $title, 'issuer' => mb_substr(trim((string)p('issuer')), 0, 80) ?: null, 'ref_type' => isset($types[p('ref_type')]) ? (string)p('ref_type') : 'other',
            'issued_date' => pdate('issued_date'), 'published_date' => pdate('published_date'), 'effective_date' => pdate('effective_date'),
            'url' => mb_substr(trim((string)p('url')), 0, 255) ?: null, 'applies_to' => mb_substr(trim((string)p('applies_to')), 0, 255) ?: null, 'notes' => trim((string)p('notes')) ?: null,
            'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        if ($row['url'] && !preg_match('#^https?://#i', $row['url'])) {
            fail('The link has to start with http:// or https://.');
        }
        if ($id) {
            DB::update('brgy_references', $row, 'id = :id', ['id' => $id]);
            Brgy::log('edit', 'reference', $id, $title, ['after' => $row, 'page_key' => 'brgy.references']);
            return 'Saved.';
        }
        if (DB::value('SELECT id FROM brgy_references WHERE code = :c', ['c' => $code])) {
            fail('The code ' . $code . ' is already used.');
        }
        $id = DB::insert('brgy_references', $row + ['code' => mb_substr($code, 0, 40)]);
        Brgy::log('create', 'reference', $id, $code . ' ' . $title, ['page_key' => 'brgy.references']);
        return 'Reference added.';
    }, url($pageKey));
}

$rows = DB::all('SELECT r.*, u.full_name FROM brgy_references r LEFT JOIN users u ON u.id = r.reviewed_by ORDER BY r.is_active DESC, r.ref_type, r.effective_date DESC, r.code');
$e = $editId ? DB::row('SELECT * FROM brgy_references WHERE id = :id', ['id' => $editId]) : null;
$stale = date('Y-m-d', strtotime('-12 months'));
layout_start('Regulatory References', brgy_layout());
?>
<div class="alert alert-light small">The module follows these references; it does not interpret them. Rates, thresholds, forms and documentary requirements are kept in the settings and the requirement library, entered and approved after checking the official text.
  Republic Act No. 12009 governs procurements started after it took effect; RA 9184 still governs those started before it, as the GPPB guidance on the transitory provision explains.</div>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Reference</th><th>Kind</th><th>Effective</th><th>Last checked</th><th></th></tr></thead>
    <?php foreach ($rows as $r): $old = !$r['last_reviewed'] || $r['last_reviewed'] < $stale; ?>
      <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="wrap-link"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['edit' => $r['id']])) . '">' . h($r['code']) . '</a>' : h($r['code']) ?>
          <?= $r['url'] ? ' <a href="' . h($r['url']) . '" target="_blank" rel="noopener noreferrer" title="Official source"><i class="bi bi-box-arrow-up-right"></i></a>' : '' ?>
          <div class="small"><?= h($r['title']) ?></div><?= $r['applies_to'] ? '<div class="small text-muted">' . h($r['applies_to']) . '</div>' : '' ?></td>
        <td class="small"><?= h($types[$r['ref_type']] ?? $r['ref_type']) ?><?= $r['issuer'] ? '<div class="text-muted">' . h($r['issuer']) . '</div>' : '' ?></td>
        <td class="small text-nowrap"><?= h(fdate($r['effective_date'])) ?></td>
        <td class="small text-nowrap <?= $old ? 'text-warning-emphasis' : '' ?>"><?= $r['last_reviewed'] ? h(fdate($r['last_reviewed'])) . '<div class="text-muted">' . h($r['full_name']) . '</div>' : 'never' ?></td>
        <td class="text-end"><?php if (Access::can($pageKey, 'edit')): ?><form method="post" action="<?= h(url($pageKey)) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="reviewed"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-link p-0">Checked today</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div></div></div>
  <div class="col-xl-4"><?php if (Access::can($pageKey, $e ? 'edit' : 'create')): ?>
    <form class="card" method="post" action="<?= h(url($pageKey)) ?>" data-no-class-check><?= csrf_field() ?><?= $e ? '<input type="hidden" name="id" value="' . (int)$e['id'] . '">' : '' ?>
      <div class="card-header"><i class="bi bi-journal-bookmark"></i> <?= $e ? 'Edit ' . h($e['code']) : 'Add a reference' ?></div><div class="card-body row g-2">
        <?= $e ? '' : input_field('code', 'Code', '', ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="e.g. GPPB-RES-01-2026"']) ?>
        <div class="col-12"><label class="form-label">Title</label><textarea class="form-control form-control-sm" name="title" rows="2" required><?= h($e['title'] ?? '') ?></textarea></div>
        <?= select_field('ref_type', 'Kind', $types, $e['ref_type'] ?? 'resolution', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
        <?= input_field('issuer', 'Issued by', $e['issuer'] ?? '', ['class' => 'col-6', 'extra' => 'placeholder="GPPB, BIR, COA ..."']) ?>
        <?= input_field('issued_date', 'Issued', $e['issued_date'] ?? '', ['type' => 'date', 'class' => 'col-4']) ?>
        <?= input_field('published_date', 'Published', $e['published_date'] ?? '', ['type' => 'date', 'class' => 'col-4']) ?>
        <?= input_field('effective_date', 'Effective', $e['effective_date'] ?? '', ['type' => 'date', 'class' => 'col-4']) ?>
        <?= input_field('url', 'Official link', $e['url'] ?? '', ['class' => 'col-12', 'type' => 'url']) ?>
        <?= input_field('applies_to', 'What it governs here', $e['applies_to'] ?? '', ['class' => 'col-12']) ?>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h($e['notes'] ?? '') ?></textarea></div>
        <?= $e ? check_field('is_active', 'In force / still followed', $e['is_active'], 'col-12') : '' ?>
      </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button><?= $e ? btn_link(url($pageKey), 'Cancel') : '' ?></div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
