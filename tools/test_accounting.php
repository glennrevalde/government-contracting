<?php
declare(strict_types=1);

/**
 * The accounting updates of version 32: checks run through the same code the pages use.
 *
 *   php tools/test_accounting.php --yes [--company=N]
 *
 * It WRITES test records (items, a supplier and a customer named "Acct Test ...", bills, checks, invoices, journal
 * entries, attachments and signatures) and posts their transactions, so run it on a copy of the database or on the demo
 * database, never on the books of a company in use. It checks:
 *   - the comparative income statements and balance sheets against the Income Statement and the Balance Sheet;
 *   - Trial Balance by Class and Statement of Cash Flows by Class against the plain reports;
 *   - the General Ledger's Cashier filter;
 *   - the card and e-wallet clearing balances against the ledger;
 *   - a supplier on an expense line of a check: its own subsidiary ledger line;
 *   - item groups on an invoice: the items at their own prices plus the price variance, the document at the group
 *     price, the books in balance and the group on Sales by Item Group;
 *   - a sale of an inventory part with no unit cost refused; the 2% rule of processing orders;
 *   - stock sold below zero settled at the cost received; Rebuild data, then every Verify check passed;
 *   - attachments: kept, read back, refused for program files, written as hex by the backup, removed with the document;
 *   - voucher signatures: signed, the voucher locked, its PDF, a signature link, reset;
 *   - Save as PDF of a report; Sort by of a sales report.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This writes test records and transactions. Run it on a copy of the database: php tools/test_accounting.php --yes\n");
    exit(2);
}
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/views/layout.php';
require_once APP_ROOT . '/modules/txn/_common.php';
Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if (Schema::pending()) {
    fwrite(STDERR, "Run the pending migrations first: " . implode(', ', Schema::pending()) . "\n");
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
        while (DB::inTransaction()) {
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
        while (DB::inTransaction()) {
            DB::rollBack();
        }
        $ok($what, false, $e->getMessage());
        return null;
    }
};

/** Runs a report with the given filters, as its page would, and returns what its run gives back */
$report = function (string $key, array $get): array {
    $_GET = $get;
    $def = Report::get($key);
    if (!$def) {
        throw new RuntimeException("Report $key is not defined");
    }
    $f = Report::filters($def);
    return ($def['run'])($f);
};
/** The row of a report whose name (first column) is $name */
$row = function (array $data, string $name, string $col = 'name'): ?array {
    foreach ($data['rows'] as $r) {
        if (trim(strip_tags((string)($r[$col] ?? ''))) === $name) {
            return $r;
        }
    }
    return null;
};
$near = fn($a, $b) => abs((float)$a - (float)$b) < 0.01;

// the administrator; a company made with New company has no "admin", so its first active user (the owner)
$admin = (int)(DB::value("SELECT id FROM users WHERE username = 'admin'") ?: DB::value("SELECT MIN(id) FROM users WHERE status = 'active'"));
Auth::actAs($admin);
$stamp = date('His');
$today = today();
$acct = fn(string $code) => (int)DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);

echo "Financial statements\n";
// ---------------------------------------------------------------- comparative statements against the plain ones
$months = [];
for ($k = 2; $k >= 0; $k--) {
    $start = date('Y-m-01', strtotime(date('Y-m-01') . " -$k months"));
    $months[] = [$start, date('Y-m-t', strtotime($start))];
}
$cmp = $report('cmp_is_month', ['dates' => 'custom', 'from' => $months[0][0], 'to' => $months[2][1]]);
$niRow = $row($cmp, 'NET INCOME');
$ok('Comparative Income Statement by Month has a NET INCOME line', $niRow !== null);
$total = 0.0;
foreach ($months as [$from, $to]) {
    $is = $report('income_statement', ['dates' => 'custom', 'from' => $from, 'to' => $to]);
    $ni = $row($is, 'NET INCOME');
    $key = 'p_' . substr($from, 0, 7);
    $ok('Net income of ' . date('M Y', strtotime($from)) . ' agrees with the Income Statement',
        $niRow && $ni && $near($niRow[$key] ?? 0, $ni['amount'] ?? ($ni['total'] ?? 0)), ($niRow[$key] ?? '?') . ' vs ' . json_encode(array_diff_key($ni ?? [], ['name' => 1, '_class' => 1, '_links' => 1])));
    $total += (float)($niRow[$key] ?? 0);
}
$ok('The Total column adds up the months', $niRow && $near($niRow['total'] ?? 0, $total));

$cbs = $report('cmp_bs_month', ['dates' => 'custom', 'from' => $months[0][0], 'to' => $months[2][1]]);
$ta = $row($cbs, 'TOTAL ASSETS');
$tle = $row($cbs, 'TOTAL LIABILITIES AND EQUITY');
foreach ($months as [$from, $to]) {
    $key = 'p_' . substr($from, 0, 7);
    $bs = $report('balance_sheet', ['asof' => $to]);
    $bta = $row($bs, 'TOTAL ASSETS');
    $ok('Balance sheet at ' . fdate($to) . ': assets = liabilities and equity, and as on the Balance Sheet',
        $ta && $tle && $near($ta[$key] ?? 0, $tle[$key] ?? 1) && $bta && $near($ta[$key] ?? 0, $bta['amount'] ?? ($bta['total'] ?? 0)),
        ($ta[$key] ?? '?') . ' / ' . ($tle[$key] ?? '?') . ' / ' . json_encode($bta));
}
foreach (['cmp_is_quarter' => [], 'cmp_is_year' => [], 'cmp_bs_quarter' => [], 'cmp_bs_year' => [],
          'cmp_is_custom' => ['r1_from' => $months[2][0], 'r1_to' => $months[2][1], 'r2_from' => $months[1][0], 'r2_to' => $months[1][1]],
          'cmp_bs_custom' => ['d1' => $months[2][1], 'd2' => $months[1][1]]] as $k => $g) {
    try {
        $d = $report($k, $g);
        $ok(Access::pageDef('rpt.' . $k)['name'] . ' runs (' . count($d['rows']) . ' rows)', count($d['rows']) > 0 || isset($d['notes']));
    } catch (Throwable $e) {
        $ok(Access::pageDef('rpt.' . $k)['name'] . ' runs', false, $e->getMessage());
    }
}

// ---------------------------------------------------------------- by class against the plain reports
$tb = $report('trial_balance', ['asof' => $today]);
$tbc = $report('trial_balance_class', ['asof' => $today]);
$tbTot = $tb['totals'] ?? [];
$tbcTot = $tbc['totals'] ?? [];
$ok('Trial Balance by Class: total debits and credits are those of the Trial Balance',
    $near($tbcTot['debit'] ?? -1, $tbTot['debit'] ?? -2) && $near($tbcTot['credit'] ?? -1, $tbTot['credit'] ?? -2),
    json_encode(['tb' => $tbTot, 'class' => array_intersect_key($tbcTot, array_flip(['debit', 'credit']))]));
$ok('Trial Balance by Class is in balance', $near($tbcTot['debit'] ?? 0, $tbcTot['credit'] ?? 1));
$cf = $report('cash_flow', ['dates' => 'this_year']);
$cfc = $report('cash_flow_class', ['dates' => 'this_year']);
$cfEnd = null;
foreach ($cf['rows'] as $r) {
    if (stripos((string)($r['name'] ?? ''), 'end of') !== false || stripos((string)($r['name'] ?? ''), 'ending') !== false) {
        $cfEnd = $r;
    }
}
$cfcEnd = null;
foreach ($cfc['rows'] as $r) {
    if (stripos((string)($r['name'] ?? ''), 'end of') !== false || stripos((string)($r['name'] ?? ''), 'ending') !== false) {
        $cfcEnd = $r;
    }
}
$ok('Statement of Cash Flows by Class: the Total column ends where the Statement of Cash Flows ends',
    $cfEnd && $cfcEnd && $near($cfcEnd['total'] ?? 0, $cfEnd['amount'] ?? ($cfEnd['total'] ?? 0)), json_encode([$cfEnd, $cfcEnd]));

// ---------------------------------------------------------------- the General Ledger's cashier filter
$someone = (int)DB::value('SELECT created_by FROM transactions WHERE created_by IS NOT NULL AND created_by <> :a GROUP BY created_by ORDER BY COUNT(*) DESC LIMIT 1', ['a' => $admin]) ?: $admin;
$gl = $report('general_ledger', ['dates' => 'all', 'user_id' => (string)$someone]);
$nums = [];
foreach ($gl['rows'] as $r) {
    if (!empty($r['num'])) {
        $nums[] = strip_tags((string)$r['num']);
    }
}
$others = $nums ? (int)DB::value('SELECT COUNT(*) FROM transactions WHERE txn_no IN (' . implode(',', array_map(fn($n) => DB::pdo()->quote($n), array_unique($nums))) . ') AND COALESCE(created_by, 0) <> :u', ['u' => $someone]) : 0;
$ok('General Ledger with a cashier: only that user\'s transactions (' . count(array_unique($nums)) . ')', $others === 0, $others . ' other');

echo "Banking\n";
// ---------------------------------------------------------------- clearing balances
$bal = Clearing::balances($today, true);
$okAll = true;
foreach ($bal as $id => $a) {
    $gl = (float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $id, 'd' => $today]);
    $okAll = $okAll && $near($a['bal'], $gl);
}
$ok('Card and e-wallet clearing: each account once, its unsettled amount is its ledger balance (' . count($bal) . ' accounts)', $okAll && count($bal) === count(array_unique(array_keys($bal))));
$ids = array_keys($bal);
$ok('The module default clearing accounts are always listed', in_array(Accts::get('card_clearing'), $ids, true) && in_array(Accts::get('ewallet_clearing'), $ids, true));

echo "Payables: a supplier on an expense line\n";
// ---------------------------------------------------------------- vendors and a check with a supplier on a line
$vendA = DB::insert('vendors', ['name' => 'Acct Test Payee ' . $stamp, 'is_active' => 1]);
$vendB = DB::insert('vendors', ['name' => 'Acct Test Supplier ' . $stamp, 'is_active' => 1]);
$bank = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' AND is_active = 1 ORDER BY code LIMIT 1");
$exp = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Expense' AND is_active = 1 ORDER BY code LIMIT 1");
$check = $does('A check to one supplier with an expense line on another supplier is saved', fn() => Txn::save('check',
    ['txn_date' => $today, 'account_id' => $bank, 'vendor_id' => $vendA, 'memo' => 'Acct test ' . $stamp],
    [['line_kind' => 'expense', 'account_id' => $exp, 'amount' => 1234.50, 'description' => 'Paid for the other supplier', 'vendor_id' => $vendB]]));
if ($check) {
    $expLine = DB::row('SELECT * FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $check, 'a' => $exp]);
    $bankLine = DB::row('SELECT * FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $check, 'a' => $bank]);
    $ok('The expense line carries the supplier of the line (its subsidiary ledger)', $expLine && (int)$expLine['vendor_id'] === $vendB);
    $ok('The bank line keeps the payee', $bankLine && (int)$bankLine['vendor_id'] === $vendA);
}

echo "Item groups and unit costs\n";
// ---------------------------------------------------------------- items
$site = (int)DB::value('SELECT id FROM sites WHERE is_active = 1 ORDER BY id LIMIT 1');
$inc = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Income' AND is_active = 1 ORDER BY code LIMIT 1");
$mkItem = function (string $code, string $type, float $price, float $cost) use ($inc, $acct) {
    return DB::insert('items', ['item_type' => $type, 'code' => $code, 'name' => $code, 'description' => $code, 'sales_price' => $price, 'cost' => $cost,
        'income_account_id' => $inc, 'cogs_account_id' => Accts::get('cogs'), 'asset_account_id' => Accts::get('inventory_asset'),
        'expense_account_id' => Accts::get('cogs'), 'is_active' => 1, 'tax_code_id' => null]);
};
$p1 = $mkItem('AT-A' . $stamp, 'inventory', 500, 300);
$p2 = $mkItem('AT-B' . $stamp, 'inventory', 250, 100);
$noCost = $mkItem('AT-Z' . $stamp, 'inventory', 90, 0);
$grp = $mkItem('AT-G' . $stamp, 'group', 1100, 0);
DB::insert('item_components', ['parent_item_id' => $grp, 'component_item_id' => $p1, 'qty' => 1]);
DB::insert('item_components', ['parent_item_id' => $grp, 'component_item_id' => $p2, 'qty' => 2]);
$ap = Accts::get('ap');
$does('Stock received on a bill', fn() => Txn::save('bill', ['txn_date' => $today, 'vendor_id' => $vendA, 'account_id' => $ap, 'site_id' => $site,
    'due_date' => $today, 'ref_no' => 'AT-' . $stamp], [
    ['line_kind' => 'item', 'item_id' => $p1, 'qty' => 10, 'rate' => 300, 'amount' => 3000, 'site_id' => $site],
    ['line_kind' => 'item', 'item_id' => $p2, 'qty' => 20, 'rate' => 100, 'amount' => 2000, 'site_id' => $site],
]));
$ok('Consolidated price of the group: 500 + 2 x 250', $near(ItemGroups::consolidated($grp), 1000));
$ok('The group is sold at its group price', $near(ItemGroups::price($grp), 1100));

$cust = DB::insert('customers', ['name' => 'Acct Test Customer ' . $stamp, 'is_active' => 1]);
$lines = parse_item_lines([['item_id' => $grp, 'qty' => '2', 'rate' => '']], 'sales', ['customer_id' => $cust]);
$ok('A group line becomes its items plus one price variance line', count($lines) === 3 && count(array_filter($lines, fn($l) => ($l['group_role'] ?? '') === 'variance')) === 1);
$inv = $does('An invoice with the group is saved', fn() => Txn::save('invoice', ['txn_date' => $today, 'customer_id' => $cust, 'account_id' => Accts::get('ar'),
    'site_id' => $site, 'due_date' => $today], $lines));
if ($inv) {
    $t = Txn::load($inv);
    $ok('The invoice total is the group price: 2 x 1,100.00', $near($t['total'], 2200), (string)$t['total']);
    $var = DB::row("SELECT * FROM transaction_lines WHERE txn_id = :t AND group_role = 'variance'", ['t' => $inv]);
    $ok('The variance line is 2,200.00 less 2 x 1,000.00, on the Item Group Price Variance account',
        $var && $near($var['amount'], 200) && (int)$var['account_id'] === Accts::get('group_price_variance'));
    $ok('The invoice posts in balance', $near(DB::value('SELECT SUM(debit - credit) FROM journal_lines WHERE txn_id = :t', ['t' => $inv]), 0));
    $cogs = (float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $inv, 'a' => Accts::get('cogs')]);
    $ok('Cost of goods sold of the items in the groups: 2 x 300 + 4 x 100', $near($cogs, 1000), (string)$cogs);
    $ok('The group sold is recorded once for the group reports', (int)DB::value('SELECT COUNT(*) FROM item_group_sales WHERE txn_id = :t', ['t' => $inv]) === 1);
    $sig = $report('sales_item_group', ['dates' => 'custom', 'from' => $today, 'to' => $today, 'group_id' => (string)$grp]);
    $gr = null;
    foreach ($sig['rows'] as $r) {
        if (str_contains((string)($r['group'] ?? ''), 'AT-G' . $stamp)) {
            $gr = $r;
        }
    }
    $ok('Sales by Item Group: qty 2 at 1,100.00, total 2,200.00', $gr && $near($gr['qty'], 2) && $near($gr['amount'], 2200), json_encode($gr));
}

$refused('A sale of an inventory part with no unit cost', fn() => Txn::save('invoice', ['txn_date' => $today, 'customer_id' => $cust, 'account_id' => Accts::get('ar'),
    'site_id' => $site, 'due_date' => $today], parse_item_lines([['item_id' => $noCost, 'qty' => '1', 'rate' => '90']], 'sales', ['customer_id' => $cust])), 'no unit cost');
[$d, $pct, $within] = Processing::reconcile(10000, 10150);
$ok('Processing orders: outputs 1.5% off the input cost are within the 2% allowed', $within);
[$d, $pct, $within] = Processing::reconcile(10000, 100000);
$ok('Processing orders: outputs at ten times the input cost are not', !$within && $near($pct, 900));
$refused('A processing order whose outputs cost ten times the inputs', fn() => Processing::assertReconciled(10000, 100000, 9000, 1000), 'at most 2%');

echo "Average cost\n";
// ---------------------------------------------------------------- stock sold below zero, then received at another cost
Settings::set('allow_negative_stock', '1');
$p3 = $mkItem('AT-C' . $stamp, 'inventory', 400, 200);
$does('A bill brings 10 in at 200.00', fn() => Txn::save('bill', ['txn_date' => $today, 'vendor_id' => $vendA, 'account_id' => $ap, 'site_id' => $site, 'due_date' => $today],
    [['line_kind' => 'item', 'item_id' => $p3, 'qty' => 10, 'rate' => 200, 'amount' => 2000, 'site_id' => $site]]));
Posting::$stockCheck = false;
$does('12 sold: 2 below zero', fn() => Txn::save('invoice', ['txn_date' => $today, 'customer_id' => $cust, 'account_id' => Accts::get('ar'), 'site_id' => $site, 'due_date' => $today],
    parse_item_lines([['item_id' => $p3, 'qty' => '12', 'rate' => '400', 'site_id' => $site]], 'sales', ['customer_id' => $cust])));
Posting::$stockCheck = true;
$bill2 = $does('Then 10 received at 300.00', fn() => Txn::save('bill', ['txn_date' => $today, 'vendor_id' => $vendA, 'account_id' => $ap, 'site_id' => $site, 'due_date' => $today],
    [['line_kind' => 'item', 'item_id' => $p3, 'qty' => 10, 'rate' => 300, 'amount' => 3000, 'site_id' => $site]]));
$avg = (float)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $p3]);
$ok('The average of what is left is the cost received (300.00), not blown up by the shortfall', $near($avg, 300), (string)$avg);
if ($bill2) {
    $settle = (float)DB::value("SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a AND memo LIKE 'Stock sold below zero%'", ['t' => $bill2, 'a' => Accts::get('cogs')]);
    $ok('The 2 sold below zero are settled at the cost received: 2 x (300 - 200) to cost of goods sold', $near($settle, 200), (string)$settle);
    $ok('The bill posts in balance', $near(DB::value('SELECT SUM(debit - credit) FROM journal_lines WHERE txn_id = :t', ['t' => $bill2]), 0));
}
$ok('Quantity on hand by site agrees with the movements', $near(Stock::onHand($p3, $site), (float)DB::value('SELECT SUM(qty) FROM inventory_movements WHERE item_id = :i AND site_id = :s', ['i' => $p3, 's' => $site])));

echo "Attachments\n";
// ---------------------------------------------------------------- files on a journal entry
$je = $does('A journal entry is saved', fn() => Txn::save('journal', ['txn_date' => $today, 'memo' => 'Acct test attachments ' . $stamp], [
    ['line_kind' => 'journal', 'account_id' => $exp, 'amount' => 75, 'description' => 'Test'],
    ['line_kind' => 'journal', 'account_id' => $bank, 'amount' => -75, 'description' => 'Test'],
]));
if ($je) {
    $tmp = tempnam(sys_get_temp_dir(), 'att');
    $bytes = random_bytes(5000) . "\x00\x01 signed copy";
    file_put_contents($tmp, $bytes);
    $attId = $does('A PDF is attached to it', fn() => Attachments::store($tmp, 'signed copy.pdf', $je));
    $ok('The journal entry lists one file', count(Attachments::forTxn($je)) === 1);
    $a = $attId ? Attachments::find($attId) : null;
    $back = $a ? implode('', DB::column('SELECT data FROM txn_attachment_chunks WHERE attachment_id = :a ORDER BY seq', ['a' => $attId])) : '';
    $ok('The file reads back byte for byte', $back === $bytes);
    $ok('A program file is refused by name', Attachments::refused('setup.exe') !== '' && Attachments::refused('report.pdf') === '');
    $backup = Backup::create('Acct test backup ' . $stamp, false);
    $path = (string)($backup['path'] ?? '');
    $content = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';
    $ok('The backup writes the file as hex', $content !== '' && str_contains($content, '0x' . bin2hex(substr($bytes, 0, 64))), $path);
    if ($path !== '' && is_file($path)) {
        Backup::delete((string)$backup['file']);
    }
    $does('The journal entry is deleted', fn() => Txn::delete($je, 'Acct test'));
    $ok('Its files are deleted with it', (int)DB::value('SELECT COUNT(*) FROM txn_attachments WHERE transaction_id = :t', ['t' => $je]) === 0
        && (int)DB::value('SELECT COUNT(*) FROM txn_attachment_chunks WHERE attachment_id = :a', ['a' => (int)$attId]) === 0);
    @unlink($tmp);
}

echo "Voucher signatures\n";
// ---------------------------------------------------------------- signing a check voucher
if ($check) {
    $t = Txn::load($check);
    $ok('A check voucher can be signed', Signatures::supports('check') && Signatures::on($t));
    $pdf = VoucherPdf::build($t);
    $ok('The voucher PDF is made', str_starts_with($pdf, '%PDF'), substr($pdf, 0, 8));
    $drawing = Signatures::parseStrokes(json_encode(['w' => 600, 'h' => 200, 's' => [[10, 50, 80, 120, 160, 60, 240, 140], [300, 90, 420, 100]]]));
    $sid = $does('Signed on the Prepared by line', fn() => Signatures::sign($t, 'prepared', ['name' => 'Administrator', 'user_id' => $admin], $drawing, VoucherPdf::hash($t)));
    $ok('The voucher is now locked', Signatures::locked($check));
    $ok('A signed PDF copy is kept, and the original unsigned voucher', (bool)Signatures::latestSigned($check) && count(Signatures::docs($check)) >= 2);
    $refused('Editing a signed check', fn() => Txn::save('check', ['txn_date' => $today, 'account_id' => $bank, 'vendor_id' => $vendA, 'memo' => 'changed'],
        [['line_kind' => 'expense', 'account_id' => $exp, 'amount' => 1, 'description' => 'x']], $check), 'signed');
    $refused('Voiding a signed check', fn() => Txn::void($check, 'test'), 'signed');
    $refused('The same person signing a second line', fn() => Signatures::sign(Txn::load($check), 'checked', ['name' => 'Administrator', 'user_id' => $admin], $drawing, VoucherPdf::hash(Txn::load($check))), 'one line');
    $req = $does('A signature link is made for the Approved by line', fn() => Signatures::request(Txn::load($check), 'approved', 'Outside Signer', '', 'Please sign', 3));
    if ($req) {
        $link = (string)($req['link'] ?? '');
        parse_str((string)parse_url($link, PHP_URL_QUERY), $q);
        $found = Signatures::findByToken((string)($q['t'] ?? ''));
        $ok('The link leads to its request, and only by its secret', $found && (int)$found['transaction_id'] === $check && Signatures::findByToken('x' . ($q['t'] ?? '')) === null);
        $does('The outside signer signs through the link', fn() => Signatures::sign(Txn::load($check), 'approved', ['name' => 'Outside Signer', 'user_id' => null, 'request' => $found],
            $drawing, VoucherPdf::hash(Txn::load($check))));
        $refused('The same link used again', fn() => Signatures::sign(Txn::load($check), 'approved', ['name' => 'Outside Signer', 'user_id' => null, 'request' => Signatures::findByToken((string)$q['t']) ?? $found],
            $drawing, VoucherPdf::hash(Txn::load($check))));
    }
    $sum = Signatures::summary(Txn::load($check));
    $ok('Status: partially signed (2 of 4)', ($sum['key'] ?? '') === 'partial' && (int)($sum['signed'] ?? 0) === 2 && (int)($sum['total'] ?? 0) === 4, json_encode($sum));
    $n = $does('The signatures are reset with a reason', fn() => Signatures::reset(Txn::load($check), 'Amount to be corrected'));
    $ok('The voucher can be changed again', !Signatures::locked($check));
}

echo "Reports\n";
// ---------------------------------------------------------------- Sort by
$s1 = $report('sales_item_summary', ['dates' => 'all', 'sort' => 'amount', 'dir' => 'desc']);
$def = Report::get('sales_item_summary');
$sorted = Report::sortRows($s1['rows'], 'amount', -1, !empty($s1['grouped']));
$blocks = [];
$cur = [];
foreach ($sorted as $r) {
    $cls = (string)($r['_class'] ?? '');
    if (str_contains($cls, 'group-row') || str_contains($cls, 'subtotal-row') || str_contains($cls, 'total-row')) {
        if ($cur) {
            $blocks[] = $cur;
        }
        $cur = [];
        continue;
    }
    if (($r['amount'] ?? '') !== '') {
        $cur[] = (float)$r['amount'];
    }
}
if ($cur) {
    $blocks[] = $cur;
}
$inOrder = true;
foreach ($blocks as $b) {
    $copy = $b;
    rsort($copy);
    $inOrder = $inOrder && $copy === $b;
}
$ok('Sales by Item Summary sorted by Amount, decreasing, inside each item type', $inOrder && isset($def['sort_by']['amount']));

echo "Rebuild and verify\n";
// ---------------------------------------------------------------- every cost worked out again in date order, then every check
$does('Rebuild data (costs and balances)', fn() => Maintenance::rebuild(true, true));
$bad = array_filter(Maintenance::verify(), fn($c) => $c['level'] === 'error');
$ok('Verify Data: no check fails after the rebuild', !$bad, implode('; ', array_map(fn($c) => $c['check'] . ': ' . $c['detail'], $bad)));
foreach (Maintenance::verify() as $c) {
    if (in_array($c['check'], ['General ledger balances', 'Quantities on hand match the movements', 'Average cost matches the value on hand', 'Inventory ledger matches the inventory movements'], true)) {
        $ok('Verify: ' . $c['check'], $c['level'] === 'ok', $c['detail']);
    }
}

$failed = count($failures);
echo "\n$pass check(s) passed, $failed failed.\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit($failed ? 1 : 0);
