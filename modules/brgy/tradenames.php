<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Tradenames (v33)
 * The tradenames the company does business under. Each transaction picks one on its transaction form; the default
 * one fills a new transaction. A tradename is added, changed or removed here at any time: a transaction keeps the
 * name it was saved with (so its printed papers do not change), unless a rename is applied to the open ones.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.tradenames';
$editId = gint('edit');

/** the default tradename is also kept in the setting brgy_tradename, read where a single name is needed */
$syncDefault = function (): void {
    $d = (string)DB::value('SELECT name FROM brgy_tradenames WHERE is_default = 1 AND is_active = 1 ORDER BY id LIMIT 1');
    if ($d === '') {
        // no default (or it was made inactive): the first active one becomes the default
        $first = DB::value('SELECT id FROM brgy_tradenames WHERE is_active = 1 ORDER BY sort_order, name LIMIT 1');
        if ($first) {
            DB::run('UPDATE brgy_tradenames SET is_default = (id = :id)', ['id' => (int)$first]);
            $d = (string)DB::value('SELECT name FROM brgy_tradenames WHERE id = :id', ['id' => (int)$first]);
        }
    }
    Settings::set('brgy_tradename', $d);
};

if (is_post()) {
    brgy_action(function () use ($syncDefault) {
        $id = pint('id');
        if (p('do') === 'delete') {
            Access::require('brgy.tradenames', 'delete');
            $old = DB::row('SELECT * FROM brgy_tradenames WHERE id = :id', ['id' => (int)$id]);
            if (!$old) {
                fail('That tradename is not on the list any more.');
            }
            return DB::tx(function () use ($old, $syncDefault) {
                DB::run('DELETE FROM brgy_tradenames WHERE id = :id', ['id' => (int)$old['id']]);
                $syncDefault();
                Brgy::log('delete', 'tradename', (int)$old['id'], $old['name'], ['before' => $old, 'page_key' => 'brgy.tradenames']);
                return 'Tradename ' . $old['name'] . ' removed. The transactions saved with it keep it.';
            });
        }
        Access::require('brgy.tradenames', $id ? 'edit' : 'create');
        $name = trim(preg_replace('/\s+/u', ' ', (string)p('name')));
        if ($name === '') {
            fail('Enter the tradename.');
        }
        if (mb_strlen($name) > 160) {
            fail('A tradename can have up to 160 characters.');
        }
        $dup = DB::value('SELECT id FROM brgy_tradenames WHERE name = :n' . ($id ? ' AND id <> ' . (int)$id : ''), ['n' => $name]);
        if ($dup) {
            fail('The tradename ' . $name . ' is already on the list.');
        }
        $row = ['name' => $name, 'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
            'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'sort_order' => (int)p('sort_order', '100'),
            'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
        $makeDefault = p('is_default') === '1';
        if ($makeDefault && !$row['is_active']) {
            fail('The default tradename has to be active.');
        }
        return DB::tx(function () use ($id, $row, $makeDefault, $syncDefault) {
            if ($makeDefault) {
                DB::run('UPDATE brgy_tradenames SET is_default = 0');
                $row['is_default'] = 1;
            }
            if ($id) {
                $old = DB::row('SELECT * FROM brgy_tradenames WHERE id = :id', ['id' => $id]);
                if (!$old) {
                    fail('That tradename is not on the list any more.');
                }
                if (!$makeDefault && (int)$old['is_default'] === 1 && $row['is_active']) {
                    $row['is_default'] = 1;   // the default stays the default until another one is made the default
                }
                DB::update('brgy_tradenames', $row, 'id = :id', ['id' => $id]);
                $moved = 0;
                if ($old['name'] !== $row['name'] && p('rename_open') === '1') {
                    $moved = DB::run("UPDATE brgy_projects SET tradename = :n WHERE tradename = :o AND status = 'open'", ['n' => $row['name'], 'o' => $old['name']])->rowCount();
                }
                $syncDefault();
                Brgy::log('edit', 'tradename', $id, $row['name'], ['before' => $old, 'after' => $row + ['open_transactions_renamed' => $moved], 'page_key' => 'brgy.tradenames']);
                return 'Saved.' . ($moved ? ' ' . $moved . ' open transaction(s) now use ' . $row['name'] . '.' : '');
            }
            $id = DB::insert('brgy_tradenames', $row + ['created_by' => Auth::id()]);
            $syncDefault();
            Brgy::log('create', 'tradename', $id, $row['name'], ['after' => $row, 'page_key' => 'brgy.tradenames']);
            return 'Tradename ' . $row['name'] . ' added.';
        });
    }, url($pageKey));
}

$rows = DB::all("SELECT t.*, (SELECT COUNT(*) FROM brgy_projects p WHERE p.tradename = t.name) AS uses,
                        (SELECT COUNT(*) FROM brgy_projects p WHERE p.tradename = t.name AND p.status = 'open') AS open_uses
                   FROM brgy_tradenames t ORDER BY t.is_default DESC, t.is_active DESC, t.sort_order, t.name");
$e = $editId ? DB::row("SELECT t.*, (SELECT COUNT(*) FROM brgy_projects p WHERE p.tradename = t.name AND p.status = 'open') AS open_uses FROM brgy_tradenames t WHERE t.id = :id", ['id' => $editId]) : null;
$canEdit = Access::can($pageKey, 'edit');
$canDelete = Access::can($pageKey, 'delete');
layout_start('Tradenames', brgy_layout(['actions' => page_link('brgy.settings', [], 'Contracting Settings', 'btn-outline-secondary', 'gear')]));
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><span><i class="bi bi-shop"></i> Tradenames of the company</span><span class="small text-muted"><?= count($rows) ?> on the list</span></div>
      <?php if (!$rows): ?>
        <div class="card-body"><div class="small text-muted">No tradename yet. Add the names the company does business under; the transaction form then offers them in its Tradename list. Until then the company name (<?= h((string)Settings::get('company_name')) ?>) is used.</div></div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 tn-table">
        <thead><tr><th>Tradename</th><th>Status</th><th class="num">Transactions</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
            <td class="wrap-link"><span class="fw-semibold"><?= h($r['name']) ?></span><?= $r['description'] ? '<div class="small text-muted">' . h($r['description']) . '</div>' : '' ?></td>
            <td class="small text-nowrap"><?= (int)$r['is_default'] ? '<span class="text-primary fw-semibold">Default</span>' : ($r['is_active'] ? 'Active' : 'Inactive') ?></td>
            <td class="num small"><?= (int)$r['uses'] ? (int)$r['uses'] . '<span class="tn-phone"> transaction(s)</span>' : '<span class="tn-phone text-muted">Not used yet</span>' ?><?= (int)$r['open_uses'] ? '<div class="text-muted"><span class="tn-phone">, </span>' . (int)$r['open_uses'] . ' open</div>' : '' ?></td>
            <td class="text-end text-nowrap">
              <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['edit' => $r['id']])) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
              <?php if ($canDelete): ?><form method="post" action="<?= h(url($pageKey)) ?>" class="d-inline" data-confirm="Remove the tradename <?= h($r['name']) ?>?<?= (int)$r['uses'] ? ' The ' . (int)$r['uses'] . ' transaction(s) saved with it keep it.' : '' ?>"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Remove <?= h($r['name']) ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <p class="small text-muted mt-2">The tradename printed on the transaction form is the one chosen on that transaction. Changing or removing a tradename here does not change the transactions already saved, except a rename applied to the open ones.</p>
  </div>
  <div class="col-xl-4">
    <?php if (Access::can($pageKey, $e ? 'edit' : 'create')): ?>
    <form class="card" method="post" action="<?= h(url($pageKey)) ?>" data-no-class-check><?= csrf_field() ?><?= $e ? '<input type="hidden" name="id" value="' . (int)$e['id'] . '">' : '' ?>
      <div class="card-header"><i class="bi bi-<?= $e ? 'pencil-square' : 'plus-lg' ?>"></i> <?= $e ? 'Edit ' . h($e['name']) : 'Add a tradename' ?></div>
      <div class="card-body row g-2">
        <?= input_field('name', 'Tradename', $e['name'] ?? '', ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="160" autocomplete="off"']) ?>
        <?= input_field('description', 'Description (optional)', $e['description'] ?? '', ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="DTI registration, line of business, ..."']) ?>
        <?= input_field('sort_order', 'Order on the list', $e['sort_order'] ?? 100, ['type' => 'number', 'step' => '1', 'class' => 'col-6']) ?>
        <div class="col-6 d-flex flex-column justify-content-end gap-1 pb-1">
          <?= check_field('is_default', 'Default', (int)($e['is_default'] ?? ($rows ? 0 : 1)), '') ?>
          <?= check_field('is_active', 'Active', $e['is_active'] ?? 1, '') ?>
        </div>
        <?php if ($e && (int)$e['open_uses']): ?>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="f_rename_open" name="rename_open" value="1"><label class="form-check-label small" for="f_rename_open">If the name changes, use the new name on the <?= (int)$e['open_uses'] ?> open transaction(s) that have it</label></div></div>
        <?php endif; ?>
        <div class="col-12 form-text">The default tradename fills a new transaction; the others are chosen on the transaction form. An inactive tradename is not offered any more.</div>
      </div>
      <div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= $e ? 'Save' : 'Add' ?></button><?= $e ? btn_link(url($pageKey), 'Cancel') : '' ?></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
