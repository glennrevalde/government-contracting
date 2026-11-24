<?php
declare(strict_types=1);

/**
 * Barangay Contracts: supplies, services and works sold to barangays and other procuring entities.
 *
 * One record (brgy_projects) follows a job from the opportunity to the closeout:
 *   01 intake, 02 eligibility, 03 costing, 04 pre-submission approval, 05 bid submitted,
 *   06 evaluation, 07 award and contract, 08 fulfilment, 09 delivery and acceptance,
 *   10 billing, 11 collection, 12 closeout.
 *
 * Controlled records (the bid, billing packs, change orders, prepared forms) move
 * Draft -> For review (maker) -> Reviewed (checker) -> Approved (approver) -> Released / posted,
 * with Returned and Reopen paths. Nobody checks or approves what they prepared themselves.
 *
 * The procuring entity's own acts (notice of award, purchase order, notice to proceed, inspection and
 * acceptance, payment) are recorded from the papers it issued, with their dates and evidence; the
 * program never presents an internal approval as the entity's decision.
 *
 * The ledger is reached only through BrgyPost (app/BrgyPost.php), with the transactions of the
 * accounting modules: invoices, customer payments, credit memos, deposits, journal entries,
 * inventory transfers and purchase orders.
 */
final class Brgy
{
    public const MODULE = 'brgy';

    public const PHASES = [
        'intake'      => ['01', 'Opportunity intake'],
        'eligibility' => ['02', 'Eligibility and compliance'],
        'costing'     => ['03', 'Costing and proposal'],
        'approval'    => ['04', 'Pre-submission approval'],
        'submitted'   => ['05', 'Bid submitted'],
        'evaluation'  => ['06', 'Evaluation and post-qualification'],
        'award'       => ['07', 'Award and contract'],
        'fulfillment' => ['08', 'Mobilization and fulfilment'],
        'delivery'    => ['09', 'Delivery and acceptance'],
        'billing'     => ['10', 'Billing'],
        'collection'  => ['11', 'Collection'],
        'closeout'    => ['12', 'Closeout'],
    ];

    /**
     * v33: the people of a transaction and the column each is kept in. People Accountable Set-up names the user
     * roles allowed for each (none named: every active user, as before); the drop-downs list only those users.
     */
    public const FUNCTIONS = [
        'sales_rep' => ['Sales representative', 'sales_rep_id'],
        'preparer'  => ['Preparer (maker)', 'preparer_id'],
        'checker'   => ['Checker / reviewer', 'checker_id'],
        'approver'  => ['Approver', 'approver_id'],
        'submitter' => ['Authorized submitter', 'submitter_id'],
        'pm'        => ['Project manager', 'pm_id'],
        'collector' => ['Collector', 'collector_id'],
    ];

    public const STATUSES = ['open' => 'Open', 'awarded' => 'Awarded', 'lost' => 'Lost', 'no_bid' => 'No bid', 'cancelled' => 'Cancelled', 'closed' => 'Closed'];

    public const CATEGORIES = ['goods' => 'Goods', 'services' => 'General services', 'works' => 'Infrastructure works', 'consultancy' => 'Consulting services'];

    public const LINE_KINDS = ['goods' => 'Goods', 'service' => 'Service', 'works' => 'Works', 'other' => 'Other'];

    public const REGIMES = ['ra12009' => 'RA 12009 (New Government Procurement Act)', 'ra9184' => 'RA 9184 (transition: started under the old law)'];

    /** status words of the controlled records */
    public const WF = [
        'draft' => 'Draft', 'for_review' => 'For review', 'reviewed' => 'Reviewed', 'approved' => 'Approved', 'returned' => 'Returned',
        'released' => 'Submitted to the entity', 'invoiced' => 'Invoiced', 'submitted' => 'Submitted', 'acknowledged' => 'Acknowledged',
        'applied' => 'Applied to the contract', 'final' => 'Final', 'void' => 'Void',
    ];

    public const ENTITY_TYPES = ['barangay' => 'Barangay', 'municipality' => 'Municipality', 'city' => 'City', 'province' => 'Province', 'agency' => 'National agency / GOCC', 'other' => 'Other'];

    public const CONTACT_ROLES = [
        'punong_barangay' => 'Punong Barangay / head of the entity', 'bac_chair' => 'BAC Chairperson', 'bac_member' => 'BAC member',
        'bac_secretariat' => 'BAC Secretariat', 'treasurer' => 'Treasurer', 'secretary' => 'Secretary', 'accountant' => 'Accountant / bookkeeper',
        'end_user' => 'End-user / requesting office', 'inspection' => 'Inspection and acceptance committee', 'appropriations_chair' => 'Chairman, Committee on Appropriations',
        'bookkeeper' => 'Barangay bookkeeper', 'city_accountant' => 'City / municipal accountant', 'other' => 'Other contact',
    ];

    public const SUB_TYPES = ['bid' => 'Bid / quotation', 'clarification' => 'Clarification / reply', 'compliance' => 'Compliance documents', 'post_qual' => 'Post-qualification documents',
        'contract' => 'Contract documents', 'billing' => 'Billing pack', 'followup' => 'Collection follow-up', 'other' => 'Other'];

    public const SUB_METHODS = ['physical' => 'Hand-carried (physical)', 'email' => 'E-mail', 'philgeps' => 'PhilGEPS / e-procurement', 'courier' => 'Courier', 'portal' => 'Other portal', 'other' => 'Other'];

    public const EVENT_TYPES = [
        'note' => 'Note', 'call' => 'Call / meeting', 'site_inspection' => 'Site inspection', 'prebid' => 'Pre-bid conference', 'bulletin' => 'Supplemental bid bulletin',
        'clarification' => 'Clarification request', 'bid_opening' => 'Bid opening', 'post_qual' => 'Post-qualification', 'noa' => 'Notice of award',
        'contract' => 'Contract / purchase order', 'ntp' => 'Notice to proceed', 'inspection' => 'Inspection', 'notice' => 'Notice from the entity',
        'letter' => 'Letter', 'sop' => 'SOP step', 'payment' => 'Payment / cheque', 'other' => 'Other',
    ];

    public const TASK_TYPES = ['task' => 'Task', 'milestone' => 'Milestone', 'progress' => 'Progress log', 'risk' => 'Risk', 'defect' => 'Defect / punch item'];

    public const SECURITY_TYPES = ['bid' => 'Bid security', 'performance' => 'Performance security', 'warranty' => 'Warranty security'];

    public const SECURITY_FORMS = [
        'cash' => 'Cash', 'cashiers_check' => "Cashier's check", 'managers_check' => "Manager's check", 'bank_draft' => 'Bank draft',
        'bank_guarantee' => 'Bank guarantee', 'letter_of_credit' => 'Irrevocable letter of credit', 'surety_bond' => 'Surety bond',
        'declaration' => 'Bid / performance securing declaration',
    ];

    /** forms of security paid out of the company's money: they post to Bid / Performance Security Deposits */
    public const SECURITY_CASH_FORMS = ['cash', 'cashiers_check', 'managers_check', 'bank_draft'];

    public const COLLECTION_METHODS = ['check' => 'Check', 'lddap_ada' => 'LDDAP-ADA (bank credit)', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'];

    public const BILLING_TYPES = ['partial' => 'Partial (per accepted delivery)', 'progress' => 'Progress billing', 'milestone' => 'Milestone', 'final' => 'Final billing'];

    public const DOC_GROUPS = ['legal' => 'Legal', 'technical' => 'Technical', 'financial' => 'Financial', 'bid' => 'Bid forms', 'contract' => 'Contract',
        'delivery' => 'Delivery and acceptance', 'billing' => 'Billing', 'collection' => 'Collection', 'other' => 'Other'];

    public const STAGES = ['eligibility' => 'Eligibility', 'bid' => 'Bid / quotation', 'post_qual' => 'Post-qualification', 'contract' => 'Contract signing',
        'delivery' => 'Delivery', 'billing' => 'Billing'];

    public const REQ_TYPES = ['mandatory' => 'Mandatory', 'conditional' => 'Conditional', 'optional' => 'Optional'];

    /** settings of the module with their defaults (System: Barangay Contracts > Contracting Settings) */
    public const SETTINGS = [
        'brgy_company_reg_no'        => '',
        'brgy_philgeps_no'           => '',
        'brgy_philgeps_class'        => '',
        'brgy_bir_status'            => 'vat',      // vat, non_vat
        'brgy_signatory_name'        => '',
        'brgy_signatory_position'    => '',
        'brgy_signatory_title'       => 'Mr.',      // Mr., Ms., Engr. ... (the notices of the barangay: "MR. JUAN DELA CRUZ", "Dear Mr. J. Dela Cruz:")
        'brgy_signatory_mobile'      => '',
        // the persons managing the affairs of the firm, and the authorized liaison officer (Supplier Profile)
        'brgy_manager_name'          => '',
        'brgy_manager_position'      => '',
        'brgy_manager_phone'         => '',
        'brgy_manager_mobile'        => '',
        'brgy_liaison_name'          => '',
        'brgy_liaison_position'      => 'Liaison Officer',
        'brgy_liaison_phone'         => '',
        'brgy_liaison_mobile'        => '',
        // v37: the representative in the sworn statements (omnibus sworn statement, bid securing declaration)
        'brgy_signatory_civil_status' => '',
        'brgy_signatory_citizenship' => 'Filipino',
        'brgy_signatory_residence'   => '',
        'brgy_signatory_id_type'     => '',
        'brgy_signatory_id_no'       => '',
        'brgy_notary_place'          => '',
        'brgy_bank_details'          => '',
        'brgy_prices_vat_inclusive'  => '1',
        'brgy_sod_strict'            => '1',
        'brgy_block_over_abc'        => '1',
        'brgy_gate_documents'        => '1',
        'brgy_gate_deadline'         => '1',
        'brgy_require_acceptance_doc'=> '1',
        'brgy_require_pack_complete' => '1',
        'brgy_require_perf_security' => '0',
        'brgy_create_class'          => '1',
        'brgy_delivered_site_id'     => '',
        'brgy_default_retention_pct' => '0',
        'brgy_default_ld_rate'       => '0',
        'brgy_wht_goods'             => 'WT-GOODS',
        'brgy_wht_services'          => 'WT-SERVICES',
        'brgy_wht_vat'               => 'WT-VAT',
        'brgy_billing_pack'          => "invoice|Sales invoice (BIR-registered)|1\nsoa|Statement of account|1\ndr|Delivery receipt, signed by the entity|1\niar|Inspection and acceptance report|1\npo|Purchase order / contract copy|1\naccomplishment|Accomplishment report (services and works)|0\nphoto|Photos of the delivery / work|0",
        'brgy_alert_days'            => '30',
        'brgy_bid_alert_days'        => '7',
        'brgy_accept_alert_days'     => '7',
        'brgy_ack_alert_days'        => '15',
        'brgy_upload_max_mb'         => '15',
        'brgy_scan_command'          => '',
        'brgy_office_converter'      => '',       // LibreOffice for the page view of Office files: '' finds it, a path to soffice, or "off"
        'brgy_alert_email'           => '',
        // the SOP (Standard Operating Procedure) of the company
        'brgy_sop_hold_billing'      => '1',        // special case F: no SI, OR or 2307 while the payment is not released
        'brgy_sop_review_other'      => '1',        // the review step is done by someone other than whoever prepared the billing papers
        'brgy_sop_need_scans'        => '1',        // a paper counts as signed when its signed copy is attached
        'brgy_svp_limit'             => '100000',   // suggested process: small value procurement below this amount
        'brgy_tradename'             => '',
        'brgy_txn_types'             => "cash_out|Cash Out (purchases)\nkaliwaan|Kaliwaan (purchases)\npurchases|Purchases\nconstruction|Construction",
        'brgy_accounting_dept'       => 'Accounting Department',
        // v34: the drop-downs of the estimates on the transaction form (rates in %, separated by commas)
        'brgy_est_wt_rates'          => '1,4,5,6,7',
        'brgy_est_profit_rates'      => '10,13,15',
        'brgy_est_receipt_rates'     => '3',
        // v37: the withholding tax is worked out on the gross divided by this (1.12: the gross less VAT), the receipt deduction on this % of the gross
        'brgy_est_wt_divisor'        => '1.12',
        'brgy_est_receipt_base'      => '80',
    ];

    // ================================================================== small helpers

    public static function setting(string $key): string
    {
        return Settings::get($key, self::SETTINGS[$key] ?? '');
    }

    public static function on(string $key): bool
    {
        return self::setting($key) === '1';
    }

    /** The tables exist (the 2026-10-29 migration has been run) */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM brgy_projects WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function label(array $map, ?string $key): string
    {
        return (string)($map[(string)$key] ?? ucwords(str_replace('_', ' ', (string)$key)));
    }

    public static function phaseLabel(?string $phase): string
    {
        $p = self::PHASES[(string)$phase] ?? null;
        return $p ? $p[0] . ' ' . $p[1] : (string)$phase;
    }

    public static function phaseIndex(?string $phase): int
    {
        $i = array_search((string)$phase, array_keys(self::PHASES), true);
        return $i === false ? -1 : (int)$i;
    }

    /** Plain coloured status word (the system shows statuses as text, never as capsules) */
    public static function st(?string $status, ?string $text = null): string
    {
        $tone = [
            'draft' => 'muted', 'for_review' => 'warn', 'reviewed' => 'info', 'approved' => 'ok', 'returned' => 'bad', 'released' => 'ok',
            'invoiced' => 'info', 'submitted' => 'info', 'acknowledged' => 'ok', 'applied' => 'ok', 'final' => 'ok', 'void' => 'muted',
            'open' => 'info', 'awarded' => 'ok', 'lost' => 'bad', 'no_bid' => 'muted', 'cancelled' => 'bad', 'closed' => 'muted',
            'missing' => 'bad', 'expired' => 'bad', 'pending' => 'warn', 'ok' => 'ok', 'waived' => 'muted', 'superseded' => 'muted',
            'delivered' => 'info', 'accepted' => 'ok', 'partial' => 'warn', 'rejected' => 'bad', 'posted' => 'ok', 'bounced' => 'bad',
            'active' => 'info', 'forfeited' => 'bad', 'done' => 'ok', 'in_progress' => 'warn', 'paid' => 'ok', 'unpaid' => 'warn', 'overdue' => 'bad',
            'go' => 'ok', 'no_go' => 'bad', 'proposed' => 'warn',
        ][(string)$status] ?? 'muted';
        $text ??= self::WF[(string)$status] ?? self::STATUSES[(string)$status] ?? ucwords(str_replace('_', ' ', (string)$status));
        return '<span class="st st-' . $tone . '">' . h($text) . '</span>';
    }

    public static function userName(?int $id): string
    {
        static $c = [];
        if (!$id) {
            return '';
        }
        return $c[$id] ??= (string)DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $id]);
    }

    public static function users(): array
    {
        return DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 AND approval_status = 'approved' ORDER BY full_name");
    }

    // ---------------------------------------------------------------- v33: people accountable and sales representatives

    /** The role ids allowed for a function (People Accountable Set-up); none means every active user */
    public static function functionRoles(string $fn): array
    {
        self::loadFunctions();
        return self::$fnRoles[$fn] ?? [];
    }

    /** v34: the users allowed for a function by name (People Accountable Set-up: "Users" instead of "Roles") */
    public static function functionUsers(string $fn): array
    {
        self::loadFunctions();
        return self::$fnUsers[$fn] ?? [];
    }

    /** How a function picks its people: 'users' (named users), 'roles' (users of named roles) or 'all' (every active user) */
    public static function functionMode(string $fn): string
    {
        return self::functionUsers($fn) ? 'users' : (self::functionRoles($fn) ? 'roles' : 'all');
    }

    private static function loadFunctions(): void
    {
        if (self::$fnRoles !== null) {
            return;
        }
        self::$fnRoles = [];
        self::$fnUsers = [];
        if (self::v33Ready()) {
            foreach (DB::all('SELECT function_code, role_id FROM brgy_function_roles') as $r) {
                self::$fnRoles[$r['function_code']][] = (int)$r['role_id'];
            }
        }
        if (self::v34Ready()) {
            foreach (DB::all('SELECT function_code, user_id FROM brgy_function_users') as $r) {
                self::$fnUsers[$r['function_code']][] = (int)$r['user_id'];
            }
        }
    }

    /** Reads People Accountable Set-up again (after it was changed in the same request) */
    public static function forgetFunctionRoles(): void
    {
        self::$fnRoles = null;
        self::$fnUsers = null;
    }

    private static ?array $fnRoles = null;
    private static ?array $fnUsers = null;

    /** The users who may be chosen for a function: the users named for it, else the active users of its roles, else every active user */
    public static function usersFor(string $fn): array
    {
        $active = "u.status = 'active' AND u.is_platform = 0 AND u.approval_status = 'approved'";
        switch (self::functionMode($fn)) {
            case 'users':
                return DB::pairs("SELECT u.id, u.full_name FROM users u WHERE $active AND u.id IN (" . implode(',', array_map('intval', self::functionUsers($fn))) . ') ORDER BY u.full_name');
            case 'roles':
                return DB::pairs("SELECT DISTINCT u.id, u.full_name FROM users u JOIN user_roles ur ON ur.user_id = u.id
                                   WHERE $active AND ur.role_id IN (" . implode(',', array_map('intval', self::functionRoles($fn))) . ')
                                   ORDER BY u.full_name');
            default:
                return self::users();
        }
    }

    /** Whether a user may be chosen for a function */
    public static function userAllowed(string $fn, ?int $userId): bool
    {
        if (!$userId) {
            return true;
        }
        switch (self::functionMode($fn)) {
            case 'users':
                return in_array($userId, self::functionUsers($fn), true);
            case 'roles':
                return (bool)DB::value('SELECT 1 FROM user_roles WHERE user_id = :u AND role_id IN (' . implode(',', array_map('intval', self::functionRoles($fn))) . ') LIMIT 1', ['u' => $userId]);
            default:
                return true;
        }
    }

    /** Stops a save when a user outside what People Accountable Set-up allows is newly chosen for a function */
    public static function assertUserAllowed(string $fn, ?int $userId, ?int $before = null): void
    {
        if ($userId && $userId !== $before && !self::userAllowed($fn, $userId)) {
            if (self::functionMode($fn) === 'users') {
                $who = DB::pairs('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', self::functionUsers($fn))) . ') ORDER BY full_name');
                $allowed = implode(', ', $who);
            } else {
                $allowed = 'the roles ' . implode(', ', DB::pairs('SELECT id, name FROM roles WHERE id IN (' . implode(',', array_map('intval', self::functionRoles($fn))) . ') ORDER BY name'));
            }
            fail(self::userName($userId) . ' cannot be the ' . strtolower(self::FUNCTIONS[$fn][0]) . ': People Accountable Set-up allows only ' . $allowed . ' for it.');
        }
    }

    /** The table of version 34 (brgy_function_users) exists: the 2026-11-21 migration has been run */
    public static function v34Ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM brgy_function_users WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    // ---------------------------------------------------------------- v34: the estimates and targets of the transaction form

    /** The rates offered by a drop-down of the transaction form (Contracting Settings): [rate => "5%"] */
    public static function rateList(string $key): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', self::setting($key)) as $v) {
            if (is_numeric($v) && (float)$v > 0 && (float)$v < 100) {
                $r = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
                $out[$r] = $r . '%';
            }
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    /** A rate as the drop-downs show it: 5.00 -> "5" */
    public static function rateKey($v): string
    {
        return $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    }

    /** v37: the gross is divided by this before the withholding tax rate is applied (Contracting Settings; 1.12 = the gross less VAT) */
    public static function estWtDivisor(): float
    {
        $v = (float)self::setting('brgy_est_wt_divisor');
        return $v >= 1 && $v <= 2 ? round($v, 4) : 1.12;
    }

    /** v37: the % of the gross the receipt deduction rate is applied to (Contracting Settings; 80) */
    public static function estReceiptBase(): float
    {
        $v = (float)self::setting('brgy_est_receipt_base');
        return $v > 0 && $v <= 100 ? round($v, 2) : 80.0;
    }

    /** v38: the values filled on a transaction's forms are kept (the 2026-11-25 migration has been run) */
    public static function v38Ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM brgy_form_values WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** v37: the column of the SOP as a % of the gross exists (the 2026-11-24 migration has been run) */
    public static function v37Ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(est_sop_rate) FROM brgy_projects WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * The estimates and targets typed on the transaction form (no accounting entry comes from them). v37:
     *   withholding tax   = gross / 1.12 x rate             (50,000 / 1.12 x 6% = 2,678.57)
     *   cheque amount     = gross - withholding tax          (47,321.43)
     *   SOP               = gross x rate, or an amount typed (50,000 x 2% = 1,000.00)
     *   profit            = gross x rate                     (50,000 x 15% = 7,500.00)
     *   receipt deduction = 80% of the gross x rate          (50,000 x 80% x 3% = 1,200.00)
     *   amount to release = gross - withholding tax - SOP - profit - receipt deduction (37,621.43)
     * The divisor (1.12) and the 80% are set on Contracting Settings.
     */
    public static function estimates(array $p): array
    {
        $gross = r2((float)($p['est_gross'] ?? 0));
        $rate = fn(string $k) => ($p[$k] ?? null) !== null && $p[$k] !== '' ? (float)$p[$k] : null;
        $wtRate = $rate('est_wt_rate');
        $profitRate = $rate('est_profit_rate');
        $receiptRate = $rate('est_receipt_rate');
        $sopRate = $rate('est_sop_rate');
        $div = self::estWtDivisor();
        $base = self::estReceiptBase();
        $wt = $wtRate !== null ? r2($gross / $div * $wtRate / 100) : 0.0;
        $sop = $sopRate !== null ? r2($gross * $sopRate / 100)
            : (($p['est_sop'] ?? null) !== null && $p['est_sop'] !== '' ? r2((float)$p['est_sop']) : null);
        $profit = $profitRate !== null ? r2($gross * $profitRate / 100) : 0.0;
        $receipt = $receiptRate !== null ? r2($gross * $base / 100 * $receiptRate / 100) : 0.0;
        $cheque = r2($gross - $wt);
        return ['gross' => $gross, 'wt_rate' => $wtRate, 'wt' => $wt, 'wt_divisor' => $div, 'cheque' => $cheque,
            'release' => r2($cheque - (float)$sop - $profit - $receipt),
            'profit_rate' => $profitRate, 'profit' => $profit,
            'receipt_rate' => $receiptRate, 'receipt' => $receipt, 'receipt_base' => $base,
            'sop' => $sop, 'sop_rate' => $sopRate,
            'any' => $gross > 0 || $wtRate !== null || $profitRate !== null || $receiptRate !== null || $sop !== null];
    }

    // ---------------------------------------------------------------- v33: tradenames

    /** The tables of version 33 exist (the 2026-11-20 migration has been run) */
    public static function v33Ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM brgy_tradenames WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM brgy_function_roles WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** The tradenames of the company: [name => name], the default first */
    public static function tradenames(bool $activeOnly = true): array
    {
        if (!self::v33Ready()) {
            $one = self::setting('brgy_tradename');
            return $one !== '' ? [$one => $one] : [];
        }
        $out = [];
        foreach (DB::all('SELECT name FROM brgy_tradenames' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_default DESC, sort_order, name') as $r) {
            $out[$r['name']] = $r['name'];
        }
        return $out;
    }

    /** The tradename a new transaction starts with: the default on the Tradenames list, else the company name */
    public static function defaultTradename(): string
    {
        if (self::v33Ready()) {
            $d = (string)DB::value('SELECT name FROM brgy_tradenames WHERE is_default = 1 AND is_active = 1 ORDER BY id LIMIT 1');
            if ($d !== '') {
                return $d;
            }
        }
        return self::setting('brgy_tradename') ?: (string)Settings::get('company_name');
    }

    public static function entities(bool $activeOnly = true): array
    {
        return DB::pairs('SELECT id, official_name FROM brgy_entities' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY official_name');
    }

    public static function modes(bool $activeOnly = true): array
    {
        return DB::pairs("SELECT id, CONCAT(name, IF(regime = 'ra9184', ' (RA 9184)', '')) FROM brgy_proc_modes" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    public static function docTypes(?bool $company = null): array
    {
        $sql = 'SELECT code, name FROM brgy_doc_types WHERE is_active = 1';
        if ($company !== null) {
            $sql .= ' AND is_company = ' . ($company ? 1 : 0);
        }
        return DB::pairs($sql . ' ORDER BY sort_order, name');
    }

    public static function docTypeName(?string $code): string
    {
        static $all = null;
        $all ??= DB::pairs('SELECT code, name FROM brgy_doc_types');
        return (string)($all[(string)$code] ?? $code);
    }

    /** VAT inclusive split of a gross amount: [net, tax] with tax = gross - net, so the two always add up */
    public static function splitVat(float $gross, ?int $taxCodeId): array
    {
        $rate = $taxCodeId ? (float)(Lookup::taxRates()[$taxCodeId] ?? 0) : 0.0;
        $gross = r2($gross);
        if ($rate <= 0) {
            return [$gross, 0.0];
        }
        if (!self::on('brgy_prices_vat_inclusive')) {
            $tax = r2($gross * $rate / 100);
            return [$gross, $tax];
        }
        $net = r2($gross / (1 + $rate / 100));
        return [$net, r2($gross - $net)];
    }

    /** The tax code new lines start with: VAT 12% for a VAT-registered company, non-taxable otherwise */
    public static function defaultTaxCode(): ?int
    {
        $code = self::setting('brgy_bir_status') === 'non_vat' ? 'NT' : 'VAT12';
        $id = DB::value('SELECT id FROM tax_codes WHERE code = :c AND is_active = 1', ['c' => $code]);
        return $id ? (int)$id : null;
    }

    // ================================================================== loading

    public static function project(int $id): ?array
    {
        return DB::row(
            'SELECT p.*, e.official_name AS entity_name, e.address AS entity_address, e.tin AS entity_tin, m.name AS mode_name, m.code AS mode_code,
                    c.name AS job_name, cl.name AS class_name, t.name AS terms_name
               FROM brgy_projects p
               JOIN brgy_entities e ON e.id = p.entity_id
               LEFT JOIN brgy_proc_modes m ON m.id = p.mode_id
               LEFT JOIN customers c ON c.id = p.job_customer_id
               LEFT JOIN classes cl ON cl.id = p.class_id
               LEFT JOIN terms t ON t.id = p.terms_id
              WHERE p.id = :id',
            ['id' => $id]
        );
    }

    public static function mustProject(int $id): array
    {
        $p = self::project($id);
        if (!$p) {
            fail('Project not found.');
        }
        return $p;
    }

    public static function lines(int $projectId): array
    {
        return DB::all(
            'SELECT l.*, i.code AS item_code, i.name AS item_name, i.item_type, tc.code AS tax_code, tc.rate AS tax_rate
               FROM brgy_project_lines l
               LEFT JOIN items i ON i.id = l.item_id
               LEFT JOIN tax_codes tc ON tc.id = l.tax_code_id
              WHERE l.project_id = :p ORDER BY l.line_no, l.id',
            ['p' => $projectId]
        );
    }

    public static function entity(int $id): ?array
    {
        return DB::row('SELECT * FROM brgy_entities WHERE id = :id', ['id' => $id]);
    }

    /** The official in office for a role on a date (the newest one when several overlap) */
    public static function official(int $entityId, string $role, ?string $on = null): ?array
    {
        $on ??= today();
        return DB::row(
            'SELECT * FROM brgy_contacts WHERE entity_id = :e AND role_code = :r
                AND (valid_from IS NULL OR valid_from <= :d1) AND (valid_to IS NULL OR valid_to >= :d2)
              ORDER BY COALESCE(valid_from, \'1900-01-01\') DESC, id DESC LIMIT 1',
            ['e' => $entityId, 'r' => $role, 'd1' => $on, 'd2' => $on]
        );
    }

    /** Contract amount with the approved change orders */
    public static function contractTotal(array $p): float
    {
        return r2((float)$p['contract_amount'] + (float)$p['co_amount']);
    }

    // ================================================================== conflicting updates

    /**
     * Optimistic locking: a form carries the lock_version it was opened with. Saving after somebody else saved
     * the same record is refused with the name of that person, instead of quietly overwriting their work.
     */
    public static function checkVersion(string $table, int $id, $posted): void
    {
        $allowed = ['brgy_projects', 'brgy_billings', 'brgy_deliveries', 'brgy_change_orders', 'brgy_forms', 'brgy_templates'];
        if (!in_array($table, $allowed, true)) {
            throw new InvalidArgumentException('No version column on ' . $table);
        }
        $row = DB::row("SELECT lock_version FROM `$table` WHERE id = :id FOR UPDATE", ['id' => $id]);
        if (!$row) {
            fail('The record no longer exists.');
        }
        if ($posted === null || $posted === '') {
            return;
        }
        if ((int)$posted !== (int)$row['lock_version']) {
            $who = DB::row("SELECT username, created_at FROM audit_trail WHERE module = 'brgy' AND record_type = :t AND record_id = :id ORDER BY id DESC LIMIT 1",
                ['t' => substr($table, 5), 'id' => $id]);
            fail('Someone else changed this record after you opened it' . ($who ? ' (' . $who['username'] . ', ' . fdatetime($who['created_at']) . ')' : '')
                . '. Nothing was saved. Reload the page to see their changes, then make yours again.');
        }
    }

    public static function bumpVersion(string $table, int $id): void
    {
        DB::run("UPDATE `$table` SET lock_version = lock_version + 1 WHERE id = :id", ['id' => $id]);
    }

    public static function versionField($v): string
    {
        return '<input type="hidden" name="lock_version" value="' . (int)$v . '">';
    }

    // ================================================================== audit and timeline

    public static function log(string $action, string $recordType, ?int $recordId, string $ref, array $opts = []): void
    {
        Audit::log($action, self::MODULE, $recordType, $recordId, $ref, $opts + ['page_key' => $GLOBALS['CURRENT_PAGE'] ?? 'brgy.projects']);
    }

    public static function event(int $projectId, string $type, string $title, array $more = []): int
    {
        return DB::insert('brgy_events', [
            'project_id' => $projectId, 'entity_id' => $more['entity_id'] ?? null, 'event_type' => $type,
            'event_at' => $more['event_at'] ?? date('Y-m-d H:i:s'), 'direction' => $more['direction'] ?? 'internal',
            'title' => mb_substr($title, 0, 200), 'details' => $more['details'] ?? null, 'party' => $more['party'] ?? null,
            'doc_id' => $more['doc_id'] ?? null, 'due_at' => $more['due_at'] ?? null, 'status' => $more['status'] ?? 'done',
            'created_by' => Auth::id(),
        ]);
    }

    /** Every step of a project in date order, newest first */
    public static function timeline(int $projectId, int $limit = 400): array
    {
        $rows = [];
        foreach (DB::all('SELECT * FROM brgy_events WHERE project_id = :p', ['p' => $projectId]) as $e) {
            $rows[] = ['at' => $e['event_at'], 'kind' => 'event', 'icon' => $e['direction'] === 'in' ? 'box-arrow-in-down-left' : ($e['direction'] === 'out' ? 'box-arrow-up-right' : 'chat-left-text'),
                'title' => $e['title'], 'sub' => self::label(self::EVENT_TYPES, $e['event_type']) . ($e['party'] ? ', ' . $e['party'] : ''), 'text' => $e['details'],
                'by' => self::userName((int)$e['created_by']), 'doc' => $e['doc_id']];
        }
        foreach (DB::all('SELECT * FROM brgy_submissions WHERE project_id = :p', ['p' => $projectId]) as $s) {
            $rows[] = ['at' => $s['submitted_at'], 'kind' => 'submission', 'icon' => 'send',
                'title' => self::label(self::SUB_TYPES, $s['sub_type']) . ' submitted by ' . self::label(self::SUB_METHODS, $s['method']),
                'sub' => trim(($s['recipient_name'] ? 'to ' . $s['recipient_name'] : '') . ($s['received_at'] ? ', received ' . fdatetime($s['received_at']) : ', receipt not yet confirmed'), ', '),
                'text' => trim(($s['tracking_no'] ? 'Tracking ' . $s['tracking_no'] . '. ' : '') . ($s['ack_ref'] ? 'Acknowledgment ' . $s['ack_ref'] . '. ' : '') . (string)$s['outcome']),
                'by' => self::userName((int)$s['submitted_by']), 'doc' => $s['proof_doc_id']];
        }
        foreach (DB::all('SELECT * FROM brgy_workflow WHERE project_id = :p', ['p' => $projectId]) as $w) {
            $obj = ['bid' => 'Bid', 'billing' => 'Billing', 'change_order' => 'Change order', 'form' => 'Form', 'phase' => 'Phase', 'requirement' => 'Requirement', 'document' => 'Document', 'closeout' => 'Closeout'][$w['object_type']] ?? $w['object_type'];
            $title = $w['object_type'] === 'phase'
                ? 'Moved to ' . self::phaseLabel($w['to_status'])
                : $obj . ': ' . ucfirst(str_replace('_', ' ', $w['action'])) . ($w['to_status'] ? ' (' . (self::WF[$w['to_status']] ?? $w['to_status']) . ')' : '');
            $rows[] = ['at' => $w['created_at'], 'kind' => 'workflow', 'icon' => $w['action'] === 'return' ? 'arrow-return-left' : ($w['action'] === 'approve' ? 'patch-check' : 'arrow-right-circle'),
                'title' => $title, 'sub' => $w['on_behalf_of'] ? 'for ' . self::userName((int)$w['on_behalf_of']) : '', 'text' => $w['remarks'], 'by' => self::userName((int)$w['user_id']), 'doc' => null];
        }
        foreach (DB::all('SELECT * FROM brgy_deliveries WHERE project_id = :p', ['p' => $projectId]) as $d) {
            $rows[] = ['at' => $d['delivery_date'] . ' 08:00:00', 'kind' => 'delivery', 'icon' => 'truck', 'title' => 'Delivery ' . $d['dr_no'] . ' (' . (self::WF[$d['status']] ?? $d['status']) . ')',
                'sub' => $d['received_by_name'] ? 'Received by ' . $d['received_by_name'] : '', 'text' => $d['acceptance_ref'] ? 'Acceptance ' . $d['acceptance_ref'] . ' on ' . fdate($d['acceptance_date']) : '', 'by' => self::userName((int)$d['created_by']), 'doc' => $d['acceptance_doc_id']];
        }
        foreach (DB::all('SELECT * FROM brgy_billings WHERE project_id = :p', ['p' => $projectId]) as $b) {
            $rows[] = ['at' => $b['billing_date'] . ' 09:00:00', 'kind' => 'billing', 'icon' => 'receipt', 'title' => 'Billing ' . $b['billing_no'] . ' for ' . money($b['gross_amount']),
                'sub' => self::WF[$b['status']] ?? $b['status'], 'text' => $b['si_no'] ? 'Sales invoice ' . $b['si_no'] : '', 'by' => self::userName((int)$b['created_by']), 'doc' => null];
        }
        foreach (DB::all('SELECT * FROM brgy_collections WHERE project_id = :p', ['p' => $projectId]) as $c) {
            $rows[] = ['at' => $c['collection_date'] . ' 10:00:00', 'kind' => 'collection', 'icon' => 'cash-coin',
                'title' => ($c['kind'] === 'retention' ? 'Retention released ' : 'Collection ') . $c['collection_no'] . ': ' . money($c['cash_received']) . ' received',
                'sub' => self::label(self::COLLECTION_METHODS, $c['method']) . ($c['reference_no'] ? ' ' . $c['reference_no'] : '') . ($c['status'] !== 'posted' ? ' (' . $c['status'] . ')' : ''),
                'text' => '', 'by' => self::userName((int)$c['created_by']), 'doc' => $c['cert_2307_doc_id']];
        }
        foreach (DB::all('SELECT * FROM brgy_documents WHERE project_id = :p', ['p' => $projectId]) as $d) {
            $rows[] = ['at' => $d['uploaded_at'], 'kind' => 'document', 'icon' => 'file-earmark-arrow-up', 'title' => 'Document filed: ' . $d['title'] . ($d['version_no'] > 1 ? ' (version ' . $d['version_no'] . ')' : ''),
                'sub' => self::docTypeName($d['doc_type']), 'text' => '', 'by' => self::userName((int)$d['uploaded_by']), 'doc' => $d['id']];
        }
        foreach (DB::all('SELECT * FROM brgy_change_orders WHERE project_id = :p', ['p' => $projectId]) as $c) {
            $rows[] = ['at' => $c['co_date'] . ' 11:00:00', 'kind' => 'change_order', 'icon' => 'arrow-left-right', 'title' => 'Change order ' . $c['co_no'] . ': ' . $c['title'],
                'sub' => (self::WF[$c['status']] ?? $c['status']) . ', ' . money($c['amount_change']), 'text' => '', 'by' => self::userName((int)$c['created_by']), 'doc' => $c['evidence_doc_id']];
        }
        usort($rows, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
        return array_slice($rows, 0, $limit);
    }

    // ================================================================== maker / checker / approver

    /** object type => [table, status column, amount column, label] */
    private const OBJECTS = [
        'bid'          => ['brgy_projects', 'bid_status', 'bid_amount', 'Bid'],
        'billing'      => ['brgy_billings', 'status', 'gross_amount', 'Billing'],
        'change_order' => ['brgy_change_orders', 'status', 'amount_change', 'Change order'],
        'form'         => ['brgy_forms', 'status', null, 'Form'],
    ];

    /** what each action needs and gives: action => [from statuses, to status] */
    private const STEPS = [
        'submit'  => [['draft', 'returned'], 'for_review'],
        'review'  => [['for_review'], 'reviewed'],
        'approve' => [['reviewed'], 'approved'],
        'return'  => [['for_review', 'reviewed'], 'returned'],
        'reopen'  => [['approved', 'final'], 'draft'],
        'release' => [['approved'], 'released'],
    ];

    /** Users who handed their review / approval authority to $userId for today */
    public static function delegatorsOf(int $userId, string $scope): array
    {
        return array_map('intval', DB::column(
            "SELECT from_user_id FROM brgy_delegations WHERE to_user_id = :u AND is_active = 1 AND valid_from <= :d1 AND valid_to >= :d2 AND scope IN ('both', :s)",
            ['u' => $userId, 'd1' => today(), 'd2' => today(), 's' => $scope]
        ));
    }

    /** The approval limit of a user (null = no limit): the "Max contract amount this role may approve" permission */
    public static function approvalLimit(int $userId): ?float
    {
        $v = Access::special('brgy_approval_limit', $userId);
        return $v === null ? null : (float)$v;
    }

    /** Who prepared (submitted) and who checked the record in its current round */
    public static function makers(string $type, int $id): array
    {
        $rows = DB::all('SELECT action, user_id, on_behalf_of FROM brgy_workflow WHERE object_type = :t AND object_id = :id ORDER BY id DESC', ['t' => $type, 'id' => $id]);
        $maker = null;
        $checker = null;
        foreach ($rows as $r) {
            if ($r['action'] === 'review' && $checker === null && $maker === null) {
                $checker = (int)$r['user_id'];
            }
            if ($r['action'] === 'submit') {
                $maker = (int)$r['user_id'];
                break;
            }
            if (in_array($r['action'], ['return', 'reopen'], true)) {
                break;
            }
        }
        return ['maker' => $maker, 'checker' => $checker];
    }

    /**
     * Can the current user take $action on the record? Returns [allowed, why not, on behalf of].
     * Used to show the right buttons and, with $enforce, to stop a request that should not go through.
     */
    public static function canStep(string $type, array $obj, string $action, ?array $project = null): array
    {
        [$table, $col, $amtCol] = self::OBJECTS[$type];
        $status = (string)$obj[$col];
        if (!isset(self::STEPS[$action]) || !in_array($status, self::STEPS[$action][0], true)) {
            return [false, 'Not possible while it is ' . (self::WF[$status] ?? $status) . '.', null];
        }
        $uid = (int)Auth::id();
        $who = self::makers($type, (int)$obj['id']);
        $amount = $amtCol ? abs((float)$obj[$amtCol]) : 0.0;
        $strict = self::on('brgy_sod_strict');
        $pageOf = ['bid' => 'brgy.projects', 'billing' => 'brgy.billing', 'change_order' => 'brgy.projects', 'form' => 'brgy.forms'];
        if ($action === 'submit') {
            return Access::can($pageOf[$type], 'edit') || Access::can($pageOf[$type], 'create')
                ? [true, '', null] : [false, 'Your role cannot prepare this record.', null];
        }
        if ($action === 'reopen') {
            return Access::can('brgy.approvals', 'approve') ? [true, '', null] : [false, 'Only an approver can reopen an approved record.', null];
        }
        if ($action === 'release') {
            // the authorized submitter hands the approved record to the entity (checked by the caller)
            return Access::can($pageOf[$type], 'edit') ? [true, '', null] : [false, 'Your role cannot submit this record.', null];
        }
        // review, approve, return: the checker's or approver's authority
        $scope = $action === 'approve' || ($action === 'return' && $status === 'reviewed') ? 'approve' : 'review';
        $right = $scope === 'approve' ? 'approve' : 'edit';
        $designated = $project ? (int)($project[$scope === 'approve' ? 'approver_id' : 'checker_id'] ?? 0) : 0;
        $delegators = self::delegatorsOf($uid, $scope);
        $onBehalf = null;
        if ($designated && $designated !== $uid) {
            if (in_array($designated, $delegators, true)) {
                $onBehalf = $designated;
            } elseif (!Access::isAdmin()) {
                return [false, 'Only ' . self::userName($designated) . ' (named ' . ($scope === 'approve' ? 'approver' : 'checker') . ' of this project) or their alternate can do this.', null];
            }
        }
        if (!$onBehalf && !Access::can('brgy.approvals', $right)) {
            foreach ($delegators as $d) {
                if (Access::can('brgy.approvals', $right, $d)) {
                    $onBehalf = $d;
                    break;
                }
            }
            if (!$onBehalf) {
                return [false, 'Your role cannot ' . ($scope === 'approve' ? 'approve' : 'review') . ' (Approval Queue: ' . ($right === 'edit' ? 'Edit' : 'Approve') . ' right).', null];
            }
        }
        // segregation of duties: nobody checks or approves what they prepared, and the approver is not the checker
        if ($strict && $who['maker'] && in_array($who['maker'], [$uid, $onBehalf], true)) {
            return [false, 'You prepared this record; another person has to ' . ($scope === 'approve' ? 'approve' : 'review') . ' it.', null];
        }
        if ($strict && $action === 'approve' && $who['checker'] && in_array($who['checker'], [$uid, $onBehalf], true)) {
            return [false, 'You reviewed this record; the approval has to come from another person.', null];
        }
        if ($action === 'approve' && $amount > 0) {
            $limit = self::approvalLimit($onBehalf ?: $uid);
            if ($limit !== null && $amount > $limit + 0.004) {
                return [false, 'The amount (' . money($amount) . ') is above ' . ($onBehalf ? 'the approval limit of ' . self::userName($onBehalf) : 'your approval limit') . ' (' . money($limit) . ').', null];
            }
        }
        return [true, '', $onBehalf];
    }

    /** Takes one step of the workflow (inside the caller's transaction or its own), logs it and returns the new status */
    public static function step(string $type, array $obj, string $action, string $remarks = '', ?array $project = null): string
    {
        [$table, $col, $amtCol, $label] = self::OBJECTS[$type];
        [$ok, $why, $onBehalf] = self::canStep($type, $obj, $action, $project);
        if (!$ok) {
            fail($why);
        }
        if (in_array($action, ['return', 'reopen'], true) && trim($remarks) === '') {
            fail('Enter the reason: it is kept with the record and shown to the preparer.');
        }
        $to = self::STEPS[$action][1];
        return DB::tx(function () use ($table, $col, $amtCol, $label, $type, $obj, $action, $remarks, $project, $to, $onBehalf) {
            $cur = DB::value("SELECT `$col` FROM `$table` WHERE id = :id FOR UPDATE", ['id' => $obj['id']]);
            if ((string)$cur !== (string)$obj[$col]) {
                fail('Someone else moved this record on (it is now ' . (self::WF[(string)$cur] ?? $cur) . '). Reload the page.');
            }
            $set = [$col => $to];
            if ($type === 'form') {
                if ($action === 'review') {
                    $set += ['reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s')];
                } elseif ($action === 'approve') {
                    $set += ['approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')];
                }
            }
            DB::update($table, $set, 'id = :id', ['id' => $obj['id']]);
            if (in_array($table, ['brgy_projects', 'brgy_billings', 'brgy_change_orders', 'brgy_forms'], true)) {
                self::bumpVersion($table, (int)$obj['id']);
            }
            $projectId = $project['id'] ?? ($obj['project_id'] ?? null);
            DB::insert('brgy_workflow', [
                'project_id' => $projectId, 'object_type' => $type, 'object_id' => $obj['id'], 'action' => $action,
                'from_status' => $obj[$col], 'to_status' => $to, 'user_id' => Auth::id(), 'on_behalf_of' => $onBehalf,
                'amount' => $amtCol ? $obj[$amtCol] : null, 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null,
            ]);
            $ref = $label . ' ' . ($obj['project_no'] ?? $obj['billing_no'] ?? $obj['co_no'] ?? $obj['form_no'] ?? ('#' . $obj['id']));
            self::log($action === 'approve' ? 'approve' : 'workflow_' . $action, $type, (int)$obj['id'], $ref, [
                'reason' => $remarks !== '' ? $remarks : null, 'amount' => $amtCol ? (float)$obj[$amtCol] : null,
                'changes' => [['field' => 'status', 'old' => $obj[$col], 'new' => $to]],
                'approved_by' => $action === 'approve' ? (Auth::user()['username'] ?? null) : null,
            ]);
            return $to;
        });
    }

    /** Records waiting for the current user: [type, record, project, action] */
    public static function queue(?int $userId = null): array
    {
        $userId ??= (int)Auth::id();
        $out = [];
        $cands = [];
        foreach (DB::all("SELECT p.*, e.official_name AS entity_name FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id WHERE p.bid_status IN ('for_review', 'reviewed')") as $p) {
            $cands[] = ['bid', $p, $p];
        }
        foreach (DB::all("SELECT b.*, p.project_no, p.title, p.checker_id, p.approver_id FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.status IN ('for_review', 'reviewed')") as $b) {
            $cands[] = ['billing', $b, self::project((int)$b['project_id'])];
        }
        foreach (DB::all("SELECT c.*, p.project_no FROM brgy_change_orders c JOIN brgy_projects p ON p.id = c.project_id WHERE c.status IN ('for_review', 'reviewed')") as $c) {
            $cands[] = ['change_order', $c, self::project((int)$c['project_id'])];
        }
        foreach (DB::all("SELECT f.*, p.project_no FROM brgy_forms f LEFT JOIN brgy_projects p ON p.id = f.project_id WHERE f.status IN ('for_review', 'reviewed')") as $f) {
            $cands[] = ['form', $f, $f['project_id'] ? self::project((int)$f['project_id']) : null];
        }
        foreach ($cands as [$type, $obj, $proj]) {
            $col = self::OBJECTS[$type][1];
            $action = $obj[$col] === 'for_review' ? 'review' : 'approve';
            [$ok] = self::canStep($type, $obj, $action, $proj);
            if ($ok) {
                $out[] = ['type' => $type, 'obj' => $obj, 'project' => $proj, 'action' => $action];
            }
        }
        return $out;
    }

    /** Records the current user prepared that came back to them */
    public static function returnedToMe(): array
    {
        $uid = (int)Auth::id();
        $out = [];
        foreach (DB::all("SELECT w.object_type, w.object_id, MAX(w.id) AS last_id FROM brgy_workflow w WHERE w.action = 'submit' AND w.user_id = :u GROUP BY w.object_type, w.object_id", ['u' => $uid]) as $r) {
            $type = $r['object_type'];
            if (!isset(self::OBJECTS[$type])) {
                continue;
            }
            [$table, $col] = self::OBJECTS[$type];
            $obj = DB::row("SELECT * FROM `$table` WHERE id = :id", ['id' => $r['object_id']]);
            if ($obj && $obj[$col] === 'returned') {
                $why = DB::value("SELECT remarks FROM brgy_workflow WHERE object_type = :t AND object_id = :id AND action = 'return' ORDER BY id DESC LIMIT 1", ['t' => $type, 'id' => $obj['id']]);
                $out[] = ['type' => $type, 'obj' => $obj, 'why' => (string)$why];
            }
        }
        return $out;
    }

    // ================================================================== compliance checklist

    /**
     * Builds (or adds to) a project's checklist from the requirement library: the active requirements whose
     * regime, procurement mode, category, amount band and dates fit the project, and the ones the procuring
     * entity asked for. Items already on the list are kept as they are; company papers are linked at once.
     */
    public static function buildChecklist(int $projectId): int
    {
        $p = self::mustProject($projectId);
        if (($p['process'] ?? 'general') === 'svp') {
            // small value procurement (SOP): the papers of the steps take the place of the compliance checklist
            return 0;
        }
        $on = $p['posting_date'] ?: today();
        $have = array_map('intval', DB::column('SELECT requirement_id FROM brgy_project_reqs WHERE project_id = :p AND requirement_id IS NOT NULL', ['p' => $projectId]));
        $added = 0;
        $reqs = DB::all("SELECT * FROM brgy_requirements WHERE is_active = 1 AND status = 'approved'
                          AND (entity_id IS NULL OR entity_id = :e)
                          AND (effective_from IS NULL OR effective_from <= :d1) AND (effective_to IS NULL OR effective_to >= :d2)
                          ORDER BY sort_order, id", ['e' => $p['entity_id'], 'd1' => $on, 'd2' => $on]);
        foreach ($reqs as $r) {
            if (in_array((int)$r['id'], $have, true)) {
                continue;
            }
            $csv = fn($v) => array_filter(array_map('trim', explode(',', (string)$v)));
            if ($csv($r['regimes']) && !in_array($p['regime'], $csv($r['regimes']), true)) {
                continue;
            }
            if ($csv($r['categories']) && !in_array($p['category'], $csv($r['categories']), true)) {
                continue;
            }
            if ($csv($r['mode_codes']) && !in_array((string)$p['mode_code'], $csv($r['mode_codes']), true)) {
                continue;
            }
            $abc = (float)$p['abc_amount'];
            if ($r['min_abc'] !== null && $abc < (float)$r['min_abc']) {
                continue;
            }
            if ($r['max_abc'] !== null && $abc > (float)$r['max_abc']) {
                continue;
            }
            DB::insert('brgy_project_reqs', [
                'project_id' => $projectId, 'requirement_id' => $r['id'], 'name' => $r['name'], 'doc_group' => $r['doc_group'], 'stage' => $r['stage'],
                'requirement_type' => $r['requirement_type'], 'needs_validity' => $r['needs_validity'], 'doc_type' => $r['doc_type'],
                'template_code' => $r['template_code'], 'legal_basis' => $r['legal_basis'],
                'due_date' => in_array($r['stage'], ['eligibility', 'bid'], true) && $p['bid_deadline'] ? substr((string)$p['bid_deadline'], 0, 10) : null,
                'sort_order' => (int)$r['sort_order'],
            ]);
            $added++;
        }
        self::linkCompanyDocs($projectId);
        self::linkProjectDocs($projectId);
        return $added;
    }

    /** Links each unanswered checklist item that a company paper satisfies to the newest approved copy of that paper */
    public static function linkCompanyDocs(int $projectId): int
    {
        $n = 0;
        foreach (DB::all("SELECT r.* FROM brgy_project_reqs r JOIN brgy_doc_types t ON t.code = r.doc_type AND t.is_company = 1
                           WHERE r.project_id = :p AND r.status IN ('missing', 'returned')", ['p' => $projectId]) as $r) {
            $doc = DB::row("SELECT * FROM brgy_documents WHERE scope = 'company' AND doc_type = :t AND is_current = 1 AND status IN ('approved', 'final')
                             ORDER BY COALESCE(valid_until, '9999-12-31') DESC, id DESC LIMIT 1", ['t' => $r['doc_type']]);
            if ($doc) {
                DB::update('brgy_project_reqs', ['document_id' => $doc['id'], 'status' => 'approved', 'reviewed_by' => $doc['reviewed_by'],
                    'reviewed_at' => $doc['reviewed_at'], 'remarks' => 'Company document ' . $doc['title'] . ' (reviewed in the vault)', 'updated_at' => date('Y-m-d H:i:s')],
                    'id = :id', ['id' => $r['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Links each unanswered checklist item to the newest paper of its type filed under the project (the notice of award,
     * the purchase order, an inspection report ...), so a paper filed anywhere in the project counts once.
     * A contract and a purchase order stand for each other.
     */
    public static function linkProjectDocs(int $projectId): int
    {
        $n = 0;
        foreach (DB::all("SELECT r.* FROM brgy_project_reqs r WHERE r.project_id = :p AND r.status = 'missing' AND r.document_id IS NULL AND r.doc_type IS NOT NULL", ['p' => $projectId]) as $r) {
            $types = in_array($r['doc_type'], ['contract', 'po'], true) ? ['contract', 'po'] : [$r['doc_type']];
            [$tin, $tp] = DB::in('t', $types);
            $doc = DB::row("SELECT * FROM brgy_documents WHERE project_id = :p AND doc_type IN $tin AND is_current = 1 AND status NOT IN ('void', 'superseded', 'returned')
                             ORDER BY id DESC LIMIT 1", $tp + ['p' => $projectId]);
            if ($doc) {
                $ok = in_array($doc['status'], ['approved', 'final'], true);
                DB::update('brgy_project_reqs', ['document_id' => $doc['id'], 'status' => $ok ? 'approved' : 'submitted', 'reviewed_by' => $ok ? $doc['reviewed_by'] : null,
                    'reviewed_at' => $ok ? $doc['reviewed_at'] : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $r['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Checklist items the records themselves answer: an invoice posted for a billing, a security recorded, a Form 2307
     * received with a collection. Returns [doc type => text] for the project.
     */
    private static function answeredByRecords(int $projectId): array
    {
        $out = [];
        $inv = DB::row("SELECT b.billing_no, t.txn_no FROM brgy_billings b JOIN transactions t ON t.id = b.invoice_txn_id
                         WHERE b.project_id = :p AND b.status IN ('invoiced', 'submitted', 'acknowledged') AND t.is_voided = 0 ORDER BY b.id LIMIT 1", ['p' => $projectId]);
        if ($inv) {
            $out['invoice'] = 'Invoice ' . $inv['txn_no'] . ' (billing ' . $inv['billing_no'] . ') in the books';
        }
        foreach (['bid' => 'bid_security', 'performance' => 'perf_security'] as $type => $docType) {
            $sec = DB::row("SELECT security_no, form FROM brgy_securities WHERE project_id = :p AND security_type = :t AND status IN ('active', 'released') ORDER BY id DESC LIMIT 1", ['p' => $projectId, 't' => $type]);
            if ($sec) {
                $out[$docType] = self::label(self::SECURITY_FORMS, $sec['form']) . ' ' . $sec['security_no'] . ' recorded';
            }
        }
        $c = DB::value("SELECT collection_no FROM brgy_collections WHERE project_id = :p AND cert_2307 = 1 AND status = 'posted' ORDER BY id LIMIT 1", ['p' => $projectId]);
        if ($c) {
            $out['form_2307'] = 'Received with collection ' . $c;
        }
        return $out;
    }

    /**
     * The checklist with the state of each item:
     *   ok (approved and valid), waived, pending (filed, waiting for review), returned, missing, expired (valid until a date before the one it is needed on)
     */
    public static function checklist(int $projectId, ?array $project = null): array
    {
        $p = $project ?? self::mustProject($projectId);
        $needOnOf = self::neededOn($p);
        $rows = DB::all(
            'SELECT r.*, d.title AS doc_title, d.valid_until, d.scope AS doc_scope, d.status AS doc_status, d.version_no, d.original_name, d.is_current
               FROM brgy_project_reqs r LEFT JOIN brgy_documents d ON d.id = r.document_id
              WHERE r.project_id = :p ORDER BY FIELD(r.stage, \'eligibility\', \'bid\', \'post_qual\', \'contract\', \'delivery\', \'billing\'), r.sort_order, r.id',
            ['p' => $projectId]
        );
        $records = self::answeredByRecords($projectId);
        $due = self::stagesDue($p);
        foreach ($rows as &$r) {
            $needOn = $needOnOf[$r['stage']] ?? today();
            $r['by_record'] = null;
            $r['due'] = in_array($r['stage'], $due, true);
            if ($r['status'] === 'waived') {
                $state = 'waived';
            } elseif (!$r['document_id'] && $r['doc_type'] && isset($records[$r['doc_type']])) {
                $state = 'ok';
                $r['by_record'] = $records[$r['doc_type']];
            } elseif (!$r['document_id']) {
                $state = $r['status'] === 'returned' ? 'returned' : 'missing';
            } elseif ((int)$r['needs_validity'] === 1 && $r['valid_until'] && $r['valid_until'] < $needOn) {
                $state = 'expired';
            } elseif ((int)$r['needs_validity'] === 1 && !$r['valid_until']) {
                $state = $r['status'] === 'approved' ? 'pending' : ($r['status'] === 'returned' ? 'returned' : 'pending');
                $r['why'] = 'The validity date of the document is not entered.';
            } elseif ($r['status'] === 'approved') {
                $state = 'ok';
            } elseif ($r['status'] === 'returned') {
                $state = 'returned';
            } else {
                $state = 'pending';
            }
            $r['state'] = $state;
            $r['need_on'] = $needOn;
        }
        unset($r);
        return $rows;
    }

    /**
     * The date each stage's papers have to be valid on: eligibility and bid papers on the bid deadline while the bid is
     * prepared, then on the day it was submitted; post-qualification papers until the award; contract papers until the
     * signing; delivery and billing papers while the contract runs (the closeout date once it is closed). Never later than today.
     */
    public static function neededOn(array $p): array
    {
        $d = fn($v) => $v ? substr((string)$v, 0, 10) : null;
        $today = today();
        $early = in_array($p['phase'], ['intake', 'eligibility', 'costing', 'approval'], true) && $p['status'] === 'open';
        $bid = $early ? ($d($p['bid_deadline']) ?? $today) : ($d($p['bid_submitted_at']) ?? $d($p['bid_deadline']) ?? $today);
        $awarded = in_array($p['status'], ['awarded', 'closed'], true);
        $out = [
            'eligibility' => $bid,
            'bid' => $bid,
            'post_qual' => $awarded ? ($d($p['noa_date']) ?? $bid) : ($early ? $bid : $today),
            'contract' => $awarded && $p['contract_signed_date'] ? $d($p['contract_signed_date']) : ($awarded ? ($d($p['noa_received_date']) ?? $today) : $today),
            'delivery' => $p['status'] === 'closed' && $p['closeout_date'] ? $d($p['closeout_date']) : $today,
            'billing' => $p['status'] === 'closed' && $p['closeout_date'] ? $d($p['closeout_date']) : $today,
        ];
        foreach ($out as $k => $v) {
            if ($v > $today) {
                $out[$k] = $early && in_array($k, ['eligibility', 'bid', 'post_qual'], true) ? $v : $today;
            }
        }
        return $out;
    }

    /** Checklist items that stop the bid from going for approval (mandatory eligibility and bid papers not in order) */
    public static function checklistBlockers(array $p): array
    {
        $out = [];
        foreach (self::checklist((int)$p['id'], $p) as $r) {
            if ($r['requirement_type'] !== 'mandatory' || !in_array($r['stage'], ['eligibility', 'bid'], true)) {
                continue;
            }
            if (in_array($r['state'], ['ok', 'waived'], true)) {
                continue;
            }
            $out[] = $r['name'] . ': ' . ['missing' => 'no document', 'expired' => 'expired on ' . fdate($r['valid_until']) . ', needed valid on ' . fdate($r['need_on']),
                'pending' => $r['why'] ?? 'filed but not reviewed yet', 'returned' => 'returned for correction'][$r['state']];
        }
        return $out;
    }

    /** The stages whose papers are due by now: the bid's before the award, the contract's at the award, delivery and billing papers as they come */
    public static function stagesDue(array $p): array
    {
        $i = self::phaseIndex($p['phase']);
        $awarded = in_array($p['status'], ['awarded', 'closed'], true);
        $due = ['eligibility', 'bid'];
        if ($awarded || $i >= self::phaseIndex('evaluation')) {
            $due[] = 'post_qual';
        }
        if ($awarded) {
            $due[] = 'contract';
            if ($p['status'] === 'closed' || $i >= self::phaseIndex('delivery')) {
                $due[] = 'delivery';
            }
            if ($p['status'] === 'closed' || $i >= self::phaseIndex('billing')) {
                $due[] = 'billing';
            }
        }
        return $due;
    }

    /** Counts of the checklist by state; items of stages not due yet count as "later", not as missing */
    public static function checklistSummary(int $projectId): array
    {
        $s = ['total' => 0, 'ok' => 0, 'missing' => 0, 'expired' => 0, 'pending' => 0, 'returned' => 0, 'waived' => 0, 'blocking' => 0, 'later' => 0];
        $p = self::mustProject($projectId);
        foreach (self::checklist($projectId, $p) as $r) {
            $s['total']++;
            if (!$r['due'] && !in_array($r['state'], ['ok', 'waived'], true)) {
                $s['later']++;
                continue;
            }
            $s[$r['state']]++;
            if ($r['requirement_type'] === 'mandatory' && !in_array($r['state'], ['ok', 'waived'], true)) {
                $s['blocking']++;
            }
        }
        return $s;
    }

    // ================================================================== price schedule

    /** Totals of the lines: bid amount (as quoted), contract amount (as contracted), estimated cost */
    public static function lineTotals(int $projectId): array
    {
        $r = DB::row('SELECT COALESCE(SUM(ROUND(bid_qty * unit_price, 2)), 0) AS bid, COALESCE(SUM(amount), 0) AS contract,
                             COALESCE(SUM(ROUND(bid_qty * unit_cost, 2)), 0) AS cost, COUNT(*) AS n
                        FROM brgy_project_lines WHERE project_id = :p', ['p' => $projectId]);
        return ['bid' => r2($r['bid']), 'contract' => r2($r['contract']), 'cost' => r2($r['cost']), 'lines' => (int)$r['n']];
    }

    /** Recounts delivered, accepted, rejected and billed quantities of every line from the records behind them */
    public static function refreshLines(int $projectId): void
    {
        DB::run(
            "UPDATE brgy_project_lines l SET
                qty_delivered = (SELECT COALESCE(SUM(dl.qty), 0) FROM brgy_delivery_lines dl JOIN brgy_deliveries d ON d.id = dl.delivery_id
                                  WHERE dl.project_line_id = l.id AND d.status <> 'void'),
                qty_accepted  = (SELECT COALESCE(SUM(dl.qty_accepted), 0) FROM brgy_delivery_lines dl JOIN brgy_deliveries d ON d.id = dl.delivery_id
                                  WHERE dl.project_line_id = l.id AND d.status <> 'void'),
                qty_rejected  = (SELECT COALESCE(SUM(dl.qty_rejected), 0) FROM brgy_delivery_lines dl JOIN brgy_deliveries d ON d.id = dl.delivery_id
                                  WHERE dl.project_line_id = l.id AND d.status <> 'void'),
                qty_billed    = (SELECT COALESCE(SUM(bl.qty), 0) FROM brgy_billing_lines bl JOIN brgy_billings b ON b.id = bl.billing_id
                                  WHERE bl.project_line_id = l.id AND b.status <> 'void')
              WHERE l.project_id = :p",
            ['p' => $projectId]
        );
    }

    /** Keeps a read-only copy of the costing each time the bid goes for review */
    public static function snapshotCosting(array $p, string $note): int
    {
        $lines = self::lines((int)$p['id']);
        $t = self::lineTotals((int)$p['id']);
        $ver = (int)DB::value('SELECT COALESCE(MAX(version_no), 0) + 1 FROM brgy_cost_versions WHERE project_id = :p', ['p' => $p['id']]);
        DB::insert('brgy_cost_versions', [
            'project_id' => $p['id'], 'version_no' => $ver, 'est_cost' => $t['cost'], 'bid_amount' => $t['bid'],
            'margin_pct' => $t['bid'] > 0 ? round(($t['bid'] - $t['cost']) / $t['bid'] * 100, 4) : 0,
            'lines_json' => json_encode(array_map(fn($l) => array_intersect_key($l, array_flip(['line_no', 'line_kind', 'item_code', 'description', 'unit', 'bid_qty', 'unit_cost', 'markup_pct', 'unit_price', 'tax_code'])), $lines), JSON_UNESCAPED_UNICODE),
            'note' => mb_substr($note, 0, 255), 'created_by' => Auth::id(),
        ]);
        DB::update('brgy_projects', ['bid_version' => $ver], 'id = :id', ['id' => $p['id']]);
        return $ver;
    }

    // ================================================================== phases

    /** Moves the project to a phase (never backwards unless $force), logged in the workflow and audit trail */
    public static function setPhase(array $p, string $phase, string $remarks = '', bool $force = false): void
    {
        if (!isset(self::PHASES[$phase]) || $p['phase'] === $phase) {
            return;
        }
        if (!$force && self::phaseIndex($phase) < self::phaseIndex($p['phase'])) {
            return;
        }
        DB::update('brgy_projects', ['phase' => $phase, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $p['id']]);
        DB::insert('brgy_workflow', ['project_id' => $p['id'], 'object_type' => 'phase', 'object_id' => $p['id'], 'action' => 'phase',
            'from_status' => $p['phase'], 'to_status' => $phase, 'user_id' => Auth::id(), 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null]);
        self::log('phase', 'project', (int)$p['id'], $p['project_no'], ['changes' => [['field' => 'phase', 'old' => self::phaseLabel($p['phase']), 'new' => self::phaseLabel($phase)]],
            'reason' => $remarks !== '' ? $remarks : null]);
    }

    /** Moves forward to $phase when the project is behind it (a first delivery, billing or collection) */
    public static function advance(int $projectId, string $phase, string $why): void
    {
        $p = self::project($projectId);
        if ($p && $p['status'] === 'awarded' && self::phaseIndex($p['phase']) < self::phaseIndex($phase)) {
            self::setPhase($p, $phase, $why);
        }
    }

    /** Reasons the bid cannot be sent for review now (empty = it can) */
    public static function bidGate(array $p): array
    {
        $out = [];
        if ($p['status'] !== 'open') {
            $out[] = 'The project is ' . self::label(self::STATUSES, $p['status']) . '.';
        }
        if ($p['go_decision'] !== 'go') {
            $out[] = 'Record the go / no-go decision first (go).';
        }
        $t = self::lineTotals((int)$p['id']);
        if ($t['lines'] === 0) {
            $out[] = 'The price schedule has no lines.';
        }
        if ($t['bid'] <= 0) {
            $out[] = 'The bid amount is zero.';
        }
        if (self::on('brgy_block_over_abc') && (float)$p['abc_amount'] > 0 && $t['bid'] > (float)$p['abc_amount'] + 0.004) {
            $out[] = 'The bid (' . money($t['bid']) . ') is above the approved budget for the contract (' . money($p['abc_amount']) . '); a bid above the ABC is not accepted.';
        }
        if (self::on('brgy_gate_deadline') && $p['bid_deadline'] && strtotime((string)$p['bid_deadline']) < time()) {
            $out[] = 'The bid deadline (' . fdatetime($p['bid_deadline']) . ') has passed.';
        }
        if (self::on('brgy_gate_documents')) {
            foreach (self::checklistBlockers($p) as $b) {
                $out[] = $b;
            }
        }
        foreach (self::lines((int)$p['id']) as $l) {
            if ((float)$l['bid_qty'] <= 0 || (float)$l['unit_price'] <= 0) {
                $out[] = 'Line ' . $l['line_no'] . ' (' . $l['description'] . ') has no quantity or price.';
            }
        }
        return $out;
    }

    // ================================================================== money summary

    /**
     * What the contract stands at, from the module's records and from the ledger:
     * contract (with approved change orders), delivered, accepted and billed value, collected cash, taxes withheld,
     * deductions, open receivable and retention held (ledger, on the job), costs and revenue (ledger), margin.
     */
    public static function money(array $p): array
    {
        $id = (int)$p['id'];
        $m = ['contract' => self::contractTotal($p), 'bid' => (float)$p['bid_amount'], 'abc' => (float)$p['abc_amount']];
        $v = DB::row('SELECT COALESCE(SUM(ROUND(qty_delivered * unit_price, 2)), 0) AS delivered, COALESCE(SUM(ROUND(qty_accepted * unit_price, 2)), 0) AS accepted,
                             COALESCE(SUM(ROUND(qty_rejected * unit_price, 2)), 0) AS rejected,
                             COALESCE(SUM(ROUND(qty * unit_cost, 2)), 0) AS est_cost
                        FROM brgy_project_lines WHERE project_id = :p', ['p' => $id]);
        $m['delivered'] = r2($v['delivered']);
        $m['accepted'] = r2($v['accepted']);
        $m['rejected'] = r2($v['rejected']);
        $m['est_cost'] = r2($v['est_cost']);
        $m['billed'] = r2(DB::value("SELECT COALESCE(SUM(gross_amount), 0) FROM brgy_billings WHERE project_id = :p AND status IN ('invoiced', 'submitted', 'acknowledged')", ['p' => $id]));
        $m['billing_draft'] = r2(DB::value("SELECT COALESCE(SUM(gross_amount), 0) FROM brgy_billings WHERE project_id = :p AND status IN ('draft', 'for_review', 'reviewed', 'approved', 'returned')", ['p' => $id]));
        $c = DB::row("SELECT COALESCE(SUM(gross_applied), 0) AS settled, COALESCE(SUM(cash_received), 0) AS cash, COALESCE(SUM(ewt_amount + vatwh_amount), 0) AS taxes,
                             COALESCE(SUM(retention_amount), 0) AS retention, COALESCE(SUM(ld_amount + other_deduction + bank_charges), 0) AS other
                        FROM brgy_collections WHERE project_id = :p AND status = 'posted' AND kind = 'billing'", ['p' => $id]);
        $m['settled'] = r2($c['settled']);
        $m['cash'] = r2($c['cash']);
        $m['taxes'] = r2($c['taxes']);
        $m['retention_deducted'] = r2($c['retention']);
        $m['other_deductions'] = r2($c['other']);
        $m['retention_released'] = r2(DB::value("SELECT COALESCE(SUM(cash_received), 0) FROM brgy_collections WHERE project_id = :p AND status = 'posted' AND kind = 'retention'", ['p' => $id]));
        $job = (int)($p['job_customer_id'] ?? 0);
        $m['ar_open'] = 0.0;
        $m['retention_held'] = 0.0;
        $m['revenue'] = 0.0;
        $m['costs'] = 0.0;
        if ($job) {
            [$ain, $ap] = DB::in('ar', Accts::arIds() ?: [0]);
            $m['ar_open'] = r2(DB::value("SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE customer_id = :c AND account_id IN $ain", ['c' => $job] + $ap));
            $ret = self::acct('retention_receivable');
            if ($ret) {
                $m['retention_held'] = r2(DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE customer_id = :c AND account_id = :a', ['c' => $job, 'a' => $ret]));
            }
        }
        [$rev, $cost] = self::ledgerResult($p);
        $m['revenue'] = $rev;
        $m['costs'] = $cost;
        $m['margin'] = r2($rev - $cost);
        $m['margin_pct'] = $rev > 0 ? round(($rev - $cost) / $rev * 100, 2) : null;
        $m['unbilled_accepted'] = r2(max(0, $m['accepted'] - $m['billed'] - $m['billing_draft']));
        $m['to_deliver'] = r2(max(0, $m['contract'] - $m['accepted']));
        return $m;
    }

    /** v36: the billing packs of a project that are not invoiced yet (they hold their quantities until invoiced or voided) */
    public static function pendingBillings(int $projectId, ?int $exceptId = null): array
    {
        return DB::all("SELECT id, billing_no, status, gross_amount FROM brgy_billings WHERE project_id = :p AND status IN ('draft', 'for_review', 'reviewed', 'approved', 'returned')"
            . ($exceptId ? ' AND id <> :x' : '') . ' ORDER BY id', ['p' => $projectId] + ($exceptId ? ['x' => $exceptId] : []));
    }

    /** v36: what a billing pack not invoiced yet waits for */
    public static function billingNext(string $status): string
    {
        return [
            'draft' => 'send it for review',
            'returned' => 'correct it and send it for review again',
            'for_review' => 'it waits for the checker\'s review',
            'reviewed' => 'it waits for the approver',
            'approved' => 'issue its invoice',
        ][$status] ?? '';
    }

    /** Revenue and costs of a project from the ledger: lines on the job, or with the project's class and no customer */
    public static function ledgerResult(array $p): array
    {
        $job = (int)($p['job_customer_id'] ?? 0);
        $class = (int)($p['class_id'] ?? 0);
        if (!$job && !$class) {
            return [0.0, 0.0];
        }
        $w = [];
        $params = [];
        if ($job) {
            $w[] = 'j.customer_id = :job';
            $params['job'] = $job;
        }
        if ($class) {
            $w[] = '(j.customer_id IS NULL AND j.class_id = :cls)';
            $params['cls'] = $class;
        }
        $r = DB::row("SELECT COALESCE(SUM(CASE WHEN a.category = 'Income' THEN j.credit - j.debit ELSE 0 END), 0) AS rev,
                             COALESCE(SUM(CASE WHEN a.category = 'Expense' THEN j.debit - j.credit ELSE 0 END), 0) AS cost
                        FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE " . implode(' OR ', $w), $params);
        return [r2($r['rev']), r2($r['cost'])];
    }

    /** A module default account of the Barangay Contracts group, or null when it is not set */
    public static function acct(string $key): ?int
    {
        $id = DB::value('SELECT account_id FROM default_accounts WHERE setting_key = :k', ['k' => $key]);
        return $id ? (int)$id : null;
    }

    // ================================================================== alerts

    /**
     * In-app alerts: papers expiring or expired, bids due, approvals waiting, returned records, late or unaccepted
     * deliveries, billings not submitted or not acknowledged, overdue receivables, retention due for release,
     * securities expiring, open clarification requests, overdue tasks and figures that do not agree with the ledger.
     * Each: [level danger|warning|info, icon, text, url, date]
     */
    public static function alerts(): array
    {
        if (!self::ready()) {
            return [];
        }
        $a = [];
        $days = max(1, (int)self::setting('brgy_alert_days'));
        $limit = date('Y-m-d', strtotime("+$days days"));
        $add = function (string $level, string $icon, string $text, string $url, ?string $date = null) use (&$a) {
            $a[] = ['level' => $level, 'icon' => $icon, 'text' => $text, 'url' => $url, 'date' => $date];
        };
        // company papers
        foreach (DB::all("SELECT * FROM brgy_documents WHERE scope = 'company' AND is_current = 1 AND status <> 'void' AND valid_until IS NOT NULL AND valid_until <= :d ORDER BY valid_until", ['d' => $limit]) as $d) {
            $exp = $d['valid_until'] < today();
            $add($exp ? 'danger' : 'warning', 'file-earmark-x', ($exp ? 'Expired: ' : 'Expires ' . fdate($d['valid_until']) . ': ') . $d['title'] . ($exp ? ' (expired ' . fdate($d['valid_until']) . ')' : ''),
                url('brgy.vault', ['a' => 'view', 'id' => $d['id']]), $d['valid_until']);
        }
        // bids due
        $bidDays = max(1, (int)self::setting('brgy_bid_alert_days'));
        foreach (DB::all("SELECT id, project_no, title, bid_deadline, bid_status FROM brgy_projects WHERE status = 'open' AND phase IN ('intake', 'eligibility', 'costing', 'approval')
                           AND bid_deadline IS NOT NULL AND bid_deadline <= :d ORDER BY bid_deadline", ['d' => date('Y-m-d H:i:s', strtotime("+$bidDays days"))]) as $p) {
            $late = strtotime((string)$p['bid_deadline']) < time();
            $add($late ? 'danger' : 'warning', 'alarm', ($late ? 'Bid deadline passed, not submitted: ' : 'Bid due ' . fdatetime($p['bid_deadline']) . ': ') . $p['project_no'] . ' ' . $p['title'],
                url('brgy.projects', ['a' => 'view', 'id' => $p['id']]), substr((string)$p['bid_deadline'], 0, 10));
            $bad = array_filter(self::checklist((int)$p['id']), fn($r) => $r['due'] && $r['requirement_type'] === 'mandatory' && !in_array($r['state'], ['ok', 'waived'], true));
            if ($bad) {
                $by = array_count_values(array_column($bad, 'state'));
                $parts = [];
                foreach (['missing' => 'missing', 'expired' => 'expired', 'pending' => 'for review', 'returned' => 'returned'] as $k => $w) {
                    if (!empty($by[$k])) {
                        $parts[] = $by[$k] . ' ' . $w;
                    }
                }
                $add('warning', 'list-check', $p['project_no'] . ': ' . count($bad) . ' mandatory document(s) not in order (' . implode(', ', $parts) . ')',
                    url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'compliance']), substr((string)$p['bid_deadline'], 0, 10));
            }
        }
        // approvals waiting for me, records returned to me
        foreach (self::queue() as $q) {
            $ref = $q['obj']['project_no'] ?? '';
            $what = ['bid' => 'Bid ' . $ref, 'billing' => 'Billing ' . ($q['obj']['billing_no'] ?? ''), 'change_order' => 'Change order ' . ($q['obj']['co_no'] ?? ''), 'form' => 'Form ' . ($q['obj']['form_no'] ?? '')][$q['type']];
            $add('info', 'hourglass-split', $what . ' waits for your ' . ($q['action'] === 'review' ? 'review' : 'approval'), url('brgy.approvals'));
        }
        foreach (self::returnedToMe() as $r) {
            $add('warning', 'arrow-return-left', ucfirst(str_replace('_', ' ', $r['type'])) . ' ' . ($r['obj']['project_no'] ?? $r['obj']['billing_no'] ?? $r['obj']['co_no'] ?? $r['obj']['form_no'] ?? '') . ' was returned to you' . ($r['why'] ? ': ' . $r['why'] : ''), url('brgy.approvals', ['tab' => 'returned']));
        }
        // deliveries late, waiting for acceptance
        foreach (DB::all("SELECT p.id, p.project_no, p.title, p.due_date FROM brgy_projects p WHERE p.status = 'awarded' AND p.due_date IS NOT NULL AND p.due_date < :d
                           AND EXISTS (SELECT 1 FROM brgy_project_lines l WHERE l.project_id = p.id AND l.qty_accepted < l.qty - 0.00001)", ['d' => today()]) as $p) {
            $add('danger', 'truck', 'Delivery late since ' . fdate($p['due_date']) . ': ' . $p['project_no'] . ' ' . $p['title'], url('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'delivery']), $p['due_date']);
        }
        $acc = max(1, (int)self::setting('brgy_accept_alert_days'));
        foreach (DB::all("SELECT d.id, d.dr_no, d.delivery_date, p.project_no FROM brgy_deliveries d JOIN brgy_projects p ON p.id = d.project_id
                           WHERE d.status = 'delivered' AND d.delivery_date <= :d", ['d' => date('Y-m-d', strtotime("-$acc days"))]) as $d) {
            $add('warning', 'clipboard-check', 'Waiting for acceptance since ' . fdate($d['delivery_date']) . ': ' . $d['dr_no'] . ' (' . $d['project_no'] . ')', url('brgy.deliveries', ['a' => 'view', 'id' => $d['id']]), $d['delivery_date']);
        }
        // rejected goods not made good yet: what was delivered less what was rejected still falls short of the contract
        foreach (DB::all("SELECT l.project_id, l.description, l.qty, l.qty_delivered, l.qty_rejected, p.project_no FROM brgy_project_lines l JOIN brgy_projects p ON p.id = l.project_id
                           WHERE p.status = 'awarded' AND l.qty_rejected > 0 AND l.qty_delivered - l.qty_rejected < l.qty - 0.00001") as $l) {
            $add('warning', 'x-octagon', 'Rejected, not yet replaced: ' . qty(min((float)$l['qty_rejected'], (float)$l['qty'] - ((float)$l['qty_delivered'] - (float)$l['qty_rejected']))) . ' of ' . $l['description'] . ' (' . $l['project_no'] . ')',
                url('brgy.projects', ['a' => 'view', 'id' => $l['project_id'], 'tab' => 'delivery']));
        }
        // billings
        $ack = max(1, (int)self::setting('brgy_ack_alert_days'));
        foreach (DB::all("SELECT b.id, b.billing_no, b.billing_date, b.status, b.submitted_at, p.project_no FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id
                           WHERE (b.status = 'invoiced' AND b.billing_date <= :d1) OR (b.status = 'submitted' AND b.submitted_at <= :d2)",
                          ['d1' => date('Y-m-d', strtotime('-3 days')), 'd2' => date('Y-m-d H:i:s', strtotime("-$ack days"))]) as $b) {
            $add('warning', 'send-exclamation', $b['status'] === 'invoiced' ? 'Billing ' . $b['billing_no'] . ' (' . $b['project_no'] . ') is invoiced but not yet submitted to the entity'
                : 'Billing ' . $b['billing_no'] . ' (' . $b['project_no'] . ') submitted ' . fdate($b['submitted_at']) . ', no acknowledgment yet', url('brgy.billing', ['a' => 'view', 'id' => $b['id']]));
        }
        // overdue receivables of the contracts
        foreach (DB::all("SELECT b.id AS billing_id, b.billing_no, p.project_no, t.id, t.due_date, t.total, t.is_voided, t.is_pending, t.txn_type
                            FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id JOIN transactions t ON t.id = b.invoice_txn_id
                           WHERE b.status IN ('invoiced', 'submitted', 'acknowledged') AND t.is_voided = 0 AND t.status IN ('open', 'partial') AND t.due_date < :d", ['d' => today()]) as $b) {
            $open = Balances::open($b);
            if ($open > 0.004) {
                $add('danger', 'cash-stack', 'Overdue since ' . fdate($b['due_date']) . ': ' . money($open) . ' on billing ' . $b['billing_no'] . ' (' . $b['project_no'] . ')', url('brgy.billing', ['a' => 'view', 'id' => $b['billing_id']]), $b['due_date']);
            }
        }
        // retention due for release, securities expiring
        foreach (DB::all("SELECT * FROM brgy_projects WHERE status IN ('awarded', 'closed') AND warranty_until IS NOT NULL AND warranty_until <= :d", ['d' => $limit]) as $p) {
            $m = self::money($p);
            if ($m['retention_held'] > 0.004) {
                $add('info', 'piggy-bank', 'Retention of ' . money($m['retention_held']) . ' can be claimed ' . ($p['warranty_until'] < today() ? 'since ' : 'from ') . fdate($p['warranty_until']) . ': ' . $p['project_no'],
                    url('brgy.collections', ['a' => 'new', 'kind' => 'retention', 'project_id' => $p['id']]), $p['warranty_until']);
            }
        }
        foreach (DB::all("SELECT s.*, p.project_no FROM brgy_securities s JOIN brgy_projects p ON p.id = s.project_id WHERE s.status = 'active' AND s.valid_until IS NOT NULL AND s.valid_until <= :d", ['d' => $limit]) as $s) {
            $add($s['valid_until'] < today() ? 'danger' : 'warning', 'shield-exclamation', self::label(self::SECURITY_TYPES, $s['security_type']) . ' ' . $s['security_no'] . ' (' . $s['project_no'] . ') '
                . ($s['valid_until'] < today() ? 'lapsed ' : 'valid until ') . fdate($s['valid_until']), url('brgy.projects', ['a' => 'view', 'id' => $s['project_id'], 'tab' => 'award']), $s['valid_until']);
        }
        foreach (DB::all("SELECT e.*, p.project_no FROM brgy_events e JOIN brgy_projects p ON p.id = e.project_id WHERE e.status = 'open' AND e.due_at IS NOT NULL AND e.due_at <= :d", ['d' => date('Y-m-d H:i:s', strtotime('+3 days'))]) as $e) {
            $add(strtotime((string)$e['due_at']) < time() ? 'danger' : 'warning', 'question-circle', 'Reply due ' . fdatetime($e['due_at']) . ': ' . $e['title'] . ' (' . $e['project_no'] . ')',
                url('brgy.projects', ['a' => 'view', 'id' => $e['project_id'], 'tab' => 'submissions']), substr((string)$e['due_at'], 0, 10));
        }
        foreach (DB::all("SELECT t.*, p.project_no FROM brgy_tasks t JOIN brgy_projects p ON p.id = t.project_id WHERE t.status IN ('open', 'in_progress') AND t.due_date < :d AND (t.assigned_to = :u OR t.assigned_to IS NULL)",
                          ['d' => today(), 'u' => Auth::id()]) as $t) {
            $add('warning', 'list-task', 'Overdue ' . strtolower(self::label(self::TASK_TYPES, $t['task_type'])) . ': ' . $t['title'] . ' (' . $t['project_no'] . ', due ' . fdate($t['due_date']) . ')',
                url('brgy.projects', ['a' => 'view', 'id' => $t['project_id'], 'tab' => 'tasks']), $t['due_date']);
        }
        // figures that do not agree with the ledger
        foreach (BrgyPost::reconcile() as $r) {
            if ($r['issues']) {
                $add('danger', 'exclamation-diamond', $r['project_no'] . ': ' . implode('; ', $r['issues']), url('rpt.brgy_reconciliation'));
            }
        }
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($a, fn($x, $y) => [$order[$x['level']], (string)$x['date']] <=> [$order[$y['level']], (string)$y['date']]);
        return $a;
    }

    /** How many records wait for the current user's review or approval (the menu shows it) */
    public static function queueCount(): int
    {
        try {
            return self::ready() ? count(self::queue()) : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
