<?php
declare(strict_types=1);

/**
 * Version 33: checks of the tradenames, the people accountable, the transaction form and the menus, run through the
 * same code the pages use.
 *
 *   php tools/test_v33.php --yes [--company=N]
 *
 * It WRITES test records (tradenames "Test Tradename ...", an entity "Barangay V33 ...", transactions, users named
 * v33_t_...), so run it on a copy of the database or on the demo database, never on the books of a company in use.
 * The People Accountable Set-up it changes is put back as it was at the end. It checks:
 *   - the Tradenames list: the default, a new transaction taking it, a tradename off the list refused, the name kept
 *     by a saved transaction when the tradename is removed, the printed transaction form;
 *   - the transaction form without the positions of the requestor and the inspector (the printed form too);
 *   - People Accountable Set-up: the drop-downs list only the users of the roles set, a person outside them is
 *     refused (sales representative, project manager, the SOP step of the authorized representative), a person
 *     already on a transaction may stay, and every user is listed again when no role is set;
 *   - the menus: Home, Report Center, Financial; the twelve entries of the Report Center; Home without them.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This writes test records. Run it on a copy of the database: php tools/test_v33.php --yes\n");
    exit(2);
}
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/views/layout.php';
Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if (Schema::pending()) {
    fwrite(STDERR, 'Run the pending migrations first: ' . implode(', ', Schema::pending()) . "\n");
    exit(2);
}

$pass = 0;
$failures = [];
$ok = function (string $what, bool $cond, string $detail = '') use (&$pass, &$failures) {
    if ($cond) {
        $pass++;
        echo "  ok    $what\n";
    } else {
        $failures[] = $what . ($detail !== '' ? " ($detail)" : '');
        echo "  FAIL  $what" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
};
$refused = function (string $what, callable $fn, string $like = '') use ($ok) {
    try {
        $fn();
        $ok($what . ': refused', false, 'it went through');
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        $msg = str_replace("\n", ' ', $e->getMessage());
        $ok($what . ': refused', $like === '' || stripos($msg, $like) !== false, $like !== '' && stripos($msg, $like) === false ? 'message: ' . $msg : '');
        echo "        \"" . mb_strimwidth($msg, 0, 150, '...') . "\"\n";
    }
};
$does = function (string $what, callable $fn) use ($ok) {
    try {
        $r = $fn();
        $ok($what, true);
        return $r;
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        $ok($what, false, $e->getMessage());
        return null;
    }
};
$stamp = date('His');
$admin = (int)(DB::value("SELECT id FROM users WHERE username = 'admin'") ?: DB::value("SELECT MIN(id) FROM users WHERE status = 'active'"));
Auth::actAs($admin);
$roleId = fn(string $n) => (int)DB::value('SELECT id FROM roles WHERE name = :n', ['n' => $n]);
$user = function (string $u, string $name, string $role) use ($roleId) {
    $id = (int)DB::value('SELECT id FROM users WHERE username = :u', ['u' => $u]);
    if (!$id) {
        $id = DB::insert('users', ['username' => $u, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'full_name' => $name, 'status' => 'active',
            'must_change_password' => 1, 'password_changed_at' => date('Y-m-d H:i:s')]);
        DB::insert('user_roles', ['user_id' => $id, 'role_id' => $roleId($role)]);
    }
    return $id;
};
foreach (['Sales Representative', 'Contracts - Project Manager', 'Contracts - Bid & Compliance Officer'] as $r) {
    if (!$roleId($r)) {
        fwrite(STDERR, "Role \"$r\" is missing.\n");
        exit(2);
    }
}
$rep = $user('v33_t_rep', 'V33 Test Sales Rep', 'Sales Representative');
$pm = $user('v33_t_pm', 'V33 Test Project Manager', 'Contracts - Project Manager');
$clerk = $user('v33_t_clerk', 'V33 Test Bid Officer', 'Contracts - Bid & Compliance Officer');
$keepRoles = DB::all('SELECT function_code, role_id FROM brgy_function_roles');
$keepTradename = Settings::get('brgy_tradename');
// the function roles are read once per request; the test reads them again after each change
$setRoles = function (string $fn, array $roleIds): void {
    DB::run('DELETE FROM brgy_function_roles WHERE function_code = :f', ['f' => $fn]);
    foreach ($roleIds as $r) {
        DB::insert('brgy_function_roles', ['function_code' => $fn, 'role_id' => $r]);
    }
    Brgy::forgetFunctionRoles();
};

echo "Version 33 checks\n\n1. Tradenames\n";
$tn1 = 'Test Tradename A ' . $stamp;
$tn2 = 'Test Tradename B ' . $stamp;
DB::run('UPDATE brgy_tradenames SET is_default = 0');
$t1 = DB::insert('brgy_tradenames', ['name' => $tn1, 'is_default' => 1, 'sort_order' => 1]);
$t2 = DB::insert('brgy_tradenames', ['name' => $tn2, 'is_default' => 0, 'sort_order' => 2]);
$ok('The default tradename is the default of the list', Brgy::defaultTradename() === $tn1, Brgy::defaultTradename());
$ok('The list offers both, the default first', array_slice(array_keys(Brgy::tradenames()), 0, 1) === [$tn1] && isset(Brgy::tradenames()[$tn2]));
$entity = $does('Procuring entity added', fn() => BrgyOps::saveEntity(['official_name' => 'Barangay V33 ' . $stamp, 'address' => 'Test Street', 'tin' => '000-000-000-000', 'submission_methods' => ['physical']]));
$svp = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'small_value'") ?: null;
$base = ['entity_id' => $entity, 'category' => 'goods', 'mode_id' => $svp, 'abc_amount' => '50000', 'process' => 'svp', 'txn_type' => 'purchases',
    'requestor' => 'Juan Dela Cruz', 'inspector' => 'Maria Clara', 'purpose' => 'Office supplies'];
$p1 = $does('A transaction saved without a tradename takes the default', fn() => BrgyOps::saveProject($base + ['title' => 'V33 default tradename ' . $stamp]));
$ok('... and it is the default tradename', $p1 && Brgy::project((int)$p1)['tradename'] === $tn1, (string)($p1 ? Brgy::project((int)$p1)['tradename'] : ''));
$p2 = $does('A transaction with the second tradename', fn() => BrgyOps::saveProject($base + ['title' => 'V33 second tradename ' . $stamp, 'tradename' => $tn2]));
$ok('... keeps the second tradename', $p2 && Brgy::project((int)$p2)['tradename'] === $tn2);
$refused('A tradename that is not on the list', fn() => BrgyOps::saveProject($base + ['title' => 'V33 typed ' . $stamp, 'tradename' => 'Typed Name ' . $stamp]), 'from the list');
$ctx = $p2 ? BrgyDocs::context(Brgy::project((int)$p2)) : [];
$ok('The printed transaction form shows the tradename of the transaction', ($ctx['txn.tradename'] ?? '') === $tn2, (string)($ctx['txn.tradename'] ?? ''));
DB::run('DELETE FROM brgy_tradenames WHERE id = :id', ['id' => $t2]);
$ok('A tradename removed from the list stays on the transaction saved with it', $p2 && Brgy::project((int)$p2)['tradename'] === $tn2);
$p2v = $p2 ? Brgy::project((int)$p2) : null;
$does('... and the transaction can still be saved with it', fn() => BrgyOps::saveProject($base + ['title' => $p2v['title'], 'tradename' => $tn2], (int)$p2, $p2v['lock_version']));
$refused('... but another transaction cannot take it any more', fn() => BrgyOps::saveProject($base + ['title' => 'V33 removed ' . $stamp, 'tradename' => $tn2]), 'from the list');

echo "\n2. The transaction form without the positions\n";
$p3 = $does('A transaction whose older record has positions', fn() => BrgyOps::saveProject($base + ['title' => 'V33 positions ' . $stamp, 'requestor_position' => 'Kagawad', 'inspector_position' => 'Secretary']));
$ctx3 = $p3 ? BrgyDocs::context(Brgy::project((int)$p3)) : [];
$ok('The printed transaction form shows the requestor without a position', ($ctx3['txn.requestor'] ?? '') === 'Juan Dela Cruz', (string)($ctx3['txn.requestor'] ?? ''));
$ok('The printed transaction form shows the inspector without a position', ($ctx3['txn.inspector'] ?? '') === 'Maria Clara', (string)($ctx3['txn.inspector'] ?? ''));
$ok('The positions saved before stay in the database', $p3 && Brgy::project((int)$p3)['requestor_position'] === 'Kagawad');
$form = file_get_contents(APP_ROOT . '/modules/brgy/projects.php');
$ok('The form no longer asks for the position of the requestor or of the inspector', !str_contains($form, "'requestor_position'") && !str_contains($form, "'inspector_position'"));

echo "\n3. People Accountable Set-up\n";
$setRoles('sales_rep', [$roleId('Sales Representative')]);
$setRoles('pm', [$roleId('Contracts - Project Manager')]);
$ok('Sales representative lists only the Sales Representative role', isset(Brgy::usersFor('sales_rep')[$rep]) && !isset(Brgy::usersFor('sales_rep')[$clerk]) && !isset(Brgy::usersFor('sales_rep')[$admin]));
$ok('Project manager lists only the Project Manager role', isset(Brgy::usersFor('pm')[$pm]) && !isset(Brgy::usersFor('pm')[$clerk]));
$ok('A function with no role set lists every active user', count(Brgy::usersFor('checker')) === count(Brgy::users()));
$refused('A sales representative outside the roles', fn() => BrgyOps::saveProject($base + ['title' => 'V33 rep ' . $stamp, 'tradename' => $tn1, 'sales_rep_id' => $clerk]), 'cannot be the sales representative');
$refused('A project manager outside the roles', fn() => BrgyOps::saveProject($base + ['title' => 'V33 pm ' . $stamp, 'tradename' => $tn1, 'pm_id' => $clerk]), 'cannot be the project manager');
$p4 = $does('People inside the roles are accepted', fn() => BrgyOps::saveProject($base + ['title' => 'V33 people ' . $stamp, 'tradename' => $tn1, 'sales_rep_id' => $rep, 'pm_id' => $pm]));
// a person chosen before the roles were set stays on the transaction
$setRoles('pm', []);
$p5 = $does('A transaction with the bid officer as project manager (no roles set yet)', fn() => BrgyOps::saveProject($base + ['title' => 'V33 earlier ' . $stamp, 'tradename' => $tn1, 'pm_id' => $clerk]));
$setRoles('pm', [$roleId('Contracts - Project Manager')]);
$p5v = $p5 ? Brgy::project((int)$p5) : null;
$does('... it is saved again with that person after the roles were set', fn() => BrgyOps::saveProject($base + ['title' => $p5v['title'], 'tradename' => $tn1, 'pm_id' => $clerk], (int)$p5, $p5v['lock_version']));
$setRoles('submitter', [$roleId('Contracts - Project Manager')]);
$refused('The authorized representative of the SOP outside the roles', fn() => Brgy::assertUserAllowed('submitter', $clerk, null), 'cannot be the authorized submitter');
$ok('A user inside the roles may be the authorized representative', Brgy::userAllowed('submitter', $pm));
ob_start();
$GLOBALS['CURRENT_PAGE'] = 'brgy.projects';
require_once APP_ROOT . '/modules/brgy/_ui.php';
$html = brgy_user_select('pm_id', 'Project manager', $clerk, 'col-md-4', '', 'pm');
ob_end_clean();
$ok('The drop-down keeps the person already chosen, and lists the role users', str_contains($html, 'V33 Test Bid Officer') && str_contains($html, 'V33 Test Project Manager') && !str_contains($html, '>V33 Test Sales Rep<'));

echo "\n4. The menus\n";
$mods = Access::modules();
$ok('The menus start Home, Report Center, Financial', array_slice(array_values(array_map(fn($m) => $m[0], $mods)), 0, 3) === ['Home', 'Report Center', 'Financial']);
$ok('The Chart of Accounts list keeps its name', (Access::pageDef('coa.accounts')['name'] ?? '') === 'Chart of Accounts' && (Access::pageDef('coa.accounts')['module'] ?? '') === 'coa');
$ok('The Report Center pages are in the Report Center menu', Access::pageDef('home.reports')['module'] === 'rc' && Access::pageDef('home.memorized')['module'] === 'rc' && Access::pageDef('home.report_layouts')['module'] === 'rc');
$ok('The database has the same menus', DB::value("SELECT name FROM modules WHERE code = 'coa'") === 'Financial'
    && (int)DB::value("SELECT COUNT(*) FROM pages p JOIN modules m ON m.id = p.module_id WHERE m.code = 'rc'") === 3);
Auth::actAs($admin);
Access::reset();
$GLOBALS['CURRENT_PAGE'] = 'home.dashboard';
$menu = build_menu();
$rcNames = array_map(fn($i) => $i['name'], array_merge(...array_values($menu['rc']['groups'] ?? [[]])));
$want = ['Financial Reports', 'Fixed Assets Reports', 'Customers Reports', 'Vendors Reports', 'Inventory Reports', 'POS Reports', 'Banking Reports', 'BIR Reports',
    'Barangay Contract Reports', 'System Administration Reports', 'Memorized Reports', 'Saved Report Layouts'];
$ok('The Report Center menu has its twelve entries, in order', $rcNames === $want, implode(', ', $rcNames));
$homeNames = array_map(fn($i) => $i['name'], array_merge(...array_values($menu['home']['groups'] ?? [[]])));
$ok('Home keeps only its own pages', !array_intersect($homeNames, ['Report Center', 'Memorized Reports', 'Saved Report Layouts']), implode(', ', $homeNames));
$ok('The pages Tradenames and People Accountable Set-up are in the Barangay Contracts menu',
    in_array('Tradenames', array_map(fn($i) => $i['name'], $menu['brgy']['groups']['Lists'] ?? []), true)
    && in_array('People Accountable Set-up', array_map(fn($i) => $i['name'], $menu['brgy']['groups']['Setup'] ?? []), true));

// ---------------------------------------------------------------- put the set-up back
DB::run('DELETE FROM brgy_function_roles');
foreach ($keepRoles as $r) {
    DB::insert('brgy_function_roles', ['function_code' => $r['function_code'], 'role_id' => (int)$r['role_id']]);
}
DB::run('DELETE FROM brgy_tradenames WHERE id = :id', ['id' => $t1]);
if (!DB::value('SELECT COUNT(*) FROM brgy_tradenames WHERE is_default = 1')) {
    DB::run('UPDATE brgy_tradenames SET is_default = 1 WHERE name = :n', ['n' => (string)$keepTradename]);
}
Settings::set('brgy_tradename', (string)$keepTradename);

echo "\n" . $pass . ' check(s) passed, ' . count($failures) . " failed.\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit($failures ? 1 : 0);
