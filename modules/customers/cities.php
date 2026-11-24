<?php
declare(strict_types=1);

/**
 * Customers > Lists > City/Municipality List (v38)
 * The cities and municipalities chosen on the customer form. A city or municipality is added, changed or deleted
 * here; it is deleted only when no customer uses it (made inactive instead, it is no longer offered).
 */
$pageKey = 'cust.cities';
$editId = gint('edit');
$q = trim((string)g('q'));

/** the customers that use a city or municipality */
$uses = fn(int $id): int => (int)DB::value('SELECT COUNT(*) FROM customers WHERE city_id = :id', ['id' => $id]);

handle_post(function () use ($pageKey, $uses) {
    $id = pint('id');
    if (p('do') === 'delete') {
        Access::require($pageKey, 'delete');
        $old = $id ? DB::row('SELECT * FROM cities WHERE id = :id', ['id' => $id]) : null;
        if (!$old) {
            fail('That city or municipality is not on the list any more.');
        }
        $n = $uses((int)$old['id']);
        if ($n > 0) {
            fail($old['name'] . ' cannot be deleted: ' . $n . ' customer' . ($n === 1 ? ' uses' : 's use') . ' it. Choose another city or municipality on '
                . ($n === 1 ? 'that customer' : 'those customers') . ' first, or make it inactive so it is no longer offered.');
        }
        DB::tx(function () use ($old) {
            DB::run('DELETE FROM cities WHERE id = :id', ['id' => (int)$old['id']]);
        });
        Audit::log('delete', 'cust', 'city', (int)$old['id'], $old['name'], ['before' => $old, 'page_key' => $pageKey]);
        flash('success', $old['name'] . ' deleted from the list.');
        redirect(url($pageKey));
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $clean = fn(string $v, int $len) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', $v)), 0, $len);
    $row = ['name' => $clean((string)p('name'), 120), 'province' => $clean((string)p('province'), 120) ?: null, 'zip_code' => $clean((string)p('zip_code'), 10) ?: null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
    if ($row['name'] === '') {
        fail('Enter the name of the city or municipality.');
    }
    if ($row['zip_code'] !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,9}$/', $row['zip_code'])) {
        fail('Enter the zip code with numbers (and letters, spaces or a dash) only, up to 10 characters, for example 6015.');
    }
    $dup = DB::value('SELECT id FROM cities WHERE name = :n AND COALESCE(province, \'\') = :p AND id <> :id', ['n' => $row['name'], 'p' => (string)$row['province'], 'id' => (int)$id]);
    if ($dup) {
        fail($row['name'] . ($row['province'] ? ', ' . $row['province'] : '') . ' is already on the list.');
    }
    if ($id) {
        $old = DB::row('SELECT * FROM cities WHERE id = :id', ['id' => $id]);
        if (!$old) {
            fail('That city or municipality is not on the list any more.');
        }
        DB::update('cities', $row, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'cust', 'city', $id, $row['name'], ['before' => $old, 'after' => $row, 'page_key' => $pageKey]);
        flash('success', $row['name'] . ' saved.');
    } else {
        $id = DB::insert('cities', $row + ['created_by' => Auth::id()]);
        Audit::log('create', 'cust', 'city', $id, $row['name'], ['snapshot' => $row, 'page_key' => $pageKey]);
        flash('success', $row['name'] . ' added to the list.');
    }
    // back to the customer form that opened the list, when it did
    $back = (string)p('back');
    redirect($back !== '' && str_starts_with($back, 'index.php?p=cust.center') ? $back : url($pageKey));
});

$rows = DB::all('SELECT c.*, (SELECT COUNT(*) FROM customers k WHERE k.city_id = c.id) AS uses FROM cities c ORDER BY c.is_active DESC, c.name, c.province');
$e = $editId ? DB::row('SELECT c.*, (SELECT COUNT(*) FROM customers k WHERE k.city_id = c.id) AS uses FROM cities c WHERE c.id = :id', ['id' => $editId]) : null;
$canCreate = Access::can($pageKey, 'create');
$canEdit = Access::can($pageKey, 'edit');
$canDelete = Access::can($pageKey, 'delete');
$back = (string)g('back');
$back = str_starts_with($back, 'index.php?p=cust.center') ? $back : '';
layout_start('City/Municipality List', ['actions' => ($back !== '' ? btn_link($back, 'Back to the customer', 'btn-outline-secondary', 'arrow-left') . ' ' : '')
    . page_link('cust.center', [], 'Customer Center', 'btn-outline-secondary', 'people')]);
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-geo-alt"></i> Cities and municipalities</span>
        <div class="live-search city-find"><i class="bi bi-search"></i>
          <input class="form-control form-control-sm" value="<?= h($q) ?>" placeholder="Type to find" autocomplete="off" aria-label="Find a city or municipality"
                 data-live-filter="#cityList tr[data-filter-text]" data-live-count="#cityCount" data-live-empty="#cityNone"></div>
      </div>
      <?php if (!$rows): ?>
        <div class="card-body small text-muted">No city or municipality yet. Add the ones your customers are in; the customer form then offers them in its City/Municipality list.</div>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 resp-cards city-table">
        <thead><tr><th>City / municipality</th><th>Province</th><th>Zip code</th><th class="num">Customers</th><th class="text-end">Actions</th></tr></thead>
        <tbody id="cityList">
        <?php foreach ($rows as $r): ?>
          <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>" data-filter-text="<?= h($r['name'] . ' ' . $r['province'] . ' ' . $r['zip_code']) ?>">
            <td data-label="City / municipality"><span class="fw-semibold"><?= h($r['name']) ?></span><?= (int)$r['is_active'] ? '' : ' <span class="small">(inactive)</span>' ?></td>
            <td data-label="Province"><?= h((string)$r['province']) ?></td>
            <td data-label="Zip code"><?= h((string)$r['zip_code']) ?></td>
            <td data-label="Customers" class="num"><?= (int)$r['uses'] ?: '<span class="text-muted">Not used</span>' ?></td>
            <td class="text-end text-nowrap">
              <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['edit' => $r['id']] + ($back !== '' ? ['back' => $back] : []))) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
              <?php if ($canDelete): ?>
                <?php if ((int)$r['uses'] === 0): ?>
                  <form method="post" action="<?= h(url($pageKey)) ?>" class="d-inline" data-confirm="Delete <?= h($r['name']) ?> from the list?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete <?= h($r['name']) ?>"><i class="bi bi-trash"></i></button></form>
                <?php else: ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Used by <?= (int)$r['uses'] ?> customer(s): it cannot be deleted" aria-label="<?= h($r['name']) ?> is used and cannot be deleted"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr id="cityNone"><td colspan="5" class="text-muted small">No city or municipality matches.</td></tr>
        </tbody>
      </table></div>
      <div class="card-footer small text-muted"><span id="cityCount"><?= count($rows) ?></span> on the list</div>
      <?php endif; ?>
    </div>
    <p class="small text-muted mt-2">A city or municipality used by a customer cannot be deleted; make it inactive instead and it is no longer offered on the customer form. Renaming it here renames it on every customer that has it.</p>
  </div>
  <div class="col-xl-4">
    <?php if ($e ? $canEdit : $canCreate): ?>
    <form class="card" method="post" action="<?= h(url($pageKey)) ?>"><?= csrf_field() ?><?= $e ? '<input type="hidden" name="id" value="' . (int)$e['id'] . '">' : '' ?>
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <div class="card-header"><i class="bi bi-<?= $e ? 'pencil-square' : 'plus-lg' ?>"></i> <?= $e ? 'Edit ' . h($e['name']) : 'Add a city or municipality' ?></div>
      <div class="card-body row g-2">
        <?= input_field('name', 'City / municipality', fv('name', $e), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="120" autocomplete="off" placeholder="Lapu-Lapu City"']) ?>
        <?= input_field('province', 'Province', fv('province', $e), ['class' => 'col-sm-7 col-xl-12', 'extra' => 'maxlength="120" autocomplete="off" placeholder="Cebu"']) ?>
        <?= input_field('zip_code', 'Zip code', fv('zip_code', $e), ['class' => 'col-sm-5 col-xl-12', 'extra' => 'maxlength="10" inputmode="numeric" placeholder="6015"']) ?>
        <?= check_field('is_active', 'Active (offered on the customer form)', fv('is_active', $e, 1), 'col-12') ?>
        <?php if ($e && (int)$e['uses']): ?><div class="col-12 form-text"><?= (int)$e['uses'] ?> customer(s) use it.</div><?php endif; ?>
      </div>
      <div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= $e ? 'Save' : 'Add' ?></button><?= $e ? btn_link(url($pageKey, $back !== '' ? ['back' => $back] : []), 'Cancel') : '' ?></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
