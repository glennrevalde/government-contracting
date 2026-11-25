<?php
declare(strict_types=1);

/**
 * Version 39: checks of the requests of the Excel file v7.
 *   1. A person may hold up to two roles on a transaction; three and above are refused.
 *   2. Step 4 of the small value procurement is the price schedule (it was the billing documents), and the SI, OR and
 *      2307 are no longer required there; finishing it records the award.
 *   3. The purchase request (and the DV, IAR, RIS) may be saved with their numbers and dates blank.
 *   4. The rows of the purchase request: as many as needed, typed on the form, and taken over by the price schedule.
 *   5. Files are made smaller before they are kept.
 *   6. The barangay's officers on the customer, named on the forms; completed contracts keep the officers they had.
 *   7. The Barangay Project Monitoring Board.
 *   8. A transaction recorded with the PhilGEPS reference, source link, source of funds and ABC blank.
 *
 *   php tools/test_v39.php --yes [--company=N]
 *
 * Everything it does runs in one database transaction that is rolled back at the end, so nothing it makes is kept (the
 * test files it files in storage/contracts are deleted again). Still, run it on a copy of the database or on the demo.
 * The pages themselves (the Items panel, the board, the officers, the upload) are checked in the browser.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This adds test transactions, forms and officers. Run it on a copy of the database: php tools/test_v39.php --yes\n");
    exit(2);
}
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/views/layout.php';
require_once __DIR__ . '/demo_brgy.php';
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
$users = array_map('intval', DB::column("SELECT id FROM users WHERE status = 'active' AND id <> :a ORDER BY id LIMIT 3", ['a' => $admin]));
$entity = (int)DB::value('SELECT id FROM brgy_entities WHERE is_active = 1 ORDER BY id LIMIT 1');
if (count($users) < 3 || !$entity) {
    fwrite(STDERR, "The demo users and a procuring entity are needed. Run it on the demo database.\n");
    exit(2);
}
[$u1, $u2, $u3] = $users;
$stamp = date('His');
$pdf = fn(string $name) => brgy_demo_pdf($name, [$name]);
$filesBefore = array_flip(glob(BrgyDocs::dir() . '/*') ?: []);
$keep = ['brgy_sod_strict' => Brgy::setting('brgy_sod_strict'), 'brgy_sop_need_scans' => Brgy::setting('brgy_sop_need_scans')];

DB::begin();
try {
    Settings::set('brgy_sod_strict', '1');
    Settings::set('brgy_sop_need_scans', '0');

    echo "Version 39 checks\n\n1. Up to two roles per person on a transaction\n";
    $why = $refusal(fn() => BrgyOps::saveProject(['title' => 'T39 roles ' . $stamp, 'entity_id' => $entity, 'preparer_id' => $u1, 'checker_id' => $u1, 'approver_id' => $u1]));
    $ok('The same person as preparer, checker and approver (three roles): refused, naming them', str_contains($why, 'three roles') && str_contains($why, 'preparer, checker and approver'), $why);
    $two = $refusal(fn() => BrgyOps::saveProject(['title' => 'T39 two roles ' . $stamp, 'entity_id' => $entity, 'preparer_id' => $u1, 'checker_id' => $u1, 'approver_id' => $u2]));
    $ok('The same person as preparer and checker (two roles): saved', $two === '', $two);
    $p2 = (int)DB::value('SELECT id FROM brgy_projects WHERE title = :t', ['t' => 'T39 two roles ' . $stamp]);
    $why = $refusal(fn() => BrgyOps::saveProject(['process' => 'svp', 'title' => 'T39 rep ' . $stamp, 'entity_id' => $entity, 'sales_rep_id' => $u3, 'preparer_id' => $u3, 'collector_id' => $u3]));
    $ok('The sales representative counts as a role (sales representative, preparer and collector)', str_contains($why, 'sales representative, preparer and collector'), $why);
    $P2 = Brgy::mustProject($p2);
    $why = $refusal(fn() => BrgyOps::saveProject(['title' => $P2['title'], 'entity_id' => $entity, 'preparer_id' => $u1, 'checker_id' => $u1, 'approver_id' => $u2, 'collector_id' => $u1], $p2, $P2['lock_version']));
    $ok('A third role given on a change: refused', str_contains($why, 'three roles'), $why);
    $bp = BrgyOps::saveProject(['process' => 'bidding', 'title' => 'T39 bidding roles ' . $stamp, 'entity_id' => $entity, 'preparer_id' => $u1, 'checker_id' => $u1, 'approver_id' => $u2]);
    BrgySop::ensure(Brgy::mustProject($bp));
    $why = $refusal(fn() => BrgySop::saveStepData($bp, 'bid5', ['submitter_id' => $u1]));
    $ok('The authorized representative named at its step counts too', str_contains($why, 'three roles'), $why);
    Settings::set('brgy_sod_strict', '0');
    $off = $refusal(fn() => BrgyOps::saveProject(['title' => 'T39 sod off ' . $stamp, 'entity_id' => $entity, 'preparer_id' => $u1, 'checker_id' => $u1, 'approver_id' => $u1]));
    $ok('Segregation of duties switched off: any number of roles', $off === '', $off);
    Settings::set('brgy_sod_strict', '1');
    // a record's own roles: preparer, checker, approver
    $ftpl = (int)DB::value('SELECT id FROM brgy_templates WHERE needs_approval = 1 AND is_active = 1 ORDER BY id LIMIT 1');
    $fid = BrgyOps::createForm($ftpl, $p2);
    DB::update('brgy_forms', ['status' => 'for_review'], 'id = :id', ['id' => $fid]);
    DB::insert('brgy_workflow', ['project_id' => $p2, 'object_type' => 'form', 'object_id' => $fid, 'action' => 'submit', 'from_status' => 'draft', 'to_status' => 'for_review', 'user_id' => $admin]);
    [$can, $whyNot] = Brgy::canStep('form', DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]), 'review', Brgy::mustProject($p2));
    $ok('The person who prepared a form may review it (two roles)', $can, (string)$whyNot);
    DB::update('brgy_forms', ['status' => 'reviewed'], 'id = :id', ['id' => $fid]);
    DB::insert('brgy_workflow', ['project_id' => $p2, 'object_type' => 'form', 'object_id' => $fid, 'action' => 'review', 'from_status' => 'for_review', 'to_status' => 'reviewed', 'user_id' => $admin]);
    [$can, $whyNot] = Brgy::canStep('form', DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]), 'approve', Brgy::mustProject($p2));
    $ok('... but not approve what they both prepared and reviewed (three roles)', !$can && str_contains((string)$whyNot, 'prepared and reviewed'), (string)$whyNot);
    DB::run("UPDATE brgy_workflow SET user_id = :u WHERE object_type = 'form' AND object_id = :f AND action = 'review'", ['u' => $u2, 'f' => $fid]);
    [$can, $whyNot] = Brgy::canStep('form', DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]), 'approve', Brgy::mustProject($p2));
    $ok('The preparer may approve what another person reviewed (two roles)', $can, (string)$whyNot);
    $doc = BrgyDocs::store($pdf('t39_self_' . $stamp . '.pdf'), ['scope' => 'project', 'project_id' => $p2, 'doc_type' => 'other', 'title' => 'T39 self review', 'status' => 'for_review']);
    $selfReview = $refusal(fn() => BrgyDocs::review(BrgyDocs::load($doc), 'approved', 'Checked'));
    $ok('A paper may be reviewed by the person who filed it (two roles)', $selfReview === '' && DB::value('SELECT status FROM brgy_documents WHERE id = :d', ['d' => $doc]) === 'approved', $selfReview);
    $set = file_get_contents(APP_ROOT . '/modules/brgy/settings.php');
    $form = file_get_contents(APP_ROOT . '/modules/brgy/projects.php');
    $ok('Contracting Settings and the transaction form say so; the form warns as the people are chosen', str_contains($set, 'A person holds up to two roles on a transaction')
        && str_contains($form, 'id="roleWarn"') && str_contains($form, 'A person may hold up to two roles on the transaction'));

    echo "\n2. Step 4 of the small value procurement: the price schedule\n";
    $s4 = DB::row("SELECT * FROM brgy_sop_steps WHERE code = 'svp4'");
    $ok('Step 4 is "Prepare price schedule", with no SI, OR and 2307 to attach', $s4 && $s4['title'] === 'Prepare price schedule' && $s4['action'] === 'price_schedule' && trim((string)$s4['papers']) === '' && trim((string)$s4['checks']) === '',
        json_encode([$s4['title'] ?? '', $s4['action'] ?? '', $s4['papers'] ?? '']));
    require_once APP_ROOT . '/tools/brgy_sop_seed.php';
    $seed = current(array_filter(brgy_sop_steps(), fn($x) => $x[1] === 'svp4'));
    $ok('... the same on a new installation', $seed && $seed[5] === 'Prepare price schedule' && $seed[9] === 'price_schedule' && $seed[7] === '');
    $ok('The small value procurement no longer holds the SI, OR and 2307; the bidding still does (special case F)', BrgySop::hasPricing('svp') && !BrgySop::hasAction('svp', 'billing_docs') && BrgySop::hasAction('bidding', 'billing_docs'));
    $sv = BrgyOps::saveProject(['process' => 'svp', 'title' => 'T39 Supply of chairs ' . $stamp, 'entity_id' => $entity, 'category' => 'goods', 'requestor' => 'T39 Requisitioner', 'inspector' => 'T39 Inspector',
        'txn_type' => 'purchases', 'purpose' => 'Barangay use', 'preparer_id' => $u1, 'checker_id' => $u2, 'approver_id' => $u3]);
    $P = Brgy::mustProject($sv);
    BrgySop::ensure($P);
    $g1 = BrgySop::gate($P, BrgySop::steps('svp')[0] ?? DB::row("SELECT * FROM brgy_sop_steps WHERE code = 'svp1'"), BrgySop::stepChecks(DB::row("SELECT * FROM brgy_sop_steps WHERE code = 'svp1'")));
    $ok('Step 1 asks for neither the approved budget nor the items (they are step 4)', !preg_grep('/approved budget|items/i', $g1), implode(' | ', $g1));
    BrgySop::prepare((int)DB::value("SELECT id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'txn_form'", ['p' => $sv]));
    $m = $refusal(fn() => BrgySop::complete($sv, 'svp1', BrgySop::stepChecks(DB::row("SELECT * FROM brgy_sop_steps WHERE code = 'svp1'"))));
    $ok('Step 1 done with no ABC and no price schedule', $m === '', $m);
    $n = BrgySop::prepareStep($sv, 'svp2');
    $ok('Step 2: the seven papers prepared', $n === 7, (string)$n);
    $prId = (int)DB::value("SELECT form_id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'pr'", ['p' => $sv]);
    $pr = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $prId]);
    $ok('The purchase request is made with an empty item table the page can fill (columns marked)', str_contains($pr['body'], 'data-table="pr.items" data-cols="no,qty,unit,description,est_unit,est_amount"') && !BrgyDocs::prItems($sv));
    // the rows typed on the purchase request, as the page writes them (Fill in the blanks > Items)
    $L = BrgyLayout::layout(DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $pr['template_id']]));
    $typed = [['bid_qty' => 10, 'qty' => 10, 'unit' => 'pc', 'description' => 'T39 Monobloc chair', 'specs' => '', 'unit_price' => 450, 'est_unit_price' => 450, 'co_id' => null],
              ['bid_qty' => 1, 'qty' => 1, 'unit' => 'unit', 'description' => 'T39 Electric fan', 'specs' => '', 'unit_price' => 1200, 'est_unit_price' => 1200, 'co_id' => null]];
    $body = (string)preg_replace('#<table class="fx fx-items" data-table="pr\.items".*?</table>#s', BrgyLayout::itemsTable('pr.items', $typed, $L), $pr['body'], 1);
    BrgyOps::saveForm($prId, ['body' => $body, 'title' => $pr['title'], 'signatory_name' => $pr['signatory_name'], 'signatory_position' => $pr['signatory_position'], 'paper' => $pr['paper'], 'orientation' => $pr['orientation']]);
    $items = BrgyDocs::prItems($sv);
    $ok('The items typed on the purchase request are read back (2 rows: description, unit, quantity, estimated cost)', count($items['items'] ?? []) === 2 && $items['items'][0]['description'] === 'T39 Monobloc chair'
        && (float)$items['items'][0]['qty'] === 10.0 && (float)$items['items'][0]['est'] === 450.0 && $items['items'][1]['unit'] === 'unit', json_encode($items['items'] ?? []));
    $m = $refusal(fn() => BrgySop::complete($sv, 'svp2', []));
    $ok('Step 2 done', $m === '', $m);
    BrgySop::saveCanvass($sv, ['is_company' => 1, 'total_amount' => '5,000.00', 'remarks' => 'passed'], $pdf('t39_cv1_' . $stamp . '.pdf'));
    $ok('The company\'s quotation is typed while the price schedule is empty (5,000.00)', abs((float)DB::value('SELECT total_amount FROM brgy_canvass WHERE project_id = :p AND is_company = 1', ['p' => $sv]) - 5000) < 0.01);
    BrgySop::saveCanvass($sv, ['supplier_name' => 'T39 Supplier A', 'total_amount' => 5200, 'remarks' => 'passed'], $pdf('t39_cv2_' . $stamp . '.pdf'));
    BrgySop::saveCanvass($sv, ['supplier_name' => 'T39 Supplier B', 'total_amount' => 5500, 'remarks' => 'failed'], $pdf('t39_cv3_' . $stamp . '.pdf'));
    $msg = '';
    $m = $refusal(function () use (&$msg, $sv) { $msg = BrgySop::complete($sv, 'svp3', ['Three suppliers completed and signed the canvass forms']); });
    $ok('Step 3 done: the company quoted the lowest; the award waits for the price schedule', $m === '' && Brgy::mustProject($sv)['status'] === 'open' && str_contains($msg, 'price schedule'), $m . $msg);
    $s4row = DB::row("SELECT * FROM brgy_sop_steps WHERE code = 'svp4'");
    $g = BrgySop::gate(Brgy::mustProject($sv), $s4row);
    $ok('Step 4 with no price schedule: stopped, offering the items of the purchase request', (bool)preg_grep('/take the items typed on the purchase request/', $g), implode(' | ', $g));
    [$nIt, $prNo] = BrgyOps::linesFromPr($sv, Brgy::mustProject($sv)['lock_version']);
    $lines = Brgy::lines($sv);
    $ok('The price schedule takes the purchase request\'s items (estimated cost and, to start with, the unit price)', $nIt === 2 && $prNo === $pr['form_no'] && count($lines) === 2
        && (float)$lines[0]['est_unit_price'] === 450.0 && (float)$lines[0]['unit_price'] === 450.0 && (float)$lines[0]['bid_qty'] === 10.0 && $lines[1]['unit'] === 'unit');
    $ok('... only into an empty price schedule', str_contains($refusal(fn() => BrgyOps::linesFromPr($sv)), 'already has items'));
    $g = BrgySop::gate(Brgy::mustProject($sv), $s4row);
    $ok('A price schedule (5,700.00) that does not agree with the company\'s quotation (5,000.00): stopped', (bool)preg_grep('/does not agree with the company\'s quotation/', $g), implode(' | ', $g));
    BrgySop::quoteFromSchedule($sv);
    $g = BrgySop::gate(Brgy::mustProject($sv), $s4row);
    $ok('The quotation made the price schedule\'s total (5,700.00) is no longer the lowest (Supplier A, 5,200.00): stopped', (bool)preg_grep('/not the lowest that passed/', $g), implode(' | ', $g));
    $L2 = Brgy::lines($sv);
    BrgyOps::saveLines($sv, [['id' => $L2[0]['id'], 'description' => $L2[0]['description'], 'unit' => 'pc', 'qty' => 10, 'unit_cost' => 300, 'unit_price' => 380, 'est_unit_price' => 450],
        ['id' => $L2[1]['id'], 'description' => $L2[1]['description'], 'unit' => 'unit', 'qty' => 1, 'unit_cost' => 1000, 'unit_price' => 1200, 'est_unit_price' => 1200]], Brgy::mustProject($sv)['lock_version']);
    BrgySop::quoteFromSchedule($sv);
    $g = BrgySop::gate(Brgy::mustProject($sv), $s4row);
    $ok('Prices changed to 5,000.00 and the quotation with them: nothing stands in the way', !$g, implode(' | ', $g));
    // the PO no. and date typed on the purchase order (kept for the transaction's forms)
    $poId = (int)DB::value("SELECT form_id FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'po'", ['p' => $sv]);
    DB::run('INSERT INTO brgy_form_values (project_id, ph_key, value, form_id) VALUES (:p, :k, :v, :f)', ['p' => $sv, 'k' => 'po.no', 'v' => 'PO-T39-' . $stamp, 'f' => $poId]);
    DB::run('INSERT INTO brgy_form_values (project_id, ph_key, value, form_id) VALUES (:p, :k, :v, :f)', ['p' => $sv, 'k' => 'po.date', 'v' => '11/26/2026', 'f' => $poId]);
    $msg = '';
    $m = $refusal(function () use (&$msg, $sv) { $msg = BrgySop::complete($sv, 'svp4', []); });
    $P = Brgy::mustProject($sv);
    $ok('Step 4 done: the purchase order recorded as the award (5,000.00), with the PO no. and date typed on it', $m === '' && $P['status'] === 'awarded' && abs((float)$P['contract_amount'] - 5000) < 0.01
        && $P['po_no'] === 'PO-T39-' . $stamp && $P['noa_date'] === '2026-11-26', $m . ' ' . $P['status'] . ' ' . $P['po_no'] . ' ' . $P['noa_date']);
    $iarBody = (string)DB::value("SELECT f.body FROM brgy_forms f JOIN brgy_project_papers pp ON pp.form_id = f.id WHERE pp.project_id = :p AND pp.paper_code = 'iar'", ['p' => $sv]);
    $risBody = (string)DB::value("SELECT f.body FROM brgy_forms f JOIN brgy_project_papers pp ON pp.form_id = f.id WHERE pp.project_id = :p AND pp.paper_code = 'ris'", ['p' => $sv]);
    $ok('The empty item tables of the forms in draft (IAR, RIS) are filled from the price schedule', str_contains($iarBody, 'T39 Monobloc chair') && str_contains($risBody, 'T39 Electric fan') && str_contains($msg, 'item tables'), $msg);
    $prAfter = (string)DB::value('SELECT body FROM brgy_forms WHERE id = :id', ['id' => $prId]);
    $ok('... the purchase request keeps the rows typed on it', str_contains($prAfter, 'T39 Monobloc chair') && str_contains($prAfter, '4,500.00'));
    $ok('No SI, OR or 2307 on step 4, nothing held for the payment', !BrgySop::holdsBilling($P) && !(int)DB::value("SELECT COUNT(*) FROM brgy_project_papers WHERE project_id = :p AND paper_code IN ('si', 'or', 'form_2307')", ['p' => $sv]));
    $tab = file_get_contents(APP_ROOT . '/modules/brgy/_process_tab.php');
    $ok('The Process tab: step 4 with the items, the quotation and "Take the items of the purchase request"; the payment in a box of its own', str_contains($tab, "case 'price_schedule':")
        && str_contains($tab, "'lines_from_pr'") && str_contains($tab, 'id="paymentBox"') && str_contains($tab, "if (!\$pricingStep)"));

    echo "\n3. Fields that may stay blank\n";
    $optOf = fn(string $c) => BrgyDocs::optionalKeys(DB::row('SELECT optional_fields FROM brgy_templates WHERE code = :c', ['c' => $c]));
    $ok('Purchase request: P.R. no. and date', $optOf('purchase_request') === ['pr.no', 'pr.date']);
    $ok('Disbursement voucher: DV no., date and fund', $optOf('disbursement_voucher') === ['dv.no', 'dv.date', 'dv.fund']);
    $ok('Inspection and acceptance report: IAR no. and date, RIS no. and date, invoice no. and date', $optOf('inspection_acceptance_report') === ['iar.no', 'iar.date', 'ris.no', 'ris.date', 'iar.invoice_no', 'iar.invoice_date']);
    $ok('Requisition and issue slip: RIS no. and date', $optOf('requisition_issue_slip') === ['ris.no', 'ris.date']);
    $prRow = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $prId]);
    $gp = BrgyDocs::gaps($prRow['body'], BrgyDocs::formOptional($prRow));
    $ok('The purchase request made with no P.R. no. and date: marked "may stay blank", not blanks for the review', str_contains($prRow['body'], 'class="ph-missing ph-opt" data-ph="pr.no"') && !in_array('Purchase request no.', $gp, true) && !in_array('Purchase request date', $gp, true), json_encode($gp));
    $old = '<p>P.R. No. <span class="ph-missing" data-ph="pr.no">[Purchase request no.]</span> <span class="ph-missing" data-ph="project.title">[Project title]</span></p>';
    $ok('A form made before: the field is still left out of the blanks by its template; another blank still counts', BrgyDocs::gaps($old, ['pr.no']) === ['Project title'] && count(BrgyDocs::gaps($old)) === 2);
    foreach (['dv' => ['DV no.', 'DV date', 'Fund (DV)'], 'iar' => ['IAR no.', 'IAR date', 'Invoice no. (IAR)', 'Invoice date (IAR)'], 'ris' => ['RIS no.', 'RIS date']] as $code => $labels) {
        $fr = DB::row("SELECT f.* FROM brgy_forms f JOIN brgy_project_papers pp ON pp.form_id = f.id WHERE pp.project_id = :p AND pp.paper_code = :c", ['p' => $sv, 'c' => $code]);
        BrgyOps::refreshForm((int)$fr['id']);
        $fr = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fr['id']]);
        $gx = BrgyDocs::gaps($fr['body'], BrgyDocs::formOptional($fr));
        $ok('The ' . strtoupper($code) . ' with ' . implode(', ', $labels) . ' blank: not blanks for the review', !array_intersect($labels, $gx), json_encode($gx));
    }
    $css = file_get_contents(APP_ROOT . '/assets/css/app.css');
    $ok('They print empty (no "[label]" on the paper), also in the Word copy', str_contains($css, '.formdoc .ph-missing.ph-opt, .formdoc.is-draft .ph-missing.ph-opt { background: none !important; color: transparent !important;')
        && str_contains(file_get_contents(APP_ROOT . '/app/BrgyDocs.php'), "'/<span class=\"ph-missing ph-opt\"[^>]*>.*?<\\/span>/'"));
    $tplPage = file_get_contents(APP_ROOT . '/modules/brgy/templates.php');
    $ok('Proforma & Form Templates: "Fields that may stay blank" on each template', str_contains($tplPage, "input_field('optional_fields', 'Fields that may stay blank'"));

    echo "\n4. The rows of the purchase request\n";
    $tbl = BrgyLayout::itemsTable('pr.items', array_fill(0, 60, $typed[0]), $L);
    $ok('Sixty rows: all of them in the table, with the total of all (270,000.00)', substr_count($tbl, 'T39 Monobloc chair') === 60 && str_contains($tbl, 'PHP 270,000.00'));
    $ok('The table can run on to more pages: the headings repeat, no row is cut in two, the total once at the end', str_contains($css, '.formdoc table.fx-items thead { display: table-header-group; }')
        && str_contains($css, '.formdoc table.fx-items tr { break-inside: avoid;') && str_contains($css, '.formdoc table.fx-items tfoot { display: table-row-group; }'));
    $plain = (string)preg_replace('/ data-(cols|aligns|blank|nf|total|prefix)="[^"]*"/', '', BrgyLayout::itemsTable('pr.items', [], $L));
    $marked = BrgyDocs::markItemTables($plain, $L);
    $ok('A purchase request made before version 39 gets its columns marked when it is opened, so rows can be added', !str_contains($plain, 'data-cols') && str_contains($marked, 'data-cols="no,qty,unit,description,est_unit,est_amount"'));
    $js = file_get_contents(APP_ROOT . '/assets/js/brgy.js');
    $ok('The Items panel: add, insert, move and remove rows, amounts and total worked out, part of Undo and Redo', str_contains($js, "b.dataset.ig === 'add'") && str_contains($js, "b.dataset.ig === 'del'")
        && str_contains($js, "b.dataset.ig === 'up'") && str_contains($js, 'H.after.push(initGrids)') && str_contains($js, 'const writeRows'));

    echo "\n5. Files made smaller before they are kept\n";
    $ok('Under 2 MB: as it is; 2 MB up to 6 MB: under 1 MB; 6 MB and above: under 2 MB', Upload::target(1900000) === 0 && Upload::target(2 * 1048576) > 0 && Upload::target(2 * 1048576) < 1048576
        && Upload::target(5 * 1048576) < 1048576 && Upload::target(6 * 1048576) < 2 * 1048576 && Upload::target(6 * 1048576) > 1048576);
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(2600, 2000);
        for ($y = 0; $y < 2000; $y += 2) {
            for ($x = 0; $x < 2600; $x += 2) {
                imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, mt_rand(0, 0xFFFFFF));
            }
        }
        $jpg = sys_get_temp_dir() . '/t39_' . $stamp . '.jpg';
        imagejpeg($im, $jpg, 97);
        imagedestroy($im);
        clearstatcache();
        $before = filesize($jpg);
        $after = Upload::shrinkImage($jpg, 'photo.jpg');
        $info = @getimagesize($jpg);
        $ok('A ' . round($before / 1048576, 1) . ' MB photo kept by the server is made smaller (' . round($after / 1024) . ' KB), still a JPG', $before >= 2 * 1048576 && $after > 0 && $after < Upload::target($before) + 1 && ($info[2] ?? 0) === IMAGETYPE_JPEG,
            $before . ' to ' . $after);
        @unlink($jpg);
        $im = imagecreatetruecolor(1400, 1400);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        for ($y = 0; $y < 1400; $y++) {
            for ($x = 0; $x < 1400; $x++) {
                imagesetpixel($im, $x, $y, (mt_rand(0, 100) << 24) | mt_rand(0, 0xFFFFFF));
            }
        }
        $png = sys_get_temp_dir() . '/t39_' . $stamp . '.png';
        imagepng($im, $png, 6);
        imagedestroy($im);
        clearstatcache();
        $before = filesize($png);
        $after = $before >= 2 * 1048576 ? Upload::shrinkImage($png, 'seal.png') : 0;
        $info = @getimagesize($png);
        $ok('A PNG with see-through parts stays a PNG when made smaller (' . round($before / 1048576, 1) . ' MB to ' . round($after / 1024) . ' KB)', $before >= 2 * 1048576 && $after > 0 && $after < $before && ($info[2] ?? 0) === IMAGETYPE_PNG, $before . ' to ' . $after);
        @unlink($png);
    } else {
        $ok('GD is not installed here: the server pass is skipped (the browser still makes the files smaller)', true);
    }
    $up = file_get_contents(APP_ROOT . '/assets/js/upload.js');
    $lay = file_get_contents(APP_ROOT . '/views/layout.php');
    $ok('In the browser: pictures and PDFs (each page drawn again with pdf.js) made smaller before they are sent, on every page', str_contains($up, 'async function shrinkPdf') && str_contains($up, 'async function shrinkImage')
        && str_contains($lay, "asset('js/upload.js')") && str_contains($lay, 'data-upload-max=') && is_file(APP_ROOT . '/assets/vendor/pdfjs/pdf.min.js') && is_file(APP_ROOT . '/assets/vendor/pdfjs/pdf.worker.min.js'));
    $ok('The backup and the CSV import are sent as they are', str_contains(file_get_contents(APP_ROOT . '/modules/admin/backup.php'), 'data-no-shrink') && str_contains(file_get_contents(APP_ROOT . '/modules/admin/import.php'), 'data-no-shrink'));
    $ok('The server makes a picture smaller before the vault and the attachments (in the database) keep it', str_contains(file_get_contents(APP_ROOT . '/app/BrgyDocs.php'), 'Upload::shrinkImage(')
        && str_contains(file_get_contents(APP_ROOT . '/app/Attachments.php'), 'Upload::shrinkImage($path, $name)'));

    echo "\n6. The barangay's officers on the customer\n";
    $cust = DB::insert('customers', ['name' => 'Barangay T39 Officers ' . $stamp, 'company_name' => 'Barangay T39 Officers ' . $stamp, 'is_active' => 1]);
    $ch = BrgyOps::saveCustomerOfficers($cust, ['punong_barangay' => 'Hon. Ana T39', 'bac_chair' => 'Hon. Bem T39', 'bac_member' => ['Hon. Carl T39', 'Hon. Dina T39', '']]);
    $e = Brgy::entityOfCustomer($cust);
    $ok('A customer that was not a procuring entity becomes one, with its officers', $e !== null && (string)(Brgy::official((int)$e['id'], 'punong_barangay')['name'] ?? '') === 'Hon. Ana T39'
        && count(Brgy::bacMembers((int)$e['id'])) === 2 && count($ch) === 4, json_encode($ch));
    $eid = (int)$e['id'];
    BrgyOps::saveCustomerOfficers($cust, ['punong_barangay' => 'Hon. Ana T39', 'bac_chair' => 'Hon. Ben T39', 'bac_member' => ['Hon. Carl T39', 'Hon. Dina T39']]);
    $ok('A name corrected on the day it was entered is corrected in place (no history line)', (int)DB::value("SELECT COUNT(*) FROM brgy_contacts WHERE entity_id = :e AND role_code = 'bac_chair'", ['e' => $eid]) === 1
        && Brgy::official($eid, 'bac_chair')['name'] === 'Hon. Ben T39');
    DB::run('UPDATE brgy_contacts SET valid_from = :d WHERE entity_id = :e', ['d' => '2026-01-05', 'e' => $eid]);
    $chg = BrgyOps::saveCustomerOfficers($cust, ['punong_barangay' => 'Hon. Ana T39', 'bac_chair' => 'Hon. Ed T39', 'bac_member' => ['Hon. Carl T39', 'Hon. Fe T39']]);
    $yest = date('Y-m-d', strtotime(today() . ' -1 day'));
    $ok('A change starts today: the one before keeps the days they were in office', Brgy::official($eid, 'bac_chair')['name'] === 'Hon. Ed T39' && Brgy::official($eid, 'bac_chair', $yest)['name'] === 'Hon. Ben T39'
        && in_array('Hon. Dina T39', array_column(Brgy::bacMembers($eid, $yest), 'name'), true) && !in_array('Hon. Dina T39', array_column(Brgy::bacMembers($eid), 'name'), true)
        && in_array('Hon. Fe T39', array_column(Brgy::bacMembers($eid), 'name'), true) && !in_array('Hon. Fe T39', array_column(Brgy::bacMembers($eid, $yest), 'name'), true), json_encode($chg));
    $open = BrgyOps::saveProject(['title' => 'T39 ongoing contract ' . $stamp, 'entity_id' => $eid, 'category' => 'goods']);
    $done = BrgyOps::saveProject(['title' => 'T39 completed contract ' . $stamp, 'entity_id' => $eid, 'category' => 'goods']);
    DB::update('brgy_projects', ['status' => 'closed', 'closeout_date' => $yest], 'id = :id', ['id' => $done]);
    $cOpen = BrgyDocs::context(Brgy::mustProject($open), null, 'T', BrgyLayout::layout(null));
    $cDone = BrgyDocs::context(Brgy::mustProject($done), null, 'T', BrgyLayout::layout(null));
    $ok('Current and future forms take the new officers; a completed contract keeps the ones it had', $cOpen['entity.bac_chair'] === 'Hon. Ed T39' && $cDone['entity.bac_chair'] === 'Hon. Ben T39'
        && $cOpen['entity.bac_member_2'] === 'Hon. Fe T39' && $cDone['entity.bac_member_2'] === 'Hon. Dina T39' && $cOpen['entity.head_name'] === 'Hon. Ana T39', $cOpen['entity.bac_chair'] . ' / ' . $cDone['entity.bac_chair']);
    $draftBody = '<div class="sg-name">HON. BEN T39</div><div class="sg-cap">BAC Chairman</div><div class="sg-name">HON. CARL T39</div><div class="sg-name">HON. DINA T39</div><div class="sg-cap">BAC Member</div>';
    $oc = BrgyDocs::officerChanges(Brgy::mustProject($open), $draftBody);
    $ok('A draft of an ongoing contract made before the change: its old officers are offered their new names', ($oc['pairs']['Hon. Ben T39'][0] ?? '') === 'Hon. Ed T39' && ($oc['pairs']['Hon. Dina T39'][0] ?? '') === 'Hon. Fe T39', json_encode($oc));
    $ok('... a draft of a completed contract is left as it is', BrgyDocs::officerChanges(Brgy::mustProject($done), $draftBody)['pairs'] === []);
    $cc = file_get_contents(APP_ROOT . '/modules/customers/center.php');
    $ok('The customer form: Barangay Chairman, BAC Chairman and as many BAC members as the barangay has', str_contains($cc, 'name="off[punong_barangay]"') && str_contains($cc, 'name="off[bac_chair]"') && str_contains($cc, 'name="off[bac_member][]"') && str_contains($cc, 'data-off-add'));

    echo "\n7. The Barangay Project Monitoring Board\n";
    $ok('The page and its menu entry, given to the roles that open Projects & Contracts', isset(Access::allPageDefs()['brgy.board']) && (int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'brgy.board'") === 1
        && (int)DB::value("SELECT COUNT(*) FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key = 'brgy.board' AND a.allowed = 1")
           >= (int)DB::value("SELECT COUNT(*) FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key = 'brgy.projects' AND a.allowed = 1"));
    $bd = file_get_contents(APP_ROOT . '/modules/brgy/board.php');
    $cols = ['Check date', 'Trans. code', 'Barangay', 'Particulars', 'Gross amount', 'Cheque amount', 'Supplier', 'Tax %', 'Sales / service inv.', 'C.R.', 'D.R.', 'Check no.', 'Process completed', 'Status'];
    $ok('The columns of the monitoring sheet', !array_filter($cols, fn($c) => !str_contains($bd, '>' . $c . '</th>')));
    $ok('The filters: process (small value, bidding, twelve phases), barangay, amount, supplier, cheque amount, C.R., D.R., invoice, status', str_contains($bd, "'svp' => 'Small value procurement', 'bidding' => 'Bidding', 'general' => 'Twelve phases'")
        && !array_filter(['entity_id', 'gross_min', 'gross_max', 'tradename', 'cheque_min', 'cheque_max', "name=\"cr\"", "name=\"dr\"", "name=\"si\"", "'status'"], fn($k) => !str_contains($bd, $k)));

    echo "\n8. A transaction with the PhilGEPS reference, source link, source of funds and ABC blank\n";
    foreach (['svp' => 'small value', 'bidding' => 'bidding', 'general' => 'twelve phases'] as $proc => $lbl) {
        $m = $refusal(fn() => BrgyOps::saveProject(['process' => $proc, 'title' => 'T39 blank ' . $proc . ' ' . $stamp, 'entity_id' => $entity, 'philgeps_ref' => '', 'source_url' => '', 'funding_source' => '', 'abc_amount' => '']));
        $row = DB::row('SELECT * FROM brgy_projects WHERE title = :t', ['t' => 'T39 blank ' . $proc . ' ' . $stamp]);
        $ok('Recorded (' . $lbl . '): the four left empty', $m === '' && $row && $row['philgeps_ref'] === null && $row['source_url'] === null && $row['funding_source'] === null && (float)$row['abc_amount'] === 0.0, $m);
    }
    $ok('The transaction form shows an empty ABC as empty, not 0.00', str_contains($form, "'abc_amount', 'Approved budget (ABC)', \$abcShown"));

    echo "\n9. The upgrade\n";
    $ok('The migration is known and has been run', in_array('database/migrations/2026_11_26_roles_price_schedule_officers_board.sql', Schema::files(), true) && !Schema::pending());
} finally {
    DB::rollBack();
    foreach ($keep as $k => $v) {
        Settings::set($k, $v);
    }
    foreach (glob(BrgyDocs::dir() . '/*') ?: [] as $f) {
        if (!isset($filesBefore[$f])) {
            @unlink($f);
        }
    }
}
echo "\n" . $pass . ' passed, ' . count($failures) . " failed (everything rolled back)\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit($failures ? 1 : 0);
