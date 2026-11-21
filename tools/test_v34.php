<?php
declare(strict_types=1);

/**
 * Version 34: checks of People Accountable Set-up by roles or users, the estimates of the transaction form, the price
 * schedule (Inventory List look-up, many lines at once), the disbursement voucher amounts, the cheque's tradename, the
 * request for quotation and the inspection and acceptance report, run through the same code the pages use.
 *
 *   php tools/test_v34.php --yes [--company=N]
 *
 * It WRITES test records (an entity "Barangay V34 ...", transactions, a tradename "V34 Tradename ...", users named
 * v34_t_...), so run it on a copy of the database or on the demo database, never on the books of a company in use.
 * The People Accountable Set-up it changes is put back as it was at the end.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This writes test records. Run it on a copy of the database: php tools/test_v34.php --yes\n");
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
$pmRole = $roleId('Contracts - Project Manager');
$repRole = $roleId('Sales Representative');
if (!$pmRole || !$repRole) {
    fwrite(STDERR, "The roles \"Contracts - Project Manager\" and \"Sales Representative\" are needed.\n");
    exit(2);
}
$pmA = $user('v34_t_pm_a', 'V34 Test PM A', 'Contracts - Project Manager');
$pmB = $user('v34_t_pm_b', 'V34 Test PM B', 'Contracts - Project Manager');
$rep = $user('v34_t_rep', 'V34 Test Sales Rep', 'Sales Representative');
$keepRoles = DB::all('SELECT function_code, role_id FROM brgy_function_roles');
$keepUsers = DB::all('SELECT function_code, user_id FROM brgy_function_users');
$setFn = function (string $fn, array $roleIds, array $userIds): void {
    DB::run('DELETE FROM brgy_function_roles WHERE function_code = :f', ['f' => $fn]);
    DB::run('DELETE FROM brgy_function_users WHERE function_code = :f', ['f' => $fn]);
    foreach ($roleIds as $r) {
        DB::insert('brgy_function_roles', ['function_code' => $fn, 'role_id' => $r]);
    }
    foreach ($userIds as $u) {
        DB::insert('brgy_function_users', ['function_code' => $fn, 'user_id' => $u]);
    }
    Brgy::forgetFunctionRoles();
};

echo "Version 34 checks\n\n1. People Accountable Set-up: roles or users\n";
$setFn('pm', [], [$pmA]);
$ok('Named users: the function is set by users', Brgy::functionMode('pm') === 'users');
$ok('... the project manager list holds only the user named', array_keys(Brgy::usersFor('pm')) === [$pmA], implode(',', array_keys(Brgy::usersFor('pm'))));
$ok('... another user of the same role is not allowed', !Brgy::userAllowed('pm', $pmB));
$setFn('pm', [$pmRole], []);
$ok('Roles: the function is set by roles', Brgy::functionMode('pm') === 'roles');
$ok('... both users of the role are listed', isset(Brgy::usersFor('pm')[$pmA]) && isset(Brgy::usersFor('pm')[$pmB]));
$ok('... a sales representative is not', !isset(Brgy::usersFor('pm')[$rep]));
$setFn('pm', [], []);
$ok('Nothing ticked: every active user', Brgy::functionMode('pm') === 'all' && isset(Brgy::usersFor('pm')[$rep]));
$setFn('pm', [], [$pmA]);
$entity = $does('Procuring entity added', fn() => BrgyOps::saveEntity(['official_name' => 'Barangay V34 ' . $stamp, 'barangay' => 'Sabang', 'city_municipality' => 'Lapu-Lapu City',
    'province' => 'Cebu', 'address' => 'Sabang, Lapu-Lapu City', 'email' => 'bac.v34@example.com', 'tin' => '000-000-000-000', 'submission_methods' => ['physical']]));
$svp = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'small_value'") ?: null;
$base = ['entity_id' => $entity, 'category' => 'goods', 'mode_id' => $svp, 'abc_amount' => '7000', 'process' => 'svp', 'txn_type' => 'purchases',
    'requestor' => 'Juan Dela Cruz', 'inspector' => 'Maria Clara', 'purpose' => 'Seminar', 'delivery_days' => '30'];
$refused('A project manager outside the users named', fn() => BrgyOps::saveProject($base + ['title' => 'V34 pm ' . $stamp, 'pm_id' => $pmB]), 'allows only');
$pid = $does('A transaction with the user named', fn() => BrgyOps::saveProject($base + ['title' => 'V34 transaction ' . $stamp, 'pm_id' => $pmA]));

echo "\n2. The estimates and targets of the transaction form\n";
$p = Brgy::project((int)$pid);
$jlBefore = (int)DB::value('SELECT COUNT(*) FROM journal_lines') + (int)DB::value('SELECT COUNT(*) FROM brgy_postings');
$does('Gross 29,750.00, withholding tax 1%, profit 13%, receipt deduction 3%, SOP 1,500.00', fn() => BrgyOps::saveProject($base + ['title' => $p['title'], 'pm_id' => $pmA,
    'est_gross' => '29,750.00', 'est_wt_rate' => '1', 'est_profit_rate' => '13', 'est_receipt_rate' => '3', 'est_sop' => '1,500.00'], (int)$pid, $p['lock_version']));
$p = Brgy::project((int)$pid);
$e = Brgy::estimates($p);
$ok('Withholding tax 1% of the gross = 297.50', abs($e['wt'] - 297.50) < 0.005, (string)$e['wt']);
$ok('Cheque amount = gross less withholding tax = 29,452.50', abs($e['cheque'] - 29452.50) < 0.005, (string)$e['cheque']);
$ok('Amount to release = gross less withholding tax = 29,452.50', abs($e['release'] - 29452.50) < 0.005, (string)$e['release']);
$ok('Profit 13% = 3,867.50', abs($e['profit'] - 3867.50) < 0.005, (string)$e['profit']);
$ok('Receipt deduction 3% = 892.50', abs($e['receipt'] - 892.50) < 0.005, (string)$e['receipt']);
$ok('SOP typed in = 1,500.00', abs((float)$e['sop'] - 1500) < 0.005);
$ok('The drop-downs offer 1, 4, 5, 6, 7% / 10, 13, 15% / 3%', implode(',', Brgy::rateList('brgy_est_wt_rates')) === '1%,4%,5%,6%,7%'
    && implode(',', Brgy::rateList('brgy_est_profit_rates')) === '10%,13%,15%' && implode(',', Brgy::rateList('brgy_est_receipt_rates')) === '3%');
$refused('A withholding tax rate not on the list', fn() => BrgyOps::saveProject($base + ['title' => $p['title'], 'pm_id' => $pmA, 'est_wt_rate' => '2'], (int)$pid, $p['lock_version']), 'from the list');
$ok('No accounting entry is made from the estimates', (int)DB::value('SELECT COUNT(*) FROM journal_lines') + (int)DB::value('SELECT COUNT(*) FROM brgy_postings') === $jlBefore);
$ctx = BrgyDocs::context($p);
$ok('The printed transaction form: gross, withholding tax, cheque amount and profit from the estimates',
    $ctx['txn.gross'] === '₱29,750.00' && $ctx['txn.wht'] === '₱297.50' && $ctx['txn.cheque_amount'] === '₱29,452.50' && $ctx['txn.profit'] === '₱3,867.50',
    $ctx['txn.gross'] . ' / ' . $ctx['txn.wht'] . ' / ' . $ctx['txn.cheque_amount'] . ' / ' . $ctx['txn.profit']);
$ok('... and the new fields (amount to release, receipt deduction, SOP)', $ctx['txn.amount_to_release'] === '₱29,452.50' && $ctx['txn.receipt_deduction'] === '₱892.50' && $ctx['txn.sop'] === '₱1,500.00');
$form = file_get_contents(APP_ROOT . '/modules/brgy/projects.php');
$ok('The card "Numbers and dates of the barangay\'s papers" is gone from the transaction form', !str_contains($form, "Numbers and dates of the barangay's papers</div>") && !str_contains($form, "input_field('ris_no'"));

echo "\n3. The price schedule: Inventory List look-up, amounts, many lines\n";
$item = DB::row("SELECT id, name, description FROM items WHERE item_type = 'inventory' AND is_active = 1 ORDER BY id LIMIT 1");
$rows = [];
for ($i = 1; $i <= 150; $i++) {
    $rows[] = ['description' => 'V34 line ' . $i, 'unit' => 'pc', 'qty' => (string)($i % 7 + 1), 'unit_price' => '10.00', 'est_unit_price' => '12.50', 'item_id' => $i === 1 && $item ? $item['id'] : ''];
}
$p = Brgy::project((int)$pid);
$does('150 lines saved at once, the Inventory List look-up on', fn() => BrgyOps::saveLines((int)$pid, $rows, $p['lock_version'], true));
$ok('... all 150 lines are there', (int)DB::value('SELECT COUNT(*) FROM brgy_project_lines WHERE project_id = :p', ['p' => $pid]) === 150);
$ok('... the look-up switch is kept on the transaction', (int)Brgy::project((int)$pid)['lines_lookup_inventory'] === 1);
$pr = BrgyLayout::rows('pr.items', Brgy::lines((int)$pid), BrgyLayout::layout(null));
$ok('PR estimated amount = quantity x estimated unit cost', $pr && $pr[0]['est_amount'] === number_format(((1 % 7) + 1) * 12.5, 2), (string)($pr[0]['est_amount'] ?? ''));
$p = Brgy::project((int)$pid);
$does('The look-up switched off again', fn() => BrgyOps::saveLines((int)$pid, $rows, $p['lock_version'], false));
$ok('... kept off', (int)Brgy::project((int)$pid)['lines_lookup_inventory'] === 0);
require_once APP_ROOT . '/modules/brgy/_ui.php';
$_POST = ['lines_json' => json_encode(array_slice($rows, 0, 3))];
$ok('The lines come in as one field (lines_json): no limit on the number of inputs', count(brgy_posted_lines()) === 3);
$_POST = [];
// leave a short price schedule for the papers below
$p = Brgy::project((int)$pid);
BrgyOps::saveLines((int)$pid, [['description' => 'Snacks', 'unit' => 'Pax', 'qty' => '80', 'unit_price' => '50'], ['description' => 'Honoraria of 2 Speaker', 'unit' => 'Pax', 'qty' => '2', 'unit_price' => '1000'],
    ['description' => 'Supplies and Materials', 'unit' => 'Lot', 'qty' => '1', 'unit_price' => '1000']], $p['lock_version'], false);

echo "\n4. The disbursement voucher\n";
$c = BrgySop::dvCompute(29750, 5, 1);
$ok('Gross 29,750.00, VAT 5% (gross / 1.12 x 5%) = 1,328.13', abs($c['vat'] - 1328.13) < 0.005, (string)$c['vat']);
$ok('... EWT 1% beside VAT 5% = 265.63 (the company\'s DV)', abs($c['ewt'] - 265.63) < 0.005, (string)$c['ewt']);
$ok('... check amount = 28,156.24', abs($c['net'] - 28156.24) < 0.005, (string)$c['net']);
$c = BrgySop::dvCompute(29750, 3, 1);
$ok('VAT 3% = gross x 3% = 892.50; EWT 1% = gross x 1% = 297.50; check 28,560.00', abs($c['vat'] - 892.50) < 0.005 && abs($c['ewt'] - 297.50) < 0.005 && abs($c['net'] - 28560) < 0.005,
    $c['vat'] . ' / ' . $c['ewt'] . ' / ' . $c['net']);
$c = BrgySop::dvCompute(29750, 5, 2);
$ok('EWT 2% = gross / 1.12 x 2% = 531.25', abs($c['ewt'] - 531.25) < 0.005, (string)$c['ewt']);
$c = BrgySop::dvCompute(29750, 0, 1);
$ok('No VAT, EWT 1% = gross x 1% = 297.50', abs($c['vat']) < 0.005 && abs($c['ewt'] - 297.50) < 0.005);
$dvT = (int)DB::value("SELECT id FROM brgy_templates WHERE code = 'disbursement_voucher'");
$fid = $does('A DV made from its template', fn() => BrgyOps::createForm($dvT, (int)$pid, null));
$f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]);
$ok('... its gross amount, taxes and check amount are marked for the DV boxes', str_contains($f['body'], 'data-ph="dv.gross"') && str_contains($f['body'], 'data-ph="dv.taxes"') && str_contains($f['body'], 'data-ph="dv.net"'));
$does('Gross 29,750.00, VAT 5%, EWT 1% entered on the DV and saved', fn() => BrgyOps::saveForm((int)$fid, ['title' => $f['title'], 'body' => $f['body'], 'paper' => $f['paper'], 'orientation' => $f['orientation'],
    'dv_gross' => '29,750.00', 'dv_vat_rate' => '5', 'dv_ewt_rate' => '1'], $f['lock_version']));
$f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]);
$ok('... the DV reads Php29,750.00 and PhP28,156.24', str_contains($f['body'], 'Php29,750.00') && substr_count($f['body'], 'PhP28,156.24') >= 1);
$ok('... with VAT 5% 1,328.13, EWT 1% 265.63, total 1,593.76', str_contains($f['body'], 'VAT 5%') && str_contains($f['body'], '1,328.13') && str_contains($f['body'], 'EWT 1%') && str_contains($f['body'], '265.63') && str_contains($f['body'], '1,593.76'));
$ok('... and the amount in words', str_contains($f['body'], 'TWENTY-EIGHT THOUSAND ONE HUNDRED FIFTY-SIX PESOS AND 24/100.'));
$ok('... kept on the transaction', (float)Brgy::project((int)$pid)['dv_gross'] === 29750.0 && Brgy::rateKey(Brgy::project((int)$pid)['dv_vat_rate']) === '5');
$does('VAT changed to 3% and saved', fn() => BrgyOps::saveForm((int)$fid, ['title' => $f['title'], 'body' => $f['body'], 'paper' => $f['paper'], 'orientation' => $f['orientation'],
    'dv_gross' => '29750', 'dv_vat_rate' => '3', 'dv_ewt_rate' => '1'], $f['lock_version']));
$f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]);
$ok('... the DV reads VAT 3% 892.50, EWT 1% 297.50 and PhP28,560.00', str_contains($f['body'], 'VAT 3%') && str_contains($f['body'], '892.50') && str_contains($f['body'], '297.50') && str_contains($f['body'], 'PhP28,560.00'));
$refused('A VAT that is not 5% or 3%', fn() => BrgyOps::saveForm((int)$fid, ['title' => $f['title'], 'body' => $f['body'], 'dv_gross' => '29750', 'dv_vat_rate' => '12', 'dv_ewt_rate' => '1'], $f['lock_version']), 'VAT');
$ok('The cheque dialog expects the DV check amount', abs(BrgySop::dvAmounts(Brgy::project((int)$pid))['net'] - 28560) < 0.005);

echo "\n5. The cheque: its tradename\n";
$tn = 'V34 Tradename ' . $stamp;
$tnId = DB::insert('brgy_tradenames', ['name' => $tn, 'is_default' => 0, 'sort_order' => 99]);
$refused('A tradename not on the list', fn() => BrgySop::releasePayment((int)$pid, ['cheque_no' => '000123', 'cheque_date' => today(), 'cheque_amount' => '28560', 'cheque_tradename' => 'Typed ' . $stamp]), 'tradename');
$does('Cheque ready, payable to a tradename chosen from the list', fn() => BrgySop::releasePayment((int)$pid, ['cheque_no' => '000123', 'cheque_date' => today(), 'cheque_bank' => 'Landbank', 'cheque_amount' => '28560', 'cheque_tradename' => $tn]));
$ok('... the tradename is kept with the cheque', Brgy::project((int)$pid)['cheque_tradename'] === $tn);
$ok('... and written on the timeline', (bool)DB::value("SELECT COUNT(*) FROM brgy_events WHERE project_id = :p AND details LIKE :d", ['p' => $pid, 'd' => '%payable to ' . $tn . '%']));

echo "\n6. The request for quotation and the inspection and acceptance report\n";
$rfqT = DB::row("SELECT * FROM brgy_templates WHERE code = 'request_for_quotation'");
$L = BrgyLayout::layout($rfqT);
$rfq = BrgyDocs::render($rfqT['body'], BrgyDocs::context(Brgy::project((int)$pid), null, 'RFQ-TEST', $L));
$ok('RFQ: the letterhead of the Bids and Award Committee', str_contains($rfq, 'BIDS AND AWARD COMMITTEE') && str_contains($rfq, 'REPUBLIC OF THE PHILIPPINES'));
$ok('RFQ: the seal of the barangay left and Picture A right (both can be changed on the Format tab)', $L['lh_style'] === 'own' && $L['lh_left'] === 'seal' && $L['lh_right'] === 'pic_a');
$ok('RFQ: the e-mail of the barangay and its hall', str_contains($rfq, 'bac.v34@example.com') && str_contains($rfq, 'Sabang Barangay Hall, Sabang, Lapu-Lapu City'));
$ok('RFQ: the ABC and the delivery period in words', str_contains($rfq, 'PHP 7,000.00') && str_contains($rfq, 'THIRTY (30) calendar days'));
$ok('RFQ: the general conditions and the documents a. to e.', str_contains($rfq, 'GENERAL CONDITIONS') && str_contains($rfq, 'BIR Form No. 2303') && str_contains($rfq, 'Tax Clearance, if available'));
$ok('RFQ: UNIT | ITEM DESCRIPTION | QUANTITY | UNIT PRICE | TOTAL', (bool)preg_match('/UNIT<\/th><th[^>]*>ITEM DESCRIPTION<\/th><th[^>]*>QUANTITY<\/th><th[^>]*>UNIT PRICE<\/th><th[^>]*>TOTAL<\/th>/', $rfq));
$ok('RFQ: ***nothing follows*** and the TOTAL line', str_contains($rfq, '***nothing follows***') && str_contains($rfq, '>TOTAL</td>'));
$ok('RFQ: the BAC Chairman signs; the supplier\'s name, establishment and date', str_contains($rfq, 'BAC Chairman') && str_contains($rfq, 'Printed Name / Signature') && str_contains($rfq, 'NAME OF ESTABLISHMENT'));
$iarT = DB::row("SELECT * FROM brgy_templates WHERE code = 'inspection_acceptance_report'");
$ok('IAR: RIS No. and Date beside the IAR No. and Date', str_contains($iarT['body'], 'RIS No.: {{ris.no}}') && str_contains($iarT['body'], '{{ris.date}}'));
$ok('IAR: no Requisitioning Office/Dept., no Responsibility Center Code', !str_contains($iarT['body'], 'Requisitioning Office') && !str_contains($iarT['body'], 'Responsibility Center Code'));
// an upgraded database keeps the version before under Versions (a new installation starts at version 1)
$ok('Every version of the two templates is under Versions', (int)DB::value('SELECT COUNT(*) FROM brgy_template_versions WHERE template_id = :t', ['t' => $rfqT['id']]) >= (int)$rfqT['version_no']
    && (int)DB::value('SELECT COUNT(*) FROM brgy_template_versions WHERE template_id = :t', ['t' => $iarT['id']]) >= (int)$iarT['version_no']);

// ---- put back the People Accountable Set-up and the tradenames
DB::run('DELETE FROM brgy_function_roles');
foreach ($keepRoles as $r) {
    DB::insert('brgy_function_roles', ['function_code' => $r['function_code'], 'role_id' => (int)$r['role_id']]);
}
DB::run('DELETE FROM brgy_function_users');
foreach ($keepUsers as $r) {
    DB::insert('brgy_function_users', ['function_code' => $r['function_code'], 'user_id' => (int)$r['user_id']]);
}
DB::run('DELETE FROM brgy_tradenames WHERE id = :id', ['id' => $tnId]);
Brgy::forgetFunctionRoles();

echo "\n" . $pass . ' passed, ' . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo '  - ' . $f . "\n";
}
exit($failures ? 1 : 0);
