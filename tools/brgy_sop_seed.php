<?php
declare(strict_types=1);

/**
 * Barangay Contracts: the company's Standard Operating Procedure (SOP) and its papers.
 *
 *  - the steps of the two processes, exactly as the SOP lists them: I. Small Value Procurement (A to F) and
 *    II. Bidding (A to G) with its payment process flow;
 *  - the papers each step handles (how each is made, how many copies, who signs, notarized or certified true copy);
 *  - the proforma forms the company prepares for the barangay (transaction form, purchase request, purchase order,
 *    inspection and acceptance report, requisition and issue slip, request for quotation / canvass, abstract of
 *    canvass, disbursement voucher, transmittal and the bidding papers).
 *
 * tools/seed.php calls brgy_seed_sop() on a new database; the migration 2026_11_03 inserts the same rows into a
 * database in use. Everything here is a starting list: SOP Steps & Papers and Proforma & Form Templates change it.
 */

function brgy_sop_settings(): array
{
    $keys = ['brgy_sop_hold_billing', 'brgy_sop_review_other', 'brgy_sop_need_scans', 'brgy_svp_limit', 'brgy_tradename', 'brgy_txn_types', 'brgy_accounting_dept'];
    return array_intersect_key(Brgy::SETTINGS, array_flip($keys));
}

function brgy_sop_doc_types(): array
{
    // code, name, group, company paper, needs validity, usual validity (months)
    return [
        ['txn_form', 'Transaction form', 'other', 0, 0, null],
        ['bidding_form', 'Bidding form (details of the bidding)', 'bid', 0, 0, null],
        ['pr', 'Purchase request (PR)', 'contract', 0, 0, null],
        ['ris', 'Requisition and issue slip (RIS)', 'delivery', 0, 0, null],
        ['canvass', 'Canvass / request for quotation signed by a supplier', 'bid', 0, 0, null],
        ['abstract', 'Abstract of canvass / bids', 'bid', 0, 0, null],
        ['dv', 'Disbursement voucher (DV)', 'billing', 0, 0, null],
        ['itb', 'Invitation to bid (ITB)', 'bid', 0, 0, null],
        ['coa_letter', 'Letter to COA', 'bid', 0, 0, null],
        ['abc', 'Approved budget for the contract (ABC)', 'bid', 0, 0, null],
        ['aboitiz', 'Aboitiz requirements', 'bid', 0, 0, null],
        ['spa', 'Special power of attorney (SPA) of the representative', 'bid', 0, 0, null],
        ['letter_intent', 'Letter of intent', 'bid', 0, 0, null],
        ['production', 'Production details', 'technical', 0, 0, null],
        ['manpower', 'Manpower schedule', 'technical', 0, 0, null],
        ['warranty_cert', 'Warranty certificate', 'technical', 0, 0, null],
        ['bac_resolution', 'BAC resolution', 'contract', 0, 0, null],
        ['post_qual', 'Post-qualification documents', 'contract', 0, 0, null],
        ['post_qual_notice', 'Notice of post-qualification', 'contract', 0, 0, null],
        ['completion_cert', 'Certificate of completion', 'delivery', 0, 0, null],
    ];
}

/**
 * The papers: [code, name, doc type, template, source, copies, signature, notarized, certified true copy, condition, description]
 * source: prepare (made by the company from a proforma), company (company paper on file, printed from PhilGEPS),
 *         record (made in the program: sales invoice, official receipt), supplier (filled and signed by a supplier),
 *         received (handed over by the sales representative or the barangay)
 */
function brgy_sop_papers(): array
{
    return [
        ['txn_form', 'Transaction form', 'txn_form', 'txn_form', 'prepare', 1, 1, 0, 0, null, 'Series-numbered form of the transaction: tradename, type, barangay, cheque, amounts, requestor, inspector, purpose.'],
        ['pr', 'Purchase request (PR)', 'pr', 'purchase_request', 'prepare', 1, 1, 0, 0, null, 'Signed by the requisitioner and approved by the Punong Barangay.'],
        ['po', 'Purchase order (PO)', 'po', 'purchase_order', 'prepare', 1, 1, 0, 0, null, 'Signed by the Punong Barangay; conforme of the supplier; appropriations certified.'],
        ['iar', 'Inspection and acceptance report (IAR)', 'iar', 'inspection_acceptance_report', 'prepare', 1, 1, 0, 0, null, 'Signed by the inspector and the property custodian.'],
        ['ris', 'Requisition and issue slip (RIS)', 'ris', 'requisition_issue_slip', 'prepare', 1, 1, 0, 0, null, 'Requested, approved for issuance, received and issued.'],
        ['canvass', 'Canvass forms (request for quotation)', 'canvass', 'request_for_quotation', 'prepare', 3, 0, 0, 0, null, 'One for each of the three suppliers canvassed; each supplier fills in and signs its own.'],
        ['abstract', 'Abstract of canvass', 'abstract', 'abstract_of_canvass', 'prepare', 1, 1, 0, 0, null, 'The quotations side by side, signed by the BAC and approved by the Punong Barangay.'],
        ['dv', 'Disbursement voucher (DV)', 'dv', 'disbursement_voucher', 'prepare', 1, 1, 0, 0, null, 'Boxes A to E: appropriations, funds available, approval, payment received, accounting entries.'],
        ['si', 'Sales invoice (SI)', 'invoice', null, 'record', 1, 1, 0, 0, null, 'The BIR-registered invoice issued from the billing pack; attach the signed copy.'],
        ['or', 'Official receipt (OR) / collection receipt', 'or_ar', null, 'record', 1, 1, 0, 0, null, 'Issued for the cheque received (Collections & Retention); attach the signed copy.'],
        ['form_2307', 'BIR Form 2307', 'form_2307', null, 'prepare', 3, 1, 0, 0, null, 'Prepared on the BIR form for the barangay; all three copies signed by the proprietor.'],
        ['transmittal', 'Transmittal', 'transmittal', 'document_transmittal', 'prepare', 1, 1, 0, 0, null, 'Lists the papers released; the signed transmittal must always come back.'],
        ['bidding_form', 'Bidding form', 'bidding_form', 'bidding_form', 'prepare', 1, 0, 0, 0, null, 'The details of the bidding given by the sales representative.'],
        ['itb', 'Invitation to bid (ITB)', 'itb', 'invitation_to_bid', 'prepare', 1, 1, 0, 0, null, 'Issued in the name of the BAC.'],
        ['coa_letter', 'Letter to COA', 'coa_letter', 'letter_to_coa', 'prepare', 1, 1, 0, 0, null, 'Informs the COA auditor of the bidding schedule.'],
        ['abc', 'Approved budget for the contract (ABC)', 'abc', 'approved_budget_contract', 'prepare', 1, 1, 0, 0, 'extra_bid_papers', 'Asked by the Cebu City offices.'],
        ['aboitiz', 'Aboitiz requirements', 'aboitiz', null, 'prepare', 1, 1, 0, 0, 'extra_bid_papers', 'Asked by the Cebu City offices; attach the papers as submitted.'],
        ['spa', 'Special power of attorney (SPA)', 'spa', 'special_power_of_attorney', 'prepare', 1, 1, 1, 0, null, 'Authorizes the representative to attend the pre-bid conference and the bidding.'],
        ['bsd', 'Bid securing declaration', 'bid_security', 'bid_securing_declaration', 'prepare', 1, 1, 1, 0, null, 'For notarization.'],
        ['oss', 'Omnibus sworn statement', 'omnibus', 'omnibus_sworn_statement', 'prepare', 1, 1, 1, 0, null, 'For notarization.'],
        ['philgeps_reg', 'PhilGEPS registration', 'philgeps_cert', null, 'company', 1, 0, 0, 1, null, 'Printed from PhilGEPS; stamped Certified True Copy.'],
        ['sec_dti', 'DTI / SEC registration', 'sec_dti', null, 'company', 1, 0, 0, 1, null, 'Printed from PhilGEPS; stamped Certified True Copy.'],
        ['mayors_permit', "Mayor's permit", 'mayors_permit', null, 'company', 1, 0, 0, 1, null, 'Printed from PhilGEPS; stamped Certified True Copy.'],
        ['cor', 'Certificate of registration (COR)', 'bir_2303', null, 'company', 1, 0, 0, 1, null, 'BIR Form 2303; stamped Certified True Copy.'],
        ['tax_clearance', 'Tax clearance', 'tax_clearance', null, 'company', 1, 0, 0, 1, null, 'Printed from PhilGEPS; stamped Certified True Copy.'],
        ['afs', 'Audited financial statements', 'afs', null, 'company', 1, 0, 0, 1, null, 'Printed from PhilGEPS; stamped Certified True Copy.'],
        ['itr', 'Income tax return', 'itr', null, 'company', 1, 0, 0, 1, null, 'Printed from PhilGEPS; stamped Certified True Copy.'],
        ['slcc_ongoing', 'Statement of ongoing contracts', 'slcc', 'statement_ongoing_contracts', 'prepare', 1, 1, 0, 0, null, 'All ongoing government and private contracts.'],
        ['slcc_similar', 'Statement of similar contracts', 'slcc', 'statement_similar_contracts', 'prepare', 1, 1, 0, 0, null, 'Completed contracts similar to the project.'],
        ['supplier_profile', 'Supplier profile', 'company_profile', 'supplier_profile', 'prepare', 1, 1, 0, 0, null, 'The supplier profile asked by the barangay: the firm, the person managing its affairs, the liaison officer.'],
        ['letter_intent', 'Letter of intent', 'letter_intent', 'letter_of_intent', 'prepare', 1, 1, 0, 0, null, 'Intent to participate in the bidding.'],
        ['tech_specs', 'Technical specifications', 'technical_proposal', 'technical_specifications', 'prepare', 1, 1, 0, 0, null, 'Section VII: the specifications with the statement of compliance.'],
        ['production', 'Production details', 'production', 'production_details', 'prepare', 1, 1, 0, 0, null, 'Where and how the goods are produced or sourced.'],
        ['manpower', 'Manpower schedule', 'manpower', 'manpower_schedule', 'prepare', 1, 1, 0, 0, null, 'People assigned to the project and when.'],
        ['warranty_cert', 'Warranty certificate', 'warranty_cert', 'warranty_certificate', 'prepare', 1, 1, 0, 0, null, 'The warranty on the goods or the work.'],
        ['bac_resolution', 'BAC resolution', 'bac_resolution', 'bac_resolution', 'prepare', 1, 1, 0, 0, null, 'Recommends the award; signed by the BAC.'],
        ['noa', 'Notice of award (NOA)', 'noa', 'notice_of_award', 'prepare', 1, 1, 0, 0, null, 'Signed by the Punong Barangay; conforme of the company.'],
        ['ntp', 'Notice to proceed (NTP)', 'ntp', 'notice_to_proceed', 'prepare', 1, 1, 0, 0, null, 'Signed by the Punong Barangay; conforme of the company.'],
        ['post_qual_notice', 'Notice of post-qualification', 'post_qual_notice', 'notice_of_post_qualification', 'prepare', 1, 1, 0, 0, null, 'The BAC informs the company that it passed the post-qualification; received by the company.'],
        ['post_qual', 'Post-qualification documents', 'post_qual', null, 'prepare', 1, 1, 0, 0, null, 'The papers asked for the post-qualification; attach them as submitted.'],
        ['completion_cert', 'Certificate of completion', 'completion_cert', 'certificate_of_completion', 'prepare', 1, 1, 0, 0, null, 'Signed by the inspector and the Barangay Captain.'],
    ];
}

/**
 * The steps: [process, code, step no., section, section title, title, instructions, papers, checks, action, who]
 * The wording follows the SOP. Actions (what the program does at the step) are explained in BrgySop::ACTIONS.
 */
function brgy_sop_steps(): array
{
    $A = 'Pre-processing';
    $B = 'Preparation of procurement documents';
    $D = 'Review and monitoring';
    $E = 'Accounting and release';
    $special = ' Special case (F): if payment has not yet been released, do NOT prepare the sales invoice, the official receipt and the BIR Form 2307; monitor the transaction until payment is available. Once payment is released, proceed with the preparation of the billing documents and continue the normal closing process.';
    return [
        // I. SMALL VALUE PROCUREMENT PROCESS
        ['svp', 'svp1', 1, 'A', $A, 'Receive transaction details',
            'The Sales Representative shall provide the project title, the approved budget, the requestor, the inspector and the complete item specifications / particulars. If complete details are already available, document preparation may proceed immediately. Otherwise, additional research and price validation shall be conducted before processing.',
            'txn_form', 'Project title|Approved budget|Requestor|Inspector|Complete item specifications / particulars', 'details', 'Sales Representative'],
        ['svp', 'svp2', 2, 'B', $B, 'Prepare the required documents',
            'The following documents shall be prepared: Purchase Request (PR), Purchase Order (PO), Inspection and Acceptance Report (IAR), Requisition and Issue Slip (RIS), Canvass, Abstract of Canvass, Disbursement Voucher (DV).',
            'pr,po,iar,ris,canvass,abstract,dv', '', 'prepare', 'Documentation staff'],
        ['svp', 'svp3', 3, 'B', $B, 'Supplier canvassing',
            'Three (3) supplier representatives shall complete and sign the canvass forms. For a complete canvass: three suppliers signed.',
            '', 'Three suppliers completed and signed the canvass forms', 'canvass', 'Documentation staff'],
        // v39: step 4 is the price schedule (it was the SI, OR and 2307, which are not needed in practice)
        ['svp', 'svp4', 4, 'C', 'Price schedule', 'Prepare price schedule',
            'Prepare the price schedule after the canvass: the items with their quantities, unit costs and unit prices (Price schedule tab). The items typed on the purchase request can be taken over. It has to agree with the company\'s quotation in the canvass. When the step is done, the purchase order is recorded as the award and the item tables of the forms still in draft are filled from the price schedule.',
            '', '', 'price_schedule', 'Documentation staff'],
        ['svp', 'svp5', 5, 'D', $D, 'Review documents',
            'Conduct a thorough review to ensure accuracy, completeness, proper signatures and correct computations.',
            '', 'Accuracy|Completeness|Proper signatures|Correct computations', 'review', 'Reviewer'],
        ['svp', 'svp6', 6, 'D', $D, 'File and monitor records',
            'Retain copies of all essential documents for monitoring and future reference. Encode transaction details into the Monitoring System.',
            '', 'Copies of all essential documents retained|Transaction details encoded in the Monitoring System', 'monitor', 'Documentation staff'],
        ['svp', 'svp7', 7, 'E', $E, 'Forward documents',
            'Forward all retained copies to the Accounting Department for final processing and closing.',
            '', '', 'forward', 'Documentation staff'],
        ['svp', 'svp8', 8, 'E', $E, 'Prepare transmittal',
            'Prepare the transmittal documents for routing and tracking purposes.',
            'transmittal', '', 'transmittal', 'Documentation staff'],
        ['svp', 'svp9', 9, 'E', $E, 'Release of documents',
            'Documents may be delivered by the Sales Representative, or released directly to the Barangay Office for pick-up. The signed transmittal must always be returned for documentation and tracking.',
            '', '', 'release', 'Sales Representative / office'],

        // II. BIDDING PROCESS
        ['bidding', 'bid1', 1, 'A', 'Receipt of bidding information', 'Receive bidding details',
            'The Sales Representative shall provide the bidding form and complete project details.',
            'bidding_form', 'Bidding form received|Complete project details', 'details', 'Sales Representative'],
        ['bidding', 'bid2', 2, 'A', 'Receipt of bidding information', 'Verify completeness',
            'Ensure the following are finalized before processing: specifications, approved schedule, bid dates, submission requirements.',
            '', 'Specifications|Approved schedule|Bid dates|Submission requirements', 'checks', 'Bid officer'],
        ['bidding', 'bid3', 3, 'A', 'Receipt of bidding information', 'Posting on PhilGEPS',
            'Post the bidding opportunity manually on PhilGEPS.',
            '', 'Posted on PhilGEPS', 'philgeps', 'Bid officer'],
        ['bidding', 'bid4', 4, 'B', 'Preparation of bidding documents', 'Prepare pre-bid documents',
            'Prepare the Invitation to Bid (ITB), the Purchase Request (PR) and the Letter to COA; for the Cebu City offices also the Approved Budget for the Contract (ABC) and the Aboitiz requirements.',
            'itb,pr,coa_letter,abc,aboitiz', '', 'prepare', 'Bid officer'],
        ['bidding', 'bid5', 5, 'C', 'Representative assignment', 'Assign authorized representative',
            'Assign a representative to attend the pre-bid conference and the actual bidding. Prepare the Special Power of Attorney (SPA).',
            'spa', 'Representative for the pre-bid conference|Representative for the actual bidding', 'representative', 'Proprietor'],
        ['bidding', 'bid6', 6, 'D', 'Eligibility and legal documents', 'Prepare legal documents',
            'Prepare the following for notarization: Bid Securing Declaration, Omnibus Sworn Statement.',
            'bsd,oss', 'Notarized', 'prepare', 'Bid officer'],
        ['bidding', 'bid7', 7, 'D', 'Eligibility and legal documents', 'Print eligibility documents',
            'Retrieve and print from PhilGEPS: PhilGEPS registration, DTI/SEC registration, Mayor\'s permit, Certificate of Registration (COR), Tax clearance, Audited financial statements, Income tax return.',
            'philgeps_reg,sec_dti,mayors_permit,cor,tax_clearance,afs,itr', '', 'prepare', 'Bid officer'],
        ['bidding', 'bid8', 8, 'E', 'Additional supporting documents', 'Prepare additional requirements',
            'Statement of ongoing contracts, statement of similar contracts, supplier profile, letter of intent, technical specifications, production details, manpower schedule, warranty certificate.',
            'slcc_ongoing,slcc_similar,supplier_profile,letter_intent,tech_specs,production,manpower,warranty_cert', '', 'prepare', 'Bid officer'],
        ['bidding', 'bid9', 9, 'F', 'Final review and submission', 'Organize and label documents',
            'Arrange all documents properly and stamp them as "Certified True Copy."',
            '', 'Documents arranged and labelled|Stamped "Certified True Copy"', 'checks', 'Bid officer'],
        ['bidding', 'bid10', 10, 'F', 'Final review and submission', 'Secure signatures',
            'Present documents to the proprietor for signature.',
            '', '', 'signatures', 'Proprietor'],
        ['bidding', 'bid11', 11, 'F', 'Final review and submission', 'Final verification',
            'Conduct final checking before submission. The bid is reviewed and approved for submission (two different people).',
            '', '', 'verify', 'Checker and approver'],
        ['bidding', 'bid12', 12, 'F', 'Final review and submission', 'Submit documents',
            'Endorse documents to the assigned bidding representative.',
            '', '', 'submit', 'Authorized representative'],
        ['bidding', 'bid13', 13, 'G', 'Post-bidding process', 'Prepare post-bidding documents',
            'For the winner: Notice of Post Qualification, BAC Resolution, Notice of Award (NOA), Notice to Proceed (NTP), Post-Qualification Documents.',
            'post_qual_notice,bac_resolution,noa,ntp,post_qual', '', 'post_bid', 'Bid officer'],
        ['bidding', 'bid14', 14, 'G', 'Post-bidding process', 'Project completion',
            'Prepare the Certificate of Completion signed by the Inspector and the Barangay Captain.',
            'completion_cert', '', 'completion', 'Project manager'],
        ['bidding', 'bid15', 15, 'G', 'Post-bidding process', 'Coordinate payment schedule',
            'Coordinate with the Barangay regarding cheque release and payment schedule. Check cheque availability: for cheque ready, proceed; for payment pending, follow up and wait.',
            '', '', 'payment', 'Collection staff'],
        ['bidding', 'pay1', 16, 'H', 'Payment process', 'Preparation of SI, OR and BIR 2307',
            'Prepare the Sales Invoice (SI), the Official Receipt (OR) and the BIR Form 2307 (3 copies, signed by the proprietor). Signed copies are uploaded to the system.' . $special,
            'si,or,form_2307', 'All copies of BIR Form 2307 signed by the proprietor', 'billing_docs', 'Billing staff'],
        ['bidding', 'pay2', 17, 'H', 'Payment process', 'Encode in monitoring',
            'Encode the transaction in the Monitoring System and retain copies of all essential documents.',
            '', 'Copies of all essential documents retained|Transaction details encoded in the Monitoring System', 'monitor', 'Documentation staff'],
        ['bidding', 'pay3', 18, 'H', 'Payment process', 'Forward to accounting',
            'Forward all retained copies to the Accounting Department for final processing and closing.',
            '', '', 'forward', 'Documentation staff'],
        ['bidding', 'pay4', 19, 'H', 'Payment process', 'Prepare transmittal',
            'Prepare the transmittal documents for routing and tracking purposes.',
            'transmittal', '', 'transmittal', 'Documentation staff'],
        ['bidding', 'pay5', 20, 'H', 'Payment process', 'Secure signature',
            'Release the documents and secure the signature on the transmittal; the signed transmittal must always be returned.',
            '', '', 'release', 'Sales Representative / office'],
        ['bidding', 'pay6', 21, 'H', 'Payment process', 'File for record',
            'File the transaction for record. The contract is closed out when it agrees with the ledger.',
            '', 'Filed for record', 'file', 'Documentation staff'],
    ];
}

/** Settings, number series, kinds of documents, steps and papers (safe to run again: nothing already there is changed) */
function brgy_seed_sop(): void
{
    foreach (brgy_sop_settings() as $k => $v) {
        DB::run('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = setting_value', ['k' => $k, 'v' => $v]);
    }
    DB::run("INSERT IGNORE INTO sequences (seq_key, prefix, next_no, pad) VALUES ('brgy_txn_form', 'TF-', 1, 4)");
    $n = (int)DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM brgy_doc_types');
    foreach (brgy_sop_doc_types() as [$c, $name, $g, $co, $v, $m]) {
        DB::run('INSERT IGNORE INTO brgy_doc_types (code, name, doc_group, is_company, needs_validity, validity_months, sort_order) VALUES (:c, :n, :g, :co, :v, :m, :s)',
            ['c' => $c, 'n' => $name, 'g' => $g, 'co' => $co, 'v' => $v, 'm' => $m, 's' => ($n += 10)]);
    }
    $n = 0;
    foreach (brgy_sop_papers() as [$code, $name, $doc, $tpl, $src, $copies, $sig, $notary, $ctc, $cond, $desc]) {
        DB::run('INSERT IGNORE INTO brgy_sop_papers (code, name, doc_type, template_code, source, copies, needs_signature, needs_notary, needs_ctc, condition_code, description, sort_order)
                 VALUES (:c, :n, :d, :t, :s, :cp, :sg, :nt, :ct, :cd, :ds, :o)',
            ['c' => $code, 'n' => $name, 'd' => $doc, 't' => $tpl, 's' => $src, 'cp' => $copies, 'sg' => $sig, 'nt' => $notary, 'ct' => $ctc, 'cd' => $cond, 'ds' => $desc, 'o' => ($n += 10)]);
    }
    foreach (brgy_sop_steps() as $i => [$proc, $code, $no, $sec, $secTitle, $title, $instr, $papers, $checks, $action, $who]) {
        DB::run('INSERT IGNORE INTO brgy_sop_steps (process, code, step_no, section, section_title, title, instructions, papers, checks, action, role_hint, sort_order)
                 VALUES (:p, :c, :n, :s, :st, :t, :i, :pp, :ck, :a, :w, :o)',
            ['p' => $proc, 'c' => $code, 'n' => $no, 's' => $sec, 'st' => $secTitle, 't' => $title, 'i' => $instr, 'pp' => $papers !== '' ? $papers : null,
             'ck' => $checks !== '' ? $checks : null, 'a' => $action, 'w' => $who, 'o' => ($i + 1) * 10]);
    }
    brgy_seed_sop_templates();
}

// ============================================================================ the proforma forms

/** A signature block: name over a line, then the caption lines */
function brgy_sop_sig(string $name, string $caption, string $align = 'center', string $lead = ''): string
{
    return '<div class="fx-sig" style="text-align:' . $align . '">' . ($lead !== '' ? '<div class="sg-lead">' . $lead . '</div>' : '')
        . '<div class="sg-name">' . $name . '</div><div class="sg-cap">' . $caption . '</div></div>';
}

/** Letterhead of the company for its own papers: the {{letterhead}} of the Format tab */
function brgy_sop_letterhead(): string
{
    // set on the Format tab of the template (the company letterhead shared by the templates, unless the template has its own)
    return '<div>{{letterhead}}</div>';
}

/** [code, name, category, stage, doc type, paper, needs approval, required fields, description, body] */
function brgy_sop_templates(): array
{
    $out = [];
    $date = '<p style="text-align:right">{{date.today}}</p>';

    // ------------------------------------------------------------------ transaction form
    $row = fn(string $label, string $ph, string $sec = '') => '<tr' . ($sec !== '' ? ' data-section="' . $sec . '" title="' . ucfirst(strtolower(rtrim($label, ':'))) . '"' : '') . '><td class="lbl">' . $label . '</td><td class="val">' . $ph . '</td></tr>';
    $out[] = ['txn_form', 'Transaction form', 'internal', 'internal', 'txn_form', 'HalfLetter', 0, 'txn.series,txn.date,txn.barangay',
        'Series-numbered transaction form of the SOP: tradename, type, barangay, cheque, amounts, requestor, inspector and purpose.',
        '<table class="fx fx-frame" style="width:100%"><tbody>'
        . '<tr><td style="padding:6px 10px"><b>Series No:</b> <span class="fx-series">{{txn.series}}</span></td></tr>'
        . '<tr><td class="fx-title" style="text-align:center">TRANSACTION FORM</td></tr>'
        . '<tr><td style="padding:8px 10px 10px"><table class="fx-lines" style="width:100%"><tbody>'
        . $row('DATE:', '{{txn.date}}') . $row('TRADENAME:', '{{txn.tradename}}')
        . '<tr><td class="lbl" style="vertical-align:middle">TYPE:</td><td>{{txn.types}}</td></tr>'
        . $row('BARANGAY:', '{{txn.barangay}}') . $row('CHEQUE NUMBER:', '{{txn.cheque_no}}') . $row('GROSS AMOUNT:', '{{txn.gross}}')
        . $row('WITHHOLDING TAX:', '{{txn.wht}}') . $row('CHEQUE AMOUNT:', '{{txn.cheque_amount}}') . $row('PROFIT:', '{{txn.profit}}', 'profit')
        . $row('REQUESTOR:', '{{txn.requestor}}') . $row('INSPECTOR:', '{{txn.inspector}}') . $row('PURPOSE:', '{{txn.purpose}}')
        . '</tbody></table></td></tr>'
        . '<tr><td style="padding:6px 10px"><table class="fx-lines" style="width:100%"><tbody><tr><td class="lbl" style="width:48%;text-align:left">PREPARED / RECEIVED BY:</td><td class="val"><span class="sg-name">{{txn.prepared_by}}</span></td></tr></tbody></table></td></tr>'
        . '</tbody></table>'];

    // ------------------------------------------------------------------ purchase request
    $out[] = ['purchase_request', 'Purchase request (PR)', 'proforma', 'bid', 'pr', 'A4', 0, 'entity.name',
        'Purchase request of the barangay, with the estimated unit costs, prepared by the company for the requisitioner and the Punong Barangay.',
        '<table class="fx" style="width:100%"><tbody>'
        . '<tr><td colspan="2" class="fx-title" style="text-align:center;padding:12px">PURCHASE REQUEST</td></tr>'
        . '<tr><td style="width:52%">Barangay: <b>{{entity.short}}</b><br>City/Municipality: {{entity.city}}</td><td>P.R. No. {{pr.no}}<br>Date: {{pr.date}}</td></tr>'
        . '<tr><td colspan="2" style="text-align:center;font-weight:bold">REQUISITION</td></tr>'
        . '<tr><td colspan="2" class="fx-slot">{{pr.items}}</td></tr>'
        . '<tr><td colspan="2"><b>Purpose : {{project.purpose}}</b></td></tr>'
        . '<tr><td style="height:140px;vertical-align:top">Requested by:' . brgy_sop_sig('{{project.requestor}}', 'Signature over Printed Name<br>Requisitioner') . '<div class="fx-dateline">Date</div></td>'
        . '<td style="vertical-align:top">Approved by:' . brgy_sop_sig('{{entity.head_name}}', 'Signature over Printed Name<br>Punong Barangay') . '<div class="fx-dateline">Date</div></td></tr>'
        . '</tbody></table>'];

    // ------------------------------------------------------------------ purchase order
    $out[] = ['purchase_order', 'Purchase order (PO)', 'proforma', 'contract', 'po', 'A4', 0, 'entity.name,po.no',
        'Purchase order of the barangay to the company: mode of procurement, items, penalty clause, conforme and appropriations.',
        '<table class="fx" style="width:100%"><tbody>'
        . '<tr><td colspan="2" class="fx-title" style="text-align:center;padding:12px">PURCHASE ORDER</td></tr>'
        . '<tr><td colspan="2" style="padding:0"><table class="fx-plain" style="width:100%"><tbody><tr><td style="width:50%">Barangay: <b>{{entity.short_upper}}</b><br>Tel. No. {{entity.phone}}</td><td>City/Municipality: {{entity.city}}<br>Province: {{entity.province}}</td></tr></tbody></table></td></tr>'
        . '<tr><td style="width:52%">Supplier: <b>{{company.name}}</b></td><td>P.O. No. {{po.no}}</td></tr>'
        . '<tr><td style="vertical-align:top">Address: {{company.address}}<br>TIN: &nbsp; {{company.tin}}</td><td>Date {{po.date}}<br>Mode of procurement<div style="margin-top:6px">{{po.modes}}</div></td></tr>'
        . '<tr><td colspan="2">Gentlemen:<br><span style="padding-left:28px">Please deliver to this office the following articles subject to the terms and conditions contained herein</span></td></tr>'
        . '<tr><td>Place of Delivery: {{po.place}}<br>Date of Delivery: {{po.delivery_date}}</td><td>Delivery term: {{po.delivery_term}}<br>Payment term: {{po.payment_term}}</td></tr>'
        . '<tr><td colspan="2" class="fx-slot">{{po.items}}</td></tr>'
        . '<tr><td colspan="2"><b>**{{po.total_words}} Only. **</b></td></tr>'
        . '<tr><td colspan="2" style="padding:6px 10px 14px"><div data-section="penalty" title="Penalty clause">&nbsp;&nbsp;&nbsp;&nbsp;In case of failure to make full delivery within the time specified above, a penalty of one-tenth (1/10) of one percent for everyday of delay shall be imposed.</div>'
        . '<table class="fx-plain" style="width:100%;margin-top:14px"><tbody><tr><td style="width:55%"></td><td>Very truly yours,' . brgy_sop_sig('{{entity.head_name}}', 'Signature Over Printed Name<br>Punong Barangay') . '</td></tr></tbody></table></td></tr>'
        . '<tr><td style="vertical-align:top;height:150px">Conforme:' . brgy_sop_sig('{{company.name_upper}}', 'Signature over Printed Name<br>Supplier') . '<div class="fx-dateline">Date</div></td>'
        . '<td style="vertical-align:top" data-section="appropriations" title="Existence of available appropriations">Existence of Available Appropriations of<div style="margin:6px 0">( ____________________________________ )</div><div>(amount in words) ( figure )</div>'
        . brgy_sop_sig('{{entity.appropriations_chair}}', 'Signature over Printed Name<br>Chairman, Committee on Appropriations') . '<div>Date:</div></td></tr>'
        . '</tbody></table>'];

    // ------------------------------------------------------------------ inspection and acceptance report
    $out[] = ['inspection_acceptance_report', 'Inspection and acceptance report (IAR)', 'proforma', 'delivery', 'iar', 'A4', 0, 'entity.name',
        'Inspection and acceptance report of the barangay for the items delivered: inspection by the inspector, acceptance by the custodian.',
        '<div class="fx-title" style="text-align:center;margin-bottom:8px">INSPECTION AND ACCEPTANCE REPORT</div>'
        . '<table class="fx" style="width:100%"><tbody>'
        . '<tr><td style="width:55%">Entity Name: <b>{{entity.name}}</b></td><td>Fund Cluster: {{project.fund}}</td></tr>'
        // v34: RIS No. and Date beside the IAR No. and Date; no requisitioning office and no responsibility center code
        . '<tr><td>Supplier: <b>{{company.name}}</b><br>PO No./Date: {{po.no}} / {{po.date}}</td>'
        . '<td><table class="fx-plain" style="width:100%"><tbody><tr><td style="width:55%;padding:0">IAR No.: {{iar.no}}<br>Date: {{iar.date}}</td><td style="padding:0">RIS No.: {{ris.no}}<br>Date: {{ris.date}}</td></tr>'
        . '<tr><td colspan="2" style="padding:0">Invoice No.: {{iar.invoice_no}}<br>Date: {{iar.invoice_date}}</td></tr></tbody></table></td></tr>'
        . '<tr><td colspan="2" class="fx-slot">{{iar.items}}</td></tr>'
        . '<tr><td style="text-align:center;font-weight:bold">INSPECTION</td><td style="text-align:center;font-weight:bold">ACCEPTANCE</td></tr>'
        . '<tr><td style="vertical-align:top;height:170px">Date Inspected: {{iar.date}}<p style="margin-top:10px"><span class="fx-chk is-on"></span> Inspected, verified and found in order as to quantity and specifications</p>'
        . brgy_sop_sig('{{project.inspector}}', 'Inspection Officer / Inspection Committee') . '</td>'
        . '<td style="vertical-align:top">Date Received: {{iar.date}}<p style="margin-top:10px"><span class="fx-chk is-on"></span> Complete<br><span class="fx-chk"></span> Partial (pls. specify quantity)</p>'
        . brgy_sop_sig('{{entity.treasurer}}', 'Supply and/or Property Custodian') . '</td></tr>'
        . '</tbody></table>'];

    // ------------------------------------------------------------------ requisition and issue slip
    $sigCell = fn(string $cap, string $name, string $desig) => '<td style="vertical-align:top;height:110px">' . $cap . '<div style="margin-top:34px;border-bottom:1px solid #000;width:80%;margin-left:auto;margin-right:auto"></div>'
        . '<div class="sg-name" style="text-align:center;font-weight:bold;text-decoration:underline">' . $name . '</div><div class="sg-cap" style="text-align:center">' . $desig . '</div></td>';
    $out[] = ['requisition_issue_slip', 'Requisition and issue slip (RIS)', 'proforma', 'delivery', 'ris', 'A4', 0, 'entity.name',
        'Requisition and issue slip of the barangay: requisition, issuance, and the four signatures.',
        '<table class="fx" style="width:100%"><tbody>'
        . '<tr><td colspan="6" class="fx-title" style="text-align:center;padding:12px">REQUISITION AND ISSUE SLIP</td></tr>'
        . '<tr><td style="width:12%">Barangay:<br>Tel No.:</td><td style="width:24%"><b>{{entity.short_upper}}</b><br>{{entity.phone}}</td><td style="width:20%">City/Municipality:<br>Province:</td><td style="width:20%">{{entity.city}}<br>{{entity.province}}</td><td style="width:9%">RIS No.<br>Date:</td><td>{{ris.no}}<br>{{ris.date}}</td></tr>'
        . '<tr><td colspan="6" class="fx-slot">{{ris.items}}</td></tr>'
        . '<tr><td colspan="6"><b>Purpose : {{project.purpose}}</b></td></tr>'
        . '<tr><td colspan="6" style="padding:0"><table class="fx fx-inner" style="width:100%"><tbody><tr>'
        . '<td style="width:15%;vertical-align:bottom;line-height:1.9">Signature<br>Printed Name<br>Designation<br>Date</td>'
        . $sigCell('Requested by:', '{{project.requestor}}', 'Requisitioner') . $sigCell('Approved for Issuance:', '{{entity.head_name}}', 'Punong Barangay')
        . $sigCell('Received by:', '{{project.requestor}}', 'Requisitioner') . $sigCell('Issued by:', '{{entity.treasurer}}', 'Barangay Treasurer')
        . '</tr></tbody></table></td></tr>'
        . '</tbody></table>'];

    // ------------------------------------------------------------------ request for quotation (the canvass form)
    // v34: the barangay's own format: the BAC letterhead (seal left, a picture right), the general conditions, the items with blank prices
    $out[] = ['request_for_quotation', 'Request for quotation (canvass form)', 'proforma', 'bid', 'canvass', 'A4', 0, 'entity.name',
        'The canvass form given to each supplier: the letterhead of the BAC with the seal, the general conditions, the items with blank prices for the supplier to fill in and sign.',
        '<div>{{letterhead}}</div>'
        . '<div class="fx-title" style="text-align:center;margin:12px 0 8px">REQUEST FOR QUOTATION</div>'
        . '<table class="fx-plain" style="width:100%"><tbody><tr><td style="width:56%"></td><td>DATE: <u>{{rfq.date}}</u><br>QUOTATION NO.: <u>{{pr.no}}</u></td></tr></tbody></table>'
        . '<p style="margin:12px 0 0;text-align:justify">Please quote your lowest price on the item/s listed below, subject to the General Conditions stated, stating the shortest time of delivery and submit your quotation duly signed by your representative not later than ____________________, __________ thru email: {{entity.email}} or personally at The Secretariat, Bids and Awards Committee, {{entity.short}} Barangay Hall, {{entity.short}}, {{entity.city}}.</p>'
        . '<div>{{signatories}}</div>'
        . '<div data-section="conditions" title="General conditions"><p style="margin:10px 0 2px">GENERAL CONDITIONS:</p>'
        . '<ol style="margin:0 0 6px;padding-left:2em"><li>All entries must be handwritten / typewritten legibly.</li><li>Approved Budget of the Contract (ABC) is <b>PHP {{project.abc_plain}}</b></li>'
        . '<li>Delivery period is within {{rfq.delivery_words}}.</li>'
        . '<li>Warranty shall be for a period of six (6) months for supplies and materials, one (1) year for equipment, from date of acceptance by the Procuring Entity.</li>'
        . '<li>Price shall be inclusive of VAT.</li>'
        . '<li>Please submit the following documents:<ol type="a" style="margin:0;padding-left:2.6em"><li>PhilGeps Registration Number</li><li>DTI/SEC Registration</li><li>Mayor&#39;s Permit</li>'
        . '<li>BIR Form No. 2303</li><li>Tax Clearance, if available</li></ol></li></ol></div>'
        . '{{rfq.items}}'
        . '<p style="margin:6px 0 0">After having carefully read and accepted your General Conditions, I/We quote you on the item at prices noted above.</p>'
        . '<table class="fx-plain" style="width:100%;margin-top:28px"><tbody><tr><td style="width:56%"></td><td><div style="border-top:1px solid #000;text-align:center">Printed Name / Signature</div></td></tr>'
        . '<tr><td style="padding-top:28px"><div style="border-top:1px solid #000;width:88%">NAME OF ESTABLISHMENT</div></td><td style="padding-top:28px"><div style="border-top:1px solid #000">DATE</div></td></tr></tbody></table>'];

    // ------------------------------------------------------------------ abstract of canvass
    $out[] = ['abstract_of_canvass', 'Abstract of bids as quoted (abstract of canvass)', 'proforma', 'bid', 'abstract', 'A4', 0, 'entity.name,project.title',
        'The quotations of the suppliers canvassed side by side (total, validity, brand, remarks), signed by the BAC and approved by the Punong Barangay.',
        '<table class="fx-plain" style="width:100%"><tbody><tr><td style="width:58%"></td><td>P.R No. : {{pr.no}}<br>Name of Project: {{project.title}}<br>Location of the Project: {{entity.location}}</td></tr></tbody></table>'
        . '<div class="fx-title" style="text-align:center;margin:18px 0 4px;text-decoration:underline">ABSTRACT OF BIDS AS QUOTED</div>'
        . '<p style="text-align:right">( {{abstract.date}} )</p>'
        . '<p><b>Project Name: {{project.title}}<br>Project Location: {{entity.name}}, {{entity.city}}<br>Implementing Office: {{project.end_user}}</b></p>'
        . '<p style="margin:12px 0 2px"><b>Approved Budget for the Contract: {{project.abc}}</b></p>'
        . '{{abstract.table}}'
        . '<div style="margin-top:34px">{{entity.bac_signatures}}</div>'
        . '<div>{{signatories}}</div>'];

    // ------------------------------------------------------------------ disbursement voucher
    $box = fn(string $tag, string $title) => '<span class="fx-tag">' . $tag . '</span> <b>' . $title . '</b>';
    // v38: the names and positions are marked (sg-name, sg-cap) so the Fill in the blanks panel lists them
    $dvSig = fn(string $name, string $pos) => '<div style="margin-top:26px">Signature:<br>Printed Name: <b><u><span class="sg-name">' . $name . '</span></u></b><br>Position: <span class="sg-cap">' . $pos . '</span><br><b>Date:</b></div>';
    $out[] = ['disbursement_voucher', 'Disbursement voucher (DV)', 'proforma', 'billing', 'dv', 'A4', 0, 'entity.name,dv.gross',
        'Disbursement voucher of the barangay for the payment to the company: particulars, taxes withheld, net amount in words, supporting papers, boxes A to E.',
        '<table class="fx" style="width:100%"><tbody>'
        . '<tr><td colspan="2" class="fx-title" style="text-align:center;padding:10px">DISBURSEMENT VOUCHER</td><td style="width:22%;vertical-align:bottom">DV No. {{dv.no}}</td></tr>'
        . '<tr><td style="width:46%">Barangay: <b>{{entity.short}}</b></td><td>City/Municipality: {{entity.city}}</td><td>Date: {{dv.date}}</td></tr>'
        . '<tr><td>Payee: <b>{{company.name}}</b></td><td>Province: {{entity.province}}</td><td>Fund: {{dv.fund}}</td></tr>'
        . '<tr><td>Address: {{company.address}}</td><td>TIN: {{company.tin}}</td><td></td></tr>'
        . '<tr><td colspan="3" style="text-align:center;font-weight:bold">PARTICULARS</td></tr>'
        . '<tr><td colspan="3" style="padding:10px 14px"><table class="fx-plain" style="width:100%"><tbody>'
        . '<tr><td style="width:70%">To Withdraw Payment for the <b>{{dv.particulars}}</b><br>per attached supporting documents in the amount of ------------</td><td style="text-align:right;font-size:12pt;font-weight:bold;vertical-align:bottom">{{dv.gross}}</td></tr>'
        . '<tr data-section="taxes" title="Taxes withheld"><td style="padding-top:16px">{{dv.taxes}}</td><td style="text-align:right;font-size:12pt;font-weight:bold;vertical-align:bottom">{{dv.net}}</td></tr>'
        . '<tr><td colspan="2" style="padding:18px 0 0 18px">Amount in words:<br><b>{{dv.net_words_upper}}</b></td></tr>'
        . '<tr data-section="papers" title="Supporting papers attached"><td colspan="2" style="padding:18px 0 0 18px">Supporting Papers Attached:{{dv.papers}}</td></tr>'
        . '<tr><td></td><td style="text-align:right;font-size:12pt"><b>TOTAL: &nbsp; {{dv.net}}</b></td></tr>'
        . '</tbody></table></td></tr>'
        . '<tr><td colspan="3" style="padding:0"><table class="fx fx-inner" style="width:100%"><tbody><tr>'
        . '<td style="width:34%;vertical-align:top">' . $box('A', 'Certified:') . '<br>Existence of available appropriations for the charges/expenses indicated above' . $dvSig('{{entity.appropriations_chair_upper}}', 'Chairman, Committee on Appropriations') . '</td>'
        . '<td style="width:33%;vertical-align:top">' . $box('B', 'Certified:') . '<br>Funds (Cash) Available:<br><br>' . $dvSig('{{entity.treasurer_upper}}', 'Barangay Treasurer') . '</td>'
        . '<td style="vertical-align:top">' . $box('C', 'Certified:') . '<br>As to validity, propriety, and legality of claim<br>Approved: FOR PAYMENT' . $dvSig('{{entity.head_name_upper}}', 'Barangay Captain') . '</td>'
        . '</tr></tbody></table></td></tr>'
        . '<tr><td colspan="3" style="vertical-align:top;height:110px">' . $box('D', 'Received Payment:')
        . '<table class="fx-plain" style="width:100%;margin-top:16px"><tbody><tr><td style="width:50%;padding-left:40px"><b><u><span class="sg-name">{{company.name_upper}}</span></u></b><br><span class="sg-cap">Signature Over Printed Name</span></td>'
        . '<td>Check No. <u>{{dv.check_no}}</u><br>Bank Name: <u>{{dv.bank}}</u><br>OR Number: <u>{{dv.or_no}}</u></td><td style="vertical-align:middle">Date: __________</td></tr>'
        . '<tr><td style="padding-top:10px">Date: __________</td><td></td><td></td></tr></tbody></table></td></tr>'
        . '<tr><td colspan="3" style="padding:0" data-section="entries" title="E. Accounting entries"><table class="fx fx-inner" style="width:100%"><tbody>'
        . '<tr><td colspan="4"><b>E. Accounting Entries:</b></td></tr>'
        . '<tr><th style="width:36%">Account</th><th style="width:22%">Account Code</th><th style="width:21%">Debit</th><th>Credit</th></tr>'
        . str_repeat('<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>', 4)
        . '<tr><td style="vertical-align:top;height:150px"></td><td colspan="3" style="vertical-align:top">Prepared By:'
        . '<table class="fx-plain" style="width:100%;margin-top:26px"><tbody><tr><td style="width:62%;text-align:center"><div style="border-top:1px solid #000;width:70%;margin:0 auto"></div><span class="sg-name">{{entity.bookkeeper}}</span><br><span class="sg-cap">Barangay Bookkeeper</span></td><td style="text-align:center"><div style="border-top:1px solid #000;width:70%;margin:0 auto"></div>Date</td></tr></tbody></table>'
        . '<div style="margin-top:12px">Approved By:</div><table class="fx-plain" style="width:100%;margin-top:22px"><tbody><tr><td style="width:62%;text-align:center"><div style="border-top:1px solid #000;width:70%;margin:0 auto"></div><span class="sg-name">{{entity.city_accountant}}</span><br><span class="sg-cap">City/Municipal Accountant</span></td><td style="text-align:center"><div style="border-top:1px solid #000;width:70%;margin:0 auto"></div>Date</td></tr></tbody></table>'
        . '</td></tr></tbody></table></td></tr>'
        . '</tbody></table>'];

    // ------------------------------------------------------------------ transmittal of the SOP
    $out[] = ['document_transmittal', 'Transmittal of documents (SOP)', 'letter', 'billing', 'transmittal', 'A4', 0, 'entity.name,transmittal.no',
        'Transmittal of the papers released to the barangay (delivered by the sales representative or picked up at the office); the signed copy must come back.',
        brgy_sop_letterhead()
        . '<div class="fx-title" style="text-align:center;margin:6px 0 10px">TRANSMITTAL</div>'
        . '<table class="fx-plain" style="width:100%"><tbody><tr><td style="width:60%">To: <b>{{entity.head_name}}</b><br>{{entity.head_position}}<br>{{entity.name}}<br>{{entity.address}}</td>'
        . '<td style="vertical-align:top">Transmittal No.: <b>{{transmittal.no}}</b><br>Date: {{transmittal.date}}<br>Transaction: {{project.txn_form_no}}<br>P.O. No.: {{po.no}}</td></tr></tbody></table>'
        . '<p style="margin-top:12px">We are transmitting herewith the following documents for <b>{{project.title}}</b>:</p>'
        . '{{sop.papers_table}}'
        . '<p>Kindly acknowledge receipt by signing below and returning a copy of this transmittal.</p>'
        . '<div>{{signatories}}</div>'];

    // ------------------------------------------------------------------ bidding papers
    $out[] = ['bidding_form', 'Bidding form (details of the bidding)', 'internal', 'internal', 'bidding_form', 'A4', 0, 'project.title,entity.name',
        'The details of a bidding given by the sales representative: project, budget, schedule, specifications and submission requirements.',
        brgy_sop_letterhead() . '<div class="fx-title" style="text-align:center;margin:6px 0 10px">BIDDING FORM</div>'
        . '<table class="fx" style="width:100%"><tbody>'
        . '<tr><td style="width:32%">Series / project no.</td><td>{{project.no}} &nbsp; {{project.txn_form_no}}</td></tr>'
        . '<tr><td>Procuring entity</td><td><b>{{entity.name}}</b>, {{entity.city}}, {{entity.province}}</td></tr>'
        . '<tr><td>Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference (PR / ITB)</td><td>{{project.reference_no}}</td></tr>'
        . '<tr><td>Approved budget (ABC)</td><td>{{project.abc}}</td></tr><tr><td>Mode of procurement</td><td>{{project.mode}}</td></tr>'
        . '<tr><td>Pre-bid conference</td><td>{{project.prebid}}</td></tr><tr><td>Bid deadline / opening</td><td>{{project.bid_deadline}}</td></tr>'
        . '<tr><td>Requestor / end-user</td><td>{{project.requestor}} {{project.end_user}}</td></tr><tr><td>Inspector</td><td>{{project.inspector}}</td></tr>'
        . '<tr><td>Submission requirements</td><td>{{entity.requirement_notes}}</td></tr><tr><td>Specifications</td><td>{{project.specs}}</td></tr>'
        . '<tr><td>Sales representative</td><td>{{project.sales_rep}}</td></tr></tbody></table>{{lines.table}}'];


    $out[] = ['invitation_to_bid', 'Invitation to bid (ITB)', 'proforma', 'bid', 'itb', 'A4', 0, 'entity.name,project.title,project.abc',
        'Invitation to bid in the name of the Bids and Awards Committee of the barangay.',
        '<div>{{letterhead}}</div><div class="fx-title" style="text-align:center;margin:12px 0">INVITATION TO BID</div>'
        . '<p style="text-align:center"><b>{{project.title}}</b><br>{{project.reference_no}}</p>'
        . '<ol><li>The {{entity.name}}, through its approved budget, intends to apply the sum of <b>{{project.abc_words}} ({{project.abc}})</b> being the Approved Budget for the Contract (ABC) to payments under the contract for {{project.title}}. Bids received in excess of the ABC shall be automatically rejected at bid opening.</li>'
        . '<li>The {{entity.name}} now invites bids for the above procurement project. Delivery of the goods is required within {{project.delivery_days}}. Bidders should have completed a contract similar to the project.</li>'
        . '<li>Bidding will be conducted through open competitive bidding procedures using a non-discretionary "pass/fail" criterion as specified in the applicable procurement law and its implementing rules.</li>'
        . '<li>A pre-bid conference shall be held on {{project.prebid}} at {{entity.address}}.</li>'
        . '<li>Bids must be duly received by the BAC Secretariat at {{entity.address}} on or before {{project.bid_deadline}}. Late bids shall not be accepted.</li>'
        . '<li>The {{entity.name}} reserves the right to reject any and all bids, declare a failure of bidding, or not award the contract at any time prior to contract award, without thereby incurring any liability to the affected bidder or bidders.</li></ol>'
        . '<div>{{signatories}}</div>'];

    $out[] = ['letter_to_coa', 'Letter to COA', 'letter', 'bid', 'coa_letter', 'A4', 0, 'entity.name,project.title',
        'Letter of the BAC informing the COA auditor of the bidding schedule and inviting a representative as observer.',
        '<div>{{letterhead}}</div>' . $date . '<p><b>The Audit Team Leader</b><br>Commission on Audit<br>{{entity.city}}, {{entity.province}}</p>'
        . '<p><b>Subject: Invitation to observe the bidding for {{project.title}}</b></p><p>Sir/Madam:</p>'
        . '<p>The Bids and Awards Committee of {{entity.name}} respectfully informs your office of the procurement of <b>{{project.title}}</b> ({{project.reference_no}}) with an Approved Budget for the Contract of {{project.abc}}.</p>'
        . '<p>The pre-bid conference is scheduled on {{project.prebid}} and the opening of bids on {{project.bid_deadline}}, at {{entity.address}}. We would be glad to have a representative of your office attend as observer.</p>'
        . '<p>Thank you.</p><div>{{signatories}}</div>'];

    $out[] = ['approved_budget_contract', 'Approved budget for the contract (ABC)', 'proforma', 'bid', 'abc', 'A4', 0, 'entity.name,project.abc',
        'Certification of the approved budget for the contract, with its breakdown (asked by the Cebu City offices).',
        '<div>{{letterhead}}</div><div class="fx-title" style="text-align:center;margin:12px 0">APPROVED BUDGET FOR THE CONTRACT</div>'
        . '<table class="fx" style="width:100%"><tbody><tr><td style="width:32%">Project</td><td><b>{{project.title}}</b></td></tr><tr><td>Reference</td><td>{{project.reference_no}}</td></tr>'
        . '<tr><td>Source of funds</td><td>{{project.funding_source}}</td></tr><tr><td>Approved budget</td><td><b>{{project.abc}}</b> ({{project.abc_words}})</td></tr></tbody></table>'
        . '{{pr.items}}<p>This is to certify that the above amount is the approved budget for the contract of the project.</p>'
        . '<div>{{signatories}}</div>'];

    $out[] = ['special_power_of_attorney', 'Special power of attorney (SPA)', 'contract', 'bid', 'spa', 'A4', 0, 'signatory.name,project.submitter',
        'Special power of attorney of the proprietor appointing the representative for the pre-bid conference and the bidding (for notarization).',
        '<div class="fx-title" style="text-align:center;margin:6px 0 14px">SPECIAL POWER OF ATTORNEY</div>'
        . '<p><b>KNOW ALL MEN BY THESE PRESENTS:</b></p>'
        . '<p>I, <b>{{signatory.name}}</b>, of legal age, Filipino, {{signatory.position}} of <b>{{company.name}}</b>, with business address at {{company.address}}, do hereby name, constitute and appoint <b>{{project.submitter}}</b>, of legal age, as my true and lawful attorney-in-fact, for me and in my name, place and stead, to do and perform the following acts:</p>'
        . '<ol><li>To attend the pre-bid conference and the opening of bids of <b>{{project.title}}</b> ({{project.reference_no}}) of the {{entity.name}};</li>'
        . '<li>To submit, sign and receive all documents relative to the said bidding, including the bid, clarifications and notices;</li>'
        . '<li>To do all acts necessary and incidental to the foregoing.</li></ol>'
        . '<p>HEREBY GIVING AND GRANTING unto my said attorney-in-fact full power and authority to do and perform the acts above, and hereby ratifying all that my said attorney-in-fact shall lawfully do by virtue hereof.</p>'
        . '<p>IN WITNESS WHEREOF, I have hereunto set my hand this ____ day of __________ at __________.</p>'
        . '<div>{{signatories}}</div>'
        . '<p style="margin-top:22px"><b>ACKNOWLEDGMENT</b></p><p>[Notarial acknowledgment]</p>'];

    // ------------------------------------------------------------------ Step 8 of the bidding: the additional requirements (the client's own forms)
    $kv = fn(array $rows, string $w = '34%') => '<table class="fx-plain fx-kv" style="width:100%"><tbody>'
        . implode('', array_map(fn($r) => '<tr><td class="k" style="width:' . $w . '">' . $r[0] . '</td><td>: ' . $r[1] . '</td></tr>', $rows)) . '</tbody></table>';
    $bigTitle = fn(string $t, string $size = '16pt', bool $underline = true, string $margin = '10px 0 16px') => '<p style="text-align:center;margin:' . $margin . '"><span style="font-size:' . $size . ';font-weight:bold'
        . ($underline ? ';text-decoration:underline' : '') . '">' . $t . '</span></p>';
    $projectHead = '<p style="margin:0;line-height:1.3"><i>Procuring Entity:</i> <b>{{entity.title}}</b><br><i>Name of the Project:</i> <b>{{project.title}}</b><br><i>Location of the Project:</i> <b>{{project.location}}</b></p>';
    $business = '<table class="fx-plain fx-kv" style="margin:4px 0 6px"><tbody><tr><td class="k">Business Name</td><td>: <u>{{company.name}}</u></td></tr><tr><td class="k">Business Address</td><td>: <u>{{company.address}}</u></td></tr></tbody></table>';
    $submitted = '<table class="fx-plain fx-kv sg-block" style="margin-top:22px"><tbody><tr><td class="k" style="width:110px">Submitted by</td><td>: <b><u><span class="sg-name">{{signatory.name}}</span></u></b></td></tr>'
        . '<tr><td></td><td style="padding-left:14px;font-size:.92em">(Printed Name &amp; Signature)</td></tr><tr><td class="k">Designation</td><td>: <u><span class="sg-cap">{{signatory.position}}</span></u></td></tr>'
        . '<tr><td class="k">Date</td><td>: {{date.today}}</td></tr></tbody></table>';

    $out[] = ['statement_ongoing_contracts', 'Statement of all ongoing government and private contracts', 'proforma', 'bid', 'slcc', 'Legal', 0, 'company.name,project.title',
        'Ongoing government and private contracts, incl. those awarded but not started (step 8 of the bidding): the company\'s other awarded contracts not closed fill the government part; private ones are typed on the form.',
        $projectHead
        . '<p style="margin:8px 0 6px"><span class="fx-hl" style="font-size:14pt;font-weight:bold">Statement of all Ongoing Government &amp; Private Contracts including contracts awarded but not yet started</span></p>'
        . $business . '<div style="margin-top:6px">{{contracts.ongoing_table}}</div>'
        . '<p style="margin:6px 0 0">Note: This statement shall be supported with:</p><ol style="margin:0;padding-left:2.4em"><li>Purchase Order / Notice to Proceed</li><li>Notice of Award</li></ol>'
        . $submitted];

    $out[] = ['statement_similar_contracts', 'Statement of the single largest completed contract similar to the contract to be bid (SLCC)', 'proforma', 'bid', 'slcc', 'Folio', 0, 'company.name,project.title',
        'Statement identifying the bidder\'s single largest completed contract similar to the contract to be bid (step 8 of the bidding): the largest closed contract of the company of the same category.',
        $projectHead
        . '<p style="margin:6px 0 6px"><span class="fx-hl" style="font-size:14pt;font-weight:bold">Statement identifying the bidder&rsquo;s single largest completed contract similar to the contract to be bid</span></p>'
        . $business . '<div style="margin-top:6px">{{contracts.slcc_table}}</div>'
        . '<p style="margin:6px 0 0">Note: This statement shall be supported with:</p>'
        . '<p style="margin:0;padding-left:2em">1. Purchase Order &nbsp;&nbsp;&nbsp; 2. Sales Invoice &nbsp;&nbsp;&nbsp; 3. Certificate of Completion / Delivery Receipt &nbsp;&nbsp;&nbsp; 4. Notice of Award &nbsp;&nbsp;&nbsp; 5. Notice to Proceed</p>'
        . $submitted];

    $out[] = ['supplier_profile', 'Supplier profile', 'proforma', 'bid', 'company_profile', 'Legal', 0, 'company.name,company.tin',
        'The supplier profile asked by the barangay (step 8 of the bidding): the firm, its general information, the person managing its affairs and the authorized liaison officer (Contracting Settings).',
        '<div>{{letterhead}}</div>' . $bigTitle('SUPPLIER', '20pt', true, '14px 0 28px')
        . '<p style="margin:0 0 6px"><b><u>Profile</u></b></p>' . $kv([['Date', '{{date.today}}'], ['Name of Firm/Company', '<b>{{company.name_upper}}</b>'], ['Tax Account Number', '{{company.tin}}']])
        . '<p style="margin:18px 0 6px"><b><u>General Information</u></b></p>' . $kv([['Address', '{{company.address}}'], ['Telephone Number', '{{company.phone}}'], ['Email Address', '{{company.email}}']])
        . '<p style="margin:18px 0 6px"><b><u>Legal Aspect</u></b></p><p style="margin:0 0 6px">Persons Managing affairs of the Firm</p>'
        . $kv([['Name', '{{company.manager_upper}}'], ['Designation', '{{company.manager_position}}'], ['Telephone', '{{company.manager_phone}}'], ['Cellphone No.', '{{company.manager_mobile}}']])
        . '<p style="margin:18px 0 6px">Authorized Liaison Officer</p>'
        . $kv([['Name', '{{company.liaison_upper}}'], ['Designation', '{{company.liaison_position}}'], ['Telephone', '{{company.liaison_phone}}'], ['Cellphone No.', '{{company.liaison_mobile}}']])];

    $out[] = ['letter_of_intent', 'Letter of intent (manifestation of intent to participate)', 'letter', 'bid', 'letter_intent', 'Legal', 0, 'entity.name,project.title',
        'Manifestation of intent to participate in the public bidding (step 8 of the bidding), on the company letterhead.',
        '<div>{{letterhead}}</div>' . $bigTitle('Manifestation of Intent to Participate', '16pt', false, '22px 0 26px')
        . '<p><b>{{date.today}}</b></p>'
        . '<p style="margin-top:18px"><b>OFFICE OF THE CHAIRMAN</b><br>Bids and Awards Committee<br><b>{{entity.title}}</b><br>{{entity.city}}, {{entity.province}}</p>'
        . '<p>Dear Sir/Madam:</p>'
        . '<p style="text-align:justify">This refers to the procurement project entitled <b>{{project.title}}.</b></p>'
        . '<p style="text-align:justify">In this regard, <b>{{company.name}}</b>, with business address at <b>{{company.address}}</b>, hereby formally manifests its intent to participate in the public bidding for the above-mentioned project, in accordance with {{project.law_text}} and its Implementing Rules and Regulations (IRR).</p>'
        . '<p style="text-align:justify">This manifestation is submitted in compliance with the requirements of the Bidding Documents issued by your office. Should our participation be accepted, we commit to comply with all eligibility requirements, submission of bidding documents, and other conditions set forth by the Bids and Awards Committee (BAC) within the prescribed timelines.</p>'
        . '<p style="text-align:justify">We trust that this manifestation satisfies your requirement for expression of interest. We look forward to participating in the bidding process.</p>'
        . '<p style="margin-top:18px">Thank you for your consideration.</p><div>{{signatories}}</div>'];

    $out[] = ['technical_specifications', 'Technical specifications (Section VII) with the statement of compliance', 'proforma', 'bid', 'technical_proposal', 'Legal', 0, 'entity.name,project.title',
        'Section VII - Technical specifications of the bid (step 8 of the bidding): each item with its specifications, one a line, and the statement of compliance. A line in capitals or ending with ":" prints bold; **text** is bold too.',
        $bigTitle('SECTION VII-TECHNICAL SPECIFICATIONS', '16pt', true, '0 0 20px')
        . '<p><b>Date:</b> {{date.today}}</p><p>To: {{entity.short}} Barangay Hall, {{entity.city}}, {{entity.province}}<br>Address: {{entity.address}}</p>'
        . '<p>Gentlemen and/ or Ladies,</p><div>{{specs.items}}</div>'
        . '<p style="margin-top:10px">We can <b><u>comply</u></b> the technical specification stated in the Bid Form.</p><div>{{signatories}}</div>'];

    $out[] = ['production_details', 'Production details (Section VI: schedule of requirements)', 'proforma', 'bid', 'production', 'Legal', 0, 'entity.name,project.title',
        'Section VI - Schedule of requirements (step 8 of the bidding): the items with their specifications, the quantity, and the delivery period in weeks or months.',
        '<div>{{letterhead}}</div>' . $bigTitle('SECTION VI-SCHEDULE OF REQUIREMENTS', '16pt', true, '8px 0 18px') . '<div>{{sched.items}}</div><div>{{signatories}}</div>'];

    $out[] = ['manpower_schedule', 'Manpower schedule (manpower requirements)', 'proforma', 'bid', 'manpower', 'Legal', 0, 'project.title',
        'Manpower requirements of the delivery (step 8 of the bidding), on the company letterhead.',
        '<div>{{letterhead}}</div>' . $bigTitle('MANPOWER REQUIREMENTS', '18pt', false, '18px 0 30px')
        . '<p style="text-align:justify">In compliance with the requirements of the Bids and Awards Committee (BAC), and based on our standard delivery procedures and actual delivery operations, the following manpower requirement is hereby submitted:</p>'
        . '<p style="text-align:justify">All goods and services shall be delivered to the designated delivery site and shall be subject to inspection upon delivery.</p>'
        . '<table class="fx" style="width:100%;margin-top:22px"><thead><tr><th style="width:28%">PARTICULARS</th><th>DETAILS</th></tr></thead><tbody>'
        . '<tr><td><b>Project Name:</b></td><td>{{project.title}}</td></tr><tr><td style="vertical-align:middle"><b>Project Location:</b></td><td>{{project.location}}</td></tr>'
        . '<tr><td><b>Delivery Vehicle:</b></td><td>Any available vehicle</td></tr>'
        . '<tr><td style="vertical-align:middle"><b>Required Personnel</b></td><td>At least two (2) personnel composed of:<br>&bull; One (1) Driver<br>&bull; One (1) Sales Representative</td></tr></tbody></table>'
        . '<div>{{signatories}}</div>'];

    $out[] = ['warranty_certificate', 'Warranty certificate (after-sales service and warranty provision)', 'proforma', 'bid', 'warranty_cert', 'Legal', 0, 'project.title',
        'After-sales service and warranty provision (step 8 of the bidding), on the company letterhead. The warranty period comes from the project (warranty in months).',
        '<div>{{letterhead}}</div>' . $bigTitle('After-Sales Service and Warranty Provision', '11.5pt', false, '10px 0 14px')
        . '<p style="text-align:justify">The Supplier shall warrant that the {{project.title}}, including all its components, accessories, and attachments, is brand new, free from defects in materials and workmanship, and compliant with the technical specifications prescribed in the Contract.</p>'
        . '<p style="margin-bottom:0"><b>1. Warranty Period</b></p><p style="text-align:justify">The warranty shall be for a minimum period of <b>{{project.warranty_words}}</b>, reckoned from the date of final acceptance by the Procuring Entity.</p>'
        . '<p style="margin-bottom:0"><b>2. Scope of Warranty</b></p><p style="margin-bottom:2px">The warranty shall cover, but not be limited to, defects or failures in the following:</p>'
        . '<ul style="margin-top:4px"><li>Materials and workmanship of every item delivered</li><li>The performance of the goods according to the technical specifications</li><li>Components, parts, and assemblies</li>'
        . '<li>Electrical, mechanical, and operating systems, where applicable</li><li>All factory-installed parts, equipment, and accessories</li></ul>'
        . '<p style="text-align:justify">The Supplier shall undertake, at its own expense, the repair or replacement of any defective parts, including the provision of labor, tools, and consumables necessary to restore the goods to their optimal operating condition.</p>'
        . '<p style="margin-bottom:0"><b>3. After-Sales Service Obligations</b></p><p style="margin-bottom:0">The Supplier shall:</p>'
        . '<p style="text-align:justify;margin-bottom:0">a. Maintain or have access to an <b>authorized service center</b> capable of providing adequate technical support within a reasonable distance from the Procuring Entity;<br>'
        . 'b. Ensure the availability of <b>genuine spare parts</b> for a minimum period of <b>five (5) years</b> from delivery;<br>'
        . 'c. Provide <b>preventive maintenance services</b> in accordance with the manufacturer&rsquo;s recommended schedule, where applicable;<br>'
        . 'd. Respond to service requests within <b>forty-eight (48) to seventy-two (72) hours</b> from receipt of notice;<br>'
        . 'e. Deploy qualified technical personnel to perform necessary inspections, diagnostics, and repairs.</p>'
        . '<p style="margin:10px 0 0"><b>4. Exclusions from Warranty</b></p><p style="margin-bottom:2px">The warranty shall not apply to defects or damages resulting from:</p>'
        . '<ul style="margin-top:4px"><li>Misuse, negligence, or improper operation by the end-user;</li><li>Unauthorized repairs, alterations, or modifications;</li>'
        . '<li>Accidents, force majeure, or external causes beyond normal operating conditions;</li><li>Normal wear and tear of consumable items, unless proven to be factory-defective.</li></ul>'
        . '<p style="margin-bottom:0"><b>5. Warranty Security</b></p><p style="text-align:justify">In accordance with the applicable procurement law and its Implementing Rules and Regulations, the Supplier shall post a <b>warranty security</b> in any of the acceptable forms prescribed by law to guarantee the faithful performance of its warranty obligations.</p>'
        . '<div>{{signatories}}</div>'];

    // ------------------------------------------------------------------ Step 13 of the bidding: the post-bidding papers (the barangay's notices, prepared for its signature)
    $addressee = '<p style="margin-top:18px"><b>{{signatory.addressee}}</b><br>{{signatory.position_upper}}<br><b>{{company.name_upper}}</b><br>{{company.address}}</p>';

    $out[] = ['notice_of_post_qualification', 'Notice of post-qualification', 'proforma', 'award', 'post_qual_notice', 'Legal', 0, 'entity.name,project.title',
        'Notice of the BAC that the company passed the post-qualification and its bid is the lowest calculated and responsive bid (step 13 of the bidding), prepared for the BAC Chairman\'s signature.',
        '<div>{{letterhead}}</div>' . $bigTitle('Notice of Post Qualification', '14pt', false, '4px 0 22px')
        . '<p>{{date.today}}</p>' . $addressee
        . '<p style="margin-top:22px"><b><u>ATTN.: {{signatory.addressee}}</u></b></p><p style="margin-top:22px">Good day!</p>'
        . '<p style="text-align:justify;line-height:1.7">After determining your bid as the Lowest Calculated Bid for the <b>{{project.title}}</b> for {{entity.title}}, {{entity.city}} and upon the post qualification involving the careful examination, validation and verification of the eligibility, technical and financial requirements that you have submitted for bidding. We are happy to inform you that you have successfully passed the Post Qualification and therefore, now consider your bid as the Lowest Calculated and Responsive for the said Contract.</p>'
        . '<div>{{signatories}}</div>'];

    $out[] = ['notice_of_award', 'Notice of award (NOA)', 'proforma', 'award', 'noa', 'Legal', 0, 'entity.name,project.title,project.bid_amount',
        'Notice of award to the company (step 13 of the bidding), for the Punong Barangay\'s signature and the conforme of the company.',
        '<div>{{letterhead}}</div>' . $bigTitle('NOTICE OF AWARD', '14pt', false, '4px 0 22px')
        . '<p>{{date.today}}</p>' . $addressee . '<p style="margin-top:18px">Dear {{signatory.salutation}}:</p>'
        . '<p style="text-align:justify;line-height:1.6">We are happy to notify you that the contract for the <b>{{project.title}}</b> is hereby awarded to you as the single lowest calculated and responsive Bid at a contract price equivalent to <b>{{project.contract_price_text}}</b>.</p>'
        . '<p style="text-align:justify;line-height:1.6">You are therefore required to provide the performance security in the form and amount stipulated in the instruction to the Bidders within ten (10) calendar days from receipt of Notice of Award. Contract signing will be done upon submission of the required performance security. Failure to provide the performance security on the said date shall constitute ground for cancellation of the award and forfeiture of the bid security.</p>'
        . '<div>{{signatories}}</div>'];

    $out[] = ['notice_to_proceed', 'Notice to proceed (NTP)', 'proforma', 'award', 'ntp', 'Legal', 0, 'entity.name,project.title',
        'Notice to proceed (step 13 of the bidding), for the Punong Barangay\'s signature and the acknowledgment of the company\'s representative.',
        '<div>{{letterhead}}</div>' . $bigTitle('NOTICE TO PROCEED', '14pt', false, '4px 0 22px')
        . '<p>{{date.today}}</p>' . $addressee . '<p style="margin-top:18px">Dear {{signatory.salutation}}:</p>'
        . '<p style="text-align:justify;text-indent:3em;line-height:1.6">The attached Purchase Order No. <u>{{po.no}}</u> Dated <u>{{po.date}}</u> having been approved. Notice is hereby given to <b>{{company.name_upper}}</b> on the <b>{{project.title}}</b> and may proceed immediately to the delivery of the items enumerated therein within the specified delivery schedule.</p>'
        . '<p style="text-align:justify;line-height:1.6">Please acknowledge receipt and acceptance of this notice by signing both copies in the space provided below and be guided accordingly.</p>'
        . '<div>{{signatories}}</div>'
        . '<table class="fx-plain fx-kv" style="width:100%;margin-top:26px"><tbody><tr><td class="k" style="width:48%">I acknowledge receipt of this Notice on</td><td>: <u>{{date.today}}</u></td></tr>'
        . '<tr><td class="k">Name of the Representative of the Bidder</td><td>: <u>{{signatory.name}}</u></td></tr><tr><td class="k">Authorized Signature</td><td>: _________________________</td></tr></tbody></table>'];

    $out[] = ['bac_resolution', 'BAC resolution recommending the award', 'proforma', 'award', 'bac_resolution', 'Legal', 0, 'entity.name,project.title',
        'Resolution of the Bids and Awards Committee recommending the award to the company (step 13 of the bidding), prepared for the signatures of the BAC and the Punong Barangay.',
        '<div>{{letterhead}}</div><p style="text-align:center;margin:0">Office of the Barangay</p>' . $bigTitle('BIDS AND AWARDS COMMITTEE', '13pt', false, '2px 0 2px')
        . '<p style="text-align:center;font-weight:bold;margin-bottom:18px">RESOLUTION NO.__________</p>'
        . '<p style="text-align:justify;line-height:1.6">WHEREAS, after a complete and final evaluation by the BAC Technical Working Group it was determined that <b>{{company.name_upper}}</b> has the CALCULATED RESPONSIVE BID for the {{project.title}} located at <b>{{entity.title_upper}}, {{entity.lgu_line_upper}}</b> for the Contract Price equivalent to <b>{{project.contract_price_text}}</b>.</p>'
        . '<p style="text-align:justify;line-height:1.6">WHEREAS, the Approved Budget for the purchase is in the amount of <b>{{project.abc_text}}</b>.</p>'
        . '<p style="text-align:justify;line-height:1.6">WHEREAS, the Bids and Awards Committee (BAC), through Resolution No.__________ dated______________ had recommended to the Barangay CHAIRMAN, <b>HON. {{entity.head_name_upper}}</b> to award the {{project.title}} located at {{entity.title_upper}}, {{entity.lgu_line_upper}}, to <b>{{company.name_upper}}</b> which recommendation was approved.</p>'
        . '<p style="text-align:justify;line-height:1.6">NOW THEREFORE, we the members of the Bids and Awards Committee (BAC), hereby RESOLVED as it is hereby RESOLVED, to recommend to the Honorable Members, Barangay Council of {{entity.title}}, {{entity.city}}, to authorize <b>HON. {{entity.head_name_upper}}</b>, Barangay CHAIRMAN of {{entity.title_upper}}, {{entity.city}}, to enter into a Contract with <b>{{company.name_upper}}</b> at the Contract Price equivalent to <b>{{project.contract_price_text}}</b>.</p>'
        . '<p style="text-align:center;margin-top:14px">{{entity.title}}, {{entity.city}}</p><div>{{signatories}}</div>'];

    $out[] = ['certificate_of_completion', 'Certificate of completion', 'proforma', 'closeout', 'completion_cert', 'A4', 0, 'entity.name,project.title',
        'Certificate of completion signed by the inspector and the Barangay Captain.',
        '<div>{{letterhead}}</div><div class="fx-title" style="text-align:center;margin:16px 0">CERTIFICATE OF COMPLETION</div>'
        . '<p>This is to certify that <b>{{company.name}}</b> has satisfactorily completed / delivered <b>{{project.title}}</b> under {{project.contract_ref}} for the <b>{{entity.name}}</b> in accordance with the specifications, in the amount of <b>{{project.contract_amount}}</b>.</p>'
        . '<p>Date of completion: <b>{{project.completion_date}}</b></p><p>Issued this ____ day of __________ for whatever legal purpose it may serve.</p>'
        . '<div>{{signatories}}</div>'];

    return $out;
}

/** The default layout of each new template (the paper sizes of the client's forms) */
function brgy_sop_template_layout(string $code): ?array
{
    $base = ['margin_top' => 10, 'margin_right' => 12, 'margin_bottom' => 10, 'margin_left' => 12, 'line_height' => 1.2, 'cell_pad' => 2, 'font_size' => 9.5, 'table_size' => 9, 'title_size' => 13];
    // a signatory ({{signatories}}): name, position, more lines, words above
    $sg = fn(string $name, string $pos, string $lead = '', string $extra = '', array $more = []) => BrgyLayout::sigBlock(['name' => $name, 'pos' => $pos, 'lead' => $lead, 'extra' => $extra] + $more + ['bold' => 1]);
    $pad = ['lh_style' => 'none', 'signatories' => []];                         // the barangay's pads: no letterhead, signatures in the grid
    $brgy = ['lh_style' => 'barangay'];
    $co = ['lh_style' => 'company'];
    // the client's papers of steps 8 and 13: Legal, one-inch margins, CG Omega (the company's) or Cambria (the barangay's)
    $legal = ['paper' => 'Legal', 'orientation' => 'portrait', 'margin_top' => 25.4, 'margin_right' => 25.4, 'margin_bottom' => 25.4, 'margin_left' => 25.4, 'line_height' => 1.3,
        'cell_pad' => 3, 'title_size' => 14, 'date_style' => 'mdy', 'blank_rows' => 0, 'nothing_follows' => 0];
    $omega = $legal + ['font' => 'CG Omega', 'font_size' => 11, 'table_size' => 10];
    $cambria = $legal + ['font' => 'Cambria', 'font_size' => 11, 'table_size' => 10];
    $officeFooter = ['footer' => 'OFFICE OF THE BARANGAY', 'footer_align' => 'center', 'footer_bold' => 1, 'footer_size' => 11, 'footer_pos' => 'bottom'];
    // the barangay's name in text only, as the barangay hall's forms have it
    $hall = fn(string $last) => ['lh_style' => 'own', 'lh_left' => 'none', 'lh_right' => 'none', 'lh_lines' => "Republic of the Philippines\nProvince of {{entity.province}}\n{{entity.lgu_line}}\n" . $last,
        'lh_align' => 'center', 'lh_font' => '', 'lh_size' => 11, 'lh_big' => 16, 'lh_pic_mm' => 24, 'lh_box' => 0, 'lh_box_bg' => '#ffffff', 'lh_box_fg' => '#111111', 'lh_rule' => 0,
        'lh_rule_color' => '#111111', 'lh_space' => 8, 'lh_pic_a' => '', 'lh_pic_b' => ''];
    $statement = ['paper' => 'Legal', 'orientation' => 'landscape', 'margin_top' => 15, 'margin_right' => 18, 'margin_bottom' => 15, 'margin_left' => 18, 'font' => 'CG Omega', 'font_size' => 10,
        'table_size' => 9.5, 'line_height' => 1.25, 'cell_pad' => 3, 'title_size' => 14, 'date_style' => 'mdy', 'lh_style' => 'none', 'signatories' => []];
    $l = [
        'txn_form' => ['font_size' => 9.5, 'margin_top' => 7, 'margin_right' => 7, 'margin_bottom' => 7, 'margin_left' => 7, 'title_size' => 12.5, 'line_height' => 1.25] + $pad,
        'purchase_request' => ['blank_rows' => 3] + $pad,
        'purchase_order' => ['blank_rows' => 2, 'font_size' => 9] + $pad,
        'requisition_issue_slip' => ['blank_rows' => 4] + $pad,
        // v34: the BAC's letterhead of its own (the seal of the barangay left, Picture A right: the Bagong Pilipinas logo, uploaded on the Format tab)
        'request_for_quotation' => ['blank_rows' => 6, 'font_size' => 10, 'table_size' => 9.5, 'line_height' => 1.25, 'margin_top' => 10, 'margin_bottom' => 10, 'margin_left' => 15, 'margin_right' => 15,
            'sig_top' => 6, 'sig_space' => 8, 'signatories' => [$sg('{{entity.bac_chair_upper}}', 'BAC Chairman', '', '', ['align' => 'half'])],
            'lh_style' => 'own', 'lh_left' => 'seal', 'lh_right' => 'pic_a', 'lh_lines' => "**REPUBLIC OF THE PHILIPPINES**\nProvince of {{entity.province}}\n{{entity.lgu_line}}\n{{entity.title}}\n**BIDS AND AWARD COMMITTEE**",
            'lh_align' => 'center', 'lh_font' => '', 'lh_size' => 10.5, 'lh_big' => 15, 'lh_pic_mm' => 24, 'lh_box' => 0, 'lh_box_bg' => '#ffffff', 'lh_box_fg' => '#111111', 'lh_rule' => 0,
            'lh_rule_color' => '#111111', 'lh_space' => 4, 'lh_pic_a' => '', 'lh_pic_b' => ''],
        'inspection_acceptance_report' => ['blank_rows' => 3] + $pad,
        'disbursement_voucher' => ['font_size' => 9, 'table_size' => 8.5] + $pad,
        'abstract_of_canvass' => ['font_size' => 10, 'lh_style' => 'none', 'sig_top' => 8, 'signatories' => [$sg('{{entity.head_name_upper}}', 'PUNONG BARANGAY', 'Approved by:', '', ['underline' => 1])]],
        'document_transmittal' => ['sig_top' => 8, 'signatories' => [$sg('{{transmittal.released_by}}', '{{company.name}}', 'Released by:', '', ['underline' => 1]),
            $sg('', 'Signature over Printed Name / Date', 'Received by:', '', ['line' => 1])]] + $co,
        'bidding_form' => ['signatories' => []] + $co,
        'invitation_to_bid' => ['sig_top' => 12, 'signatories' => [$sg('{{entity.bac_chair_upper}}', 'BAC Chairman', '', '', ['align' => 'half', 'underline' => 1])]] + $brgy,
        'letter_to_coa' => ['sig_top' => 6, 'signatories' => [$sg('{{entity.bac_chair_upper}}', 'BAC Chairman', 'Very truly yours,', '', ['underline' => 1])]] + $brgy,
        'approved_budget_contract' => ['sig_top' => 12, 'signatories' => [$sg('{{entity.treasurer_upper}}', 'Barangay Treasurer', '', '', ['align' => 'center', 'underline' => 1]),
            $sg('{{entity.head_name_upper}}', 'Punong Barangay', '', '', ['align' => 'center', 'underline' => 1])]] + $brgy,
        'special_power_of_attorney' => ['lh_style' => 'none', 'sig_top' => 12, 'signatories' => [$sg('{{signatory.name}}', 'Principal', '', '', ['align' => 'center', 'underline' => 1]),
            $sg('{{project.submitter}}', 'Attorney-in-fact', '', '', ['align' => 'center', 'underline' => 1])]],
        'certificate_of_completion' => ['sig_top' => 14, 'signatories' => [$sg('{{project.inspector_upper}}', 'Inspector', 'Inspected by:', '', ['align' => 'center', 'underline' => 1]),
            $sg('{{entity.head_name_upper}}', 'Barangay Captain', 'Approved by:', '', ['align' => 'center', 'underline' => 1])]] + $brgy,
        // step 8: the additional requirements
        'statement_ongoing_contracts' => $statement,
        'statement_similar_contracts' => ['paper' => 'Folio'] + $statement,
        'supplier_profile' => ['signatories' => []] + $hall('**{{entity.short_upper}} Barangay Hall**') + $omega,
        'letter_of_intent' => ['sig_cols' => 1, 'sig_top' => 12, 'sig_space' => 12, 'lh_space' => 4,
            'signatories' => [$sg('{{signatory.name}}', '{{signatory.position}}', 'Respectfully yours,', '{{company.name}}')]] + $co + $omega,
        'technical_specifications' => ['lh_style' => 'none', 'sig_cols' => 1, 'sig_top' => 16, 'sig_space' => 14, 'comply_text' => '"comply"', 'margin_top' => 22,
            'signatories' => [$sg('{{signatory.name}}', 'Dealer\'s Signature', '', "\n**{{company.name_upper}}**\n{{company.address}}", ['underline' => 1])]] + $omega,
        'production_details' => ['sig_cols' => 1, 'sig_top' => 14, 'sig_space' => 14, 'th_bg' => '#fff2cc', 'font_size' => 10, 'table_size' => 9.5, 'cell_pad' => 1, 'margin_top' => 20,
            'signatories' => [$sg('{{signatory.name}}', 'Dealer\'s Signature', "**{{company.name_upper}}**\n{{company.address_upper}}", '', ['underline' => 1])]]
            + $hall('**{{entity.short}}** Barangay Hall') + $omega,
        'manpower_schedule' => ['sig_cols' => 1, 'sig_top' => 22, 'sig_space' => 12,
            'signatories' => [$sg('{{signatory.name}}', '{{signatory.position}}', 'Respectfully submitted by:')]] + $co + $omega,
        'warranty_certificate' => ['sig_cols' => 1, 'sig_top' => 12, 'sig_space' => 12, 'font_size' => 10.5, 'line_height' => 1.22, 'margin_top' => 18, 'margin_bottom' => 18, 'lh_space' => 4,
            'signatories' => [$sg('{{signatory.name}}', '{{signatory.position}}', 'Sincerely yours,')]] + $co + $omega,
        // step 13: the post-bidding papers
        'notice_of_post_qualification' => ['sig_cols' => 1, 'sig_top' => 14, 'sig_space' => 10, 'sig_gap' => 8, 'signatories' => [
            $sg('HON. {{entity.bac_chair_upper}}', 'BAC CHAIRMAN', 'Very truly yours,', '', ['underline' => 1, 'italic' => 1]),
            $sg('{{signatory.name}}', 'PRINTED NAME AND SIGNATURE', 'Received by:', '', ['underline' => 1, 'italic' => 1, 'bold' => 0, 'newrow' => 1])]] + $officeFooter + $brgy + $cambria,
        'notice_of_award' => ['sig_cols' => 1, 'sig_top' => 12, 'sig_space' => 10, 'sig_gap' => 8, 'signatories' => [
            $sg('HON. {{entity.head_name_upper}}', 'Barangay Chairman', 'Very truly yours,', '{{entity.location}}', ['underline' => 1]),
            $sg('{{signatory.name}}', '{{company.name_upper}}', 'Conforme:', 'Date: {{date.today}}', ['underline' => 1, 'newrow' => 1])]] + $officeFooter + $brgy + $cambria,
        'notice_to_proceed' => ['sig_cols' => 1, 'sig_top' => 12, 'sig_space' => 10, 'signatories' => [
            $sg('HON. {{entity.head_name_upper}}', 'Barangay Chairman', 'Sincerely yours,', '{{entity.location}}', ['underline' => 1])]] + $brgy + $cambria,
        'bac_resolution' => ['sig_cols' => 3, 'sig_top' => 8, 'sig_space' => 11, 'sig_gap' => 6, 'signatories' => [
            $sg('HON. {{entity.bac_chair_upper}}', 'BAC Chairman', '', '', ['align' => 'center']), $sg('HON. {{entity.bac_member_1_upper}}', 'BAC Member', '', '', ['align' => 'center']),
            $sg('HON. {{entity.bac_member_2_upper}}', 'BAC Member', '', '', ['align' => 'center']), $sg('HON. {{entity.bac_member_3_upper}}', 'BAC Member', '', '', ['align' => 'center']),
            $sg('HON. {{entity.bac_member_4_upper}}', 'BAC Member', '', '', ['align' => 'center']), $sg('HON. {{entity.bac_member_5_upper}}', 'BAC Member', '', '', ['align' => 'center']),
            $sg('HON. {{entity.head_name_upper}}', 'Barangay CHAIRMAN', '', '', ['align' => 'center', 'newrow' => 1])]] + $brgy + $cambria,
    ];
    if (!in_array($code, array_column(brgy_sop_templates(), 0), true)) {
        return null;
    }
    return array_merge($base, $l[$code] ?? []);
}

/** Where the values of the transaction form fall on the company's pre-printed, series-numbered pad (a start: the test print calibrates it) */
function brgy_sop_template_positions(string $code): ?array
{
    if ($code !== 'txn_form') {
        return null;
    }
    $f = fn(string $key, float $x, float $y, float $w, float $size = 0, int $bold = 0, string $align = 'left') => ['key' => $key, 'text' => '', 'x' => $x, 'y' => $y, 'w' => $w, 'size' => $size, 'bold' => $bold, 'align' => $align, 'upper' => 0];
    return ['paper' => 'HalfLetter', 'orientation' => 'portrait', 'width_mm' => '', 'height_mm' => '', 'offset_x' => 0, 'offset_y' => 0, 'font' => 'Arial', 'font_size' => 9.5,
        'fields' => [
            $f('txn.series', 42, 5, 50, 12, 1), $f('txn.date', 43, 23, 52), $f('txn.tradename', 43, 29.5, 52), $f('txn.mark_cash_out', 45.5, 35, 5, 10, 1, 'center'),
            $f('txn.mark_kaliwaan', 45.5, 42, 5, 10, 1, 'center'), $f('txn.mark_purchases', 45.5, 49, 5, 10, 1, 'center'),
            $f('txn.mark_construction', 45.5, 56, 5, 10, 1, 'center'), $f('txn.barangay', 44, 64, 52), $f('txn.cheque_no', 44, 69.5, 52), $f('txn.gross', 44, 75, 52),
            $f('txn.wht', 44, 80.5, 52), $f('txn.cheque_amount', 44, 86, 52), $f('txn.profit', 44, 100, 52), $f('txn.requestor', 44, 122, 52), $f('txn.inspector', 44, 127.5, 52),
            $f('txn.purpose', 44, 133, 52), $f('txn.prepared_by', 46, 148, 48),
        ], 'tables' => []];
}

/** v39: the fields of a template that may stay blank (printed empty, not counted as blanks) */
function brgy_sop_optional_fields(): array
{
    return ['purchase_request' => 'pr.no,pr.date', 'disbursement_voucher' => 'dv.no,dv.date,dv.fund',
        'inspection_acceptance_report' => 'iar.no,iar.date,ris.no,ris.date,iar.invoice_no,iar.invoice_date', 'requisition_issue_slip' => 'ris.no,ris.date'];
}

function brgy_seed_sop_templates(): void
{
    $n = (int)DB::value('SELECT COALESCE(MAX(sort_order), 0) FROM brgy_templates');
    foreach (brgy_sop_templates() as [$code, $name, $cat, $stage, $doc, $paper, $approval, $req, $desc, $body]) {
        $n += 10;
        if (DB::value('SELECT id FROM brgy_templates WHERE code = :c', ['c' => $code])) {
            continue;
        }
        $body = BrgyDocs::sanitize($body);
        $layout = brgy_sop_template_layout($code);
        $pos = brgy_sop_template_positions($code);
        $id = DB::insert('brgy_templates', ['code' => $code, 'name' => $name, 'category' => $cat, 'stage' => $stage, 'doc_type' => $doc, 'description' => $desc, 'body' => $body,
            'paper' => $paper, 'orientation' => ($layout['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait', 'needs_approval' => $approval, 'layout_json' => $layout ? json_encode($layout) : null, 'positions_json' => $pos ? json_encode($pos) : null,
            'required_fields' => $req, 'optional_fields' => brgy_sop_optional_fields()[$code] ?? null, 'sort_order' => $n]);
        DB::insert('brgy_template_versions', ['template_id' => $id, 'version_no' => 1, 'name' => $name, 'body' => $body, 'layout_json' => $layout ? json_encode($layout) : null,
            'positions_json' => $pos ? json_encode($pos) : null, 'change_note' => 'Supplied with the program']);
    }
}
