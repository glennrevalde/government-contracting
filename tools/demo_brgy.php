<?php
declare(strict_types=1);

/**
 * Barangay Contracts: sample records for the demo database, made through the same operations the pages use
 * (app/BrgyOps.php), by the users whose job each step is, so the approvals, the ledger, the inventory and the audit
 * trail are what they would be after real work. Dates are relative to the day the installer runs.
 *
 *   BCP-00001  goods, small value       San Isidro   delivered in two lots (one monitor rejected and replaced), billed twice, first billing collected
 *   BCP-00002  services                 Poblacion    monthly janitorial services: a check bounced before deposit and was replaced; the second billing waits for review
 *   BCP-00003  works, competitive       Lahug        performance security, progress billing with retention collected, change order applied, second progress waiting for inspection
 *   BCP-00004  goods, competitive       Malanday     bid in preparation, due in 5 days: a mandatory paper missing and the tax clearance expired
 *   BCP-00005  goods                    Poblacion    bid lost (reason and winner recorded)
 *   BCP-00006  services                 San Isidro   new opportunity, go / no-go pending
 *   BCP-00007  goods                    Lahug        bid submitted, ranked first at the bid opening, post-qualification documents asked
 *   BCP-00008  goods                    Malanday     delivered, billed, collected and closed out
 *   BCP-00009 to BCP-00011                           transactions on the company's SOP (tools/demo_brgy_sop.php)
 */

/** A small one-page PDF with the given lines, as an upload the vault can store */
function brgy_demo_pdf(string $name, array $lines): array
{
    $text = "BT /F1 16 Tf 56 780 Td (" . brgy_pdf_esc(array_shift($lines)) . ") Tj ET\n";
    $y = 750;
    foreach ($lines as $l) {
        $text .= "BT /F1 11 Tf 56 $y Td (" . brgy_pdf_esc($l) . ") Tj ET\n";
        $y -= 18;
    }
    $text .= "BT /F1 8 Tf 56 60 Td (Sample document made by the demo data of G-SMaRT Integrated Accounting and Government Contracting System) Tj ET\n";
    $objs = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "endstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    $tmp = tempnam(sys_get_temp_dir(), 'brgy');
    file_put_contents($tmp, $pdf);
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($pdf), 'type' => 'application/pdf'];
}

function brgy_pdf_esc(string $s): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '-', $s));
}

function load_brgy_demo_data(): void
{
    $d = fn(int $daysAgo) => date('Y-m-d', strtotime("-$daysAgo days"));
    $dt = fn(int $daysAgo, string $time = '10:00') => date('Y-m-d', strtotime("-$daysAgo days")) . ' ' . $time . ':00';
    $ahead = fn(int $days) => date('Y-m-d', strtotime("+$days days"));
    $u = fn(string $name) => (int)DB::value('SELECT id FROM users WHERE username = :u', ['u' => $name]);
    $as = fn(string $name) => Auth::actAs($u($name));
    $item = fn(string $code) => (int)DB::value('SELECT id FROM items WHERE code = :c', ['c' => $code]);
    $acct = fn(string $code) => (int)DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
    $vat = (int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'");
    $wh = (int)DB::value("SELECT id FROM sites WHERE code = 'WH'");
    $lastWf = 0;
    $runStart = (string)DB::value('SELECT NOW()');
    // the sample company's details on the forms (Contracting Settings)
    foreach (['brgy_company_reg_no' => 'SEC CS201512345', 'brgy_philgeps_no' => '2015-0012345', 'brgy_philgeps_class' => 'Platinum', 'brgy_signatory_name' => 'Juan P. Dela Cruz',
                 'brgy_signatory_position' => 'President and General Manager', 'brgy_signatory_title' => 'Mr.', 'brgy_signatory_mobile' => '0917 555 0142',
                 'brgy_manager_mobile' => '0917 555 0142', 'brgy_liaison_name' => 'Ana M. Reyes', 'brgy_liaison_position' => 'Liaison Officer', 'brgy_liaison_mobile' => '0918 555 0177',
                 'brgy_bank_details' => 'BDO Unibank, Makati Branch, Account Name: Sample Trading Company, Account No. 0012-3456-7890'] as $k => $v) {
        Settings::set($k, $v);
    }
    // the steps are made today; their workflow rows and documents are dated to the day they stand for
    // the workflow steps recorded since the last call happened at $when, one after the other (25 minutes apart)
    $at = function (string $when) use (&$lastWf) {
        $t = strtotime($when);
        foreach (DB::column('SELECT id FROM brgy_workflow WHERE id > :l ORDER BY id', ['l' => $lastWf]) as $wid) {
            DB::run('UPDATE brgy_workflow SET created_at = :d WHERE id = :id', ['d' => date('Y-m-d H:i:s', $t), 'id' => $wid]);
            $t += 25 * 60;
        }
        $lastWf = (int)DB::value('SELECT COALESCE(MAX(id), 0) FROM brgy_workflow');
    };
    $docAt = fn(int $docId, string $when) => DB::run('UPDATE brgy_documents SET uploaded_at = :d, reviewed_at = COALESCE(reviewed_at, :d2) WHERE id = :id', ['d' => $when, 'd2' => $when, 'id' => $docId]);
    $pdf = fn(string $file, string ...$lines) => brgy_demo_pdf($file, $lines);
    $gate = Settings::get('brgy_gate_deadline', '1');
    Settings::set('brgy_gate_deadline', '0');     // the sample bids are entered after their deadlines passed

    // ---------------------------------------------------------------- items and suppliers used by the contracts
    $as('admin');
    $mkItem = function (array $r) use ($vat, $acct) {
        if ($id = DB::value('SELECT id FROM items WHERE code = :c', ['c' => $r['code']])) {
            return (int)$id;
        }
        return DB::insert('items', $r + ['tax_code_id' => $vat, 'income_account_id' => $acct('4000'), 'cogs_account_id' => $acct('5000'),
            'asset_account_id' => $r['item_type'] === 'inventory' ? $acct('1200') : null, 'expense_account_id' => $r['item_type'] === 'inventory' ? null : $acct('6110')]);
    };
    $mkItem(['item_type' => 'non_inventory', 'code' => 'LED-SL100', 'name' => 'LED streetlight 100W with bracket', 'unit' => 'set', 'cost' => 4200, 'sales_price' => 5600]);
    $mkItem(['item_type' => 'inventory', 'code' => 'MED-PCM500', 'name' => 'Paracetamol 500mg tablets (box of 100)', 'unit' => 'box', 'cost' => 180, 'sales_price' => 260]);
    $mkItem(['item_type' => 'inventory', 'code' => 'MED-ALC500', 'name' => 'Isopropyl alcohol 70% 500ml', 'unit' => 'btl', 'cost' => 55, 'sales_price' => 85]);
    $mkItem(['item_type' => 'inventory', 'code' => 'RLF-PACK', 'name' => 'Relief goods pack (rice 5kg, canned goods, noodles)', 'unit' => 'pack', 'cost' => 520, 'sales_price' => 690]);
    $mkItem(['item_type' => 'inventory', 'code' => 'SPT-BALL', 'name' => 'Basketball, official size', 'unit' => 'pc', 'cost' => 900, 'sales_price' => 1350]);
    $mkVendor = function (string $name, string $addr, string $tin) {
        return (int)(DB::value('SELECT id FROM vendors WHERE name = :n', ['n' => $name]) ?: DB::insert('vendors', ['name' => $name, 'company_name' => $name, 'address' => $addr, 'tin' => $tin,
            'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'")]));
    };
    $vTech = (int)DB::value("SELECT id FROM vendors WHERE name = 'TechDistributors Inc.'");
    $vDepot = $mkVendor('Cebu Builders Depot', 'Mandaue City, Cebu', '204-556-112-000');
    $vSub = $mkVendor('R. Tan Construction Services', 'Cebu City', '301-778-990-000');
    $vLight = $mkVendor('Luzon Lighting Supply', 'Marikina City', '411-208-336-000');
    Lookup::reset();

    // ---------------------------------------------------------------- procuring entities and their officials
    $e = [];
    $e['sanisidro'] = BrgyOps::saveEntity(['official_name' => 'Barangay San Isidro', 'entity_type' => 'barangay', 'region' => 'NCR', 'province' => 'Metro Manila', 'city_municipality' => 'Quezon City',
        'barangay' => 'San Isidro', 'address' => 'Barangay Hall, 12 Mabini St., San Isidro, Quezon City', 'zip_code' => '1104', 'tin' => '000-611-222-000', 'phone' => '(02) 8921-1100',
        'email' => 'bac.sanisidro@qc.test', 'submission_methods' => ['physical', 'email'], 'submission_notes' => 'Bids hand-carried to the BAC Secretariat, 2/F barangay hall, 8:00 to 5:00. E-mail copies accepted for RFQs below the small-value ceiling.',
        'funding_notes' => 'Annual budget; 20% development fund for equipment.', 'requirement_notes' => 'Asks for a notarized omnibus sworn statement even on small purchases.']);
    $e['poblacion'] = BrgyOps::saveEntity(['official_name' => 'Barangay Poblacion', 'entity_type' => 'barangay', 'region' => 'NCR', 'province' => 'Metro Manila', 'city_municipality' => 'Makati City',
        'barangay' => 'Poblacion', 'address' => 'Barangay Hall, Poblacion, Makati City', 'zip_code' => '1210', 'tin' => '000-611-333-000', 'phone' => '(02) 8899-2200',
        'email' => 'treasury.poblacion@makati.test', 'submission_methods' => ['physical', 'philgeps'], 'submission_notes' => 'Billing packs to the Treasurer; payment by LDDAP-ADA or check.']);
    $e['lahug'] = BrgyOps::saveEntity(['official_name' => 'Barangay Lahug', 'entity_type' => 'barangay', 'region' => 'Region VII', 'province' => 'Cebu', 'city_municipality' => 'Cebu City',
        'barangay' => 'Lahug', 'address' => 'Barangay Hall, Gorordo Ave., Lahug, Cebu City', 'zip_code' => '6000', 'tin' => '000-611-444-000', 'phone' => '(032) 231-4400',
        'email' => 'bac.lahug@cebucity.test', 'submission_methods' => ['physical', 'philgeps', 'courier'], 'submission_notes' => 'Competitive bidding through PhilGEPS; sealed bids at the barangay session hall.']);
    $e['malanday'] = BrgyOps::saveEntity(['official_name' => 'Barangay Malanday', 'entity_type' => 'barangay', 'region' => 'NCR', 'province' => 'Metro Manila', 'city_municipality' => 'Marikina City',
        'barangay' => 'Malanday', 'address' => 'Barangay Hall, Malanday, Marikina City', 'zip_code' => '1805', 'tin' => '000-611-555-000', 'phone' => '(02) 8941-5500',
        'email' => 'bac.malanday@marikina.test', 'submission_methods' => ['physical']]);
    foreach ([
        ['sanisidro', 'punong_barangay', 'Hon. Lourdes A. Villanueva', 'Punong Barangay', '2018-07-01', null],
        ['sanisidro', 'punong_barangay', 'Hon. Ricardo M. Santos', 'Punong Barangay', '2023-11-30', null],
        ['sanisidro', 'bac_chair', 'Kgd. Maria L. Reyes', 'Barangay Kagawad, BAC Chairperson', '2024-01-15', null],
        ['sanisidro', 'treasurer', 'Elena P. Cruz', 'Barangay Treasurer', '2023-12-01', null],
        ['sanisidro', 'secretary', 'Jose R. Lim', 'Barangay Secretary', '2023-12-01', null],
        ['sanisidro', 'inspection', 'Engr. Paolo D. Garcia', 'Chair, Inspection and Acceptance Committee', '2024-01-15', null],
        ['poblacion', 'punong_barangay', 'Hon. Carmela T. Uy', 'Punong Barangay', '2023-11-30', null],
        ['poblacion', 'bac_chair', 'Kgd. Ramon B. Dizon', 'BAC Chairperson', '2024-01-10', null],
        ['poblacion', 'treasurer', 'Grace S. Mendoza', 'Barangay Treasurer', '2023-12-01', null],
        ['poblacion', 'end_user', 'Lito C. Manalo', 'Barangay Administrator', '2024-01-10', null],
        ['lahug', 'punong_barangay', 'Hon. Antonio G. Ybanez', 'Punong Barangay', '2023-11-30', null],
        ['lahug', 'bac_chair', 'Kgd. Teresa O. Ramos', 'BAC Chairperson', '2024-01-08', null],
        ['lahug', 'treasurer', 'Rodel P. Alcantara', 'Barangay Treasurer', '2023-12-01', null],
        ['lahug', 'inspection', 'Engr. Danilo F. Cabrera', 'Barangay Engineer, Inspection Committee', '2024-01-08', null],
        ['malanday', 'punong_barangay', 'Hon. Felipe S. Bautista', 'Punong Barangay', '2023-11-30', null],
        ['malanday', 'bac_chair', 'Kgd. Rowena M. Torres', 'BAC Chairperson', '2024-01-12', null],
        ['malanday', 'treasurer', 'Nora A. Pascual', 'Barangay Treasurer', '2023-12-01', null],
    ] as [$k, $role, $name, $pos, $from, $to]) {
        BrgyOps::saveContact($e[$k], ['role_code' => $role, 'name' => $name, 'position' => $pos, 'valid_from' => $from, 'valid_to' => $to, 'is_signatory' => in_array($role, ['punong_barangay', 'treasurer'], true) ? 1 : 0]);
    }
    // a barangay-specific requirement, approved
    DB::insert('brgy_requirements', ['code' => 'REQ-SANISIDRO-OSS', 'name' => 'Notarized omnibus sworn statement on every purchase (Barangay San Isidro practice)', 'doc_group' => 'bid', 'stage' => 'bid',
        'requirement_type' => 'mandatory', 'doc_type' => 'omnibus', 'template_code' => 'omnibus_sworn_statement', 'is_prescribed' => 1, 'entity_id' => $e['sanisidro'],
        'legal_basis' => 'BAC Resolution 2024-03 of Barangay San Isidro (copy on file)', 'status' => 'approved', 'approved_by' => $u('manager'), 'approved_at' => $dt(100), 'sort_order' => 205, 'created_by' => $u('bidofficer')]);

    // ---------------------------------------------------------------- company papers in the vault
    $as('bidofficer');
    $company = [
        ['philgeps_cert', 'PhilGEPS Certificate of Platinum Membership', 'PhilGEPS', 'PG-2015-0012345', 340, 20],
        ['mayors_permit', "Mayor's Permit 2026", 'Makati City BPLO', 'MP-2026-04411', 280, 78],
        ['sec_dti', 'SEC Certificate of Incorporation', 'Securities and Exchange Commission', 'CS201512345', 3900, null],
        ['bir_2303', 'BIR Certificate of Registration (Form 2303)', 'BIR RDO 047', 'OCN 2015-047-00123', 3900, null],
        ['tax_clearance', 'Tax Clearance for bidding purposes', 'Bureau of Internal Revenue', 'TC-2025-118822', 375, -10],
        ['afs', 'Audited Financial Statements 2025', 'Reyes Tacandong & Co. (sample)', 'AFS-2025', 190, 175],
        ['slcc', 'Statement of ongoing and completed contracts', 'Sample Trading Company', 'SLCC-2026-09', 30, null],
        ['pcab_license', 'PCAB License, Category C, General Building', 'PCAB', 'PCAB-41557', 250, 115],
        ['authority', "Secretary's Certificate: authority of the President to bid and sign", 'Corporate Secretary', 'SC-2026-01', 200, null],
        ['itr', 'Annual Income Tax Return 2025 (BIR Form 1702)', 'Bureau of Internal Revenue', '1702-2025', 180, null],
    ];
    $cdoc = [];
    foreach ($company as [$type, $title, $issuer, $no, $issuedAgo, $validDays]) {
        $id = BrgyDocs::store($pdf(str_replace(' ', '_', $type) . '.pdf', $title, 'Issued by: ' . $issuer, 'Document no.: ' . $no, 'Issued: ' . $d($issuedAgo), $validDays !== null ? 'Valid until: ' . $ahead($validDays) : 'No expiry'),
            ['scope' => 'company', 'doc_type' => $type, 'title' => $title, 'doc_no' => $no, 'issued_by' => $issuer, 'issue_date' => $d($issuedAgo),
                'valid_until' => $validDays !== null ? date('Y-m-d', strtotime(($validDays >= 0 ? '+' : '') . $validDays . ' days')) : null]);
        $cdoc[$type] = $id;
        $docAt($id, $dt(min(120, $issuedAgo), '09:00'));
    }
    $as('accountant');
    foreach ($cdoc as $type => $id) {
        BrgyDocs::review(BrgyDocs::load($id), 'approved', 'Checked against the original');
    }

    // ---------------------------------------------------------------- BCP-00001: goods, two deliveries, one rejected monitor replaced
    $as('bidofficer');
    $svp = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'small_value'");
    $cbid = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'competitive_bidding'");
    $people = ['preparer_id' => $u('bidofficer'), 'checker_id' => $u('accountant'), 'approver_id' => $u('manager'), 'submitter_id' => $u('bidofficer'), 'pm_id' => $u('pmgr'), 'collector_id' => $u('collector')];
    $p1 = BrgyOps::saveProject($people + ['title' => 'Supply and delivery of office and IT equipment for the barangay hall', 'entity_id' => $e['sanisidro'], 'category' => 'goods', 'mode_id' => $svp,
        'reference_no' => 'RFQ-SI-2026-031', 'philgeps_ref' => '11829933', 'source_url' => 'https://notices.philgeps.gov.ph/', 'posting_date' => $d(70), 'end_user' => 'Office of the Barangay Secretary',
        'funding_source' => 'Barangay General Fund 2026', 'abc_amount' => 300000, 'delivery_site' => 'Barangay Hall, 12 Mabini St., San Isidro, Quezon City', 'delivery_days' => 15,
        'bid_deadline' => $dt(60, '10:00'), 'bid_opening_at' => $dt(60, '10:30'), 'warranty_months' => 12, 'payment_terms' => 'Within 30 days from receipt of the billing with complete documents',
        'specs' => "Laptop: 14-inch, Core i5 or better, 16GB RAM, 512GB SSD\nMonitor: 24-inch IPS, full HD\nToner: compatible with the barangay's laser printers", 'site_id' => $wh,
        'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'")]);
    $at($dt(70));
    BrgyOps::goDecision($p1, 'go', 'Within our IT line; good payment record of the barangay');
    $at($dt(69));
    BrgyOps::saveLines($p1, [
        ['line_kind' => 'goods', 'item_id' => $item('LAP-001'), 'description' => 'Laptop 14-inch, Core i5, 16GB RAM, 512GB SSD', 'unit' => 'unit', 'qty' => 5, 'unit_cost' => 32000, 'unit_price' => 38500, 'tax_code_id' => $vat],
        ['line_kind' => 'goods', 'item_id' => $item('MON-024'), 'description' => 'Monitor 24-inch IPS full HD', 'unit' => 'unit', 'qty' => 5, 'unit_cost' => 6500, 'unit_price' => 8500, 'tax_code_id' => $vat],
        ['line_kind' => 'goods', 'item_id' => $item('KEY-001'), 'description' => 'Keyboard, USB', 'unit' => 'pc', 'qty' => 5, 'unit_cost' => 650, 'unit_price' => 950, 'tax_code_id' => $vat],
        ['line_kind' => 'goods', 'item_id' => $item('MOU-001'), 'description' => 'Mouse, wireless', 'unit' => 'pc', 'qty' => 5, 'unit_cost' => 350, 'unit_price' => 550, 'tax_code_id' => $vat],
        ['line_kind' => 'goods', 'item_id' => $item('NI-TONER'), 'description' => 'Toner cartridge for laser printer', 'unit' => 'pc', 'qty' => 10, 'unit_cost' => 2500, 'unit_price' => 3100, 'tax_code_id' => $vat],
        ['line_kind' => 'service', 'item_id' => $item('SRV-INSTALL'), 'description' => 'Installation, set-up and orientation of the users', 'unit' => 'lot', 'qty' => 1, 'unit_cost' => 2000, 'unit_price' => 4500, 'tax_code_id' => $vat],
    ]);
    $at($dt(68));
    Brgy::buildChecklist($p1);
    // the project papers of the bid
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p1]) as $r) {
        if ($r['requirement_type'] !== 'mandatory') {
            BrgyOps::waiveRequirement((int)$r['id'], 'Not asked in the RFQ for this small value procurement');
            continue;
        }
        BrgyOps::attachRequirement((int)$r['id'], $pdf($r['doc_type'] . '_p1.pdf', $r['name'], 'Project: BCP-00001', 'Signed by: Juan P. Dela Cruz'), null, ['title' => $r['name']]);
    }
    $at($dt(66));
    $as('manager');
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p1]) as $r) {
        BrgyOps::waiveRequirement((int)$r['id'], 'Not asked in the RFQ for this small value procurement');
    }
    $as('accountant');
    foreach (DB::all("SELECT document_id FROM brgy_project_reqs WHERE project_id = :p AND status = 'submitted'", ['p' => $p1]) as $r) {
        BrgyDocs::review(BrgyDocs::load((int)$r['document_id']), 'approved', 'Complete and signed');
    }
    $at($dt(65));
    $as('bidofficer');
    BrgyOps::submitBid($p1, 'Costing at about 15% margin; supplier quotation of TechDistributors attached');
    $at($dt(64, '09:00'));
    $as('accountant');
    BrgyOps::bidStep($p1, 'review', 'Costs agree with the supplier quotation; taxes included');
    $at($dt(63, '14:00'));
    $as('manager');
    BrgyOps::bidStep($p1, 'approve', 'Go ahead');
    $at($dt(62, '16:00'));
    $as('bidofficer');
    BrgyOps::recordSubmission($p1, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => $dt(61, '09:20'), 'recipient_name' => 'BAC Secretariat', 'recipient_office' => 'Barangay San Isidro',
        'received_by' => 'Ana M. Flores, BAC Secretariat', 'received_at' => $dt(61, '09:22'), 'ack_ref' => 'Logbook entry 2026-114'],
        $pdf('receiving_copy_bid.pdf', 'Receiving copy of the quotation', 'Received by: Ana M. Flores, BAC Secretariat', 'Date and time: ' . $dt(61, '09:22')));
    $at($dt(61, '09:20'));
    BrgyOps::addEvent($p1, ['event_type' => 'bid_opening', 'event_at' => $dt(60, '10:30'), 'direction' => 'in', 'title' => 'Opening of quotations: lowest calculated quotation',
        'details' => '3 quotations received. Sample Trading Company: 278,000.00 (lowest). Others: 289,500.00 and 296,800.00.', 'party' => 'BAC, Barangay San Isidro']);
    BrgyOps::recordResult($p1, 'rank', ['result_rank' => 1]);
    $at($dt(60, '11:00'));
    BrgyOps::recordAward($p1, ['noa_ref' => 'NOA-SI-2026-031', 'noa_date' => $d(52), 'noa_received_date' => $d(51), 'noa_ack_date' => $d(51), 'contract_amount' => 278000, 'po_no' => 'PO-SI-2026-0117',
        'contract_signed_date' => $d(50), 'start_date' => $d(50), 'evidence_type' => 'po'],
        $pdf('noa_po_si_031.pdf', 'Notice of Award and Purchase Order', 'NOA-SI-2026-031 / PO-SI-2026-0117', 'Barangay San Isidro, Quezon City', 'Amount: PHP 278,000.00', 'Signed: Hon. Ricardo M. Santos, Punong Barangay'));
    $at($dt(51));
    BrgyOps::startFulfillment($p1);
    $at($dt(50));
    $P1 = Brgy::mustProject($p1);
    // buy what the contract needs, charged to its job
    Txn::save('bill', ['txn_date' => $d(49), 'vendor_id' => $vTech, 'account_id' => Accts::get('ap'), 'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'"), 'due_date' => $ahead(-19),
        'ref_no' => 'TD-INV-55210', 'memo' => 'For BCP-00001 Barangay San Isidro', 'class_id' => $P1['class_id'], 'site_id' => $wh],
        array_map(fn($r) => ['line_kind' => 'item', 'item_id' => $item($r[0]), 'description' => $r[1], 'qty' => $r[2], 'rate' => $r[3], 'uom_qty' => $r[2], 'uom_rate' => $r[3],
            'amount' => r2($r[2] * $r[3]), 'tax_code_id' => $vat, 'tax_amount' => r2($r[2] * $r[3] * 0.12), 'site_id' => $wh, 'customer_id' => $P1['job_customer_id'], 'class_id' => $P1['class_id']],
            [['LAP-001', 'Laptop 14-inch i5 16GB', 5, 32000], ['MON-024', 'Monitor 24-inch IPS', 6, 6500], ['KEY-001', 'Keyboard USB', 5, 650], ['MOU-001', 'Wireless mouse', 5, 350], ['NI-TONER', 'Toner cartridge', 10, 2500]]));
    $as('pmgr');
    $lines = array_column(Brgy::lines($p1), null, 'line_no');
    $dr1 = BrgyOps::saveDelivery(['project_id' => $p1, 'delivery_date' => $d(40), 'kind' => 'goods', 'from_site_id' => $wh, 'received_by_name' => 'Jose R. Lim', 'received_position' => 'Barangay Secretary',
        'vehicle' => 'L300 NAB-1234', 'lines' => [$lines[1]['id'] => ['qty' => 5, 'serial_batch' => 'SN LP5520-01 to 05'], $lines[2]['id'] => ['qty' => 5, 'serial_batch' => 'SN MN2401-11 to 15'],
            $lines[3]['id'] => ['qty' => 5], $lines[4]['id'] => ['qty' => 5]]]);
    $at($dt(40));
    $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d ORDER BY id', ['d' => $dr1]);
    BrgyOps::recordInspection($dr1, ['inspection_date' => $d(38), 'acceptance_date' => $d(38), 'acceptance_ref' => 'IAR-SI-2026-088', 'inspectors' => 'Engr. Paolo D. Garcia; Jose R. Lim',
        'inspection_notes' => 'One monitor (SN MN2401-14) has dead pixels: rejected for replacement.', 'lines' => [
            $dl[0]['id'] => ['accepted' => 5], $dl[1]['id'] => ['accepted' => 4, 'rejected' => 1, 'remarks' => 'SN MN2401-14 rejected: dead pixels'], $dl[2]['id'] => ['accepted' => 5], $dl[3]['id'] => ['accepted' => 5]]],
        $pdf('iar_si_088.pdf', 'Inspection and Acceptance Report IAR-SI-2026-088', 'Barangay San Isidro', '5 laptops, 4 monitors, 5 keyboards, 5 mice accepted', '1 monitor rejected (dead pixels)', 'Inspected by: Engr. Paolo D. Garcia'));
    $at($dt(38));
    $drNo = (string)DB::value('SELECT dr_no FROM brgy_deliveries WHERE id = :id', ['id' => $dr1]);
    BrgyDocs::store($pdf('dr_si_1.pdf', 'Delivery Receipt ' . $drNo, 'Received by: Jose R. Lim, Barangay Secretary', 'Date: ' . $d(40)), ['scope' => 'project', 'project_id' => $p1, 'doc_type' => 'dr',
        'title' => 'Signed delivery receipt ' . $drNo, 'doc_no' => $drNo, 'related_type' => 'delivery', 'related_id' => $dr1, 'status' => 'approved']);
    $as('billing');
    $b1 = BrgyOps::saveBilling(['project_id' => $p1, 'billing_date' => $d(35), 'billing_type' => 'partial', 'si_no' => '0004511',
        'lines' => [$lines[1]['id'] => 5, $lines[2]['id'] => 4, $lines[3]['id'] => 5, $lines[4]['id'] => 5]]);
    BrgyOps::billingStep($b1, 'submit', 'First partial billing for the accepted items');
    $at($dt(35, '09:00'));
    $as('accountant');
    BrgyOps::billingStep($b1, 'review', 'Quantities agree with IAR-SI-2026-088');
    $at($dt(35, '11:00'));
    $as('manager');
    BrgyOps::billingStep($b1, 'approve', '');
    $at($dt(35, '14:00'));
    $as('billing');
    $inv1 = BrgyOps::invoiceBilling($b1);
    $at($dt(35, '15:00'));
    $f = BrgyOps::createForm((int)DB::value("SELECT id FROM brgy_templates WHERE code = 'statement_of_account'"), $p1, ['billing', $b1]);
    BrgyOps::formStep($f, 'submit');
    $as('accountant');
    BrgyOps::formStep($f, 'review');
    $as('manager');
    BrgyOps::formStep($f, 'approve');
    $as('billing');
    BrgyOps::finalizeForm($f);
    BrgyDocs::store($pdf('si_0004511.pdf', 'Sales Invoice No. 0004511', 'Barangay San Isidro', 'Amount: PHP 234,000.00 (VAT inclusive)'), ['scope' => 'project', 'project_id' => $p1, 'doc_type' => 'invoice',
        'title' => 'Sales invoice 0004511', 'doc_no' => '0004511', 'related_type' => 'billing', 'related_id' => $b1, 'status' => 'approved']);
    BrgyOps::submitBilling($b1, ['method' => 'physical', 'submitted_at' => $dt(34, '10:00'), 'recipient_name' => 'Elena P. Cruz', 'recipient_office' => 'Office of the Barangay Treasurer']);
    $at($dt(34, '10:00'));
    BrgyOps::acknowledgeBilling($b1, ['acknowledged_at' => $dt(33, '15:00'), 'received_by' => 'Elena P. Cruz, Barangay Treasurer', 'ack_ref' => 'RCV-2026-0412'],
        $pdf('receiving_copy_bbl1.pdf', 'Receiving copy of billing BBL-00001', 'Received by: Elena P. Cruz', 'Date: ' . $d(33)));
    $at($dt(33, '15:00'));
    $as('collector');
    $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b1]);
    $exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
    $c1 = BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b1, 'collection_date' => $d(15), 'method' => 'check', 'check_no' => '0071245', 'check_date' => $d(16), 'bank_name' => 'Land Bank of the Philippines',
        'reference_no' => 'DV 2026-09-118', 'cash_received' => r2((float)$b['gross_amount'] - $exp['ewt'] - $exp['vat']), 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'receipt_no' => 'AR-0451',
        'cert_2307' => 1, 'cert_2307_file' => $pdf('2307_si.pdf', 'BIR Form 2307', 'Payor: Barangay San Isidro', 'Payee: Sample Trading Company', 'WC158 1%: ' . number_format($exp['ewt'], 2), 'WV010 5%: ' . number_format($exp['vat'], 2))]);
    // the replacement monitor, toner and installation
    $as('pmgr');
    $dr2 = BrgyOps::saveDelivery(['project_id' => $p1, 'delivery_date' => $d(20), 'kind' => 'goods', 'from_site_id' => $wh, 'received_by_name' => 'Jose R. Lim', 'received_position' => 'Barangay Secretary',
        'lines' => [$lines[2]['id'] => ['qty' => 1, 'serial_batch' => 'SN MN2401-16 (replacement)'], $lines[5]['id'] => ['qty' => 10], $lines[6]['id'] => ['qty' => 1]]]);
    $at($dt(20));
    $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d ORDER BY id', ['d' => $dr2]);
    BrgyOps::recordInspection($dr2, ['inspection_date' => $d(18), 'acceptance_date' => $d(18), 'acceptance_ref' => 'IAR-SI-2026-097', 'inspectors' => 'Engr. Paolo D. Garcia',
        'lines' => [$dl[0]['id'] => ['accepted' => 1], $dl[1]['id'] => ['accepted' => 10], $dl[2]['id'] => ['accepted' => 1]]],
        $pdf('iar_si_097.pdf', 'Inspection and Acceptance Report IAR-SI-2026-097', 'Replacement monitor, 10 toner cartridges and installation accepted'));
    $at($dt(18));
    $as('billing');
    $b2 = BrgyOps::saveBilling(['project_id' => $p1, 'billing_date' => $d(16), 'billing_type' => 'final', 'si_no' => '0004538', 'lines' => [$lines[2]['id'] => 1, $lines[5]['id'] => 10, $lines[6]['id'] => 1]]);
    BrgyOps::billingStep($b2, 'submit');
    $as('accountant');
    BrgyOps::billingStep($b2, 'review');
    $as('manager');
    BrgyOps::billingStep($b2, 'approve');
    $as('billing');
    BrgyOps::invoiceBilling($b2);
    BrgyDocs::store($pdf('si_0004538.pdf', 'Sales Invoice No. 0004538', 'Amount: PHP 44,000.00'), ['scope' => 'project', 'project_id' => $p1, 'doc_type' => 'invoice', 'title' => 'Sales invoice 0004538',
        'doc_no' => '0004538', 'related_type' => 'billing', 'related_id' => $b2, 'status' => 'approved']);
    $f = BrgyOps::createForm((int)DB::value("SELECT id FROM brgy_templates WHERE code = 'statement_of_account'"), $p1, ['billing', $b2]);
    BrgyOps::formStep($f, 'submit');
    $as('accountant');
    BrgyOps::formStep($f, 'review');
    $as('manager');
    BrgyOps::formStep($f, 'approve');
    $as('billing');
    BrgyOps::finalizeForm($f);
    BrgyOps::submitBilling($b2, ['method' => 'physical', 'submitted_at' => $dt(15, '10:30'), 'recipient_name' => 'Elena P. Cruz', 'recipient_office' => 'Office of the Barangay Treasurer']);
    $at($dt(15, '10:30'));
    BrgyOps::acknowledgeBilling($b2, ['acknowledged_at' => $dt(14, '09:00'), 'received_by' => 'Elena P. Cruz, Barangay Treasurer', 'ack_ref' => 'RCV-2026-0455']);
    $at($dt(14, '09:00'));
    $as('bidofficer');
    $f = BrgyOps::createForm((int)DB::value("SELECT id FROM brgy_templates WHERE code = 'cover_letter'"), $p1);
    DB::update('brgy_forms', ['created_at' => $dt(62)], 'id = :id', ['id' => $f]);

    // ---------------------------------------------------------------- BCP-00002: monthly janitorial services; a check bounced and was replaced
    $as('bidofficer');
    $neg = (int)DB::value("SELECT id FROM brgy_proc_modes WHERE code = 'negotiated_other'");
    $p2 = BrgyOps::saveProject($people + ['title' => 'Janitorial and maintenance services for the barangay hall and health center (2 months)', 'entity_id' => $e['poblacion'], 'category' => 'services', 'mode_id' => $svp,
        'reference_no' => 'RFQ-POB-2026-022', 'posting_date' => $d(80), 'abc_amount' => 95000, 'delivery_site' => 'Barangay Hall and Health Center, Poblacion, Makati City', 'delivery_days' => 61,
        'bid_deadline' => $dt(72, '15:00'), 'end_user' => 'Barangay Administrator', 'payment_terms' => 'Monthly, upon certification of the services rendered',
        'specs' => '4 janitors, Monday to Saturday, 7:00 AM to 4:00 PM; cleaning materials included', 'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 15'")]);
    $at($dt(80));
    BrgyOps::goDecision($p2, 'go', 'Repeat client; services team available');
    BrgyOps::saveLines($p2, [['line_kind' => 'service', 'description' => 'Janitorial and maintenance services, 4 janitors (per month)', 'unit' => 'month', 'qty' => 2, 'unit_cost' => 36000, 'unit_price' => 45000, 'tax_code_id' => $vat]]);
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p2]) as $r) {
        if ($r['requirement_type'] === 'mandatory') {
            BrgyOps::attachRequirement((int)$r['id'], $pdf($r['doc_type'] . '_p2.pdf', $r['name'], 'Project: BCP-00002'), null, ['title' => $r['name']]);
        }
    }
    $as('manager');
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p2]) as $r) {
        BrgyOps::waiveRequirement((int)$r['id'], 'Not required by the RFQ');
    }
    $as('accountant');
    foreach (DB::all("SELECT document_id FROM brgy_project_reqs WHERE project_id = :p AND status = 'submitted'", ['p' => $p2]) as $r) {
        BrgyDocs::review(BrgyDocs::load((int)$r['document_id']), 'approved', '');
    }
    $as('bidofficer');
    BrgyOps::submitBid($p2);
    $as('accountant');
    BrgyOps::bidStep($p2, 'review');
    $as('manager');
    BrgyOps::bidStep($p2, 'approve');
    $as('bidofficer');
    BrgyOps::recordSubmission($p2, ['sub_type' => 'bid', 'method' => 'email', 'submitted_at' => $dt(73, '11:00'), 'recipient_name' => 'BAC Secretariat', 'tracking_no' => 'Email of ' . $d(73) . ' 11:00'],
        $pdf('email_sent_p2.pdf', 'E-mail submission of quotation RFQ-POB-2026-022', 'Sent to: bac.poblacion@makati.test', 'Time: ' . $dt(73, '11:00')));
    $at($dt(73, '11:00'));
    BrgyOps::recordAward($p2, ['noa_ref' => 'JO-POB-2026-045', 'noa_date' => $d(66), 'noa_received_date' => $d(65), 'contract_amount' => 90000, 'po_no' => 'JO-POB-2026-045', 'contract_signed_date' => $d(64),
        'ntp_date' => $d(63), 'start_date' => $d(62), 'evidence_type' => 'po'], $pdf('jo_pob_045.pdf', 'Job Order JO-POB-2026-045', 'Janitorial services, 2 months', 'Amount: PHP 90,000.00'));
    $at($dt(65));
    BrgyOps::startFulfillment($p2);
    $at($dt(62));
    $P2 = Brgy::mustProject($p2);
    // the janitors' pay, charged to the job
    foreach ([[48, 'August'], [18, 'September']] as [$ago, $mon]) {
        Txn::save('cash_voucher', ['txn_date' => $d($ago), 'account_id' => $acct('1006'), 'payee_name' => 'Janitorial crew payroll', 'memo' => 'Janitors, ' . $mon . ' (BCP-00002)', 'class_id' => $P2['class_id'], 'total' => 33600, 'subtotal' => 33600, 'status' => 'open'],
            [['line_kind' => 'journal', 'account_id' => $acct('6000'), 'amount' => 33600, 'description' => 'Janitors wages, ' . $mon, 'customer_id' => $P2['job_customer_id']]], null, ['keep_totals' => true]);
    }
    $line2 = Brgy::lines($p2)[0];
    $as('pmgr');
    $acc1 = BrgyOps::saveDelivery(['project_id' => $p2, 'delivery_date' => $d(32), 'kind' => 'service', 'delivered_to' => 'Barangay Hall and Health Center', 'received_by_name' => 'Lito C. Manalo',
        'received_position' => 'Barangay Administrator', 'notes' => 'Services for the first month', 'lines' => [$line2['id'] => ['qty' => 1, 'remarks' => 'Month 1']]]);
    $at($dt(32));
    $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $acc1]);
    BrgyOps::recordInspection($acc1, ['acceptance_date' => $d(31), 'acceptance_ref' => 'CERT-POB-2026-201', 'inspectors' => 'Lito C. Manalo', 'lines' => [$dl[0]['id'] => ['accepted' => 1]]],
        $pdf('cert_services_m1.pdf', 'Certificate of Services Rendered', 'Month 1: services satisfactorily rendered', 'Lito C. Manalo, Barangay Administrator'));
    $at($dt(31));
    $as('billing');
    $b3 = BrgyOps::saveBilling(['project_id' => $p2, 'billing_date' => $d(30), 'billing_type' => 'progress', 'si_no' => '0004520', 'period_from' => $d(62), 'period_to' => $d(32), 'lines' => [$line2['id'] => 1]]);
    BrgyOps::billingStep($b3, 'submit');
    $as('accountant');
    BrgyOps::billingStep($b3, 'review');
    $as('manager');
    BrgyOps::billingStep($b3, 'approve');
    $as('billing');
    BrgyOps::invoiceBilling($b3);
    foreach (['invoice' => 'Sales invoice 0004520', 'soa' => 'Statement of account, month 1', 'po' => 'Copy of the job order', 'accomplishment' => 'Accomplishment report, month 1'] as $t => $title) {
        BrgyDocs::store($pdf($t . '_b3.pdf', $title, 'BCP-00002 Barangay Poblacion'), ['scope' => 'project', 'project_id' => $p2, 'doc_type' => $t, 'title' => $title, 'related_type' => 'billing', 'related_id' => $b3, 'status' => 'approved']);
    }
    BrgyOps::submitBilling($b3, ['method' => 'physical', 'submitted_at' => $dt(29, '09:00'), 'recipient_name' => 'Grace S. Mendoza', 'recipient_office' => 'Treasury']);
    BrgyOps::acknowledgeBilling($b3, ['acknowledged_at' => $dt(29, '09:05'), 'received_by' => 'Grace S. Mendoza, Barangay Treasurer']);
    $at($dt(29));
    $as('collector');
    $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b3]);
    $exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
    $cash = r2((float)$b['gross_amount'] - $exp['ewt'] - $exp['vat']);
    $c2 = BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b3, 'collection_date' => $d(22), 'method' => 'check', 'check_no' => '0003318', 'check_date' => $d(22), 'bank_name' => 'Development Bank of the Philippines',
        'cash_received' => $cash, 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'receipt_no' => 'AR-0447']);
    BrgyPost::bounce($c2, $d(21), 'Drawn against insufficient funds (DAIF); the barangay will replace it through LDDAP-ADA');
    $c2b = BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b3, 'collection_date' => $d(17), 'method' => 'lddap_ada', 'reference_no' => 'LDDAP-ADA 2026-09-0331', 'deposit_account_id' => $acct('1010'),
        'cash_received' => $cash, 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'receipt_no' => 'AR-0449', 'notes' => 'Replaces check 0003318']);
    $as('pmgr');
    $acc2 = BrgyOps::saveDelivery(['project_id' => $p2, 'delivery_date' => $d(3), 'kind' => 'service', 'received_by_name' => 'Lito C. Manalo', 'received_position' => 'Barangay Administrator',
        'notes' => 'Services for the second month', 'lines' => [$line2['id'] => ['qty' => 1, 'remarks' => 'Month 2']]]);
    $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $acc2]);
    BrgyOps::recordInspection($acc2, ['acceptance_date' => $d(2), 'acceptance_ref' => 'CERT-POB-2026-233', 'inspectors' => 'Lito C. Manalo', 'lines' => [$dl[0]['id'] => ['accepted' => 1]]],
        $pdf('cert_services_m2.pdf', 'Certificate of Services Rendered', 'Month 2: services satisfactorily rendered'));
    $as('billing');
    $b4 = BrgyOps::saveBilling(['project_id' => $p2, 'billing_date' => $d(1), 'billing_type' => 'final', 'si_no' => '0004561', 'period_from' => $d(31), 'period_to' => $d(1), 'lines' => [$line2['id'] => 1]]);
    BrgyOps::billingStep($b4, 'submit', 'Final month');
    $at($dt(1, '10:00'));

    // ---------------------------------------------------------------- BCP-00003: works with retention, a change order and a performance security
    $as('bidofficer');
    $p3 = BrgyOps::saveProject($people + ['title' => 'Rehabilitation of the barangay multi-purpose hall', 'entity_id' => $e['lahug'], 'category' => 'works', 'mode_id' => $cbid,
        'reference_no' => 'ITB-LAHUG-2026-004', 'philgeps_ref' => '11790241', 'source_url' => 'https://notices.philgeps.gov.ph/', 'posting_date' => $d(78), 'abc_amount' => 1500000,
        'delivery_site' => 'Multi-purpose hall, Barangay Lahug, Cebu City', 'delivery_days' => 90, 'prebid_at' => $dt(70, '09:00'), 'bid_deadline' => $dt(60, '09:00'), 'bid_opening_at' => $dt(60, '10:00'),
        'funding_source' => '20% Development Fund 2026', 'retention_pct' => 10, 'ld_rate_pct' => 0.1, 'warranty_months' => 12, 'end_user' => 'Barangay Engineering Office',
        'payment_terms' => 'Progress billings per certified accomplishment, less 10% retention', 'specs' => 'Per the approved plans, program of work and technical specifications of the ITB.',
        'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'")]);
    $at($dt(78));
    BrgyOps::goDecision($p3, 'go', 'Within our PCAB category; site 20 minutes from our Cebu branch');
    BrgyOps::addEvent($p3, ['event_type' => 'prebid', 'event_at' => $dt(70, '09:00'), 'direction' => 'in', 'title' => 'Pre-bid conference attended', 'party' => 'BAC, Barangay Lahug', 'details' => 'Clarified the ceiling material and the working hours.']);
    BrgyOps::addEvent($p3, ['event_type' => 'bulletin', 'event_at' => $dt(67, '16:00'), 'direction' => 'in', 'title' => 'Supplemental bid bulletin no. 1: ceiling board thickness 4.5mm', 'party' => 'BAC, Barangay Lahug'],
        $pdf('sbb1.pdf', 'Supplemental Bid Bulletin No. 1', 'ITB-LAHUG-2026-004', 'Ceiling board thickness: 4.5mm fiber cement'));
    BrgyOps::saveLines($p3, [
        ['line_kind' => 'works', 'description' => 'Demolition and hauling of damaged ceiling and partitions', 'unit' => 'lot', 'qty' => 1, 'unit_cost' => 68000, 'unit_price' => 85000, 'tax_code_id' => $vat],
        ['line_kind' => 'works', 'description' => 'Roof repair: replacement of GI sheets, purlins and trusses', 'unit' => 'lot', 'qty' => 1, 'unit_cost' => 345000, 'unit_price' => 420000, 'tax_code_id' => $vat],
        ['line_kind' => 'works', 'description' => 'Ceiling works: 4.5mm fiber cement board on metal furring', 'unit' => 'sq.m', 'qty' => 180, 'unit_cost' => 1150, 'unit_price' => 1450, 'tax_code_id' => $vat],
        ['line_kind' => 'works', 'description' => 'Electrical works: rewiring, panel board and LED lighting', 'unit' => 'lot', 'qty' => 1, 'unit_cost' => 172000, 'unit_price' => 215000, 'tax_code_id' => $vat],
        ['line_kind' => 'works', 'description' => 'Painting works, interior and exterior', 'unit' => 'sq.m', 'qty' => 650, 'unit_cost' => 300, 'unit_price' => 380, 'tax_code_id' => $vat],
        ['line_kind' => 'works', 'description' => 'Comfort room renovation (2 units)', 'unit' => 'lot', 'qty' => 1, 'unit_cost' => 146000, 'unit_price' => 180000, 'tax_code_id' => $vat],
    ]);
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p3]) as $r) {
        BrgyOps::attachRequirement((int)$r['id'], $pdf($r['doc_type'] . '_p3.pdf', $r['name'], 'Project: BCP-00003', 'ITB-LAHUG-2026-004'), null, ['title' => $r['name'], 'valid_until' => (int)$r['needs_validity'] ? $ahead(60) : null]);
    }
    $as('accountant');
    foreach (DB::all("SELECT document_id FROM brgy_project_reqs WHERE project_id = :p AND status = 'submitted'", ['p' => $p3]) as $r) {
        BrgyDocs::review(BrgyDocs::load((int)$r['document_id']), 'approved', '');
    }
    $as('bidofficer');
    $sec0 = BrgyOps::saveSecurity($p3, ['security_type' => 'bid', 'form' => 'declaration', 'amount' => 0, 'issue_date' => $d(61), 'valid_until' => $ahead(60), 'notes' => 'Bid securing declaration']);
    BrgyOps::submitBid($p3);
    $as('accountant');
    BrgyOps::bidStep($p3, 'review', 'BOQ checked against the program of work');
    $as('manager');
    BrgyOps::bidStep($p3, 'approve');
    $as('bidofficer');
    BrgyOps::recordSubmission($p3, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => $dt(60, '08:40'), 'recipient_name' => 'BAC Secretariat', 'recipient_office' => 'Barangay Lahug',
        'received_by' => 'BAC Secretariat (stamp)', 'received_at' => $dt(60, '08:41'), 'ack_ref' => 'Bid no. 3'], $pdf('lahug_receipt.pdf', 'Bid receipt', 'Received 08:41, bid no. 3'));
    $at($dt(60, '08:40'));
    BrgyOps::addEvent($p3, ['event_type' => 'bid_opening', 'event_at' => $dt(60, '10:00'), 'direction' => 'in', 'title' => 'Bid opening: lowest calculated bid', 'details' => 'Sample Trading Company 1,408,000.00; two others above.']);
    BrgyOps::addEvent($p3, ['event_type' => 'post_qual', 'event_at' => $dt(56, '10:00'), 'direction' => 'in', 'title' => 'Post-qualification: documents verified, bid found responsive']);
    BrgyOps::recordAward($p3, ['noa_ref' => 'NOA-LAHUG-2026-004', 'noa_date' => $d(48), 'noa_received_date' => $d(47), 'noa_ack_date' => $d(47), 'contract_amount' => 1408000, 'contract_no' => 'CA-LAHUG-2026-004',
        'contract_signed_date' => $d(44), 'ntp_date' => $d(42), 'ntp_received_date' => $d(42), 'start_date' => $d(40), 'evidence_type' => 'noa'],
        $pdf('noa_lahug_004.pdf', 'Notice of Award NOA-LAHUG-2026-004', 'Rehabilitation of the barangay multi-purpose hall', 'Contract price: PHP 1,408,000.00'));
    $at($dt(47));
    $sec = BrgyOps::saveSecurity($p3, ['security_type' => 'performance', 'form' => 'managers_check', 'amount' => 70400, 'issuer' => 'BDO Unibank', 'reference_no' => 'MC 0098812', 'issue_date' => $d(45),
        'valid_until' => $ahead(410), 'pay_account_id' => $acct('1010'), 'notes' => '5% of the contract price, until final acceptance'],
        $pdf('perf_sec.pdf', "Manager's check MC 0098812", 'Performance security, PHP 70,400.00'));
    BrgyOps::startFulfillment($p3);
    $at($dt(40));
    $P3 = Brgy::mustProject($p3);
    BrgyOps::saveTask($p3, ['task_type' => 'milestone', 'title' => 'Roof works complete', 'assigned_to' => $u('pmgr'), 'due_date' => $d(18), 'status' => 'done', 'pct_complete' => 100]);
    BrgyOps::saveTask($p3, ['task_type' => 'risk', 'title' => 'Rainy season may delay exterior painting', 'details' => 'Schedule exterior painting on dry weeks; extension request ready.', 'assigned_to' => $u('pmgr'), 'status' => 'open']);
    BrgyOps::saveTask($p3, ['task_type' => 'defect', 'title' => 'Leak at the stage area after the first heavy rain', 'assigned_to' => $u('pmgr'), 'due_date' => $d(2), 'status' => 'in_progress', 'pct_complete' => 60]);
    BrgyOps::saveTask($p3, ['task_type' => 'progress', 'title' => 'Week 5: ceiling furring 40% done; electrical roughing-in started', 'status' => 'done']);
    // costs: materials and the subcontractor, charged to the job
    Txn::save('bill', ['txn_date' => $d(35), 'vendor_id' => $vDepot, 'account_id' => Accts::get('ap'), 'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'"), 'due_date' => $d(5),
        'ref_no' => 'CBD-88231', 'memo' => 'Materials for BCP-00003', 'class_id' => $P3['class_id']], [
        ['line_kind' => 'expense', 'account_id' => $acct('6110'), 'amount' => 312000, 'description' => 'GI sheets, purlins, trusses, fiber cement boards, furring', 'tax_code_id' => $vat, 'tax_amount' => 37440, 'customer_id' => $P3['job_customer_id']],
    ]);
    Txn::save('bill', ['txn_date' => $d(14), 'vendor_id' => $vSub, 'account_id' => Accts::get('ap'), 'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'"), 'due_date' => $ahead(16),
        'ref_no' => 'RTCS-0042', 'memo' => 'Labor subcontract, progress 1, BCP-00003', 'class_id' => $P3['class_id']], [
        ['line_kind' => 'expense', 'account_id' => $acct('6110'), 'amount' => 125000, 'description' => 'Roof and ceiling labor (subcontract)', 'tax_code_id' => $vat, 'tax_amount' => 15000, 'customer_id' => $P3['job_customer_id']],
    ]);
    $as('pmgr');
    $pl3 = array_column(Brgy::lines($p3), null, 'line_no');
    $pr1 = BrgyOps::saveDelivery(['project_id' => $p3, 'delivery_date' => $d(15), 'kind' => 'works', 'received_by_name' => 'Engr. Danilo F. Cabrera', 'received_position' => 'Barangay Engineer',
        'notes' => 'Progress report no. 1', 'lines' => [$pl3[1]['id'] => ['qty' => 1, 'remarks' => '100%'], $pl3[2]['id'] => ['qty' => 1, 'remarks' => '100%'], $pl3[3]['id'] => ['qty' => 60, 'remarks' => '60 of 180 sq.m']]]);
    $at($dt(15));
    $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d ORDER BY id', ['d' => $pr1]);
    BrgyOps::recordInspection($pr1, ['inspection_date' => $d(13), 'acceptance_date' => $d(13), 'acceptance_ref' => 'SWA-LAHUG-01', 'inspectors' => 'Engr. Danilo F. Cabrera',
        'inspection_notes' => 'Statement of work accomplished no. 1 certified.', 'lines' => [$dl[0]['id'] => ['accepted' => 1], $dl[1]['id'] => ['accepted' => 1], $dl[2]['id'] => ['accepted' => 60]]],
        $pdf('swa_lahug_01.pdf', 'Statement of Work Accomplished No. 1', 'Certified by: Engr. Danilo F. Cabrera', 'Accomplishment: 42.05%'));
    $at($dt(13));
    $as('billing');
    $b5 = BrgyOps::saveBilling(['project_id' => $p3, 'billing_date' => $d(12), 'billing_type' => 'progress', 'si_no' => '0004541', 'lines' => [$pl3[1]['id'] => 1, $pl3[2]['id'] => 1, $pl3[3]['id'] => 60]]);
    BrgyOps::billingStep($b5, 'submit', 'Progress billing no. 1');
    $as('accountant');
    BrgyOps::billingStep($b5, 'review');
    $as('manager');
    BrgyOps::billingStep($b5, 'approve');
    $as('billing');
    BrgyOps::invoiceBilling($b5);
    foreach (['invoice' => 'Sales invoice 0004541', 'soa' => 'Statement of account, progress 1', 'accomplishment' => 'Statement of work accomplished no. 1', 'photo' => 'Progress photos'] as $t => $title) {
        BrgyDocs::store($pdf($t . '_b5.pdf', $title, 'BCP-00003 Barangay Lahug'), ['scope' => 'project', 'project_id' => $p3, 'doc_type' => $t, 'title' => $title, 'related_type' => 'billing', 'related_id' => $b5, 'status' => 'approved']);
    }
    BrgyDocs::store($pdf('contract_lahug.pdf', 'Contract Agreement CA-LAHUG-2026-004'), ['scope' => 'project', 'project_id' => $p3, 'doc_type' => 'contract', 'title' => 'Signed contract CA-LAHUG-2026-004', 'status' => 'approved']);
    BrgyOps::submitBilling($b5, ['method' => 'physical', 'submitted_at' => $dt(11, '10:00'), 'recipient_name' => 'Rodel P. Alcantara', 'recipient_office' => 'Treasury']);
    BrgyOps::acknowledgeBilling($b5, ['acknowledged_at' => $dt(11, '10:10'), 'received_by' => 'Rodel P. Alcantara, Barangay Treasurer', 'ack_ref' => 'LAH-RCV-0771']);
    $at($dt(11));
    $as('collector');
    $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b5]);
    $exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
    BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b5, 'collection_date' => $d(4), 'method' => 'lddap_ada', 'reference_no' => 'LDDAP-ADA 2026-10-0108', 'deposit_account_id' => $acct('1010'),
        'retention_amount' => (float)$b['retention_expected'], 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'],
        'cash_received' => r2((float)$b['gross_amount'] - (float)$b['retention_expected'] - $exp['ewt'] - $exp['vat']), 'receipt_no' => 'AR-0460']);
    // a change order: 40 sq.m more ceiling and 10 days
    $as('pmgr');
    $co = BrgyOps::saveChangeOrder($p3, ['co_date' => $d(10), 'title' => 'Additional ceiling at the stage area', 'reason' => 'Damaged ceiling found at the stage area after demolition; not in the original plans.',
        'days_extension' => 10, 'lines' => [['project_line_id' => $pl3[3]['id'], 'qty_change' => 40]]]);
    BrgyOps::changeOrderStep($co, 'submit');
    $as('accountant');
    BrgyOps::changeOrderStep($co, 'review');
    $as('manager');
    BrgyOps::changeOrderStep($co, 'approve', 'Within the 10% allowance; entity approval to follow');
    $as('pmgr');
    BrgyOps::applyChangeOrder($co, ['external_ref' => 'VO-LAHUG-2026-01', 'external_date' => $d(7)], $pdf('vo_lahug_01.pdf', 'Variation Order VO-LAHUG-2026-01', 'Additional ceiling 40 sq.m, 10 days extension', 'Approved by: Hon. Antonio G. Ybanez'));
    $at($dt(7));
    $pr2 = BrgyOps::saveDelivery(['project_id' => $p3, 'delivery_date' => $d(2), 'kind' => 'works', 'received_by_name' => 'Engr. Danilo F. Cabrera', 'notes' => 'Progress report no. 2',
        'lines' => [$pl3[3]['id'] => ['qty' => 100, 'remarks' => 'Ceiling continued'], $pl3[4]['id'] => ['qty' => 0.5, 'remarks' => 'Electrical 50%']]]);

    // ---------------------------------------------------------------- BCP-00004: bid in preparation, due in 5 days, papers not in order
    $as('bidofficer');
    $p4 = BrgyOps::saveProject($people + ['title' => 'Supply of medicines and medical supplies for the barangay health station', 'entity_id' => $e['malanday'], 'category' => 'goods', 'mode_id' => $cbid,
        'reference_no' => 'ITB-MAL-2026-012', 'philgeps_ref' => '11902215', 'source_url' => 'https://notices.philgeps.gov.ph/', 'posting_date' => $d(9), 'abc_amount' => 480000,
        'delivery_site' => 'Barangay Health Station, Malanday, Marikina City', 'delivery_days' => 30, 'bid_deadline' => date('Y-m-d', strtotime('+5 days')) . ' 10:00:00', 'warranty_months' => 0,
        'end_user' => 'Barangay Health Station', 'funding_source' => 'Barangay health fund 2026']);
    BrgyOps::goDecision($p4, 'go', 'Medicines line; supplier stocks available');
    BrgyOps::saveLines($p4, [
        ['line_kind' => 'goods', 'item_id' => $item('MED-PCM500'), 'description' => 'Paracetamol 500mg tablets, box of 100', 'unit' => 'box', 'qty' => 600, 'unit_cost' => 180, 'markup_pct' => 38],
        ['line_kind' => 'goods', 'item_id' => $item('MED-ALC500'), 'description' => 'Isopropyl alcohol 70%, 500ml', 'unit' => 'btl', 'qty' => 2400, 'unit_cost' => 55, 'markup_pct' => 40],
    ]);
    BrgyOps::addEvent($p4, ['event_type' => 'clarification', 'event_at' => $dt(1, '15:00'), 'direction' => 'in', 'title' => 'BAC asks bidders to confirm the expiry dating of the medicines (at least 18 months)',
        'party' => 'BAC, Barangay Malanday', 'due_at' => date('Y-m-d', strtotime('+2 days')) . ' 17:00:00', 'details' => 'Reply in writing before the deadline.']);
    $f = BrgyOps::createForm((int)DB::value("SELECT id FROM brgy_templates WHERE code = 'omnibus_sworn_statement'"), $p4);

    // ---------------------------------------------------------------- BCP-00005: lost
    $p5 = BrgyOps::saveProject($people + ['title' => 'Supply of sports equipment for the barangay youth program', 'entity_id' => $e['poblacion'], 'category' => 'goods', 'mode_id' => $svp,
        'reference_no' => 'RFQ-POB-2026-030', 'posting_date' => $d(40), 'abc_amount' => 60000, 'bid_deadline' => $dt(33, '15:00'), 'delivery_days' => 10]);
    BrgyOps::goDecision($p5, 'go', 'Small, quick job');
    BrgyOps::saveLines($p5, [['line_kind' => 'goods', 'item_id' => $item('SPT-BALL'), 'description' => 'Basketball, official size', 'unit' => 'pc', 'qty' => 40, 'unit_cost' => 900, 'unit_price' => 1400, 'tax_code_id' => $vat]]);
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status IN ('missing') AND stage IN ('eligibility', 'bid')", ['p' => $p5]) as $r) {
        if ($r['requirement_type'] === 'mandatory' && !in_array($r['doc_type'], ['philgeps_cert', 'mayors_permit'], true)) {
            BrgyOps::attachRequirement((int)$r['id'], $pdf($r['doc_type'] . '_p5.pdf', $r['name'], 'BCP-00005'), null, ['title' => $r['name']]);
        }
    }
    $as('manager');
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p5]) as $r) {
        BrgyOps::waiveRequirement((int)$r['id'], 'Not required by the RFQ');
    }
    $as('accountant');
    foreach (DB::all("SELECT document_id FROM brgy_project_reqs WHERE project_id = :p AND status = 'submitted'", ['p' => $p5]) as $r) {
        BrgyDocs::review(BrgyDocs::load((int)$r['document_id']), 'approved', '');
    }
    $as('bidofficer');
    BrgyOps::submitBid($p5);
    $as('accountant');
    BrgyOps::bidStep($p5, 'review');
    $as('manager');
    BrgyOps::bidStep($p5, 'approve');
    $as('bidofficer');
    BrgyOps::recordSubmission($p5, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => $dt(34, '14:00'), 'received_by' => 'BAC Secretariat']);
    BrgyOps::recordResult($p5, 'lost', ['lost_reason' => 'Price: lowest quotation was about 6% lower than ours', 'winning_bidder' => 'Makati Sports Center', 'winning_amount' => 52800, 'result_rank' => 2]);
    $at($dt(31));

    // ---------------------------------------------------------------- BCP-00006: new opportunity
    $p6 = BrgyOps::saveProject($people + ['title' => 'Repair and preventive maintenance of the barangay patrol vehicle', 'entity_id' => $e['sanisidro'], 'category' => 'services', 'mode_id' => $svp,
        'reference_no' => 'RFQ-SI-2026-045', 'posting_date' => $d(1), 'abc_amount' => 45000, 'bid_deadline' => date('Y-m-d', strtotime('+9 days')) . ' 15:00:00', 'delivery_days' => 7,
        'notes' => 'Need a partner shop; check whether our service team can do the engine work.']);

    // ---------------------------------------------------------------- BCP-00007: submitted, under evaluation
    $p7 = BrgyOps::saveProject($people + ['title' => 'Supply of relief goods packs for disaster preparedness', 'entity_id' => $e['lahug'], 'category' => 'goods', 'mode_id' => $svp,
        'reference_no' => 'RFQ-LAHUG-2026-019', 'posting_date' => $d(12), 'abc_amount' => 380000, 'bid_deadline' => $dt(4, '10:00'), 'delivery_days' => 7, 'site_id' => $wh]);
    BrgyOps::goDecision($p7, 'go', 'Stock available; quick turnover');
    BrgyOps::saveLines($p7, [['line_kind' => 'goods', 'item_id' => $item('RLF-PACK'), 'description' => 'Relief goods pack: rice 5kg, 6 canned goods, 6 noodles', 'unit' => 'pack', 'qty' => 500, 'unit_cost' => 520, 'unit_price' => 740, 'tax_code_id' => $vat]]);
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p7]) as $r) {
        if ($r['requirement_type'] === 'mandatory') {
            BrgyOps::attachRequirement((int)$r['id'], $pdf($r['doc_type'] . '_p7.pdf', $r['name'], 'BCP-00007'), null, ['title' => $r['name']]);
        }
    }
    $as('manager');
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p7]) as $r) {
        BrgyOps::waiveRequirement((int)$r['id'], 'Not required by the RFQ');
    }
    $as('accountant');
    foreach (DB::all("SELECT document_id FROM brgy_project_reqs WHERE project_id = :p AND status = 'submitted'", ['p' => $p7]) as $r) {
        BrgyDocs::review(BrgyDocs::load((int)$r['document_id']), 'approved', '');
    }
    $as('bidofficer');
    BrgyOps::submitBid($p7);
    $as('accountant');
    BrgyOps::bidStep($p7, 'review');
    $as('manager');
    BrgyOps::bidStep($p7, 'approve');
    $as('bidofficer');
    BrgyOps::recordSubmission($p7, ['sub_type' => 'bid', 'method' => 'courier', 'submitted_at' => $dt(5, '15:00'), 'tracking_no' => 'LBC 1288-5521-09', 'received_by' => 'BAC Secretariat', 'received_at' => $dt(4, '09:10')],
        $pdf('lbc_proof.pdf', 'Courier proof of delivery', 'LBC 1288-5521-09', 'Received: ' . $dt(4, '09:10')));
    BrgyOps::addEvent($p7, ['event_type' => 'bid_opening', 'event_at' => $dt(4, '10:30'), 'direction' => 'in', 'title' => 'Quotations opened: ranked first (370,000.00)']);
    BrgyOps::recordResult($p7, 'rank', ['result_rank' => 1]);
    BrgyOps::addEvent($p7, ['event_type' => 'post_qual', 'event_at' => $dt(3, '14:00'), 'direction' => 'in', 'title' => 'BAC asks for the latest income tax return and a sample pack for post-qualification',
        'due_at' => date('Y-m-d', strtotime('+1 day')) . ' 17:00:00']);
    $at($dt(3));

    // ---------------------------------------------------------------- BCP-00008: closed out
    $p8 = BrgyOps::saveProject($people + ['title' => 'Supply and installation of LED streetlights along M. Santos St.', 'entity_id' => $e['malanday'], 'category' => 'goods', 'mode_id' => $svp,
        'reference_no' => 'RFQ-MAL-2026-007', 'posting_date' => $d(88), 'abc_amount' => 130000, 'bid_deadline' => $dt(82, '10:00'), 'delivery_days' => 10, 'warranty_months' => 12,
        'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 15'")]);
    BrgyOps::goDecision($p8, 'go', 'Lighting partner ready');
    BrgyOps::saveLines($p8, [
        ['line_kind' => 'goods', 'item_id' => $item('LED-SL100'), 'description' => 'LED streetlight 100W with bracket', 'unit' => 'set', 'qty' => 20, 'unit_cost' => 4200, 'unit_price' => 5600, 'tax_code_id' => $vat],
        ['line_kind' => 'service', 'item_id' => $item('SRV-INSTALL'), 'description' => 'Installation on existing poles', 'unit' => 'set', 'qty' => 20, 'unit_cost' => 400, 'unit_price' => 650, 'tax_code_id' => $vat],
    ]);
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p8]) as $r) {
        if ($r['requirement_type'] === 'mandatory') {
            BrgyOps::attachRequirement((int)$r['id'], $pdf($r['doc_type'] . '_p8.pdf', $r['name'], 'BCP-00008'), null, ['title' => $r['name']]);
        }
    }
    $as('manager');
    foreach (DB::all("SELECT * FROM brgy_project_reqs WHERE project_id = :p AND status = 'missing' AND stage IN ('eligibility', 'bid')", ['p' => $p8]) as $r) {
        BrgyOps::waiveRequirement((int)$r['id'], 'Not required by the RFQ');
    }
    $as('accountant');
    foreach (DB::all("SELECT document_id FROM brgy_project_reqs WHERE project_id = :p AND status = 'submitted'", ['p' => $p8]) as $r) {
        BrgyDocs::review(BrgyDocs::load((int)$r['document_id']), 'approved', '');
    }
    $as('bidofficer');
    BrgyOps::submitBid($p8);
    $as('accountant');
    BrgyOps::bidStep($p8, 'review');
    $as('manager');
    BrgyOps::bidStep($p8, 'approve');
    $as('bidofficer');
    BrgyOps::recordSubmission($p8, ['sub_type' => 'bid', 'method' => 'physical', 'submitted_at' => $dt(83, '09:00'), 'received_by' => 'BAC Secretariat']);
    BrgyOps::recordAward($p8, ['noa_ref' => 'PO-MAL-2026-031', 'noa_date' => $d(78), 'noa_received_date' => $d(78), 'contract_amount' => 125000, 'po_no' => 'PO-MAL-2026-031', 'contract_signed_date' => $d(78),
        'evidence_type' => 'po'], $pdf('po_mal_031.pdf', 'Purchase Order PO-MAL-2026-031', 'LED streetlights, PHP 125,000.00'));
    BrgyOps::startFulfillment($p8);
    $P8 = Brgy::mustProject($p8);
    Txn::save('bill', ['txn_date' => $d(75), 'vendor_id' => $vLight, 'account_id' => Accts::get('ap'), 'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'"), 'due_date' => $d(45),
        'ref_no' => 'LLS-2210', 'memo' => 'Streetlights for BCP-00008', 'class_id' => $P8['class_id']], [
        ['line_kind' => 'item', 'item_id' => $item('LED-SL100'), 'description' => 'LED streetlight 100W with bracket', 'qty' => 20, 'rate' => 4200, 'uom_qty' => 20, 'uom_rate' => 4200, 'amount' => 84000,
            'tax_code_id' => $vat, 'tax_amount' => 10080, 'customer_id' => $P8['job_customer_id'], 'class_id' => $P8['class_id']],
    ]);
    $as('pmgr');
    $pl8 = array_column(Brgy::lines($p8), null, 'line_no');
    $dr8 = BrgyOps::saveDelivery(['project_id' => $p8, 'delivery_date' => $d(70), 'kind' => 'goods', 'received_by_name' => 'Kgd. Rowena M. Torres', 'lines' => [$pl8[1]['id'] => ['qty' => 20], $pl8[2]['id'] => ['qty' => 20]]]);
    $dl = DB::all('SELECT * FROM brgy_delivery_lines WHERE delivery_id = :d ORDER BY id', ['d' => $dr8]);
    BrgyOps::recordInspection($dr8, ['acceptance_date' => $d(68), 'acceptance_ref' => 'IAR-MAL-2026-019', 'inspectors' => 'Inspection committee', 'lines' => [$dl[0]['id'] => ['accepted' => 20], $dl[1]['id'] => ['accepted' => 20]]],
        $pdf('iar_mal_019.pdf', 'Inspection and Acceptance Report IAR-MAL-2026-019', '20 streetlights installed and tested'));
    $drNo = (string)DB::value('SELECT dr_no FROM brgy_deliveries WHERE id = :id', ['id' => $dr8]);
    BrgyDocs::store($pdf('dr_mal_1.pdf', 'Delivery Receipt ' . $drNo, 'Received by: Kgd. Rowena M. Torres', 'Date: ' . $d(70)), ['scope' => 'project', 'project_id' => $p8, 'doc_type' => 'dr',
        'title' => 'Signed delivery receipt ' . $drNo, 'doc_no' => $drNo, 'related_type' => 'delivery', 'related_id' => $dr8, 'status' => 'approved']);
    $as('billing');
    $b8 = BrgyOps::saveBilling(['project_id' => $p8, 'billing_date' => $d(67), 'billing_type' => 'final', 'si_no' => '0004490', 'lines' => [$pl8[1]['id'] => 20, $pl8[2]['id'] => 20]]);
    BrgyOps::billingStep($b8, 'submit');
    $as('accountant');
    BrgyOps::billingStep($b8, 'review');
    $as('manager');
    BrgyOps::billingStep($b8, 'approve');
    $as('billing');
    BrgyOps::invoiceBilling($b8);
    foreach (['invoice' => 'Sales invoice 0004490', 'soa' => 'Statement of account'] as $t => $title) {
        BrgyDocs::store($pdf($t . '_b8.pdf', $title, 'BCP-00008'), ['scope' => 'project', 'project_id' => $p8, 'doc_type' => $t, 'title' => $title, 'related_type' => 'billing', 'related_id' => $b8, 'status' => 'approved']);
    }
    BrgyOps::submitBilling($b8, ['method' => 'physical', 'submitted_at' => $dt(66, '10:00'), 'recipient_name' => 'Nora A. Pascual']);
    BrgyOps::acknowledgeBilling($b8, ['acknowledged_at' => $dt(66, '10:05'), 'received_by' => 'Nora A. Pascual, Barangay Treasurer']);
    $as('collector');
    $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $b8]);
    $exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
    $c8 = BrgyOps::saveCollection(['kind' => 'billing', 'billing_id' => $b8, 'collection_date' => $d(45), 'method' => 'check', 'check_no' => '0005521', 'check_date' => $d(46), 'bank_name' => 'Land Bank of the Philippines',
        'cash_received' => r2((float)$b['gross_amount'] - $exp['ewt'] - $exp['vat']), 'ewt_amount' => $exp['ewt'], 'vatwh_amount' => $exp['vat'], 'receipt_no' => 'AR-0438', 'cert_2307' => 1]);
    // the check goes to the bank with the day's other collections
    $as('admin');
    $pay8 = (int)DB::value('SELECT payment_txn_id FROM brgy_collections WHERE id = :id', ['id' => $c8]);
    $pt = Txn::load($pay8);
    Txn::save('deposit', ['txn_date' => $d(44), 'account_id' => $acct('1010'), 'memo' => 'Deposit of check 0005521 (BCP-00008)'], [
        ['line_kind' => 'deposit', 'account_id' => (int)$pt['account_id'], 'amount' => (float)$pt['total'], 'ref_txn_id' => $pay8, 'customer_id' => $pt['customer_id'], 'description' => 'Check 0005521, Barangay Malanday'],
    ]);
    $as('manager');
    BrgyOps::closeout($p8, 'Delivered, accepted, billed and collected in full. Warranty of 12 months runs from acceptance; no retention was deducted.', true);
    $at($dt(30));

    // a delegation: the approver's alternate while on leave next month
    DB::insert('brgy_delegations', ['from_user_id' => $u('manager'), 'to_user_id' => $u('admin'), 'scope' => 'approve', 'valid_from' => $ahead(20), 'valid_to' => $ahead(27),
        'reason' => 'Annual leave of Victor Aquino', 'created_by' => $u('manager')]);

    brgy_demo_dates($runStart);
    // the transactions that follow the company's SOP (tools/demo_brgy_sop.php)
    require_once __DIR__ . '/demo_brgy_sop.php';
    brgy_demo_sop();
    Settings::set('brgy_gate_deadline', $gate);
    Auth::actAs($u('admin'));
}

/**
 * The sample records are made in a few seconds; this dates what was filed and noted during the run to the day it stands
 * for (a paper on its record's date, a requirement paper before the bid went in, a project's first note on its posting),
 * so the timelines read in order.
 */
function brgy_demo_dates(string $runStart): void
{
    $stamp = fn(?string $date, string $time) => $date ? substr($date, 0, 10) . ' ' . $time : null;
    foreach (DB::all('SELECT d.*, p.posting_date, p.bid_submitted_at, p.bid_deadline, p.noa_received_date FROM brgy_documents d LEFT JOIN brgy_projects p ON p.id = d.project_id WHERE d.uploaded_at >= :s', ['s' => $runStart]) as $d) {
        $rid = (int)$d['related_id'];
        $when = match ((string)$d['related_type']) {
            'delivery' => $stamp(DB::value('SELECT COALESCE(acceptance_date, delivery_date) FROM brgy_deliveries WHERE id = :i', ['i' => $rid]), '16:00:00'),
            'billing' => $stamp(DB::value('SELECT billing_date FROM brgy_billings WHERE id = :i', ['i' => $rid]), '15:30:00'),
            'collection' => $stamp(DB::value('SELECT collection_date FROM brgy_collections WHERE id = :i', ['i' => $rid]), '14:00:00'),
            'security' => $stamp(DB::value('SELECT issue_date FROM brgy_securities WHERE id = :i', ['i' => $rid]), '11:00:00'),
            'change_order' => $stamp(DB::value('SELECT external_date FROM brgy_change_orders WHERE id = :i', ['i' => $rid]), '11:00:00'),
            'award' => $stamp($d['noa_received_date'], '09:30:00'),
            default => null,
        };
        if (!$when && $d['scope'] === 'project') {
            $usedBy = DB::value('SELECT stage FROM brgy_project_reqs WHERE document_id = :d LIMIT 1', ['d' => $d['id']]);
            $bidDay = $d['bid_submitted_at'] ?: $d['bid_deadline'];
            if ($usedBy && in_array($usedBy, ['eligibility', 'bid'], true) && $bidDay) {
                $when = date('Y-m-d 15:00:00', strtotime(substr((string)$bidDay, 0, 10) . ' -2 days'));
            } elseif ($d['issue_date']) {
                $when = $stamp($d['issue_date'], '16:00:00');
            } elseif ($d['posting_date']) {
                $when = $stamp($d['posting_date'], '15:00:00');
            }
        } elseif (!$when && $d['issue_date']) {
            $when = $stamp($d['issue_date'], '16:00:00');
        }
        if ($when && $when < date('Y-m-d H:i:s')) {
            DB::run('UPDATE brgy_documents SET uploaded_at = :w, reviewed_at = CASE WHEN reviewed_at IS NULL THEN NULL WHEN reviewed_at < :w2 THEN :w3 ELSE reviewed_at END WHERE id = :id',
                ['w' => $when, 'w2' => $when, 'w3' => $when, 'id' => $d['id']]);
        }
    }
    // notes made by the steps themselves: on the day of the step they record
    foreach (DB::all('SELECT e.*, p.bid_submitted_at FROM brgy_events e JOIN brgy_projects p ON p.id = e.project_id WHERE e.event_at >= :s AND e.title <> :t', ['s' => $runStart, 't' => 'Opportunity recorded']) as $e) {
        $when = null;
        if (str_starts_with($e['title'], 'Bid approved')) {
            $when = DB::value("SELECT MAX(created_at) FROM brgy_workflow WHERE object_type = 'bid' AND object_id = :p AND action = 'approve'", ['p' => $e['project_id']]);
        } elseif (str_starts_with($e['title'], 'Check returned')) {
            $when = $stamp(DB::value("SELECT MAX(bounce_date) FROM brgy_collections WHERE project_id = :p AND status = 'bounced'", ['p' => $e['project_id']]), '15:00:00');
        } elseif ($e['bid_submitted_at']) {
            $when = date('Y-m-d 16:00:00', strtotime(substr((string)$e['bid_submitted_at'], 0, 10) . ' +6 days'));
        }
        if ($when && $when < date('Y-m-d H:i:s')) {
            DB::run('UPDATE brgy_events SET event_at = :w WHERE id = :id', ['w' => $when, 'id' => $e['id']]);
        }
    }
    // the first note of each project ("Opportunity recorded") on its posting date, and the project itself
    foreach (DB::all('SELECT id, posting_date FROM brgy_projects WHERE posting_date IS NOT NULL') as $p) {
        DB::run('UPDATE brgy_events SET event_at = :w WHERE project_id = :p AND event_at >= :s AND title = :t', ['w' => $p['posting_date'] . ' 09:00:00', 'p' => $p['id'], 's' => $runStart, 't' => 'Opportunity recorded']);
        DB::run('UPDATE brgy_projects SET created_at = :w WHERE id = :p', ['w' => $p['posting_date'] . ' 09:00:00', 'p' => $p['id']]);
    }
}
