<?php
declare(strict_types=1);

/**
 * Barangay Contracts > Contracting Settings
 * The company details the forms use, the controls of the module, how it posts to the ledger (accounts, site,
 * withholding taxes), what a billing pack must hold, when alerts come up, and the rules of the company's SOP.
 */
require_once APP_ROOT . '/modules/brgy/_ui.php';

$pageKey = 'brgy.settings';

$toggles = [
    'brgy_prices_vat_inclusive' => ['Prices in the price schedule include VAT', 'Billing splits each line into its amount and VAT, which add up to the price exactly.'],
    'brgy_sod_strict' => ['Segregation of duties', 'A person holds up to two roles on a transaction (the preparer and the checker, say); a third is refused. On a bid, billing, change order or form, nobody approves what they both prepared and reviewed.'],
    'brgy_block_over_abc' => ['A bid above the ABC cannot go for approval', 'The approved budget for the contract is the ceiling of the bid.'],
    'brgy_gate_documents' => ['Mandatory papers in order before the bid goes for review', 'Missing, expired or unreviewed mandatory eligibility and bid papers stop it.'],
    'brgy_gate_deadline' => ['No bid submission after the deadline', 'The submission is refused once the bid deadline has passed.'],
    'brgy_require_acceptance_doc' => ['Acceptance needs the entity\'s paper', 'An inspection result with accepted quantities needs the signed inspection and acceptance report or delivery receipt.'],
    'brgy_require_pack_complete' => ['A billing pack is complete before it is submitted', 'Every required paper of the pack (below) is on file.'],
    'brgy_require_perf_security' => ['Performance security before fulfilment', 'Fulfilment starts only with a performance security recorded when the mode asks for one.'],
    'brgy_create_class' => ['A class per contract', 'At the award, a class is opened for the contract (besides its Customer:Job) for the profit and loss by contract.'],
];
$sopToggles = [
    'brgy_sop_hold_billing' => ['Hold the SI, OR and 2307 until the payment is released', 'Special case F of the SOP (the bidding process): no sales invoice, official receipt or BIR Form 2307 is prepared while the cheque is not ready.'],
    'brgy_sop_need_scans' => ['A paper counts as signed when its signed copy is attached', 'The billing, review, completion and release steps need the scans (PDF or JPG) of the signed papers.'],
    'brgy_sop_review_other' => ['The review is done by another person', 'Step "Review documents" is marked done by someone other than whoever prepared the SI, OR and 2307 (the bidding process).'],
];

// the stored previews of Office files are made again (after LibreOffice is installed or changed)
if (is_post() && g('a') === 'clear_previews') {
    brgy_action(function () {
        if (!Access::isAdmin()) {
            fail('Only an administrator clears the stored previews.');
        }
        $n = 0;
        foreach (glob(OfficePreview::cacheDir() . '/*.{pdf,html,pdf-failed}', GLOB_BRACE) ?: [] as $f) {
            $n += @unlink($f) ? 1 : 0;
        }
        Brgy::log('settings', 'settings', null, 'Office file previews cleared', ['reason' => $n . ' stored preview(s) removed', 'page_key' => 'brgy.settings']);
        return $n . ' stored preview(s) removed; they are made again when the files are opened.';
    }, url('brgy.settings'));
}

if (is_post()) {
    brgy_action(function () use ($toggles, $sopToggles) {
        Access::require('brgy.settings', 'edit');
        $before = [];
        $after = [];
        $set = function (string $k, string $v) use (&$before, &$after) {
            $old = Brgy::setting($k);
            if ($old !== $v) {
                $before[$k] = $old;
                $after[$k] = $v;
                Settings::set($k, $v);
            }
        };
        foreach (['brgy_company_reg_no' => 60, 'brgy_philgeps_no' => 40, 'brgy_philgeps_class' => 40, 'brgy_signatory_name' => 120, 'brgy_signatory_position' => 120, 'brgy_bank_details' => 500,
                     'brgy_signatory_title' => 20, 'brgy_signatory_mobile' => 40, 'brgy_manager_name' => 120, 'brgy_manager_position' => 120, 'brgy_manager_phone' => 40, 'brgy_manager_mobile' => 40,
                     'brgy_liaison_name' => 120, 'brgy_liaison_position' => 120, 'brgy_liaison_phone' => 40, 'brgy_liaison_mobile' => 40,
                     // v37: the representative in the sworn statements
                     'brgy_signatory_civil_status' => 30, 'brgy_signatory_citizenship' => 40, 'brgy_signatory_residence' => 255, 'brgy_signatory_id_type' => 60,
                     'brgy_signatory_id_no' => 60, 'brgy_notary_place' => 120] as $k => $len) {
            $set($k, mb_substr(trim((string)p($k)), 0, $len));
        }
        $set('brgy_bir_status', p('brgy_bir_status') === 'non_vat' ? 'non_vat' : 'vat');
        foreach ($toggles + $sopToggles as $k => $x) {
            $set($k, p($k) === '1' ? '1' : '0');
        }
        $set('brgy_svp_limit', (string)max(0, round(pnum('brgy_svp_limit'), 2)));
        // v33: the tradenames are kept on Barangay Contracts > Tradenames (its default is copied to brgy_tradename)
        $set('brgy_accounting_dept', mb_substr(trim((string)p('brgy_accounting_dept')), 0, 120) ?: 'Accounting Department');
        $types = [];
        foreach (preg_split('/\R/', (string)p('brgy_txn_types')) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] === '') {
                continue;
            }
            $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $parts[0]));
            $types[] = $code . '|' . mb_substr($parts[1] ?? ucfirst($parts[0]), 0, 40);
        }
        if (!$types) {
            fail('Enter at least one type of transaction.');
        }
        $set('brgy_txn_types', implode("\n", $types));
        // v34: the rates of the drop-downs of the estimates on the transaction form
        foreach (['brgy_est_wt_rates' => 'Withholding tax', 'brgy_est_profit_rates' => 'Profit', 'brgy_est_receipt_rates' => 'Receipt deduction'] as $k => $what) {
            if (!array_key_exists($k, $_POST)) {
                continue;
            }
            $rates = [];
            foreach (preg_split('/[\s,;%]+/', (string)p($k)) as $v) {
                if ($v === '') {
                    continue;
                }
                if (!is_numeric($v) || (float)$v <= 0 || (float)$v >= 100) {
                    fail($what . ' rates: enter percentages above 0 and below 100, separated by commas (for example 1, 4, 5).');
                }
                $rates[Brgy::rateKey((float)$v)] = (float)$v;
            }
            if (!$rates) {
                fail($what . ' rates: enter at least one percentage.');
            }
            asort($rates);
            $set($k, implode(',', array_keys($rates)));
        }
        // v37: the withholding tax on the gross divided by this (1.12 = less VAT; 1 = the gross), the receipt deduction on this % of the gross
        if (array_key_exists('brgy_est_wt_divisor', $_POST)) {
            $v = trim((string)p('brgy_est_wt_divisor'));
            if (!is_numeric($v) || (float)$v < 1 || (float)$v > 2) {
                fail('Withholding tax base: enter the number the gross amount is divided by, from 1 to 2 (1.12 for the gross less VAT, 1 for the gross itself).');
            }
            $set('brgy_est_wt_divisor', rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'));
        }
        if (array_key_exists('brgy_est_receipt_base', $_POST)) {
            $v = trim(str_replace('%', '', (string)p('brgy_est_receipt_base')));
            if (!is_numeric($v) || (float)$v <= 0 || (float)$v > 100) {
                fail('Receipt deduction base: enter the % of the gross amount the rate is applied to, above 0 and up to 100 (for example 80).');
            }
            $set('brgy_est_receipt_base', rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'));
        }
        $site = (int)p('brgy_delivered_site_id');
        if ($site && !DB::value('SELECT id FROM sites WHERE id = :id', ['id' => $site])) {
            fail('Choose a site.');
        }
        $set('brgy_delivered_site_id', $site ? (string)$site : '');
        foreach (['brgy_wht_goods', 'brgy_wht_services', 'brgy_wht_vat'] as $k) {
            $code = (string)p($k);
            if ($code !== '' && !BrgyPost::whtType($code)) {
                fail('Choose an active withholding tax type for ' . $k . '.');
            }
            $set($k, $code);
        }
        foreach (['brgy_default_retention_pct' => [0, 100], 'brgy_default_ld_rate' => [0, 100]] as $k => [$min, $max]) {
            $v = pnum($k);
            if ($v < $min || $v > $max) {
                fail('Percentages are between 0 and 100.');
            }
            $set($k, rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0');
        }
        $pack = [];
        foreach (preg_split('/\R/', (string)p('brgy_billing_pack')) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }
            if (!DB::value('SELECT code FROM brgy_doc_types WHERE code = :c', ['c' => $parts[0]])) {
                fail('Billing pack: "' . $parts[0] . '" is not a document type code (Document Vault types).');
            }
            $pack[] = $parts[0] . '|' . $parts[1] . '|' . (($parts[2] ?? '1') === '0' ? '0' : '1');
        }
        $set('brgy_billing_pack', implode("\n", $pack));
        foreach (['brgy_alert_days' => [1, 365], 'brgy_bid_alert_days' => [1, 90], 'brgy_accept_alert_days' => [1, 90], 'brgy_ack_alert_days' => [1, 180], 'brgy_upload_max_mb' => [1, 100]] as $k => [$min, $max]) {
            $set($k, (string)max($min, min($max, (int)p($k))));
        }
        $mail = trim((string)p('brgy_alert_email'));
        foreach (array_filter(array_map('trim', explode(',', $mail))) as $m) {
            if (!filter_var($m, FILTER_VALIDATE_EMAIL)) {
                fail('Not an e-mail address: ' . $m);
            }
        }
        $set('brgy_alert_email', mb_substr($mail, 0, 255));
        if (Access::isAdmin()) {
            $set('brgy_scan_command', mb_substr(trim((string)p('brgy_scan_command')), 0, 255));
            // only the LibreOffice program itself can be named (it is run without a shell)
            $conv = trim((string)p('brgy_office_converter'), " \t\n\r\0\x0B\"'");
            if ($conv !== '' && strtolower($conv) !== 'off') {
                if (!preg_match('/(^|[\\\\\/])(soffice|libreoffice)(\.exe|\.com|\.bin)?$/i', $conv)) {
                    fail('The page view program must be LibreOffice\'s soffice (for example C:\\Program Files\\LibreOffice\\program\\soffice.exe or /usr/bin/soffice), or "off".');
                }
                if (!is_file($conv)) {
                    fail('No program at ' . $conv . '. Check the folder LibreOffice was installed in.');
                }
            }
            $set('brgy_office_converter', mb_substr($conv, 0, 255));
        }
        if ($after) {
            Brgy::log('settings', 'settings', null, 'Contracting Settings', ['before' => $before, 'after' => $after, 'page_key' => 'brgy.settings']);
        }
        return $after ? count($after) . ' setting(s) saved.' : 'Nothing changed.';
    }, url($pageKey));
}

$ro = Access::can($pageKey, 'edit') ? '' : 'disabled';
$s = fn(string $k) => Brgy::setting($k);
$wht = DB::pairs("SELECT code, CONCAT(name, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM rate)), '%)') FROM withholding_taxes WHERE is_active = 1 ORDER BY sort_order, code");
$defaults = ['gov_goods_income' => 'Sales of goods to government', 'gov_services_income' => 'Services to government', 'gov_works_income' => 'Contract revenue (works)', 'retention_receivable' => 'Retention receivable',
    'security_deposits' => 'Bid and performance security deposits', 'liquidated_damages' => 'Liquidated damages', 'forfeited_security' => 'Loss on forfeited security'];
layout_start('Contracting Settings', brgy_layout(['actions' => page_link('coa.default_accounts', [], 'Module Default Accounts', 'btn-outline-secondary', 'diagram-3')]));
?>
<form method="post" class="brgy-form" data-no-class-check><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-building"></i> The company on the forms</div><div class="card-body row g-2">
      <?= input_field('brgy_company_reg_no', 'SEC / DTI / CDA registration no.', $s('brgy_company_reg_no'), ['class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= select_field('brgy_bir_status', 'BIR registration', ['vat' => 'VAT registered', 'non_vat' => 'Non-VAT (percentage tax)'], $s('brgy_bir_status'), ['class' => 'col-md-6', 'extra' => 'data-no-combo ' . $ro]) ?>
      <?= input_field('brgy_philgeps_no', 'PhilGEPS registration no.', $s('brgy_philgeps_no'), ['class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= input_field('brgy_philgeps_class', 'PhilGEPS membership (e.g. Platinum)', $s('brgy_philgeps_class'), ['class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= input_field('brgy_signatory_title', 'Title', $s('brgy_signatory_title'), ['class' => 'col-4 col-md-2', 'extra' => 'maxlength="20" placeholder="Mr." list="sigTitles" ' . $ro]) ?>
      <datalist id="sigTitles"><option value="Mr."><option value="Ms."><option value="Mrs."><option value="Engr."><option value="Atty."><option value="Dr."></datalist>
      <?= input_field('brgy_signatory_name', 'Authorized representative', $s('brgy_signatory_name'), ['class' => 'col-8 col-md-5', 'extra' => $ro]) ?>
      <?= input_field('brgy_signatory_position', 'Position', $s('brgy_signatory_position'), ['class' => 'col-7 col-md-5', 'extra' => $ro]) ?>
      <?= input_field('brgy_signatory_mobile', 'Cellphone', $s('brgy_signatory_mobile'), ['class' => 'col-5 col-md-4', 'extra' => 'maxlength="40" inputmode="tel" ' . $ro]) ?>
      <div class="col-md-8 form-text d-flex align-items-end">The barangay's notices are addressed to <?= h(mb_strtoupper(trim($s('brgy_signatory_title') . ' ' . $s('brgy_signatory_name'))) ?: 'the representative') ?>.</div>
      <div class="col-12 settings-sub">Person managing the affairs of the firm <span class="text-muted fw-normal">(Supplier Profile; blank = the representative)</span></div>
      <?= input_field('brgy_manager_name', 'Name', $s('brgy_manager_name'), ['class' => 'col-md-6', 'extra' => 'maxlength="120" ' . $ro]) ?>
      <?= input_field('brgy_manager_position', 'Designation', $s('brgy_manager_position'), ['class' => 'col-md-6', 'extra' => 'maxlength="120" ' . $ro]) ?>
      <?= input_field('brgy_manager_phone', 'Telephone', $s('brgy_manager_phone'), ['class' => 'col-6', 'extra' => 'maxlength="40" inputmode="tel" ' . $ro]) ?>
      <?= input_field('brgy_manager_mobile', 'Cellphone', $s('brgy_manager_mobile'), ['class' => 'col-6', 'extra' => 'maxlength="40" inputmode="tel" ' . $ro]) ?>
      <div class="col-12 settings-sub">Authorized liaison officer</div>
      <?= input_field('brgy_liaison_name', 'Name', $s('brgy_liaison_name'), ['class' => 'col-md-6', 'extra' => 'maxlength="120" ' . $ro]) ?>
      <?= input_field('brgy_liaison_position', 'Designation', $s('brgy_liaison_position'), ['class' => 'col-md-6', 'extra' => 'maxlength="120" ' . $ro]) ?>
      <?= input_field('brgy_liaison_phone', 'Telephone', $s('brgy_liaison_phone'), ['class' => 'col-6', 'extra' => 'maxlength="40" inputmode="tel" ' . $ro]) ?>
      <?= input_field('brgy_liaison_mobile', 'Cellphone', $s('brgy_liaison_mobile'), ['class' => 'col-6', 'extra' => 'maxlength="40" inputmode="tel" ' . $ro]) ?>
      <div class="col-12 settings-sub">The representative in the sworn statements <span class="text-muted fw-normal">(omnibus sworn statement, bid securing declaration)</span></div>
      <?= input_field('brgy_signatory_civil_status', 'Civil status', $s('brgy_signatory_civil_status'), ['class' => 'col-6 col-md-3', 'extra' => 'maxlength="30" placeholder="married" list="civilStatus" ' . $ro]) ?>
      <datalist id="civilStatus"><option value="single"><option value="married"><option value="widowed"><option value="legally separated"></datalist>
      <?= input_field('brgy_signatory_citizenship', 'Citizenship', $s('brgy_signatory_citizenship'), ['class' => 'col-6 col-md-3', 'extra' => 'maxlength="40" placeholder="Filipino" ' . $ro]) ?>
      <?= input_field('brgy_notary_place', 'Signed and notarized at', $s('brgy_notary_place'), ['class' => 'col-md-6', 'extra' => 'maxlength="120" placeholder="Lapu-Lapu City, Cebu" ' . $ro]) ?>
      <?= input_field('brgy_signatory_residence', 'Residence', $s('brgy_signatory_residence'), ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="House no., street, barangay, city, province" ' . $ro]) ?>
      <?= input_field('brgy_signatory_id_type', 'ID shown to the notary', $s('brgy_signatory_id_type'), ['class' => 'col-6', 'extra' => 'maxlength="60" placeholder="SSS ID" list="idTypes" ' . $ro]) ?>
      <datalist id="idTypes"><option value="SSS ID"><option value="UMID"><option value="Philippine Passport"><option value="Driver's License"><option value="PhilSys National ID"><option value="TIN ID"><option value="PRC ID"></datalist>
      <?= input_field('brgy_signatory_id_no', 'ID no.', $s('brgy_signatory_id_no'), ['class' => 'col-6', 'extra' => 'maxlength="60" placeholder="06-1474911-3" ' . $ro]) ?>
      <div class="col-12 form-text">Filled into the sworn statements with the authorized representative above as the affiant. Left blank, they are blanks to fill on each form.</div>
      <div class="col-12"><label class="form-label">Bank details for payment (statement of account, billing letters)</label><textarea class="form-control form-control-sm" name="brgy_bank_details" rows="2" <?= $ro ?>><?= h($s('brgy_bank_details')) ?></textarea></div>
      <div class="col-12 form-text">Name, address, TIN, phone and logo come from Company Information.</div>
    </div></div>
    <?php
    // the letterheads shared by the templates: where each is used, and how it looks
    $lhUse = ['company' => [], 'barangay' => []];
    foreach (DB::all('SELECT id, name, layout_json, body FROM brgy_templates WHERE is_active = 1 ORDER BY sort_order, name') as $tr) {
        $tl = BrgyLayout::layout($tr);
        if (isset($lhUse[$tl['lh_style']]) && preg_match('/\{\{\s*letterhead\s*\}\}/', (string)$tr['body'])) {
            $lhUse[$tl['lh_style']][] = $tr;
        }
    }
    $lhProj = (int)DB::value("SELECT id FROM brgy_projects ORDER BY (process <> 'general') DESC, id DESC LIMIT 1");
    $lhP = $lhProj ? Brgy::project($lhProj) : null;
    ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-card-heading"></i> Letterheads of the forms</div><div class="card-body">
      <?php foreach (['company' => 'The company letterhead', 'barangay' => 'The barangay letterhead'] as $style => $title):
          $LL = array_merge(BrgyLayout::DEFAULTS, ['lh_style' => $style], BrgyLayout::sharedLetterhead($style));
          $ctxL = BrgyDocs::context($style === 'barangay' ? $lhP : null, null, '', $LL); ?>
        <div class="lh-sample">
          <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1"><b class="small"><?= h($title) ?></b><span class="small text-muted"><?= count($lhUse[$style]) ?> template(s)</span>
            <?php if ($lhUse[$style] && Access::page('brgy.templates')): ?><a class="small ms-auto" href="<?= h(url('brgy.templates', ['a' => 'view', 'id' => $lhUse[$style][0]['id'], 'tab' => 'format'])) ?>">Change it <i class="bi bi-arrow-right"></i></a><?php endif; ?></div>
          <div class="lh-sample-paper"><div class="formdoc" style="<?= h(BrgyLayout::styleAttr(BrgyLayout::layout(null), false)) ?>"><?= $ctxL['letterhead']['html'] ?: '<span class="text-muted small">Nothing to show yet.</span>' ?></div></div>
          <?php if ($lhUse[$style]): ?><div class="small text-muted mt-1"><?= h(implode(', ', array_map(fn($x) => $x['name'], array_slice($lhUse[$style], 0, 8))) . (count($lhUse[$style]) > 8 ? ', ...' : '')) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="form-text">A shared letterhead is changed on the Format tab of any template that uses it, and the change shows on all of them. A template can also have a letterhead of its own, or none.<?= $lhP ? ' The barangay letterhead is shown with ' . h($lhP['project_no']) . '.' : '' ?></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-shield-lock"></i> Controls</div><div class="card-body">
      <?php foreach ($toggles as $k => [$lbl, $help]): ?>
        <div class="form-check form-switch mb-2"><input type="hidden" name="<?= h($k) ?>" value="0"><input class="form-check-input" type="checkbox" role="switch" id="t_<?= h($k) ?>" name="<?= h($k) ?>" value="1" <?= $s($k) === '1' ? 'checked' : '' ?> <?= $ro ?>>
          <label class="form-check-label" for="t_<?= h($k) ?>"><?= h($lbl) ?><span class="d-block small text-muted"><?= h($help) ?></span></label></div>
      <?php endforeach; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-signpost-split"></i> The SOP (Standard Operating Procedure)</div><div class="card-body row g-2">
      <?php $tnList = Brgy::tradenames(); ?>
      <div class="col-md-6"><label class="form-label">Tradenames on the transaction form</label>
        <div class="form-control form-control-sm bg-body-tertiary text-truncate" title="<?= h(implode(', ', $tnList)) ?>"><?= $tnList ? h(Brgy::defaultTradename()) . (count($tnList) > 1 ? ' <span class="text-muted">and ' . (count($tnList) - 1) . ' more</span>' : '') : '<span class="text-muted">None yet: the company name is used</span>' ?></div>
        <div class="form-text">Added, changed and removed on <?= safe_link('brgy.tradenames', [], 'Tradenames') ?>; each transaction picks one.</div></div>
      <?= input_field('brgy_svp_limit', 'Small value procurement below (amount)', $s('brgy_svp_limit'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" ' . $ro]) ?>
      <?= input_field('brgy_accounting_dept', 'Papers are forwarded to', $s('brgy_accounting_dept'), ['class' => 'col-md-6', 'extra' => 'maxlength="120" ' . $ro]) ?>
      <div class="col-md-6"><label class="form-label">Types of transaction (code|name, one a line)</label><textarea class="form-control form-control-sm font-monospace" name="brgy_txn_types" rows="4" <?= $ro ?>><?= h($s('brgy_txn_types')) ?></textarea>
        <div class="form-text">The steps and papers each type goes through are ticked on <?= safe_link('brgy.process', [], 'SOP Steps & Papers') ?>. The box of the type on the transaction form prints the name without what is in brackets.</div></div>
      <div class="col-12">
        <?php foreach ($sopToggles as $k => [$lbl, $help]): ?>
          <div class="form-check form-switch mb-2"><input type="hidden" name="<?= h($k) ?>" value="0"><input class="form-check-input" type="checkbox" role="switch" id="t_<?= h($k) ?>" name="<?= h($k) ?>" value="1" <?= $s($k) === '1' ? 'checked' : '' ?> <?= $ro ?>>
            <label class="form-check-label" for="t_<?= h($k) ?>"><?= h($lbl) ?><span class="d-block small text-muted"><?= h($help) ?></span></label></div>
        <?php endforeach; ?>
      </div>
      <div class="col-12 form-text">The amount only suggests the process of a new transaction; the steps and papers themselves are on <?= safe_link('brgy.process', [], 'SOP Steps & Papers') ?>.</div>
      <div class="col-12"><div class="fw-semibold small mt-1">Estimates and targets of the transaction form (rates in %, separated by commas)</div></div>
      <?= input_field('brgy_est_wt_rates', 'Withholding tax', $s('brgy_est_wt_rates'), ['class' => 'col-md-4', 'extra' => 'maxlength="60" ' . $ro]) ?>
      <?= input_field('brgy_est_profit_rates', 'Profit', $s('brgy_est_profit_rates'), ['class' => 'col-md-4', 'extra' => 'maxlength="60" ' . $ro]) ?>
      <?= input_field('brgy_est_receipt_rates', 'Receipt deduction', $s('brgy_est_receipt_rates'), ['class' => 'col-md-4', 'extra' => 'maxlength="60" ' . $ro]) ?>
      <?= input_field('brgy_est_wt_divisor', 'Withholding tax: the gross divided by', $s('brgy_est_wt_divisor'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" maxlength="8" ' . $ro]) ?>
      <?= input_field('brgy_est_receipt_base', 'Receipt deduction: % of the gross', $s('brgy_est_receipt_base'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" maxlength="6" ' . $ro]) ?>
      <div class="col-12 form-text">What the drop-downs of the transaction form offer, and how the amounts are worked out: withholding tax = gross &divide; <?= h($s('brgy_est_wt_divisor')) ?> &times; rate
        (1.12: the gross less VAT; 1: the gross itself); receipt deduction = <?= h($s('brgy_est_receipt_base')) ?>% of the gross &times; rate. They are estimates and targets only: no accounting entry is made from them.</div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-percent"></i> Defaults of a new project</div><div class="card-body row g-2">
      <?= input_field('brgy_default_retention_pct', 'Retention %', $s('brgy_default_retention_pct'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" ' . $ro]) ?>
      <?= input_field('brgy_default_ld_rate', 'Liquidated damages % per day', $s('brgy_default_ld_rate'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal" ' . $ro]) ?>
      <div class="col-12 form-text">Each project keeps what its contract states; these only fill a new project. Leave them at 0 unless your contracts usually carry them.</div>
    </div></div>
  </div>
  <div class="col-xl-6">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-journal-text"></i> Accounting</div><div class="card-body row g-2">
      <?= select_field('brgy_delivered_site_id', 'Site for goods delivered, awaiting acceptance', Lookup::sites(false), $s('brgy_delivered_site_id'), ['blank' => '', 'class' => 'col-12', 'extra' => 'data-no-combo ' . $ro]) ?>
      <?= select_field('brgy_wht_goods', 'Income tax withheld on goods', $wht, $s('brgy_wht_goods'), ['blank' => '', 'class' => 'col-md-4', 'extra' => 'data-no-combo ' . $ro]) ?>
      <?= select_field('brgy_wht_services', 'On services and works', $wht, $s('brgy_wht_services'), ['blank' => '', 'class' => 'col-md-4', 'extra' => 'data-no-combo ' . $ro]) ?>
      <?= select_field('brgy_wht_vat', 'VAT withheld', $wht, $s('brgy_wht_vat'), ['blank' => '', 'class' => 'col-md-4', 'extra' => 'data-no-combo ' . $ro]) ?>
      <div class="col-12 form-text">Rates come from the Withholding Tax Table (Banking / BIR set-up); a collection's taxes are checked against it, and a difference needs an explanation.</div>
      <div class="col-12"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Default account</th><th>Account</th></tr></thead>
        <?php foreach ($defaults as $k => $lbl): $acc = Brgy::acct($k); ?><tr><td class="small"><?= h($lbl) ?></td><td class="small"><?= $acc ? h((string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :id", ['id' => $acc])) : '<span class="text-danger">not set</span>' ?></td></tr><?php endforeach; ?>
      </table></div><div class="form-text">Change them on Financial &gt; Module Default Accounts (Barangay Contracts).</div></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-folder-check"></i> What a billing pack holds</div><div class="card-body">
      <textarea class="form-control form-control-sm font-monospace" name="brgy_billing_pack" rows="7" <?= $ro ?>><?= h($s('brgy_billing_pack')) ?></textarea>
      <div class="form-text">One paper a line: <code>document type code|name|1</code> (1 = required, 0 = if any). A contract and a purchase order stand for each other.</div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-bell"></i> Alerts and files</div><div class="card-body row g-2">
      <?= input_field('brgy_alert_days', 'Warn before papers expire (days)', $s('brgy_alert_days'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= input_field('brgy_bid_alert_days', 'Warn before a bid deadline (days)', $s('brgy_bid_alert_days'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= input_field('brgy_accept_alert_days', 'Delivery not accepted after (days)', $s('brgy_accept_alert_days'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= input_field('brgy_ack_alert_days', 'Billing not acknowledged after (days)', $s('brgy_ack_alert_days'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6', 'extra' => $ro]) ?>
      <?= input_field('brgy_alert_email', 'Daily alert e-mail to (comma between addresses)', $s('brgy_alert_email'), ['class' => 'col-12', 'extra' => $ro]) ?>
      <?= input_field('brgy_upload_max_mb', 'Largest file (MB)', $s('brgy_upload_max_mb'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-6', 'extra' => $ro]) ?>
      <?php if (Access::isAdmin()): ?><?= input_field('brgy_scan_command', 'Virus scan command (optional; the file name is added at the end)', $s('brgy_scan_command'), ['class' => 'col-md-6', 'extra' => 'placeholder="clamscan --no-summary" ' . $ro]) ?><?php endif; ?>
      <div class="col-12 form-text">The e-mail goes out when tools/brgy_alerts.php runs (scheduled daily on the server).</div>
    </div></div>
    <?php $conv = OfficePreview::converter(); ?>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-file-earmark-richtext"></i> Previews of Word, Excel, PowerPoint and Publisher files</div><div class="card-body row g-2">
      <div class="col-12"><div class="op-status <?= $conv ? 'is-on' : 'is-off' ?>"><i class="bi <?= $conv ? 'bi-check-circle-fill' : 'bi-info-circle-fill' ?>"></i>
        <div><strong><?= $conv ? 'Page view on' : 'Quick view only' ?></strong>
          <div class="small"><?= $conv ? 'LibreOffice at ' . h($conv) . ' shows each file as it prints: its exact pages, fonts and pictures.'
            : (strtolower(trim($s('brgy_office_converter'))) === 'off' ? 'The page view is turned off.' : 'LibreOffice is not installed on the server, so files show in the quick view (their text, tables, sheets, slides and pictures). Install LibreOffice (free) for the page view.') ?></div></div></div></div>
      <?php if (!class_exists('ZipArchive')): ?><div class="col-12"><div class="alert alert-warning small mb-0">The zip extension of PHP is off, so Word, Excel and PowerPoint 2007 and later files (.docx, .xlsx, .pptx) cannot show in the quick view.
        In C:\xampp\php\php.ini remove the ; before <code>extension=zip</code>, then restart Apache.</div></div><?php endif; ?>
      <?php if (Access::isAdmin()): ?>
        <?= input_field('brgy_office_converter', 'LibreOffice program for the page view (blank: found by itself; "off": no page view)', $s('brgy_office_converter'), ['class' => 'col-12', 'extra' => 'placeholder="C:\Program Files\LibreOffice\program\soffice.exe" ' . $ro]) ?>
        <div class="col-12 d-flex flex-wrap align-items-center gap-2"><button type="submit" class="btn btn-sm btn-outline-secondary" form="fmClearPreviews"><i class="bi bi-arrow-clockwise"></i> Make the previews again</button>
          <span class="small text-muted">After LibreOffice is installed or changed.</span></div>
      <?php endif; ?>
      <div class="col-12 form-text">The quick view needs nothing installed: it reads Word (Word for DOS and Word 2.0 to 365), Excel (2.1 to 365), PowerPoint (95 to 365) and Publisher (98 to 365) files itself. Previews are made once per file and kept in storage/contracts/previews.</div>
    </div></div>
  </div>
</div>
<div class="card mb-3"><div class="card-header"><i class="bi bi-arrow-left-right"></i> How the contracts reach the books</div><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>When</th><th>Debit</th><th>Credit</th><th>Transaction</th></tr></thead>
  <?php foreach (BrgyPost::MAP as $ev => [$when, $dr, $cr, $txn]): ?><tr><td class="small"><?= h($when) ?></td><td class="small"><?= h($dr) ?></td><td class="small"><?= h($cr) ?></td><td class="small"><?= h($txn) ?></td></tr><?php endforeach; ?>
</table></div><div class="card-body py-2 small text-muted">Each record posts once per event (a second posting is refused); voiding the record voids its entry. The Contracts-to-Ledger Reconciliation report ties every contract to the books.</div></div>
<?php if (!$ro): ?><div class="sticky-actions"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save settings</button></div><?php endif; ?>
</form>
<?php if (Access::isAdmin()): ?><form id="fmClearPreviews" method="post" action="<?= h(url($pageKey, ['a' => 'clear_previews'])) ?>" class="d-none"><?= csrf_field() ?></form><?php endif; ?>
<?php
layout_end();
