<?php
declare(strict_types=1);

/**
 * Version 38: checks of the requests of the Excel file v6.
 *   1. The City/Municipality List: added, changed, deleted only when no customer uses it; chosen on the customer form.
 *   2. The form's Signatory and Position kept the same as the signature that carries that name in the page.
 *   3. and 4. Undo and redo on every form; what is filled into the blanks stays a field that can be changed.
 *   5. What is filled into a blank on one form (the purchase order no. and date) fills the transaction's next forms (the IAR).
 *   6. The signatories (names and positions) of every form listed in the Fill in the blanks panel.
 *
 *   php tools/test_v38.php --yes [--company=N]
 *
 * Everything it does runs in one database transaction that is rolled back at the end, so nothing it makes is kept.
 * Still, run it on a copy of the database or on the demo database, never on the books of a company in use.
 * The page itself (undo, redo, the panel) is checked in the browser; here its files are checked for what it needs.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This adds test cities and test forms. Run it on a copy of the database: php tools/test_v38.php --yes\n");
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
$pid = (int)DB::value("SELECT id FROM brgy_projects WHERE status IN ('open', 'awarded') AND (po_no IS NULL OR po_no = '') AND (iar_no IS NULL OR iar_no = '') ORDER BY id LIMIT 1");
$bidPid = (int)DB::value("SELECT id FROM brgy_projects WHERE status IN ('open', 'awarded') ORDER BY process = 'bidding' DESC, id LIMIT 1");
if (!$pid || !$bidPid) {
    fwrite(STDERR, "No open or awarded transaction without a PO no. to try the forms on. Run it on the demo database.\n");
    exit(2);
}
$tpl = fn(string $c) => (int)DB::value('SELECT id FROM brgy_templates WHERE code = :c', ['c' => $c]);

DB::begin();
try {
    echo "Version 38 checks\n\n1. The City/Municipality List\n";
    $ok('The list, the customer\'s city and the page are there', Cities::ready() && isset(Access::allPageDefs()['cust.cities'])
        && (int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'cust.cities'") === 1);
    $ok('... given to the roles that open the Customer Center', (int)DB::value("SELECT COUNT(*) FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key = 'cust.cities' AND a.allowed = 1")
        >= (int)DB::value("SELECT COUNT(*) FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key = 'cust.center' AND a.allowed = 1"));
    $cid = DB::insert('cities', ['name' => 'Cordova', 'province' => 'Cebu', 'zip_code' => '6017', 'created_by' => $admin]);
    $ok('Added: "Cordova, Cebu" offered on the customer form, with its zip code', in_array('Cordova, Cebu', array_map([Cities::class, 'label'], Cities::active()), true)
        && Cities::find('Cordova', 'Cebu') === $cid && (string)Cities::get($cid)['zip_code'] === '6017');
    DB::update('cities', ['is_active' => 0], 'id = :id', ['id' => $cid]);
    $ok('Made inactive: no longer offered, except to a customer that has it', !in_array($cid, array_map(fn($c) => (int)$c['id'], Cities::active()), true)
        && in_array($cid, array_map(fn($c) => (int)$c['id'], Cities::active($cid)), true));
    $cust = (int)DB::value('SELECT id FROM customers WHERE parent_id IS NULL ORDER BY id LIMIT 1');
    DB::update('customers', ['city_id' => $cid], 'id = :id', ['id' => $cust]);
    $why = $refusal(fn() => DB::run('DELETE FROM cities WHERE id = :id', ['id' => $cid]));
    $ok('A city a customer uses cannot be deleted (the database refuses it too)', $why !== '' && (int)DB::value('SELECT COUNT(*) FROM cities WHERE id = :id', ['id' => $cid]) === 1, $why);
    $page = file_get_contents(APP_ROOT . '/modules/customers/cities.php');
    $ok('... and the list page refuses it, naming the customers, before trying', str_contains($page, 'cannot be deleted: ') && str_contains($page, "SELECT COUNT(*) FROM customers WHERE city_id = :id"));
    DB::update('customers', ['city_id' => null], 'id = :id', ['id' => $cust]);
    DB::run('DELETE FROM cities WHERE id = :id', ['id' => $cid]);
    $ok('Not used any more: deleted', (int)DB::value('SELECT COUNT(*) FROM cities WHERE id = :id', ['id' => $cid]) === 0);
    $center = file_get_contents(APP_ROOT . '/modules/customers/center.php');
    $ok('Customer form: the City/Municipality box, its "Edit the list", the card, the search and the export', str_contains($center, 'name="city_id"') && str_contains($center, "url('cust.cities')")
        && str_contains($center, 'City/Municipality:') && str_contains($center, "'City/Municipality', 'Province'") && str_contains($center, 'cities WHERE name LIKE :q'));
    $town = DB::row('SELECT * FROM cities WHERE is_active = 1 ORDER BY id LIMIT 1');
    $ok('Import from CSV (Customers) takes the city by its name, or as the list shows it ("Name, Province")', isset(Importer::types()['customers']['columns']['city'])
        && Importer::ref('cities', (string)$town['name']) === (int)$town['id'] && Importer::ref('cities', Cities::label($town)) === (int)$town['id'] && Importer::ref('cities', 'No Such Town') === null);
    $ok('Custom reports have the customer\'s city', str_contains(file_get_contents(APP_ROOT . '/app/ReportFields.php'), "'city'        => ['City/Municipality'"));
    $ok('Customers of the procuring entities took the entity\'s city', (int)DB::value("SELECT COUNT(*) FROM customers c JOIN brgy_entities e ON e.customer_id = c.id WHERE c.city_id IS NULL AND TRIM(COALESCE(e.city_municipality, '')) <> ''") === 0);

    echo "\n2. The form's Signatory and Position, and the signature in the page\n";
    $oss = (int)BrgyOps::createForm($tpl('omnibus_sworn_statement'), $bidPid);
    $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $oss]);
    $ent = BrgyDocs::sigEntries($f['body']);
    $ok('Omnibus sworn statement: the affiant found in the page with "Affiant" under the name', count($ent) === 1 && $ent[0]['name'] === Brgy::setting('brgy_signatory_name') && $ent[0]['pos'] === 'Affiant', json_encode($ent));
    $ok('... so the form\'s Position is "Affiant", the same as the page', $f['signatory_position'] === 'Affiant', (string)$f['signatory_position']);
    $ok('A form whose signature is not the signatory keeps the position of Contracting Settings', BrgyOps::formSignatoryPosition('<p>No signature</p>', 'Juan P. Dela Cruz', 'Manager') === 'Manager');
    $edit = file_get_contents(APP_ROOT . '/modules/brgy/forms.php');
    $js = file_get_contents(APP_ROOT . '/assets/js/brgy.js');
    $ok('The page keeps them the same, both ways (the boxes beside the page and the signature)', str_contains($edit, 'id="f_signatory_name"') && str_contains($edit, 'id="sigLinkNote"')
        && str_contains($js, 'sideName.addEventListener(\'input\'') && str_contains($js, 'const sideFromPage'));

    echo "\n3. and 4. Undo and redo on every form; filled blanks stay changeable\n";
    $ok('Undo and Redo on the toolbar of the forms and the templates, and on the Fill in the blanks panel', substr_count($edit, 'data-hist="undo"') === 2 && substr_count($edit, 'data-hist="redo"') === 2
        && str_contains(file_get_contents(APP_ROOT . '/modules/brgy/templates.php'), 'data-hist="undo"'));
    $ok('... with the page\'s own history (Ctrl+Z, Ctrl+Y, the browser\'s Edit > Undo) that keeps the panel, the signatory and the DV boxes', str_contains($js, "inputType === 'historyUndo'")
        && str_contains($js, 'const goTo = ') && str_contains($js, "'[data-hist-field]'") && str_contains($edit, 'data-hist-field'));
    $ok('A filled blank stays a field of the page (span.ph-set), listed in the panel to change', str_contains($js, "s.className = 'ph-set'") && str_contains($js, "$$('.ph-missing, .ph-set', editor)"));
    $html = '<p>PO No. <span class="ph-set" data-ph="po.no" title="Purchase order no.">4454675</span>, date <span class="ph-missing" data-ph="po.date">[Purchase order date]</span></p>';
    $fv = BrgyDocs::formValues($html);
    $ok('Read back from the page: the filled field and the blank one', $fv['set'] === ['po.no' => '4454675'] && $fv['blank'] === ['po.date'], json_encode($fv));
    $ok('A filled field is not a blank for the review (only the ones still blank are)', BrgyDocs::gaps($html) === ['Purchase order date'], json_encode(BrgyDocs::gaps($html)));
    $ok('It prints as plain text (marked only while editing)', str_contains(file_get_contents(APP_ROOT . '/assets/css/app.css'), '.formdoc.is-editing .ph-set'));

    echo "\n5. The purchase order no. and date filled on the purchase order fill the IAR\n";
    $iarBefore = (int)BrgyOps::createForm($tpl('inspection_acceptance_report'), $pid);
    $po = (int)BrgyOps::createForm($tpl('purchase_order'), $pid);
    $poRow = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $po]);
    $ok('The purchase order is made with the PO no. and date blank', in_array('Purchase order no.', BrgyDocs::gaps($poRow['body']), true) && in_array('Purchase order date', BrgyDocs::gaps($poRow['body']), true));
    $fill = fn(string $body, array $vals) => (string)preg_replace_callback('/<span class="ph-missing" data-ph="([a-z0-9_.]+)">\[([^\]]*)\]<\/span>/', fn($m) => isset($vals[$m[1]])
        ? '<span class="ph-set" data-ph="' . $m[1] . '" title="' . $m[2] . '">' . h($vals[$m[1]]) . '</span>' : $m[0], $body);
    $poBody = $fill($poRow['body'], ['po.no' => '4454675', 'po.date' => '11/23/2026', 'date.today' => '11/25/2026']);
    BrgyOps::saveForm($po, ['body' => $poBody, 'title' => $poRow['title'], 'signatory_name' => $poRow['signatory_name'], 'signatory_position' => $poRow['signatory_position'],
        'paper' => $poRow['paper'], 'orientation' => $poRow['orientation']], $poRow['lock_version']);
    $sv = BrgyDocs::storedValues($pid);
    $ok('Saved: the PO no. and date are kept for the transaction, from the purchase order', ($sv['po.no']['v'] ?? '') === '4454675' && ($sv['po.date']['v'] ?? '') === '11/23/2026'
        && $sv['po.no']['from'] === $poRow['form_no'], json_encode($sv));
    $ok('... a date of the day and the DV amounts are not kept (each form has its own)', !isset($sv['date.today']) && !BrgyDocs::storable('dv.gross') && !BrgyDocs::storable('delivery.received_by') && BrgyDocs::storable('iar.no'));
    $iar = (int)BrgyOps::createForm($tpl('inspection_acceptance_report'), $pid);
    $iarBody = (string)DB::value('SELECT body FROM brgy_forms WHERE id = :id', ['id' => $iar]);
    $ok('An IAR made next: its PO no. and date are filled, as fields that can still be changed', str_contains($iarBody, 'data-ph="po.no" title="Purchase order no.">4454675<')
        && str_contains($iarBody, 'data-ph="po.date" title="Purchase order date">11/23/2026<') && !in_array('Purchase order no.', BrgyDocs::gaps($iarBody), true));
    $ok('An IAR made before: the page offers the values to fill its blanks on opening (the edit page has them)', isset(BrgyDocs::storedValues($pid, $iarBefore)['po.no'])
        && str_contains($edit, 'data-stored=') && str_contains($js, "gapPanel.dataset.stored"));
    $ok('... except to the purchase order itself', BrgyDocs::storedValues($pid, $po) === []);
    $p = Brgy::mustProject($pid);
    $ok('The transaction\'s own records come first (its PO no. once recorded)', BrgyDocs::context(['po_no' => 'PO-REC-1'] + $p, null, '', BrgyLayout::layout(null))['po.no'] === 'PO-REC-1');
    $poRow = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $po]);
    BrgyOps::saveForm($po, ['body' => str_replace('title="Purchase order no.">4454675</span>', '>[Purchase order no.]</span>', str_replace('<span class="ph-set" data-ph="po.no"', '<span class="ph-missing" data-ph="po.no"', $poRow['body'])),
        'title' => $poRow['title'], 'signatory_name' => $poRow['signatory_name'], 'signatory_position' => $poRow['signatory_position'], 'paper' => $poRow['paper'], 'orientation' => $poRow['orientation']], $poRow['lock_version']);
    $ok('Made blank again on the purchase order: forgotten for the next forms', !isset(BrgyDocs::storedValues($pid)['po.no']) && isset(BrgyDocs::storedValues($pid)['po.date']));

    echo "\n6. The signatories in the Fill in the blanks panel\n";
    $sig = fn(string $code, int $proj) => BrgyDocs::sigEntries((string)DB::value('SELECT body FROM brgy_forms WHERE id = :id', ['id' => (int)BrgyOps::createForm($tpl($code), $proj)]));
    $e = BrgyDocs::sigEntries($iarBody);
    $ok('IAR: the inspection officer and the supply custodian, with their positions', count($e) === 2 && $e[0]['pos'] === 'Inspection Officer / Inspection Committee' && $e[1]['pos'] === 'Supply and/or Property Custodian', json_encode($e));
    $ok('Purchase order: the punong barangay and the supplier\'s conforme', count(BrgyDocs::sigEntries($poRow['body'])) >= 1, json_encode(BrgyDocs::sigEntries($poRow['body'])));
    $ris = $sig('requisition_issue_slip', $pid);
    $ok('Requisition and issue slip: 4 signatories (requested, approved, received, issued)', count($ris) === 4 && $ris[1]['pos'] === 'Punong Barangay' && $ris[3]['pos'] === 'Barangay Treasurer', json_encode($ris));
    $dv = $sig('disbursement_voucher', $pid);
    $ok('Disbursement voucher: 6 signatories (A, B, C, D, prepared by, approved by)', count($dv) === 6 && $dv[0]['pos'] === 'Chairman, Committee on Appropriations' && $dv[3]['pos'] === 'Signature Over Printed Name', json_encode($dv));
    $tf = $sig('txn_form', $pid);
    $ok('Transaction form: the person who prepared it', count($tf) === 1, json_encode($tf));
    $st = $sig('statement_ongoing_contracts', $bidPid);
    $ok('Statement of ongoing contracts: submitted by, with the designation', count($st) === 1 && $st[0]['pos'] === Brgy::setting('brgy_signatory_position'), json_encode($st));
    $ok('Older templates with a signature table (delivery receipt, contracts): their name lines and captions', count($sig('delivery_receipt', $pid)) >= 2);
    $ok('The five templates were marked by the migration with a version kept', (int)DB::value("SELECT COUNT(*) FROM brgy_template_versions WHERE change_note LIKE 'Version 38:%'") >= 5);
    $ok('The panel lists them with a name and a position each', str_contains($edit, 'id="sigList"') && str_contains($js, 'const sigEntries') && str_contains($js, '<div class="fsig-no">Signatory \' + (i + 1)') && str_contains($js, 'Position</label><textarea'));
} finally {
    DB::rollBack();
}
echo "\n" . $pass . ' passed, ' . count($failures) . " failed (everything rolled back)\n";
foreach ($failures as $f) {
    echo '  - ' . $f . "\n";
}
exit($failures ? 1 : 0);
