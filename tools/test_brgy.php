<?php
declare(strict_types=1);

/**
 * Barangay Contracts: checks of the controls, run through the same operations the pages use (app/BrgyOps.php).
 *
 *   php tools/test_brgy.php --yes [--company=N]
 *
 * It WRITES test records (an entity "Barangay Test ...", projects, deliveries, billings, collections, users named
 * brgy_t_...) and posts their transactions, so run it on a copy of the database or on the demo database, never on
 * the books of a company in use. Each check says what it tried and whether the program behaved as it should:
 *   an expired permit, missing proof, an unauthorized submission, self-approval, a lapsed deadline, a bid above the
 *   ABC, an unapproved change order, overdelivery, billing without acceptance, overbilling, a duplicate invoice,
 *   a bounced check, taxes withheld that differ from the table, a conflicting update, a cancelled award, the steps of
 *   the company's SOP (order, checks, signed copies, the canvass, the payment hold of special case F, the review by
 *   another person, the release with the signed transmittal), the format of the forms, the previews of Word, Excel,
 *   PowerPoint and Publisher files (old and new versions) and the upload check of Office files, and in the end that
 *   every contract still agrees with the ledger and the trial balance is in balance.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This writes test records and transactions. Run it on a copy of the database: php tools/test_brgy.php --yes\n");
    exit(2);
}
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/demo_brgy.php';
Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if (!Brgy::ready()) {
    fwrite(STDERR, "The Barangay Contracts tables are missing: run database/migrations/2026_10_29_barangay_contracting.sql first.\n");
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
/** The operation has to be refused (a UserError); $like: words the message should contain */
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

// ---------------------------------------------------------------- people
$stamp = date('His');
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
foreach (['Contracts - Bid & Compliance Officer', 'Contracts - Approver (Management)', 'Contracts - Project Manager', 'Contracts - Billing Officer', 'Contracts - Collection Officer', 'Contracts - Warehouse & Delivery'] as $r) {
    if (!$roleId($r)) {
        fwrite(STDERR, "Role \"$r\" is missing (the migration adds it).\n");
        exit(2);
    }
}
$U = [
    'maker' => $user('brgy_t_maker', 'Test Bid Officer', 'Contracts - Bid & Compliance Officer'),
    'checker' => $user('brgy_t_checker', 'Test Checker', 'Contracts - Approver (Management)'),
    'approver' => $user('brgy_t_approver', 'Test Approver', 'Contracts - Approver (Management)'),
    'approver2' => $user('brgy_t_approver2', 'Test Final Approver', 'Contracts - Approver (Management)'),
    'pm' => $user('brgy_t_pm', 'Test Project Manager', 'Contracts - Project Manager'),
    'billing' => $user('brgy_t_billing', 'Test Billing Officer', 'Contracts - Billing Officer'),
    'collector' => $user('brgy_t_collector', 'Test Collector', 'Contracts - Collection Officer'),
    'wh' => $user('brgy_t_wh', 'Test Warehouse', 'Contracts - Warehouse & Delivery'),
];
$as = function (string $who) use (&$U) {
    Auth::actAs($U[$who]);
};
$pdf = fn(string $name, string ...$lines) => brgy_demo_pdf($name, $lines ?: [$name]);
$keepSettings = [];
foreach (['brgy_gate_deadline', 'brgy_gate_documents', 'brgy_sod_strict', 'brgy_block_over_abc', 'brgy_require_acceptance_doc', 'brgy_require_pack_complete', 'brgy_sop_hold_billing',
             'brgy_sop_need_scans', 'brgy_sop_review_other'] as $k) {
    $keepSettings[$k] = Brgy::setting($k);
    Settings::set($k, '1');
}
$tbBefore = (float)DB::value('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM journal_lines');

echo "Barangay Contracts control checks\n\n1. Directory, company papers and the bid\n";
$as('maker');
$entity = $does('Procuring entity added', fn() => BrgyOps::saveEntity(['official_name' => 'Barangay Test ' . $stamp, 'address' => 'Test Street', 'tin' => '000-000-000-000', 'submission_methods' => ['physical', 'email']]));
$mode = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'competitive_bidding' LIMIT 1") ?: (int)DB::value('SELECT id FROM brgy_proc_modes ORDER BY id LIMIT 1');
$deadline = date('Y-m-d H:i:s', strtotime('+10 days'));
$pid = $does('Opportunity recorded, checklist made from the library', fn() => BrgyOps::saveProject(['title' => 'Test supply ' . $stamp, 'entity_id' => $entity, 'category' => 'goods', 'mode_id' => $mode,
    'abc_amount' => '100000', 'bid_deadline' => $deadline, 'posting_date' => today(), 'delivery_days' => 15, 'retention_pct' => 0, 'ld_rate_pct' => '0.1',
    'approver_id' => $U['approver2'], 'submitter_id' => $U['maker'], 'pm_id' => $U['pm']]));
// v39: a person may hold up to two roles on a transaction; a third is refused
$refused('Same person as preparer, checker and approver (three roles)', fn() => BrgyOps::saveProject(['title' => 'x', 'entity_id' => $entity, 'preparer_id' => $U['checker'], 'checker_id' => $U['checker'], 'approver_id' => $U['checker']]), 'three roles');
$does('Go decision', fn() => BrgyOps::goDecision($pid, 'go', 'Within our product line'));
$p = Brgy::mustProject($pid);
$refused('Conflicting update (someone else saved the project first)', fn() => BrgyOps::saveProject(['title' => $p['title'] . ' changed', 'entity_id' => $entity, 'abc_amount' => '100000', 'mode_id' => $mode,
    'bid_deadline' => $deadline, 'checker_id' => $U['checker'], 'approver_id' => $U['approver'], 'submitter_id' => $U['maker']], $pid, (int)$p['lock_version'] - 1), 'changed');
$does('Price schedule above the ABC saved as a draft', fn() => BrgyOps::saveLines($pid, [['line_kind' => 'goods', 'description' => 'Office chair, ergonomic', 'unit' => 'pc', 'qty' => 10, 'unit_cost' => 8000, 'unit_price' => 11000]], Brgy::mustProject($pid)['lock_version']));
// every mandatory paper: filed by the maker, reviewed by the checker; the tax clearance expired
$expired = null;
foreach (Brgy::checklist($pid) as $r) {
    if (!in_array($r['stage'], ['eligibility', 'bid'], true) || $r['requirement_type'] !== 'mandatory' || in_array($r['state'], ['ok', 'waived'], true)) {
        continue;
    }
    $as('maker');
    $valid = (int)$r['needs_validity'] ? ($r['doc_type'] === 'tax_clearance' ? date('Y-m-d', strtotime('-5 days')) : date('Y-m-d', strtotime('+200 days'))) : '';
    BrgyOps::attachRequirement((int)$r['id'], $pdf('t_' . $r['doc_type'] . '_' . $stamp . '_' . $r['id'] . '.pdf', $r['name'] . ' ' . $stamp), null, ['title' => $r['name'], 'valid_until' => $valid]);
    $doc = BrgyDocs::load((int)DB::value('SELECT document_id FROM brgy_project_reqs WHERE id = :id', ['id' => $r['id']]));
    if ($r['doc_type'] === 'tax_clearance') {
        $expired = $doc;
    }
    $as('maker');
    if ($doc['status'] === 'for_review') {
        if (empty($selfReviewed)) {
            // v39: filing a paper and reviewing it are two roles, which one person may hold
            $selfReviewed = true;
            BrgyDocs::review($doc, 'approved', 'Checked by the one who filed it');
            $ok('Reviewing a paper one filed oneself: allowed (two roles)', DB::value('SELECT status FROM brgy_documents WHERE id = :d', ['d' => $doc['id']]) === 'approved');
            continue;
        }
        $as('checker');
        BrgyDocs::review(BrgyDocs::load((int)$doc['id']), 'approved', 'Checked');
    }
}
$as('maker');
$refused('Bid above the ABC sent for review', fn() => BrgyOps::submitBid($pid), 'above the approved budget');
$does('Price schedule corrected under the ABC', fn() => BrgyOps::saveLines($pid, [['id' => DB::value('SELECT id FROM brgy_project_lines WHERE project_id = :p', ['p' => $pid]), 'line_kind' => 'goods', 'description' => 'Office chair, ergonomic',
    'unit' => 'pc', 'qty' => 10, 'unit_cost' => 7000, 'unit_price' => 9500]], Brgy::mustProject($pid)['lock_version']));
if ($expired) {
    $refused('Bid with an expired tax clearance sent for review', fn() => BrgyOps::submitBid($pid), 'expired');
    $as('maker');
    $nd = $does('Renewed tax clearance filed as a new version', fn() => BrgyDocs::store($pdf('t_taxclear_new_' . $stamp . '.pdf', 'Tax clearance (renewed)'), ['replaces' => $expired['id'], 'valid_until' => date('Y-m-d', strtotime('+90 days'))]));
    $as('checker');
    BrgyDocs::review(BrgyDocs::load((int)$nd), 'approved', 'Renewed');
} else {
    $ok('Expired permit check (no tax clearance requirement in the library for this mode)', true);
}
// the bid is sent for review by a manager, who may also review it (two roles), but not approve it as well (three)
$as('checker');
$does('Bid sent for review', fn() => BrgyOps::submitBid($pid, 'Ready'));
$as('wh');
$refused('A user without the review right reviews the bid', fn() => BrgyOps::bidStep($pid, 'review'), 'cannot review');
$as('checker');
$does('The person who sent the bid reviews it (two roles)', fn() => BrgyOps::bidStep($pid, 'review', 'Prices checked'));
DB::run('UPDATE brgy_projects SET approver_id = NULL WHERE id = :p', ['p' => $pid]);
$refused('The person who sent and reviewed the bid approves it (three roles)', fn() => BrgyOps::bidStep($pid, 'approve'), 'prepared and reviewed');
// approval limit: an approver whose role allows up to 1,000.00 cannot approve the bid
$limRole = $roleId('Contracts - Test Limited Approver');
if (!$limRole) {
    $limRole = DB::insert('roles', ['name' => 'Contracts - Test Limited Approver', 'description' => 'Made by tools/test_brgy.php', 'is_admin' => 0, 'is_system' => 0, 'all_sites' => 1]);
    DB::run('INSERT INTO role_page_access (role_id, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve)
             SELECT :n, page_id, allowed, can_create, can_edit, can_delete, can_print, can_approve FROM role_page_access WHERE role_id = :a', ['n' => $limRole, 'a' => $roleId('Contracts - Approver (Management)')]);
    DB::insert('role_special_permissions', ['role_id' => $limRole, 'perm_key' => 'brgy_approval_limit', 'perm_value' => '1000']);
}
$U['limited'] = $user('brgy_t_limited', 'Test Limited Approver', 'Contracts - Test Limited Approver');
$as('limited');
$refused('Approver above their approval limit (1,000.00)', fn() => BrgyOps::bidStep($pid, 'approve'), 'approval limit');
DB::run('UPDATE brgy_projects SET approver_id = :u WHERE id = :p', ['u' => $U['approver2'], 'p' => $pid]);
$refused('Someone other than the named approver approves', fn() => BrgyOps::bidStep($pid, 'approve'), 'named approver');
$as('approver2');
$does('The named approver approves the bid', fn() => BrgyOps::bidStep($pid, 'approve', 'Go'));
$as('maker');
$refused('Price schedule changed after the approval', fn() => BrgyOps::saveLines($pid, [['description' => 'x', 'qty' => 1, 'unit_price' => 1]], Brgy::mustProject($pid)['lock_version']), 'read-only');

echo "\n2. Submission\n";
$as('pm');
$refused('Unauthorized person records the bid submission', fn() => BrgyOps::recordSubmission($pid, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => date('Y-m-d H:i'), 'received_by' => 'BAC'], null));
$as('maker');
$refused('Hand-carried bid without proof of receipt', fn() => BrgyOps::recordSubmission($pid, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => date('Y-m-d H:i')], null), 'proof');
$refused('Submission dated in the future', fn() => BrgyOps::recordSubmission($pid, ['sub_type' => 'bid', 'method' => 'email', 'submitted_at' => date('Y-m-d H:i', strtotime('+2 days'))], null), 'future');
$does('Bid submitted with the receiving copy', fn() => BrgyOps::recordSubmission($pid, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => date('Y-m-d H:i'), 'received_by' => 'BAC Secretariat'],
    $pdf('t_receiving_' . $stamp . '.pdf', 'Receiving copy')));

// a second project whose deadline lapsed before approval
$as('maker');
$pid2 = BrgyOps::saveProject(['title' => 'Test late bid ' . $stamp, 'entity_id' => $entity, 'category' => 'goods', 'mode_id' => $mode, 'abc_amount' => '50000', 'bid_deadline' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'checker_id' => $U['checker'], 'approver_id' => $U['approver'], 'submitter_id' => $U['maker']]);
BrgyOps::goDecision($pid2, 'go', 'Test');
BrgyOps::saveLines($pid2, [['description' => 'Item', 'qty' => 1, 'unit_price' => 1000]], Brgy::mustProject($pid2)['lock_version']);
$refused('Bid whose deadline lapsed sent for review', fn() => BrgyOps::submitBid($pid2), 'deadline');

echo "\n3. Award\n";
$jlBefore = (int)DB::value('SELECT COUNT(*) FROM journal_lines');
$refused('Award without the entity\'s notice', fn() => BrgyOps::recordAward($pid, ['noa_ref' => 'NOA-T-' . $stamp, 'noa_date' => today(), 'contract_amount' => 95000]), 'Attach');
$refused('Award for an amount other than the approved schedule', fn() => BrgyOps::recordAward($pid, ['noa_ref' => 'NOA-T-' . $stamp, 'noa_date' => today(), 'contract_amount' => 99000], $pdf('t_noa_x.pdf', 'NOA')), 'does not agree');
$does('Award recorded from the notice of award', fn() => BrgyOps::recordAward($pid, ['noa_ref' => 'NOA-T-' . $stamp, 'noa_date' => today(), 'contract_amount' => 95000, 'po_no' => 'PO-T-' . $stamp, 'evidence_type' => 'po'],
    $pdf('t_noa_' . $stamp . '.pdf', 'Notice of award')));
$ok('The award posts nothing to the ledger', (int)DB::value('SELECT COUNT(*) FROM journal_lines') === $jlBefore);
$does('Fulfilment started', fn() => BrgyOps::startFulfillment($pid));
$line = Brgy::lines($pid)[0];

echo "\n4. Deliveries, acceptance and change orders\n";
$as('pm');
$refused('Delivery beyond the contract quantity', fn() => BrgyOps::saveDelivery(['project_id' => $pid, 'delivery_date' => today(), 'kind' => 'goods', 'lines' => [$line['id'] => ['qty' => 12]]]), 'more than');
$dlv = $does('Delivery of the 10 chairs', fn() => BrgyOps::saveDelivery(['project_id' => $pid, 'delivery_date' => today(), 'kind' => 'goods', 'received_by_name' => 'Barangay Secretary', 'lines' => [$line['id'] => ['qty' => 10]]]));
$as('billing');
$refused('Billing before the entity accepted anything', fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => [$line['id'] => 10]]), 'accepted');
$as('pm');
$dl = DB::value('SELECT id FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $dlv]);
$refused('Acceptance without the entity\'s report', fn() => BrgyOps::recordInspection($dlv, ['acceptance_date' => today(), 'acceptance_ref' => 'IAR-T-' . $stamp, 'lines' => [$dl => ['accepted' => 8, 'rejected' => 2]]]), 'Attach');
$refused('Accepted and rejected more than delivered', fn() => BrgyOps::recordInspection($dlv, ['acceptance_date' => today(), 'acceptance_ref' => 'IAR-T-' . $stamp, 'lines' => [$dl => ['accepted' => 9, 'rejected' => 2]]], $pdf('t_iar0.pdf', 'IAR')), 'more than');
$does('Inspection: 8 accepted, 2 rejected', fn() => BrgyOps::recordInspection($dlv, ['acceptance_date' => today(), 'acceptance_ref' => 'IAR-T-' . $stamp, 'lines' => [$dl => ['accepted' => 8, 'rejected' => 2]]],
    $pdf('t_iar_' . $stamp . '.pdf', 'Inspection and acceptance report')));
$as('pm');
$co = $does('Change order prepared (+2 chairs)', fn() => BrgyOps::saveChangeOrder($pid, ['title' => 'Two more chairs', 'co_date' => today(), 'lines' => [['project_line_id' => $line['id'], 'qty_change' => 2]]]));
$refused('Unapproved change order applied to the contract', fn() => BrgyOps::applyChangeOrder($co, ['external_ref' => 'VO-T', 'external_date' => today()], $pdf('t_vo0.pdf', 'VO')), 'approved');
BrgyOps::changeOrderStep($co, 'submit');
$as('checker');
BrgyOps::changeOrderStep($co, 'review');
$as('approver2');
BrgyOps::changeOrderStep($co, 'approve');
$as('pm');
$refused('Approved change order without the entity\'s approval paper', fn() => BrgyOps::applyChangeOrder($co, ['external_ref' => 'VO-T-' . $stamp, 'external_date' => today()]), 'Attach');
$does('Change order applied with the entity\'s approval', fn() => BrgyOps::applyChangeOrder($co, ['external_ref' => 'VO-T-' . $stamp, 'external_date' => today()], $pdf('t_vo_' . $stamp . '.pdf', 'Variation order')));
$ok('Contract amount follows the change order', abs(Brgy::contractTotal(Brgy::mustProject($pid)) - 114000) < 0.01, money(Brgy::contractTotal(Brgy::mustProject($pid))));

echo "\n5. Billing\n";
$as('billing');
$refused('Billing more than was accepted', fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'lines' => [$line['id'] => 9]]), 'accepted');
$b1 = $does('Billing of the 8 accepted chairs', fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'si_no' => 'T' . $stamp . '1', 'lines' => [$line['id'] => 8]]));
$refused('Second billing with the same sales invoice number', fn() => BrgyOps::saveBilling(['project_id' => $pid, 'billing_date' => today(), 'si_no' => 'T' . $stamp . '1', 'lines' => [$line['id'] => 0.5]]), 'already used');
$refused('Invoice of a billing not approved', fn() => BrgyOps::invoiceBilling($b1), 'approved');
BrgyOps::billingStep($b1, 'submit');
$refused('Billing officer reviews their own billing', fn() => BrgyOps::billingStep($b1, 'review'));
$as('checker');
BrgyOps::billingStep($b1, 'review');
$as('approver2');
$does('Billing approved', fn() => BrgyOps::billingStep($b1, 'approve'));
$as('billing');
$inv = $does('Invoice posted', fn() => BrgyOps::invoiceBilling($b1));
$refused('The same billing invoiced a second time', fn() => BrgyOps::invoiceBilling($b1));
$refused('Pack submitted while papers are missing', fn() => BrgyOps::submitBilling($b1, ['method' => 'physical', 'submitted_at' => date('Y-m-d H:i')]), 'not complete');
$bRow = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b1]);
$ok('Invoice equals the billing (amount + VAT)', $inv && abs((float)Txn::load($inv)['total'] - (float)$bRow['gross_amount']) < 0.01, $inv ? money(Txn::load($inv)['total']) . ' vs ' . money($bRow['gross_amount']) : '');

echo "\n6. Collections\n";
$as('collector');
$b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b1]);
$exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
$refused('Taxes withheld that differ from the table, without an explanation', fn() => BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b1, 'collection_date' => today(), 'method' => 'check', 'check_no' => 'T1',
    'cash_received' => r2((float)$b['gross_amount'] - 500), 'ewt_amount' => 500]), 'differ');
$c1 = $does('Check received with the taxes withheld per the table', fn() => BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b1, 'collection_date' => today(), 'method' => 'check', 'check_no' => 'T' . $stamp,
    'cash_received' => r2((float)$b['gross_amount'] - $exp['ewt'] - $exp['vat']), 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'cert_2307' => 1]));
$openAfter = Balances::open(Txn::load((int)$inv));
$ok('The invoice is settled', abs($openAfter) < 0.01, money($openAfter));
$does('The check bounced before it was deposited', fn() => BrgyPost::bounce((int)$c1, today(), 'DAIF'));
$openBounced = Balances::open(Txn::load((int)$inv));
$ok('The billing is open again for the full amount', abs($openBounced - (float)$b['gross_amount']) < 0.01, money($openBounced));
$refused('A returned check voided again', fn() => BrgyPost::voidCollection((int)$c1, 'x'));
$c2 = $does('Replacement payment by LDDAP-ADA', fn() => BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b1, 'collection_date' => today(), 'method' => 'lddap_ada', 'reference_no' => 'ADA-T-' . $stamp,
    'cash_received' => r2((float)$b['gross_amount'] - $exp['ewt'] - $exp['vat']), 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'cert_2307' => 1]));
$as('billing');
$refused('Billing voided while a collection is on it', fn() => BrgyOps::voidBilling($b1, 'test'), 'Void them first');

echo "\n7. Cancelled award\n";
$as('maker');
$pid3 = BrgyOps::saveProject(['title' => 'Test cancelled ' . $stamp, 'entity_id' => $entity, 'category' => 'goods', 'mode_id' => $mode, 'abc_amount' => '20000', 'bid_deadline' => $deadline,
    'checker_id' => $U['checker'], 'approver_id' => $U['approver'], 'submitter_id' => $U['maker']]);
BrgyOps::goDecision($pid3, 'go', 'Test');
BrgyOps::saveLines($pid3, [['description' => 'Item', 'qty' => 2, 'unit_price' => 5000]], Brgy::mustProject($pid3)['lock_version']);
foreach (Brgy::checklist($pid3) as $r) {
    if (in_array($r['stage'], ['eligibility', 'bid'], true) && $r['requirement_type'] === 'mandatory' && !in_array($r['state'], ['ok', 'waived'], true)) {
        $as('approver');
        BrgyOps::waiveRequirement((int)$r['id'], 'Not asked in the RFQ (test)');
    }
}
$as('maker');
BrgyOps::submitBid($pid3);
$as('checker');
BrgyOps::bidStep($pid3, 'review');
$as('approver');
BrgyOps::bidStep($pid3, 'approve');
$as('maker');
BrgyOps::recordSubmission($pid3, ['sub_type' => 'bid', 'method' => 'email', 'submitted_at' => date('Y-m-d H:i'), 'tracking_no' => 'mail-' . $stamp]);
BrgyOps::recordAward($pid3, ['noa_ref' => 'NOA-C-' . $stamp, 'noa_date' => today(), 'contract_amount' => 10000, 'po_no' => 'POC'], $pdf('t_noac_' . $stamp . '.pdf', 'NOA'));
$does('The entity cancels the award', fn() => BrgyOps::cancelAward($pid3, 'Funds were realigned (test)'));
$as('pm');
$refused('Delivery on a cancelled award', fn() => BrgyOps::saveDelivery(['project_id' => $pid3, 'delivery_date' => today(), 'lines' => [Brgy::lines($pid3)[0]['id'] => ['qty' => 1]]]), 'awarded');
$as('billing');
$refused('Billing on a cancelled award', fn() => BrgyOps::saveBilling(['project_id' => $pid3, 'billing_date' => today(), 'lines' => [Brgy::lines($pid3)[0]['id'] => 1]]), 'awarded');

echo "\n8. The SOP: small value procurement\n";
if (!BrgySop::ready()) {
    $ok('The SOP tables are there (migration 2026_11_03)', false);
} else {
    $as('maker');
    $sv = $does('Transaction on the SOP recorded (small value)', fn() => BrgyOps::saveProject(['process' => 'svp', 'title' => 'Payment for the purchase of test supplies ' . $stamp, 'entity_id' => $entity,
        'category' => 'goods', 'mode_id' => $mode, 'abc_amount' => '20000', 'reference_no' => 'PR-T-' . $stamp, 'po_no' => 'PO-T-' . $stamp, 'po_date' => today(), 'iar_no' => 'IAR-T-' . $stamp,
        'iar_date' => today(), 'txn_type' => 'purchases', 'purpose' => 'Test']));
    $P = Brgy::mustProject((int)$sv);
    $ok('It got a transaction form number and its steps', (bool)$P['txn_form_no'] && (int)DB::value('SELECT COUNT(*) FROM brgy_project_steps WHERE project_id = :p', ['p' => $sv]) >= 9);
    $ok('No compliance checklist for a small value transaction', (int)DB::value('SELECT COUNT(*) FROM brgy_project_reqs WHERE project_id = :p', ['p' => $sv]) === 0);
    $refused('Step 1 with the requestor, the inspector and the items missing', fn() => BrgySop::complete((int)$sv, 'svp1', ['Project title', 'Approved budget', 'Requestor', 'Inspector', 'Complete item specifications / particulars']), 'requestor');
    BrgyOps::saveProject(['title' => $P['title'], 'entity_id' => $entity, 'category' => 'goods', 'mode_id' => $mode, 'abc_amount' => '20000', 'requestor' => 'Test Requisitioner', 'inspector' => 'Test Inspector'], (int)$sv, $P['lock_version']);
    BrgyOps::saveLines((int)$sv, [['line_kind' => 'goods', 'description' => 'Test bond paper', 'unit' => 'ream', 'qty' => 10, 'unit_cost' => 200, 'unit_price' => 250, 'est_unit_price' => 260]]);
    $refused('Step 1 with a check not ticked', fn() => BrgySop::complete((int)$sv, 'svp1', ['Project title']), 'Tick');
    $refused('Step 2 before step 1 (the steps are done in order)', fn() => BrgySop::complete((int)$sv, 'svp2', []), 'order');
    $refused('Step 1 with the transaction form not prepared', fn() => BrgySop::complete((int)$sv, 'svp1', ['Project title', 'Approved budget', 'Requestor', 'Inspector', 'Complete item specifications / particulars']), 'Transaction form');
    BrgySop::prepare((int)DB::value("SELECT id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'txn_form'", ['p' => $sv]));
    $does('Step 1 done: the details are complete', fn() => BrgySop::complete((int)$sv, 'svp1', ['Project title', 'Approved budget', 'Requestor', 'Inspector', 'Complete item specifications / particulars']));
    $refused('Step 2 with the papers not prepared', fn() => BrgySop::complete((int)$sv, 'svp2', []), 'not prepared');
    $n = BrgySop::prepareStep((int)$sv, 'svp2');
    $ok('Every paper of step 2 prepared from its proforma (7)', $n === 7, (string)$n);
    $prForm = (int)DB::value("SELECT form_id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'pr'", ['p' => $sv]);
    $prBody = (string)DB::value('SELECT body FROM brgy_forms WHERE id = :f', ['f' => $prForm]);
    $ok('The purchase request carries the estimated cost (2,600.00) and its total', str_contains($prBody, '2,600.00') && str_contains($prBody, 'Test bond paper'));
    $ok('A form of the SOP needs no approval and prints clean', (int)DB::value('SELECT needs_approval FROM brgy_forms WHERE id = :f', ['f' => $prForm]) === 0);
    $does('Step 2 done', fn() => BrgySop::complete((int)$sv, 'svp2', []));
    $refused('Step 3 with no quotation', fn() => BrgySop::complete((int)$sv, 'svp3', ['Three suppliers completed and signed the canvass forms']), 'three');
    BrgySop::saveCanvass((int)$sv, ['is_company' => 1, 'remarks' => 'passed'], $pdf('t_cv1_' . $stamp . '.pdf', 'Canvass company'));
    BrgySop::saveCanvass((int)$sv, ['supplier_name' => 'Test Supplier A', 'total_amount' => 2400, 'remarks' => 'failed'], $pdf('t_cv2_' . $stamp . '.pdf', 'Canvass A'));
    $cv3 = BrgySop::saveCanvass((int)$sv, ['supplier_name' => 'Test Supplier B', 'total_amount' => 2700, 'remarks' => 'passed'], null);
    $refused('Step 3 with a canvass form not signed', fn() => BrgySop::complete((int)$sv, 'svp3', ['Three suppliers completed and signed the canvass forms']), 'signed');
    BrgySop::saveCanvass((int)$sv, ['supplier_name' => 'Test Supplier B', 'total_amount' => 2700, 'remarks' => 'passed'], $pdf('t_cv3_' . $stamp . '.pdf', 'Canvass B'), $cv3);
    $ok('The lowest quotation that passed is the company\'s', (int)(BrgySop::lowest(BrgySop::canvass((int)$sv))['is_company'] ?? 0) === 1);
    $does('Step 3 done: the company quoted the lowest', fn() => BrgySop::complete((int)$sv, 'svp3', ['Three suppliers completed and signed the canvass forms']));
    // v39: step 4 is the price schedule; finishing it records the award (it was the SI, OR and 2307)
    $ok('Not awarded at the canvass: the award waits for the price schedule (step 4)', Brgy::mustProject((int)$sv)['status'] === 'open');
    $does('Step 4 done: the price schedule agrees with the quotation, the award is recorded', fn() => BrgySop::complete((int)$sv, 'svp4', []));
    $P = Brgy::mustProject((int)$sv);
    $ok('Awarded from the canvass for 2,500.00 at step 4, fulfilment started on the PO', $P['status'] === 'awarded' && abs((float)$P['contract_amount'] - 2500) < 0.01 && $P['phase'] === 'fulfillment', $P['status'] . ' ' . $P['phase']);
    $ok('Step 4 has no SI, OR or 2307 to attach, and nothing is held for the payment (special case F is for the bidding)',
        !BrgySop::holdsBilling($P) && !(int)DB::value("SELECT COUNT(*) FROM brgy_project_papers WHERE project_id = :p AND paper_code IN ('si', 'or', 'form_2307')", ['p' => $sv]));
    $iar = (int)DB::value("SELECT id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'iar'", ['p' => $sv]);
    $as('pm');
    $refused('Delivered and accepted without the signed IAR', fn() => BrgySop::deliverAll((int)$sv, []), 'inspection and acceptance');
    $as('maker');
    $does('Signed IAR attached (PDF)', fn() => BrgySop::attach($iar, $pdf('t_iar_' . $stamp . '.pdf', 'IAR signed')));
    $refused('Signed copy that is not a PDF or a picture', fn() => BrgySop::attach($iar, ['name' => 'x.docx', 'tmp_name' => '', 'error' => UPLOAD_ERR_OK, 'size' => 10]), 'PDF');
    $as('pm');
    $does('Delivered and accepted in full from the signed IAR', fn() => BrgySop::deliverAll((int)$sv, ['delivery_date' => today(), 'acceptance_date' => today()]));
    $line = Brgy::lines((int)$sv)[0];
    $w = BrgySop::withholding(Brgy::mustProject((int)$sv));
    $ok('Taxes withheld from the table: VAT 5% and EWT 1% of the amount net of VAT', abs($w['vat'] - round(2500 / 1.12 * 0.05, 2)) < 0.01 && abs($w['ewt'] - round(2500 / 1.12 * 0.01, 2)) < 0.01,
        $w['vat'] . ' / ' . $w['ewt']);
    $as('collector');
    $does('Payment followed up', fn() => BrgySop::followUp((int)$sv, 'Cheque not ready (test)', date('Y-m-d', strtotime('+2 days'))));
    $does('Cheque released', fn() => BrgySop::releasePayment((int)$sv, ['cheque_no' => 'CHK' . $stamp, 'cheque_date' => today(), 'cheque_bank' => 'Landbank', 'cheque_amount' => $w['net']]));
    $as('billing');
    $bid = $does('Billing pack once the cheque is ready', fn() => BrgyOps::saveBilling(['project_id' => $sv, 'billing_date' => today(), 'si_no' => 'T' . $stamp, 'lines' => [$line['id'] => 10]]));
    BrgyOps::billingStep((int)$bid, 'submit');
    $as('checker');
    BrgyOps::billingStep((int)$bid, 'review');
    $as('approver');
    BrgyOps::billingStep((int)$bid, 'approve');
    $as('billing');
    BrgyOps::invoiceBilling((int)$bid);
    $as('collector');
    $bb = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $bid]);
    $exp = BrgyPost::expectedWithholding($bb, (float)$bb['gross_amount']);
    $does('Payment recorded with the official receipt and the taxes withheld', fn() => BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $bid, 'collection_date' => today(), 'method' => 'check',
        'check_no' => 'CHK' . $stamp, 'check_date' => today(), 'bank_name' => 'Landbank', 'cash_received' => r2((float)$bb['gross_amount'] - $exp['ewt'] - $exp['vat']), 'ewt_amount' => $exp['ewt'],
        'vatwh_amount' => $exp['vat'], 'receipt_no' => 'OR-T' . $stamp]));
    $as('approver');
    $refused('The review with signed copies missing', fn() => BrgySop::complete((int)$sv, 'svp5', ['Accuracy', 'Completeness', 'Proper signatures', 'Correct computations']), 'signed copy');
    $as('maker');
    foreach (DB::all("SELECT pp.id, pp.paper_code FROM brgy_project_papers pp WHERE pp.project_id = :p AND pp.needs_signature = 1 AND pp.document_id IS NULL AND pp.step_code IN ('svp1', 'svp2')", ['p' => $sv]) as $x) {
        BrgySop::attach((int)$x['id'], $pdf('t_' . $x['paper_code'] . '_s_' . $stamp . '.pdf', $x['paper_code'] . ' signed'));
    }
    $as('approver');
    $does('Review by another person', fn() => BrgySop::complete((int)$sv, 'svp5', ['Accuracy', 'Completeness', 'Proper signatures', 'Correct computations']));
    $as('maker');
    $does('Filed and encoded in monitoring', fn() => BrgySop::complete((int)$sv, 'svp6', ['Copies of all essential documents retained', 'Transaction details encoded in the Monitoring System']));
    $refused('Forward without the date', fn() => BrgySop::complete((int)$sv, 'svp7', []), 'forwarded');
    BrgySop::saveStepData((int)$sv, 'svp7', ['forwarded_to' => 'Accounting Department', 'forwarded_date' => today()]);
    $does('Forwarded to accounting', fn() => BrgySop::complete((int)$sv, 'svp7', []));
    $trForm = BrgySop::prepare((int)DB::value("SELECT id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'transmittal'", ['p' => $sv]));
    BrgySop::saveStepData((int)$sv, 'svp8', ['transmittal_no' => (string)DB::value('SELECT form_no FROM brgy_forms WHERE id = :f', ['f' => $trForm])]);
    $does('Transmittal prepared', fn() => BrgySop::complete((int)$sv, 'svp8', []));
    BrgySop::saveStepData((int)$sv, 'svp9', ['release_method' => 'pickup', 'released_to' => 'Test Treasurer', 'released_date' => today()]);
    $as('approver');
    $refused('Release without the signed transmittal returned', fn() => BrgySop::complete((int)$sv, 'svp9', []), 'transmittal');
    $as('maker');
    BrgySop::attach((int)DB::value("SELECT id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'transmittal'", ['p' => $sv]), $pdf('t_tr_' . $stamp . '.pdf', 'Transmittal signed'));
    $as('approver');
    $does('Released, the signed transmittal returned: the last step closes the contract', fn() => BrgySop::complete((int)$sv, 'svp9', []));
    $ok('Closed out and every step done', Brgy::mustProject((int)$sv)['status'] === 'closed' && (int)DB::value("SELECT COUNT(*) FROM brgy_project_steps WHERE project_id = :p AND status = 'pending'", ['p' => $sv]) === 0);
    $as('maker');
    $refused('Skipping a step without being an approver', fn() => BrgySop::skip((int)$pid, 'svp1', 'test'), 'approver');

    echo "\n9. The format of the forms\n";
    $L = BrgyLayout::fromPost(['paper' => 'Custom', 'width_mm' => '9999', 'height_mm' => '120', 'margin_top' => '-5', 'font' => 'Comic', 'font_size' => '50', 'copies' => '9',
        'col' => ['pr.items' => ['qty' => ['show' => '1', 'label' => 'Quantity', 'width' => '200', 'align' => 'diagonal']]], 'hidden' => ['penalty', '<x>']]);
    $ok('Format kept within bounds (size, margins, font, copies, columns)', BrgyLayout::size($L) === [600.0, 120.0] && $L['margin_top'] == 0 && $L['font'] === 'Arial' && $L['font_size'] == 20
        && $L['copies'] === 6 && $L['columns']['pr.items']['qty']['width'] === 90 && $L['columns']['pr.items']['qty']['align'] === 'center' && $L['hidden'] === ['penalty', 'x']);
    $L2 = BrgyLayout::layout(['layout_json' => json_encode(['columns' => ['po.items' => ['unit_price' => ['show' => 0]]], 'blank_rows' => 0, 'nothing_follows' => 0])]);
    $t = BrgyLayout::itemsTable('po.items', Brgy::lines((int)$sv), $L2);
    $ok('A column switched off is not printed', !str_contains($t, 'Unit Cost') && str_contains($t, 'Particulars') && !str_contains($t, 'nothing follows'));
    $html = BrgyDocs::sanitize('<div data-section="penalty" onclick="x()">A</div><img src="index.php?p=brgy.entities&a=seal&id=1&k=seal"><img src="http://evil.test/x.png">');
    $ok('Parts and seals survive the cleaning; scripts and outside pictures do not', str_contains($html, 'data-section="penalty"') && str_contains($html, 'a=seal') && !str_contains($html, 'onclick') && !str_contains($html, 'evil.test'));
    $P2 = BrgyLayout::positionsFromPost(['paper' => 'HalfLetter'], json_encode(['fields' => [['key' => 'txn.series', 'x' => 500, 'y' => 10, 'w' => 40], ['key' => 'nonsense.field', 'x' => 1, 'y' => 1]], 'tables' => []]));
    $ok('Positions kept on the paper, unknown fields dropped', count($P2['fields']) === 1 && $P2['fields'][0]['x'] <= 139.7);

    echo "\n9b. Letterheads, signatories and the types of transaction\n";
    $sig = array_fill(0, 20, ['name' => '{{signatory.name}}', 'pos' => 'Proprietor', 'align' => 'sideways']);
    $L3 = BrgyLayout::fromPost(['lh_style' => 'own', 'lh_left' => 'logo', 'lh_right' => 'virus', 'lh_box_bg' => 'red;background:url(x)', 'lh_pic_a' => '../../config.php', 'lh_size' => '99',
        'sig' => $sig, 'sig_cols' => '9', 'footer' => 'OFFICE OF THE BARANGAY', 'footer_pos' => 'bottom', 'footer_align' => 'center', 'th_bg' => '#FFF2CC', 'date_style' => 'mdy']);
    $ok('Letterhead and signatory settings kept within bounds', $L3['lh_right'] === 'none' && $L3['lh_box_bg'] === '#b8963e' && $L3['lh_pic_a'] === '' && $L3['lh_size'] == 24
        && count($L3['signatories']) === 16 && $L3['sig_cols'] === 4 && $L3['signatories'][0]['align'] === 'left' && $L3['th_bg'] === '#fff2cc' && $L3['date_style'] === 'mdy');
    $render = fn(string $t) => BrgyDocs::render($t, ['company.name' => 'ACME', 'company.phone' => '', 'company.email' => '', 'signatory.name' => 'Hon. Juan Cruz'] + array_fill_keys(['entity.title_upper'], ''));
    $lh = BrgyLayout::letterheadHtml(['lh_lines' => "# {{company.name}}\nTel. {{company.phone}} {{company.email}}\n**Bold line**"] + $L3, [], $render);
    $ok('Letterhead: big line, bold, and a line of blank fields left out', str_contains($lh, 'lh-big') && str_contains($lh, '>ACME<') && str_contains($lh, '<b>Bold line</b>') && !str_contains($lh, 'Tel.'));
    $sg = BrgyLayout::signatoriesHtml(['signatories' => [BrgyLayout::sigBlock(['name' => 'HON. {{signatory.name}}', 'pos' => 'BAC Chairman', 'align' => 'half']),
        BrgyLayout::sigBlock(['name' => '{{entity.bac_member_5_upper}}', 'pos' => 'BAC Member', 'newrow' => 1])], 'sig_cols' => 2] + BrgyLayout::DEFAULTS, $render);
    $ok('Signatories: the title once, a blank name printed as a line to sign on, the right half', substr_count($sg, 'HON.') === 1 && str_contains($sg, 'sg-line') && str_contains($sg, 'width:52%'));
    $ok('Footer at the bottom of the page', BrgyLayout::docClass($L3) === ' has-foot-bottom' && str_contains(BrgyLayout::footerHtml($L3), 'OFFICE OF THE BARANGAY'));
    $ok('Amounts and periods in words', BrgyDocs::priceText(194500) === 'One Hundred Ninety-Four Thousand Five Hundred Pesos and 00/100 (Php 194,500.00) Only' && BrgyDocs::numWords(30) === 'Thirty');
    $html = BrgyDocs::sanitize('<img src="index.php?p=file.lhpic&f=lhpic_company_ab12.png" style="height:24mm;object-fit:contain">');
    $ok('Letterhead pictures survive the cleaning', str_contains($html, 'p=file.lhpic') && str_contains($html, 'object-fit:contain'));
    $oldShared = Settings::get('brgy_lh_company', '');
    $tplLoi = DB::row("SELECT * FROM brgy_templates WHERE code = 'letter_of_intent'");
    if ($tplLoi) {
        BrgyLayout::saveSharedLetterhead('company', ['lh_lines' => "# TEST LETTERHEAD " . $stamp] + BrgyLayout::sharedLetterhead('company'));
        $Lt = BrgyLayout::layout($tplLoi);
        $ok('A template with the company letterhead shows the shared one', $Lt['lh_style'] === 'company' && str_contains($Lt['lh_lines'], 'TEST LETTERHEAD ' . $stamp));
        $body = BrgyDocs::render($tplLoi['body'], BrgyDocs::context(Brgy::mustProject((int)$sv), null, 'T', $Lt));
        $ok('Letter of intent: letterhead, the law and the signature filled', str_contains($body, 'TEST LETTERHEAD') && str_contains($body, 'Republic Act No. 12009') && str_contains($body, 'fx-sigs') && !str_contains($body, '{{'));
        Settings::set('brgy_lh_company', $oldShared);
    }
    foreach (['notice_of_award' => 'Pesos and', 'bac_resolution' => 'BAC Member', 'technical_specifications' => 'Statement of', 'statement_ongoing_contracts' => 'Owner', 'statement_similar_contracts' => 'Amount at Award',
                 'supplier_profile' => 'Legal Aspect', 'production_details' => 'WEEKS', 'notice_of_post_qualification' => 'Post Qualification'] as $code => $want) {
        $tp = DB::row('SELECT * FROM brgy_templates WHERE code = :c', ['c' => $code]);
        $out = $tp ? BrgyDocs::render($tp['body'], BrgyDocs::context(Brgy::mustProject((int)$pid), null, 'T', BrgyLayout::layout($tp))) : '';
        $ok('Form ' . $code . ' is filled', $tp && str_contains($out, $want) && !str_contains($out, 'ph-unknown'), $tp ? '' : 'template missing');
    }
    // the types of transaction: a step and a paper only for construction
    $as('maker');
    $tv = $does('Cash Out (purchases) transaction recorded', fn() => BrgyOps::saveProject(['process' => 'svp', 'title' => 'Cash out test ' . $stamp, 'entity_id' => $entity, 'category' => 'goods',
        'mode_id' => $mode, 'abc_amount' => '15000', 'txn_type' => 'cash_out', 'purpose' => 'Test']));
    $ok('The type of the transaction is Cash Out', Brgy::mustProject((int)$tv)['txn_type'] === 'cash_out' && isset(BrgySop::txnTypes()['kaliwaan']));
    $boxes = BrgyDocs::context(Brgy::mustProject((int)$tv))['txn.types']['html'];
    $ok('Its transaction form ticks CASH OUT (the name without the brackets)', (bool)preg_match('/is-on"><\/span> CASH OUT</', $boxes) && str_contains($boxes, 'KALIWAAN'));
    $keepStep = DB::value("SELECT txn_types FROM brgy_sop_steps WHERE code = 'svp3'");
    $keepPaper = DB::value("SELECT txn_types FROM brgy_sop_papers WHERE code = 'abstract'");
    DB::run("UPDATE brgy_sop_steps SET txn_types = 'construction' WHERE code = 'svp3'");
    DB::run("UPDATE brgy_sop_papers SET txn_types = 'construction' WHERE code = 'abstract'");
    BrgySop::ensure(Brgy::mustProject((int)$tv));
    $st = DB::row("SELECT status, remarks FROM brgy_project_steps WHERE project_id = :p AND step_code = 'svp3'", ['p' => $tv]);
    $pp = DB::row("SELECT status, waive_reason FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'abstract'", ['p' => $tv]);
    $ok('A step not for Cash Out is skipped, a paper not for it marked not needed (with why)', $st['status'] === 'skipped' && str_contains((string)$st['remarks'], 'Cash Out')
        && $pp['status'] === 'waived' && str_contains((string)$pp['waive_reason'], 'Cash Out'));
    $P = Brgy::mustProject((int)$tv);
    BrgyOps::saveProject(['title' => $P['title'], 'entity_id' => $entity, 'category' => 'goods', 'mode_id' => $mode, 'abc_amount' => '15000', 'txn_type' => 'construction'], (int)$tv, $P['lock_version']);
    $st = DB::row("SELECT status FROM brgy_project_steps WHERE project_id = :p AND step_code = 'svp3'", ['p' => $tv]);
    $pp = DB::row("SELECT status FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'abstract'", ['p' => $tv]);
    $ok('Changed to Construction: the step and the paper come back', $st['status'] === 'pending' && $pp['status'] === 'open');
    DB::run('UPDATE brgy_sop_steps SET txn_types = :t WHERE code = :c', ['t' => $keepStep, 'c' => 'svp3']);
    DB::run('UPDATE brgy_sop_papers SET txn_types = :t WHERE code = :c', ['t' => $keepPaper, 'c' => 'abstract']);
}

echo "\n10. The books\n";
$as('approver');
$chk = BrgyOps::closeoutCheck(Brgy::mustProject($pid));
$ok('Closeout refused while 4 chairs are still to be delivered and billed', (bool)$chk['blocking'] || (bool)$chk['warnings']);
$issues = [];
foreach (BrgyPost::reconcile() as $r) {
    if ($r['issues']) {
        $issues[] = $r['project_no'] . ': ' . implode('; ', $r['issues']);
    }
}
$ok('Every contract agrees with the ledger', !$issues, implode(' | ', $issues));
$tb = (float)DB::value('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM journal_lines');
$ok('Trial balance in balance', abs($tb) < 0.005 && abs($tbBefore) < 0.005, number_format($tb, 2));
$posted = DB::all("SELECT source_type, source_id, event, COUNT(*) AS n FROM brgy_postings WHERE status = 'posted' GROUP BY source_type, source_id, event HAVING COUNT(*) > 1");
$ok('No record posted twice for the same event', !$posted);

echo "\n11. Previews of Word, Excel, PowerPoint and Publisher files (Document Vault)\n";
$fx = APP_ROOT . '/tests/fixtures/office';
$quick = function (string $file, string $ext): string {
    return match (OfficePreview::kind($ext)) {
        'word' => OfficeWord::render($file, $ext), 'excel' => OfficeSheet::render($file, $ext), 'powerpoint' => OfficeSlides::render($file, $ext),
        'publisher' => OfficePublisher::render($file), default => '',
    };
};
$plain = fn(string $html) => html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '</td>', '<br>'], [' ', ' ', ' '], $html))), ENT_QUOTES, 'UTF-8');
$safe = fn(string $html) => !preg_match('/<script|<iframe|<object|<embed|<link|\son[a-z]+\s*=|javascript:|(src|href)="(?!data:image\/)/i', $html);
foreach (['supplier_profile.docx' => ['Supplier Profile Test', 'bold', 'Total (merged)', 'Second bullet'], 'supplier_profile_97.doc' => ['Supplier Profile Test', 'Flashlight', '58,800.00', 'Step one'],
             'supplier_profile.rtf' => ['Supplier Profile Test', 'Ñiño café', 'Total (merged)'], 'price_schedule.xlsx' => ['Price schedule', 'Tanod uniform', '2,850.50', '11/12/2026', '12.5%'],
             'price_schedule_97.xls' => ['Price schedule', 'Tanod uniform', '171,030.00', '367,830.00'], 'bid_presentation.pptx' => ['Slide 3', 'Bid Presentation', 'Supply and delivery', '138,000.00'],
             'bid_presentation_97.ppt' => ['Slide 2', 'Bid Presentation', 'Within thirty days']] as $name => $want) {
    $file = $fx . '/' . $name;
    if (!is_file($file)) {
        $ok($name . ': the sample file is in tests/fixtures/office', false);
        continue;
    }
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    try {
        $html = $quick($file, $ext);
        $text = $plain($html);
        $missing = array_filter($want, fn($w) => !str_contains($text, $w));
        $ok($name . ': the quick view shows its text, tables and numbers', !$missing, 'missing: ' . implode(', ', $missing));
        $ok($name . ': nothing active in the quick view (no script, frame, event or outside link)', $safe($html));
        if (in_array($ext, ['docx', 'doc', 'rtf', 'pptx', 'ppt'], true)) {
            $ok($name . ': its picture is shown', str_contains($html, '<img src="data:image/png;base64,'));
        }
    } catch (Throwable $e) {
        $ok($name . ': the quick view reads it', false, $e->getMessage());
    }
}
// the older and plainer forms Word and Excel also save, made here
$tmp = sys_get_temp_dir() . '/brgy_op_' . bin2hex(random_bytes(4));
@mkdir($tmp);
$biff2 = pack('vv', 0x0009, 4) . pack('vv', 2, 0x10);
foreach ([[0, 0, 'Excel 2.1 sheet'], [1, 0, 'Projector']] as [$r, $c, $t]) {
    $biff2 .= pack('vv', 0x0004, 8 + strlen($t)) . pack('vv', $r, $c) . "\0\0\0" . chr(strlen($t)) . $t;
}
$biff2 .= pack('vv', 0x0003, 15) . pack('vv', 1, 1) . "\0\0\0" . pack('e', 12500.5) . pack('vv', 0x000A, 0);
$made = [
    'excel21.xls' => [$biff2, ['Excel 2.1 sheet', 'Projector', '12500.5']],
    'xml2003.xls' => ['<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Bids"><Table>'
        . '<Row><Cell><Data ss:Type="String">Laptop</Data></Cell><Cell><Data ss:Type="Number">45000</Data></Cell></Row></Table></Worksheet></Workbook>', ['Bids', 'Laptop', '45000']],
    'webpage.xls' => ['<html><body><table border="1"><tr><th>Item</th><th>Amount</th></tr><tr><td>Printer ink</td><td>1,250.00</td></tr></table></body></html>', ['Printer ink', '1,250.00']],
    'xml2003.doc' => ['<?xml version="1.0"?><?mso-application progid="Word.Document"?><w:wordDocument xmlns:w="http://schemas.microsoft.com/office/word/2003/wordml"><w:body>'
        . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Notice of Award</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Lot 1</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:wordDocument>', ['Notice of Award', 'Lot 1']],
    'webpage.doc' => ['<html><body><h1>Bid Bulletin No. 1</h1><p>Deadline <b>moved</b>.</p><script>alert(1)</script></body></html>', ['Bid Bulletin No. 1', 'Deadline moved']],
    'list.csv' => ["Item,Amount\n\"Chairs, monobloc\",3500\n<script>alert(1)</script>,1\n", ['Chairs, monobloc', '3500', '<script>alert(1)</script>']],
];
foreach ($made as $name => [$bytes, $want]) {
    file_put_contents($tmp . '/' . $name, $bytes);
    try {
        $html = $quick($tmp . '/' . $name, pathinfo($name, PATHINFO_EXTENSION));
        $text = $plain($html);
        $missing = array_filter($want, fn($w) => !str_contains($text, $w));
        $ok($name . ': read by the quick view', !$missing, 'missing: ' . implode(', ', $missing));
        $ok($name . ': its text is escaped, nothing active', $safe($html));
    } catch (Throwable $e) {
        $ok($name . ': read by the quick view', false, $e->getMessage());
    }
}
// what the vault accepts: a real Office file, not a zip or a program named like one
if (is_file($fx . '/supplier_profile.docx')) {
    copy($fx . '/supplier_profile.docx', $tmp . '/real.docx');
    $does('A real Word file passes the upload check', fn() => BrgyDocs::scan($tmp . '/real.docx', 'docx', 'real.docx'));
}
if (class_exists('ZipArchive')) {
    $z = new ZipArchive();
    $z->open($tmp . '/fake.docx', ZipArchive::CREATE);
    $z->addFromString('readme.txt', 'not a document');
    $z->close();
    $refused('A zip renamed .docx', fn() => BrgyDocs::scan($tmp . '/fake.docx', 'docx', 'fake.docx'), 'zip');
} else {
    echo "  skip  The zip extension of PHP is off: Office 2007 and later files are not checked inside\n";
}
file_put_contents($tmp . '/prog.doc', 'MZ' . str_repeat("\0", 200));
$refused('A program renamed .doc', fn() => BrgyDocs::scan($tmp . '/prog.doc', 'doc', 'prog.doc'), 'program');
file_put_contents($tmp . '/text.ppt', str_repeat('just text ', 50));
$refused('A text file renamed .ppt', fn() => BrgyDocs::scan($tmp . '/text.ppt', 'ppt', 'text.ppt'));
$ok('The upload box offers PowerPoint, Publisher and the older Office files', str_contains(BrgyDocs::accept(), '.pptx') && str_contains(BrgyDocs::accept(), '.pub') && str_contains(BrgyDocs::accept(), '.xlsb') && str_contains(BrgyDocs::accept(), '.rtf'));
// made once, kept by the checksum
if (is_file($fx . '/price_schedule_97.xls')) {
    $key = 'test_' . bin2hex(random_bytes(6));
    $a1 = OfficePreview::html($fx . '/price_schedule_97.xls', 'xls', $key);
    $cache = OfficePreview::cacheDir() . '/' . $key . '.v' . OfficePreview::VERSION . '.html';
    $a2 = OfficePreview::html($fx . '/price_schedule_97.xls', 'xls', $key);
    $ok('A quick view is made once and kept', $a1['error'] === '' && is_file($cache) && $a1['html'] === $a2['html']);
    @unlink($cache);
    if (OfficePreview::converter()) {
        $err = null;
        $pdf = OfficePreview::pdf($fx . '/supplier_profile_97.doc', 'doc', $key, $err);
        $ok('The page view (LibreOffice) makes the pages of a Word 97 file', $pdf !== null && str_starts_with((string)file_get_contents($pdf, false, null, 0, 5), '%PDF'), (string)$err);
        if ($pdf) {
            @unlink($pdf);
        }
    } else {
        echo "  skip  The page view: LibreOffice is not installed on this computer (the quick view is used)\n";
    }
}
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

foreach ($keepSettings as $k => $v) {
    Settings::set($k, $v);
}
echo "\n" . $pass . ' check(s) passed, ' . count($failures) . " failed.\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit($failures ? 1 : 0);
