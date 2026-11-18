<?php
declare(strict_types=1);

/**
 * Barangay Contracts: the set-up lists a new installation starts with (tools/seed.php calls it).
 * Settings, numbering, kinds of documents, procurement modes, regulatory references, the compliance requirement
 * library and the proforma / form templates. The migration 2026_10_29 inserts the same rows into a database in use.
 * The company's SOP (its steps, papers and the barangay forms it prepares) is in tools/brgy_sop_seed.php (migration 2026_11_03).
 *
 * Nothing here states a ceiling, a threshold or a deadline as law: those are left blank for the company to enter
 * after checking the current issuances and the procuring entity's own documents.
 */

function brgy_seed_settings(): void
{
    // the company's own details (registration, PhilGEPS, signatory, bank) stay blank: Contracting Settings
    foreach (Brgy::SETTINGS as $k => $v) {
        DB::run('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = setting_value', ['k' => $k, 'v' => $v]);
    }
    foreach ([['brgy_project', 'BCP-'], ['brgy_delivery', 'BDR-'], ['brgy_billing', 'BBL-'], ['brgy_collection', 'BCL-'], ['brgy_co', 'BCO-'], ['brgy_form', 'BFM-'], ['brgy_security', 'BSC-']] as [$k, $pfx]) {
        DB::run('INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES (:k, :p, 1, 5)', ['k' => $k, 'p' => $pfx]);
    }
    $site = DB::value("SELECT id FROM sites WHERE code = 'BRGY-DLV'") ?: DB::insert('sites', ['code' => 'BRGY-DLV', 'name' => 'Delivered, awaiting acceptance', 'site_type' => 'other',
        'address' => 'Goods at the procuring entity, not yet accepted']);
    Settings::set('brgy_delivered_site_id', (string)$site);
}

function brgy_seed_doc_types(): void
{
    $rows = [
        // code, name, group, company paper, needs validity, usual validity (months)
        ['philgeps_cert', 'PhilGEPS registration certificate (Platinum)', 'legal', 1, 1, 12],
        ['mayors_permit', "Mayor's / business permit", 'legal', 1, 1, 12],
        ['sec_dti', 'SEC / DTI / CDA registration', 'legal', 1, 0, null],
        ['bir_2303', 'BIR Certificate of Registration (Form 2303)', 'legal', 1, 0, null],
        ['tax_clearance', 'Tax clearance', 'legal', 1, 1, 12],
        ['pcab_license', 'PCAB contractor license', 'legal', 1, 1, 12],
        ['authority', "Secretary's certificate / special power of attorney", 'legal', 1, 0, null],
        ['prof_license', 'Professional licenses of key personnel', 'legal', 1, 1, 36],
        ['itr', 'Latest income tax return', 'financial', 1, 0, null],
        ['business_tax_return', 'Business tax returns (VAT / percentage tax)', 'financial', 1, 0, null],
        ['afs', 'Audited financial statements', 'financial', 1, 1, 12],
        ['nfcc', 'NFCC computation / committed line of credit', 'financial', 1, 0, null],
        ['slcc', 'Statement of ongoing and completed contracts', 'technical', 1, 0, null],
        ['company_profile', 'Company profile', 'technical', 1, 0, null],
        ['rfq', 'RFQ / invitation to bid / notice', 'bid', 0, 0, null],
        ['bulletin', 'Supplemental bid bulletin', 'bid', 0, 0, null],
        ['specs', 'Technical specifications / terms of reference', 'technical', 0, 0, null],
        ['price_schedule', 'Price schedule / bill of quantities', 'bid', 0, 0, null],
        ['quotation', 'Price quotation / bid form', 'bid', 0, 0, null],
        ['technical_proposal', 'Technical proposal / statement of compliance', 'technical', 0, 0, null],
        ['method_statement', 'Method statement', 'technical', 0, 0, null],
        ['schedule', 'Implementation / delivery schedule', 'technical', 0, 0, null],
        ['proposal', 'Supply / services / scope-of-work proposal', 'technical', 0, 0, null],
        ['brochure', 'Brochures and technical data sheets', 'technical', 0, 0, null],
        ['cover_letter', 'Cover letter', 'bid', 0, 0, null],
        ['omnibus', 'Omnibus sworn statement', 'bid', 0, 0, null],
        ['bid_security', 'Bid security / bid securing declaration', 'bid', 0, 1, null],
        ['submission_proof', 'Proof of submission / receiving copy', 'bid', 0, 0, null],
        ['correspondence', 'Letter / correspondence', 'other', 0, 0, null],
        ['noa', 'Notice of award', 'contract', 0, 0, null],
        ['po', 'Purchase order / job order', 'contract', 0, 0, null],
        ['contract', 'Contract agreement', 'contract', 0, 0, null],
        ['ntp', 'Notice to proceed', 'contract', 0, 0, null],
        ['perf_security', 'Performance security', 'contract', 0, 1, null],
        ['change_order', 'Approved variation / change order', 'contract', 0, 0, null],
        ['dr', 'Delivery receipt (signed)', 'delivery', 0, 0, null],
        ['iar', 'Inspection and acceptance report', 'delivery', 0, 0, null],
        ['accomplishment', 'Accomplishment / service report', 'delivery', 0, 0, null],
        ['photo', 'Photos of the delivery / work', 'delivery', 0, 0, null],
        ['invoice', 'Sales invoice', 'billing', 0, 0, null],
        ['soa', 'Statement of account', 'billing', 0, 0, null],
        ['billing_letter', 'Billing cover letter / request for payment', 'billing', 0, 0, null],
        ['transmittal', 'Transmittal letter', 'billing', 0, 0, null],
        ['receiving_copy', 'Receiving copy / acknowledgment of the entity', 'billing', 0, 0, null],
        ['or_ar', 'Acknowledgment / collection receipt', 'collection', 0, 0, null],
        ['form_2307', 'BIR Form 2307 from the entity', 'collection', 0, 0, null],
        ['followup_letter', 'Collection follow-up letter', 'collection', 0, 0, null],
        ['closeout', 'Completion / closeout papers', 'contract', 0, 0, null],
        ['supplier_request', 'Request to a supplier / subcontractor', 'other', 0, 0, null],
        ['internal', 'Internal sheet (approval, review, reconciliation)', 'other', 0, 0, null],
        ['other', 'Other document', 'other', 0, 0, null],
    ];
    $n = 0;
    foreach ($rows as [$c, $name, $g, $co, $v, $m]) {
        DB::run('INSERT IGNORE INTO brgy_doc_types (code, name, doc_group, is_company, needs_validity, validity_months, sort_order) VALUES (:c, :n, :g, :co, :v, :m, :s)',
            ['c' => $c, 'n' => $name, 'g' => $g, 'co' => $co, 'v' => $v, 'm' => $m, 's' => ($n += 10)]);
    }
}

function brgy_seed_modes(): void
{
    $verify = 'Verify the conditions and any ceiling against the current IRR and GPPB issuances before use.';
    $rows = [
        // code, name, regime, bid security, performance security, legal basis
        ['competitive_bidding', 'Competitive Bidding', 'ra12009', 1, 1, 'RA 12009 and its IRR (default mode)'],
        ['limited_source', 'Limited Source Bidding', 'ra12009', 1, 1, 'RA 12009 and its IRR'],
        ['direct_contracting', 'Direct Contracting', 'ra12009', 0, 1, 'RA 12009 and its IRR'],
        ['direct_acquisition', 'Direct Acquisition', 'ra12009', 0, 0, 'RA 12009 and its IRR'],
        ['repeat_order', 'Repeat Order', 'ra12009', 0, 1, 'RA 12009 and its IRR'],
        ['small_value', 'Small Value Procurement', 'ra12009', 0, 0, 'RA 12009 and its IRR'],
        ['negotiated_emergency', 'Negotiated Procurement (Emergency Cases)', 'ra12009', 0, 0, 'RA 12009 and its IRR'],
        ['negotiated_failure', 'Negotiated Procurement (Two Failed Biddings)', 'ra12009', 0, 1, 'RA 12009 and its IRR'],
        ['negotiated_other', 'Negotiated Procurement (Other Cases)', 'ra12009', 0, 0, 'RA 12009 and its IRR'],
        ['direct_sales', 'Direct Sales', 'ra12009', 0, 0, 'RA 12009 and its IRR'],
        ['public_bidding_9184', 'Public Bidding', 'ra9184', 1, 1, 'RA 9184 and its 2016 revised IRR (procurement started before the RA 12009 rules applied)'],
        ['shopping_9184', 'Shopping', 'ra9184', 0, 0, 'RA 9184 and its 2016 revised IRR (transition cases)'],
        ['svp_9184', 'Small Value Procurement', 'ra9184', 0, 0, 'RA 9184 and its 2016 revised IRR (transition cases)'],
    ];
    $n = 0;
    foreach ($rows as [$c, $name, $reg, $bs, $ps, $basis]) {
        DB::run('INSERT IGNORE INTO brgy_proc_modes (code, name, regime, legal_basis, source_url, needs_bid_security, needs_perf_security, notes, sort_order)
                 VALUES (:c, :n, :r, :b, :u, :bs, :ps, :no, :s)', ['c' => $c, 'n' => $name, 'r' => $reg, 'b' => $basis, 'u' => 'https://www.gppb.gov.ph/',
            'bs' => $bs, 'ps' => $ps, 'no' => $verify, 's' => ($n += 10)]);
    }
}

function brgy_seed_references(): void
{
    $today = date('Y-m-d');
    $rows = [
        ['RA-12009', 'Republic Act No. 12009, New Government Procurement Act', 'Congress of the Philippines', 'law', '2024-07-20', null, '2024-08-13', 'https://www.gppb.gov.ph/',
            'All procurement of goods, infrastructure projects and consulting services by government entities, barangays included', 'Signed 20 July 2024; took effect 13 August 2024. Repealed RA 9184. Procurement started before the new rules applied follows the transitory provisions (see GPPB Resolution No. 05-2025).'],
        ['IRR-RA-12009', 'Implementing Rules and Regulations of RA 12009', 'Government Procurement Policy Board', 'irr', null, null, null, 'https://www.gppb.gov.ph/',
            'Procedures, procurement modes, documentary requirements, securities, contract implementation', 'Approved by the GPPB in February 2025 and published in the same month. Enter the effectivity date after checking the official copy.'],
        ['GPPB-RES-05-2025', 'GPPB Resolution No. 05-2025 approving Circular No. 01-2025: guidance on Section 113 (Transitory Provision) in relation to Section 115 (Repealing Clause) of RA 12009 and its IRR', 'Government Procurement Policy Board', 'resolution', '2025-09-18', null, null,
            'https://www.gppb.gov.ph/wp-content/uploads/2025/10/GPPB-Resolution-No.-05-2025.pdf', 'Which law and forms govern a procurement during the transition',
            'Covers whether RA 9184 still applies to ongoing procurement, the use of RA 9184 forms, when the new standard procurement forms become mandatory, and a three-year transitory period counted from 14 May 2025.'],
        ['GPPB-FORMS', 'GPPB Philippine Bidding Documents and standard procurement forms', 'Government Procurement Policy Board', 'other', null, null, null, 'https://www.gppb.gov.ph/',
            'Bid forms, omnibus sworn statement, bid / performance securing declarations, contract forms, price schedules', 'Use the version the procuring entity issued with its bidding documents or RFQ. The program keeps these as prescribed-form shells: paste the official text, do not reword it.'],
        ['COA-2023-004', 'COA Circular No. 2023-004, updated documentary requirements for common government transactions', 'Commission on Audit', 'circular', '2023-06-14', null, null, 'https://www.coa.gov.ph/',
            'Supporting papers the entity needs to pay a supplier or contractor', 'Amends COA Circular No. 2012-001. Use it to build the billing pack the entity\'s accountant and auditor will look for.'],
        ['BIR-RR-7-2024', 'Revenue Regulations No. 7-2024 (invoicing under the Ease of Paying Taxes Act)', 'Bureau of Internal Revenue', 'regulation', null, '2024-04-12', '2024-04-27', 'https://www.bir.gov.ph/',
            'Sales invoice as the primary document for sales of goods and services', 'Official receipts may still be used as supplementary documents marked "This document is not valid for claim of input tax." A document printed by this program is not by itself a BIR-registered invoice.'],
        ['BIR-RR-11-2024', 'Revenue Regulations No. 11-2024 (amending RR 7-2024: registration and invoicing transition)', 'Bureau of Internal Revenue', 'regulation', '2024-06-13', null, null, 'https://www.bir.gov.ph/',
            'Transition for invoices and receipts', 'Read with RR 7-2024 and the later BIR issuances, including e-invoicing guidance.'],
        ['BIR-RR-11-2018', 'Revenue Regulations No. 11-2018 (withholding tax rates, including government payors)', 'Bureau of Internal Revenue', 'regulation', null, null, null, 'https://www.bir.gov.ph/',
            'Income tax and VAT withheld by government buyers (BIR Reports > Withholding Tax Table)', 'Rates are kept on the Withholding Tax Table, never in the program; check them against the current rules and the entity\'s Form 2307.'],
        ['RA-9184', 'Republic Act No. 9184, Government Procurement Reform Act (repealed)', 'Congress of the Philippines', 'law', '2003-01-10', null, null, 'https://www.gppb.gov.ph/',
            'Procurement started before the RA 12009 rules applied', 'Repealed by RA 12009; still governs transition cases as the transitory provisions allow.'],
        ['RA-10173', 'Republic Act No. 10173, Data Privacy Act of 2012', 'Congress of the Philippines', 'law', '2012-08-15', null, null, 'https://www.privacy.gov.ph/',
            'Personal information of officials and contact persons, documents kept in the vault', 'Keep only what is needed, limit access (confidential documents), and log every download.'],
    ];
    foreach ($rows as [$code, $title, $issuer, $type, $issued, $pub, $eff, $url, $applies, $notes]) {
        DB::run('INSERT IGNORE INTO brgy_references (code, title, issuer, ref_type, issued_date, published_date, effective_date, url, applies_to, notes, last_reviewed)
                 VALUES (:c, :t, :i, :ty, :d1, :d2, :d3, :u, :a, :n, :r)', ['c' => $code, 't' => $title, 'i' => $issuer, 'ty' => $type, 'd1' => $issued, 'd2' => $pub, 'd3' => $eff,
            'u' => $url, 'a' => $applies, 'n' => $notes, 'r' => $today]);
    }
}

function brgy_seed_requirements(): void
{
    $basis = 'RA 12009 and its IRR; the RFQ / bidding documents of the procuring entity';
    $cb = 'competitive_bidding,limited_source,public_bidding_9184';
    $rows = [
        // code, name, group, stage, type, categories, modes, validity, doc type, template, prescribed
        ['REQ-PHILGEPS', 'PhilGEPS registration certificate (Platinum membership where required)', 'legal', 'eligibility', 'mandatory', '', '', 1, 'philgeps_cert', null, 0],
        ['REQ-MAYORS', "Mayor's / business permit (current)", 'legal', 'eligibility', 'mandatory', '', '', 1, 'mayors_permit', null, 0],
        ['REQ-REG', 'SEC / DTI / CDA registration', 'legal', 'eligibility', 'mandatory', '', $cb, 0, 'sec_dti', null, 0],
        ['REQ-BIR2303', 'BIR Certificate of Registration (Form 2303)', 'legal', 'eligibility', 'conditional', '', '', 0, 'bir_2303', null, 0],
        ['REQ-TAXCLEAR', 'Tax clearance', 'legal', 'eligibility', 'mandatory', '', $cb, 1, 'tax_clearance', null, 0],
        ['REQ-PCAB', 'PCAB contractor license (category and size range for the project)', 'legal', 'eligibility', 'mandatory', 'works', '', 1, 'pcab_license', null, 0],
        ['REQ-PROF', 'Professional licenses of the key personnel', 'legal', 'eligibility', 'conditional', 'consultancy,works', '', 1, 'prof_license', null, 0],
        ['REQ-AUTH', "Authority of the signatory (secretary's certificate / special power of attorney)", 'legal', 'eligibility', 'conditional', '', '', 0, 'authority', null, 0],
        ['REQ-ITR', 'Latest income tax return / business tax returns', 'financial', 'eligibility', 'conditional', '', '', 0, 'itr', null, 0],
        ['REQ-SLCC', 'Statement of all ongoing and completed government and private contracts', 'technical', 'eligibility', 'mandatory', '', $cb, 0, 'slcc', null, 0],
        ['REQ-AFS', 'Audited financial statements', 'financial', 'eligibility', 'mandatory', '', $cb, 1, 'afs', null, 0],
        ['REQ-NFCC', 'Net financial contracting capacity (NFCC) or committed line of credit', 'financial', 'eligibility', 'mandatory', '', $cb, 0, 'nfcc', null, 0],
        ['REQ-OSS', 'Omnibus sworn statement (prescribed form)', 'bid', 'bid', 'mandatory', '', '', 0, 'omnibus', 'omnibus_sworn_statement', 1],
        ['REQ-BIDSEC', 'Bid security or bid securing declaration', 'bid', 'bid', 'mandatory', '', $cb, 1, 'bid_security', 'bid_securing_declaration', 1],
        ['REQ-QUOTE', 'Signed price quotation / bid form (in the form the entity issued)', 'bid', 'bid', 'mandatory', '', '', 0, 'quotation', 'bid_form', 1],
        ['REQ-PRICESCHED', 'Price schedule / bill of quantities', 'bid', 'bid', 'mandatory', '', '', 0, 'price_schedule', 'price_schedule', 0],
        ['REQ-SPECS', 'Technical proposal / statement of compliance with the specifications', 'technical', 'bid', 'mandatory', '', '', 0, 'technical_proposal', 'technical_proposal', 0],
        ['REQ-BROCHURE', 'Brochures and technical data sheets of the products offered', 'technical', 'bid', 'optional', 'goods', '', 0, 'brochure', null, 0],
        ['REQ-SCHED', 'Delivery / implementation schedule', 'technical', 'bid', 'conditional', 'services,works', '', 0, 'schedule', 'implementation_schedule', 0],
        ['REQ-METHOD', 'Method statement', 'technical', 'bid', 'conditional', 'works', '', 0, 'method_statement', 'method_statement', 0],
        ['REQ-PERFSEC', 'Performance security', 'contract', 'contract', 'conditional', '', '', 1, 'perf_security', null, 0],
        ['REQ-CONTRACT', 'Signed contract / purchase order', 'contract', 'contract', 'mandatory', '', '', 0, 'contract', null, 0],
        ['REQ-NTP', 'Notice to proceed', 'contract', 'contract', 'conditional', 'services,works', '', 0, 'ntp', null, 0],
        ['REQ-DR', 'Delivery receipt signed by the entity', 'delivery', 'delivery', 'mandatory', 'goods', '', 0, 'dr', 'delivery_receipt', 0],
        ['REQ-IAR', 'Inspection and acceptance report', 'delivery', 'delivery', 'mandatory', '', '', 0, 'iar', null, 0],
        ['REQ-ACCOMP', 'Accomplishment / service completion report', 'delivery', 'delivery', 'mandatory', 'services,works', '', 0, 'accomplishment', 'accomplishment_report', 0],
        ['REQ-SI', 'Sales invoice (BIR-registered)', 'billing', 'billing', 'mandatory', '', '', 0, 'invoice', null, 0],
        ['REQ-SOA', 'Statement of account', 'billing', 'billing', 'mandatory', '', '', 0, 'soa', 'statement_of_account', 0],
        ['REQ-2307', 'BIR Form 2307 issued by the entity for the taxes it withheld', 'billing', 'billing', 'optional', '', '', 0, 'form_2307', null, 0],
    ];
    $n = 0;
    foreach ($rows as [$code, $name, $g, $stage, $type, $cats, $modes, $v, $dt, $tpl, $pres]) {
        DB::run('INSERT IGNORE INTO brgy_requirements (code, name, doc_group, stage, requirement_type, categories, mode_codes, needs_validity, doc_type, template_code, is_prescribed,
                        legal_basis, source_url, status, sort_order, description)
                 VALUES (:c, :n, :g, :st, :ty, :ca, :mo, :v, :dt, :tp, :pr, :lb, :u, \'approved\', :s, :d)',
            ['c' => $code, 'n' => $name, 'g' => $g, 'st' => $stage, 'ty' => $type, 'ca' => $cats ?: null, 'mo' => $modes ?: null, 'v' => $v, 'dt' => $dt, 'tp' => $tpl, 'pr' => $pres,
                'lb' => $basis, 'u' => 'https://www.gppb.gov.ph/', 's' => ($n += 10),
                'd' => $type === 'conditional' ? 'Required when the RFQ / bidding documents or the amount band ask for it. Enter the amount band on this requirement after checking the current rules.' : null]);
    }
}

/** Shared pieces of the template bodies */
/** The head of a company paper: the company letterhead of the Format tab ({{letterhead}}), the form no. and date, the title */
function brgy_tpl_head(string $title, bool $withRef = true): string
{
    return '<div>{{letterhead}}</div>' . ($withRef ? '<p style="text-align:right;margin:0 0 6px">{{doc.no}}<br>{{date.today}}</p>' : '')
        . ($title !== '' ? '<h2 style="text-align:center;margin:10px 0 14px">' . $title . '</h2>' : '');
}

function brgy_tpl_addressee(): string
{
    return '<p><b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</p>'
        . '<p><b>Attention:</b> The Chairperson, Bids and Awards Committee &nbsp;({{entity.bac_chair}})</p>';
}

/**
 * The signature of the company's authorized representative: the {{signatories}} of the Format tab. Other words than "Very truly yours,"
 * above it go into the template's format when it is installed (brgy_seed_templates).
 */
function brgy_tpl_company_sign(string $lead = 'Very truly yours,'): string
{
    return '<div>{{signatories}}</div>' . ($lead !== 'Very truly yours,' ? '<!--sig-lead:' . $lead . '-->' : '');
}

/** [body, layout] of a template: the words above its signature taken out of the text into its format */
function brgy_tpl_split(string $body): array
{
    $layout = null;
    if (preg_match('/<!--sig-lead:(.*?)-->/', $body, $m)) {
        $layout = ['signatories' => [BrgyLayout::sigBlock(['lead' => $m[1]] + BrgyLayout::SIG_DEFAULT[0])]];
        $body = str_replace($m[0], '', $body);
    }
    return [$body, $layout];
}

function brgy_tpl_sigs(array $captions): string
{
    $cells = '';
    foreach ($captions as [$cap, $name]) {
        $cells .= '<td style="width:' . floor(100 / count($captions)) . '%;vertical-align:top;padding:28px 10px 0 0"><div style="border-bottom:1px solid #000;min-height:22px">' . $name . '</div><div>' . $cap . '</div></td>';
    }
    return '<table class="sig-table" style="width:100%;border-collapse:collapse;margin-top:26px"><tr>' . $cells . '</tr></table>';
}

/** The templates: [code, name, category, stage, doc type, prescribed, required fields, description, body] */
function brgy_templates(): array
{
    $proj = '<table class="ft"><tbody>'
        . '<tr><td style="width:32%">Project</td><td><b>{{project.title}}</b></td></tr>'
        . '<tr><td>Reference (PR / RFQ / ITB)</td><td>{{project.reference_no}}</td></tr>'
        . '<tr><td>Procuring entity</td><td>{{entity.name}}</td></tr>'
        . '<tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr>'
        . '<tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr></tbody></table>';
    $out = [];

    // ---------------------------------------------------------------- bid stage
    $out[] = ['cover_letter', 'Cover letter: submission of bid / quotation', 'letter', 'bid', 'cover_letter', 0, 'signatory.name,entity.name',
        'Covering letter of the bid or quotation, listing what is enclosed.',
        brgy_tpl_head('', true) . brgy_tpl_addressee()
        . '<p><b>Subject: Submission of bid / quotation for {{project.title}} ({{project.reference_no}})</b></p>'
        . '<p>Dear Sir / Madam:</p>'
        . '<p>In response to your invitation, {{company.name}} respectfully submits its bid / quotation for <b>{{project.title}}</b> in the total amount of <b>{{project.bid_amount}}</b> ({{project.bid_amount_words}}), inclusive of all applicable taxes, under the terms and specifications of your request.</p>'
        . '<p>Enclosed are the documents your request asks for, as listed in our checklist:</p>{{requirements.table}}'
        . '<p>Our offer is valid for the period stated in your bidding documents. For clarifications, please contact the undersigned at {{company.phone}} or {{company.email}}.</p>'
        . brgy_tpl_company_sign()];

    $out[] = ['company_profile', 'Company profile', 'proforma', 'bid', 'company_profile', 0, 'company.name',
        'Short profile of the company for bid and eligibility envelopes.',
        brgy_tpl_head('COMPANY PROFILE', false)
        . '<table class="ft"><tbody><tr><td style="width:34%">Registered name</td><td><b>{{company.name}}</b></td></tr><tr><td>Business address</td><td>{{company.address}}</td></tr>'
        . '<tr><td>Registration</td><td>{{company.reg_no}}</td></tr><tr><td>TIN / tax status</td><td>{{company.tin}} &nbsp; ({{company.vat_status}})</td></tr>'
        . '<tr><td>PhilGEPS registration</td><td>{{company.philgeps_no}} &nbsp; {{company.philgeps_class}}</td></tr><tr><td>Contact</td><td>{{company.phone}} &nbsp; {{company.email}}</td></tr>'
        . '<tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr></tbody></table>'
        . '<h3>Lines of business</h3><p>Supply and delivery of office, school, medical and household supplies, IT equipment and furniture; general services (janitorial, maintenance, repairs); and, through its licensed construction arm, minor infrastructure works for barangays and local government units.</p>'
        . '<h3>Facilities and people</h3><p>[Describe the warehouse, delivery vehicles, technical staff and the after-sales service offered.]</p>'
        . '<h3>Major government clients</h3><p>[List recent contracts: entity, project, amount, date completed.]</p>'];

    $out[] = ['quotation', 'Price quotation', 'proforma', 'bid', 'quotation', 0, 'signatory.name,project.title',
        'Company price quotation with the price schedule (when the entity has no form of its own).',
        brgy_tpl_head('PRICE QUOTATION', true) . brgy_tpl_addressee() . $proj
        . '<p>We offer to supply the following under the terms of your request for quotation:</p>{{lines.table}}'
        . '<p><b>Total amount: {{lines.total}}</b> ({{lines.total_words}})</p>'
        . '<table class="ft"><tbody><tr><td style="width:32%">Delivery period</td><td>{{project.delivery_days}} from receipt of the purchase order / notice to proceed</td></tr>'
        . '<tr><td>Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr>'
        . '<tr><td>Validity of the offer</td><td>As stated in the RFQ</td></tr></tbody></table>'
        . '<p>Prices include all applicable taxes, delivery and handling. We confirm that the items offered comply with the specifications stated in your request.</p>'
        . brgy_tpl_company_sign('Submitted by:')];

    $out[] = ['price_schedule', 'Price schedule / bill of quantities', 'proforma', 'bid', 'price_schedule', 0, 'project.title',
        'Price schedule from the project lines (use the entity\'s own schedule form when its documents prescribe one).',
        brgy_tpl_head('PRICE SCHEDULE', true) . $proj . '<p></p>{{lines.table}}<p><b>Total bid price: {{lines.total}}</b><br>({{lines.total_words}})</p>'
        . '<p>Name of bidder: <b>{{company.name}}</b><br>Signature of the authorized representative:</p>' . brgy_tpl_company_sign('')];

    $out[] = ['technical_proposal', 'Technical proposal and statement of compliance', 'proforma', 'bid', 'technical_proposal', 0, 'project.title',
        'Item-by-item statement of compliance with the technical specifications.',
        brgy_tpl_head('TECHNICAL PROPOSAL AND STATEMENT OF COMPLIANCE', true) . $proj
        . '<p>We state below, item by item, our compliance with the technical specifications. Where "Comply" is stated, the offer meets or exceeds the requirement; supporting brochures and data sheets are attached.</p>'
        . '<table class="ft"><thead><tr><th style="width:6%">Item</th><th>Specification required</th><th style="width:30%">Offer (brand, model, specification)</th><th style="width:12%">Statement</th></tr></thead>'
        . '<tbody><tr><td>1</td><td>{{project.specs}}</td><td>[Brand / model offered]</td><td>Comply</td></tr></tbody></table>'
        . '<p><b>Delivery:</b> {{project.delivery_days}} at {{project.delivery_site}}. <b>Warranty:</b> {{project.warranty_months}}.</p>' . brgy_tpl_company_sign('Certified correct:')];

    $out[] = ['method_statement', 'Method statement (works / services)', 'proforma', 'bid', 'method_statement', 0, 'project.title',
        'How the works or services will be carried out: sequence, resources, safety and quality control.',
        brgy_tpl_head('METHOD STATEMENT', true) . $proj
        . '<h3>1. Scope</h3><p>{{project.specs}}</p><h3>2. Mobilization</h3><p>[Site set-up, permits, coordination with the barangay and the end-user ({{project.end_user}}).]</p>'
        . '<h3>3. Sequence of work</h3><ol><li>[Activity]</li><li>[Activity]</li><li>[Activity]</li></ol><h3>4. Manpower and equipment</h3><p>[List of key personnel and equipment.]</p>'
        . '<h3>5. Quality control and testing</h3><p>[Tests, inspections and hold points; acceptance by the entity\'s inspection committee.]</p><h3>6. Safety and environment</h3><p>[Safety plan, signage, housekeeping, disposal.]</p>'
        . '<h3>7. Schedule</h3><p>Completion within {{project.delivery_days}} from the notice to proceed.</p>' . brgy_tpl_company_sign('Prepared by:')];

    $out[] = ['implementation_schedule', 'Implementation / delivery schedule', 'proforma', 'bid', 'schedule', 0, 'project.title',
        'Schedule of deliveries or activities over the contract period.',
        brgy_tpl_head('IMPLEMENTATION / DELIVERY SCHEDULE', true) . $proj
        . '<table class="ft"><thead><tr><th style="width:6%">No.</th><th>Activity / delivery</th><th style="width:18%">Start</th><th style="width:18%">Finish</th><th style="width:16%">Weight %</th></tr></thead><tbody>'
        . '<tr><td>1</td><td>Mobilization / receipt of PO or NTP</td><td>Day 1</td><td>Day 3</td><td>5</td></tr><tr><td>2</td><td>[Delivery / work package]</td><td></td><td></td><td></td></tr>'
        . '<tr><td>3</td><td>Inspection and acceptance</td><td></td><td></td><td></td></tr></tbody></table>'
        . '<p>Total contract period: {{project.delivery_days}}.</p>' . brgy_tpl_company_sign('Prepared by:')];

    $out[] = ['supply_proposal', 'Supply and services proposal', 'proforma', 'bid', 'proposal', 0, 'project.title',
        'Narrative proposal for supply and related services (training, installation, after-sales).',
        brgy_tpl_head('SUPPLY AND SERVICES PROPOSAL', true) . brgy_tpl_addressee() . $proj
        . '<p>{{company.name}} proposes to supply and deliver the items below, together with the related services described, for <b>{{lines.total}}</b>.</p>{{lines.table}}'
        . '<h3>Related services</h3><ul><li>Delivery, unloading and installation at {{project.delivery_site}}</li><li>Orientation of the end-users</li><li>Warranty service: {{project.warranty_months}}</li></ul>'
        . brgy_tpl_company_sign()];

    $out[] = ['scope_of_work', 'Scope-of-work proposal', 'proforma', 'bid', 'proposal', 0, 'project.title',
        'Proposal for services or works: scope, deliverables, exclusions and price.',
        brgy_tpl_head('SCOPE-OF-WORK PROPOSAL', true) . brgy_tpl_addressee() . $proj
        . '<h3>Scope of work</h3><p>{{project.specs}}</p><h3>Deliverables</h3><ul><li>[Deliverable]</li><li>[Deliverable]</li></ul>'
        . '<h3>Exclusions</h3><p>[Items not included.]</p><h3>Price</h3>{{lines.table}}<p>Duration: {{project.delivery_days}}. Payment: {{project.payment_terms}}.</p>' . brgy_tpl_company_sign()];

    $out[] = ['supplier_request', 'Request for quotation to a supplier / subcontractor', 'internal', 'internal', 'supplier_request', 0, 'project.title',
        'Asks suppliers and subcontractors for prices and their certifications (kept apart from the sale to the barangay).',
        brgy_tpl_head('REQUEST FOR QUOTATION (SUPPLIER / SUBCONTRACTOR)', true)
        . '<p>To: [Supplier / subcontractor]<br>Address: [Address]</p><p>We are preparing an offer for <b>{{project.title}}</b> for {{entity.name}}. Please quote your best price and delivery for the following, valid for at least 30 days:</p>'
        . '{{lines.table}}<p>Please include with your quotation: your business permit, BIR registration, product certifications or licenses, warranty terms and delivery lead time. '
        . 'Prices to us are separate from our prices to the procuring entity; this request does not disclose the entity\'s budget.</p>'
        . '<p>Reply to {{project.preparer}} at {{company.email}} by [date].</p>' . brgy_tpl_company_sign('Requested by:')];

    $out[] = ['bid_approval_sheet', 'Internal bid approval sheet', 'internal', 'internal', 'internal', 0, 'project.no',
        'Go / no-go, costing and margin, compliance, and the sign-off of the preparer, checker and approver before submission.',
        brgy_tpl_head('INTERNAL BID APPROVAL SHEET', true)
        . '<table class="ft"><tbody><tr><td style="width:32%">Project no.</td><td>{{project.no}}</td></tr><tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr>'
        . '<tr><td>Mode / law</td><td>{{project.mode}}, {{project.regime}}</td></tr><tr><td>ABC</td><td>{{project.abc}}</td></tr><tr><td>Bid amount</td><td><b>{{project.bid_amount}}</b></td></tr>'
        . '<tr><td>Bid deadline</td><td>{{project.bid_deadline}}</td></tr></tbody></table>'
        . '<h3>Costing and margin (confidential)</h3>{{costing.table}}<h3>Compliance checklist</h3>{{requirements.table}}'
        . '<h3>Remarks</h3><p>[Risks, assumptions, supplier quotations relied on, payment record of the entity.]</p>'
        . brgy_tpl_sigs([['Prepared by', '{{project.preparer}}'], ['Checked by', '{{project.checker}}'], ['Approved by', '{{project.approver}}']])];

    $out[] = ['contract_review_sheet', 'Contract review sheet', 'internal', 'award', 'internal', 0, 'project.no',
        'Review of the notice of award, purchase order or contract against the bid before signing.',
        brgy_tpl_head('CONTRACT REVIEW SHEET', true)
        . '<table class="ft"><tbody><tr><td style="width:32%">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Notice of award</td><td>{{project.noa_ref}}, {{project.noa_date}}</td></tr>'
        . '<tr><td>Contract / PO no.</td><td>{{project.contract_ref}}</td></tr><tr><td>Amount awarded</td><td>{{project.contract_amount}}</td></tr><tr><td>Delivery / completion</td><td>{{project.delivery_days}}; due {{project.due_date}}</td></tr>'
        . '<tr><td>Retention</td><td>{{project.retention_pct}}</td></tr><tr><td>Liquidated damages</td><td>{{project.ld_rate}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr><tr><td>Payment terms</td><td>{{project.payment_terms}}</td></tr></tbody></table>'
        . '<h3>Checks</h3><table class="ft"><thead><tr><th>Point</th><th style="width:16%">OK</th><th style="width:36%">Remarks</th></tr></thead><tbody>'
        . '<tr><td>Items, quantities and prices agree with the approved bid</td><td></td><td></td></tr><tr><td>Delivery period and site can be met</td><td></td><td></td></tr>'
        . '<tr><td>Performance security / warranty requirements understood</td><td></td><td></td></tr><tr><td>Billing documents and payment terms understood</td><td></td><td></td></tr>'
        . '<tr><td>Taxes to be withheld by the entity noted</td><td></td><td></td></tr></tbody></table>'
        . brgy_tpl_sigs([['Reviewed by', '{{project.checker}}'], ['Approved by', '{{project.approver}}']])];

    $out[] = ['handover_memo', 'Project handover memo (sales to operations)', 'internal', 'award', 'internal', 0, 'project.no',
        'Hands the awarded contract to the project manager and warehouse with what was promised.',
        brgy_tpl_head('PROJECT HANDOVER MEMO', true)
        . '<p><b>To:</b> {{project.pm}}, Project Manager; Warehouse and Delivery<br><b>From:</b> {{project.preparer}}<br><b>Re:</b> {{project.title}} ({{project.no}})</p>'
        . '<p>The contract below was awarded to the company by {{entity.name}} (notice of award {{project.noa_ref}}, {{project.noa_date}}). Please mobilize as follows:</p>'
        . '{{lines.contract_table}}<table class="ft"><tbody><tr><td style="width:32%">Delivery site</td><td>{{project.delivery_site}}</td></tr><tr><td>Due</td><td>{{project.due_date}}</td></tr>'
        . '<tr><td>End-user</td><td>{{project.end_user}}</td></tr><tr><td>Warranty</td><td>{{project.warranty_months}}</td></tr></tbody></table>'
        . '<p>Deliveries are recorded on Deliveries & Acceptance; the signed delivery receipt and the inspection and acceptance report are filed in the Document Vault, as billing depends on them.</p>'
        . brgy_tpl_sigs([['Handed over by', '{{project.preparer}}'], ['Received by', '{{project.pm}}']])];

    // ---------------------------------------------------------------- delivery
    $out[] = ['delivery_receipt', 'Delivery receipt', 'proforma', 'delivery', 'dr', 0, 'delivery.no,entity.name',
        'Delivery receipt of the goods delivered, for the entity\'s signature.',
        brgy_tpl_head('DELIVERY RECEIPT', false)
        . '<table class="ft"><tbody><tr><td style="width:24%">DR no.</td><td><b>{{delivery.no}}</b></td><td style="width:20%">Date</td><td>{{delivery.date}}</td></tr>'
        . '<tr><td>Delivered to</td><td colspan="3">{{entity.name}}, {{delivery.to}}</td></tr><tr><td>Project</td><td colspan="3">{{project.title}} (contract / PO {{project.contract_ref}})</td></tr></tbody></table>'
        . '<p></p>{{delivery.table}}<p>Received the above items in good order and condition, subject to inspection and acceptance by the entity.</p>'
        . brgy_tpl_sigs([['Delivered by ({{company.name}})', '&nbsp;'], ['Received by (name, position, date)', '{{delivery.received_by}} {{delivery.received_position}}']])];

    $out[] = ['accomplishment_report', 'Service / accomplishment report', 'proforma', 'delivery', 'accomplishment', 0, 'project.title',
        'Report of services rendered or work accomplished for the period, for the end-user\'s certification.',
        brgy_tpl_head('SERVICE / ACCOMPLISHMENT REPORT', true) . $proj
        . '<p>Period covered: [from] to [to]</p>{{delivery.table}}'
        . '<h3>Work done</h3><p>[Describe the services rendered / work accomplished, with dates.]</p><h3>Issues and actions</h3><p>[None]</p>'
        . brgy_tpl_sigs([['Submitted by', '{{project.pm}}'], ['Certified by the end-user', '{{entity.end_user}}']])];

    // ---------------------------------------------------------------- billing and collection
    $out[] = ['transmittal', 'Transmittal letter', 'letter', 'billing', 'transmittal', 0, 'entity.name',
        'Transmits documents to the entity and asks for the receiving copy.',
        brgy_tpl_head('TRANSMITTAL', true) . brgy_tpl_addressee()
        . '<p><b>Re: {{project.title}}</b></p><p>We transmit the following documents for your appropriate action:</p>'
        . '<table class="ft"><thead><tr><th style="width:6%">No.</th><th>Document</th><th style="width:16%">Copies</th></tr></thead><tbody><tr><td>1</td><td>[Document]</td><td>1</td></tr><tr><td>2</td><td>[Document]</td><td>1</td></tr></tbody></table>'
        . '<p>Kindly acknowledge receipt by signing below.</p>' . brgy_tpl_company_sign()
        . brgy_tpl_sigs([['Received by (name, position)', '&nbsp;'], ['Date and time received', '&nbsp;']])];

    $out[] = ['billing_cover_letter', 'Billing cover letter / request for payment', 'letter', 'billing', 'billing_letter', 0, 'billing.no,signatory.name',
        'Requests payment of a billing, listing the pack enclosed.',
        brgy_tpl_head('', true) . brgy_tpl_addressee()
        . '<p><b>Subject: Request for payment, {{billing.type}} {{billing.no}} for {{project.title}}</b></p><p>Dear Sir / Madam:</p>'
        . '<p>We respectfully request payment of <b>{{billing.gross}}</b> ({{billing.gross_words}}) for the deliveries / services accepted under {{project.contract_ref}}, as detailed below:</p>'
        . '{{billing.table}}<p>Enclosed are our sales invoice no. {{billing.si_no}}, the statement of account, and the signed delivery and inspection and acceptance documents.</p>'
        . '<p>Payment may be made by check payable to <b>{{company.name}}</b> or by credit to: {{company.bank_details}}. Kindly furnish us BIR Form 2307 for the taxes withheld.</p>'
        . brgy_tpl_company_sign()];

    $out[] = ['statement_of_account', 'Statement of account', 'proforma', 'billing', 'soa', 0, 'entity.name',
        'Billings, payments (with the taxes withheld and deductions) and the balance of the contract.',
        brgy_tpl_head('STATEMENT OF ACCOUNT', true)
        . '<table class="ft"><tbody><tr><td style="width:24%">Bill to</td><td><b>{{entity.name}}</b><br>{{entity.address}}</td></tr><tr><td>Project</td><td>{{project.title}} ({{project.no}})</td></tr>'
        . '<tr><td>Contract / PO</td><td>{{project.contract_ref}}, {{project.contract_amount}}</td></tr></tbody></table><p></p>{{soa.table}}'
        . '<p>Please make payment to {{company.name}}: {{company.bank_details}}.</p>' . brgy_tpl_sigs([['Prepared by', '&nbsp;'], ['Noted by', '{{signatory.name}}']])];

    $out[] = ['collection_followup', 'Collection follow-up letter', 'letter', 'collection', 'followup_letter', 0, 'entity.name',
        'Courteous follow-up of a billing that is due.',
        brgy_tpl_head('', true) . '<p><b>{{entity.treasurer}}</b><br>Treasurer<br>{{entity.name}}<br>{{entity.address}}</p>'
        . '<p><b>Subject: Follow-up of payment, {{project.title}}</b></p><p>Dear Sir / Madam:</p>'
        . '<p>May we respectfully follow up the payment of our billing for the above project, which has been accepted and is now due. Our records show the following:</p>{{soa.table}}'
        . '<p>Should any document be lacking for the processing of the payment, please let us know so we can provide it at once.</p>' . brgy_tpl_company_sign()];

    $out[] = ['acknowledgment_receipt', 'Acknowledgment receipt (supplementary)', 'receipt', 'collection', 'or_ar', 0, 'collection.no',
        'Acknowledges a payment received. A supplementary document only: the sales invoice remains the primary document.',
        brgy_tpl_head('ACKNOWLEDGMENT RECEIPT', false)
        . '<table class="ft"><tbody><tr><td style="width:26%">No.</td><td>{{collection.no}}</td><td style="width:16%">Date</td><td>{{collection.date}}</td></tr></tbody></table>'
        . '<p>Received from <b>{{entity.name}}</b> the amount of <b>{{collection.amount_words}} ({{collection.amount}})</b> by {{collection.reference}}, in payment of billing {{billing.no}} (sales invoice no. {{billing.si_no}}) for {{project.title}}.</p>'
        . '{{collection.table}}<p style="margin-top:14px;font-weight:bold;text-align:center;border:1px solid #000;padding:6px">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p>'
        . brgy_tpl_sigs([['Received by (cashier / collector)', '&nbsp;']])];

    $out[] = ['reconciliation_sheet', 'Contract reconciliation sheet', 'internal', 'collection', 'internal', 0, 'project.no',
        'Reconciles the contract, deliveries, billings, collections, taxes, retention and the ledger.',
        brgy_tpl_head('CONTRACT RECONCILIATION SHEET', true) . '<p><b>{{project.title}}</b> ({{project.no}}), {{entity.name}}</p>{{closeout.table}}<h3>Statement of account</h3>{{soa.table}}'
        . '<h3>Differences and actions</h3><p>[None]</p>' . brgy_tpl_sigs([['Prepared by', '&nbsp;'], ['Checked by (accounting)', '&nbsp;']])];

    $out[] = ['closeout_summary', 'Project completion and closeout summary', 'internal', 'closeout', 'closeout', 0, 'project.no',
        'Final figures, papers on file, warranty and lessons learned at closeout.',
        brgy_tpl_head('PROJECT COMPLETION AND CLOSEOUT SUMMARY', true)
        . '<table class="ft"><tbody><tr><td style="width:32%">Project</td><td><b>{{project.title}}</b> ({{project.no}})</td></tr><tr><td>Procuring entity</td><td>{{entity.name}}</td></tr>'
        . '<tr><td>Contract</td><td>{{project.contract_ref}}, NOA {{project.noa_ref}}</td></tr><tr><td>Warranty until</td><td>{{project.warranty_until}}</td></tr></tbody></table>'
        . '<h3>Final figures</h3>{{closeout.table}}<h3>Papers on file</h3>{{requirements.all_table}}<h3>Lessons learned</h3><p>[What went well, what to change next time.]</p>'
        . brgy_tpl_sigs([['Prepared by', '{{project.pm}}'], ['Approved by', '{{project.approver}}']])];

    // ---------------------------------------------------------------- proforma contracts (reusable for any government contract)
    $parties = '<p>This Agreement is made on {{project.contract_date}} at {{entity.city}}, {{entity.province}}, by and between:</p>'
        . '<p><b>{{entity.name}}</b>, a government entity with office at {{entity.address}}, represented by <b>{{entity.head_name}}</b>, {{entity.head_position}} (the "ENTITY"); and</p>'
        . '<p><b>{{company.name}}</b>, {{company.reg_no}}, with business address at {{company.address}}, TIN {{company.tin}}, represented by <b>{{signatory.name}}</b>, {{signatory.position}} (the "SUPPLIER");</p>';
    $recitals = '<p><b>WHEREAS</b>, the ENTITY procured <b>{{project.title}}</b> ({{project.reference_no}}) through {{project.mode}} under {{project.regime}}, funded by {{project.funding_source}};</p>'
        . '<p><b>WHEREAS</b>, the ENTITY awarded the contract to the SUPPLIER by Notice of Award {{project.noa_ref}} dated {{project.noa_date}};</p>'
        . '<p><b>NOW, THEREFORE</b>, the parties agree as follows:</p>';
    $note = '<p style="font-size:9pt;border:1px solid #999;padding:6px"><i>Company proforma. Where the ENTITY\'s bidding documents prescribe a contract form or general and special conditions of contract, those govern; this proforma is used only as the ENTITY accepts it. Review with counsel before signing.</i></p>';
    $common = function (string $what, string $acceptance, string $extra) {
        return '<p><b>1. Contract documents.</b> The following form part of this Agreement and are read together, in this order of precedence: this Agreement; the Notice of Award; the purchase order or notice to proceed; the bid / quotation of the SUPPLIER with its price schedule; the technical specifications or terms of reference; and the general and special conditions issued by the ENTITY, if any.</p>'
            . '<p><b>2. Scope.</b> The SUPPLIER shall ' . $what . ' as listed below:</p>{{lines.contract_table}}'
            . '<p><b>3. Contract price.</b> The contract price is <b>{{project.contract_amount}}</b> ({{project.contract_amount_words}}), inclusive of all applicable taxes, duties, delivery and other charges. The ENTITY shall withhold the taxes required by law and issue BIR Form 2307 for them.</p>'
            . '<p><b>4. Period and place.</b> ' . $acceptance . '</p>'
            . '<p><b>5. Inspection and acceptance.</b> The ENTITY, through its inspection and acceptance committee, shall inspect the deliveries or work and issue its inspection and acceptance report. Items rejected shall be replaced or corrected by the SUPPLIER at its cost within the period the ENTITY sets.</p>'
            . '<p><b>6. Payment.</b> {{project.payment_terms}}. Each billing shall be supported by the sales invoice, statement of account, signed delivery receipts or accomplishment reports and the inspection and acceptance report. A retention of {{project.retention_pct}} of each payment, where applicable, shall be released after the warranty period upon the ENTITY\'s final acceptance.</p>'
            . '<p><b>7. Warranty.</b> The SUPPLIER warrants the goods / work against defects for {{project.warranty_months}} from acceptance and shall correct any defect reported within that period at its cost.</p>'
            . '<p><b>8. Liquidated damages.</b> For each day of delay beyond the agreed period not caused by the ENTITY or by force majeure, the SUPPLIER shall pay liquidated damages of {{project.ld_rate}} of the cost of the unperformed portion, deductible from any amount due.</p>'
            . $extra
            . '<p><b>10. Changes.</b> No change in quantity, scope, price or period is valid unless approved in writing by the ENTITY\'s authorized official in accordance with the applicable procurement rules.</p>'
            . '<p><b>11. Termination.</b> Either party may terminate this Agreement on the grounds and through the procedures provided by the applicable procurement law, its implementing rules and the contract documents.</p>'
            . '<p><b>12. Governing law and venue.</b> This Agreement is governed by the laws of the Philippines. Disputes shall first be settled amicably; failing that, through the dispute resolution provided by the applicable procurement rules, and the courts of the place of the ENTITY.</p>'
            . '<p><b>13. Entire agreement.</b> This Agreement and the contract documents contain the whole agreement of the parties; amendments are valid only in writing and signed by both.</p>';
    };
    $sign = '<p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p>'
        . brgy_tpl_sigs([['For the ENTITY: {{entity.head_position}}', '{{entity.head_name}}'], ['For the SUPPLIER: {{signatory.position}}', '{{signatory.name}}']])
        . '<p style="margin-top:14px">Signed in the presence of:</p>' . brgy_tpl_sigs([['Witness', '&nbsp;'], ['Witness', '&nbsp;']])
        . '<h3 style="margin-top:20px">ACKNOWLEDGMENT</h3><p>Republic of the Philippines )<br>________________________ ) S.S.</p><p>[Notarial acknowledgment to be completed by the notary public.]</p>';

    $out[] = ['contract_goods', 'Contract agreement: supply and delivery of goods (proforma)', 'contract', 'contract', 'contract', 0, 'entity.head_name,signatory.name,project.contract_amount',
        'Reusable contract for goods: parties, contract documents, scope, price, delivery, inspection, payment, retention, warranty, liquidated damages, changes, termination.',
        '<h2 style="text-align:center">CONTRACT AGREEMENT<br><span style="font-size:12pt">Supply and Delivery of Goods</span></h2>' . $note . $parties . $recitals
        . $common('supply and deliver the goods', 'The SUPPLIER shall deliver the goods to {{project.delivery_site}} within {{project.delivery_days}} from receipt of the purchase order / notice to proceed, on or before {{project.due_date}}.',
            '<p><b>9. Performance security.</b> Where required by the contract documents, the SUPPLIER shall post the performance security in the form and amount stated there, valid until final acceptance.</p>')
        . $sign];

    $out[] = ['contract_services', 'Contract agreement: general services (proforma)', 'contract', 'contract', 'contract', 0, 'entity.head_name,signatory.name,project.contract_amount',
        'Reusable contract for janitorial, maintenance, repair, catering and other general services.',
        '<h2 style="text-align:center">CONTRACT AGREEMENT<br><span style="font-size:12pt">General Services</span></h2>' . $note . $parties . $recitals
        . $common('render the services', 'The services shall be rendered at {{project.delivery_site}} from {{project.start_date}} for {{project.delivery_days}}, under the schedule in the terms of reference. Each period\'s services are certified by the end-user ({{project.end_user}}) through the accomplishment report.',
            '<p><b>9. Personnel.</b> The SUPPLIER employs and pays its personnel, complies with labor, social security and safety laws, and remains solely responsible for them; no employer-employee relationship arises between the ENTITY and the SUPPLIER\'s personnel.</p>')
        . $sign];

    $out[] = ['contract_works', 'Contract agreement: infrastructure works (proforma)', 'contract', 'contract', 'contract', 0, 'entity.head_name,signatory.name,project.contract_amount',
        'Reusable contract for minor infrastructure works. The contractor must hold the PCAB license the project calls for.',
        '<h2 style="text-align:center">CONTRACT AGREEMENT<br><span style="font-size:12pt">Infrastructure Works</span></h2>' . $note . $parties . $recitals
        . $common('carry out and complete the works', 'The works at {{project.delivery_site}} shall start upon the notice to proceed dated {{project.ntp_date}} and be completed within {{project.delivery_days}}, on or before {{project.due_date}}. Progress billings follow the accomplishment certified by the ENTITY\'s engineer.',
            '<p><b>9. License, securities and insurance.</b> The CONTRACTOR (the SUPPLIER) holds a valid PCAB license for the category and size of this project, posts the performance security required by the contract documents, and keeps the works and its workers insured as the contract documents require. It is liable for defects of the works as provided by law.</p>')
        . $sign];

    $out[] = ['subcontract', 'Subcontract agreement (company and subcontractor)', 'contract', 'internal', 'contract', 0, 'signatory.name',
        'Back-to-back subcontract for part of a barangay contract; the company stays responsible to the entity.',
        '<h2 style="text-align:center">SUBCONTRACT AGREEMENT</h2>'
        . '<p>This Agreement is made on {{date.today}} between <b>{{company.name}}</b>, represented by {{signatory.name}}, {{signatory.position}} (the "CONTRACTOR"), and <b>[Subcontractor]</b>, [address, TIN], represented by [name, position] (the "SUBCONTRACTOR").</p>'
        . '<p><b>1. Main contract.</b> The CONTRACTOR holds the contract for <b>{{project.title}}</b> with {{entity.name}} (NOA {{project.noa_ref}}). Subcontracting is allowed only as the main contract and the procurement rules allow, and the CONTRACTOR remains responsible to the entity for the whole contract.</p>'
        . '<p><b>2. Scope.</b> [Part of the work subcontracted, with quantities.]</p><p><b>3. Price and payment.</b> [Amount], paid within [days] after the entity accepts and pays the corresponding portion, less the same retention and deductions the entity applies to that portion.</p>'
        . '<p><b>4. Schedule and quality.</b> The SUBCONTRACTOR follows the specifications, schedule and inspection of the main contract and corrects rejected work at its cost.</p>'
        . '<p><b>5. Permits, licenses and personnel.</b> The SUBCONTRACTOR holds the licenses its work requires and is solely responsible for its personnel and their compliance with labor and safety laws.</p>'
        . '<p><b>6. Liquidated damages.</b> Delay caused by the SUBCONTRACTOR is charged to it at the rate the entity applies ({{project.ld_rate}}).</p>'
        . '<p><b>7. Confidentiality.</b> Prices and terms of the main contract are confidential.</p>'
        . brgy_tpl_sigs([['For the CONTRACTOR', '{{signatory.name}}'], ['For the SUBCONTRACTOR', '&nbsp;']])];

    $out[] = ['supply_agreement', 'Supply agreement with a supplier (back-to-back)', 'contract', 'internal', 'contract', 0, 'signatory.name',
        'Commits a supplier to the items, prices and delivery dates the company promised the entity.',
        '<h2 style="text-align:center">SUPPLY AGREEMENT</h2>'
        . '<p>Between <b>{{company.name}}</b> (the "BUYER"), represented by {{signatory.name}}, and <b>[Supplier]</b> (the "SELLER"), dated {{date.today}}.</p>'
        . '<p><b>1. Goods.</b> The SELLER supplies the following for the BUYER\'s contract {{project.no}} ({{project.title}}):</p><p>[Items, quantities and prices to the BUYER]</p>'
        . '<p><b>2. Delivery.</b> To [warehouse / site] on or before [date], allowing the BUYER to deliver to {{entity.name}} by {{project.due_date}}.</p>'
        . '<p><b>3. Quality and warranty.</b> The goods meet the specifications of the main contract; the SELLER replaces goods the entity rejects and honors a warranty of at least {{project.warranty_months}}.</p>'
        . '<p><b>4. Payment.</b> [Terms]. The BUYER withholds the taxes required by law.</p>'
        . brgy_tpl_sigs([['For the BUYER', '{{signatory.name}}'], ['For the SELLER', '&nbsp;']])];

    $out[] = ['po_conforme', 'Purchase order acceptance / conforme letter', 'letter', 'award', 'correspondence', 0, 'project.po_no',
        'Acknowledges the notice of award or purchase order and confirms the delivery commitment.',
        brgy_tpl_head('', true) . brgy_tpl_addressee()
        . '<p><b>Subject: Acceptance of Purchase Order / Notice of Award, {{project.title}}</b></p><p>Dear Sir / Madam:</p>'
        . '<p>We acknowledge receipt of your Notice of Award {{project.noa_ref}} dated {{project.noa_date}} and Purchase Order {{project.po_no}} for <b>{{project.contract_amount}}</b>, and confirm our acceptance (conforme) of their terms.</p>'
        . '<p>We will deliver to {{project.delivery_site}} within {{project.delivery_days}}, on or before {{project.due_date}}. Our contact person for this contract is {{project.pm}}.</p>'
        . brgy_tpl_company_sign('Conforme:')];

    // ---------------------------------------------------------------- prescribed form shells
    $shell = function (string $name, string $fields) {
        return '<h2 style="text-align:center">' . strtoupper($name) . '</h2>'
            . '<div style="border:2px solid #b45309;padding:8px;margin:8px 0;background:#fff7ed"><b>Prescribed form.</b> Paste here the current official text of this form, exactly as issued with the procuring entity\'s bidding documents or RFQ (or the GPPB\'s prescribed version they refer to), without changing its wording. '
            . 'Then fill its blanks with the details below and remove this box. The form cannot be made final until the official version it follows is stated.</div>'
            . '<p>[OFFICIAL TEXT OF THE FORM]</p><h3>Details for the blanks</h3><table class="ft"><tbody>' . $fields . '</tbody></table>';
    };
    $f = '<tr><td style="width:36%">Name of bidder</td><td>{{company.name}}</td></tr><tr><td>Address</td><td>{{company.address}}</td></tr><tr><td>Authorized representative</td><td>{{signatory.name}}, {{signatory.position}}</td></tr>'
        . '<tr><td>Procuring entity</td><td>{{entity.name}}</td></tr><tr><td>Project / reference</td><td>{{project.title}} ({{project.reference_no}})</td></tr>';
    $out[] = ['omnibus_sworn_statement', 'Omnibus sworn statement (prescribed form)', 'prescribed', 'bid', 'omnibus', 1, 'company.name,signatory.name',
        'Shell for the prescribed omnibus sworn statement: the official text is pasted in, the program fills its blanks and keeps its review.', $shell('Omnibus sworn statement', $f)];
    $out[] = ['bid_securing_declaration', 'Bid securing declaration (prescribed form)', 'prescribed', 'bid', 'bid_security', 1, 'company.name,signatory.name',
        'Shell for the prescribed bid securing declaration.', $shell('Bid securing declaration', $f . '<tr><td>ABC</td><td>{{project.abc}}</td></tr>')];
    $out[] = ['bid_form', 'Bid form / price quotation form (prescribed by the entity)', 'prescribed', 'bid', 'quotation', 1, 'company.name,signatory.name',
        'Shell for the bid or quotation form the entity issued; the price schedule of the project is below to copy the figures.', $shell('Bid form / price quotation form', $f . '<tr><td>Total bid</td><td>{{project.bid_amount}} ({{project.bid_amount_words}})</td></tr>') . '{{lines.table}}'];
    return $out;
}

function brgy_seed_templates(): void
{
    $n = 0;
    foreach (brgy_templates() as [$code, $name, $cat, $stage, $doc, $pres, $req, $desc, $body]) {
        $n += 10;
        if (DB::value('SELECT id FROM brgy_templates WHERE code = :c', ['c' => $code])) {
            continue;
        }
        [$body, $layout] = brgy_tpl_split($body);
        $body = BrgyDocs::sanitize($body);
        $layout = $layout ? json_encode($layout, JSON_UNESCAPED_UNICODE) : null;
        $id = DB::insert('brgy_templates', ['code' => $code, 'name' => $name, 'category' => $cat, 'stage' => $stage, 'doc_type' => $doc, 'description' => $desc, 'body' => $body,
            'paper' => in_array($code, ['contract_goods', 'contract_services', 'contract_works'], true) ? 'Folio' : 'A4', 'is_prescribed' => $pres,
            'official_source' => $pres ? 'https://www.gppb.gov.ph/ (and the bidding documents of the procuring entity)' : null, 'required_fields' => $req, 'sort_order' => $n, 'layout_json' => $layout]);
        DB::insert('brgy_template_versions', ['template_id' => $id, 'version_no' => 1, 'name' => $name, 'body' => $body, 'layout_json' => $layout, 'change_note' => 'Supplied with the program']);
    }
}

/** Everything the module needs on a new database */
function seed_brgy_master_data(): void
{
    brgy_seed_settings();
    brgy_seed_doc_types();
    brgy_seed_modes();
    brgy_seed_references();
    brgy_seed_requirements();
    brgy_seed_templates();
    require_once __DIR__ . '/brgy_sop_seed.php';
    brgy_seed_sop();
}
