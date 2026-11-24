<?php
declare(strict_types=1);

/**
 * Version 36: checks of a billing pack that is not invoiced yet. Its quantities are not billed twice, and the pages and
 * messages name the pack instead of showing a form where nothing can be entered.
 *
 *   php tools/test_v36.php --yes [--company=N]
 *
 * Everything it does runs in one database transaction that is rolled back at the end, so nothing it makes is kept.
 * Still, run it on a copy of the database or on the demo database, never on the books of a company in use.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This prepares and voids a test billing pack. Run it on a copy of the database: php tools/test_v36.php --yes\n");
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
$refusal = function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
};
$admin = (int)(DB::value("SELECT id FROM users WHERE username = 'admin'") ?: DB::value("SELECT MIN(id) FROM users WHERE status = 'active'"));
Auth::actAs($admin);

// a contract with accepted quantities that are in no pack (the demo has BCP-00010)
$pid = (int)DB::value("SELECT p.id FROM brgy_projects p JOIN brgy_project_lines l ON l.project_id = p.id WHERE p.status = 'awarded'
                        GROUP BY p.id HAVING SUM(l.qty_accepted - l.qty_billed) > 0 ORDER BY p.id LIMIT 1");
if (!$pid) {
    fwrite(STDERR, "No awarded contract has accepted quantities left to bill. Run it on the demo database.\n");
    exit(2);
}
DB::begin();
try {
    DB::run("UPDATE brgy_projects SET payment_status = 'released' WHERE id = :p", ['p' => $pid]); // not held by special case F
    $p = Brgy::mustProject($pid);
    Brgy::refreshLines($pid);
    $left = [];
    foreach (Brgy::lines($pid) as $l) {
        if ((float)$l['qty_accepted'] - (float)$l['qty_billed'] > 0) {
            $left[(int)$l['id']] = round((float)$l['qty_accepted'] - (float)$l['qty_billed'], 4);
        }
    }
    echo 'Version 36 checks on ' . $p['project_no'] . "\n\n1. A billing pack not invoiced yet\n";
    $bid = (int)BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'billing_type' => 'partial', 'lines' => $left]);
    $bno = (string)DB::value('SELECT billing_no FROM brgy_billings WHERE id = :id', ['id' => $bid]);
    $pend = Brgy::pendingBillings($pid);
    $ok('The pack of everything accepted is a draft, listed among the packs not invoiced yet', in_array($bid, array_map('intval', array_column($pend, 'id')), true), json_encode($pend));
    $ok('... and its next step is to send it for review', Brgy::billingNext('draft') === 'send it for review' && Brgy::billingNext('approved') === 'issue its invoice');
    $ok('... not listed when the pack itself is edited', !in_array($bid, array_map('intval', array_column(Brgy::pendingBillings($pid, $bid), 'id')), true));
    $m = Brgy::money(Brgy::mustProject($pid));
    $ok('The contract figures: the pack shows as not invoiced yet, nothing accepted left unbilled', $m['billing_draft'] > 0 && $m['unbilled_accepted'] == 0.0, json_encode(['draft' => $m['billing_draft'], 'unbilled' => $m['unbilled_accepted']]));

    echo "\n2. A second pack for the same quantities\n";
    $why = $refusal(fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => []]));
    $ok('Prepared with nothing to bill: refused, naming the pack not invoiced yet', str_contains($why, $bno) && str_contains($why, 'not invoiced yet') && str_contains($why, 'Draft'), $why);
    $first = array_key_first($left);
    $why = $refusal(fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => [$first => 1]]));
    $ok('A quantity already in the pack: refused (not billed twice)', str_contains($why, 'accepted and not yet billed'), $why);

    echo "\n3. The pages\n";
    $page = file_get_contents(APP_ROOT . '/modules/brgy/billing.php');
    $ok('New billing pack names the packs not invoiced yet, with a link, and "In other packs"', str_contains($page, 'Brgy::pendingBillings(') && str_contains($page, 'is not invoiced yet') && str_contains($page, 'In other packs'));
    $ok('... and offers "Open" the pack instead of Prepare billing when nothing is left', str_contains($page, "'Open ' . \$pending[0]['billing_no']") && str_contains($page, '$canBill ? save_buttons('));
    $tab = file_get_contents(APP_ROOT . '/modules/brgy/_process_tab.php');
    $ok('Process tab: the Sales invoice row opens the pack and offers a new pack only for quantities in no pack', str_contains($tab, 'Brgy::pendingBillings($id)') && str_contains($tab, "\$m2['billed'] + \$m2['billing_draft'] + 0.004"));
    $ok('Project page: the figure of the packs not invoiced yet', str_contains(file_get_contents(APP_ROOT . '/modules/brgy/_project_view.php'), 'in billing packs not invoiced yet'));

    echo "\n4. Voiding the pack frees its quantities\n";
    BrgyOps::voidBilling($bid, 'Test v36');
    $ok('No pack not invoiced yet after the void', !Brgy::pendingBillings($pid));
    $b2 = 0;
    $why = $refusal(function () use ($pid, $first, &$b2) {
        $b2 = (int)BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => [$first => 1]]);
    });
    $ok('A new pack bills the quantity again', $why === '' && $b2 > 0, $why);

    echo "\n5. Nothing accepted left and no pack waiting\n";
    DB::run("UPDATE brgy_billings SET status = 'invoiced' WHERE id = :b", ['b' => $b2]);
    $rest = $left;
    $rest[$first] = round($rest[$first] - 1, 4);
    $b3 = (int)BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => array_filter($rest, fn($q) => $q > 0)]);
    DB::run("UPDATE brgy_billings SET status = 'invoiced' WHERE id = :b", ['b' => $b3]);
    $why = $refusal(fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => []]));
    $ok('Refused with "Nothing accepted is left to bill"', str_contains($why, 'Nothing accepted is left to bill'), $why);
} finally {
    DB::rollBack();
}
echo "\n" . $pass . ' passed, ' . count($failures) . " failed (everything rolled back)\n";
foreach ($failures as $f) {
    echo '  - ' . $f . "\n";
}
exit($failures ? 1 : 0);
