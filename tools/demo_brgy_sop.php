<?php
declare(strict_types=1);

/**
 * Barangay Contracts: sample transactions that follow the company's SOP (called at the end of load_brgy_demo_data).
 * Made through the same operations the pages use, by the users whose job each step is; dates are relative to the day
 * the installer runs. Names of officials and suppliers are made up.
 *
 *   BCP-00009  small value procurement  Pajo     cleaning materials: transaction form, PR, PO, IAR, RIS, canvass of three
 *                                                suppliers, abstract, DV, cheque released, SI, OR, 2307, review, monitoring,
 *                                                accounting, transmittal released and returned signed: closed
 *   BCP-00010  small value procurement  Pajo     office supplies: awarded from the canvass, delivered and accepted, waiting for
 *                                                the cheque (SI, OR and 2307 held), one follow-up logged
 *   BCP-00011  bidding                  Lahug    tanod equipment: steps 1 to 6 done (papers prepared, SPA signed and notarized),
 *                                                at step 7 with the tax clearance not valid (Cebu City: ABC and Aboitiz papers)
 */

/** A small picture of a seal (made up: a ring with the name), saved for an entity */
function brgy_demo_seal(string $file, string $top, string $bottom, array $rgb): string
{
    $dir = BrgyDocs::dir();
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }
    $s = 240;
    $im = imagecreatetruecolor($s, $s);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
    $c = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    $light = imagecolorallocate($im, 255, 255, 255);
    imagefilledellipse($im, 120, 120, 232, 232, $c);
    imagefilledellipse($im, 120, 120, 200, 200, $light);
    imagefilledellipse($im, 120, 120, 120, 120, $c);
    imagefilledellipse($im, 120, 120, 104, 104, $light);
    // a star in the middle
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $r = $i % 2 ? 18 : 44;
        $a = -M_PI / 2 + $i * M_PI / 5;
        $pts[] = (int)(120 + $r * cos($a));
        $pts[] = (int)(120 + $r * sin($a));
    }
    imagefilledpolygon($im, $pts, $c);
    // the name around the ring (letter by letter)
    $draw = function (string $text, bool $upper) use ($im, $light) {
        $n = strlen($text);
        $span = min(M_PI * 0.95, $n * 0.16);
        for ($i = 0; $i < $n; $i++) {
            $a = ($upper ? -M_PI / 2 : M_PI / 2) + ($upper ? 1 : -1) * (-$span / 2 + $span * ($n > 1 ? $i / ($n - 1) : 0.5));
            imagestring($im, 5, (int)(120 + 103 * cos($a)) - 4, (int)(120 + 103 * sin($a)) - 7, $text[$i], $light);
        }
    };
    $draw(strtoupper($top), true);
    $draw(strtoupper($bottom), false);
    $name = 'd' . date('YmdHis') . '_' . $file . '.png';
    imagepng($im, $dir . '/' . $name);
    imagedestroy($im);
    return $name;
}

function brgy_demo_sop(): void
{
    $d = fn(int $daysAgo) => date('Y-m-d', strtotime("-$daysAgo days"));
    $dt = fn(int $daysAgo, string $time = '10:00') => date('Y-m-d', strtotime("-$daysAgo days")) . ' ' . $time . ':00';
    $ahead = fn(int $days) => date('Y-m-d', strtotime("+$days days"));
    $u = fn(string $name) => (int)DB::value('SELECT id FROM users WHERE username = :u', ['u' => $name]);
    $as = fn(string $name) => Auth::actAs($u($name));
    $pdf = fn(string $file, string ...$lines) => brgy_demo_pdf($file, $lines);
    $vat = (int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'");
    $svpMode = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'small_value'");
    $cbid = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'competitive_bidding'");
    Settings::set('brgy_tradename', (string)Settings::get('company_name'));

    // everything made since the last mark is dated to the day it stands for
    $mark = [];
    $tables = ['brgy_events' => 'event_at', 'brgy_documents' => 'uploaded_at', 'brgy_workflow' => 'created_at', 'brgy_forms' => 'created_at', 'audit_trail' => null];
    $setMark = function () use (&$mark, $tables) {
        foreach (array_keys($tables) as $t) {
            $mark[$t] = (int)DB::value("SELECT COALESCE(MAX(id), 0) FROM $t");
        }
    };
    $on = function (string $when) use (&$mark, $tables, $setMark) {
        $t = strtotime($when);
        foreach ($tables as $tb => $col) {
            if (!$col) {
                continue;
            }
            $i = 0;
            foreach (DB::column("SELECT id FROM $tb WHERE id > :m ORDER BY id", ['m' => $mark[$tb]]) as $rid) {
                DB::run("UPDATE $tb SET $col = :w WHERE id = :id", ['w' => date('Y-m-d H:i:s', $t + ($i++) * 300), 'id' => $rid]);
            }
        }
        DB::run('UPDATE brgy_project_papers SET signed_at = :w WHERE signed_at >= :x', ['w' => date('Y-m-d H:i:s', $t), 'x' => date('Y-m-d 00:00:00')]);
        $setMark();
    };
    $stepAt = fn(int $pid, string $code, string $when) => DB::run('UPDATE brgy_project_steps SET done_at = :w WHERE project_id = :p AND step_code = :c', ['w' => $when, 'p' => $pid, 'c' => $code]);
    $paper = fn(int $pid, string $code) => (int)DB::value('SELECT id FROM brgy_project_papers WHERE project_id = :p AND paper_code = :c ORDER BY id LIMIT 1', ['p' => $pid, 'c' => $code]);
    $signed = function (int $pid, string $code, string $what) use ($paper, $pdf) {
        $pp = DB::row('SELECT * FROM brgy_project_papers WHERE id = :id', ['id' => $paper($pid, $code)]);
        BrgySop::attach((int)$pp['id'], $pdf('signed_' . $code . '_' . $pid . '.pdf', $pp['name'] . ' (signed)', $what, 'Project ' . DB::value('SELECT project_no FROM brgy_projects WHERE id = :p', ['p' => $pid])));
    };
    $setMark();

    // ---------------------------------------------------------------- a barangay of Lapu-Lapu City with its officials and seals
    $as('bidofficer');
    $pajo = BrgyOps::saveEntity(['official_name' => 'Barangay Pajo', 'entity_type' => 'barangay', 'region' => 'Region VII', 'province' => 'Cebu', 'city_municipality' => 'Lapu-Lapu City',
        'barangay' => 'Pajo', 'address' => 'Barangay Hall, M.L. Quezon National Highway, Pajo, Lapu-Lapu City', 'zip_code' => '6015', 'tin' => '000-612-701-000', 'phone' => '(032) 340-7711',
        'email' => 'treasury.pajo@lapulapu.test', 'submission_methods' => ['physical'], 'submission_notes' => 'Papers to the Barangay Treasurer, 8:00 to 5:00. Cheques released at the treasury every Friday.',
        'funding_notes' => 'General Fund; cheques drawn on Landbank, Lapu-Lapu branch.']);
    foreach ([
        ['punong_barangay', 'Hon. Ernesto B. Lagunday', 'Punong Barangay', '2023-11-30'],
        ['appropriations_chair', 'Hon. Danilo R. Cabahug', 'Chairman, Committee on Appropriations', '2023-11-30'],
        ['bac_chair', 'Hon. Danilo R. Cabahug', 'BAC Chairman', '2024-01-08'],
        ['bac_member', 'Hon. Liezl M. Tumulak', 'BAC Member', '2024-01-08'],
        ['bac_member', 'Hon. Ramil P. Ouano', 'BAC Member', '2024-01-08'],
        ['bac_member', 'Hon. Gemma S. Patalinghug', 'BAC Member', '2024-01-08'],
        ['bac_member', 'Hon. Arturo C. Baring', 'BAC Member', '2024-01-08'],
        ['bac_member', 'Hon. Rosalie T. Inoc', 'BAC Member', '2024-01-08'],
        ['treasurer', 'Marites D. Alcoseba', 'Barangay Treasurer', '2023-12-01'],
        ['secretary', 'Joel A. Cuizon', 'Barangay Secretary', '2023-12-01'],
        ['bookkeeper', 'Analyn F. Sitoy', 'Barangay Bookkeeper', '2024-01-02'],
        ['city_accountant', 'Corazon L. Igot', 'City Accountant', '2024-01-02'],
        ['end_user', 'Jennelyn P. Ompad', 'Requisitioner', '2024-01-08'],
        ['inspection', 'Engr. Victor S. Ybanez', 'Inspector', '2024-01-08'],
    ] as [$role, $name, $pos, $from]) {
        BrgyOps::saveContact($pajo, ['role_code' => $role, 'name' => $name, 'position' => $pos, 'valid_from' => $from, 'is_signatory' => in_array($role, ['punong_barangay', 'treasurer'], true) ? 1 : 0]);
    }
    DB::update('brgy_entities', ['seal_file' => brgy_demo_seal('seal_pajo', 'Barangay Pajo', 'Lapu-Lapu', [21, 94, 52]) ?: null,
        'lgu_seal_file' => brgy_demo_seal('seal_lapulapu', 'City of Lapu-Lapu', 'Official seal', [11, 74, 160]) ?: null], 'id = :id', ['id' => $pajo]);
    $lahug = (int)DB::value("SELECT id FROM brgy_entities WHERE official_name = 'Barangay Lahug'");
    DB::update('brgy_entities', ['extra_bid_papers' => 1, 'seal_file' => brgy_demo_seal('seal_lahug', 'Barangay Lahug', 'Cebu City', [150, 60, 20]) ?: null,
        'lgu_seal_file' => brgy_demo_seal('seal_cebu', 'City of Cebu', 'Official seal', [11, 74, 160]) ?: null], 'id = :id', ['id' => $lahug]);
    BrgyOps::saveContact($lahug, ['role_code' => 'appropriations_chair', 'name' => 'Kgd. Teresa O. Ramos', 'position' => 'Chairman, Committee on Appropriations', 'valid_from' => '2024-01-08']);
    // the members of the BAC of Lahug (the signatures of its resolution)
    foreach (['Kgd. Jose P. Abellana', 'Kgd. Melinda C. Osmena', 'Kgd. Victor R. Gaite', 'Kgd. Nenita B. Cabrera', 'Kgd. Romeo A. Lim'] as $m) {
        BrgyOps::saveContact($lahug, ['role_code' => 'bac_member', 'name' => $m, 'position' => 'BAC Member', 'valid_from' => '2024-01-08']);
    }
    $on($dt(40, '08:30'));

    $people = ['preparer_id' => $u('bidofficer'), 'checker_id' => $u('accountant'), 'approver_id' => $u('manager'), 'submitter_id' => $u('bidofficer'), 'pm_id' => $u('pmgr'), 'collector_id' => $u('collector')];

    // ---------------------------------------------------------------- BCP-00009: small value, the whole SOP (the client's sample transaction)
    $p9 = BrgyOps::saveProject($people + ['process' => 'svp', 'title' => 'Payment for the Purchase of Cleaning Materials', 'entity_id' => $pajo, 'category' => 'goods', 'mode_id' => $svpMode,
        'reference_no' => 'PR-2026-0318', 'pr_date' => $d(35), 'posting_date' => $d(35), 'abc_amount' => 100000, 'end_user' => 'Barangay Pajo', 'funding_source' => 'General Fund 2026',
        'delivery_site' => 'Barangay Pajo, Lapu-Lapu City', 'delivery_days' => 15, 'warranty_months' => 3, 'payment_terms' => 'Upon delivery and acceptance',
        'txn_date' => $d(35), 'txn_type' => 'purchases', 'requestor' => 'Jennelyn P. Ompad', 'requestor_position' => 'Requisitioner', 'inspector' => 'Engr. Victor S. Ybanez',
        'inspector_position' => 'Inspector', 'purpose' => 'For Barangay Office Use', 'sales_rep_id' => $u('salesrep'), 'fund' => 'General Fund', 'po_no' => 'PO-2026-0412', 'po_date' => $d(33),
        'ris_no' => 'RIS-2026-0177', 'ris_date' => $d(26), 'iar_no' => 'IAR-2026-0201', 'iar_date' => $d(27), 'dv_no' => '101-2026-10-0533', 'dv_date' => $d(20), 'delivery_date' => $d(28),
        'delivery_term' => 'Within 15 calendar days', 'site_id' => null]);
    $cleaning = [
        ['Empty Sack', 'Pcs', 300, 30, 30], ['Soft broom', 'Pcs', 10, 220, 235], ['Broomsticks', 'Pcs', 10, 120, 110], ['Hand Gloves (Reusable & Disposable)', 'box', 40, 300, 300],
        ['Alcohol', 'gall', 40, 450, 450], ['Liquid Handwash', 'gall', 30, 480, 480], ['Floor Cleaner', 'gall', 40, 320, 320], ['Toilet Brush', 'Pcs', 20, 180, 180],
        ['Trash Bag (various sizes)', 'roll', 50, 250, 250], ['Dust pan', 'Pcs', 10, 120, 120], ['Round Rags', 'Pcs', 300, 3, 3], ['Muriatic Acid', 'liter', 30, 157, 157],
        ['Mop Heads & Handles', 'Pcs', 10, 250, 250], ['Tissue', 'Pack', 20, 247, 247],
    ];
    BrgyOps::saveLines($p9, array_map(fn($l) => ['line_kind' => 'goods', 'description' => $l[0], 'unit' => $l[1], 'qty' => $l[2], 'unit_cost' => round($l[3] * 0.82, 2), 'unit_price' => $l[3],
        'est_unit_price' => $l[4], 'tax_code_id' => $vat], $cleaning));
    $on($dt(35, '09:00'));
    // 1. the transaction details are complete
    $fid = BrgySop::prepare($paper($p9, 'txn_form'));
    BrgySop::complete($p9, 'svp1', ['Project title', 'Approved budget', 'Requestor', 'Inspector', 'Complete item specifications / particulars'], 'Details from the sales representative, complete');
    $on($dt(35, '10:00'));
    $stepAt($p9, 'svp1', $dt(35, '10:00'));
    // 2. the barangay's papers, prepared by the company
    BrgySop::prepareStep($p9, 'svp2');
    BrgySop::complete($p9, 'svp2', []);
    $on($dt(34, '15:00'));
    $stepAt($p9, 'svp2', $dt(34, '15:00'));
    // 3. canvass: three suppliers, the company the lowest that passed
    foreach ([
        [1, '', 99950, 'passed', 'company quotation'],
        [0, 'Northpoint Consumer Goods Trading', 100150, 'failed', 'Mandaue City'],
        [0, 'Silverline General Merchandise', 100590, 'failed', 'Cebu City'],
    ] as [$co, $name, $amt, $rem, $where]) {
        BrgySop::saveCanvass($p9, ['is_company' => $co, 'supplier_name' => $name, 'total_amount' => $amt, 'remarks' => $rem, 'validity' => '120 calendar days', 'delivery_period' => '15 days',
            'address' => $where, 'received_date' => $d(33)], $pdf('canvass_' . ($co ? 'company' : substr(md5($name), 0, 6)) . '.pdf', 'Request for quotation (signed)', $co ? 'Sample Trading Company' : $name,
            'Total quoted: PHP ' . number_format($amt, 2), 'Project: Payment for the Purchase of Cleaning Materials'));
    }
    BrgySop::complete($p9, 'svp3', ['Three suppliers completed and signed the canvass forms']);
    $on($dt(33, '16:00'));
    $stepAt($p9, 'svp3', $dt(33, '16:00'));
    // 4. (v39) the price schedule agrees with the company's quotation: the purchase order is recorded as the award
    BrgySop::complete($p9, 'svp4', [], 'Price schedule checked against the canvass');
    $on($dt(33, '17:00'));
    $stepAt($p9, 'svp4', $dt(33, '17:00'));
    // the barangay signs its papers; the goods are delivered and accepted on the signed IAR
    foreach (['txn_form' => 'Prepared / received by the sales representative', 'pr' => 'Signed by the requisitioner and the Punong Barangay', 'po' => 'Signed by the Punong Barangay, conforme of the supplier',
                 'iar' => 'Inspected and accepted, complete', 'ris' => 'Requested, approved, received and issued', 'abstract' => 'Signed by the BAC, approved by the Punong Barangay'] as $code => $what) {
        $signed($p9, $code, $what);
    }
    $on($dt(27, '15:00'));
    $as('pmgr');
    BrgySop::deliverAll($p9, ['delivery_date' => $d(28), 'acceptance_date' => $d(27)]);
    $on($dt(27, '16:00'));
    $as('bidofficer');
    $signed($p9, 'dv', 'Boxes A, B and C signed; for payment');
    $on($dt(20, '10:00'));
    // the cheque is released: the billing papers may now be prepared
    BrgySop::releasePayment($p9, ['cheque_no' => '0000981889', 'cheque_date' => $d(13), 'cheque_bank' => 'Landbank', 'cheque_amount' => 94595.54]);
    $on($dt(13, '14:00'));
    $as('billing');
    $pl = Brgy::lines($p9);
    $b9 = BrgyOps::saveBilling(['project_id' => $p9, 'billing_date' => $d(13), 'billing_type' => 'final', 'si_no' => '0004512', 'lines' => array_combine(array_column($pl, 'id'), array_column($pl, 'qty'))]);
    BrgyOps::billingStep($b9, 'submit');
    $as('accountant');
    BrgyOps::billingStep($b9, 'review');
    $as('manager');
    BrgyOps::billingStep($b9, 'approve');
    $as('billing');
    BrgyOps::invoiceBilling($b9);
    $on($dt(13, '15:00'));
    $as('collector');
    $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b9]);
    $exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
    BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b9, 'collection_date' => $d(12), 'method' => 'check', 'check_no' => '0000981889', 'check_date' => $d(13), 'bank_name' => 'Landbank',
        'cash_received' => r2((float)$b['gross_amount'] - $exp['ewt'] - $exp['vat']), 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'receipt_no' => 'OR-0951']);
    $on($dt(12, '10:00'));
    $as('manager');
    BrgySop::complete($p9, 'svp5', ['Accuracy', 'Completeness', 'Proper signatures', 'Correct computations'], 'Computations checked against the DV and the 2307');
    $on($dt(11, '09:30'));
    $stepAt($p9, 'svp5', $dt(11, '09:30'));
    $as('bidofficer');
    BrgySop::complete($p9, 'svp6', ['Copies of all essential documents retained', 'Transaction details encoded in the Monitoring System']);
    $stepAt($p9, 'svp6', $dt(11, '11:00'));
    BrgySop::saveStepData($p9, 'svp7', ['forwarded_to' => 'Accounting Department', 'forwarded_date' => $d(10)]);
    BrgySop::complete($p9, 'svp7', []);
    $stepAt($p9, 'svp7', $dt(10, '09:00'));
    $tr = BrgySop::prepare($paper($p9, 'transmittal'));
    BrgySop::saveStepData($p9, 'svp8', ['transmittal_no' => (string)DB::value('SELECT form_no FROM brgy_forms WHERE id = :f', ['f' => $tr])]);
    BrgySop::complete($p9, 'svp8', []);
    $stepAt($p9, 'svp8', $dt(10, '10:00'));
    $on($dt(10, '10:00'));
    BrgySop::saveStepData($p9, 'svp9', ['release_method' => 'sales_rep', 'released_to' => 'Marites D. Alcoseba, Barangay Treasurer', 'released_date' => $d(8)]);
    $signed($p9, 'transmittal', 'Received by the Barangay Treasurer');
    $on($dt(6, '15:00'));
    $as('manager');
    BrgySop::complete($p9, 'svp9', [], 'Signed transmittal returned by the sales representative');
    $stepAt($p9, 'svp9', $dt(6, '16:00'));
    $on($dt(6, '16:00'));
    DB::update('brgy_projects', ['closeout_date' => $d(6), 'completion_date' => $d(27)], 'id = :id', ['id' => $p9]);

    // ---------------------------------------------------------------- BCP-00010: small value, delivered, waiting for the cheque
    $as('bidofficer');
    $p10 = BrgyOps::saveProject($people + ['process' => 'svp', 'title' => 'Purchase of Office Supplies for the Barangay Hall', 'entity_id' => $pajo, 'category' => 'goods', 'mode_id' => $svpMode,
        'reference_no' => 'PR-2026-0344', 'pr_date' => $d(22), 'posting_date' => $d(22), 'abc_amount' => 30000, 'end_user' => 'Barangay Pajo', 'funding_source' => 'General Fund 2026',
        'delivery_site' => 'Barangay Pajo, Lapu-Lapu City', 'delivery_days' => 10, 'txn_date' => $d(22), 'txn_type' => 'purchases', 'requestor' => 'Jennelyn P. Ompad', 'requestor_position' => 'Requisitioner',
        'inspector' => 'Engr. Victor S. Ybanez', 'inspector_position' => 'Inspector', 'purpose' => 'For Barangay Office Use', 'sales_rep_id' => $u('salesrep'), 'fund' => 'General Fund',
        'po_no' => 'PO-2026-0447', 'po_date' => $d(20), 'ris_no' => 'RIS-2026-0190', 'ris_date' => $d(15), 'iar_no' => 'IAR-2026-0219', 'iar_date' => $d(16), 'delivery_date' => $d(17),
        'delivery_term' => 'Within 10 calendar days',
        // v34: the estimates and targets of the transaction form (no accounting entry)
        'est_gross' => 29750, 'est_wt_rate' => 1, 'est_profit_rate' => 13, 'est_receipt_rate' => 3, 'est_sop' => 1000]);
    BrgyOps::saveLines($p10, array_map(fn($l) => ['line_kind' => 'goods', 'description' => $l[0], 'unit' => $l[1], 'qty' => $l[2], 'unit_cost' => round($l[3] * 0.8, 2), 'unit_price' => $l[3], 'tax_code_id' => $vat], [
        ['Bond paper, A4, 70gsm', 'ream', 40, 245], ['Bond paper, long, 70gsm', 'ream', 20, 275], ['Ballpen, black', 'box', 10, 190], ['Folder, long, kraft', 'pack', 15, 210],
        ['Stapler with staples', 'set', 6, 360], ['Correction tape', 'pc', 24, 45], ['Ink, printer, black', 'btl', 12, 380], ['Record book, 300 pages', 'pc', 10, 160],
    ]));
    $on($dt(22, '09:00'));
    BrgySop::prepare($paper($p10, 'txn_form'));
    BrgySop::complete($p10, 'svp1', ['Project title', 'Approved budget', 'Requestor', 'Inspector', 'Complete item specifications / particulars']);
    $stepAt($p10, 'svp1', $dt(22, '10:00'));
    BrgySop::prepareStep($p10, 'svp2');
    BrgySop::complete($p10, 'svp2', []);
    $stepAt($p10, 'svp2', $dt(21, '15:00'));
    $on($dt(21, '15:00'));
    $tot10 = Brgy::lineTotals($p10)['bid'];
    foreach ([[1, '', $tot10, 'passed'], [0, 'Northpoint Consumer Goods Trading', 29500, 'failed'], [0, 'Bluewave Office Depot', 31200, 'passed']] as [$co, $name, $amt, $rem]) {
        BrgySop::saveCanvass($p10, ['is_company' => $co, 'supplier_name' => $name, 'total_amount' => $amt, 'remarks' => $rem, 'validity' => '120 calendar days', 'received_date' => $d(20)],
            $pdf('canvass10_' . ($co ? 'company' : substr(md5($name), 0, 6)) . '.pdf', 'Request for quotation (signed)', $co ? 'Sample Trading Company' : $name, 'Total quoted: PHP ' . number_format($amt, 2)));
    }
    BrgySop::complete($p10, 'svp3', ['Three suppliers completed and signed the canvass forms'], 'The lower quotation failed: two items missing');
    $stepAt($p10, 'svp3', $dt(20, '16:00'));
    $on($dt(20, '16:00'));
    BrgySop::complete($p10, 'svp4', []);   // v39: the price schedule, then the award
    $stepAt($p10, 'svp4', $dt(20, '17:00'));
    $on($dt(20, '17:00'));
    foreach (['txn_form' => 'Received by the sales representative', 'pr' => 'Signed', 'po' => 'Signed, conforme', 'iar' => 'Inspected and accepted', 'ris' => 'Signed', 'abstract' => 'Signed by the BAC'] as $code => $what) {
        $signed($p10, $code, $what);
    }
    $on($dt(16, '15:00'));
    $as('pmgr');
    BrgySop::deliverAll($p10, ['delivery_date' => $d(17), 'acceptance_date' => $d(16)]);
    $on($dt(16, '16:00'));
    $as('collector');
    BrgySop::followUp($p10, 'Treasurer: the DV is with the City Accountant; the cheque may be ready next Friday.', $ahead(2));
    $on($dt(5, '11:00'));

    // ---------------------------------------------------------------- BCP-00011: bidding at Lahug (Cebu City: ABC and Aboitiz papers), at step 7
    $as('bidofficer');
    $p11 = BrgyOps::saveProject($people + ['process' => 'bidding', 'title' => 'Supply and Delivery of Barangay Tanod Equipment', 'entity_id' => $lahug, 'category' => 'goods', 'mode_id' => $cbid,
        'reference_no' => 'ITB-LAH-2026-021', 'pr_date' => $d(9), 'posting_date' => $d(8), 'abc_amount' => 450000, 'end_user' => 'Barangay Peace and Order Council', 'funding_source' => 'General Fund 2026',
        'delivery_site' => 'Barangay Hall, Lahug, Cebu City', 'delivery_days' => 30, 'warranty_months' => 12, 'prebid_at' => $dt(3, '10:00'), 'bid_deadline' => date('Y-m-d', strtotime('+12 days')) . ' 10:00:00',
        'bid_opening_at' => date('Y-m-d', strtotime('+12 days')) . ' 10:30:00', 'specs' => "Tanod uniforms with reflective vest\nRechargeable LED flashlights\nHandheld two-way radios\nRaincoats with hood",
        'txn_date' => $d(9), 'txn_type' => 'purchases', 'requestor' => 'Kgd. Teresa O. Ramos', 'requestor_position' => 'Chair, Peace and Order', 'inspector' => 'Engr. Danilo F. Cabrera',
        'inspector_position' => 'Barangay Engineer', 'purpose' => 'For the Barangay Tanod', 'sales_rep_id' => $u('salesrep'), 'fund' => 'General Fund']);
    // the specifications of each item, one a line, as the technical specifications (Section VII) and the schedule of requirements (Section VI) print them
    BrgyOps::saveLines($p11, array_map(fn($l) => ['line_kind' => 'goods', 'description' => $l[0], 'unit' => $l[1], 'qty' => $l[2], 'unit_cost' => round($l[3] * 0.78, 2), 'unit_price' => $l[3], 'tax_code_id' => $vat,
        'specs' => $l[4]], [
        ['Tanod uniform set with reflective vest', 'set', 60, 2850, "Polo shirt: navy blue, poly-cotton, with patches\nTrousers: navy blue, with belt loops\nREFLECTIVE VEST\nColor: fluorescent yellow-green\nReflective tape: 5 cm, front and back\n\n**Sizes:** as listed by the barangay"],
        ['Rechargeable LED flashlight', 'pc', 60, 980, "Output: 1,000 lumens\nBattery: rechargeable lithium-ion, USB-C\nBody: aluminum, water resistant (IPX4)\n\n**Warranty:** one (1) year"],
        ['Handheld two-way radio', 'unit', 20, 6900, "Frequency: UHF 400-470 MHz\nOutput power: 5 W\nChannels: 16\nINCLUSIONS:\nBattery, charger, belt clip and antenna"],
        ['Raincoat with hood', 'pc', 60, 640, "Material: PVC-coated polyester\nColor: navy blue, printed BARANGAY TANOD at the back"],
    ]));
    $on($dt(9, '09:00'));
    BrgySop::prepare($paper($p11, 'bidding_form'));
    BrgySop::complete($p11, 'bid1', ['Bidding form received', 'Complete project details']);
    $stepAt($p11, 'bid1', $dt(9, '10:00'));
    BrgySop::complete($p11, 'bid2', ['Specifications', 'Approved schedule', 'Bid dates', 'Submission requirements']);
    $stepAt($p11, 'bid2', $dt(9, '14:00'));
    BrgySop::saveStepData($p11, 'bid3', ['philgeps_ref' => '12650817', 'posting_date' => $d(8)]);
    BrgySop::complete($p11, 'bid3', ['Posted on PhilGEPS']);
    $stepAt($p11, 'bid3', $dt(8, '09:00'));
    $on($dt(8, '09:00'));
    BrgySop::prepareStep($p11, 'bid4');
    BrgySop::attach($paper($p11, 'aboitiz'), $pdf('aboitiz_lahug.pdf', 'Aboitiz requirements', 'Submitted with the bid for the Cebu City office'));
    BrgySop::complete($p11, 'bid4', []);
    $stepAt($p11, 'bid4', $dt(7, '15:00'));
    $on($dt(7, '15:00'));
    BrgySop::prepare($paper($p11, 'spa'));
    $signed($p11, 'spa', 'Signed by the proprietor and notarized');
    BrgySop::complete($p11, 'bid5', ['Representative for the pre-bid conference', 'Representative for the actual bidding']);
    $stepAt($p11, 'bid5', $dt(6, '11:00'));
    $on($dt(6, '11:00'));
    BrgySop::prepareStep($p11, 'bid6');
    $signed($p11, 'bsd', 'Notarized');
    $signed($p11, 'oss', 'Notarized');
    BrgySop::complete($p11, 'bid6', ['Notarized']);
    $stepAt($p11, 'bid6', $dt(4, '16:00'));
    $on($dt(4, '16:00'));

    // the SOP steps and forms are dated; their projects and first notes too
    foreach ([[$p9, 35], [$p10, 22], [$p11, 9]] as [$pid, $ago]) {
        DB::run('UPDATE brgy_projects SET created_at = :w WHERE id = :p', ['w' => $dt($ago, '08:45'), 'p' => $pid]);
        DB::run("UPDATE brgy_events SET event_at = :w WHERE project_id = :p AND title = 'Opportunity recorded'", ['w' => $dt($ago, '08:45'), 'p' => $pid]);
    }
}
