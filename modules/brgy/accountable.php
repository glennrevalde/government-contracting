<?php
declare(strict_types=1);

/**
 * Barangay Contracts > People Accountable Set-up (v33)
 * Which user roles may be chosen for each person of a transaction: the sales representative (the positions that act
 * as sales representative) and the people accountable for the project (preparer, checker / reviewer, approver,
 * authorized submitter, project manager, collector). The drop-downs of the transaction form then list only the active
 * users in those roles. A function with no role ticked lists every active user, as before.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.accountable';
$roles = DB::pairs('SELECT id, name FROM roles ORDER BY name');

if (is_post()) {
    brgy_action(function () use ($roles) {
        Access::require('brgy.accountable', 'edit');
        $before = [];
        foreach (DB::all('SELECT function_code, role_id FROM brgy_function_roles') as $r) {
            $before[$r['function_code']][] = $roles[(int)$r['role_id']] ?? (string)$r['role_id'];
        }
        $after = [];
        DB::tx(function () use ($roles, &$after) {
            foreach (array_keys(Brgy::FUNCTIONS) as $fn) {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['roles_' . $fn] ?? [])), fn($id) => isset($roles[$id]))));
                DB::run('DELETE FROM brgy_function_roles WHERE function_code = :f', ['f' => $fn]);
                foreach ($ids as $rid) {
                    DB::insert('brgy_function_roles', ['function_code' => $fn, 'role_id' => $rid]);
                }
                if ($ids) {
                    $after[$fn] = array_map(fn($id) => $roles[$id], $ids);
                }
            }
        });
        Brgy::log('edit', 'people_accountable', null, 'People Accountable Set-up', ['before' => $before, 'after' => $after, 'page_key' => 'brgy.accountable']);
        return 'Saved. The drop-downs of the transaction form now list the users of the roles ticked.';
    }, url($pageKey));
}

$canEdit = Access::can($pageKey, 'edit');
$users = Brgy::users();
$roleUsers = [];
foreach (DB::all("SELECT ur.role_id, u.id, u.full_name FROM user_roles ur JOIN users u ON u.id = ur.user_id
                   WHERE u.status = 'active' AND u.is_platform = 0 AND u.approval_status = 'approved' ORDER BY u.full_name") as $r) {
    $roleUsers[(int)$r['role_id']][(int)$r['id']] = $r['full_name'];
}
// the people a function lists with its roles (all active users when none is ticked)
$offered = function (string $fn) use ($roleUsers, $users): array {
    $ids = Brgy::functionRoles($fn);
    if (!$ids) {
        return $users;
    }
    $out = [];
    foreach ($ids as $rid) {
        $out += $roleUsers[$rid] ?? [];
    }
    asort($out);
    return $out;
};
$names = function (array $list): string {
    if (!$list) {
        return '<span class="text-danger">No active user has these roles yet</span>';
    }
    $shown = array_slice($list, 0, 6);
    return h(implode(', ', $shown)) . (count($list) > 6 ? ' <span class="text-muted">and ' . (count($list) - 6) . ' more</span>' : '');
};
$help = [
    'sales_rep' => 'The Sales representative list of the transaction form shows only the users in these roles.',
    'preparer'  => 'Prepares (makes) the transaction and its papers.',
    'checker'   => 'Checks and reviews what the preparer made. Blank on a transaction: anyone with the review right.',
    'approver'  => 'Approves the bid, billing packs, change orders and forms. Blank on a transaction: anyone with the approve right.',
    'submitter' => 'The only person who records the bid submission (also chosen on the SOP step of the authorized representative).',
    'pm'        => 'Runs the delivery or the works once the contract is awarded.',
    'collector' => 'Follows up and receives the payments of the barangay.',
];
layout_start('People Accountable Set-up', brgy_layout(['actions' => page_link('admin.roles', [], 'User Role Configuration', 'btn-outline-secondary', 'person-badge')]));
?>
<form method="post" action="<?= h(url($pageKey)) ?>" data-no-class-check>
  <?= csrf_field() ?>
  <p class="text-muted small mb-3">Tick the user roles allowed for each function. The drop-downs of the transaction form (Barangay Contracts > Projects &amp; Contracts) then list only the active users who have one of those roles. With no role ticked, every active user is listed. A person already chosen on a saved transaction stays on it.</p>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-person-badge"></i> Sales representative</div>
    <div class="card-body">
      <div class="acc-row">
        <div class="acc-fn"><div class="fw-semibold">Positions acting as Sales Representative</div><div class="small text-muted"><?= h($help['sales_rep']) ?></div></div>
        <?= multi_check_field($canEdit ? 'roles_sales_rep' : '', 'Roles', $roles, Brgy::functionRoles('sales_rep'), ['all' => 'Every active user', 'noun' => 'roles', 'class' => 'acc-roles', 'id' => 'accSales']) ?>
        <div class="acc-who small"><span class="text-muted">Listed now (<?= count($offered('sales_rep')) ?>):</span> <?= $names($offered('sales_rep')) ?></div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-people"></i> People accountable for the project</div>
    <div class="card-body acc-list">
      <?php foreach (Brgy::FUNCTIONS as $fn => [$label]): if ($fn === 'sales_rep') { continue; } $list = $offered($fn); ?>
      <div class="acc-row">
        <div class="acc-fn"><div class="fw-semibold"><?= h($label) ?></div><div class="small text-muted"><?= h($help[$fn]) ?></div></div>
        <?= multi_check_field($canEdit ? 'roles_' . $fn : '', 'Roles allowed', $roles, Brgy::functionRoles($fn), ['all' => 'Every active user', 'noun' => 'roles', 'class' => 'acc-roles', 'id' => 'acc_' . $fn]) ?>
        <div class="acc-who small"><span class="text-muted">Listed now (<?= count($list) ?>):</span> <?= $names($list) ?></div>
      </div>
      <?php endforeach; ?>
      <div class="form-text mt-2">The preparer, checker and approver still have to be three different people when that control is on (Contracting Settings).</div>
    </div>
  </div>

  <?php if ($canEdit): ?>
    <div class="sticky-actions"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> Save</button> <?= btn_link(url($pageKey), 'Cancel') ?></div>
  <?php else: ?>
    <div class="alert alert-light border small">You may look at this set-up; changing it needs the Edit right on People Accountable Set-up.</div>
  <?php endif; ?>
</form>
<?php
layout_end();
