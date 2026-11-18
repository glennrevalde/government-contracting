<?php
declare(strict_types=1);

/**
 * Barangay Contracts: the accounting handoff.
 *
 * Every entry the module puts in the books is an ordinary transaction of the accounting modules, saved through
 * Txn::save (so the period lock, the posting engine, the audit trail and the reports all apply) and remembered in
 * brgy_postings under its source record and event. That table has one live row per (source, event): posting the
 * same thing twice is refused, and every ledger entry can be traced back to the contract record it came from.
 *
 * Revenue is recognised only when a billing pack for accepted quantities is approved and its sales invoice is
 * issued; winning a bid, a notice of award or a delivery never posts a sale.
 */
final class BrgyPost
{
    /**
     * The posting map: event => [when, debit, credit, transaction]. Shown on Contracting Settings and in the manual.
     */
    public const MAP = [
        'award'      => ['Notice of award, contract, notice to proceed', 'Nothing (no sale, no receivable)', '', 'none: the contract opens a Customer:Job and a class for job costing'],
        'po'         => ['Purchase order to a supplier or subcontractor', 'Nothing (a purchase order does not post)', '', 'Purchase Order, lines on the Customer:Job'],
        'purchase'   => ['Supplier bill / receipt for the contract (Vendors module)', 'Inventory, or the cost / expense account, on the Customer:Job', 'Accounts Payable', 'Bill / Item Receipt'],
        'transfer'   => ['Goods delivered, waiting for the entity\'s acceptance', 'Inventory at the site "Delivered, awaiting acceptance"', 'Inventory at the warehouse (same value)', 'Inventory Transfer'],
        'return'     => ['Goods rejected and brought back', 'Inventory at the warehouse', 'Inventory at the delivered site', 'Inventory Transfer'],
        'invoice'    => ['Billing pack approved: sales invoice for accepted quantities', 'Accounts Receivable (Customer:Job); Cost of Goods Sold', 'Sales / Service / Contract revenue; Output VAT; Inventory', 'Invoice'],
        'payment'    => ['Collection (check, LDDAP-ADA, transfer, cash)', 'Undeposited Funds or the bank; Creditable Withholding Tax (1% / 2%); Creditable VAT Withheld (5%)', 'Accounts Receivable', 'Customer Payment with the taxes withheld (BIR Reports, Form 2307)'],
        'deductions' => ['Retention, liquidated damages, other deductions and bank charges on a collection', 'Retention Receivable; Liquidated Damages; the account chosen; Bank Charges', 'Accounts Receivable', 'Credit Memo applied to the invoice'],
        'release'    => ['Retention released by the entity', 'The bank', 'Retention Receivable', 'Deposit'],
        'bounce'     => ['A deposited check is returned', 'Accounts Receivable (a returned-check charge)', 'The bank', 'Statement Charge (an undeposited check: the payment is voided instead)'],
        'security'   => ['Bid / performance security paid in cash or by check', 'Bid and Performance Security Deposits', 'The bank', 'Journal Entry'],
        'refund'     => ['Security returned by the entity', 'The bank', 'Bid and Performance Security Deposits', 'Journal Entry'],
        'forfeit'    => ['Security forfeited', 'Loss on Forfeited Security', 'Bid and Performance Security Deposits', 'Journal Entry'],
    ];

    // ================================================================== the postings ledger

    /** The live posting of a source record and event, or null */
    public static function live(string $sourceType, int $sourceId, string $event): ?array
    {
        $r = DB::row('SELECT bp.*, t.is_voided FROM brgy_postings bp LEFT JOIN transactions t ON t.id = bp.txn_id
                       WHERE bp.source_type = :s AND bp.source_id = :id AND bp.event = :e FOR UPDATE', ['s' => $sourceType, 'id' => $sourceId, 'e' => $event]);
        if (!$r || $r['status'] !== 'posted' || !$r['txn_id'] || (int)$r['is_voided'] === 1) {
            return null;
        }
        return $r;
    }

    /** Remembers a posting; a second live posting of the same source and event is refused (duplicate prevention) */
    public static function record(int $projectId, string $sourceType, int $sourceId, string $event, int $txnId, float $amount, string $note = ''): void
    {
        $row = DB::row('SELECT bp.*, t.is_voided FROM brgy_postings bp LEFT JOIN transactions t ON t.id = bp.txn_id
                         WHERE bp.source_type = :s AND bp.source_id = :id AND bp.event = :e FOR UPDATE', ['s' => $sourceType, 'id' => $sourceId, 'e' => $event]);
        if ($row && $row['status'] === 'posted' && $row['txn_id'] && (int)$row['txn_id'] !== $txnId && (int)$row['is_voided'] === 0) {
            fail('This has already been posted (' . Txn::ref(Txn::load((int)$row['txn_id']) ?? ['txn_type' => 'journal', 'txn_no' => '#' . $row['txn_id'], 'id' => $row['txn_id']])
                . '). A second entry was not made.');
        }
        $data = ['project_id' => $projectId, 'txn_id' => $txnId, 'amount' => r2($amount), 'status' => 'posted', 'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
            'posted_by' => Auth::id(), 'reversed_at' => null];
        if ($row) {
            DB::update('brgy_postings', $data, 'id = :id', ['id' => $row['id']]);
        } else {
            DB::insert('brgy_postings', $data + ['source_type' => $sourceType, 'source_id' => $sourceId, 'event' => $event]);
        }
    }

    public static function markReversed(string $sourceType, int $sourceId, string $event, string $note): void
    {
        DB::run("UPDATE brgy_postings SET status = 'reversed', reversed_at = :d, note = :n WHERE source_type = :s AND source_id = :id AND event = :e",
            ['d' => date('Y-m-d H:i:s'), 'n' => mb_substr($note, 0, 255), 's' => $sourceType, 'id' => $sourceId, 'e' => $event]);
    }

    /** Voids the transaction behind a live posting (when there is one) and marks it reversed */
    public static function voidPosting(string $sourceType, int $sourceId, string $event, string $reason): void
    {
        $r = DB::row('SELECT * FROM brgy_postings WHERE source_type = :s AND source_id = :id AND event = :e', ['s' => $sourceType, 'id' => $sourceId, 'e' => $event]);
        if (!$r || $r['status'] !== 'posted' || !$r['txn_id']) {
            return;
        }
        $t = Txn::load((int)$r['txn_id']);
        if ($t && (int)$t['is_voided'] === 0) {
            Txn::void((int)$t['id'], $reason);
        }
        self::markReversed($sourceType, $sourceId, $event, $reason);
    }

    /** Every posting of a project with its transaction */
    public static function forProject(int $projectId): array
    {
        return DB::all('SELECT bp.*, t.txn_type, t.txn_no, t.txn_date, t.total, t.is_voided, t.status AS txn_status
                          FROM brgy_postings bp LEFT JOIN transactions t ON t.id = bp.txn_id
                         WHERE bp.project_id = :p ORDER BY bp.posted_at, bp.id', ['p' => $projectId]);
    }

    // ================================================================== customers, jobs, classes, sites

    /** The procuring entity as a customer (made the first time it is needed) */
    public static function ensureCustomer(int $entityId): int
    {
        $e = Brgy::entity($entityId);
        if (!$e) {
            fail('Procuring entity not found.');
        }
        if ($e['customer_id'] && DB::value('SELECT id FROM customers WHERE id = :id', ['id' => $e['customer_id']])) {
            return (int)$e['customer_id'];
        }
        $type = DB::value("SELECT id FROM customer_types WHERE name = 'Government' LIMIT 1") ?: DB::insert('customer_types', ['name' => 'Government']);
        $terms = DB::value("SELECT id FROM terms WHERE name = 'Net 30' AND is_active = 1 LIMIT 1");
        $id = DB::insert('customers', [
            'name' => mb_substr($e['official_name'], 0, 120), 'company_name' => mb_substr($e['official_name'], 0, 120),
            'contact_person' => ($h = Brgy::official($entityId, 'treasurer')) ? $h['name'] : null,
            'phone' => $e['phone'], 'email' => $e['email'], 'billing_address' => $e['address'], 'tin' => $e['tin'],
            'wht_ids' => self::whtIdsCsv(), 'terms_id' => $terms ?: null, 'tax_code_id' => Brgy::defaultTaxCode(), 'customer_type_id' => (int)$type,
            'notes' => 'Procuring entity (Barangay Contracts)',
        ]);
        DB::update('brgy_entities', ['customer_id' => $id], 'id = :id', ['id' => $entityId]);
        Audit::log('create', 'cust', 'customer', $id, $e['official_name'], ['page_key' => 'cust.center', 'reason' => 'Made for the procuring entity in Barangay Contracts']);
        Lookup::reset();
        return $id;
    }

    /** The Customer:Job and the class of an awarded project (made once) */
    public static function ensureJob(array $p): array
    {
        $customer = self::ensureCustomer((int)$p['entity_id']);
        $job = $p['job_customer_id'] ? (int)$p['job_customer_id'] : 0;
        if (!$job || !DB::value('SELECT id FROM customers WHERE id = :id', ['id' => $job])) {
            $parent = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $customer]);
            $job = DB::insert('customers', [
                'parent_id' => $customer, 'name' => mb_substr($p['project_no'] . ' ' . $p['title'], 0, 120), 'company_name' => $parent['company_name'],
                'billing_address' => $parent['billing_address'], 'shipping_address' => $p['delivery_site'] ? mb_substr((string)$p['delivery_site'], 0, 255) : null,
                'tin' => $parent['tin'], 'wht_ids' => $parent['wht_ids'], 'terms_id' => $p['terms_id'] ?: $parent['terms_id'], 'tax_code_id' => $parent['tax_code_id'],
                'customer_type_id' => $parent['customer_type_id'], 'notes' => 'Contract ' . ($p['contract_no'] ?: $p['po_no'] ?: $p['project_no']) . ' (Barangay Contracts)',
            ]);
            Audit::log('create', 'cust', 'customer', $job, $p['project_no'] . ' job', ['page_key' => 'cust.center', 'reason' => 'Customer:Job for the awarded project']);
        }
        $class = $p['class_id'] ? (int)$p['class_id'] : null;
        if (!$class && Brgy::on('brgy_create_class') && Classes::ready()) {
            $class = (int)(DB::value('SELECT id FROM classes WHERE name = :n', ['n' => $p['project_no']]) ?: DB::insert('classes', [
                'name' => $p['project_no'], 'description' => mb_substr($p['title'], 0, 255), 'sort_order' => 900, 'created_by' => Auth::id(),
            ]));
            Classes::reset();
        }
        DB::update('brgy_projects', ['customer_id' => $customer, 'job_customer_id' => $job, 'class_id' => $class], 'id = :id', ['id' => $p['id']]);
        Lookup::reset();
        return ['customer' => $customer, 'job' => $job, 'class' => $class];
    }

    /** The inventory site where delivered goods wait for the entity's acceptance (made when missing) */
    public static function deliveredSite(): int
    {
        $id = (int)Brgy::setting('brgy_delivered_site_id');
        if ($id && DB::value('SELECT id FROM sites WHERE id = :id', ['id' => $id])) {
            return $id;
        }
        $id = (int)(DB::value("SELECT id FROM sites WHERE code = 'BRGY-DLV'") ?: DB::insert('sites', [
            'code' => 'BRGY-DLV', 'name' => 'Delivered, awaiting acceptance', 'site_type' => 'other', 'address' => 'Goods at the procuring entity, not yet accepted',
        ]));
        Settings::set('brgy_delivered_site_id', (string)$id);
        return $id;
    }

    /** The withholding taxes a government buyer usually withholds, as the ids the customer record keeps ("1,3") */
    public static function whtIdsCsv(): string
    {
        if (!Withholding::ready()) {
            return '';
        }
        $ids = [];
        foreach (['brgy_wht_goods', 'brgy_wht_services', 'brgy_wht_vat'] as $k) {
            if ($t = self::whtType(Brgy::setting($k))) {
                $ids[] = (int)$t['id'];
            }
        }
        return implode(',', array_unique($ids));
    }

    public static function whtType(string $code): ?array
    {
        foreach (Withholding::all() as $t) {
            if (strcasecmp((string)$t['code'], $code) === 0 && (int)$t['is_active'] === 1) {
                return $t;
            }
        }
        return null;
    }

    /** Income account of a contract line without an item */
    public static function incomeAccount(string $kind): int
    {
        $key = ['goods' => 'gov_goods_income', 'service' => 'gov_services_income', 'works' => 'gov_works_income'][$kind] ?? 'gov_services_income';
        return Brgy::acct($key) ?: Accts::get('sales_income');
    }

    private static function paymentMethodFor(string $method): ?int
    {
        $type = ['check' => 'check', 'lddap_ada' => 'bank_transfer', 'bank_transfer' => 'bank_transfer', 'cash' => 'cash'][$method] ?? 'other';
        $id = DB::value('SELECT id FROM payment_methods WHERE method_type = :t AND is_active = 1 ORDER BY id LIMIT 1', ['t' => $type]);
        return $id ? (int)$id : null;
    }

    // ================================================================== deliveries

    /** Moves the delivered goods (inventory items) from the warehouse to the "delivered, awaiting acceptance" site */
    public static function postDelivery(int $deliveryId): ?int
    {
        $d = DB::row('SELECT d.*, p.project_no, p.class_id, p.title FROM brgy_deliveries d JOIN brgy_projects p ON p.id = d.project_id WHERE d.id = :id', ['id' => $deliveryId]);
        $lines = [];
        foreach (DB::all('SELECT dl.*, i.item_type, i.name AS item_name FROM brgy_delivery_lines dl LEFT JOIN items i ON i.id = dl.item_id WHERE dl.delivery_id = :d', ['d' => $deliveryId]) as $l) {
            if ($l['item_id'] && Stock::isTracked($l['item_type']) && (float)$l['qty'] > 0) {
                $lines[] = ['line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'qty' => (float)$l['qty'], 'description' => mb_substr($l['description'], 0, 255), 'site_id' => (int)$d['from_site_id']];
            }
        }
        $existing = DB::row("SELECT * FROM brgy_postings WHERE source_type = 'delivery' AND source_id = :id AND event = 'transfer' AND status = 'posted'", ['id' => $deliveryId]);
        $oldTxn = $existing && $existing['txn_id'] ? Txn::load((int)$existing['txn_id']) : null;
        if ($oldTxn && (int)$oldTxn['is_voided'] === 1) {
            $oldTxn = null;
        }
        if (!$lines) {
            if ($oldTxn) {
                self::voidPosting('delivery', $deliveryId, 'transfer', 'Delivery ' . $d['dr_no'] . ' no longer moves stock');
            }
            return null;
        }
        if (!$d['from_site_id']) {
            fail('Choose the site the goods leave from.');
        }
        $to = self::deliveredSite();
        if ((int)$d['from_site_id'] === $to) {
            fail('The goods cannot leave from the "delivered, awaiting acceptance" site itself.');
        }
        $h = ['txn_date' => $d['delivery_date'], 'site_id' => (int)$d['from_site_id'], 'site2_id' => $to, 'status' => 'open', 'ref_no' => $d['dr_no'],
            'memo' => mb_substr('Delivered on ' . $d['dr_no'] . ' (' . $d['project_no'] . '), awaiting acceptance', 0, 255), 'class_id' => $d['class_id'] ?: null];
        $id = Txn::save('inventory_transfer', $h, $lines, $oldTxn ? (int)$oldTxn['id'] : null, ['reason' => 'Barangay Contracts delivery ' . $d['dr_no']]);
        self::record((int)$d['project_id'], 'delivery', $deliveryId, 'transfer', $id, 0, 'Stock to the delivered site');
        DB::update('brgy_deliveries', ['transfer_txn_id' => $id], 'id = :id', ['id' => $deliveryId]);
        return $id;
    }

    /** Brings rejected goods back from the delivered site to the warehouse (kept in step with the inspection) */
    public static function postReturn(int $deliveryId): ?int
    {
        $d = DB::row('SELECT d.*, p.project_no, p.class_id FROM brgy_deliveries d JOIN brgy_projects p ON p.id = d.project_id WHERE d.id = :id', ['id' => $deliveryId]);
        $lines = [];
        foreach (DB::all('SELECT dl.*, i.item_type FROM brgy_delivery_lines dl LEFT JOIN items i ON i.id = dl.item_id WHERE dl.delivery_id = :d', ['d' => $deliveryId]) as $l) {
            if ($l['item_id'] && Stock::isTracked($l['item_type']) && (float)$l['qty_rejected'] > 0) {
                $lines[] = ['line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'qty' => (float)$l['qty_rejected'], 'description' => mb_substr('Rejected: ' . $l['description'], 0, 255), 'site_id' => self::deliveredSite()];
            }
        }
        $existing = DB::row("SELECT * FROM brgy_postings WHERE source_type = 'delivery' AND source_id = :id AND event = 'return' AND status = 'posted'", ['id' => $deliveryId]);
        $oldTxn = $existing && $existing['txn_id'] ? Txn::load((int)$existing['txn_id']) : null;
        if ($oldTxn && (int)$oldTxn['is_voided'] === 1) {
            $oldTxn = null;
        }
        if (!$lines) {
            if ($oldTxn) {
                self::voidPosting('delivery', $deliveryId, 'return', 'No rejected goods left on ' . $d['dr_no']);
            }
            DB::update('brgy_deliveries', ['return_txn_id' => null], 'id = :id', ['id' => $deliveryId]);
            return null;
        }
        $h = ['txn_date' => $d['acceptance_date'] ?: ($d['inspection_date'] ?: $d['delivery_date']), 'site_id' => self::deliveredSite(), 'site2_id' => (int)$d['from_site_id'], 'status' => 'open',
            'ref_no' => $d['dr_no'], 'memo' => mb_substr('Rejected on inspection of ' . $d['dr_no'] . ' (' . $d['project_no'] . '), brought back', 0, 255), 'class_id' => $d['class_id'] ?: null];
        $id = Txn::save('inventory_transfer', $h, $lines, $oldTxn ? (int)$oldTxn['id'] : null, ['reason' => 'Barangay Contracts rejected goods ' . $d['dr_no']]);
        self::record((int)$d['project_id'], 'delivery', $deliveryId, 'return', $id, 0, 'Rejected goods back to the warehouse');
        DB::update('brgy_deliveries', ['return_txn_id' => $id], 'id = :id', ['id' => $deliveryId]);
        return $id;
    }

    // ================================================================== billing: the sales invoice

    /**
     * Issues the sales invoice of an approved billing pack (once). Checks again, inside the transaction, that every
     * line is within what the entity accepted and has not been billed elsewhere, and that the contract amount is not
     * exceeded. Prices are VAT inclusive (setting), so each line is split into its amount and VAT, which add up to the
     * billed amount exactly.
     */
    public static function issueInvoice(int $billingId): int
    {
        return DB::tx(function () use ($billingId) {
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id FOR UPDATE', ['id' => $billingId]);
            if (!$b) {
                fail('Billing not found.');
            }
            if ($b['status'] !== 'approved') {
                fail('Only an approved billing pack is invoiced (this one is ' . (Brgy::WF[$b['status']] ?? $b['status']) . ').');
            }
            if (self::live('billing', $billingId, 'invoice') || ($b['invoice_txn_id'] && ($t = Txn::load((int)$b['invoice_txn_id'])) && (int)$t['is_voided'] === 0)) {
                fail('This billing already has its invoice. A second invoice was not made (duplicate prevention).');
            }
            $p = Brgy::mustProject((int)$b['project_id']);
            if ($p['status'] !== 'awarded') {
                fail('The project is ' . Brgy::label(Brgy::STATUSES, $p['status']) . '; only an awarded contract is billed.');
            }
            if ($b['si_no'] !== null && $b['si_no'] !== '') {
                $dup = DB::value("SELECT billing_no FROM brgy_billings WHERE si_no = :s AND id <> :id AND status <> 'void'", ['s' => $b['si_no'], 'id' => $billingId]);
                if ($dup) {
                    fail('Sales invoice number ' . $b['si_no'] . ' is already used on billing ' . $dup . '.');
                }
            }
            Brgy::refreshLines((int)$p['id']);
            $bl = DB::all('SELECT bl.*, pl.qty_accepted, pl.qty_billed, pl.qty AS contract_qty, i.item_type FROM brgy_billing_lines bl
                             JOIN brgy_project_lines pl ON pl.id = bl.project_line_id LEFT JOIN items i ON i.id = bl.item_id
                            WHERE bl.billing_id = :b ORDER BY bl.id', ['b' => $billingId]);
            if (!$bl) {
                fail('The billing has no lines.');
            }
            foreach ($bl as $l) {
                $others = (float)$l['qty_billed'] - (float)$l['qty'];
                if ((float)$l['qty'] + $others > (float)$l['qty_accepted'] + 0.00001) {
                    fail('Line "' . $l['description'] . '": ' . qty($l['qty']) . ' billed here and ' . qty($others) . ' elsewhere is more than the ' . qty($l['qty_accepted'])
                        . ' the entity accepted. Billing is allowed only for accepted quantities.');
                }
            }
            $billedAll = r2(DB::value("SELECT COALESCE(SUM(gross_amount), 0) FROM brgy_billings WHERE project_id = :p AND status IN ('approved', 'invoiced', 'submitted', 'acknowledged')", ['p' => $p['id']]));
            if ($billedAll > Brgy::contractTotal($p) + 0.004) {
                fail('Billed in total (' . money($billedAll) . ') would be more than the contract amount with approved change orders (' . money(Brgy::contractTotal($p)) . ').');
            }
            $jc = self::ensureJob($p);
            $p = Brgy::mustProject((int)$p['id']);
            $deliveredSite = self::deliveredSite();
            $lines = [];
            foreach ($bl as $l) {
                $tracked = $l['item_id'] && Stock::isTracked($l['item_type']);
                $q = (float)$l['qty'];
                $lines[] = [
                    'line_kind' => 'item', 'item_id' => $l['item_id'] ? (int)$l['item_id'] : null,
                    'account_id' => $l['item_id'] ? null : (int)($l['account_id'] ?: self::incomeAccount((string)$l['line_kind'])),
                    'description' => mb_substr($l['description'], 0, 255), 'unit' => $l['unit'],
                    'qty' => $q, 'rate' => $q > 0 ? round((float)$l['net'] / $q, 4) : 0, 'uom_qty' => $q, 'uom_rate' => $q > 0 ? round((float)$l['net'] / $q, 4) : 0,
                    'amount' => r2($l['net']), 'tax_code_id' => $l['tax_code_id'] ?: null, 'tax_amount' => r2($l['tax']),
                    'site_id' => $tracked ? $deliveredSite : null, 'customer_id' => $jc['job'], 'class_id' => $jc['class'],
                ];
            }
            $entity = Brgy::entity((int)$p['entity_id']);
            $terms = $p['terms_id'] ? (int)$p['terms_id'] : ((int)DB::value('SELECT terms_id FROM customers WHERE id = :c', ['c' => $jc['job']]) ?: null);
            $h = [
                'txn_date' => $b['billing_date'], 'customer_id' => $jc['job'], 'account_id' => Accts::get('ar'), 'terms_id' => $terms,
                'due_date' => Txn::dueDate($b['billing_date'], $terms), 'site_id' => $deliveredSite, 'class_id' => $jc['class'],
                'ref_no' => $b['si_no'] ?: null, 'billing_address' => $entity['address'],
                'memo' => mb_substr('Billing ' . $b['billing_no'] . ', ' . $p['project_no'] . ' ' . $p['title'], 0, 255),
                'customer_message' => mb_substr(trim(($p['contract_no'] ? 'Contract ' . $p['contract_no'] : '') . ($p['po_no'] ? ' PO ' . $p['po_no'] : '')), 0, 255) ?: null,
            ];
            $id = Txn::save('invoice', $h, $lines, null, ['reason' => 'Barangay Contracts billing ' . $b['billing_no']]);
            $inv = Txn::load($id);
            if (abs((float)$inv['total'] - (float)$b['gross_amount']) > 0.01) {
                fail('The invoice total (' . money($inv['total']) . ') does not agree with the billing (' . money($b['gross_amount']) . '). Nothing was posted.');
            }
            self::record((int)$p['id'], 'billing', $billingId, 'invoice', $id, (float)$inv['total'], 'Sales invoice for accepted quantities');
            DB::update('brgy_billings', ['status' => 'invoiced', 'invoice_txn_id' => $id, 'due_date' => $inv['due_date'], 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $billingId]);
            Brgy::bumpVersion('brgy_billings', $billingId);
            DB::insert('brgy_workflow', ['project_id' => $p['id'], 'object_type' => 'billing', 'object_id' => $billingId, 'action' => 'post', 'from_status' => 'approved', 'to_status' => 'invoiced',
                'user_id' => Auth::id(), 'amount' => $inv['total'], 'remarks' => 'Invoice ' . $inv['txn_no']]);
            Brgy::advance((int)$p['id'], 'billing', 'First billing invoiced');
            return $id;
        });
    }

    // ================================================================== collections

    /** What is still open on a billing: its invoice and any returned-check charges, the charges first */
    public static function collectionTargets(array $b): array
    {
        $out = ['invoice' => null, 'charges' => [], 'open' => 0.0];
        if ($b['invoice_txn_id']) {
            $inv = Txn::load((int)$b['invoice_txn_id']);
            if ($inv && (int)$inv['is_voided'] === 0) {
                $inv['open_balance'] = Balances::open($inv);
                $out['invoice'] = $inv;
                $out['open'] += $inv['open_balance'];
            }
        }
        foreach (DB::all("SELECT t.* FROM brgy_postings bp JOIN brgy_collections c ON c.id = bp.source_id JOIN transactions t ON t.id = bp.txn_id
                           WHERE bp.source_type = 'collection' AND bp.event = 'bounce' AND bp.status = 'posted' AND c.billing_id = :b AND t.is_voided = 0", ['b' => $b['id']]) as $t) {
            $t['open_balance'] = Balances::open($t);
            if ($t['open_balance'] > 0.004) {
                $out['charges'][] = $t;
                $out['open'] += $t['open_balance'];
            }
        }
        $out['open'] = r2($out['open']);
        return $out;
    }

    /**
     * The taxes the entity is expected to withhold on $onInvoice of the billing (the part of the collection that goes to
     * the invoice), from the Withholding Tax Table: income tax on goods and on services (works count as services), and
     * VAT withheld on the VATable lines, all on the amount net of VAT (or gross, as the table says).
     * Returns ['ewt' => total, 'vat' => total, 'types' => [wht id => [type row, base, amount]]].
     */
    public static function expectedWithholding(array $b, float $onInvoice): array
    {
        $out = ['ewt' => 0.0, 'vat' => 0.0, 'types' => []];
        if (!Withholding::ready() || (float)$b['gross_amount'] <= 0 || $onInvoice <= 0) {
            return $out;
        }
        $ratio = min(1.0, $onInvoice / (float)$b['gross_amount']);
        $add = function (?array $t, float $net, float $gross) use (&$out) {
            if (!$t) {
                return;
            }
            $base = $t['base_type'] === 'gross' ? $gross : $net;
            $id = (int)$t['id'];
            $out['types'][$id] ??= ['t' => $t, 'base' => 0.0, 'amount' => 0.0];
            $out['types'][$id]['base'] += $base;
        };
        $goods = self::whtType(Brgy::setting('brgy_wht_goods'));
        $services = self::whtType(Brgy::setting('brgy_wht_services'));
        $vat = self::whtType(Brgy::setting('brgy_wht_vat'));
        foreach (DB::all('SELECT * FROM brgy_billing_lines WHERE billing_id = :b', ['b' => $b['id']]) as $l) {
            $net = (float)$l['net'] * $ratio;
            $gross = (float)$l['gross'] * $ratio;
            $add($l['line_kind'] === 'goods' ? $goods : $services, $net, $gross);
            if ((float)$l['tax'] > 0) {
                $add($vat, $net, $gross);
            }
        }
        foreach ($out['types'] as $id => &$x) {
            $x['base'] = r2($x['base']);
            $x['amount'] = r2($x['base'] * (float)$x['t']['rate'] / 100);
            $out[$x['t']['tax_kind'] === 'vat' ? 'vat' : 'ewt'] += $x['amount'];
        }
        unset($x);
        $out['ewt'] = r2($out['ewt']);
        $out['vat'] = r2($out['vat']);
        return $out;
    }

    /**
     * Posts a collection: the customer payment (cash and the taxes withheld, kept by type for BIR Form 2307 and the
     * BIR Reports) and, when there are deductions, a credit memo applied to the invoice with one line per deduction
     * (retention, liquidated damages, other, bank charges). A retention release posts a deposit instead.
     */
    public static function postCollection(int $collectionId): void
    {
        DB::tx(function () use ($collectionId) {
            $c = DB::row('SELECT * FROM brgy_collections WHERE id = :id FOR UPDATE', ['id' => $collectionId]);
            if (!$c || $c['status'] !== 'posted') {
                fail('Collection not found, or not open for posting.');
            }
            $p = Brgy::mustProject((int)$c['project_id']);
            if ($c['kind'] === 'retention') {
                self::postRetentionRelease($c, $p);
                return;
            }
            if (self::live('collection', $collectionId, 'payment') || self::live('collection', $collectionId, 'deductions')) {
                fail('This collection is already posted. A second entry was not made.');
            }
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $c['billing_id']]);
            if (!$b || !in_array($b['status'], ['invoiced', 'submitted', 'acknowledged'], true)) {
                fail('Collections are recorded against an invoiced billing.');
            }
            $tg = self::collectionTargets($b);
            if (!$tg['invoice'] && !$tg['charges']) {
                fail('The billing has nothing open to collect.');
            }
            $taxes = r2((float)$c['ewt_amount'] + (float)$c['vatwh_amount']);
            $deductions = r2((float)$c['retention_amount'] + (float)$c['ld_amount'] + (float)$c['other_deduction'] + (float)$c['bank_charges']);
            $cash = r2((float)$c['cash_received']);
            $gross = r2((float)$c['gross_applied']);
            if (abs(r2($cash + $taxes + $deductions) - $gross) > 0.01) {
                fail('Cash received (' . money($cash) . ') + taxes withheld (' . money($taxes) . ') + deductions (' . money($deductions) . ') must equal the amount settled (' . money($gross) . ').');
            }
            if ($gross > $tg['open'] + 0.004) {
                fail('The amount settled (' . money($gross) . ') is more than what is open on the billing (' . money($tg['open']) . ').');
            }
            $jc = self::ensureJob($p);
            // cash goes to returned-check charges first, then to the invoice
            $apps = [];
            $leftCash = $cash;
            foreach ($tg['charges'] as $ch) {
                if ($leftCash <= 0.004) {
                    break;
                }
                $use = r2(min($leftCash, (float)$ch['open_balance']));
                $apps[] = ['target_txn_id' => (int)$ch['id'], 'amount' => $use, 'discount' => 0, 'withholding' => 0];
                $leftCash = r2($leftCash - $use);
            }
            $inv = $tg['invoice'];
            $onInvoice = r2($leftCash + $taxes + $deductions);
            if ($onInvoice > 0.004) {
                if (!$inv) {
                    fail('The invoice of this billing is not open; only the returned-check charges are (' . money($cash) . ' at most).');
                }
                if ($onInvoice > (float)$inv['open_balance'] + 0.004) {
                    fail('What goes to the invoice (' . money($onInvoice) . ') is more than its open balance (' . money($inv['open_balance']) . ').');
                }
                if ($leftCash > 0 || $taxes > 0) {
                    $apps[] = ['target_txn_id' => (int)$inv['id'], 'amount' => $leftCash, 'discount' => 0, 'withholding' => $taxes];
                }
            } elseif ($taxes + $deductions > 0.004) {
                fail('Taxes and deductions are taken on the invoice; this collection only covers returned-check charges.');
            }
            // the taxes by type: the actual amounts, spread over the expected types
            $whtRows = [];
            if ($taxes > 0 && $inv) {
                $exp = self::expectedWithholding($b, $onInvoice);
                foreach (['income' => (float)$c['ewt_amount'], 'vat' => (float)$c['vatwh_amount']] as $kind => $actual) {
                    if ($actual <= 0) {
                        continue;
                    }
                    $types = array_filter($exp['types'], fn($x) => ($x['t']['tax_kind'] === 'vat') === ($kind === 'vat'));
                    if (!$types) {
                        $fallback = self::whtType(Brgy::setting($kind === 'vat' ? 'brgy_wht_vat' : ((int)DB::value("SELECT COUNT(*) FROM brgy_billing_lines WHERE billing_id = :b AND line_kind <> 'goods'", ['b' => $b['id']]) ? 'brgy_wht_services' : 'brgy_wht_goods')));
                        if (!$fallback) {
                            fail('Set the withholding tax codes on Contracting Settings (they must be on the Withholding Tax Table).');
                        }
                        $types = [(int)$fallback['id'] => ['t' => $fallback, 'base' => r2($onInvoice * Withholding::netRatio($inv)), 'amount' => $actual]];
                    }
                    $expTotal = array_sum(array_column($types, 'amount'));
                    $left = r2($actual);
                    $i = 0;
                    foreach ($types as $tid => $x) {
                        $i++;
                        $amt = $i === count($types) ? $left : r2($expTotal > 0 ? $actual * $x['amount'] / $expTotal : $actual / count($types));
                        $left = r2($left - $amt);
                        $whtRows[] = ['wht_id' => (int)$tid, 'tax_kind' => $x['t']['tax_kind'], 'atc' => $x['t']['atc'], 'rate' => (float)$x['t']['rate'],
                            'base' => $x['base'], 'amount' => $amt, 'account_id' => Withholding::account($x['t'], 'sales')];
                    }
                }
            }
            $depositTo = (int)($c['deposit_account_id'] ?: Accts::get('undeposited_funds'));
            $memo = mb_substr('Collection ' . $c['collection_no'] . ' on billing ' . $b['billing_no'] . ' (' . $p['project_no'] . ')', 0, 255);
            if ($cash > 0 || $taxes > 0) {
                $payId = Txn::save('payment', [
                    'txn_date' => $c['collection_date'], 'customer_id' => $jc['job'], 'account_id' => $depositTo, 'account2_id' => Accts::get('ar'),
                    'payment_method_id' => self::paymentMethodFor((string)$c['method']), 'ref_no' => mb_substr((string)($c['check_no'] ?: $c['reference_no']), 0, 60) ?: null,
                    'memo' => $memo, 'total' => $cash, 'discount_amount' => 0, 'withholding_amount' => $taxes, 'class_id' => $jc['class'],
                ], [], null, ['keep_totals' => true, 'applications' => $apps, 'withholdings' => $whtRows ? [(int)$inv['id'] => $whtRows] : [],
                    'reason' => 'Barangay Contracts collection ' . $c['collection_no']]);
                self::record((int)$p['id'], 'collection', $collectionId, 'payment', $payId, $cash + $taxes, 'Cash and taxes withheld');
                DB::update('brgy_collections', ['payment_txn_id' => $payId], 'id = :id', ['id' => $collectionId]);
            }
            if ($deductions > 0) {
                $dl = [];
                $mk = fn(int $acct, float $amt, string $what) => ['line_kind' => 'item', 'account_id' => $acct, 'description' => mb_substr($what . ' (' . $c['collection_no'] . ', ' . $b['billing_no'] . ')', 0, 255),
                    'qty' => 0, 'rate' => 0, 'amount' => r2($amt), 'tax_code_id' => null, 'tax_amount' => 0, 'customer_id' => $jc['job'], 'class_id' => $jc['class']];
                if ((float)$c['retention_amount'] > 0) {
                    $ret = Brgy::acct('retention_receivable');
                    if (!$ret) {
                        fail('Set the "Retention Receivable" account under Chart of Accounts > Module Default Accounts (Barangay Contracts).');
                    }
                    $dl[] = $mk($ret, (float)$c['retention_amount'], 'Retention (' . pct($p['retention_pct']) . ')');
                }
                if ((float)$c['ld_amount'] > 0) {
                    $ld = Brgy::acct('liquidated_damages');
                    if (!$ld) {
                        fail('Set the "Liquidated Damages" account under Chart of Accounts > Module Default Accounts (Barangay Contracts).');
                    }
                    $dl[] = $mk($ld, (float)$c['ld_amount'], 'Liquidated damages');
                }
                if ((float)$c['other_deduction'] > 0) {
                    if (!$c['other_account_id']) {
                        fail('Choose the account of the other deduction.');
                    }
                    $dl[] = $mk((int)$c['other_account_id'], (float)$c['other_deduction'], $c['other_note'] ?: 'Other deduction');
                }
                if ((float)$c['bank_charges'] > 0) {
                    $dl[] = $mk(Accts::get('merchant_fees'), (float)$c['bank_charges'], 'Bank charges');
                }
                $cmId = Txn::save('credit_memo', [
                    'txn_date' => $c['collection_date'], 'customer_id' => $jc['job'], 'account_id' => Accts::get('ar'), 'class_id' => $jc['class'],
                    'memo' => mb_substr('Deductions on ' . $memo, 0, 255), 'restock' => 0, 'credit_disposition' => 'apply', 'ref_no' => $c['collection_no'],
                ], $dl, null, ['applications' => [['target_txn_id' => (int)$inv['id'], 'amount' => $deductions]], 'reason' => 'Barangay Contracts collection ' . $c['collection_no']]);
                self::record((int)$p['id'], 'collection', $collectionId, 'deductions', $cmId, $deductions, 'Retention and other deductions');
                DB::update('brgy_collections', ['deduction_txn_id' => $cmId], 'id = :id', ['id' => $collectionId]);
            }
            Brgy::advance((int)$p['id'], 'collection', 'First collection recorded');
        });
    }

    /** Retention released: a deposit to the bank out of Retention Receivable, on the job */
    private static function postRetentionRelease(array $c, array $p): void
    {
        if (self::live('collection', (int)$c['id'], 'release')) {
            fail('This retention release is already posted.');
        }
        $ret = Brgy::acct('retention_receivable');
        if (!$ret) {
            fail('Set the "Retention Receivable" account under Chart of Accounts > Module Default Accounts (Barangay Contracts).');
        }
        $bank = (int)$c['deposit_account_id'];
        if (!$bank || DB::value('SELECT account_type FROM accounts WHERE id = :id', ['id' => $bank]) !== 'Bank') {
            fail('Choose the bank account the released retention went to.');
        }
        $held = Brgy::money($p)['retention_held'];
        $amt = r2((float)$c['cash_received']);
        if ($amt <= 0) {
            fail('Enter the amount released.');
        }
        if ($amt > $held + 0.004) {
            fail('Only ' . money($held) . ' of retention is held on this contract.');
        }
        $jc = self::ensureJob($p);
        $id = Txn::save('deposit', [
            'txn_date' => $c['collection_date'], 'account_id' => $bank, 'class_id' => $jc['class'], 'ref_no' => mb_substr((string)($c['check_no'] ?: $c['reference_no']), 0, 60) ?: null,
            'memo' => mb_substr('Retention released, ' . $c['collection_no'] . ' (' . $p['project_no'] . ')', 0, 255), 'cash_back_amount' => 0,
        ], [['line_kind' => 'deposit', 'account_id' => $ret, 'amount' => $amt, 'customer_id' => $jc['job'], 'class_id' => $jc['class'],
            'description' => mb_substr('Retention released by ' . $p['entity_name'] . ($c['reference_no'] ? ', ' . $c['reference_no'] : ''), 0, 255)]],
            null, ['reason' => 'Barangay Contracts retention release ' . $c['collection_no']]);
        self::record((int)$p['id'], 'collection', (int)$c['id'], 'release', $id, $amt, 'Retention released');
        DB::update('brgy_collections', ['release_txn_id' => $id, 'gross_applied' => $amt], 'id = :id', ['id' => $c['id']]);
    }

    /** Voids a collection: its payment and deductions (or its retention deposit) are voided in the books */
    public static function voidCollection(int $collectionId, string $reason): void
    {
        DB::tx(function () use ($collectionId, $reason) {
            $c = DB::row('SELECT * FROM brgy_collections WHERE id = :id FOR UPDATE', ['id' => $collectionId]);
            if (!$c || $c['status'] === 'void') {
                fail('Collection not found or already void.');
            }
            if ($c['status'] === 'bounced') {
                fail('A returned check is already taken off; record the replacement as a new collection.');
            }
            foreach (['payment', 'deductions', 'release'] as $ev) {
                self::voidPosting('collection', $collectionId, $ev, 'Collection ' . $c['collection_no'] . ' voided: ' . $reason);
            }
            DB::update('brgy_collections', ['status' => 'void', 'notes' => mb_substr(trim(($c['notes'] ? $c['notes'] . ' ' : '') . 'Void: ' . $reason), 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $collectionId]);
            Brgy::log('void', 'collection', $collectionId, $c['collection_no'], ['reason' => $reason, 'amount' => (float)$c['cash_received']]);
        });
    }

    /**
     * A check that bounced. Not deposited yet: the payment and the deductions are voided, and the billing is open again in
     * full. Already deposited (or paid straight into the bank): a returned-check charge (statement charge) puts the cash back
     * on the receivable and takes it out of the bank; a bank fee can be booked with it.
     */
    public static function bounce(int $collectionId, string $date, string $reason, float $fee = 0.0, ?int $feeAccount = null): void
    {
        DB::tx(function () use ($collectionId, $date, $reason, $fee, $feeAccount) {
            $c = DB::row('SELECT * FROM brgy_collections WHERE id = :id FOR UPDATE', ['id' => $collectionId]);
            if (!$c || $c['status'] !== 'posted' || $c['kind'] !== 'billing') {
                fail('Only a posted collection can be marked as a returned check.');
            }
            if (!valid_date($date)) {
                fail('Enter the date the check was returned.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason the bank gave (e.g. DAIF, account closed, stale check).');
            }
            $pay = $c['payment_txn_id'] ? Txn::load((int)$c['payment_txn_id']) : null;
            if (!$pay || (int)$pay['is_voided'] === 1 || (float)$pay['total'] <= 0) {
                fail('This collection has no cash payment to reverse.');
            }
            $p = Brgy::mustProject((int)$c['project_id']);
            $deposit = DB::row("SELECT d.* FROM transaction_lines l JOIN transactions d ON d.id = l.txn_id WHERE l.ref_txn_id = :p AND d.txn_type = 'deposit' AND d.is_voided = 0 LIMIT 1", ['p' => $pay['id']]);
            $intoBank = DB::value('SELECT account_type FROM accounts WHERE id = :id', ['id' => $pay['account_id']]) === 'Bank';
            $note = 'Returned check ' . ($c['check_no'] ?: $c['reference_no']) . ' (' . $c['collection_no'] . '): ' . $reason;
            $bounceTxn = null;
            if (!$deposit && !$intoBank && !Txn::isReconciled((int)$pay['id'])) {
                self::voidPosting('collection', $collectionId, 'payment', $note);
                self::voidPosting('collection', $collectionId, 'deductions', $note . ' (deductions come again with the replacement)');
            } else {
                $bank = (int)($deposit['account_id'] ?? $pay['account_id']);
                $jc = self::ensureJob($p);
                $bounceTxn = Txn::save('statement_charge', [
                    'txn_date' => $date, 'customer_id' => $jc['job'], 'account_id' => Accts::get('ar'), 'due_date' => $date, 'class_id' => $jc['class'],
                    'memo' => mb_substr($note, 0, 255), 'ref_no' => mb_substr((string)($c['check_no'] ?: $c['reference_no']), 0, 60) ?: null,
                ], [['line_kind' => 'item', 'account_id' => $bank, 'description' => mb_substr($note, 0, 255), 'qty' => 0, 'rate' => 0, 'amount' => r2((float)$pay['total']),
                    'tax_code_id' => null, 'tax_amount' => 0, 'customer_id' => $jc['job'], 'class_id' => $jc['class']]], null, ['reason' => $note]);
                self::record((int)$p['id'], 'collection', $collectionId, 'bounce', $bounceTxn, (float)$pay['total'], 'Returned check charged back');
                if ($fee > 0) {
                    $fa = $feeAccount ?: Accts::get('merchant_fees');
                    $feeTxn = Txn::save('journal', ['txn_date' => $date, 'memo' => mb_substr('Bank fee on ' . $note, 0, 255), 'class_id' => $jc['class']], [
                        ['line_kind' => 'journal', 'account_id' => $fa, 'amount' => r2($fee), 'description' => 'Returned check fee'],
                        ['line_kind' => 'journal', 'account_id' => $bank, 'amount' => -r2($fee), 'description' => 'Returned check fee'],
                    ], null, ['reason' => $note]);
                    self::record((int)$p['id'], 'collection', $collectionId, 'bounce_fee', $feeTxn, $fee, 'Bank fee on the returned check');
                }
            }
            DB::update('brgy_collections', ['status' => 'bounced', 'bounce_date' => $date, 'bounce_reason' => mb_substr($reason, 0, 255), 'bounce_txn_id' => $bounceTxn,
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $collectionId]);
            Brgy::log('bounce', 'collection', $collectionId, $c['collection_no'], ['reason' => $note, 'amount' => (float)$pay['total']]);
            Brgy::event((int)$p['id'], 'notice', 'Check returned: ' . ($c['check_no'] ?: $c['reference_no'] ?: $c['collection_no']), ['details' => $note, 'direction' => 'in']);
        });
    }

    // ================================================================== securities

    /** A security paid in cash or by check goes to Bid and Performance Security Deposits */
    public static function postSecurity(int $securityId): ?int
    {
        $s = DB::row('SELECT s.*, p.project_no, p.class_id, p.customer_id FROM brgy_securities s JOIN brgy_projects p ON p.id = s.project_id WHERE s.id = :id', ['id' => $securityId]);
        if (!in_array($s['form'], Brgy::SECURITY_CASH_FORMS, true) || (float)$s['amount'] <= 0) {
            return null;
        }
        if (self::live('security', $securityId, 'posting')) {
            fail('This security is already posted.');
        }
        $dep = Brgy::acct('security_deposits');
        if (!$dep) {
            fail('Set the "Bid and Performance Security Deposits" account under Chart of Accounts > Module Default Accounts (Barangay Contracts).');
        }
        if (!$s['pay_account_id']) {
            fail('Choose the bank or cash account the security was paid from.');
        }
        $cust = $s['customer_id'] ?: self::ensureCustomer((int)DB::value('SELECT entity_id FROM brgy_projects WHERE id = :p', ['p' => $s['project_id']]));
        $what = Brgy::label(Brgy::SECURITY_TYPES, $s['security_type']) . ' ' . $s['security_no'] . ' (' . $s['project_no'] . ')';
        $id = Txn::save('journal', ['txn_date' => $s['issue_date'] ?: today(), 'memo' => mb_substr($what, 0, 255), 'class_id' => $s['class_id'] ?: null, 'ref_no' => $s['reference_no'] ?: null], [
            ['line_kind' => 'journal', 'account_id' => $dep, 'amount' => r2($s['amount']), 'description' => mb_substr($what, 0, 255), 'customer_id' => (int)$cust],
            ['line_kind' => 'journal', 'account_id' => (int)$s['pay_account_id'], 'amount' => -r2($s['amount']), 'description' => mb_substr($what, 0, 255)],
        ], null, ['reason' => 'Barangay Contracts security ' . $s['security_no']]);
        self::record((int)$s['project_id'], 'security', $securityId, 'posting', $id, (float)$s['amount'], 'Security paid');
        DB::update('brgy_securities', ['posted_txn_id' => $id], 'id = :id', ['id' => $securityId]);
        return $id;
    }

    /** Security returned (money back to the bank) or forfeited (a loss) */
    public static function closeSecurity(int $securityId, string $outcome, string $date, ?int $bankId): ?int
    {
        $s = DB::row('SELECT s.*, p.project_no, p.class_id, p.customer_id FROM brgy_securities s JOIN brgy_projects p ON p.id = s.project_id WHERE s.id = :id', ['id' => $securityId]);
        if ($s['status'] !== 'active') {
            fail('The security is already ' . $s['status'] . '.');
        }
        $id = null;
        if ($s['posted_txn_id'] && self::live('security', $securityId, 'posting')) {
            $dep = Brgy::acct('security_deposits');
            $what = Brgy::label(Brgy::SECURITY_TYPES, $s['security_type']) . ' ' . $s['security_no'] . ' (' . $s['project_no'] . ') ' . ($outcome === 'released' ? 'returned' : 'forfeited');
            if ($outcome === 'released') {
                if (!$bankId) {
                    fail('Choose the account the returned security went to.');
                }
                $dr = $bankId;
            } else {
                $dr = Brgy::acct('forfeited_security') ?: Accts::get('default_expense');
            }
            $id = Txn::save('journal', ['txn_date' => $date, 'memo' => mb_substr($what, 0, 255), 'class_id' => $s['class_id'] ?: null], [
                ['line_kind' => 'journal', 'account_id' => $dr, 'amount' => r2($s['amount']), 'description' => mb_substr($what, 0, 255)],
                ['line_kind' => 'journal', 'account_id' => $dep, 'amount' => -r2($s['amount']), 'description' => mb_substr($what, 0, 255), 'customer_id' => $s['customer_id'] ?: null],
            ], null, ['reason' => 'Barangay Contracts security ' . $s['security_no']]);
            self::record((int)$s['project_id'], 'security', $securityId, $outcome === 'released' ? 'refund' : 'forfeit', $id, (float)$s['amount'], $what);
        }
        DB::update('brgy_securities', ['status' => $outcome, 'released_date' => $date, 'release_txn_id' => $id], 'id = :id', ['id' => $securityId]);
        return $id;
    }

    // ================================================================== purchases for a contract

    /** A purchase order to a supplier or subcontractor, its lines on the project's Customer:Job and class */
    public static function purchaseOrder(array $p, int $vendorId, string $date, array $rows, string $memo): int
    {
        if (!DB::value('SELECT id FROM vendors WHERE id = :v AND is_active = 1', ['v' => $vendorId])) {
            fail('Choose the supplier.');
        }
        $jc = self::ensureJob($p);
        $lines = [];
        foreach ($rows as $r) {
            $iid = (int)($r['item_id'] ?? 0);
            $q = to_num($r['qty'] ?? 0);
            $rate = to_num($r['rate'] ?? 0);
            if (!$iid && $q == 0.0) {
                continue;
            }
            $it = $iid ? DB::row('SELECT * FROM items WHERE id = :id', ['id' => $iid]) : null;
            if (!$it) {
                fail('Choose an item on every line (use a non-inventory or service item for services and subcontracts).');
            }
            if ($q <= 0) {
                fail('Enter the quantity of ' . $it['code'] . '.');
            }
            $tax = $it['tax_code_id'] ? (int)$it['tax_code_id'] : Brgy::defaultTaxCode();
            $amt = r2($q * $rate);
            $lines[] = ['line_kind' => 'item', 'item_id' => $iid, 'description' => mb_substr(trim((string)($r['description'] ?? '')) ?: ($it['purchase_description'] ?: $it['name']), 0, 255),
                'unit' => $it['unit'], 'qty' => $q, 'rate' => $rate, 'uom_qty' => $q, 'uom_rate' => $rate, 'amount' => $amt, 'tax_code_id' => $tax,
                'tax_amount' => r2($amt * (float)(Lookup::taxRates()[$tax] ?? 0) / 100), 'customer_id' => $jc['job'], 'class_id' => $jc['class'], 'site_id' => $p['site_id'] ?: null, 'is_billable' => 0];
        }
        if (!$lines) {
            fail('Enter at least one line.');
        }
        $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $vendorId]);
        $id = Txn::save('purchase_order', ['txn_date' => $date, 'vendor_id' => $vendorId, 'terms_id' => $terms ?: null, 'site_id' => $p['site_id'] ?: null, 'class_id' => $jc['class'],
            'memo' => mb_substr($memo !== '' ? $memo : 'For ' . $p['project_no'] . ' ' . $p['title'], 0, 255), 'ship_date' => $p['due_date'] ?: null, 'status' => 'open'], $lines, null,
            ['reason' => 'Barangay Contracts purchase for ' . $p['project_no']]);
        self::record((int)$p['id'], 'purchase', $id, 'po', $id, (float)Txn::load($id)['total'], 'Purchase order for the contract');
        return $id;
    }

    /** The purchasing and cost documents of a project: anything with a line on its job, or its class */
    public static function costDocuments(array $p): array
    {
        $job = (int)($p['job_customer_id'] ?? 0);
        $class = (int)($p['class_id'] ?? 0);
        if (!$job && !$class) {
            return [];
        }
        $w = [];
        $params = [];
        if ($job) {
            $w[] = 'EXISTS (SELECT 1 FROM transaction_lines l WHERE l.txn_id = t.id AND l.customer_id = :job)';
            $params['job'] = $job;
        }
        if ($class) {
            $w[] = 't.class_id = :cls';
            $params['cls'] = $class;
        }
        return DB::all("SELECT t.*, v.name AS vendor_name FROM transactions t LEFT JOIN vendors v ON v.id = t.vendor_id
                         WHERE t.txn_type IN ('purchase_order', 'bill', 'item_receipt', 'check', 'cash_voucher', 'vendor_credit', 'bill_payment')
                           AND (" . implode(' OR ', $w) . ') ORDER BY t.txn_date DESC, t.id DESC', $params);
    }

    // ================================================================== reconciliation with the ledger

    /**
     * Ties every contract's operational totals to the ledger. Per project: billed in the module vs invoices posted,
     * applied to the billing invoices through the module vs everything applied in the ledger (a difference is money
     * received on the Receive Payments page directly), retention deducted less released vs the Retention Receivable
     * balance on the job, and transactions behind live postings that were voided in accounting.
     */
    public static function reconcile(?int $projectId = null): array
    {
        $out = [];
        $sql = "SELECT * FROM brgy_projects WHERE status IN ('awarded', 'closed')" . ($projectId ? ' AND id = :id' : '') . ' ORDER BY project_no';
        foreach (DB::all($sql, $projectId ? ['id' => $projectId] : []) as $p) {
            $issues = [];
            $billedModule = r2(DB::value("SELECT COALESCE(SUM(gross_amount), 0) FROM brgy_billings WHERE project_id = :p AND status IN ('invoiced', 'submitted', 'acknowledged')", ['p' => $p['id']]));
            $inv = DB::all("SELECT b.billing_no, t.id, t.total, t.is_voided, t.is_pending FROM brgy_billings b JOIN transactions t ON t.id = b.invoice_txn_id
                             WHERE b.project_id = :p AND b.status IN ('invoiced', 'submitted', 'acknowledged')", ['p' => $p['id']]);
            $billedLedger = 0.0;
            $appliedLedger = 0.0;
            $ids = [];
            foreach ($inv as $t) {
                if ((int)$t['is_voided'] === 1) {
                    $issues[] = 'the invoice of billing ' . $t['billing_no'] . ' was voided in accounting';
                    continue;
                }
                $billedLedger += (float)$t['total'];
                $appliedLedger += Balances::applied((int)$t['id']);
                $ids[] = (int)$t['id'];
            }
            $billedLedger = r2($billedLedger);
            if (abs($billedLedger - $billedModule) > 0.01) {
                $issues[] = 'billed ' . money($billedModule) . ' here but ' . money($billedLedger) . ' invoiced in the books';
            }
            $appliedModule = 0.0;
            if ($ids) {
                [$in, $pi] = DB::in('i', $ids);
                $appliedModule = r2(DB::value("SELECT COALESCE(SUM(pa.amount + pa.discount + pa.withholding), 0) FROM payment_applications pa
                                                 JOIN transactions pt ON pt.id = pa.payment_txn_id AND pt.is_voided = 0
                                                 JOIN brgy_postings bp ON bp.txn_id = pa.payment_txn_id AND bp.status = 'posted' AND bp.project_id = :p
                                                WHERE pa.target_txn_id IN $in", ['p' => $p['id']] + $pi));
            }
            $outside = r2($appliedLedger - $appliedModule);
            if (abs($outside) > 0.01) {
                $issues[] = money($outside) . ' was applied to the billing invoices outside Barangay Contracts (record it here, or explain it)';
            }
            foreach (DB::all("SELECT bp.*, t.txn_type, t.txn_no FROM brgy_postings bp JOIN transactions t ON t.id = bp.txn_id
                               WHERE bp.project_id = :p AND bp.status = 'posted' AND t.is_voided = 1", ['p' => $p['id']]) as $x) {
                $issues[] = Txn::label($x['txn_type']) . ' ' . $x['txn_no'] . ' (' . $x['source_type'] . ' ' . $x['event'] . ') was voided in accounting';
            }
            $retModule = r2(DB::value("SELECT COALESCE(SUM(retention_amount), 0) FROM brgy_collections c WHERE c.project_id = :p AND c.kind = 'billing'
                                        AND (c.status = 'posted' OR (c.status = 'bounced' AND c.bounce_txn_id IS NOT NULL))", ['p' => $p['id']])
                - DB::value("SELECT COALESCE(SUM(cash_received), 0) FROM brgy_collections WHERE project_id = :p AND kind = 'retention' AND status = 'posted'", ['p' => $p['id']]));
            $retLedger = 0.0;
            $ret = Brgy::acct('retention_receivable');
            if ($ret && $p['job_customer_id']) {
                $retLedger = r2(DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE customer_id = :c AND account_id = :a', ['c' => $p['job_customer_id'], 'a' => $ret]));
            }
            if (abs($retLedger - $retModule) > 0.01) {
                $issues[] = 'retention held is ' . money($retModule) . ' here but ' . money($retLedger) . ' in Retention Receivable';
            }
            $out[] = ['project_id' => (int)$p['id'], 'project_no' => $p['project_no'], 'title' => $p['title'], 'billed_module' => $billedModule, 'billed_ledger' => $billedLedger,
                'applied_module' => $appliedModule, 'applied_ledger' => r2($appliedLedger), 'outside' => $outside, 'retention_module' => $retModule, 'retention_ledger' => $retLedger,
                'issues' => $issues];
        }
        return $out;
    }
}
