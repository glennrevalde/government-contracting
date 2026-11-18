<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Barangays & Procuring Entities
 * The government customers: official name, address and TIN, how they take submissions, their officials and contact
 * persons with the dates they served (a change of officials keeps the history), and everything done with them.
 * The seals of the barangay and of its city / municipality print on the letterheads of the forms of the SOP.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.entities';
$a = g('a', 'list');
$id = gint('id');

// ------------------------------------------------------------------ the seals (pictures for the letterheads)
if ($a === 'seal' && $id) {
    Access::require($pageKey, 'view');
    $e = Brgy::entity($id);
    $f = $e ? (string)(g('k') === 'lgu' ? $e['lgu_seal_file'] : $e['seal_file']) : '';
    $path = BrgyDocs::dir() . '/' . $f;
    if ($f === '' || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

// ------------------------------------------------------------------ officials and contacts
if ($a === 'view' && $id && is_post()) {
    $do = (string)p('do');
    brgy_action(function () use ($do, $id) {
        switch ($do) {
            case 'contact':
                BrgyOps::saveContact($id, $_POST, pint('contact_id'));
                return 'Saved. A new holder of a role closes the term of the one before.';
            case 'contact_end':
                Access::require('brgy.entities', 'edit');
                $c = DB::row('SELECT * FROM brgy_contacts WHERE id = :id AND entity_id = :e', ['id' => (int)pint('contact_id'), 'e' => $id]);
                if (!$c) {
                    fail('Contact not found.');
                }
                $to = pdate('valid_to', today());
                DB::update('brgy_contacts', ['valid_to' => $to], 'id = :id', ['id' => $c['id']]);
                Brgy::log('edit', 'contact', (int)$c['id'], $c['name'], ['reason' => 'Term ended ' . fdate($to), 'page_key' => 'brgy.entities']);
                return $c['name'] . ' is no longer in office from ' . fdate($to) . '.';
            case 'seal_upload':
            case 'seal_remove':
                Access::require('brgy.entities', 'edit');
                $e = Brgy::entity($id);
                $col = p('which') === 'lgu' ? 'lgu_seal_file' : 'seal_file';
                $name = null;
                if ($do === 'seal_upload') {
                    $f = brgy_file('seal');
                    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
                    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['jpg', 'jpeg', 'png'], true) || !@getimagesize($f['tmp_name'])) {
                        fail('Choose the picture of the seal (PNG or JPG).');
                    }
                    if ((int)$f['size'] > 3 * 1048576) {
                        fail('The picture of a seal should be under 3 MB.');
                    }
                    $name = 'seal_' . $id . '_' . ($col === 'lgu_seal_file' ? 'lgu_' : '') . bin2hex(random_bytes(4)) . '.' . ($ext === 'png' ? 'png' : 'jpg');
                    if (!move_uploaded_file($f['tmp_name'], BrgyDocs::dir() . '/' . $name) && !(PHP_SAPI === 'cli' && copy($f['tmp_name'], BrgyDocs::dir() . '/' . $name))) {
                        fail('The picture could not be saved (storage/contracts must be writable).');
                    }
                }
                if ($e[$col] && is_file(BrgyDocs::dir() . '/' . $e[$col])) {
                    @unlink(BrgyDocs::dir() . '/' . $e[$col]);
                }
                DB::update('brgy_entities', [$col => $name, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
                Brgy::log('edit', 'entity', $id, $e['official_name'], ['reason' => ($name ? 'Seal uploaded: ' : 'Seal removed: ') . ($col === 'lgu_seal_file' ? 'city / municipality' : 'barangay'), 'page_key' => 'brgy.entities']);
                return $name ? 'Seal saved: it prints on the letterheads of the forms.' : 'Seal removed.';
            case 'customer':
                Access::require('brgy.entities', 'edit');
                $cid = BrgyPost::ensureCustomer($id);
                return 'The entity is now a customer (' . DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $cid]) . ').';
            default:
                fail('Unknown action.');
        }
    }, url($pageKey, ['a' => 'view', 'id' => $id]));
}

// ------------------------------------------------------------------ new / edit
if (in_array($a, ['new', 'edit'], true)) {
    $e = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $e = Brgy::entity((int)$id);
        if (!$e) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    handle_post(function () use ($e, $pageKey) {
        $newId = BrgyOps::saveEntity($_POST, $e ? (int)$e['id'] : null);
        flash('success', $e ? 'Saved.' : 'Procuring entity added. Add its officials (Punong Barangay, BAC chair, treasurer) next.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $newId]));
    });
    $methods = is_post() ? (array)($_POST['submission_methods'] ?? []) : array_filter(explode(',', (string)($e['submission_methods'] ?? 'physical')));
    layout_start($e ? 'Edit ' . $e['official_name'] : 'New procuring entity', brgy_layout(['actions' => btn_link($e ? url($pageKey, ['a' => 'view', 'id' => $e['id']]) : url($pageKey), 'Cancel')]));
    ?>
    <form method="post" class="brgy-form" data-no-class-check><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-bank"></i> The entity</div><div class="card-body row g-2">
      <?= input_field('official_name', 'Official name', fv('official_name', $e), ['class' => 'col-md-6', 'required' => true, 'extra' => 'maxlength="160" placeholder="e.g. Barangay San Isidro"']) ?>
      <?= select_field('entity_type', 'Kind', Brgy::ENTITY_TYPES, fv('entity_type', $e, 'barangay'), ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= input_field('code', 'Short code', fv('code', $e), ['class' => 'col-md-3', 'extra' => 'maxlength="30"']) ?>
      <?= input_field('address', 'Address', fv('address', $e), ['class' => 'col-md-6']) ?>
      <?= input_field('barangay', 'Barangay', fv('barangay', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('city_municipality', 'City / municipality', fv('city_municipality', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('province', 'Province', fv('province', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('region', 'Region', fv('region', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('zip_code', 'ZIP', fv('zip_code', $e), ['class' => 'col-md-2']) ?>
      <?= input_field('tin', 'TIN', fv('tin', $e), ['class' => 'col-md-4']) ?>
      <?= input_field('phone', 'Phone', fv('phone', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('email', 'E-mail', fv('email', $e), ['class' => 'col-md-3', 'type' => 'email']) ?>
      <?php if ($e): ?><?= check_field('is_active', 'Active', fv('is_active', $e, 1), 'col-md-3') ?><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-send"></i> Submissions, funds and requirements</div><div class="card-body row g-2">
      <div class="col-12"><label class="form-label">Takes submissions by</label><div class="d-flex flex-wrap gap-3">
        <?php foreach (Brgy::SUB_METHODS as $k => $lbl): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="submission_methods[]" value="<?= h($k) ?>" <?= in_array($k, $methods, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= h($lbl) ?></span></label><?php endforeach; ?></div></div>
      <div class="col-md-4"><label class="form-label">Submission instructions (office, hours, copies)</label><textarea class="form-control form-control-sm" name="submission_notes" rows="3"><?= h(fv('submission_notes', $e)) ?></textarea></div>
      <div class="col-md-4"><label class="form-label">Funding and payment practice</label><textarea class="form-control form-control-sm" name="funding_notes" rows="3"><?= h(fv('funding_notes', $e)) ?></textarea></div>
      <div class="col-md-4"><label class="form-label">Particular requirements of this entity</label><textarea class="form-control form-control-sm" name="requirement_notes" rows="3"><?= h(fv('requirement_notes', $e)) ?></textarea></div>
      <?= check_field('extra_bid_papers', 'Asks for the ABC and the Aboitiz requirements with the bid (e.g. the Cebu City offices)', fv('extra_bid_papers', $e, 0), 'col-12') ?>
    </div></div>
    <div class="sticky-actions"><?= save_buttons($e ? 'Save' : 'Add entity', $e ? url($pageKey, ['a' => 'view', 'id' => $e['id']]) : url($pageKey), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ view
if ($a === 'view' && $id) {
    $e = Brgy::entity($id);
    if (!$e) {
        redirect(url($pageKey));
    }
    $canEdit = Access::can($pageKey, 'edit');
    $contacts = DB::all('SELECT * FROM brgy_contacts WHERE entity_id = :e ORDER BY (valid_to IS NULL OR valid_to >= CURDATE()) DESC, role_code, valid_from DESC, id DESC', ['e' => $id]);
    $projects = DB::all('SELECT * FROM brgy_projects WHERE entity_id = :e ORDER BY id DESC', ['e' => $id]);
    $editContact = gint('contact') ? DB::row('SELECT * FROM brgy_contacts WHERE id = :id AND entity_id = :e', ['id' => gint('contact'), 'e' => $id]) : null;
    $actions = ($canEdit ? btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
        . page_link('brgy.projects', ['a' => 'new', 'entity_id' => $id], 'New opportunity', 'btn-primary', 'plus-lg', 'create');
    layout_start($e['official_name'], brgy_layout(['actions' => $actions]));
    $won = count(array_filter($projects, fn($x) => in_array($x['status'], ['awarded', 'closed'], true)));
    $lost = count(array_filter($projects, fn($x) => $x['status'] === 'lost'));
    $ar = 0.0;
    $jobs = array_values(array_filter(array_map(fn($x) => (int)$x['job_customer_id'], $projects)));
    if ($jobs) {
        [$jin, $jp] = DB::in('j', $jobs);
        [$ain, $ap] = DB::in('a', Accts::arIds() ?: [0]);
        $ar = (float)DB::value("SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE customer_id IN $jin AND account_id IN $ain", $jp + $ap);
    }
    echo brgy_figures(['Projects' => [(string)count($projects), '', $won . ' won, ' . $lost . ' lost'], 'Contracts awarded' => [money(array_sum(array_map(fn($x) => in_array($x['status'], ['awarded', 'closed'], true) ? (float)$x['contract_amount'] + (float)$x['co_amount'] : 0, $projects)))],
        'Receivable (ledger)' => [money($ar), $ar > 0 ? 'warn' : ''], 'Status' => [$e['is_active'] ? 'Active' : 'Inactive', $e['is_active'] ? 'ok' : '']]);
    ?>
    <div class="row g-3">
      <div class="col-xl-7">
        <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-people"></i> Officials and contact persons</span></div>
          <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Role</th><th>Name</th><th>Contact</th><th>In office</th><th></th></tr></thead>
          <?php foreach ($contacts as $c): $past = $c['valid_to'] && $c['valid_to'] < today(); ?>
            <tr class="<?= $past ? 'text-muted' : '' ?>"><td class="small"><?= h(Brgy::label(Brgy::CONTACT_ROLES, $c['role_code'])) ?><?= (int)$c['is_signatory'] ? '<div class="text-muted">signatory</div>' : '' ?></td>
              <td class="wrap-link"><?= h($c['name']) ?><?= $c['position'] ? '<div class="small text-muted">' . h($c['position']) . ($c['department'] ? ', ' . h($c['department']) : '') . '</div>' : '' ?></td>
              <td class="small"><?= h($c['phone']) ?><?= $c['email'] ? '<div><a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a></div>' : '' ?></td>
              <td class="small text-nowrap"><?= $c['valid_from'] ? h(fdate($c['valid_from'])) : '' ?><?= $c['valid_to'] ? ' to ' . h(fdate($c['valid_to'])) : ($c['valid_from'] ? ' to now' : '') ?></td>
              <td class="text-end text-nowrap"><?php if ($canEdit): ?><a class="btn btn-sm btn-link p-0" href="<?= h(url($pageKey, ['a' => 'view', 'id' => $id, 'contact' => $c['id']])) ?>#contactForm">Edit</a><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$contacts): ?><tr><td colspan="5" class="text-muted py-3">No officials recorded yet. The forms take the Punong Barangay, the BAC chair and the treasurer from here.</td></tr><?php endif; ?>
        </table></div></div>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-briefcase"></i> Projects</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
          <thead><tr><th>Project</th><th>Phase</th><th>Status</th><th class="num">Amount</th></tr></thead>
          <?php foreach ($projects as $x): ?><tr><td class="wrap-link"><a href="<?= h(url('brgy.projects', ['a' => 'view', 'id' => $x['id']])) ?>"><?= h($x['project_no']) ?></a> <span class="small"><?= h(mb_strimwidth($x['title'], 0, 70, '...')) ?></span></td>
            <td class="small"><?= h(Brgy::phaseLabel($x['phase'])) ?></td><td><?= Brgy::st($x['status']) ?></td><td class="num"><?= in_array($x['status'], ['awarded', 'closed'], true) ? money((float)$x['contract_amount'] + (float)$x['co_amount']) : money($x['bid_amount'] ?: $x['abc_amount']) ?></td></tr><?php endforeach; ?>
          <?php if (!$projects): ?><tr><td colspan="4" class="text-muted py-3">No projects with this entity yet.</td></tr><?php endif; ?>
        </table></div></div>
      </div>
      <div class="col-xl-5">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-info-circle"></i> Details</div><div class="card-body"><dl class="dl-grid">
          <dt>Kind</dt><dd><?= h(Brgy::label(Brgy::ENTITY_TYPES, $e['entity_type'])) ?></dd>
          <?php foreach (array_filter(['Address' => trim(implode(', ', array_filter([$e['address'], $e['city_municipality'], $e['province'], $e['zip_code']])), ', '), 'Region' => $e['region'], 'TIN' => $e['tin'], 'Phone' => $e['phone'], 'E-mail' => $e['email'],
              'Submissions by' => implode(', ', array_map(fn($k) => Brgy::label(Brgy::SUB_METHODS, $k), array_filter(explode(',', (string)$e['submission_methods']))))]) as $k => $v): ?>
            <dt><?= h($k) ?></dt><dd><?= h((string)$v) ?></dd>
          <?php endforeach; ?>
          <dt>Bidding papers</dt><dd><?= (int)$e['extra_bid_papers'] ? 'Also the ABC and the Aboitiz requirements' : 'The usual papers' ?></dd>
          <dt>As a customer</dt><dd><?= $e['customer_id'] ? safe_link('cust.center', ['id' => $e['customer_id']], (string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $e['customer_id']])) : '<span class="text-muted">made at the first award</span>' ?></dd>
        </dl>
        <?php foreach (['submission_notes' => 'Submission instructions', 'funding_notes' => 'Funding and payment practice', 'requirement_notes' => 'Particular requirements'] as $k => $lbl): if (!$e[$k]) { continue; } ?>
          <div class="small text-muted mt-2"><?= h($lbl) ?></div><div class="pre-text small"><?= h($e[$k]) ?></div>
        <?php endforeach; ?>
        <?php $reqs = DB::all("SELECT name, status FROM brgy_requirements WHERE entity_id = :e AND is_active = 1", ['e' => $id]); if ($reqs): ?>
          <div class="small text-muted mt-2">Its own requirements in the library</div><ul class="small mb-0"><?php foreach ($reqs as $r): ?><li><?= h($r['name']) ?><?= $r['status'] === 'proposed' ? ' <span class="text-warning-emphasis">(proposed)</span>' : '' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        </div>
        <?php if ($canEdit && !$e['customer_id']): ?><form class="card-footer" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="customer"><button class="btn btn-sm btn-outline-secondary">Make it a customer now</button></form><?php endif; ?>
        </div>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-award"></i> Seals for the letterheads</div><div class="card-body"><div class="seal-grid">
          <?php foreach (['seal' => ['seal_file', 'Barangay'], 'lgu' => ['lgu_seal_file', 'City / municipality']] as $k => [$col, $lbl]): ?>
            <div class="seal-box"><div class="seal-pic"><?= $e[$col] ? '<img src="' . h(url($pageKey, ['a' => 'seal', 'id' => $id, 'k' => $k, 'v' => substr(md5((string)$e[$col]), 0, 8)])) . '" alt="Seal: ' . h($lbl) . '">' : '<i class="bi bi-image"></i>' ?></div>
              <div class="small fw-semibold"><?= h($lbl) ?></div>
              <?php if ($canEdit): ?><form method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-1 mt-1"><?= csrf_field() ?><input type="hidden" name="which" value="<?= $k ?>">
                <input class="form-control form-control-sm" type="file" name="seal" accept=".png,.jpg,.jpeg" aria-label="Picture of the seal">
                <div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary flex-fill" name="do" value="seal_upload">Upload</button><?= $e[$col] ? '<button class="btn btn-sm btn-outline-danger" name="do" value="seal_remove" formnovalidate>Remove</button>' : '' ?></div></form><?php endif; ?></div>
          <?php endforeach; ?>
        </div><div class="form-text">The request for quotation, invitation to bid, BAC resolution, notices and certificate of completion carry them, left and right of the letterhead. A PNG with a transparent background prints best.</div></div></div>
        <?php if ($canEdit): $c = $editContact; ?>
        <form class="card" id="contactForm" method="post" action="<?= h(url($pageKey, ['a' => 'view', 'id' => $id])) ?>" data-no-class-check><?= csrf_field() ?><input type="hidden" name="do" value="contact"><?= $c ? '<input type="hidden" name="contact_id" value="' . (int)$c['id'] . '">' : '' ?>
          <div class="card-header"><i class="bi bi-person-plus"></i> <?= $c ? 'Edit ' . h($c['name']) : 'Add an official or contact person' ?></div><div class="card-body row g-2">
            <?= select_field('role_code', 'Role', Brgy::CONTACT_ROLES, $c['role_code'] ?? 'punong_barangay', ['class' => 'col-12', 'extra' => 'data-no-combo']) ?>
            <?= input_field('name', 'Name', $c['name'] ?? '', ['class' => 'col-12', 'required' => true]) ?>
            <?= input_field('position', 'Position / title', $c['position'] ?? '', ['class' => 'col-6']) ?>
            <?= input_field('department', 'Office', $c['department'] ?? '', ['class' => 'col-6']) ?>
            <?= input_field('phone', 'Phone', $c['phone'] ?? '', ['class' => 'col-6']) ?>
            <?= input_field('email', 'E-mail', $c['email'] ?? '', ['class' => 'col-6', 'type' => 'email']) ?>
            <?= input_field('valid_from', 'In office from', $c['valid_from'] ?? '', ['type' => 'date', 'class' => 'col-6']) ?>
            <?= input_field('valid_to', 'Until (blank: now)', $c['valid_to'] ?? '', ['type' => 'date', 'class' => 'col-6']) ?>
            <?= check_field('is_signatory', 'Signs contracts / documents for the entity', $c['is_signatory'] ?? 0, 'col-12') ?>
            <?= input_field('notes', 'Notes', $c['notes'] ?? '', ['class' => 'col-12']) ?>
            <div class="col-12 form-text">After an election or a reorganization, add the new official with the date they took office; the one before is kept with the dates they served, and forms dated earlier still show the right name.</div>
          </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button><?= $c ? btn_link(url($pageKey, ['a' => 'view', 'id' => $id]), 'Cancel') : '' ?></div></form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$rows = DB::all("SELECT e.*, (SELECT COUNT(*) FROM brgy_projects p WHERE p.entity_id = e.id) AS n_projects,
                        (SELECT COUNT(*) FROM brgy_projects p WHERE p.entity_id = e.id AND p.status = 'awarded') AS n_live,
                        (SELECT c.name FROM brgy_contacts c WHERE c.entity_id = e.id AND c.role_code = 'punong_barangay' AND (c.valid_to IS NULL OR c.valid_to >= CURDATE()) ORDER BY c.valid_from DESC LIMIT 1) AS head
                   FROM brgy_entities e" . ($q !== '' ? ' WHERE e.official_name LIKE :q OR e.city_municipality LIKE :q OR e.province LIKE :q OR e.code LIKE :q' : '') . ' ORDER BY e.is_active DESC, e.official_name', $q !== '' ? ['q' => "%$q%"] : []);
layout_start('Barangays & Procuring Entities', brgy_layout(['actions' => page_link($pageKey, ['a' => 'new'], 'New entity', 'btn-primary', 'plus-lg', 'create')]));
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <div class="col-md-6"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, city / municipality, province, code" data-live-filter="#entTable tbody tr" data-live-enter-submits></div>
  <div class="col-md-1"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i></button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="entTable">
  <thead><tr><th>Entity</th><th>Location</th><th>Head</th><th class="num">Projects</th><th class="num">Contracts in force</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="wrap-link"><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= h($r['official_name']) ?></b></a><div class="small text-muted"><?= h(Brgy::label(Brgy::ENTITY_TYPES, $r['entity_type'])) ?><?= $r['tin'] ? ', TIN ' . h($r['tin']) : '' ?></div></td>
      <td class="small"><?= h(trim(implode(', ', array_filter([$r['city_municipality'], $r['province']])), ', ')) ?></td><td class="small"><?= h($r['head']) ?></td>
      <td class="num"><?= (int)$r['n_projects'] ?></td><td class="num"><?= (int)$r['n_live'] ?: '' ?></td><td><?= Brgy::st($r['is_active'] ? 'ok' : 'void', $r['is_active'] ? 'Active' : 'Inactive') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted py-4 text-center">No procuring entities yet.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php
layout_end();
