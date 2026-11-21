<?php
declare(strict_types=1);

/**
 * Barangay Contracts > People Accountable Set-up (v33, v34)
 * Who may be chosen for each person of a transaction: the sales representative (the positions that act as sales
 * representative) and the people accountable for the project (preparer, checker / reviewer, approver, authorized
 * submitter, project manager, collector).
 * v34: each function is set either by ROLES (the active users of the roles ticked) or by USERS (the users ticked by
 * name). The drop-downs of the transaction form then list only those people. Nothing ticked: every active user.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.accountable';
$roles = DB::pairs('SELECT id, name FROM roles ORDER BY name');
$users = Brgy::users();

if (is_post()) {
    brgy_action(function () use ($roles, $users) {
        Access::require('brgy.accountable', 'edit');
        $allUsers = DB::pairs('SELECT id, full_name FROM users');
        $describe = function () use ($roles, $allUsers): array {
            $out = [];
            foreach (DB::all('SELECT function_code, role_id FROM brgy_function_roles') as $r) {
                $out[$r['function_code']]['roles'][] = $roles[(int)$r['role_id']] ?? (string)$r['role_id'];
            }
            foreach (DB::all('SELECT function_code, user_id FROM brgy_function_users') as $r) {
                $out[$r['function_code']]['users'][] = $allUsers[(int)$r['user_id']] ?? (string)$r['user_id'];
            }
            return $out;
        };
        $before = $describe();
        DB::tx(function () use ($roles, $users) {
            foreach (array_keys(Brgy::FUNCTIONS) as $fn) {
                $mode = ($_POST['mode_' . $fn] ?? 'roles') === 'users' ? 'users' : 'roles';
                $roleIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['roles_' . $fn] ?? [])), fn($id) => isset($roles[$id]))));
                $userIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['users_' . $fn] ?? [])), fn($id) => isset($users[$id]))));
                // a function is set one way: by its roles, or by the users named for it
                DB::run('DELETE FROM brgy_function_roles WHERE function_code = :f', ['f' => $fn]);
                DB::run('DELETE FROM brgy_function_users WHERE function_code = :f', ['f' => $fn]);
                if ($mode === 'roles') {
                    foreach ($roleIds as $rid) {
                        DB::insert('brgy_function_roles', ['function_code' => $fn, 'role_id' => $rid]);
                    }
                } else {
                    foreach ($userIds as $uid) {
                        DB::insert('brgy_function_users', ['function_code' => $fn, 'user_id' => $uid]);
                    }
                }
            }
        });
        Brgy::forgetFunctionRoles();
        Brgy::log('edit', 'people_accountable', null, 'People Accountable Set-up', ['before' => $before, 'after' => $describe(), 'page_key' => 'brgy.accountable']);
        return 'Saved. The drop-downs of the transaction form now list the people allowed for each function.';
    }, url($pageKey));
}

$canEdit = Access::can($pageKey, 'edit');
$names = function (array $list): string {
    if (!$list) {
        return '<span class="text-danger">Nobody yet: tick a role that has active users, or tick users</span>';
    }
    $shown = array_slice($list, 0, 6);
    return h(implode(', ', $shown)) . (count($list) > 6 ? ' <span class="text-muted">and ' . (count($list) - 6) . ' more</span>' : '');
};
$help = [
    'sales_rep' => 'The Sales representative list of the transaction form shows only the people allowed here.',
    'preparer'  => 'Prepares (makes) the transaction and its papers.',
    'checker'   => 'Checks and reviews what the preparer made. Blank on a transaction: anyone with the review right.',
    'approver'  => 'Approves the bid, billing packs, change orders and forms. Blank on a transaction: anyone with the approve right.',
    'submitter' => 'The only person who records the bid submission (also chosen on the SOP step of the authorized representative).',
    'pm'        => 'Runs the delivery or the works once the contract is awarded.',
    'collector' => 'Follows up and receives the payments of the barangay.',
];
/** one function: its name, Roles or Users, the list of each, and the people listed now */
$row = function (string $fn, string $title) use ($canEdit, $roles, $users, $names, $help): string {
    $mode = Brgy::functionMode($fn) === 'users' ? 'users' : 'roles';
    $list = Brgy::usersFor($fn);
    $dis = $canEdit ? '' : ' disabled';
    ob_start(); ?>
      <div class="acc-row" data-acc="<?= h($fn) ?>">
        <div class="acc-fn"><div class="fw-semibold"><?= h($title) ?></div><div class="small text-muted"><?= h($help[$fn]) ?></div></div>
        <div class="acc-pick">
          <div class="acc-mode btn-group btn-group-sm" role="radiogroup" aria-label="<?= h($title) ?>: allow by">
            <input class="btn-check" type="radio" name="mode_<?= h($fn) ?>" id="m_<?= h($fn) ?>_roles" value="roles" <?= $mode === 'roles' ? 'checked' : '' ?><?= $dis ?>>
            <label class="btn btn-sm btn-outline-secondary" for="m_<?= h($fn) ?>_roles"><i class="bi bi-person-badge"></i> Roles</label>
            <input class="btn-check" type="radio" name="mode_<?= h($fn) ?>" id="m_<?= h($fn) ?>_users" value="users" <?= $mode === 'users' ? 'checked' : '' ?><?= $dis ?>>
            <label class="btn btn-sm btn-outline-secondary" for="m_<?= h($fn) ?>_users"><i class="bi bi-person"></i> Users</label>
          </div>
          <div data-acc-for="roles" <?= $mode === 'users' ? 'hidden' : '' ?>><?= multi_check_field($canEdit ? 'roles_' . $fn : '', 'Roles allowed', $roles, Brgy::functionRoles($fn), ['all' => 'Every active user', 'noun' => 'roles', 'class' => 'acc-roles', 'id' => 'acc_' . $fn]) ?></div>
          <div data-acc-for="users" <?= $mode === 'roles' ? 'hidden' : '' ?>><?= multi_check_field($canEdit ? 'users_' . $fn : '', 'Users allowed', $users, Brgy::functionUsers($fn), ['all' => 'Every active user', 'noun' => 'users', 'class' => 'acc-roles', 'id' => 'accu_' . $fn]) ?></div>
        </div>
        <div class="acc-who small"><span class="text-muted">Listed now (<?= count($list) ?>):</span> <?= $names($list) ?></div>
      </div>
    <?php return (string)ob_get_clean();
};
layout_start('People Accountable Set-up', brgy_layout(['actions' => page_link('admin.roles', [], 'User Role Configuration', 'btn-outline-secondary', 'person-badge')]));
?>
<form method="post" action="<?= h(url($pageKey)) ?>" data-no-class-check>
  <?= csrf_field() ?>
  <p class="text-muted small mb-3">For each function, choose <strong>Roles</strong> to allow the active users of the roles you tick, or <strong>Users</strong> to name the people allowed. The drop-downs of the transaction form (Barangay Contracts &gt; Projects &amp; Contracts) then list only them. With nothing ticked, every active user is listed. A person already chosen on a saved transaction stays on it.</p>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-person-badge"></i> Sales representative</div>
    <div class="card-body acc-list">
      <?= $row('sales_rep', 'Positions acting as Sales Representative') ?>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><i class="bi bi-people"></i> People accountable for the project</div>
    <div class="card-body acc-list">
      <?php foreach (Brgy::FUNCTIONS as $fn => [$label]) { if ($fn !== 'sales_rep') { echo $row($fn, $label); } } ?>
      <div class="form-text mt-2">The preparer, checker and approver still have to be three different people when that control is on (Contracting Settings).</div>
    </div>
  </div>

  <?php if ($canEdit): ?>
    <div class="sticky-actions"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> Save</button> <?= btn_link(url($pageKey), 'Cancel') ?></div>
  <?php else: ?>
    <div class="alert alert-light border small">You may look at this set-up; changing it needs the Edit right on People Accountable Set-up.</div>
  <?php endif; ?>
</form>
<script>
// Roles or Users: show the list of the choice made
document.querySelectorAll('[data-acc]').forEach((row) => {
  const show = () => {
    const m = (row.querySelector('input[type=radio]:checked') || {}).value || 'roles';
    row.querySelectorAll('[data-acc-for]').forEach((x) => { x.hidden = x.dataset.accFor !== m; });
  };
  row.querySelectorAll('input[type=radio]').forEach((r) => r.addEventListener('change', show));
});
</script>
<?php
layout_end();
