<?php
declare(strict_types=1);

/**
 * Version 37: checks of the requests of the Excel file v5.
 *   1. The estimates and targets of the transaction form, worked out as the sample transaction of the file
 *      (gross 50,000.00, withholding tax 6%, SOP 2%, profit 15%, receipt deduction 3%: net to release 37,621.43).
 *   2. The omnibus sworn statement and 3. the bid securing declaration as whole forms whose text, header, footer and
 *      affiant are changed on the template, like the other forms.
 *
 *   php tools/test_v37.php --yes [--company=N]
 *
 * Everything it does runs in one database transaction that is rolled back at the end, so nothing it makes is kept.
 * Still, run it on a copy of the database or on the demo database, never on the books of a company in use.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This saves a test transaction and test forms. Run it on a copy of the database: php tools/test_v37.php --yes\n");
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
$near = fn($a, float $b) => $a !== null && abs((float)$a - $b) < 0.005;
$admin = (int)(DB::value("SELECT id FROM users WHERE username = 'admin'") ?: DB::value("SELECT MIN(id) FROM users WHERE status = 'active'"));
Auth::actAs($admin);
// a transaction not yet paid, so the printed transaction form shows the estimates (the taxes actually withheld come first)
$pid = (int)DB::value("SELECT id FROM brgy_projects WHERE status IN ('open', 'awarded') ORDER BY status = 'open' DESC, bid_status IN ('draft', 'returned') DESC, id LIMIT 1");
if (!$pid) {
    fwrite(STDERR, "No open or awarded transaction to try the forms on. Run it on the demo database.\n");
    exit(2);
}

DB::begin();
try {
    $p = Brgy::mustProject($pid);
    $jl = (int)DB::value('SELECT COUNT(*) FROM journal_lines') + (int)DB::value('SELECT COUNT(*) FROM brgy_postings');
    // the rates of the sample are on the drop-downs (Contracting Settings), the divisor and the base at their defaults
    Settings::set('brgy_est_wt_rates', '1,4,5,6,7');
    Settings::set('brgy_est_profit_rates', '10,13,15');
    Settings::set('brgy_est_receipt_rates', '3');
    Settings::set('brgy_est_wt_divisor', '1.12');
    Settings::set('brgy_est_receipt_base', '80');

    echo 'Version 37 checks on ' . $p['project_no'] . "\n\n1. The estimates and targets (the sample transaction of the Excel file)\n";
    // saved as the transaction form saves it: everything the transaction has, with the estimates typed
    $save = function (array $est) use ($pid) {
        $p = Brgy::mustProject($pid);
        BrgyOps::saveProject($est + $p, $pid, $p['lock_version']);
        return Brgy::estimates(Brgy::mustProject($pid));
    };
    $e = $save(['est_gross' => '50,000.00', 'est_wt_rate' => '6', 'est_profit_rate' => '15', 'est_receipt_rate' => '3', 'est_sop_kind' => 'pct', 'est_sop' => '2']);
    $ok('Withholding tax 6% = 50,000 / 1.12 x 0.06 = 2,678.57', $near($e['wt'], 2678.57), (string)$e['wt']);
    $ok('Cheque amount = 50,000 - 2,678.57 = 47,321.43', $near($e['cheque'], 47321.43), (string)$e['cheque']);
    $ok('SOP 2% of the gross = 50,000 x 0.02 = 1,000.00, kept as a %', $near($e['sop'], 1000) && $near($e['sop_rate'], 2) && Brgy::mustProject($pid)['est_sop'] === null, json_encode([$e['sop'], $e['sop_rate']]));
    $ok('Profit 15% = 50,000 x 0.15 = 7,500.00', $near($e['profit'], 7500), (string)$e['profit']);
    $ok('Receipt deduction 3% = 50,000 x 0.80 x 0.03 = 1,200.00', $near($e['receipt'], 1200), (string)$e['receipt']);
    $ok('Amount to release = 50,000 - tax - SOP - 15% - 3% = 37,621.43', $near($e['release'], 37621.43), (string)$e['release']);
    $e = $save(['est_gross' => '50,000.00', 'est_wt_rate' => '6', 'est_profit_rate' => '15', 'est_receipt_rate' => '3', 'est_sop_kind' => 'amount', 'est_sop' => '1,000.00']);
    $ok('SOP typed as an amount (1,000.00): the same 37,621.43, no % kept', $near($e['sop'], 1000) && $e['sop_rate'] === null && $near($e['release'], 37621.43), json_encode([$e['sop'], $e['sop_rate'], $e['release']]));
    $why = $refusal(fn() => $save(['est_gross' => '50,000.00', 'est_sop_kind' => 'pct', 'est_sop' => '120']));
    $ok('An SOP of 120% of the gross is refused', str_contains($why, 'percentage of the gross'), $why);
    $e = $save(['est_gross' => '50,000.00', 'est_wt_rate' => '6', 'est_profit_rate' => '15', 'est_receipt_rate' => '3', 'est_sop_kind' => 'pct', 'est_sop' => '2']);
    $ctx = BrgyDocs::context(Brgy::mustProject($pid));
    $ok('The printed transaction form: tax 2,678.57, cheque 47,321.43, SOP 1,000.00 (2%), receipt 1,200.00, release 37,621.43',
        $ctx['txn.wht'] === '₱2,678.57' && $ctx['txn.cheque_amount'] === ((float)$p['cheque_amount'] > 0 ? $ctx['txn.cheque_amount'] : '₱47,321.43') && $ctx['txn.sop'] === '₱1,000.00'
        && $ctx['txn.sop_rate'] === '2%' && $ctx['txn.receipt_deduction'] === '₱1,200.00' && $ctx['txn.amount_to_release'] === '₱37,621.43',
        implode(' / ', [$ctx['txn.wht'], $ctx['txn.cheque_amount'], $ctx['txn.sop'], $ctx['txn.sop_rate'], $ctx['txn.receipt_deduction'], $ctx['txn.amount_to_release']]));
    Settings::set('brgy_est_wt_divisor', '1');
    Settings::set('brgy_est_receipt_base', '100');
    $e = Brgy::estimates(Brgy::mustProject($pid));
    $ok('Contracting Settings: divisor 1 and 100% of the gross give 3,000.00 and 1,500.00', $near($e['wt'], 3000) && $near($e['receipt'], 1500), json_encode([$e['wt'], $e['receipt']]));
    Settings::set('brgy_est_wt_divisor', '1.12');
    Settings::set('brgy_est_receipt_base', '80');
    $form = file_get_contents(APP_ROOT . '/modules/brgy/projects.php') . file_get_contents(APP_ROOT . '/assets/js/brgy.js');
    $ok('The transaction form: SOP as an amount or a % of the gross, worked out on the page the same way',
        str_contains($form, 'name="est_sop_kind"') && str_contains($form, 'gross / div * wtR / 100') && str_contains($form, 'gross * rcBase / 100 * rcR / 100') && str_contains($form, 'cheque - sop - profit - receipt'));
    $ok('No accounting entry from the estimates', (int)DB::value('SELECT COUNT(*) FROM journal_lines') + (int)DB::value('SELECT COUNT(*) FROM brgy_postings') === $jl);

    echo "\n2. and 3. The omnibus sworn statement and the bid securing declaration\n";
    foreach (['brgy_signatory_name' => 'Jerome T. Cuizon', 'brgy_signatory_position' => 'Proprietor', 'brgy_signatory_civil_status' => 'married', 'brgy_signatory_citizenship' => 'Filipino',
                 'brgy_signatory_residence' => 'Cuizon Compound, Bankal, Lapu-Lapu City, Cebu', 'brgy_signatory_id_type' => 'SSS ID', 'brgy_signatory_id_no' => '06-1474911-3',
                 'brgy_notary_place' => 'Lapu-Lapu City, Cebu'] as $k => $v) {
        Settings::set($k, $v);
    }
    $tpl = fn(string $code) => DB::row('SELECT * FROM brgy_templates WHERE code = :c', ['c' => $code]);
    $oss = $tpl('omnibus_sworn_statement');
    $bsd = $tpl('bid_securing_declaration');
    $ok('Both are whole forms now, not prescribed shells', $oss && $bsd && (int)$oss['is_prescribed'] === 0 && (int)$bsd['is_prescribed'] === 0
        && !str_contains($oss['body'] . $bsd['body'], '[OFFICIAL TEXT OF THE FORM]') && $oss['category'] === 'proforma' && $bsd['category'] === 'proforma');
    $ok('... the older shell kept as a version to put back', (int)DB::value('SELECT COUNT(*) FROM brgy_template_versions WHERE template_id IN (:a, :b)', ['a' => $oss['id'], 'b' => $bsd['id']]) >= 4);
    $L = BrgyLayout::layout($oss);
    $ok('Header of its own: "Omnibus Sworn Statement" over a rule; the affiant from the Format tab; a footer to set', $L['lh_style'] === 'own' && str_contains($L['lh_lines'], 'Omnibus Sworn Statement')
        && $L['lh_rule'] > 0 && count($L['signatories']) === 1 && $L['signatories'][0]['pos'] === 'Affiant' && $L['signatories'][0]['name'] === '{{signatory.name}}' && array_key_exists('footer', $L));
    $L2 = BrgyLayout::layout($bsd);
    $ok('Bid securing declaration: "Bid Securing Declaration" and the project title over a rule', str_contains($L2['lh_lines'], 'Bid Securing Declaration') && str_contains($L2['lh_lines'], '{{project.title}}'));

    $fid = (int)BrgyOps::createForm((int)$oss['id'], $pid);
    $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]);
    $bidder = mb_strtoupper(trim((string)(Brgy::mustProject($pid)['tradename'] ?: Brgy::defaultTradename())));
    $txt = html_entity_decode(strip_tags($f['body']));
    $ok('Omnibus sworn statement made for the transaction: the affiant and his details', str_contains($txt, 'I, Jerome T. Cuizon, of legal age, married, Filipino, and with residence at Cuizon Compound, Bankal, Lapu-Lapu City, Cebu'));
    $ok('... the bidder (the tradename of the transaction) in items 1, 2, 3, 5, 8, 9, 10 and 11', substr_count($txt, $bidder) >= 8, $bidder . ' x ' . substr_count($txt, $bidder));
    $ok('... the 11 items, a) to d) and the RA 12009 IRR', substr_count($f['body'], '<li') === 11 && str_contains($txt, 'd)') && str_contains($txt, 'Section 100 of the Implementing Rules and Regulations (IRR) of Republic Act (RA) No. 12009'));
    $ok('... the project and the entity', str_contains($txt, Brgy::mustProject($pid)['title']) && str_contains($txt, 'for the bidding of the'));
    $ok('... the jurat: place, ID and the series of this year', str_contains($txt, 'at Lapu-Lapu City, Cebu, Philippines') && str_contains($txt, 'SSS ID No. 06-1474911-3') && str_contains($txt, 'Series of ' . date('Y') . '.'));
    $ok('... the header and the affiant are on the form', str_contains($f['body'], 'Omnibus Sworn Statement') && str_contains($f['body'], 'Affiant') && str_contains($f['body'], 'Jerome T. Cuizon'));
    $ok('... a form that is not a prescribed shell, on Legal paper', (int)$f['is_prescribed'] === 0 && $f['paper'] === 'Legal');
    $gaps = BrgyDocs::gaps($f['body']);
    $ok('... no blank but the representative for the bidding when none is named', !array_diff($gaps, ['Authorized representative (bidding)']), implode(', ', $gaps));

    $fid2 = (int)BrgyOps::createForm((int)$bsd['id'], $pid);
    $txt2 = html_entity_decode(strip_tags((string)DB::value('SELECT body FROM brgy_forms WHERE id = :id', ['id' => $fid2])));
    $ok('Bid securing declaration made: To the entity, the 3 items, a) to d), the affiant and the jurat', str_contains($txt2, 'To: ') && str_contains($txt2, 'I/We, the undersigned, declare that:')
        && str_contains($txt2, 'Sections 52.2 (a), 63.2, 69.1 and 100, except 100.3 (c)') && str_contains($txt2, 'said right;') && str_contains($txt2, 'Jerome T. Cuizon') && str_contains($txt2, 'Affiant')
        && str_contains($txt2, 'SSS ID No. 06-1474911-3'));

    // the Format tab: a second affiant, another header and a footer, as saved from the template (templates.php saves fromPost)
    $L = BrgyLayout::fromPost(['paper' => 'A4', 'lh_style' => 'own', 'lh_left' => 'none', 'lh_right' => 'none', 'lh_lines' => "# SWORN STATEMENT OF {{company.bidder_upper}}", 'lh_align' => 'center', 'lh_rule' => 2,
        'footer' => '**Omnibus sworn statement** of the bidder', 'footer_align' => 'center',
        'sig' => [['name' => '{{signatory.name}}', 'pos' => 'Affiant', 'align' => 'center', 'bold' => 1], ['name' => 'Maria Teresa Marimon', 'pos' => 'Co-affiant', 'align' => 'center', 'bold' => 1, 'newrow' => 1]]]);
    DB::update('brgy_templates', ['layout_json' => json_encode($L), 'body' => str_replace('do hereby depose and state that:', 'do hereby depose and state THE FOLLOWING:', $oss['body'])], 'id = :id', ['id' => $oss['id']]);
    $fid3 = (int)BrgyOps::createForm((int)$oss['id'], $pid);
    $f3 = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid3]);
    $ok('Changed on the template: the text, the header, the affiants all show on the next form', str_contains($f3['body'], 'depose and state THE FOLLOWING:') && str_contains($f3['body'], 'SWORN STATEMENT OF ' . $bidder)
        && str_contains($f3['body'], 'Maria Teresa Marimon') && str_contains($f3['body'], 'Co-affiant'));
    $ok('... and the footer', str_contains(BrgyLayout::footerHtml(BrgyLayout::layout(['layout_json' => json_encode($L)])), 'Omnibus sworn statement'));

    // a draft made from the shell before version 37 takes the whole form with "Fill again from the records"
    DB::update('brgy_forms', ['is_prescribed' => 1, 'template_version' => 1, 'body' => '<p>[OFFICIAL TEXT OF THE FORM]</p>'], 'id = :id', ['id' => $fid]);
    BrgyOps::refreshForm($fid);
    $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $fid]);
    $ok('A draft of the old shell, filled again: the whole form, no longer prescribed', (int)$f['is_prescribed'] === 0 && !str_contains($f['body'], '[OFFICIAL TEXT OF THE FORM]') && str_contains($f['body'], 'Jerome T. Cuizon'));
    $edit = file_get_contents(APP_ROOT . '/modules/brgy/forms.php');
    $ok('The form page says when its template has changed since', str_contains($edit, 'The template has changed.'));
    $ok('Contracting Settings holds the details of the affiant', str_contains(file_get_contents(APP_ROOT . '/modules/brgy/settings.php'), "'brgy_signatory_residence'"));
} finally {
    DB::rollBack();
}
echo "\n" . $pass . ' passed, ' . count($failures) . " failed (everything rolled back)\n";
foreach ($failures as $f) {
    echo '  - ' . $f . "\n";
}
exit($failures ? 1 : 0);
