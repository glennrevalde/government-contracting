<?php
declare(strict_types=1);

/**
 * Barangay Contracts: every change to a contract record goes through here, from the pages, the sample data and
 * the tests alike, so the rules hold whichever way a record is made:
 *   rights on the page, segregation of duties, the gates of each phase, evidence of the procuring entity's own acts,
 *   no delivery beyond the contract, no billing beyond what was accepted, no total beyond the contract with its
 *   approved change orders, one invoice per billing, taxes withheld checked against the table, conflicting edits refused.
 */
final class BrgyOps
{
    private static function need(string $page, string $action, string $what): void
    {
        if (!Access::can($page, $action)) {
            fail('Your role cannot ' . $what . '.');
        }
    }

    private static function s($v, int $len): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $len);
    }

    private static function date($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return valid_date($v) ? $v : null;
    }

    /** "2026-10-20T14:00" or "2026-10-20 14:00:00" -> "2026-10-20 14:00:00" (null when blank or invalid) */
    public static function dt($v): ?string
    {
        $v = str_replace('T', ' ', trim((string)($v ?? '')));
        if ($v === '') {
            return null;
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    private static function num($v): float
    {
        return to_num($v ?? 0);
    }

    private static function url($v): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $v)) {
            fail('A source link has to start with http:// or https://.');
        }
        return mb_substr($v, 0, 255);
    }

    /** A file for a document, or null when no file was chosen */
    private static function fileGiven(?array $f): bool
    {
        return $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /** A document of the project of one of the given types (evidence picked from the vault) */
    private static function projectDoc(int $projectId, ?int $docId, array $types = []): ?array
    {
        if (!$docId) {
            return null;
        }
        $d = DB::row("SELECT * FROM brgy_documents WHERE id = :id AND status <> 'void' AND (project_id = :p OR scope = 'company')", ['id' => $docId, 'p' => $projectId]);
        if (!$d) {
            fail('The document chosen as evidence is not filed under this project.');
        }
        if ($types && !in_array($d['doc_type'], $types, true)) {
            fail('The document chosen is a ' . Brgy::docTypeName($d['doc_type']) . '; expected ' . implode(' or ', array_map([Brgy::class, 'docTypeName'], $types)) . '.');
        }
        return $d;
    }

    // ================================================================== directory

    public static function saveEntity(array $d, ?int $id = null): int
    {
        self::need('brgy.entities', $id ? 'edit' : 'create', ($id ? 'change' : 'add') . ' procuring entities');
        $name = self::s($d['official_name'] ?? '', 160);
        if (!$name) {
            fail('Enter the official name of the procuring entity (e.g. Barangay San Isidro).');
        }
        $methods = $d['submission_methods'] ?? [];
        $methods = is_array($methods) ? array_values(array_intersect(array_keys(Brgy::SUB_METHODS), $methods)) : array_filter(array_map('trim', explode(',', (string)$methods)));
        $row = [
            'code' => self::s($d['code'] ?? '', 30), 'official_name' => $name,
            'entity_type' => isset(Brgy::ENTITY_TYPES[$d['entity_type'] ?? '']) ? $d['entity_type'] : 'barangay',
            'region' => self::s($d['region'] ?? '', 80), 'province' => self::s($d['province'] ?? '', 80), 'city_municipality' => self::s($d['city_municipality'] ?? '', 80),
            'barangay' => self::s($d['barangay'] ?? '', 80), 'address' => self::s($d['address'] ?? '', 255), 'zip_code' => self::s($d['zip_code'] ?? '', 10),
            'tin' => self::s($d['tin'] ?? '', 30), 'phone' => self::s($d['phone'] ?? '', 40), 'email' => self::s($d['email'] ?? '', 120),
            'submission_methods' => $methods ? implode(',', $methods) : null, 'submission_notes' => self::s($d['submission_notes'] ?? '', 5000),
            'funding_notes' => self::s($d['funding_notes'] ?? '', 5000), 'requirement_notes' => self::s($d['requirement_notes'] ?? '', 5000),
            'is_active' => isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1,
        ];
        if (array_key_exists('extra_bid_papers', $d)) {
            $row['extra_bid_papers'] = (int)(bool)$d['extra_bid_papers'];
        }
        if ($row['email'] && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            fail('The e-mail address is not valid.');
        }
        if (!empty($d['customer_id']) && DB::value('SELECT id FROM customers WHERE id = :c AND parent_id IS NULL', ['c' => $d['customer_id']])) {
            $row['customer_id'] = (int)$d['customer_id'];
        }
        return DB::tx(function () use ($row, $id) {
            if ($id) {
                $old = Brgy::entity($id);
                if (!$old) {
                    fail('Procuring entity not found.');
                }
                $row['updated_by'] = Auth::id();
                $row['updated_at'] = date('Y-m-d H:i:s');
                DB::update('brgy_entities', $row, 'id = :id', ['id' => $id]);
                if ($old['customer_id']) {
                    DB::update('customers', ['billing_address' => $row['address'], 'tin' => $row['tin'], 'phone' => $row['phone'], 'email' => $row['email']], 'id = :id', ['id' => $old['customer_id']]);
                }
                Brgy::log('edit', 'entity', $id, $row['official_name'], ['before' => $old, 'after' => $row, 'page_key' => 'brgy.entities']);
                return $id;
            }
            $row['created_by'] = Auth::id();
            $id = DB::insert('brgy_entities', $row);
            Brgy::log('create', 'entity', $id, $row['official_name'], ['after' => $row, 'page_key' => 'brgy.entities']);
            return $id;
        });
    }

    /**
     * An official or contact person. Naming a new holder of a role who starts on a date closes the term of the one before
     * (their "valid to" becomes the day before), so the history of the officials is kept.
     */
    public static function saveContact(int $entityId, array $d, ?int $id = null): int
    {
        self::need('brgy.entities', 'edit', 'change the officials of a procuring entity');
        if (!Brgy::entity($entityId)) {
            fail('Procuring entity not found.');
        }
        $row = [
            'entity_id' => $entityId, 'role_code' => isset(Brgy::CONTACT_ROLES[$d['role_code'] ?? '']) ? $d['role_code'] : 'other',
            'name' => self::s($d['name'] ?? '', 120), 'position' => self::s($d['position'] ?? '', 120), 'department' => self::s($d['department'] ?? '', 120),
            'phone' => self::s($d['phone'] ?? '', 40), 'email' => self::s($d['email'] ?? '', 120), 'valid_from' => self::date($d['valid_from'] ?? ''),
            'valid_to' => self::date($d['valid_to'] ?? ''), 'is_signatory' => !empty($d['is_signatory']) ? 1 : 0, 'notes' => self::s($d['notes'] ?? '', 255),
        ];
        if (!$row['name']) {
            fail('Enter the name of the official or contact person.');
        }
        if ($row['valid_from'] && $row['valid_to'] && $row['valid_to'] < $row['valid_from']) {
            fail('"Valid to" cannot be before "valid from".');
        }
        return DB::tx(function () use ($row, $id, $entityId) {
            if ($id) {
                DB::update('brgy_contacts', $row, 'id = :id AND entity_id = :e', ['id' => $id, 'e' => $entityId]);
                Brgy::log('edit', 'contact', $id, $row['name'], ['after' => $row, 'page_key' => 'brgy.entities']);
                return $id;
            }
            if ($row['valid_from'] && $row['role_code'] !== 'other' && $row['role_code'] !== 'bac_member') {
                DB::run('UPDATE brgy_contacts SET valid_to = :to WHERE entity_id = :e AND role_code = :r AND valid_to IS NULL AND (valid_from IS NULL OR valid_from < :f)',
                    ['to' => date('Y-m-d', strtotime($row['valid_from'] . ' -1 day')), 'e' => $entityId, 'r' => $row['role_code'], 'f' => $row['valid_from']]);
            }
            $row['created_by'] = Auth::id();
            $id = DB::insert('brgy_contacts', $row);
            Brgy::log('create', 'contact', $id, $row['name'] . ' (' . Brgy::label(Brgy::CONTACT_ROLES, $row['role_code']) . ')', ['after' => $row, 'page_key' => 'brgy.entities']);
            return $id;
        });
    }

    // ================================================================== projects

    /** Fields that may still change after the bid was approved (they do not change the offer) */
    private const AFTER_BID = ['title', 'end_user', 'funding_source', 'delivery_site', 'source_url', 'philgeps_ref', 'reference_no', 'specs', 'eligibility_notes', 'notes',
        'preparer_id', 'checker_id', 'approver_id', 'submitter_id', 'pm_id', 'collector_id', 'site_id', 'payment_terms', 'terms_id', 'bid_opening_at', 'clarification_deadline'];

    public static function saveProject(array $d, ?int $id = null, $lockVersion = null): int
    {
        self::need('brgy.projects', $id ? 'edit' : 'create', ($id ? 'change' : 'add') . ' projects');
        $title = self::s($d['title'] ?? '', 255);
        if (!$title) {
            fail('Enter the title of the project (as in the notice / RFQ).');
        }
        $entityId = (int)($d['entity_id'] ?? 0);
        if (!$entityId || !Brgy::entity($entityId)) {
            fail('Choose the procuring entity.');
        }
        $modeId = !empty($d['mode_id']) ? (int)$d['mode_id'] : null;
        $mode = $modeId ? DB::row('SELECT * FROM brgy_proc_modes WHERE id = :id', ['id' => $modeId]) : null;
        if ($modeId && !$mode) {
            fail('Choose a procurement mode from the list.');
        }
        $userId = fn($v) => !empty($v) && DB::value("SELECT id FROM users WHERE id = :id AND status = 'active'", ['id' => (int)$v]) ? (int)$v : null;
        $row = [
            'title' => $title, 'entity_id' => $entityId, 'category' => isset(Brgy::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'goods',
            'mode_id' => $modeId, 'regime' => isset(Brgy::REGIMES[$d['regime'] ?? '']) ? $d['regime'] : ($mode['regime'] ?? 'ra12009'),
            'reference_no' => self::s($d['reference_no'] ?? '', 80), 'philgeps_ref' => self::s($d['philgeps_ref'] ?? '', 40), 'source_url' => self::url($d['source_url'] ?? ''),
            'posting_date' => self::date($d['posting_date'] ?? ''), 'end_user' => self::s($d['end_user'] ?? '', 120), 'funding_source' => self::s($d['funding_source'] ?? '', 160),
            'abc_amount' => r2(self::num($d['abc_amount'] ?? 0)), 'delivery_site' => self::s($d['delivery_site'] ?? '', 255),
            'delivery_days' => trim((string)($d['delivery_days'] ?? '')) === '' ? null : max(0, (int)$d['delivery_days']),
            'site_inspection_at' => self::dt($d['site_inspection_at'] ?? ''), 'prebid_at' => self::dt($d['prebid_at'] ?? ''),
            'clarification_deadline' => self::dt($d['clarification_deadline'] ?? ''), 'bid_deadline' => self::dt($d['bid_deadline'] ?? ''), 'bid_opening_at' => self::dt($d['bid_opening_at'] ?? ''),
            'specs' => self::s($d['specs'] ?? '', 20000), 'eligibility_notes' => self::s($d['eligibility_notes'] ?? '', 5000),
            'retention_pct' => max(0, min(100, self::num($d['retention_pct'] ?? ($mode['default_retention_pct'] ?? Brgy::setting('brgy_default_retention_pct'))))),
            'ld_rate_pct' => max(0, self::num($d['ld_rate_pct'] ?? Brgy::setting('brgy_default_ld_rate'))),
            'warranty_months' => trim((string)($d['warranty_months'] ?? '')) === '' ? null : max(0, (int)$d['warranty_months']),
            'terms_id' => !empty($d['terms_id']) ? (int)$d['terms_id'] : null, 'payment_terms' => self::s($d['payment_terms'] ?? '', 255),
            'site_id' => !empty($d['site_id']) ? (int)$d['site_id'] : null,
            'preparer_id' => $userId($d['preparer_id'] ?? null), 'checker_id' => $userId($d['checker_id'] ?? null), 'approver_id' => $userId($d['approver_id'] ?? null),
            'submitter_id' => $userId($d['submitter_id'] ?? null), 'pm_id' => $userId($d['pm_id'] ?? null), 'collector_id' => $userId($d['collector_id'] ?? null),
            'notes' => self::s($d['notes'] ?? '', 5000),
        ];
        // the transaction form and the papers of the SOP (only the fields the form sent)
        if (isset($d['process']) || !$id) {
            $row['process'] = isset(BrgySop::PROCESSES[$d['process'] ?? '']) ? $d['process'] : 'general';
        }
        $sopText = ['txn_form_no' => 30, 'tradename' => 160, 'requestor' => 120, 'requestor_position' => 120, 'inspector' => 120, 'inspector_position' => 120, 'purpose' => 255,
            'fund' => 60, 'ris_no' => 40, 'iar_no' => 40, 'dv_no' => 40, 'delivery_term' => 120, 'po_no' => 60];
        foreach ($sopText as $k => $len) {
            if (array_key_exists($k, $d)) {
                $row[$k] = self::s($d[$k], $len);
            }
        }
        foreach (['txn_date', 'pr_date', 'po_date', 'ris_date', 'iar_date', 'dv_date', 'delivery_date'] as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = self::date($d[$k]);
            }
        }
        if (array_key_exists('txn_type', $d)) {
            $row['txn_type'] = isset(BrgySop::txnTypes()[$d['txn_type']]) ? $d['txn_type'] : null;
        }
        if (array_key_exists('sales_rep_id', $d)) {
            $row['sales_rep_id'] = $userId($d['sales_rep_id']);
        }
        if ($row['abc_amount'] < 0) {
            fail('The approved budget (ABC) cannot be negative.');
        }
        if ($mode && $mode['max_abc'] !== null && $row['abc_amount'] > (float)$mode['max_abc'] + 0.004) {
            fail('The ABC (' . money($row['abc_amount']) . ') is above the ceiling set for ' . $mode['name'] . ' (' . money($mode['max_abc']) . ', see Procurement Modes). Check the mode.');
        }
        if (Brgy::on('brgy_sod_strict')) {
            foreach ([['preparer_id', 'checker_id', 'checker'], ['preparer_id', 'approver_id', 'approver'], ['checker_id', 'approver_id', 'approver']] as [$a, $b, $what]) {
                if ($row[$a] && $row[$b] && $row[$a] === $row[$b]) {
                    fail('The same person cannot be the ' . str_replace('_id', '', $a) . ' and the ' . $what . ' (segregation of duties).');
                }
            }
        }
        if ($row['posting_date'] && $row['bid_deadline'] && substr($row['bid_deadline'], 0, 10) < $row['posting_date']) {
            fail('The bid deadline is before the posting date.');
        }
        return DB::tx(function () use ($row, $id, $lockVersion) {
            if ($id) {
                Brgy::checkVersion('brgy_projects', $id, $lockVersion);
                $old = Brgy::mustProject($id);
                if (in_array($old['status'], ['closed'], true)) {
                    fail('A closed project cannot be changed. Reopen it first (approver).');
                }
                if (isset($row['process']) && $row['process'] !== $old['process']) {
                    if ($old['status'] !== 'open' || $old['bid_status'] === 'released'
                        || DB::value("SELECT COUNT(*) FROM brgy_project_steps WHERE project_id = :p AND status <> 'pending'", ['p' => $id])) {
                        fail('The process cannot change once steps of the SOP are done or the offer is out.');
                    }
                    DB::run('DELETE FROM brgy_project_papers WHERE project_id = :p AND document_id IS NULL AND form_id IS NULL', ['p' => $id]);
                    DB::run('DELETE FROM brgy_project_steps WHERE project_id = :p', ['p' => $id]);
                    $row['sop_step'] = null;
                }
                if (array_key_exists('po_no', $row) && $old['status'] !== 'open') {
                    unset($row['po_no']);
                }
                if (!in_array($old['bid_status'], ['draft', 'returned'], true) || $old['status'] !== 'open') {
                    // the offer is approved or out: only descriptive fields change; dates of the contract change on the Award tab
                    foreach ($row as $k => $v) {
                        if (!in_array($k, self::AFTER_BID, true) && in_array($k, ['abc_amount', 'mode_id', 'category', 'regime', 'entity_id', 'bid_deadline'], true) && (string)$v !== (string)$old[$k]) {
                            fail('The ' . str_replace('_', ' ', $k) . ' cannot change once the bid is ' . (Brgy::WF[$old['bid_status']] ?? $old['bid_status']) . '. An approver can reopen the bid.');
                        }
                    }
                    unset($row['retention_pct'], $row['ld_rate_pct'], $row['warranty_months']);
                }
                $row['updated_by'] = Auth::id();
                $row['updated_at'] = date('Y-m-d H:i:s');
                DB::update('brgy_projects', $row, 'id = :id', ['id' => $id]);
                Brgy::bumpVersion('brgy_projects', $id);
                BrgySop::ensure(Brgy::mustProject($id));
                Brgy::log('edit', 'project', $id, $old['project_no'] . ' ' . $row['title'], ['before' => $old, 'after' => $row, 'page_key' => 'brgy.projects']);
                return $id;
            }
            if (BrgySop::uses($row) && empty($row['txn_form_no'])) {
                $row['txn_form_no'] = Seq::next('brgy_txn_form');
            }
            if (BrgySop::uses($row)) {
                $row['txn_date'] ??= today();
                $row['tradename'] ??= Brgy::setting('brgy_tradename') ?: null;
            }
            $row['project_no'] = Seq::next('brgy_project');
            $row['created_by'] = Auth::id();
            $row['preparer_id'] ??= Auth::id();
            $id = DB::insert('brgy_projects', $row);
            Brgy::buildChecklist($id);
            BrgySop::ensure(Brgy::mustProject($id));
            Brgy::event($id, 'note', 'Opportunity recorded', ['details' => $row['reference_no'] ? 'Reference ' . $row['reference_no'] : null]);
            Brgy::log('create', 'project', $id, $row['project_no'] . ' ' . $row['title'], ['after' => $row, 'amount' => $row['abc_amount'], 'page_key' => 'brgy.projects']);
            return $id;
        });
    }

    /**
     * The price schedule (bill of quantities). It changes only while the bid is a draft or was returned, and never after
     * the award (then only through change orders). $rows: [id?, line_kind, item_id, description, specs, unit, qty, unit_cost,
     * markup_pct, unit_price, tax_code_id, delivery_due, notes]. A blank price is the cost plus the markup.
     */
    public static function saveLines(int $projectId, array $rows, $lockVersion = null): void
    {
        self::need('brgy.projects', 'edit', 'change the price schedule');
        DB::tx(function () use ($projectId, $rows, $lockVersion) {
            Brgy::checkVersion('brgy_projects', $projectId, $lockVersion);
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'open' || !in_array($p['bid_status'], ['draft', 'returned'], true)) {
                fail('The price schedule is read-only: the bid is ' . (Brgy::WF[$p['bid_status']] ?? $p['bid_status']) . ($p['status'] !== 'open' ? ' and the project is ' . Brgy::label(Brgy::STATUSES, $p['status']) : '') . '. An approver can reopen the bid; after the award, use a change order.');
            }
            $existing = array_map('intval', DB::column('SELECT id FROM brgy_project_lines WHERE project_id = :p', ['p' => $projectId]));
            $keep = [];
            $n = 0;
            $taxIds = array_map('intval', array_keys(Lookup::taxCodes()));
            foreach ($rows as $r) {
                $desc = trim((string)($r['description'] ?? ''));
                $itemId = !empty($r['item_id']) ? (int)$r['item_id'] : null;
                $q = self::num($r['qty'] ?? 0);
                $cost = self::num($r['unit_cost'] ?? 0);
                $mk = self::num($r['markup_pct'] ?? 0);
                $price = trim((string)($r['unit_price'] ?? '')) === '' ? r2($cost * (1 + $mk / 100)) : self::num($r['unit_price']);
                if ($desc === '' && !$itemId && $q == 0.0 && $price == 0.0) {
                    continue;
                }
                $item = $itemId ? DB::row("SELECT * FROM items WHERE id = :id AND item_type IN ('inventory','non_inventory','service','assembly','other_charge')", ['id' => $itemId]) : null;
                if ($itemId && !$item) {
                    fail('Line ' . ($n + 1) . ': choose an inventory, non-inventory, service, assembly or other charge item.');
                }
                if ($desc === '') {
                    $desc = $item ? ($item['description'] ?: $item['name']) : '';
                }
                if ($desc === '') {
                    fail('Line ' . ($n + 1) . ': enter the description.');
                }
                if ($q < 0 || $price < 0 || $cost < 0) {
                    fail('Line ' . ($n + 1) . ' (' . $desc . '): quantities, costs and prices cannot be negative.');
                }
                // a line without a tax code takes the company's usual one (VAT 12% or non-taxable); exempt and
                // zero-rated lines are chosen on purpose
                $tax = !empty($r['tax_code_id']) ? (int)$r['tax_code_id'] : null;
                if (!$tax || !in_array($tax, $taxIds, true)) {
                    $tax = Brgy::defaultTaxCode();
                }
                $kind = isset(Brgy::LINE_KINDS[$r['line_kind'] ?? '']) ? $r['line_kind'] : ($item && $item['item_type'] === 'service' ? 'service' : 'goods');
                $line = [
                    'project_id' => $projectId, 'line_no' => ++$n, 'line_kind' => $kind, 'item_id' => $itemId, 'description' => mb_substr($desc, 0, 255),
                    'specs' => self::s($r['specs'] ?? '', 5000), 'unit' => self::s($r['unit'] ?? ($item['unit'] ?? ''), 20),
                    'bid_qty' => round($q, 4), 'qty' => round($q, 4), 'unit_cost' => round($cost, 4), 'markup_pct' => round($mk, 4), 'unit_price' => round($price, 4),
                    'est_unit_price' => trim((string)($r['est_unit_price'] ?? '')) === '' ? null : round(max(0, self::num($r['est_unit_price'])), 4),
                    'amount' => r2($q * $price), 'tax_code_id' => $tax, 'account_id' => !empty($r['account_id']) ? (int)$r['account_id'] : null,
                    'delivery_due' => self::date($r['delivery_due'] ?? ''), 'notes' => self::s($r['notes'] ?? '', 255),
                ];
                $lid = !empty($r['id']) ? (int)$r['id'] : 0;
                if ($lid && in_array($lid, $existing, true)) {
                    DB::update('brgy_project_lines', $line, 'id = :id', ['id' => $lid]);
                    $keep[] = $lid;
                } else {
                    $keep[] = DB::insert('brgy_project_lines', $line);
                }
            }
            foreach (array_diff($existing, $keep) as $gone) {
                DB::run('DELETE FROM brgy_project_lines WHERE id = :id', ['id' => $gone]);
            }
            $t = Brgy::lineTotals($projectId);
            DB::update('brgy_projects', ['bid_amount' => $t['bid'], 'est_cost' => $t['cost'], 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            if (in_array($p['phase'], ['intake', 'eligibility'], true) && $p['go_decision'] === 'go') {
                Brgy::setPhase($p, 'costing', 'Price schedule prepared');
            }
            Brgy::log('edit_lines', 'project', $projectId, $p['project_no'] . ' price schedule', ['amount' => $t['bid'], 'reason' => count($keep) . ' line(s), estimated cost ' . money($t['cost']), 'page_key' => 'brgy.projects']);
        });
    }

    /** Go / no-go: go opens the eligibility work, no-go ends the opportunity (an approver can reopen it) */
    public static function goDecision(int $projectId, string $decision, string $reason): void
    {
        self::need('brgy.projects', 'edit', 'record the go / no-go decision');
        if (!in_array($decision, ['go', 'no_go'], true)) {
            fail('Choose go or no-go.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for the decision.');
        }
        DB::tx(function () use ($projectId, $decision, $reason) {
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'open' || !in_array($p['phase'], ['intake', 'eligibility', 'costing'], true)) {
                fail('The go / no-go decision is made before the bid goes for approval.');
            }
            DB::update('brgy_projects', ['go_decision' => $decision, 'go_reason' => mb_substr($reason, 0, 255), 'go_by' => Auth::id(), 'go_at' => date('Y-m-d H:i:s'),
                'status' => $decision === 'no_go' ? 'no_bid' : 'open'], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            if ($decision === 'go') {
                Brgy::setPhase($p, 'eligibility', 'Go: ' . $reason);
            }
            DB::insert('brgy_workflow', ['project_id' => $projectId, 'object_type' => 'phase', 'object_id' => $projectId, 'action' => $decision === 'go' ? 'go' : 'no_go',
                'from_status' => $p['go_decision'], 'to_status' => $decision, 'user_id' => Auth::id(), 'remarks' => mb_substr($reason, 0, 255)]);
            Brgy::log('decision', 'project', $projectId, $p['project_no'], ['reason' => ($decision === 'go' ? 'Go: ' : 'No-go: ') . $reason, 'page_key' => 'brgy.projects']);
        });
    }

    /** Moves between the preparation phases (intake, eligibility, costing) */
    public static function movePhase(int $projectId, string $phase, string $remarks = ''): void
    {
        self::need('brgy.projects', 'edit', 'move projects between phases');
        $p = Brgy::mustProject($projectId);
        $prep = ['intake', 'eligibility', 'costing'];
        if ($p['status'] !== 'open' || !in_array($p['phase'], $prep, true) || !in_array($phase, $prep, true)) {
            fail('Only the preparation phases (intake, eligibility, costing) are moved by hand; the others move with the records that prove them.');
        }
        if ($phase !== 'intake' && $p['go_decision'] !== 'go') {
            fail('Record the go decision first.');
        }
        DB::tx(fn() => Brgy::setPhase($p, $phase, $remarks, true));
    }

    /** Sends the bid for review: the gates of phase 04, then a read-only copy of the costing */
    public static function submitBid(int $projectId, string $remarks = ''): void
    {
        DB::tx(function () use ($projectId, $remarks) {
            $p = Brgy::mustProject($projectId);
            if (!in_array($p['bid_status'], ['draft', 'returned'], true)) {
                fail('The bid is already ' . (Brgy::WF[$p['bid_status']] ?? $p['bid_status']) . '.');
            }
            if ($gate = Brgy::bidGate($p)) {
                fail("The bid cannot go for review yet:\n- " . implode("\n- ", $gate));
            }
            $ver = Brgy::snapshotCosting($p, $remarks !== '' ? $remarks : 'Sent for review');
            $p = Brgy::mustProject($projectId);
            Brgy::step('bid', $p, 'submit', $remarks !== '' ? $remarks : 'Costing version ' . $ver . ' sent for review', $p);
            Brgy::setPhase(Brgy::mustProject($projectId), 'approval', 'Bid sent for review (costing version ' . $ver . ')');
        });
    }

    /** Review, approval, return or reopening of the bid */
    public static function bidStep(int $projectId, string $action, string $remarks = ''): void
    {
        DB::tx(function () use ($projectId, $action, $remarks) {
            $p = Brgy::mustProject($projectId);
            if ($action === 'approve' && Brgy::on('brgy_gate_deadline') && $p['bid_deadline'] && strtotime((string)$p['bid_deadline']) < time()) {
                fail('The bid deadline (' . fdatetime($p['bid_deadline']) . ') has passed; the bid can no longer be approved for submission.');
            }
            if ($action === 'approve' && ($gate = Brgy::bidGate(array_merge($p, ['bid_status' => 'draft'])))) {
                fail("Something changed since the bid was sent for review:\n- " . implode("\n- ", $gate));
            }
            if ($action === 'reopen' && $p['bid_status'] === 'released') {
                fail('The bid was already submitted to the entity; it cannot be reopened.');
            }
            Brgy::step('bid', $p, $action, $remarks, $p);
            if (in_array($action, ['return', 'reopen'], true)) {
                Brgy::setPhase(Brgy::mustProject($projectId), 'costing', ($action === 'return' ? 'Returned: ' : 'Reopened: ') . $remarks, true);
            }
            if ($action === 'approve') {
                Brgy::event($projectId, 'note', 'Bid approved for submission (' . money($p['bid_amount']) . ')', ['details' => $remarks !== '' ? $remarks : null]);
            }
        });
    }

    /**
     * A submission to the entity, with proof. A bid submission needs the approved bid, the named authorized submitter and
     * the deadline not passed; it releases the bid (the offer is then fixed) and moves the project to "bid submitted".
     */
    public static function recordSubmission(int $projectId, array $d, ?array $proof = null): int
    {
        // billing packs are submitted by the billing staff, follow-ups by the collectors, everything else by the bid team
        $page = ['billing' => 'brgy.billing', 'followup' => 'brgy.collections'][$d['sub_type'] ?? ''] ?? 'brgy.projects';
        if (!Access::can($page, 'edit') && !Access::can('brgy.projects', 'edit')) {
            fail('Your role cannot record submissions.');
        }
        return DB::tx(function () use ($projectId, $d, $proof) {
            $p = Brgy::mustProject($projectId);
            $type = isset(Brgy::SUB_TYPES[$d['sub_type'] ?? '']) ? $d['sub_type'] : 'other';
            $at = self::dt($d['submitted_at'] ?? '');
            if (!$at) {
                fail('Enter the actual date and time of the submission.');
            }
            if (strtotime($at) > time() + 300) {
                fail('The submission time is in the future. Record a submission after it happened, with its proof.');
            }
            if ($type === 'bid') {
                if ($p['bid_status'] !== 'approved') {
                    fail('Only an approved bid is submitted (the bid is ' . (Brgy::WF[$p['bid_status']] ?? $p['bid_status']) . ').');
                }
                if (!$p['submitter_id']) {
                    fail('Name the authorized submitter on the project first (Edit project).');
                }
                if ((int)$p['submitter_id'] !== (int)Auth::id() && !Access::isAdmin()) {
                    fail('Only the authorized submitter, ' . Brgy::userName((int)$p['submitter_id']) . ', records the bid submission.');
                }
                if ($p['bid_deadline'] && strtotime($at) > strtotime((string)$p['bid_deadline'])) {
                    fail('Submitted ' . fdatetime($at) . ', after the deadline (' . fdatetime($p['bid_deadline']) . '). A late bid is not recorded as submitted.');
                }
                if (!self::fileGiven($proof) && empty($d['proof_doc_id']) && in_array($d['method'] ?? '', ['physical', 'courier'], true) && empty($d['received_by'])) {
                    fail('A hand-carried or couriered bid needs its proof: the receiving copy, or at least who received it.');
                }
            }
            $row = [
                'project_id' => $projectId, 'sub_type' => $type, 'method' => isset(Brgy::SUB_METHODS[$d['method'] ?? '']) ? $d['method'] : 'other', 'submitted_at' => $at, 'submitted_by' => Auth::id(),
                'recipient_name' => self::s($d['recipient_name'] ?? '', 120), 'recipient_office' => self::s($d['recipient_office'] ?? '', 120), 'destination' => self::s($d['destination'] ?? '', 255),
                'tracking_no' => self::s($d['tracking_no'] ?? '', 80), 'received_by' => self::s($d['received_by'] ?? '', 120), 'received_at' => self::dt($d['received_at'] ?? ''),
                'ack_ref' => self::s($d['ack_ref'] ?? '', 80), 'related_type' => self::s($d['related_type'] ?? '', 20), 'related_id' => !empty($d['related_id']) ? (int)$d['related_id'] : null,
                'outcome' => self::s($d['outcome'] ?? '', 255), 'notes' => self::s($d['notes'] ?? '', 255), 'created_by' => Auth::id(),
            ];
            $id = DB::insert('brgy_submissions', $row);
            $docId = null;
            if (self::fileGiven($proof)) {
                $docId = BrgyDocs::store($proof, ['scope' => 'project', 'project_id' => $projectId, 'doc_type' => 'submission_proof', 'title' => 'Proof of submission: ' . Brgy::label(Brgy::SUB_TYPES, $type) . ' ' . fdate($at),
                    'related_type' => 'submission', 'related_id' => $id, 'status' => 'approved']);
            } elseif (!empty($d['proof_doc_id'])) {
                $docId = (int)self::projectDoc($projectId, (int)$d['proof_doc_id'])['id'];
            }
            if ($docId) {
                DB::update('brgy_submissions', ['proof_doc_id' => $docId], 'id = :id', ['id' => $id]);
            }
            if ($type === 'bid') {
                Brgy::step('bid', $p, 'release', 'Submitted by ' . Brgy::label(Brgy::SUB_METHODS, $row['method']) . ' on ' . fdatetime($at), $p);
                DB::update('brgy_projects', ['bid_submitted_at' => $at], 'id = :id', ['id' => $projectId]);
                Brgy::setPhase(Brgy::mustProject($projectId), 'submitted', 'Bid submitted ' . fdatetime($at));
            }
            Brgy::log('submission', 'project', $projectId, $p['project_no'] . ' ' . Brgy::label(Brgy::SUB_TYPES, $type), ['after' => $row, 'page_key' => 'brgy.projects']);
            return $id;
        });
    }

    /** Correspondence, bulletins, clarification requests and other events (with an optional paper) */
    public static function addEvent(int $projectId, array $d, ?array $file = null): int
    {
        self::need('brgy.projects', 'edit', 'record correspondence');
        $p = Brgy::mustProject($projectId);
        $title = self::s($d['title'] ?? '', 200);
        if (!$title) {
            fail('Enter what happened (title).');
        }
        $at = self::dt($d['event_at'] ?? '') ?? date('Y-m-d H:i:s');
        return DB::tx(function () use ($p, $d, $file, $title, $at) {
            $docId = null;
            if (self::fileGiven($file)) {
                $docId = BrgyDocs::store($file, ['scope' => 'project', 'project_id' => $p['id'], 'doc_type' => $d['doc_type'] ?? 'correspondence', 'title' => $title, 'related_type' => 'event', 'status' => 'approved']);
            }
            $type = isset(Brgy::EVENT_TYPES[$d['event_type'] ?? '']) ? $d['event_type'] : 'note';
            $id = Brgy::event((int)$p['id'], $type, $title, ['event_at' => $at, 'direction' => in_array($d['direction'] ?? '', ['in', 'out', 'internal'], true) ? $d['direction'] : 'internal',
                'details' => self::s($d['details'] ?? '', 20000), 'party' => self::s($d['party'] ?? '', 160), 'doc_id' => $docId, 'due_at' => self::dt($d['due_at'] ?? ''),
                'status' => !empty($d['due_at']) && empty($d['done']) ? 'open' : 'done']);
            if ($docId) {
                DB::update('brgy_documents', ['related_id' => $id], 'id = :d', ['d' => $docId]);
            }
            if (in_array($type, ['bid_opening', 'post_qual', 'clarification', 'bulletin'], true) && $p['phase'] === 'submitted') {
                Brgy::setPhase($p, 'evaluation', Brgy::label(Brgy::EVENT_TYPES, $type) . ': ' . $title);
            }
            Brgy::log('event', 'project', (int)$p['id'], $p['project_no'] . ': ' . $title, ['page_key' => 'brgy.projects']);
            return $id;
        });
    }

    public static function closeEvent(int $eventId, string $note): void
    {
        self::need('brgy.projects', 'edit', 'update correspondence');
        $e = DB::row('SELECT * FROM brgy_events WHERE id = :id', ['id' => $eventId]);
        if (!$e) {
            fail('Not found.');
        }
        DB::update('brgy_events', ['status' => 'done', 'details' => trim(((string)$e['details']) . "\n" . date('m/d/Y H:i') . ' ' . (Auth::user()['username'] ?? '') . ': ' . ($note !== '' ? $note : 'answered'))], 'id = :id', ['id' => $eventId]);
        Brgy::log('event_done', 'project', (int)$e['project_id'], (string)$e['title'], ['reason' => $note, 'page_key' => 'brgy.projects']);
    }

    /** Result of the evaluation when the bid is not awarded: lost, or cancelled / failed by the entity */
    public static function recordResult(int $projectId, string $result, array $d): void
    {
        self::need('brgy.projects', 'edit', 'record bid results');
        DB::tx(function () use ($projectId, $result, $d) {
            $p = Brgy::mustProject($projectId);
            if ($result === 'lost') {
                if (!in_array($p['phase'], ['submitted', 'evaluation'], true) || $p['status'] !== 'open') {
                    fail('A bid is lost after it was submitted.');
                }
                $why = self::s($d['lost_reason'] ?? '', 255);
                if (!$why) {
                    fail('Enter why the bid was not awarded (it feeds the won / lost report).');
                }
                DB::update('brgy_projects', ['status' => 'lost', 'lost_reason' => $why, 'winning_bidder' => self::s($d['winning_bidder'] ?? '', 160),
                    'winning_amount' => trim((string)($d['winning_amount'] ?? '')) === '' ? null : r2(self::num($d['winning_amount'])), 'result_rank' => !empty($d['result_rank']) ? (int)$d['result_rank'] : null], 'id = :id', ['id' => $projectId]);
                Brgy::setPhase($p, 'evaluation', 'Lost: ' . $why);
                Brgy::event($projectId, 'notice', 'Bid not awarded', ['details' => $why . (!empty($d['winning_bidder']) ? ' Awarded to ' . $d['winning_bidder'] . '.' : ''), 'direction' => 'in']);
            } elseif ($result === 'cancelled') {
                $why = self::s($d['cancel_reason'] ?? '', 255);
                if (!$why) {
                    fail('Enter the reason the entity gave (cancelled, failed bidding ...).');
                }
                if ($p['status'] === 'awarded') {
                    fail('Use "Award cancelled" on the Award tab for an awarded contract.');
                }
                DB::update('brgy_projects', ['status' => 'cancelled', 'cancel_reason' => $why], 'id = :id', ['id' => $projectId]);
                Brgy::event($projectId, 'notice', 'Procurement cancelled by the entity', ['details' => $why, 'direction' => 'in']);
            } elseif ($result === 'rank') {
                DB::update('brgy_projects', ['result_rank' => !empty($d['result_rank']) ? (int)$d['result_rank'] : null], 'id = :id', ['id' => $projectId]);
                Brgy::setPhase($p, 'evaluation', 'Bid opening: ranked ' . ($d['result_rank'] ?? '?'));
            } else {
                fail('Unknown result.');
            }
            Brgy::bumpVersion('brgy_projects', $projectId);
            DB::insert('brgy_workflow', ['project_id' => $projectId, 'object_type' => 'phase', 'object_id' => $projectId, 'action' => $result, 'from_status' => $p['status'],
                'to_status' => $result === 'rank' ? $p['status'] : $result, 'user_id' => Auth::id(), 'remarks' => mb_substr((string)($d['lost_reason'] ?? $d['cancel_reason'] ?? ''), 0, 255) ?: null]);
            Brgy::log('result', 'project', $projectId, $p['project_no'] . ' ' . $result, ['reason' => $d['lost_reason'] ?? $d['cancel_reason'] ?? null, 'page_key' => 'brgy.projects']);
        });
    }

    /**
     * The award, recorded from the entity's notice of award (or purchase order / contract): its reference, its date, when
     * the company received it, and the paper itself. The amount awarded has to agree with the approved price schedule.
     * A Customer:Job and a class are opened for job costing; nothing is posted to the ledger.
     */
    public static function recordAward(int $projectId, array $d, ?array $evidence = null): void
    {
        self::need('brgy.projects', 'edit', 'record the award');
        DB::tx(function () use ($projectId, $d, $evidence) {
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'open' || !in_array($p['phase'], ['submitted', 'evaluation'], true) || $p['bid_status'] !== 'released') {
                fail('An award is recorded for a submitted bid (this project is ' . Brgy::label(Brgy::STATUSES, $p['status']) . ', ' . Brgy::phaseLabel($p['phase']) . ').');
            }
            $noaRef = self::s($d['noa_ref'] ?? '', 60);
            $noaDate = self::date($d['noa_date'] ?? '');
            if (!$noaRef || !$noaDate) {
                fail('Enter the reference and the date of the notice of award (or purchase order) issued by the entity.');
            }
            $recv = self::date($d['noa_received_date'] ?? '') ?? $noaDate;
            if ($recv < $noaDate) {
                fail('The notice cannot be received before it was issued.');
            }
            $amount = r2(self::num($d['contract_amount'] ?? $p['bid_amount']));
            $lines = Brgy::lineTotals($projectId);
            if (abs($amount - $lines['contract']) > 0.01) {
                fail('The amount awarded (' . money($amount) . ') does not agree with the approved price schedule (' . money($lines['contract']) . '). If the entity corrected the bid, an approver reopens it, the schedule is corrected and approved again, then the award is recorded.');
            }
            if ((float)$p['abc_amount'] > 0 && $amount > (float)$p['abc_amount'] + 0.004) {
                fail('The amount awarded is above the ABC.');
            }
            $evDoc = null;
            if (self::fileGiven($evidence)) {
                $evDoc = BrgyDocs::store($evidence, ['scope' => 'project', 'project_id' => $projectId, 'doc_type' => in_array($d['evidence_type'] ?? '', ['noa', 'po', 'contract'], true) ? $d['evidence_type'] : 'noa',
                    'title' => 'Notice of award ' . $noaRef, 'doc_no' => $noaRef, 'issued_by' => $p['entity_name'], 'issue_date' => $noaDate, 'related_type' => 'award', 'status' => 'approved']);
            } elseif (!empty($d['evidence_doc_id'])) {
                $evDoc = (int)self::projectDoc($projectId, (int)$d['evidence_doc_id'], ['noa', 'po', 'contract'])['id'];
            }
            $fromCanvass = !$evDoc && !empty($d['sop_canvass']) && ($p['process'] ?? '') === 'svp';
            if ($fromCanvass) {
                // small value procurement (SOP): the award is the abstract of canvass and the purchase order the company prepared
                $evDoc = (int)DB::value("SELECT document_id FROM brgy_project_papers WHERE project_id = :p AND paper_code IN ('po', 'abstract') AND document_id IS NOT NULL
                                          ORDER BY paper_code = 'po' DESC LIMIT 1", ['p' => $projectId]) ?: null;
            }
            if (!$evDoc && !$fromCanvass) {
                fail('Attach the notice of award, purchase order or contract received from the entity. The award is recorded from the entity\'s own paper.');
            }
            $ntp = self::date($d['ntp_date'] ?? '');
            $start = self::date($d['start_date'] ?? '') ?? $ntp ?? $recv;
            $due = self::date($d['due_date'] ?? '') ?? ($p['delivery_days'] ? date('Y-m-d', strtotime($start . ' +' . (int)$p['delivery_days'] . ' days')) : null);
            $set = [
                'status' => 'awarded', 'contract_amount' => $amount, 'noa_ref' => $noaRef, 'noa_date' => $noaDate, 'noa_received_date' => $recv,
                'noa_ack_date' => self::date($d['noa_ack_date'] ?? ''), 'contract_no' => self::s($d['contract_no'] ?? '', 60), 'po_no' => self::s($d['po_no'] ?? '', 60),
                'contract_signed_date' => self::date($d['contract_signed_date'] ?? ''), 'ntp_date' => $ntp, 'ntp_received_date' => self::date($d['ntp_received_date'] ?? ''),
                'start_date' => $start, 'due_date' => $due, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s'),
            ];
            foreach (['retention_pct', 'ld_rate_pct'] as $k) {
                if (isset($d[$k]) && trim((string)$d[$k]) !== '') {
                    $set[$k] = max(0, self::num($d[$k]));
                }
            }
            if (isset($d['warranty_months']) && trim((string)$d['warranty_months']) !== '') {
                $set['warranty_months'] = max(0, (int)$d['warranty_months']);
            }
            if (!empty($d['terms_id'])) {
                $set['terms_id'] = (int)$d['terms_id'];
            }
            DB::update('brgy_projects', $set, 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            BrgyPost::ensureJob(Brgy::mustProject($projectId));
            if ($evDoc) {
                DB::update('brgy_documents', ['related_id' => $projectId], 'id = :d AND related_id IS NULL', ['d' => $evDoc]);
            }
            Brgy::setPhase($p, 'award', ($fromCanvass ? 'Canvass and purchase order ' : 'Notice of award ') . $noaRef . ' dated ' . fdate($noaDate) . ', received ' . fdate($recv));
            Brgy::event($projectId, 'noa', 'Notice of award ' . $noaRef . ' received', ['event_at' => $recv . ' 09:00:00', 'direction' => 'in', 'doc_id' => $evDoc,
                'details' => 'Issued ' . fdate($noaDate) . ' by ' . $p['entity_name'] . ' for ' . money($amount) . '.']);
            DB::insert('brgy_workflow', ['project_id' => $projectId, 'object_type' => 'phase', 'object_id' => $projectId, 'action' => 'award', 'from_status' => 'open', 'to_status' => 'awarded',
                'user_id' => Auth::id(), 'amount' => $amount, 'remarks' => 'NOA ' . $noaRef]);
            Brgy::log('award', 'project', $projectId, $p['project_no'], ['amount' => $amount, 'after' => $set, 'reason' => ($fromCanvass ? 'Canvass (SOP), purchase order ' : 'Notice of award ') . $noaRef . ($evDoc ? ' (document #' . $evDoc . ')' : ''), 'page_key' => 'brgy.projects']);
        });
    }

    /** Contract details after the award (signing, notice to proceed, dates) */
    public static function saveContractDates(int $projectId, array $d): void
    {
        self::need('brgy.projects', 'edit', 'change the contract details');
        DB::tx(function () use ($projectId, $d) {
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'awarded') {
                fail('Only an awarded contract has contract details.');
            }
            $set = [];
            foreach (['noa_ack_date', 'contract_signed_date', 'ntp_date', 'ntp_received_date', 'start_date', 'due_date', 'completion_date', 'warranty_until'] as $k) {
                if (array_key_exists($k, $d)) {
                    $set[$k] = self::date($d[$k]);
                }
            }
            foreach (['contract_no' => 60, 'po_no' => 60, 'payment_terms' => 255] as $k => $len) {
                if (array_key_exists($k, $d)) {
                    $set[$k] = self::s($d[$k], $len);
                }
            }
            if (array_key_exists('warranty_months', $d)) {
                $set['warranty_months'] = trim((string)$d['warranty_months']) === '' ? null : max(0, (int)$d['warranty_months']);
            }
            if (!empty($d['terms_id'])) {
                $set['terms_id'] = (int)$d['terms_id'];
            }
            if (!$set) {
                return;
            }
            DB::update('brgy_projects', $set + ['updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            if (!empty($set['ntp_date']) && !$p['ntp_date']) {
                Brgy::event($projectId, 'ntp', 'Notice to proceed dated ' . fdate($set['ntp_date']), ['direction' => 'in', 'event_at' => ($set['ntp_received_date'] ?? $set['ntp_date']) . ' 09:00:00']);
            }
            Brgy::log('edit', 'project', $projectId, $p['project_no'] . ' contract details', ['before' => array_intersect_key($p, $set), 'after' => $set, 'page_key' => 'brgy.projects']);
        });
    }

    /** Starts fulfilment: the contract is signed (or the purchase order received) and, when the settings say so, the performance security is in place */
    public static function startFulfillment(int $projectId): void
    {
        self::need('brgy.projects', 'edit', 'start fulfilment');
        DB::tx(function () use ($projectId) {
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'awarded' || $p['phase'] !== 'award') {
                fail('Fulfilment starts right after the award.');
            }
            if (!$p['contract_signed_date'] && !$p['po_no']) {
                fail('Record the contract signing date or the purchase order number first.');
            }
            if ($p['category'] === 'works' && !$p['ntp_date']) {
                fail('Works start with the notice to proceed: record its date first.');
            }
            $needsPerf = (int)DB::value('SELECT needs_perf_security FROM brgy_proc_modes WHERE id = :m', ['m' => (int)$p['mode_id']]);
            if (Brgy::on('brgy_require_perf_security') && $needsPerf
                && !DB::value("SELECT COUNT(*) FROM brgy_securities WHERE project_id = :p AND security_type = 'performance' AND status = 'active'", ['p' => $projectId])) {
                fail('Post the performance security first (Award tab).');
            }
            Brgy::setPhase($p, 'fulfillment', 'Contract ' . ($p['contract_no'] ?: $p['po_no']) . ' in force');
        });
    }

    /** The entity cancelled the award: allowed only while nothing was delivered, billed or collected */
    public static function cancelAward(int $projectId, string $reason, ?array $evidence = null): void
    {
        self::need('brgy.projects', 'edit', 'record a cancelled award');
        if (trim($reason) === '') {
            fail('Enter the reason given by the entity.');
        }
        DB::tx(function () use ($projectId, $reason, $evidence) {
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'awarded') {
                fail('Only an awarded contract can have its award cancelled.');
            }
            $busy = (int)DB::value("SELECT (SELECT COUNT(*) FROM brgy_deliveries WHERE project_id = :p1 AND status <> 'void') + (SELECT COUNT(*) FROM brgy_billings WHERE project_id = :p2 AND status <> 'void')
                                      + (SELECT COUNT(*) FROM brgy_collections WHERE project_id = :p3 AND status = 'posted')", ['p1' => $projectId, 'p2' => $projectId, 'p3' => $projectId]);
            if ($busy) {
                fail('Deliveries, billings or collections are recorded on this contract. Void them first; an award with work done is closed out instead.');
            }
            $docId = self::fileGiven($evidence) ? BrgyDocs::store($evidence, ['scope' => 'project', 'project_id' => $projectId, 'doc_type' => 'correspondence', 'title' => 'Cancellation of award', 'related_type' => 'award', 'status' => 'approved']) : null;
            DB::update('brgy_projects', ['status' => 'cancelled', 'cancel_reason' => mb_substr('Award cancelled: ' . $reason, 0, 255)], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            Brgy::event($projectId, 'notice', 'Award cancelled by the entity', ['details' => $reason, 'direction' => 'in', 'doc_id' => $docId]);
            DB::insert('brgy_workflow', ['project_id' => $projectId, 'object_type' => 'phase', 'object_id' => $projectId, 'action' => 'cancel', 'from_status' => 'awarded', 'to_status' => 'cancelled',
                'user_id' => Auth::id(), 'remarks' => mb_substr($reason, 0, 255)]);
            Brgy::log('cancel_award', 'project', $projectId, $p['project_no'], ['reason' => $reason, 'page_key' => 'brgy.projects']);
        });
    }

    // ================================================================== checklist

    /** Files a document for a checklist item (a new upload, or a document already in the vault) */
    public static function attachRequirement(int $reqId, ?array $file, ?int $docId, array $meta = []): void
    {
        self::need('brgy.projects', 'edit', 'file compliance documents');
        DB::tx(function () use ($reqId, $file, $docId, $meta) {
            $r = DB::row('SELECT * FROM brgy_project_reqs WHERE id = :id', ['id' => $reqId]);
            if (!$r) {
                fail('Checklist item not found.');
            }
            $p = Brgy::mustProject((int)$r['project_id']);
            if (self::fileGiven($file)) {
                $isCompany = $r['doc_type'] && (int)DB::value('SELECT is_company FROM brgy_doc_types WHERE code = :c', ['c' => $r['doc_type']]) === 1 && !empty($meta['as_company']);
                $docId = BrgyDocs::store($file, $meta + ['scope' => $isCompany ? 'company' : 'project', 'project_id' => $isCompany ? null : $p['id'], 'doc_type' => $r['doc_type'] ?: 'other',
                    'title' => $meta['title'] ?? $r['name'], 'related_type' => 'requirement', 'related_id' => $reqId]);
            } elseif ($docId) {
                $doc = self::projectDoc((int)$p['id'], $docId);
                $docId = (int)$doc['id'];
            } else {
                fail('Choose a file, or a document already in the vault.');
            }
            $doc = DB::row('SELECT * FROM brgy_documents WHERE id = :id', ['id' => $docId]);
            if ((int)$r['needs_validity'] === 1 && !$doc['valid_until']) {
                fail('This requirement has a validity date: enter the "valid until" date of the document.');
            }
            DB::update('brgy_project_reqs', ['document_id' => $docId, 'status' => in_array($doc['status'], ['approved', 'final'], true) ? 'approved' : 'submitted',
                'reviewed_by' => in_array($doc['status'], ['approved', 'final'], true) ? $doc['reviewed_by'] : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reqId]);
            Brgy::log('file_requirement', 'project', (int)$p['id'], $p['project_no'] . ': ' . $r['name'], ['reason' => 'Document #' . $docId, 'page_key' => 'brgy.projects']);
        });
    }

    /** A checklist item that does not apply. A mandatory one is waived by an approver only, with the reason. */
    public static function waiveRequirement(int $reqId, string $reason): void
    {
        $r = DB::row('SELECT * FROM brgy_project_reqs WHERE id = :id', ['id' => $reqId]);
        if (!$r) {
            fail('Checklist item not found.');
        }
        if (trim($reason) === '') {
            fail('Enter why the requirement does not apply (e.g. "not asked in the RFQ").');
        }
        if ($r['requirement_type'] === 'mandatory') {
            self::need('brgy.approvals', 'approve', 'waive a mandatory requirement (approvers only)');
        } else {
            self::need('brgy.projects', 'edit', 'change the checklist');
        }
        DB::update('brgy_project_reqs', ['status' => 'waived', 'waive_reason' => mb_substr($reason, 0, 255), 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reqId]);
        DB::insert('brgy_workflow', ['project_id' => $r['project_id'], 'object_type' => 'requirement', 'object_id' => $reqId, 'action' => 'waive', 'from_status' => $r['status'], 'to_status' => 'waived',
            'user_id' => Auth::id(), 'remarks' => mb_substr($reason, 0, 255)]);
        Brgy::log('waive', 'requirement', $reqId, $r['name'], ['reason' => $reason, 'page_key' => 'brgy.projects']);
    }

    public static function addProjectRequirement(int $projectId, array $d): int
    {
        self::need('brgy.projects', 'edit', 'change the checklist');
        $p = Brgy::mustProject($projectId);
        $name = self::s($d['name'] ?? '', 200);
        if (!$name) {
            fail('Enter the requirement.');
        }
        $id = DB::insert('brgy_project_reqs', [
            'project_id' => $projectId, 'name' => $name, 'doc_group' => isset(Brgy::DOC_GROUPS[$d['doc_group'] ?? '']) ? $d['doc_group'] : 'other',
            'stage' => isset(Brgy::STAGES[$d['stage'] ?? '']) ? $d['stage'] : 'eligibility', 'requirement_type' => isset(Brgy::REQ_TYPES[$d['requirement_type'] ?? '']) ? $d['requirement_type'] : 'mandatory',
            'needs_validity' => !empty($d['needs_validity']) ? 1 : 0, 'doc_type' => self::s($d['doc_type'] ?? '', 40), 'legal_basis' => self::s($d['legal_basis'] ?? '', 255),
            'due_date' => self::date($d['due_date'] ?? ''), 'sort_order' => 900,
        ]);
        Brgy::log('add_requirement', 'project', $projectId, $p['project_no'] . ': ' . $name, ['reason' => $d['legal_basis'] ?? null, 'page_key' => 'brgy.projects']);
        return $id;
    }

    // ================================================================== securities

    public static function saveSecurity(int $projectId, array $d, ?array $file = null): int
    {
        self::need('brgy.projects', 'edit', 'record securities');
        return DB::tx(function () use ($projectId, $d, $file) {
            $p = Brgy::mustProject($projectId);
            $type = isset(Brgy::SECURITY_TYPES[$d['security_type'] ?? '']) ? $d['security_type'] : 'bid';
            $form = isset(Brgy::SECURITY_FORMS[$d['form'] ?? '']) ? $d['form'] : 'cash';
            $amt = r2(self::num($d['amount'] ?? 0));
            if ($form !== 'declaration' && $amt <= 0) {
                fail('Enter the amount of the security.');
            }
            $row = ['security_no' => Seq::next('brgy_security'), 'project_id' => $projectId, 'security_type' => $type, 'form' => $form, 'amount' => $amt,
                'issuer' => self::s($d['issuer'] ?? '', 160), 'reference_no' => self::s($d['reference_no'] ?? '', 80), 'issue_date' => self::date($d['issue_date'] ?? '') ?? today(),
                'valid_until' => self::date($d['valid_until'] ?? ''), 'status' => 'active',
                'pay_account_id' => in_array($form, Brgy::SECURITY_CASH_FORMS, true) && !empty($d['pay_account_id']) ? (int)$d['pay_account_id'] : null,
                'notes' => self::s($d['notes'] ?? '', 255), 'created_by' => Auth::id()];
            if (in_array($form, Brgy::SECURITY_CASH_FORMS, true) && !$row['pay_account_id']) {
                fail('Choose the bank or cash account the security was paid from.');
            }
            $id = DB::insert('brgy_securities', $row);
            if (self::fileGiven($file)) {
                $doc = BrgyDocs::store($file, ['scope' => 'project', 'project_id' => $projectId, 'doc_type' => $type === 'bid' ? 'bid_security' : 'perf_security',
                    'title' => Brgy::label(Brgy::SECURITY_TYPES, $type) . ' ' . $row['security_no'], 'doc_no' => $row['reference_no'], 'issued_by' => $row['issuer'],
                    'issue_date' => $row['issue_date'], 'valid_until' => $row['valid_until'], 'related_type' => 'security', 'related_id' => $id, 'status' => 'approved']);
                DB::update('brgy_securities', ['doc_id' => $doc], 'id = :id', ['id' => $id]);
            }
            BrgyPost::postSecurity($id);
            Brgy::log('create', 'security', $id, $row['security_no'] . ' ' . $p['project_no'], ['amount' => $amt, 'after' => $row, 'page_key' => 'brgy.projects']);
            return $id;
        });
    }

    public static function closeSecurity(int $securityId, string $outcome, string $date, ?int $bankId, string $reason): void
    {
        self::need('brgy.projects', 'edit', 'release securities');
        if (!in_array($outcome, ['released', 'forfeited'], true)) {
            fail('Choose returned or forfeited.');
        }
        if (!valid_date($date)) {
            fail('Enter the date.');
        }
        DB::tx(function () use ($securityId, $outcome, $date, $bankId, $reason) {
            $s = DB::row('SELECT * FROM brgy_securities WHERE id = :id', ['id' => $securityId]);
            if (!$s) {
                fail('Security not found.');
            }
            BrgyPost::closeSecurity($securityId, $outcome, $date, $bankId);
            Brgy::log($outcome === 'released' ? 'release' : 'forfeit', 'security', $securityId, $s['security_no'], ['reason' => $reason, 'amount' => (float)$s['amount'], 'page_key' => 'brgy.projects']);
        });
    }

    // ================================================================== tasks

    public static function saveTask(int $projectId, array $d, ?int $id = null): int
    {
        self::need('brgy.projects', 'edit', 'change tasks');
        Brgy::mustProject($projectId);
        $title = self::s($d['title'] ?? '', 200);
        if (!$title) {
            fail('Enter the task.');
        }
        $status = in_array($d['status'] ?? 'open', ['open', 'in_progress', 'done', 'cancelled'], true) ? $d['status'] : 'open';
        $row = ['project_id' => $projectId, 'task_type' => isset(Brgy::TASK_TYPES[$d['task_type'] ?? '']) ? $d['task_type'] : 'task', 'title' => $title,
            'details' => self::s($d['details'] ?? '', 5000), 'assigned_to' => !empty($d['assigned_to']) ? (int)$d['assigned_to'] : null, 'due_date' => self::date($d['due_date'] ?? ''),
            'status' => $status, 'pct_complete' => trim((string)($d['pct_complete'] ?? '')) === '' ? null : max(0, min(100, self::num($d['pct_complete']))),
            'completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('brgy_tasks', $row, 'id = :id AND project_id = :p', ['id' => $id, 'p' => $projectId]);
        } else {
            $row['created_by'] = Auth::id();
            $id = DB::insert('brgy_tasks', $row);
        }
        Brgy::log('task', 'project', $projectId, $title . ' (' . $status . ')', ['page_key' => 'brgy.projects']);
        return $id;
    }

    // ================================================================== deliveries

    /**
     * A delivery (goods), a service accomplishment or a works progress report. Quantities never go beyond what is left of
     * the contract (with approved change orders). Goods that are stocked move to the "delivered, awaiting acceptance" site.
     * $d['lines'] = [project line id => ['qty' => , 'serial_batch' => , 'remarks' => ]]
     */
    public static function saveDelivery(array $d, ?int $id = null, $lockVersion = null): int
    {
        self::need('brgy.deliveries', $id ? 'edit' : 'create', ($id ? 'change' : 'record') . ' deliveries');
        return DB::tx(function () use ($d, $id, $lockVersion) {
            $old = null;
            if ($id) {
                Brgy::checkVersion('brgy_deliveries', $id, $lockVersion);
                $old = DB::row('SELECT * FROM brgy_deliveries WHERE id = :id', ['id' => $id]);
                if ($old['status'] !== 'delivered') {
                    fail('Only a delivery that has not been inspected yet can be changed (this one is ' . (Brgy::WF[$old['status']] ?? $old['status']) . ').');
                }
                $d['project_id'] = $old['project_id'];
            }
            $p = Brgy::mustProject((int)($d['project_id'] ?? 0));
            if ($p['status'] !== 'awarded' || Brgy::phaseIndex($p['phase']) < Brgy::phaseIndex('award')) {
                fail('Deliveries are recorded on an awarded contract.');
            }
            $date = self::date($d['delivery_date'] ?? '');
            if (!$date) {
                fail('Enter the delivery date.');
            }
            if ($p['noa_received_date'] && $date < $p['noa_received_date']) {
                fail('The delivery date is before the award was received.');
            }
            $kind = in_array($d['kind'] ?? '', ['goods', 'service', 'works'], true) ? $d['kind'] : 'goods';
            $from = !empty($d['from_site_id']) ? (int)$d['from_site_id'] : ($p['site_id'] ? (int)$p['site_id'] : null);
            if ($from && !Access::siteAllowed($from)) {
                fail('You are not allowed to move stock out of that site.');
            }
            Brgy::refreshLines((int)$p['id']);
            $mine = [];
            if ($old) {
                foreach (DB::all('SELECT project_line_id, qty FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $id]) as $x) {
                    $mine[(int)$x['project_line_id']] = (float)$x['qty'];
                }
            }
            $lines = [];
            $anyGoods = false;
            foreach (Brgy::lines((int)$p['id']) as $l) {
                $in = $d['lines'][$l['id']] ?? $d['lines'][(string)$l['id']] ?? null;
                $q = is_array($in) ? self::num($in['qty'] ?? 0) : self::num($in);
                if ($q <= 0) {
                    continue;
                }
                $alreadyOut = (float)$l['qty_delivered'] - (float)$l['qty_rejected'] - ($mine[(int)$l['id']] ?? 0.0);
                $left = round((float)$l['qty'] - $alreadyOut, 4);
                if ($q > $left + 0.00001) {
                    fail('Line ' . $l['line_no'] . ' (' . $l['description'] . '): ' . qty($q) . ' is more than the ' . qty(max(0, $left)) . ' left on the contract. Deliveries beyond the contract need an approved change order.');
                }
                if ($l['item_id'] && Stock::isTracked($l['item_type'])) {
                    $anyGoods = true;
                }
                $lines[] = ['project_line_id' => (int)$l['id'], 'item_id' => $l['item_id'], 'description' => $l['description'], 'unit' => $l['unit'], 'qty' => round($q, 4),
                    'serial_batch' => is_array($in) ? self::s($in['serial_batch'] ?? '', 255) : null, 'remarks' => is_array($in) ? self::s($in['remarks'] ?? '', 255) : null];
            }
            if (!$lines) {
                fail('Enter the quantity delivered on at least one line.');
            }
            if ($anyGoods && !$from) {
                fail('Choose the site the goods leave from.');
            }
            $row = ['project_id' => (int)$p['id'], 'delivery_date' => $date, 'kind' => $kind, 'from_site_id' => $from, 'delivered_to' => self::s($d['delivered_to'] ?? $p['delivery_site'], 255),
                'vehicle' => self::s($d['vehicle'] ?? '', 60), 'received_by_name' => self::s($d['received_by_name'] ?? '', 120), 'received_position' => self::s($d['received_position'] ?? '', 120),
                'days_late' => $p['due_date'] && $date > $p['due_date'] ? (int)round((strtotime($date) - strtotime((string)$p['due_date'])) / 86400) : 0,
                'notes' => self::s($d['notes'] ?? '', 255), 'updated_at' => date('Y-m-d H:i:s')];
            if ($old) {
                DB::update('brgy_deliveries', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $id]);
                Brgy::bumpVersion('brgy_deliveries', $id);
            } else {
                $row += ['dr_no' => Seq::next('brgy_delivery'), 'status' => 'delivered', 'created_by' => Auth::id()];
                $id = DB::insert('brgy_deliveries', $row);
            }
            foreach ($lines as $l) {
                DB::insert('brgy_delivery_lines', $l + ['delivery_id' => $id]);
            }
            BrgyPost::postDelivery($id);
            Brgy::refreshLines((int)$p['id']);
            Brgy::advance((int)$p['id'], 'delivery', 'First delivery recorded');
            $dr = DB::value('SELECT dr_no FROM brgy_deliveries WHERE id = :id', ['id' => $id]);
            Brgy::log($old ? 'edit' : 'create', 'delivery', $id, $dr . ' ' . $p['project_no'], ['before' => $old, 'after' => $row + ['lines' => $lines], 'page_key' => 'brgy.deliveries']);
            return $id;
        });
    }

    /**
     * The entity's inspection and acceptance, from its inspection and acceptance report: per line, how much was accepted
     * and how much rejected. Rejected stocked goods come back to the warehouse. Acceptance can never drop below what
     * has already been billed. $d['lines'] = [delivery line id => ['accepted' => , 'rejected' => , 'remarks' => ]]
     */
    public static function recordInspection(int $deliveryId, array $d, ?array $evidence = null, $lockVersion = null): void
    {
        self::need('brgy.deliveries', 'edit', 'record inspection results');
        DB::tx(function () use ($deliveryId, $d, $evidence, $lockVersion) {
            Brgy::checkVersion('brgy_deliveries', $deliveryId, $lockVersion);
            $dv = DB::row('SELECT * FROM brgy_deliveries WHERE id = :id', ['id' => $deliveryId]);
            if (!$dv || $dv['status'] === 'void') {
                fail('Delivery not found or void.');
            }
            $p = Brgy::mustProject((int)$dv['project_id']);
            $date = self::date($d['acceptance_date'] ?? '') ?? self::date($d['inspection_date'] ?? '');
            if (!$date) {
                fail('Enter the date of the inspection / acceptance.');
            }
            if ($date < $dv['delivery_date']) {
                fail('The acceptance cannot be dated before the delivery.');
            }
            $ref = self::s($d['acceptance_ref'] ?? '', 80);
            Brgy::refreshLines((int)$p['id']);
            $accTotal = 0.0;
            $rejTotal = 0.0;
            $qtyTotal = 0.0;
            $updates = [];
            foreach (DB::all('SELECT dl.*, pl.qty_accepted AS line_accepted, pl.qty_billed AS line_billed FROM brgy_delivery_lines dl JOIN brgy_project_lines pl ON pl.id = dl.project_line_id WHERE dl.delivery_id = :d', ['d' => $deliveryId]) as $l) {
                $in = $d['lines'][$l['id']] ?? $d['lines'][(string)$l['id']] ?? [];
                $acc = round(self::num($in['accepted'] ?? 0), 4);
                $rej = round(self::num($in['rejected'] ?? 0), 4);
                if ($acc < 0 || $rej < 0) {
                    fail('Quantities cannot be negative.');
                }
                if ($acc + $rej > (float)$l['qty'] + 0.00001) {
                    fail($l['description'] . ': accepted and rejected (' . qty($acc + $rej) . ') are more than the ' . qty($l['qty']) . ' delivered.');
                }
                $lineAfter = (float)$l['line_accepted'] - (float)$l['qty_accepted'] + $acc;
                if ($lineAfter < (float)$l['line_billed'] - 0.00001) {
                    fail($l['description'] . ': ' . qty($l['line_billed']) . ' has already been billed; acceptance cannot go below it. Void the billing first.');
                }
                $updates[] = [(int)$l['id'], $acc, $rej, self::s($in['remarks'] ?? $l['remarks'], 255)];
                $accTotal += $acc;
                $rejTotal += $rej;
                $qtyTotal += (float)$l['qty'];
            }
            if ($accTotal > 0 && !$ref) {
                fail('Enter the number of the inspection and acceptance report (or the receiving copy) issued by the entity.');
            }
            $evDoc = null;
            if (self::fileGiven($evidence)) {
                $evDoc = BrgyDocs::store($evidence, ['scope' => 'project', 'project_id' => $p['id'], 'doc_type' => 'iar', 'title' => 'Inspection and acceptance ' . ($ref ?: $dv['dr_no']),
                    'doc_no' => $ref, 'issued_by' => $p['entity_name'], 'issue_date' => $date, 'related_type' => 'delivery', 'related_id' => $deliveryId, 'status' => 'approved']);
            } elseif (!empty($d['acceptance_doc_id'])) {
                $evDoc = (int)self::projectDoc((int)$p['id'], (int)$d['acceptance_doc_id'], ['iar', 'dr', 'accomplishment'])['id'];
            } elseif ($dv['acceptance_doc_id']) {
                $evDoc = (int)$dv['acceptance_doc_id'];
            }
            if ($accTotal > 0 && !$evDoc && Brgy::on('brgy_require_acceptance_doc')) {
                fail('Attach the signed inspection and acceptance report (or the signed delivery receipt). Acceptance is recorded from the entity\'s own paper.');
            }
            foreach ($updates as [$lid, $acc, $rej, $rem]) {
                DB::update('brgy_delivery_lines', ['qty_accepted' => $acc, 'qty_rejected' => $rej, 'remarks' => $rem], 'id = :id', ['id' => $lid]);
            }
            $status = $accTotal <= 0.00001 && $rejTotal <= 0.00001 ? 'delivered' : ($rejTotal <= 0.00001 && abs($accTotal - $qtyTotal) < 0.00001 ? 'accepted' : ($accTotal <= 0.00001 ? 'rejected' : 'partial'));
            DB::update('brgy_deliveries', ['status' => $status, 'inspection_date' => self::date($d['inspection_date'] ?? '') ?? $date, 'acceptance_date' => $date, 'acceptance_ref' => $ref,
                'inspectors' => self::s($d['inspectors'] ?? '', 255), 'acceptance_doc_id' => $evDoc, 'inspection_notes' => self::s($d['inspection_notes'] ?? '', 5000),
                'punch_list' => self::s($d['punch_list'] ?? '', 5000), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $deliveryId]);
            Brgy::bumpVersion('brgy_deliveries', $deliveryId);
            BrgyPost::postReturn($deliveryId);
            Brgy::refreshLines((int)$p['id']);
            Brgy::event((int)$p['id'], 'inspection', 'Inspection of ' . $dv['dr_no'] . ': ' . qty($accTotal) . ' accepted' . ($rejTotal > 0 ? ', ' . qty($rejTotal) . ' rejected' : ''),
                ['event_at' => $date . ' 15:00:00', 'direction' => 'in', 'doc_id' => $evDoc, 'details' => trim(($ref ? 'Report ' . $ref . '. ' : '') . (string)($d['inspection_notes'] ?? ''))]);
            Brgy::log('inspection', 'delivery', $deliveryId, $dv['dr_no'], ['reason' => 'Accepted ' . qty($accTotal) . ', rejected ' . qty($rejTotal) . ($ref ? ' (' . $ref . ')' : ''), 'page_key' => 'brgy.deliveries']);
        });
    }

    public static function voidDelivery(int $deliveryId, string $reason): void
    {
        self::need('brgy.deliveries', 'delete', 'void deliveries');
        if (trim($reason) === '') {
            fail('Enter the reason.');
        }
        DB::tx(function () use ($deliveryId, $reason) {
            $dv = DB::row('SELECT * FROM brgy_deliveries WHERE id = :id', ['id' => $deliveryId]);
            if (!$dv || $dv['status'] === 'void') {
                fail('Delivery not found or already void.');
            }
            Brgy::refreshLines((int)$dv['project_id']);
            foreach (DB::all('SELECT dl.*, pl.qty_accepted AS line_accepted, pl.qty_billed AS line_billed FROM brgy_delivery_lines dl JOIN brgy_project_lines pl ON pl.id = dl.project_line_id WHERE dl.delivery_id = :d', ['d' => $deliveryId]) as $l) {
                if ((float)$l['line_accepted'] - (float)$l['qty_accepted'] < (float)$l['line_billed'] - 0.00001) {
                    fail($l['description'] . ' of this delivery has been billed. Void the billing first.');
                }
            }
            BrgyPost::voidPosting('delivery', $deliveryId, 'return', 'Delivery ' . $dv['dr_no'] . ' voided: ' . $reason);
            BrgyPost::voidPosting('delivery', $deliveryId, 'transfer', 'Delivery ' . $dv['dr_no'] . ' voided: ' . $reason);
            DB::update('brgy_deliveries', ['status' => 'void', 'notes' => mb_substr(trim(((string)$dv['notes']) . ' Void: ' . $reason), 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $deliveryId]);
            Brgy::bumpVersion('brgy_deliveries', $deliveryId);
            Brgy::refreshLines((int)$dv['project_id']);
            Brgy::log('void', 'delivery', $deliveryId, $dv['dr_no'], ['reason' => $reason, 'page_key' => 'brgy.deliveries']);
        });
    }

    // ================================================================== change orders

    /** $d['lines'] = [['project_line_id' => id or '', 'description', 'unit', 'line_kind', 'item_id', 'qty_change', 'unit_price', 'tax_code_id'], ...] */
    public static function saveChangeOrder(int $projectId, array $d, ?int $id = null, $lockVersion = null): int
    {
        self::need('brgy.projects', 'edit', 'prepare change orders');
        return DB::tx(function () use ($projectId, $d, $id, $lockVersion) {
            $p = Brgy::mustProject($projectId);
            if ($p['status'] !== 'awarded') {
                fail('Change orders are made on an awarded contract.');
            }
            $isNew = !$id;
            if ($id) {
                Brgy::checkVersion('brgy_change_orders', $id, $lockVersion);
                $old = DB::row('SELECT * FROM brgy_change_orders WHERE id = :id AND project_id = :p', ['id' => $id, 'p' => $projectId]);
                if (!$old || !in_array($old['status'], ['draft', 'returned'], true)) {
                    fail('Only a draft or returned change order can be changed.');
                }
            }
            $title = self::s($d['title'] ?? '', 200);
            if (!$title) {
                fail('Enter what the change order is about.');
            }
            $lines = [];
            $total = 0.0;
            $plines = [];
            foreach (Brgy::lines($projectId) as $l) {
                $plines[(int)$l['id']] = $l;
            }
            foreach ((array)($d['lines'] ?? []) as $r) {
                $q = self::num($r['qty_change'] ?? 0);
                $plid = !empty($r['project_line_id']) ? (int)$r['project_line_id'] : null;
                $desc = trim((string)($r['description'] ?? ''));
                if ($q == 0.0 && $desc === '') {
                    continue;
                }
                if ($plid && !isset($plines[$plid])) {
                    fail('A changed line does not belong to this contract.');
                }
                $base = $plid ? $plines[$plid] : null;
                $price = $base ? (float)$base['unit_price'] : self::num($r['unit_price'] ?? 0);
                if ($base && $q < 0 && (float)$base['qty'] + $q < max((float)$base['qty_accepted'], (float)$base['qty_delivered'] - (float)$base['qty_rejected']) - 0.00001) {
                    fail($base['description'] . ': the quantity cannot go below what was already delivered or accepted.');
                }
                if (!$base && ($desc === '' || $q <= 0 || $price <= 0)) {
                    fail('A new line needs a description, a quantity and a price.');
                }
                $amt = r2($q * $price);
                $total += $amt;
                $lines[] = ['project_line_id' => $plid, 'line_kind' => $base['line_kind'] ?? (isset(Brgy::LINE_KINDS[$r['line_kind'] ?? '']) ? $r['line_kind'] : 'goods'),
                    'item_id' => $base['item_id'] ?? (!empty($r['item_id']) ? (int)$r['item_id'] : null), 'description' => mb_substr($base['description'] ?? $desc, 0, 255),
                    'unit' => $base['unit'] ?? self::s($r['unit'] ?? '', 20), 'qty_change' => round($q, 4), 'unit_price' => round($price, 4), 'amount' => $amt,
                    'tax_code_id' => $base['tax_code_id'] ?? (!empty($r['tax_code_id']) ? (int)$r['tax_code_id'] : Brgy::defaultTaxCode())];
            }
            $days = max(0, (int)($d['days_extension'] ?? 0));
            if (!$lines && $days === 0) {
                fail('Enter the lines changed or the days of extension.');
            }
            $row = ['project_id' => $projectId, 'co_date' => self::date($d['co_date'] ?? '') ?? today(), 'title' => $title, 'reason' => self::s($d['reason'] ?? '', 5000),
                'amount_change' => r2($total), 'days_extension' => $days, 'updated_at' => date('Y-m-d H:i:s')];
            if ($id) {
                DB::update('brgy_change_orders', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM brgy_co_lines WHERE co_id = :c', ['c' => $id]);
                Brgy::bumpVersion('brgy_change_orders', $id);
            } else {
                $row += ['co_no' => Seq::next('brgy_co'), 'status' => 'draft', 'created_by' => Auth::id()];
                $id = DB::insert('brgy_change_orders', $row);
            }
            foreach ($lines as $l) {
                DB::insert('brgy_co_lines', $l + ['co_id' => $id]);
            }
            Brgy::log($isNew ? 'create' : 'edit', 'change_order', $id, $p['project_no'] . ' ' . $title, ['amount' => $total, 'after' => $row + ['lines' => $lines], 'page_key' => 'brgy.projects']);
            return $id;
        });
    }

    public static function changeOrderStep(int $coId, string $action, string $remarks = ''): void
    {
        DB::tx(function () use ($coId, $action, $remarks) {
            $co = DB::row('SELECT * FROM brgy_change_orders WHERE id = :id', ['id' => $coId]);
            if (!$co) {
                fail('Change order not found.');
            }
            if ($action === 'reopen' && $co['status'] === 'applied') {
                fail('An applied change order stays; make another one to change it back.');
            }
            Brgy::step('change_order', $co, $action, $remarks, Brgy::mustProject((int)$co['project_id']));
        });
    }

    /**
     * Applies an approved change order to the contract once the entity's own approval is on file: the lines change, the
     * contract amount and the due date follow. Until then it does not count anywhere.
     */
    public static function applyChangeOrder(int $coId, array $d, ?array $evidence = null): void
    {
        self::need('brgy.projects', 'edit', 'apply change orders');
        DB::tx(function () use ($coId, $d, $evidence) {
            $co = DB::row('SELECT * FROM brgy_change_orders WHERE id = :id FOR UPDATE', ['id' => $coId]);
            if (!$co || $co['status'] !== 'approved') {
                fail('Only an approved change order is applied.');
            }
            $p = Brgy::mustProject((int)$co['project_id']);
            $ref = self::s($d['external_ref'] ?? '', 80);
            $date = self::date($d['external_date'] ?? '');
            if (!$ref || !$date) {
                fail('Enter the reference and date of the entity\'s approval of the change (variation order, amended PO, extension).');
            }
            $doc = null;
            if (self::fileGiven($evidence)) {
                $doc = BrgyDocs::store($evidence, ['scope' => 'project', 'project_id' => $p['id'], 'doc_type' => 'change_order', 'title' => 'Approved change ' . $ref, 'doc_no' => $ref,
                    'issued_by' => $p['entity_name'], 'issue_date' => $date, 'related_type' => 'change_order', 'related_id' => $coId, 'status' => 'approved']);
            } elseif (!empty($d['evidence_doc_id'])) {
                $doc = (int)self::projectDoc((int)$p['id'], (int)$d['evidence_doc_id'])['id'];
            }
            if (!$doc) {
                fail('Attach the entity\'s approval of the change. An unapproved change does not change the contract.');
            }
            foreach (DB::all('SELECT * FROM brgy_co_lines WHERE co_id = :c', ['c' => $coId]) as $l) {
                if ($l['project_line_id']) {
                    DB::run('UPDATE brgy_project_lines SET qty = qty + :q, amount = ROUND((qty) * unit_price, 2) WHERE id = :id', ['q' => $l['qty_change'], 'id' => $l['project_line_id']]);
                } else {
                    $n = (int)DB::value('SELECT COALESCE(MAX(line_no), 0) + 1 FROM brgy_project_lines WHERE project_id = :p', ['p' => $p['id']]);
                    DB::insert('brgy_project_lines', ['project_id' => $p['id'], 'line_no' => $n, 'line_kind' => $l['line_kind'], 'item_id' => $l['item_id'], 'description' => $l['description'],
                        'unit' => $l['unit'], 'bid_qty' => 0, 'qty' => $l['qty_change'], 'unit_price' => $l['unit_price'], 'amount' => $l['amount'], 'tax_code_id' => $l['tax_code_id'], 'co_id' => $coId]);
                }
            }
            $set = ['co_amount' => r2((float)$p['co_amount'] + (float)$co['amount_change'])];
            if ((int)$co['days_extension'] > 0 && $p['due_date']) {
                $set['due_date'] = date('Y-m-d', strtotime($p['due_date'] . ' +' . (int)$co['days_extension'] . ' days'));
            }
            DB::update('brgy_projects', $set, 'id = :id', ['id' => $p['id']]);
            Brgy::bumpVersion('brgy_projects', (int)$p['id']);
            DB::update('brgy_change_orders', ['status' => 'applied', 'external_ref' => $ref, 'external_date' => $date, 'evidence_doc_id' => $doc, 'applied_at' => date('Y-m-d H:i:s'), 'applied_by' => Auth::id()], 'id = :id', ['id' => $coId]);
            DB::insert('brgy_workflow', ['project_id' => $p['id'], 'object_type' => 'change_order', 'object_id' => $coId, 'action' => 'apply', 'from_status' => 'approved', 'to_status' => 'applied',
                'user_id' => Auth::id(), 'amount' => $co['amount_change'], 'remarks' => 'Entity approval ' . $ref]);
            Brgy::event((int)$p['id'], 'notice', 'Change order ' . $co['co_no'] . ' approved by the entity (' . $ref . ')', ['event_at' => $date . ' 10:00:00', 'direction' => 'in', 'doc_id' => $doc,
                'details' => money($co['amount_change']) . ($co['days_extension'] ? ', ' . $co['days_extension'] . ' day(s) extension' : '')]);
            Brgy::log('apply', 'change_order', $coId, $co['co_no'] . ' ' . $p['project_no'], ['amount' => (float)$co['amount_change'], 'reason' => 'Entity approval ' . $ref, 'page_key' => 'brgy.projects']);
        });
    }

    // ================================================================== billing

    /** The pack of a billing: each required paper and whether it is on file (a document of the billing / project, or a final form) */
    public static function packStatus(array $b): array
    {
        $out = [];
        foreach (preg_split('/\R/', Brgy::setting('brgy_billing_pack')) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }
            [$type, $name] = $parts;
            $req = ($parts[2] ?? '1') === '1';
            $projectWide = in_array($type, ['po', 'contract', 'noa'], true);
            // a contract and a purchase order stand for each other in the pack
            [$tin, $tp] = DB::in('t', in_array($type, ['po', 'contract'], true) ? ['po', 'contract'] : [$type]);
            $doc = DB::row("SELECT id, title FROM brgy_documents WHERE doc_type IN $tin AND status <> 'void' AND is_current = 1 AND project_id = :p
                             AND (" . ($projectWide ? '1 = 1' : "(related_type = 'billing' AND related_id = :b)") . ') ORDER BY id DESC LIMIT 1',
                $tp + ['p' => $b['project_id']] + ($projectWide ? [] : ['b' => $b['id']]));
            if (!$doc && $type === 'dr') {
                // the signed delivery receipts / acceptance papers of the deliveries billed
                $doc = DB::row("SELECT d.id, d.title FROM brgy_documents d JOIN brgy_deliveries dv ON dv.acceptance_doc_id = d.id
                                 WHERE dv.project_id = :p AND dv.status IN ('accepted', 'partial') LIMIT 1", ['p' => $b['project_id']]);
            }
            if (!$doc && $type === 'iar') {
                $doc = DB::row("SELECT d.id, d.title FROM brgy_documents d WHERE d.project_id = :p AND d.doc_type = 'iar' AND d.status <> 'void' ORDER BY d.id DESC LIMIT 1", ['p' => $b['project_id']]);
            }
            $form = null;
            if (!$doc) {
                $form = DB::row("SELECT id, form_no, title FROM brgy_forms WHERE doc_type = :t AND status = 'final' AND project_id = :p AND (" . ($projectWide ? '1 = 1' : "(related_type = 'billing' AND related_id = :b)") . ') ORDER BY id DESC LIMIT 1',
                    ['t' => $type, 'p' => $b['project_id']] + ($projectWide ? [] : ['b' => $b['id']]));
            }
            if (!$doc && !$form && $type === 'invoice' && $b['invoice_txn_id'] && $b['si_no']) {
                $doc = ['id' => null, 'title' => 'Sales invoice ' . $b['si_no'] . ' (recorded on the billing)'];
            }
            $out[] = ['type' => $type, 'name' => $name, 'required' => $req, 'ok' => (bool)($doc || $form), 'doc' => $doc, 'form' => $form];
        }
        return $out;
    }

    /**
     * A billing pack for accepted quantities not billed yet. $d['lines'] = [project line id => qty]. Prices come from the
     * contract; with VAT-inclusive prices each line is split into its amount and VAT. The expected taxes withheld,
     * retention and liquidated damages are worked out for the statement of account.
     */
    public static function saveBilling(array $d, ?int $id = null, $lockVersion = null): int
    {
        self::need('brgy.billing', $id ? 'edit' : 'create', ($id ? 'change' : 'prepare') . ' billing packs');
        return DB::tx(function () use ($d, $id, $lockVersion) {
            $old = null;
            if ($id) {
                Brgy::checkVersion('brgy_billings', $id, $lockVersion);
                $old = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $id]);
                if (!in_array($old['status'], ['draft', 'returned'], true)) {
                    fail('Only a draft or returned billing can be changed (this one is ' . (Brgy::WF[$old['status']] ?? $old['status']) . ').');
                }
                $d['project_id'] = $old['project_id'];
            }
            $p = Brgy::mustProject((int)($d['project_id'] ?? 0));
            if ($p['status'] !== 'awarded') {
                fail('Only an awarded contract is billed.');
            }
            if (BrgySop::holdsBilling($p)) {
                fail('The payment of ' . $p['project_no'] . ' has not been released yet: the sales invoice, official receipt and BIR Form 2307 are not prepared until it is (special case F of the SOP). Record the cheque on the Process tab first.');
            }
            $date = self::date($d['billing_date'] ?? '') ?? today();
            $si = self::s($d['si_no'] ?? '', 40);
            if ($si && ($dup = DB::value("SELECT billing_no FROM brgy_billings WHERE si_no = :s AND status <> 'void'" . ($id ? ' AND id <> :id' : ''), ['s' => $si] + ($id ? ['id' => $id] : [])))) {
                fail('Sales invoice number ' . $si . ' is already used on billing ' . $dup . '.');
            }
            Brgy::refreshLines((int)$p['id']);
            $mine = [];
            if ($old) {
                foreach (DB::all('SELECT project_line_id, qty FROM brgy_billing_lines WHERE billing_id = :b', ['b' => $id]) as $x) {
                    $mine[(int)$x['project_line_id']] = (float)$x['qty'];
                }
            }
            $lines = [];
            $gross = 0.0;
            $net = 0.0;
            $vat = 0.0;
            $lateDays = (int)DB::value("SELECT COALESCE(MAX(days_late), 0) FROM brgy_deliveries WHERE project_id = :p AND status IN ('accepted', 'partial')", ['p' => $p['id']]);
            $ld = 0.0;
            foreach (Brgy::lines((int)$p['id']) as $l) {
                $q = round(self::num($d['lines'][$l['id']] ?? $d['lines'][(string)$l['id']] ?? 0), 4);
                if ($q <= 0) {
                    continue;
                }
                $avail = round((float)$l['qty_accepted'] - ((float)$l['qty_billed'] - ($mine[(int)$l['id']] ?? 0.0)), 4);
                if ($q > $avail + 0.00001) {
                    fail('Line ' . $l['line_no'] . ' (' . $l['description'] . '): only ' . qty(max(0, $avail)) . ' accepted and not yet billed. Billing is allowed for what the entity accepted.');
                }
                $g = r2($q * (float)$l['unit_price']);
                [$n, $t] = Brgy::splitVat($g, $l['tax_code_id'] ? (int)$l['tax_code_id'] : null);
                if (!Brgy::on('brgy_prices_vat_inclusive')) {
                    $g = r2($n + $t);
                }
                $lines[] = ['project_line_id' => (int)$l['id'], 'line_kind' => $l['line_kind'], 'item_id' => $l['item_id'], 'account_id' => $l['account_id'], 'description' => $l['description'],
                    'unit' => $l['unit'], 'qty' => $q, 'unit_price' => $l['unit_price'], 'gross' => $g, 'net' => $n, 'tax' => $t, 'tax_code_id' => $l['tax_code_id']];
                $gross += $g;
                $net += $n;
                $vat += $t;
                $ld += $g * (float)$p['ld_rate_pct'] / 100 * $lateDays;
            }
            if (!$lines) {
                fail('Enter the quantity to bill on at least one line (accepted quantities only).');
            }
            $gross = r2($gross);
            $billedOthers = r2(DB::value("SELECT COALESCE(SUM(gross_amount), 0) FROM brgy_billings WHERE project_id = :p AND status <> 'void'" . ($id ? ' AND id <> :id' : ''), ['p' => $p['id']] + ($id ? ['id' => $id] : [])));
            if ($billedOthers + $gross > Brgy::contractTotal($p) + 0.004) {
                fail('Billed in total (' . money($billedOthers + $gross) . ') would be more than the contract amount (' . money(Brgy::contractTotal($p)) . ').');
            }
            $row = ['project_id' => (int)$p['id'], 'billing_date' => $date, 'billing_type' => isset(Brgy::BILLING_TYPES[$d['billing_type'] ?? '']) ? $d['billing_type'] : 'partial',
                'period_from' => self::date($d['period_from'] ?? ''), 'period_to' => self::date($d['period_to'] ?? ''), 'si_no' => $si,
                'gross_amount' => $gross, 'net_amount' => r2($net), 'vat_amount' => r2($vat), 'retention_expected' => r2($gross * (float)$p['retention_pct'] / 100),
                'ld_expected' => trim((string)($d['ld_expected'] ?? '')) !== '' ? r2(self::num($d['ld_expected'])) : r2($ld),
                'notes' => self::s($d['notes'] ?? '', 255), 'updated_at' => date('Y-m-d H:i:s')];
            if ($old) {
                DB::update('brgy_billings', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM brgy_billing_lines WHERE billing_id = :b', ['b' => $id]);
                Brgy::bumpVersion('brgy_billings', $id);
            } else {
                $row += ['billing_no' => Seq::next('brgy_billing'), 'status' => 'draft', 'created_by' => Auth::id()];
                $id = DB::insert('brgy_billings', $row);
            }
            foreach ($lines as $l) {
                DB::insert('brgy_billing_lines', $l + ['billing_id' => $id]);
            }
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => $id]);
            $exp = BrgyPost::expectedWithholding($b, (float)$b['gross_amount']);
            DB::update('brgy_billings', ['ewt_expected' => $exp['ewt'], 'vatwh_expected' => $exp['vat']], 'id = :id', ['id' => $id]);
            Brgy::refreshLines((int)$p['id']);
            Brgy::log($old ? 'edit' : 'create', 'billing', $id, $b['billing_no'] . ' ' . $p['project_no'], ['amount' => $gross, 'before' => $old, 'after' => $row + ['lines' => $lines], 'page_key' => 'brgy.billing']);
            return $id;
        });
    }

    public static function billingStep(int $billingId, string $action, string $remarks = ''): void
    {
        DB::tx(function () use ($billingId, $action, $remarks) {
            $b = DB::row('SELECT b.*, p.project_no FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE b.id = :id', ['id' => $billingId]);
            if (!$b) {
                fail('Billing not found.');
            }
            if ($action === 'reopen' && $b['status'] !== 'approved') {
                fail('An invoiced billing is not reopened; void it instead.');
            }
            Brgy::step('billing', $b, $action, $remarks, Brgy::mustProject((int)$b['project_id']));
        });
    }

    /** Posts the invoice of an approved billing (the controlled accounting handoff) */
    public static function invoiceBilling(int $billingId): int
    {
        self::need('brgy.billing', 'create', 'issue invoices');
        return BrgyPost::issueInvoice($billingId);
    }

    /** The pack goes to the entity: complete (setting), recorded with how, when and to whom */
    public static function submitBilling(int $billingId, array $d, ?array $proof = null): void
    {
        self::need('brgy.billing', 'edit', 'submit billing packs');
        DB::tx(function () use ($billingId, $d, $proof) {
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id FOR UPDATE', ['id' => $billingId]);
            if (!$b || $b['status'] !== 'invoiced') {
                fail('Only an invoiced billing is submitted to the entity.');
            }
            $missing = array_filter(self::packStatus($b), fn($x) => $x['required'] && !$x['ok']);
            if ($missing && Brgy::on('brgy_require_pack_complete')) {
                fail('The billing pack is not complete. Missing: ' . implode(', ', array_column($missing, 'name')) . '.');
            }
            $subId = self::recordSubmission((int)$b['project_id'], $d + ['sub_type' => 'billing', 'related_type' => 'billing', 'related_id' => $billingId], $proof);
            $at = DB::value('SELECT submitted_at FROM brgy_submissions WHERE id = :id', ['id' => $subId]);
            DB::update('brgy_billings', ['status' => 'submitted', 'submitted_at' => $at, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $billingId]);
            Brgy::bumpVersion('brgy_billings', $billingId);
            DB::insert('brgy_workflow', ['project_id' => $b['project_id'], 'object_type' => 'billing', 'object_id' => $billingId, 'action' => 'release', 'from_status' => 'invoiced', 'to_status' => 'submitted',
                'user_id' => Auth::id(), 'amount' => $b['gross_amount'], 'remarks' => 'Submitted ' . fdatetime($at)]);
        });
    }

    /** The entity's acknowledgment of the billing (receiving copy / reference) */
    public static function acknowledgeBilling(int $billingId, array $d, ?array $proof = null): void
    {
        self::need('brgy.billing', 'edit', 'record acknowledgments');
        DB::tx(function () use ($billingId, $d, $proof) {
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id FOR UPDATE', ['id' => $billingId]);
            if (!$b || $b['status'] !== 'submitted') {
                fail('Only a submitted billing is acknowledged.');
            }
            $at = self::dt($d['acknowledged_at'] ?? '');
            $by = self::s($d['received_by'] ?? '', 120);
            if (!$at || !$by) {
                fail('Enter when and by whom the billing was received.');
            }
            if ($at < (string)$b['submitted_at']) {
                fail('The acknowledgment cannot be before the submission.');
            }
            $doc = null;
            if (self::fileGiven($proof)) {
                $doc = BrgyDocs::store($proof, ['scope' => 'project', 'project_id' => $b['project_id'], 'doc_type' => 'receiving_copy', 'title' => 'Receiving copy of billing ' . $b['billing_no'],
                    'related_type' => 'billing', 'related_id' => $billingId, 'status' => 'approved']);
            }
            DB::update('brgy_billings', ['status' => 'acknowledged', 'acknowledged_at' => $at, 'received_by' => $by, 'ack_ref' => self::s($d['ack_ref'] ?? '', 80), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $billingId]);
            Brgy::bumpVersion('brgy_billings', $billingId);
            DB::run("UPDATE brgy_submissions SET received_by = :r, received_at = :a, ack_ref = :k, proof_doc_id = COALESCE(:d, proof_doc_id) WHERE related_type = 'billing' AND related_id = :b AND received_at IS NULL",
                ['r' => $by, 'a' => $at, 'k' => self::s($d['ack_ref'] ?? '', 80), 'd' => $doc, 'b' => $billingId]);
            DB::insert('brgy_workflow', ['project_id' => $b['project_id'], 'object_type' => 'billing', 'object_id' => $billingId, 'action' => 'complete', 'from_status' => 'submitted', 'to_status' => 'acknowledged',
                'user_id' => Auth::id(), 'remarks' => 'Received by ' . $by]);
            Brgy::log('acknowledged', 'billing', $billingId, $b['billing_no'], ['reason' => 'Received by ' . $by . ' on ' . fdatetime($at), 'page_key' => 'brgy.billing']);
        });
    }

    public static function voidBilling(int $billingId, string $reason): void
    {
        self::need('brgy.billing', 'delete', 'void billings');
        if (trim($reason) === '') {
            fail('Enter the reason.');
        }
        DB::tx(function () use ($billingId, $reason) {
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id FOR UPDATE', ['id' => $billingId]);
            if (!$b || $b['status'] === 'void') {
                fail('Billing not found or already void.');
            }
            if ((int)DB::value("SELECT COUNT(*) FROM brgy_collections WHERE billing_id = :b AND status = 'posted'", ['b' => $billingId])) {
                fail('Collections are recorded on this billing. Void them first.');
            }
            BrgyPost::voidPosting('billing', $billingId, 'invoice', 'Billing ' . $b['billing_no'] . ' voided: ' . $reason);
            DB::update('brgy_billings', ['status' => 'void', 'notes' => mb_substr(trim(((string)$b['notes']) . ' Void: ' . $reason), 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $billingId]);
            Brgy::bumpVersion('brgy_billings', $billingId);
            Brgy::refreshLines((int)$b['project_id']);
            DB::insert('brgy_workflow', ['project_id' => $b['project_id'], 'object_type' => 'billing', 'object_id' => $billingId, 'action' => 'cancel', 'from_status' => $b['status'], 'to_status' => 'void',
                'user_id' => Auth::id(), 'remarks' => mb_substr($reason, 0, 255)]);
            Brgy::log('void', 'billing', $billingId, $b['billing_no'], ['reason' => $reason, 'amount' => (float)$b['gross_amount'], 'page_key' => 'brgy.billing']);
        });
    }

    // ================================================================== collections

    /**
     * Money received. kind = billing: against a billing, with each tax and deduction kept apart; the taxes withheld are
     * checked against the Withholding Tax Table and a difference needs an explanation. kind = retention: retention released.
     */
    public static function saveCollection(array $d): int
    {
        self::need('brgy.collections', 'create', 'record collections');
        return DB::tx(function () use ($d) {
            $kind = ($d['kind'] ?? 'billing') === 'retention' ? 'retention' : 'billing';
            $date = self::date($d['collection_date'] ?? '');
            if (!$date) {
                fail('Enter the date the payment was received.');
            }
            $method = isset(Brgy::COLLECTION_METHODS[$d['method'] ?? '']) ? $d['method'] : 'check';
            $row = ['kind' => $kind, 'collection_date' => $date, 'method' => $method, 'reference_no' => self::s($d['reference_no'] ?? '', 80), 'check_no' => self::s($d['check_no'] ?? '', 40),
                'check_date' => self::date($d['check_date'] ?? ''), 'bank_name' => self::s($d['bank_name'] ?? '', 80), 'receipt_no' => self::s($d['receipt_no'] ?? '', 40),
                'cert_2307' => !empty($d['cert_2307']) ? 1 : 0, 'notes' => self::s($d['notes'] ?? '', 255), 'status' => 'posted', 'created_by' => Auth::id(),
                'deposit_account_id' => !empty($d['deposit_account_id']) ? (int)$d['deposit_account_id'] : null];
            if ($method === 'check' && !$row['check_no']) {
                fail('Enter the check number.');
            }
            if ($method === 'lddap_ada' && !$row['reference_no']) {
                fail('Enter the LDDAP-ADA number.');
            }
            if ($kind === 'retention') {
                $p = Brgy::mustProject((int)($d['project_id'] ?? 0));
                $row += ['project_id' => (int)$p['id'], 'cash_received' => r2(self::num($d['cash_received'] ?? 0))];
                $row['collection_no'] = Seq::next('brgy_collection');
                $id = DB::insert('brgy_collections', $row);
                BrgyPost::postCollection($id);
                Brgy::log('create', 'collection', $id, $row['collection_no'] . ' retention ' . $p['project_no'], ['amount' => $row['cash_received'], 'after' => $row, 'page_key' => 'brgy.collections']);
                return $id;
            }
            $b = DB::row('SELECT * FROM brgy_billings WHERE id = :id', ['id' => (int)($d['billing_id'] ?? 0)]);
            if (!$b) {
                fail('Choose the billing being paid.');
            }
            $p = Brgy::mustProject((int)$b['project_id']);
            if ($date < $b['billing_date']) {
                fail('The payment is dated before the billing.');
            }
            $amt = fn($k) => r2(max(0, self::num($d[$k] ?? 0)));
            $row += ['project_id' => (int)$p['id'], 'billing_id' => (int)$b['id'], 'cash_received' => $amt('cash_received'), 'ewt_amount' => $amt('ewt_amount'), 'vatwh_amount' => $amt('vatwh_amount'),
                'retention_amount' => $amt('retention_amount'), 'ld_amount' => $amt('ld_amount'), 'other_deduction' => $amt('other_deduction'), 'other_note' => self::s($d['other_note'] ?? '', 160),
                'other_account_id' => !empty($d['other_account_id']) ? (int)$d['other_account_id'] : null, 'bank_charges' => $amt('bank_charges')];
            $row['gross_applied'] = r2($row['cash_received'] + $row['ewt_amount'] + $row['vatwh_amount'] + $row['retention_amount'] + $row['ld_amount'] + $row['other_deduction'] + $row['bank_charges']);
            if ($row['gross_applied'] <= 0) {
                fail('Enter the cash received and the amounts the entity deducted.');
            }
            // the taxes withheld: compared with the Withholding Tax Table on the part that goes to the invoice
            $tg = BrgyPost::collectionTargets($b);
            $toCharges = 0.0;
            foreach ($tg['charges'] as $ch) {
                $toCharges += (float)$ch['open_balance'];
            }
            $onInvoice = max(0.0, r2($row['gross_applied'] - min($row['cash_received'], $toCharges)));
            $exp = BrgyPost::expectedWithholding($b, $onInvoice);
            $variance = r2(($row['ewt_amount'] + $row['vatwh_amount']) - ($exp['ewt'] + $exp['vat']));
            $row['wht_variance'] = $variance;
            $row['variance_note'] = self::s($d['variance_note'] ?? '', 255);
            if (abs($variance) > 0.05 && !$row['variance_note']) {
                fail('The taxes withheld (income tax ' . money($row['ewt_amount']) . ', VAT ' . money($row['vatwh_amount']) . ') differ from the Withholding Tax Table (income tax ' . money($exp['ewt'])
                    . ', VAT ' . money($exp['vat']) . ') by ' . money($variance) . '. Correct the amounts, or explain the difference (e.g. the entity\'s 2307 shows other rates).');
            }
            $row['collection_no'] = Seq::next('brgy_collection');
            $id = DB::insert('brgy_collections', $row);
            BrgyPost::postCollection($id);
            if (!empty($d['cert_2307_file']) && self::fileGiven($d['cert_2307_file'])) {
                self::attach2307($id, $d['cert_2307_file']);
            }
            if ($b['status'] === 'invoiced') {
                // paid before it was formally submitted: the payment itself shows the entity received it
                DB::insert('brgy_workflow', ['project_id' => $p['id'], 'object_type' => 'billing', 'object_id' => $b['id'], 'action' => 'note', 'from_status' => 'invoiced', 'to_status' => 'invoiced',
                    'user_id' => Auth::id(), 'remarks' => 'Paid before the submission was recorded']);
            }
            Brgy::log('create', 'collection', $id, $row['collection_no'] . ' ' . $b['billing_no'], ['amount' => $row['cash_received'], 'after' => $row, 'reason' => $variance != 0.0 ? 'Withheld tax difference ' . money($variance) . ': ' . $row['variance_note'] : null, 'page_key' => 'brgy.collections']);
            return $id;
        });
    }

    /** The BIR Form 2307 the entity issued for the taxes it withheld on a collection */
    public static function attach2307(int $collectionId, ?array $file, ?int $docId = null): void
    {
        self::need('brgy.collections', 'edit', 'file certificates');
        $c = DB::row('SELECT * FROM brgy_collections WHERE id = :id', ['id' => $collectionId]);
        if (!$c) {
            fail('Collection not found.');
        }
        if (self::fileGiven($file)) {
            $docId = BrgyDocs::store($file, ['scope' => 'project', 'project_id' => $c['project_id'], 'doc_type' => 'form_2307', 'title' => 'BIR Form 2307 for ' . $c['collection_no'],
                'related_type' => 'collection', 'related_id' => $collectionId, 'status' => 'approved']);
        } elseif ($docId) {
            $docId = (int)self::projectDoc((int)$c['project_id'], $docId, ['form_2307'])['id'];
        }
        DB::update('brgy_collections', ['cert_2307' => 1, 'cert_2307_doc_id' => $docId, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $collectionId]);
        Brgy::log('cert_2307', 'collection', $collectionId, $c['collection_no'], ['reason' => $docId ? 'Document #' . $docId : 'Marked received', 'page_key' => 'brgy.collections']);
    }

    // ================================================================== forms from templates

    public static function createForm(int $templateId, ?int $projectId, ?array $related = null, string $title = ''): int
    {
        self::need('brgy.forms', 'create', 'prepare forms');
        $t = DB::row('SELECT * FROM brgy_templates WHERE id = :id AND is_active = 1', ['id' => $templateId]);
        if (!$t) {
            fail('Choose a template.');
        }
        $p = $projectId ? Brgy::mustProject($projectId) : null;
        return DB::tx(function () use ($t, $p, $related, $title) {
            if ($p && in_array($t['doc_type'], ['invoice', 'or_ar', 'form_2307'], true) && BrgySop::holdsBilling($p)) {
                fail('The payment has not been released yet: the ' . $t['name'] . ' is not prepared until it is (special case F of the SOP).');
            }
            $no = Seq::next('brgy_form');
            $L = BrgyLayout::layout($t);
            $ctx = BrgyDocs::context($p, $related, $no, $L);
            $body = BrgyDocs::render($t['body'], $ctx);
            $id = DB::insert('brgy_forms', [
                'form_no' => $no, 'project_id' => $p['id'] ?? null, 'template_id' => $t['id'], 'template_version' => $t['version_no'],
                'title' => mb_substr(trim($title) !== '' ? $title : $t['name'] . ($p ? ' - ' . $p['project_no'] : ''), 0, 200), 'doc_type' => $t['doc_type'],
                'related_type' => $related[0] ?? null, 'related_id' => $related[1] ?? null, 'body' => $body, 'paper' => $L['paper'], 'orientation' => $L['orientation'],
                'needs_approval' => (int)$t['needs_approval'], 'values_json' => json_encode(BrgyLayout::values($ctx, $p ? Brgy::lines((int)$p['id']) : [], $L), JSON_UNESCAPED_UNICODE),
                'status' => 'draft', 'is_prescribed' => $t['is_prescribed'], 'official_version' => $t['official_version'],
                'signatory_name' => Brgy::setting('brgy_signatory_name') ?: null, 'signatory_position' => Brgy::setting('brgy_signatory_position') ?: null, 'prepared_by' => Auth::id(),
            ]);
            Brgy::log('create', 'form', $id, $no . ' ' . $t['name'], ['page_key' => 'brgy.forms', 'reason' => 'From template ' . $t['code'] . ' v' . $t['version_no']]);
            return $id;
        });
    }

    /** Fills the placeholders again from today's records (the text typed since is replaced) */
    public static function refreshForm(int $formId): void
    {
        self::need('brgy.forms', 'edit', 'change forms');
        $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $formId]);
        if (!$f || !in_array($f['status'], ['draft', 'returned'], true)) {
            fail('Only a draft or returned form is refreshed.');
        }
        $t = DB::row('SELECT * FROM brgy_templates WHERE id = :id', ['id' => $f['template_id']]);
        if (!$t) {
            fail('The template of this form no longer exists.');
        }
        $p = $f['project_id'] ? Brgy::mustProject((int)$f['project_id']) : null;
        $L = BrgyLayout::layout($t);
        $ctx = BrgyDocs::context($p, $f['related_type'] ? [$f['related_type'], (int)$f['related_id']] : null, $f['form_no'], $L);
        $body = BrgyDocs::render($t['body'], $ctx);
        DB::update('brgy_forms', ['body' => $body, 'template_version' => $t['version_no'], 'needs_approval' => (int)$t['needs_approval'],
            'values_json' => json_encode(BrgyLayout::values($ctx, $p ? Brgy::lines((int)$p['id']) : [], $L), JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $formId]);
        Brgy::bumpVersion('brgy_forms', $formId);
        Brgy::log('refresh', 'form', $formId, $f['form_no'], ['page_key' => 'brgy.forms']);
    }

    public static function saveForm(int $formId, array $d, $lockVersion = null): void
    {
        self::need('brgy.forms', 'edit', 'change forms');
        DB::tx(function () use ($formId, $d, $lockVersion) {
            Brgy::checkVersion('brgy_forms', $formId, $lockVersion);
            $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $formId]);
            if (!in_array($f['status'], ['draft', 'returned'], true)) {
                fail('The form is ' . (Brgy::WF[$f['status']] ?? $f['status']) . ' and cannot be changed. An approver can reopen it.');
            }
            $set = ['title' => self::s($d['title'] ?? $f['title'], 200) ?? $f['title'], 'body' => BrgyDocs::sanitize((string)($d['body'] ?? $f['body'])),
                'signatory_name' => self::s($d['signatory_name'] ?? '', 120), 'signatory_position' => self::s($d['signatory_position'] ?? '', 120),
                'paper' => isset(BrgyLayout::PAPERS[$d['paper'] ?? '']) && $d['paper'] !== 'Custom' ? $d['paper'] : $f['paper'],
                'orientation' => ($d['orientation'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
                'official_version' => self::s($d['official_version'] ?? $f['official_version'], 80), 'updated_at' => date('Y-m-d H:i:s')];
            if (isset($d['values']) && is_array($d['values'])) {
                // the values printed on pre-printed paper, as corrected on the form
                $vals = json_decode((string)$f['values_json'], true) ?: ['f' => [], 't' => []];
                foreach ($d['values'] as $k => $v) {
                    if (is_string($k) && preg_match('/^[a-z0-9_.]+$/', $k)) {
                        $vals['f'][$k] = mb_substr((string)$v, 0, 500);
                    }
                }
                $set['values_json'] = json_encode($vals, JSON_UNESCAPED_UNICODE);
            }
            DB::update('brgy_forms', $set, 'id = :id', ['id' => $formId]);
            Brgy::bumpVersion('brgy_forms', $formId);
            Brgy::log('edit', 'form', $formId, $f['form_no'], ['before' => ['title' => $f['title'], 'body_length' => strlen($f['body'])], 'after' => ['title' => $set['title'], 'body_length' => strlen($set['body'])], 'page_key' => 'brgy.forms']);
        });
    }

    public static function formStep(int $formId, string $action, string $remarks = ''): void
    {
        DB::tx(function () use ($formId, $action, $remarks) {
            $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id', ['id' => $formId]);
            if (!$f) {
                fail('Form not found.');
            }
            if ($action === 'submit' && ($gaps = BrgyDocs::gaps($f['body']))) {
                fail('Fill in the gaps before sending the form for review: ' . rtrim(implode(', ', $gaps), '.') . '.');
            }
            Brgy::step('form', $f, $action, $remarks, $f['project_id'] ? Brgy::mustProject((int)$f['project_id']) : null);
            if ($action === 'reopen') {
                DB::update('brgy_forms', ['finalized_at' => null], 'id = :id', ['id' => $formId]);
            }
        });
    }

    /**
     * Makes an approved form final (locked, printed without the DRAFT mark) and files it in the vault, where it can satisfy a
     * checklist item or a billing pack. A prescribed form needs the official version it follows, and no form goes final with
     * a blank field or without its signatory.
     */
    public static function finalizeForm(int $formId, array $d = []): void
    {
        self::need('brgy.forms', 'edit', 'finalize forms');
        DB::tx(function () use ($formId, $d) {
            $f = DB::row('SELECT * FROM brgy_forms WHERE id = :id FOR UPDATE', ['id' => $formId]);
            $direct = $f && (int)$f['needs_approval'] === 0 && in_array($f['status'], ['draft', 'returned'], true);
            if (!$f || ($f['status'] !== 'approved' && !$direct)) {
                fail('Only an approved form is made final.');
            }
            if ($gaps = BrgyDocs::gaps($f['body'])) {
                fail('Blank fields: ' . rtrim(implode(', ', $gaps), '.') . '.');
            }
            if (!$f['signatory_name']) {
                fail('Enter the signatory of the form.');
            }
            $ver = self::s($d['official_version'] ?? $f['official_version'], 80);
            if ((int)$f['is_prescribed'] === 1 && !$ver) {
                fail('This is a prescribed form: state the official version it follows (issuance and date), after checking it against the procuring entity\'s bidding documents.');
            }
            $docId = DB::insert('brgy_documents', [
                'scope' => $f['project_id'] ? 'project' : 'company', 'project_id' => $f['project_id'], 'doc_type' => $f['doc_type'] ?: 'other', 'title' => $f['title'],
                'doc_no' => $f['form_no'], 'issued_by' => Settings::get('company_name'), 'issue_date' => today(), 'status' => 'final', 'is_locked' => 1,
                'related_type' => $f['related_type'] ?: 'form', 'related_id' => $f['related_type'] ? $f['related_id'] : $formId, 'notes' => 'Prepared on Forms & Proformas (' . $f['form_no'] . ')',
                'uploaded_by' => Auth::id(), 'reviewed_by' => $f['approved_by'], 'reviewed_at' => $f['approved_at'], 'scan_status' => 'Made in the program',
            ]);
            DB::update('brgy_forms', ['status' => 'final', 'finalized_at' => date('Y-m-d H:i:s'), 'official_version' => $ver, 'document_id' => $docId], 'id = :id', ['id' => $formId]);
            Brgy::bumpVersion('brgy_forms', $formId);
            if ($f['project_id'] && $f['doc_type']) {
                DB::run("UPDATE brgy_project_reqs SET document_id = :d, status = 'approved', reviewed_by = :u, reviewed_at = :t, updated_at = :t2
                          WHERE project_id = :p AND doc_type = :ty AND status IN ('missing', 'returned')", ['d' => $docId, 'u' => $f['approved_by'], 't' => $f['approved_at'] ?: date('Y-m-d H:i:s'),
                    't2' => date('Y-m-d H:i:s'), 'p' => $f['project_id'], 'ty' => $f['doc_type']]);
            }
            DB::insert('brgy_workflow', ['project_id' => $f['project_id'], 'object_type' => 'form', 'object_id' => $formId, 'action' => 'complete', 'from_status' => $f['status'], 'to_status' => 'final', 'user_id' => Auth::id()]);
            Brgy::log('finalize', 'form', $formId, $f['form_no'] . ' ' . $f['title'], ['reason' => $ver ? 'Official version: ' . $ver : null, 'page_key' => 'brgy.forms']);
        });
    }

    // ================================================================== closeout

    /** What stands between the contract and its closeout: [blocking => [...], warnings => [...]] */
    public static function closeoutCheck(array $p): array
    {
        $block = [];
        $warn = [];
        if ($p['status'] !== 'awarded') {
            $block[] = 'Only an awarded contract is closed out.';
            return ['blocking' => $block, 'warnings' => $warn];
        }
        Brgy::refreshLines((int)$p['id']);
        $m = Brgy::money($p);
        foreach (Brgy::lines((int)$p['id']) as $l) {
            if ((float)$l['qty_accepted'] < (float)$l['qty'] - 0.00001) {
                $warn[] = 'Line ' . $l['line_no'] . ' (' . $l['description'] . '): ' . qty($l['qty_accepted']) . ' of ' . qty($l['qty']) . ' accepted.';
            }
            if ((float)$l['qty_billed'] < (float)$l['qty_accepted'] - 0.00001) {
                $block[] = 'Line ' . $l['line_no'] . ': ' . qty((float)$l['qty_accepted'] - (float)$l['qty_billed']) . ' accepted but not billed.';
            }
        }
        if ((int)DB::value("SELECT COUNT(*) FROM brgy_deliveries WHERE project_id = :p AND status = 'delivered'", ['p' => $p['id']])) {
            $block[] = 'A delivery is still waiting for the entity\'s inspection.';
        }
        if ((int)DB::value("SELECT COUNT(*) FROM brgy_billings WHERE project_id = :p AND status IN ('draft', 'for_review', 'reviewed', 'approved', 'returned')", ['p' => $p['id']])) {
            $block[] = 'A billing pack is not finished (draft or in approval).';
        }
        if ((int)DB::value("SELECT COUNT(*) FROM brgy_change_orders WHERE project_id = :p AND status IN ('draft', 'for_review', 'reviewed', 'approved', 'returned')", ['p' => $p['id']])) {
            $warn[] = 'A change order was never applied.';
        }
        if ($m['ar_open'] > 0.004) {
            $block[] = money($m['ar_open']) . ' is still receivable on the job.';
        }
        if ($m['retention_held'] > 0.004) {
            $warn[] = money($m['retention_held']) . ' of retention is still held' . ($p['warranty_until'] && $p['warranty_until'] > today() ? ' (warranty until ' . fdate($p['warranty_until']) . ')' : '') . '.';
        }
        if ((int)DB::value("SELECT COUNT(*) FROM brgy_securities WHERE project_id = :p AND status = 'active' AND form <> 'declaration'", ['p' => $p['id']])) {
            $warn[] = 'A security is still active (not returned or forfeited).';
        }
        foreach (BrgyPost::reconcile((int)$p['id']) as $r) {
            foreach ($r['issues'] as $i) {
                $block[] = 'Ledger: ' . $i . '.';
            }
        }
        return ['blocking' => $block, 'warnings' => $warn];
    }

    /** Closes the contract (approvers). Warnings need an override reason; a blocking item cannot be overridden. */
    public static function closeout(int $projectId, string $notes, bool $override = false): void
    {
        self::need('brgy.approvals', 'approve', 'close out contracts (approvers only)');
        DB::tx(function () use ($projectId, $notes, $override) {
            $p = Brgy::mustProject($projectId);
            $chk = self::closeoutCheck($p);
            if ($chk['blocking']) {
                fail("The contract cannot be closed yet:\n- " . implode("\n- ", $chk['blocking']));
            }
            if ($chk['warnings'] && (!$override || trim($notes) === '')) {
                fail("Confirm the closeout with a reason for these open points:\n- " . implode("\n- ", $chk['warnings']));
            }
            DB::update('brgy_projects', ['status' => 'closed', 'closeout_date' => today(), 'closeout_notes' => self::s($notes, 5000), 'completion_date' => $p['completion_date'] ?: today(),
                'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            Brgy::setPhase($p, 'closeout', 'Closed out' . ($notes !== '' ? ': ' . $notes : ''));
            DB::insert('brgy_workflow', ['project_id' => $projectId, 'object_type' => 'closeout', 'object_id' => $projectId, 'action' => 'complete', 'from_status' => 'awarded', 'to_status' => 'closed',
                'user_id' => Auth::id(), 'amount' => Brgy::contractTotal($p), 'remarks' => mb_substr(($chk['warnings'] ? 'Overrode: ' . implode('; ', $chk['warnings']) . ' ' : '') . $notes, 0, 255)]);
            Brgy::log('closeout', 'project', $projectId, $p['project_no'], ['reason' => $notes, 'amount' => Brgy::contractTotal($p), 'page_key' => 'brgy.projects']);
        });
    }

    /** Reopens a closed, lost, cancelled or no-bid project (approvers, with a reason) */
    public static function reopenProject(int $projectId, string $reason): void
    {
        self::need('brgy.approvals', 'approve', 'reopen projects (approvers only)');
        if (trim($reason) === '') {
            fail('Enter the reason for reopening.');
        }
        DB::tx(function () use ($projectId, $reason) {
            $p = Brgy::mustProject($projectId);
            $to = match ($p['status']) {
                'closed' => ['awarded', 'collection'],
                'lost' => ['open', 'evaluation'],
                'cancelled' => $p['contract_amount'] > 0 && $p['noa_ref'] ? ['awarded', 'award'] : ['open', $p['bid_status'] === 'released' ? 'evaluation' : 'costing'],
                'no_bid' => ['open', 'intake'],
                default => null,
            };
            if (!$to) {
                fail('Only a closed, lost, cancelled or no-bid project is reopened.');
            }
            DB::update('brgy_projects', ['status' => $to[0], 'go_decision' => $p['status'] === 'no_bid' ? 'pending' : $p['go_decision'], 'closeout_date' => null], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            Brgy::setPhase(array_merge($p, ['status' => $to[0]]), $to[1], 'Reopened: ' . $reason, true);
            DB::insert('brgy_workflow', ['project_id' => $projectId, 'object_type' => 'phase', 'object_id' => $projectId, 'action' => 'reopen', 'from_status' => $p['status'], 'to_status' => $to[0],
                'user_id' => Auth::id(), 'remarks' => mb_substr($reason, 0, 255)]);
            Brgy::log('reopen', 'project', $projectId, $p['project_no'], ['reason' => $reason, 'page_key' => 'brgy.projects']);
        });
    }
}
