<?php
declare(strict_types=1);

/**
 * Barangay Contracts: the company's Standard Operating Procedure (SOP), step by step, on each transaction.
 *
 * A transaction follows one of the processes of the SOP:
 *   svp      I. Small value procurement (below the limit, "below 100K"): transaction form, the barangay's papers
 *            prepared by the company (PR, PO, IAR, RIS, canvass, abstract, DV), canvass of three suppliers, billing
 *            papers once the payment is released (SI, OR, 2307), review, monitoring, forwarding to accounting,
 *            transmittal and release;
 *   bidding  II. Bidding: bidding details, verification, PhilGEPS posting, pre-bid papers, representative and SPA,
 *            notarized papers, eligibility papers, additional requirements, certified true copies, signatures,
 *            final verification, submission, post-bidding papers, completion, then the payment process flow;
 *   general  the twelve phases of the module, without the SOP.
 *
 * Steps are done in order. Each step lists its papers (made from a proforma, printed, then the signed copy attached
 * as PDF or JPG) and its checks; the program will not mark a step done while something it needs is missing. An
 * approver may skip a step with a reason or open a done step again. The books are kept by the module's own records
 * (award, delivery and acceptance, billing pack and sales invoice, collection), which the steps link to.
 */
final class BrgySop
{
    public const PROCESSES = ['svp' => 'Small value procurement (SOP)', 'bidding' => 'Bidding (SOP)', 'general' => 'Twelve phases (without the SOP)'];

    /** What the program does at a step */
    public const ACTIONS = [
        'details' => 'Transaction details are complete (yes / no)', 'checks' => 'Checks ticked', 'prepare' => 'Papers prepared from the proformas',
        'canvass' => 'Canvass of three suppliers, abstract, award', 'billing_docs' => 'SI, OR and 2307 (held until payment is released)', 'review' => 'Review by another person',
        'monitor' => 'Copies retained, encoded in monitoring', 'forward' => 'Forwarded to the Accounting Department', 'transmittal' => 'Transmittal prepared',
        'release' => 'Released; signed transmittal returned', 'philgeps' => 'PhilGEPS posting recorded', 'representative' => 'Representative and SPA',
        'signatures' => 'Signatures of the proprietor', 'verify' => 'Bid reviewed and approved', 'submit' => 'Endorsed and submitted', 'post_bid' => 'Award and post-bidding papers',
        'completion' => 'Accepted; certificate of completion', 'payment' => 'Cheque released (follow-ups until then)', 'file' => 'Filed for record; closeout',
    ];

    public const SOURCES = ['prepare' => 'Prepared by the company', 'company' => 'Company paper on file', 'record' => 'Issued in the program', 'supplier' => 'Filled by a supplier', 'received' => 'Received'];

    public const PAPER_STATES = ['done' => 'Signed copy attached', 'onfile' => 'On file', 'prepared' => 'Prepared', 'todo' => 'To prepare', 'waived' => 'Not needed', 'held' => 'Held: payment not released'];

    private static function need(string $action, string $what): void
    {
        if (!Access::can('brgy.projects', $action)) {
            fail('Your role cannot ' . $what . '.');
        }
    }

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM brgy_sop_steps WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function uses(array $p): bool
    {
        return in_array($p['process'] ?? 'general', ['svp', 'bidding'], true);
    }

    /** The transaction types of the transaction form (Contracting Settings), code => label */
    public static function txnTypes(): array
    {
        $out = [];
        foreach (preg_split('/\R/', Brgy::setting('brgy_txn_types')) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] !== '') {
                $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $parts[0]));
                $out[$code] = $parts[1] ?? ucfirst($parts[0]);
            }
        }
        return $out ?: ['purchases' => 'Purchases', 'construction' => 'Construction'];
    }

    /** The process suggested for a new transaction from its approved budget */
    public static function suggest(float $abc): string
    {
        $limit = (float)Brgy::setting('brgy_svp_limit');
        return $limit > 0 && $abc > 0 && $abc < $limit ? 'svp' : 'bidding';
    }

    // ================================================================== the lists

    public static function steps(string $process, bool $activeOnly = true): array
    {
        return DB::all('SELECT * FROM brgy_sop_steps WHERE process = :p' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, step_no, id', ['p' => $process]);
    }

    public static function papers(bool $activeOnly = false): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM brgy_sop_papers' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, code') as $r) {
            $out[$r['code']] = $r;
        }
        return $out;
    }

    /** A step or paper of the SOP is for every type of transaction, or only for those ticked on SOP Steps & Papers */
    public static function forType(array $row, ?string $type): bool
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string)($row['txn_types'] ?? '')))));
        return !$list || $type === null || $type === '' || in_array($type, $list, true);
    }

    /** The note on a step skipped (a paper not needed) because of the type of the transaction */
    private const TYPE_NOTE = '/^Not (part of|needed for) a .+ transaction \(SOP Steps & Papers\)$/';

    public static function stepPapers(array $step): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$step['papers']))));
    }

    public static function stepChecks(array $step): array
    {
        return array_values(array_filter(array_map('trim', explode('|', (string)$step['checks']))));
    }

    // ================================================================== a transaction's steps and papers

    /** Puts the steps and papers of the project's process on it (new steps and papers of the SOP are added; nothing is removed) */
    public static function ensure(array $p): void
    {
        if (!self::uses($p) || !self::ready()) {
            return;
        }
        $catalog = self::papers(true);
        $extra = (int)DB::value('SELECT extra_bid_papers FROM brgy_entities WHERE id = :e', ['e' => $p['entity_id']]);
        $n = 0;
        foreach (self::steps($p['process']) as $s) {
            DB::run('INSERT IGNORE INTO brgy_project_steps (project_id, step_code) VALUES (:p, :s)', ['p' => $p['id'], 's' => $s['code']]);
            foreach (self::stepPapers($s) as $code) {
                $c = $catalog[$code] ?? null;
                if (!$c || ($c['condition_code'] === 'extra_bid_papers' && !$extra)) {
                    continue;
                }
                DB::run('INSERT IGNORE INTO brgy_project_papers (project_id, step_code, paper_code, name, doc_type, template_code, source, copies, needs_signature, needs_notary, needs_ctc, sort_order)
                         VALUES (:p, :s, :c, :n, :d, :t, :src, :cp, :sg, :nt, :ct, :o)',
                    ['p' => $p['id'], 's' => $s['code'], 'c' => $code, 'n' => $c['name'], 'd' => $c['doc_type'], 't' => $c['template_code'], 'src' => $c['source'], 'cp' => $c['copies'],
                     'sg' => $c['needs_signature'], 'nt' => $c['needs_notary'], 'ct' => $c['needs_ctc'], 'o' => ($n += 10)]);
            }
        }
        $moved = self::applyType($p, $catalog);
        if ((!$p['sop_step'] || $moved) && $p['status'] === 'open') {
            $first = DB::value("SELECT ps.step_code FROM brgy_project_steps ps JOIN brgy_sop_steps s ON s.code = ps.step_code
                                 WHERE ps.project_id = :p AND ps.status = 'pending' AND s.process = :pr ORDER BY s.sort_order LIMIT 1", ['p' => $p['id'], 'pr' => $p['process']]);
            if ($first && $first !== $p['sop_step']) {
                DB::update('brgy_projects', ['sop_step' => $first], 'id = :id', ['id' => $p['id']]);
            }
        }
    }

    /**
     * The steps and papers of the SOP that are not for the type of the transaction (Cash Out, Kaliwaan, Purchases, Construction ...):
     * a pending step is skipped and a paper with nothing attached is marked not needed, each with a note saying why; when the type
     * changes, what was set aside for the type before comes back. A step done or a paper signed stays as it is. True when a step moved.
     */
    public static function applyType(array $p, ?array $catalog = null): bool
    {
        $type = (string)($p['txn_type'] ?? '');
        $label = self::txnTypes()[$type] ?? $type;
        $catalog ??= self::papers(true);
        $moved = false;
        $changed = 0;
        foreach (self::steps($p['process']) as $s) {
            if (!array_key_exists('txn_types', $s)) {
                return false;      // the database has not been brought up to date yet
            }
            $row = DB::row('SELECT status, remarks FROM brgy_project_steps WHERE project_id = :p AND step_code = :c', ['p' => $p['id'], 'c' => $s['code']]);
            if (!$row) {
                continue;
            }
            $auto = (bool)preg_match(self::TYPE_NOTE, (string)$row['remarks']);
            if (!self::forType($s, $type) && $row['status'] === 'pending') {
                DB::update('brgy_project_steps', ['status' => 'skipped', 'remarks' => mb_substr('Not part of a ' . $label . ' transaction (SOP Steps & Papers)', 0, 255), 'done_at' => date('Y-m-d H:i:s')],
                    'project_id = :p AND step_code = :c', ['p' => $p['id'], 'c' => $s['code']]);
                $moved = true;
                $changed++;
            } elseif (self::forType($s, $type) && $row['status'] === 'skipped' && $auto) {
                DB::update('brgy_project_steps', ['status' => 'pending', 'remarks' => null, 'done_by' => null, 'done_at' => null], 'project_id = :p AND step_code = :c', ['p' => $p['id'], 'c' => $s['code']]);
                $moved = true;
                $changed++;
            }
        }
        foreach (DB::all('SELECT id, paper_code, status, waive_reason, document_id FROM brgy_project_papers WHERE project_id = :p', ['p' => $p['id']]) as $pp) {
            $c = $catalog[$pp['paper_code']] ?? null;
            if (!$c || !array_key_exists('txn_types', $c)) {
                continue;
            }
            $auto = (bool)preg_match(self::TYPE_NOTE, (string)$pp['waive_reason']);
            if (!self::forType($c, $type) && $pp['status'] === 'open' && !$pp['document_id']) {
                DB::update('brgy_project_papers', ['status' => 'waived', 'waive_reason' => mb_substr('Not needed for a ' . $label . ' transaction (SOP Steps & Papers)', 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $pp['id']]);
                $changed++;
            } elseif (self::forType($c, $type) && $pp['status'] === 'waived' && $auto) {
                DB::update('brgy_project_papers', ['status' => 'open', 'waive_reason' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $pp['id']]);
                $changed++;
            }
        }
        if ($changed) {
            Brgy::event((int)$p['id'], 'sop', 'Steps and papers set for a ' . $label . ' transaction', ['details' => $changed . ' step(s) or paper(s) set aside or brought back by the type of the transaction (SOP Steps & Papers).']);
        }
        return $moved;
    }

    /** The steps of a project with their state, papers and what still stands in the way */
    public static function state(array $p): array
    {
        $done = [];
        foreach (DB::all('SELECT * FROM brgy_project_steps WHERE project_id = :p', ['p' => $p['id']]) as $r) {
            $done[$r['step_code']] = $r;
        }
        $papers = [];
        foreach (DB::all('SELECT pp.*, d.title AS doc_title, d.original_name, d.mime_type, f.form_no, f.status AS form_status
                            FROM brgy_project_papers pp LEFT JOIN brgy_documents d ON d.id = pp.document_id LEFT JOIN brgy_forms f ON f.id = pp.form_id
                           WHERE pp.project_id = :p ORDER BY pp.sort_order, pp.id', ['p' => $p['id']]) as $r) {
            $r += self::paperState($r, $p);
            $papers[$r['step_code']][] = $r;
        }
        $steps = [];
        $current = null;
        $nDone = 0;
        foreach (self::steps($p['process']) as $s) {
            $row = $done[$s['code']] ?? ['status' => 'pending', 'checks_done' => null, 'remarks' => null, 'done_by' => null, 'done_at' => null];
            $s['state'] = $row['status'];
            $s['row'] = $row;
            $s['papers_list'] = $papers[$s['code']] ?? [];
            if ($row['status'] !== 'pending') {
                $nDone++;
            } elseif ($current === null) {
                $current = $s['code'];
            }
            $steps[$s['code']] = $s;
        }
        $total = count($steps);
        return ['steps' => $steps, 'current' => $current, 'done' => $nDone, 'total' => $total, 'pct' => $total ? (int)round($nDone / $total * 100) : 0,
            'ended' => in_array($p['status'], ['lost', 'no_bid', 'cancelled'], true), 'closed' => $p['status'] === 'closed'];
    }

    /** Where a paper stands: state (done, onfile, prepared, todo, waived, held) and a line saying why */
    public static function paperState(array $pp, array $p): array
    {
        if ($pp['status'] === 'waived') {
            return ['state' => 'waived', 'why' => 'Not needed: ' . $pp['waive_reason']];
        }
        if ($pp['document_id']) {
            return ['state' => 'done', 'why' => 'Signed copy attached' . ($pp['signed_at'] ? ', ' . fdate(substr((string)$pp['signed_at'], 0, 10)) : '')];
        }
        if (in_array($pp['paper_code'], ['si', 'or', 'form_2307'], true) && self::holdsBilling($p)) {
            return ['state' => 'held', 'why' => 'Held until the payment is released (special case F).'];
        }
        if ($pp['paper_code'] === 'canvass') {
            $n = (int)DB::value('SELECT COUNT(*) FROM brgy_canvass WHERE project_id = :p AND document_id IS NOT NULL', ['p' => $p['id']]);
            if ($n >= 3) {
                return ['state' => 'done', 'why' => $n . ' canvass forms signed by the suppliers (see the canvass).'];
            }
        }
        if ($pp['source'] === 'company') {
            $on = $p['bid_deadline'] ? substr((string)$p['bid_deadline'], 0, 10) : today();
            $doc = DB::row("SELECT id, title, valid_until FROM brgy_documents WHERE scope = 'company' AND doc_type = :t AND is_current = 1 AND status IN ('approved', 'final')
                             AND (valid_until IS NULL OR valid_until >= :d) ORDER BY valid_until DESC, id DESC LIMIT 1", ['t' => $pp['doc_type'], 'd' => $on]);
            if ($doc) {
                return ['state' => 'onfile', 'why' => 'On file: ' . $doc['title'] . ($doc['valid_until'] ? ', valid until ' . fdate($doc['valid_until']) : ''), 'file_doc' => (int)$doc['id']];
            }
            return ['state' => 'todo', 'why' => 'No valid copy in the vault (Company papers) for ' . fdate($on) . '.'];
        }
        if ($pp['paper_code'] === 'si') {
            $si = DB::row("SELECT si_no, billing_no FROM brgy_billings WHERE project_id = :p AND status IN ('invoiced', 'submitted', 'acknowledged') ORDER BY id DESC LIMIT 1", ['p' => $p['id']]);
            if ($si) {
                return ['state' => 'prepared', 'why' => 'Sales invoice ' . ($si['si_no'] ?: $si['billing_no']) . ' issued; attach the signed copy.'];
            }
            return ['state' => 'todo', 'why' => 'Issue the sales invoice from a billing pack.'];
        }
        if ($pp['paper_code'] === 'or') {
            $or = DB::row("SELECT receipt_no, collection_no FROM brgy_collections WHERE project_id = :p AND status = 'posted' AND kind = 'billing' ORDER BY id DESC LIMIT 1", ['p' => $p['id']]);
            if ($or) {
                return ['state' => 'prepared', 'why' => 'Receipt ' . ($or['receipt_no'] ?: $or['collection_no']) . ' for the cheque; attach the signed copy.'];
            }
            return ['state' => 'todo', 'why' => 'Record the payment received (Collections & Retention) with the receipt number.'];
        }
        if ($pp['form_id']) {
            $fno = $pp['form_no'] ?? DB::value('SELECT form_no FROM brgy_forms WHERE id = :f', ['f' => $pp['form_id']]);
            return ['state' => 'prepared', 'why' => 'Prepared: ' . $fno . ($pp['needs_signature'] ? '; attach the signed copy.' : '.')];
        }
        return ['state' => 'todo', 'why' => $pp['template_code'] ? 'Prepare it from the proforma.' : 'Attach it.'];
    }

    /** Special case F of the SOP: no sales invoice, official receipt or 2307 while the payment is not released */
    public static function holdsBilling(array $p): bool
    {
        return self::uses($p) && ($p['payment_status'] ?? 'waiting') !== 'released' && Brgy::on('brgy_sop_hold_billing');
    }

    // ================================================================== what stands in the way of a step

    /** Reasons the step cannot be marked done (empty = it can); $checks are the checks ticked */
    public static function gate(array $p, array $step, array $checks = []): array
    {
        $out = [];
        $missing = array_diff(self::stepChecks($step), $checks);
        foreach ($missing as $m) {
            $out[] = 'Tick "' . $m . '".';
        }
        $papers = DB::all('SELECT * FROM brgy_project_papers WHERE project_id = :p AND step_code = :s ORDER BY sort_order', ['p' => $p['id'], 's' => $step['code']]);
        $needScans = Brgy::on('brgy_sop_need_scans');
        $state = fn(array $pp) => self::paperState($pp, $p)['state'];
        switch ($step['action']) {
            case 'details':
                if (trim((string)$p['title']) === '') {
                    $out[] = 'Enter the project title.';
                }
                if ((float)$p['abc_amount'] <= 0) {
                    $out[] = 'Enter the approved budget.';
                }
                if ($p['process'] === 'svp') {
                    if (!$p['requestor']) {
                        $out[] = 'Enter the requestor.';
                    }
                    if (!$p['inspector']) {
                        $out[] = 'Enter the inspector.';
                    }
                    if (!Brgy::lineTotals((int)$p['id'])['lines']) {
                        $out[] = 'Enter the items (Price schedule tab): the complete specifications / particulars.';
                    }
                }
                break;
            case 'checks':
                if ($step['code'] === 'bid2') {
                    if (!$p['bid_deadline']) {
                        $out[] = 'Enter the bid deadline (Edit > Key dates).';
                    }
                    if (trim((string)$p['specs']) === '' && !Brgy::lineTotals((int)$p['id'])['lines']) {
                        $out[] = 'Enter the specifications or the items.';
                    }
                }
                break;
            case 'philgeps':
                if (!$p['philgeps_ref'] || !$p['posting_date']) {
                    $out[] = 'Enter the PhilGEPS reference number and the posting date.';
                }
                break;
            case 'representative':
                if (!$p['submitter_id']) {
                    $out[] = 'Choose the authorized representative.';
                }
                break;
            case 'canvass':
                $c = DB::all('SELECT * FROM brgy_canvass WHERE project_id = :p ORDER BY sort_order, id', ['p' => $p['id']]);
                if (count($c) < 3) {
                    $out[] = 'Record the quotations of three (3) suppliers (' . count($c) . ' so far).';
                }
                if (count(array_filter($c, fn($r) => (int)$r['is_company'] === 1)) !== 1) {
                    $out[] = 'Mark one quotation as the company\'s own.';
                }
                foreach ($c as $r) {
                    if (!$r['document_id']) {
                        $out[] = $r['supplier_name'] . ': attach the canvass form signed by the supplier.';
                    }
                    if (!in_array($r['remarks'], ['passed', 'failed'], true)) {
                        $out[] = $r['supplier_name'] . ': enter the remarks (passed or failed).';
                    }
                }
                break;
            case 'billing_docs':
                if (self::holdsBilling($p)) {
                    $out[] = 'The payment has not been released yet: the SI, OR and 2307 are held (special case F). Record the cheque when it is ready.';
                    break;
                }
                $billed = (float)DB::value("SELECT COALESCE(SUM(gross_amount), 0) FROM brgy_billings WHERE project_id = :p AND status IN ('invoiced', 'submitted', 'acknowledged')", ['p' => $p['id']]);
                if ($billed < Brgy::contractTotal($p) - 0.004) {
                    $out[] = 'Issue the sales invoice for the whole contract (' . money(Brgy::contractTotal($p)) . '; invoiced so far ' . money($billed) . ').';
                }
                if (!DB::value("SELECT COUNT(*) FROM brgy_collections WHERE project_id = :p AND kind = 'billing' AND status = 'posted' AND receipt_no IS NOT NULL", ['p' => $p['id']])) {
                    $out[] = 'Record the payment received with its official receipt number (Collections & Retention).';
                }
                break;
            case 'review':
                if (Brgy::on('brgy_sop_review_other')) {
                    $by = (int)DB::value("SELECT ps.done_by FROM brgy_project_steps ps JOIN brgy_sop_steps s ON s.code = ps.step_code
                                          WHERE ps.project_id = :p AND s.process = :pr AND s.action = 'billing_docs' AND ps.status = 'done' LIMIT 1", ['p' => $p['id'], 'pr' => $p['process']]);
                    if ($by && $by === (int)Auth::id()) {
                        $out[] = 'The review is done by someone other than the person who prepared the billing documents.';
                    }
                }
                if ($needScans) {
                    foreach (DB::all("SELECT pp.* FROM brgy_project_papers pp JOIN brgy_sop_steps s ON s.code = pp.step_code WHERE pp.project_id = :p AND s.sort_order < :o ORDER BY s.sort_order, pp.sort_order",
                        ['p' => $p['id'], 'o' => $step['sort_order']]) as $pp) {
                        if ($pp['needs_signature'] && !in_array($state($pp), ['done', 'waived', 'onfile'], true)) {
                            $out[] = $pp['name'] . ': the signed copy is not attached.';
                        }
                    }
                }
                break;
            case 'forward':
                if (!$p['forwarded_at']) {
                    $out[] = 'Enter when the papers were forwarded to the Accounting Department.';
                }
                break;
            case 'transmittal':
                if (!$p['transmittal_no']) {
                    $out[] = 'Enter the transmittal number (or prepare the transmittal).';
                }
                break;
            case 'release':
                if (!$p['release_method'] || !$p['released_at']) {
                    $out[] = 'Record the release: delivered by the sales representative or picked up at the office, when and to whom.';
                }
                $tr = DB::row("SELECT * FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'transmittal' ORDER BY id DESC LIMIT 1", ['p' => $p['id']]);
                if (!$tr || (!$tr['document_id'] && $tr['status'] !== 'waived')) {
                    $out[] = 'Attach the signed transmittal: it must always be returned.';
                }
                break;
            case 'signatures':
                foreach (DB::all("SELECT * FROM brgy_project_papers WHERE project_id = :p AND needs_signature = 1 AND source = 'prepare' AND status <> 'waived'", ['p' => $p['id']]) as $pp) {
                    $s = DB::value('SELECT sort_order FROM brgy_sop_steps WHERE code = :c', ['c' => $pp['step_code']]);
                    if ((int)$s < (int)$step['sort_order'] && !$pp['signed_at'] && !$pp['document_id']) {
                        $out[] = $pp['name'] . ': not signed by the proprietor yet.';
                    }
                }
                break;
            case 'verify':
                if (!in_array($p['bid_status'], ['approved', 'released'], true)) {
                    $out[] = 'The bid has to be reviewed and approved for submission (now ' . (Brgy::WF[$p['bid_status']] ?? $p['bid_status']) . ').';
                }
                break;
            case 'submit':
                if (!$p['bid_endorsed_at']) {
                    $out[] = 'Endorse the documents to the representative.';
                }
                if ($p['bid_status'] !== 'released') {
                    $out[] = 'Record the submission of the bid (only the authorized representative can).';
                }
                break;
            case 'post_bid':
                if ($p['status'] !== 'awarded' && $p['status'] !== 'closed') {
                    $out[] = 'Record the award (notice of award) first, or the result if the bid was not awarded.';
                }
                break;
            case 'completion':
                Brgy::refreshLines((int)$p['id']);
                foreach (Brgy::lines((int)$p['id']) as $l) {
                    if ((float)$l['qty_accepted'] < (float)$l['qty'] - 0.00001) {
                        $out[] = 'Line ' . $l['line_no'] . ' (' . $l['description'] . '): ' . qty($l['qty_accepted']) . ' of ' . qty($l['qty']) . ' accepted.';
                    }
                }
                break;
            case 'payment':
                if (($p['payment_status'] ?? 'waiting') !== 'released') {
                    $out[] = 'The cheque is not ready yet: follow up and wait, then record it.';
                }
                break;
        }
        // the papers of the step
        foreach ($papers as $pp) {
            $st = $state($pp);
            if ($st === 'waived') {
                continue;
            }
            $signedNeeded = in_array($step['action'], ['billing_docs', 'completion', 'release', 'post_bid'], true) && $needScans && $pp['needs_signature'];
            if ($pp['paper_code'] === 'form_2307' && $st !== 'done' && $st !== 'held') {
                $out[] = 'BIR Form 2307: attach the copies signed by the proprietor.';
            } elseif ($signedNeeded && $st !== 'done' && $st !== 'held' && $pp['paper_code'] !== 'transmittal') {
                $out[] = $pp['name'] . ': attach the signed copy.';
            } elseif (in_array($st, ['todo'], true)) {
                $out[] = $pp['name'] . ': ' . ($pp['source'] === 'company' ? 'no valid copy on file' : 'not prepared yet') . '.';
            }
        }
        return array_values(array_unique($out));
    }

    // ================================================================== moving through the steps

    private static function stepRow(array $p, string $code): array
    {
        $s = DB::row('SELECT * FROM brgy_sop_steps WHERE code = :c AND process = :p', ['c' => $code, 'p' => $p['process']]);
        if (!$s) {
            fail('That step is not part of this transaction\'s process.');
        }
        return $s;
    }

    private static function nextPending(int $projectId, string $process): ?string
    {
        $c = DB::value("SELECT s.code FROM brgy_sop_steps s LEFT JOIN brgy_project_steps ps ON ps.step_code = s.code AND ps.project_id = :p
                         WHERE s.process = :pr AND s.is_active = 1 AND COALESCE(ps.status, 'pending') = 'pending' ORDER BY s.sort_order LIMIT 1", ['p' => $projectId, 'pr' => $process]);
        return $c ?: null;
    }

    /** Saves what a step asks for (PhilGEPS posting, forwarding, release ...) without marking it done */
    public static function saveStepData(int $projectId, string $code, array $in): void
    {
        self::need('edit', 'work on the transactions');
        DB::tx(function () use ($projectId, $code, $in) {
            $p = Brgy::mustProject($projectId);
            $s = self::stepRow($p, $code);
            $set = [];
            $d = fn($k) => valid_date(trim((string)($in[$k] ?? ''))) ? trim((string)$in[$k]) : null;
            $str = fn($k, $n) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $n);
            switch ($s['action']) {
                case 'philgeps':
                    $set = ['philgeps_ref' => $str('philgeps_ref', 40), 'posting_date' => $d('posting_date')];
                    break;
                case 'representative':
                    $set = ['submitter_id' => !empty($in['submitter_id']) ? (int)$in['submitter_id'] : null];
                    Brgy::assertUserAllowed('submitter', $set['submitter_id'], (int)$p['submitter_id'] ?: null);   // v33
                    break;
                case 'forward':
                    $set = ['forwarded_to' => $str('forwarded_to', 120) ?? Brgy::setting('brgy_accounting_dept'), 'forwarded_at' => ($d('forwarded_date') ?? today()) . ' ' . date('H:i:s')];
                    break;
                case 'transmittal':
                    $set = ['transmittal_no' => $str('transmittal_no', 40)];
                    break;
                case 'release':
                    $m = in_array($in['release_method'] ?? '', ['sales_rep', 'pickup'], true) ? $in['release_method'] : null;
                    if (!$m) {
                        fail('Choose how the papers are released: by the sales representative or picked up at the office.');
                    }
                    $set = ['release_method' => $m, 'released_to' => $str('released_to', 120), 'released_at' => ($d('released_date') ?? today()) . ' ' . date('H:i:s')];
                    break;
                case 'submit':
                    $set = ['bid_endorsed_at' => ($d('endorsed_date') ?? today()) . ' ' . date('H:i:s')];
                    break;
                default:
                    return;
            }
            DB::update('brgy_projects', $set + ['updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            Brgy::log('sop_data', 'project', $projectId, $p['project_no'] . ' ' . $s['title'], ['before' => array_intersect_key($p, $set), 'after' => $set, 'page_key' => 'brgy.projects']);
        });
    }

    /** Marks a step done once nothing stands in the way; returns the message for the user */
    public static function complete(int $projectId, string $code, array $checks, string $remarks = ''): string
    {
        self::need('edit', 'work on the transactions');
        return DB::tx(function () use ($projectId, $code, $checks, $remarks) {
            $p = Brgy::mustProject($projectId);
            if (!self::uses($p)) {
                fail('This project does not follow the SOP.');
            }
            if (!in_array($p['status'], ['open', 'awarded'], true)) {
                fail('The transaction is ' . Brgy::label(Brgy::STATUSES, $p['status']) . '.');
            }
            self::ensure($p);
            $s = self::stepRow($p, $code);
            $row = DB::row('SELECT * FROM brgy_project_steps WHERE project_id = :p AND step_code = :c FOR UPDATE', ['p' => $projectId, 'c' => $code]);
            if ($row['status'] !== 'pending') {
                fail('Step ' . $s['step_no'] . ' is already ' . ($row['status'] === 'done' ? 'done' : 'skipped') . '.');
            }
            $before = DB::value("SELECT s.title FROM brgy_sop_steps s LEFT JOIN brgy_project_steps ps ON ps.step_code = s.code AND ps.project_id = :p
                                  WHERE s.process = :pr AND s.is_active = 1 AND s.sort_order < :o AND COALESCE(ps.status, 'pending') = 'pending' ORDER BY s.sort_order LIMIT 1",
                ['p' => $projectId, 'pr' => $p['process'], 'o' => $s['sort_order']]);
            if ($before) {
                fail('The steps are done in order: "' . $before . '" comes first.');
            }
            $checks = array_values(array_intersect(self::stepChecks($s), array_map('strval', $checks)));
            if ($gate = self::gate($p, $s, $checks)) {
                fail("Step " . $s['step_no'] . " (" . $s['title'] . ") is not finished:\n- " . implode("\n- ", $gate));
            }
            DB::update('brgy_project_steps', ['status' => 'done', 'checks_done' => $checks ? implode('|', $checks) : null, 'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null,
                'done_by' => Auth::id(), 'done_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $row['id']]);
            $msg = 'Step ' . $s['step_no'] . ' done: ' . $s['title'] . '.';
            // what the step sets in motion
            if ($s['action'] === 'details') {
                DB::update('brgy_projects', ['details_complete' => 1], 'id = :id', ['id' => $projectId]);
                if ($p['go_decision'] === 'pending') {
                    DB::update('brgy_projects', ['go_decision' => 'go', 'go_reason' => 'Transaction details complete (SOP)', 'go_by' => Auth::id(), 'go_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
                    Brgy::setPhase(Brgy::mustProject($projectId), Brgy::lineTotals($projectId)['lines'] ? 'costing' : 'eligibility', 'Transaction details complete (SOP step 1)');
                }
            } elseif ($s['action'] === 'canvass') {
                $msg .= ' ' . self::canvassResult($projectId);
                self::refreshPapers($projectId, ['abstract', 'po', 'txn_form']);
            } elseif ($s['action'] === 'monitor') {
                Brgy::event($projectId, 'sop', 'Encoded in the monitoring system', ['details' => 'Copies of the essential documents retained.']);
            }
            $next = self::nextPending($projectId, $p['process']);
            DB::update('brgy_projects', ['sop_step' => $next, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            Brgy::event($projectId, 'sop', 'SOP step ' . $s['step_no'] . ' done: ' . $s['title'], ['details' => trim(($checks ? 'Checked: ' . implode(', ', $checks) . '. ' : '') . $remarks) ?: null]);
            Brgy::log('sop_step', 'project', $projectId, $p['project_no'] . ' ' . $s['code'] . ' ' . $s['title'], ['reason' => $remarks !== '' ? $remarks : null, 'page_key' => 'brgy.projects']);
            if ($next === null) {
                $msg .= ' ' . self::finish($projectId);
            }
            return $msg;
        });
    }

    /** After the last step: closes the contract out when the user may and nothing stands in the way */
    private static function finish(int $projectId): string
    {
        $p = Brgy::mustProject($projectId);
        if ($p['status'] !== 'awarded') {
            return 'Every step of the SOP is done.';
        }
        $chk = BrgyOps::closeoutCheck($p);
        if (!Access::can('brgy.approvals', 'approve')) {
            return 'Every step of the SOP is done; an approver closes the contract out.';
        }
        if ($chk['blocking'] || $chk['warnings']) {
            return 'Every step of the SOP is done; the contract stays open: ' . implode(' ', array_merge($chk['blocking'], $chk['warnings']));
        }
        BrgyOps::closeout($projectId, 'All the steps of the SOP are done (' . self::PROCESSES[$p['process']] . ').');
        return 'Every step of the SOP is done and the contract is closed out.';
    }

    /** Skips a step that does not apply to this transaction (approvers, with the reason) */
    public static function skip(int $projectId, string $code, string $reason): void
    {
        if (!Access::can('brgy.projects', 'approve') && !Access::can('brgy.approvals', 'approve')) {
            fail('Only an approver skips a step of the SOP.');
        }
        if (trim($reason) === '') {
            fail('Enter why the step is skipped.');
        }
        DB::tx(function () use ($projectId, $code, $reason) {
            $p = Brgy::mustProject($projectId);
            self::ensure($p);
            $s = self::stepRow($p, $code);
            DB::update('brgy_project_steps', ['status' => 'skipped', 'remarks' => mb_substr($reason, 0, 255), 'done_by' => Auth::id(), 'done_at' => date('Y-m-d H:i:s')],
                'project_id = :p AND step_code = :c AND status = :st', ['p' => $projectId, 'c' => $code, 'st' => 'pending']);
            DB::update('brgy_projects', ['sop_step' => self::nextPending($projectId, $p['process'])], 'id = :id', ['id' => $projectId]);
            Brgy::event($projectId, 'sop', 'SOP step ' . $s['step_no'] . ' skipped: ' . $s['title'], ['details' => $reason]);
            Brgy::log('sop_skip', 'project', $projectId, $p['project_no'] . ' ' . $s['code'], ['reason' => $reason, 'page_key' => 'brgy.projects']);
        });
    }

    /** Opens a done or skipped step again (approvers, with the reason); the transaction goes back to it */
    public static function reopenStep(int $projectId, string $code, string $reason): void
    {
        if (!Access::can('brgy.projects', 'approve') && !Access::can('brgy.approvals', 'approve')) {
            fail('Only an approver opens a step again.');
        }
        if (trim($reason) === '') {
            fail('Enter why the step is opened again.');
        }
        DB::tx(function () use ($projectId, $code, $reason) {
            $p = Brgy::mustProject($projectId);
            $s = self::stepRow($p, $code);
            DB::update('brgy_project_steps', ['status' => 'pending', 'checks_done' => null, 'remarks' => mb_substr('Opened again: ' . $reason, 0, 255), 'done_by' => null, 'done_at' => null],
                'project_id = :p AND step_code = :c', ['p' => $projectId, 'c' => $code]);
            DB::update('brgy_projects', ['sop_step' => self::nextPending($projectId, $p['process'])], 'id = :id', ['id' => $projectId]);
            Brgy::event($projectId, 'sop', 'SOP step ' . $s['step_no'] . ' opened again: ' . $s['title'], ['details' => $reason]);
            Brgy::log('sop_reopen', 'project', $projectId, $p['project_no'] . ' ' . $s['code'], ['reason' => $reason, 'page_key' => 'brgy.projects']);
        });
    }

    /** "Request / prepare details": the details are not complete; the sales representative is asked for them */
    public static function requestDetails(int $projectId, string $what): void
    {
        self::need('edit', 'work on the transactions');
        if (trim($what) === '') {
            fail('Enter what is missing or has to be validated.');
        }
        $p = Brgy::mustProject($projectId);
        DB::tx(function () use ($p, $what) {
            DB::update('brgy_projects', ['details_complete' => 0], 'id = :id', ['id' => $p['id']]);
            BrgyOps::saveTask((int)$p['id'], ['title' => 'Complete the transaction details', 'details' => $what, 'assigned_to' => $p['sales_rep_id'] ?: null, 'due_date' => date('Y-m-d', strtotime('+2 days')), 'task_type' => 'task']);
            Brgy::event((int)$p['id'], 'sop', 'Details requested (additional research and price validation)', ['details' => $what]);
        });
    }

    // ================================================================== papers

    private static function paper(int $paperId): array
    {
        $pp = DB::row('SELECT * FROM brgy_project_papers WHERE id = :id', ['id' => $paperId]);
        if (!$pp) {
            fail('Paper not found.');
        }
        return $pp;
    }

    /** Prepares a paper from its proforma (a form on Forms & Proformas); returns the form */
    public static function prepare(int $paperId): int
    {
        $pp = self::paper($paperId);
        $p = Brgy::mustProject((int)$pp['project_id']);
        if ($pp['form_id'] && DB::value("SELECT id FROM brgy_forms WHERE id = :f AND status <> 'void'", ['f' => $pp['form_id']])) {
            return (int)$pp['form_id'];
        }
        if (in_array($pp['paper_code'], ['si', 'or', 'form_2307'], true) && self::holdsBilling($p)) {
            fail('The payment has not been released: the ' . $pp['name'] . ' is not prepared yet (special case F of the SOP).');
        }
        if (!$pp['template_code']) {
            fail($pp['name'] . ' has no proforma: attach it.');
        }
        $tid = (int)DB::value('SELECT id FROM brgy_templates WHERE code = :c AND is_active = 1', ['c' => $pp['template_code']]);
        if (!$tid) {
            fail('The proforma "' . $pp['template_code'] . '" is not on Proforma & Form Templates (or is inactive).');
        }
        $fid = BrgyOps::createForm($tid, (int)$p['id'], null, $pp['name'] . ' - ' . $p['project_no']);
        DB::update('brgy_project_papers', ['form_id' => $fid, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $paperId]);
        return $fid;
    }

    /** Fills again from the records the prepared papers that print later facts (the abstract after the canvass, the cheque on the DV) */
    public static function refreshPapers(int $projectId, array $codes): void
    {
        if (!Access::can('brgy.forms', 'edit')) {
            return;
        }
        foreach (DB::all("SELECT pp.form_id FROM brgy_project_papers pp JOIN brgy_forms f ON f.id = pp.form_id
                           WHERE pp.project_id = :p AND f.status IN ('draft', 'returned') AND f.needs_approval = 0 AND pp.paper_code IN ('" . implode("','", array_map(fn($c) => preg_replace('/[^a-z0-9_]/', '', $c), $codes)) . "')",
            ['p' => $projectId]) as $r) {
            BrgyOps::refreshForm((int)$r['form_id']);
        }
    }

    /** Prepares every paper of a step that has a proforma and is not prepared yet; returns how many */
    public static function prepareStep(int $projectId, string $code): int
    {
        $n = 0;
        $p = Brgy::mustProject($projectId);
        foreach (DB::all("SELECT * FROM brgy_project_papers WHERE project_id = :p AND step_code = :c AND status = 'open' AND template_code IS NOT NULL AND document_id IS NULL ORDER BY sort_order", ['p' => $projectId, 'c' => $code]) as $pp) {
            if ($pp['form_id'] && DB::value("SELECT id FROM brgy_forms WHERE id = :f AND status <> 'void'", ['f' => $pp['form_id']])) {
                continue;
            }
            if (in_array($pp['paper_code'], ['si', 'or', 'form_2307'], true) && self::holdsBilling($p)) {
                continue;
            }
            self::prepare((int)$pp['id']);
            $n++;
        }
        return $n;
    }

    /** Attaches the signed copy of a paper (PDF or picture), filed in the vault under the project */
    public static function attach(int $paperId, ?array $file, string $note = ''): int
    {
        if (!Access::can('brgy.projects', 'edit') && !Access::can('brgy.vault', 'create')) {
            fail('Your role cannot attach papers.');
        }
        $pp = self::paper($paperId);
        $p = Brgy::mustProject((int)$pp['project_id']);
        if (!$file) {
            fail('Choose the signed copy (PDF, JPG or JPEG).');
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            fail('Attach the signed copy as a PDF, JPG or JPEG file (a scan or a photo).');
        }
        return DB::tx(function () use ($pp, $p, $file, $note) {
            $doc = BrgyDocs::store($file, ['scope' => 'project', 'project_id' => $p['id'], 'doc_type' => $pp['doc_type'] ?: 'other',
                'title' => $pp['name'] . ' (signed) - ' . $p['project_no'], 'doc_no' => $pp['paper_code'] === 'transmittal' ? $p['transmittal_no'] : null, 'issued_by' => $p['entity_name'],
                'issue_date' => today(), 'related_type' => 'paper', 'related_id' => $pp['id'], 'status' => 'approved', 'notes' => $note !== '' ? mb_substr($note, 0, 255) : 'Signed copy (SOP)']);
            if ($pp['document_id']) {
                DB::update('brgy_documents', ['is_current' => 0, 'status' => 'superseded'], 'id = :id', ['id' => $pp['document_id']]);
                DB::update('brgy_documents', ['parent_id' => $pp['document_id'], 'version_no' => (int)DB::value('SELECT version_no FROM brgy_documents WHERE id = :d', ['d' => $pp['document_id']]) + 1], 'id = :id', ['id' => $doc]);
            }
            DB::update('brgy_project_papers', ['document_id' => $doc, 'signed_at' => date('Y-m-d H:i:s'), 'signed_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $pp['id']]);
            if ($pp['form_id']) {
                DB::insert('brgy_workflow', ['project_id' => $p['id'], 'object_type' => 'form', 'object_id' => $pp['form_id'], 'action' => 'note', 'from_status' => 'signed', 'to_status' => 'signed',
                    'user_id' => Auth::id(), 'remarks' => 'Signed copy attached (document #' . $doc . ')']);
            }
            if ($pp['paper_code'] === 'transmittal') {
                DB::update('brgy_projects', ['transmittal_returned_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $p['id']]);
            }
            if ($pp['paper_code'] === 'form_2307') {
                $col = DB::value("SELECT id FROM brgy_collections WHERE project_id = :p AND kind = 'billing' AND status = 'posted' AND cert_2307_doc_id IS NULL ORDER BY id DESC LIMIT 1", ['p' => $p['id']]);
                if ($col) {
                    DB::update('brgy_collections', ['cert_2307' => 1, 'cert_2307_doc_id' => $doc], 'id = :id', ['id' => $col]);
                }
            }
            Brgy::event((int)$p['id'], 'sop', $pp['name'] . ': signed copy attached', ['doc_id' => $doc]);
            Brgy::log('attach', 'project', (int)$p['id'], $p['project_no'] . ' ' . $pp['name'], ['reason' => 'Signed copy (document #' . $doc . ')', 'page_key' => 'brgy.projects']);
            return $doc;
        });
    }

    /** The proprietor signed the paper (step "Secure signatures"), before or without a scan */
    public static function markSigned(int $paperId, bool $signed = true): void
    {
        self::need('edit', 'work on the transactions');
        $pp = self::paper($paperId);
        DB::update('brgy_project_papers', ['signed_at' => $signed ? date('Y-m-d H:i:s') : null, 'signed_by' => $signed ? Auth::id() : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $paperId]);
        Brgy::log($signed ? 'sign' : 'unsign', 'project', (int)$pp['project_id'], $pp['name'], ['page_key' => 'brgy.projects']);
    }

    /** A paper not needed for this transaction (approvers, with the reason); $undo puts it back */
    public static function waive(int $paperId, string $reason, bool $undo = false): void
    {
        if (!Access::can('brgy.projects', 'approve') && !Access::can('brgy.approvals', 'approve')) {
            fail('Only an approver marks a paper as not needed.');
        }
        $pp = self::paper($paperId);
        if (!$undo && trim($reason) === '') {
            fail('Enter why the paper is not needed.');
        }
        DB::update('brgy_project_papers', ['status' => $undo ? 'open' : 'waived', 'waive_reason' => $undo ? null : mb_substr($reason, 0, 255), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $paperId]);
        Brgy::event((int)$pp['project_id'], 'sop', $pp['name'] . ($undo ? ': needed again' : ': not needed'), ['details' => $undo ? null : $reason]);
        Brgy::log($undo ? 'unwaive' : 'waive', 'project', (int)$pp['project_id'], $pp['name'], ['reason' => $reason, 'page_key' => 'brgy.projects']);
    }

    /** A paper the SOP does not list, added to a step of this transaction */
    public static function addPaper(int $projectId, string $stepCode, array $d): int
    {
        self::need('edit', 'work on the transactions');
        $p = Brgy::mustProject($projectId);
        self::stepRow($p, $stepCode);
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            fail('Enter the name of the paper.');
        }
        $code = 'x' . substr(md5($name . microtime()), 0, 10);
        return DB::insert('brgy_project_papers', ['project_id' => $projectId, 'step_code' => $stepCode, 'paper_code' => $code, 'name' => mb_substr($name, 0, 160),
            'doc_type' => isset(Brgy::docTypes()[$d['doc_type'] ?? '']) ? $d['doc_type'] : 'other', 'template_code' => null, 'source' => 'received', 'copies' => max(1, (int)($d['copies'] ?? 1)),
            'needs_signature' => !empty($d['needs_signature']) ? 1 : 0, 'sort_order' => 900]);
    }

    // ================================================================== canvass

    public static function canvass(int $projectId): array
    {
        return DB::all('SELECT c.*, d.title AS doc_title FROM brgy_canvass c LEFT JOIN brgy_documents d ON d.id = c.document_id WHERE c.project_id = :p ORDER BY c.sort_order, c.total_amount, c.id', ['p' => $projectId]);
    }

    /** A supplier's quotation (with its signed canvass form) */
    public static function saveCanvass(int $projectId, array $d, ?array $file, ?int $id = null): int
    {
        self::need('edit', 'record the canvass');
        $p = Brgy::mustProject($projectId);
        if ($p['status'] !== 'open') {
            fail('The canvass is recorded before the award.');
        }
        $name = trim((string)($d['supplier_name'] ?? ''));
        $isCo = !empty($d['is_company']) ? 1 : 0;
        if ($isCo && $name === '') {
            $name = (string)($p['tradename'] ?: Brgy::defaultTradename());   // v33: the tradename of this transaction
        }
        if ($name === '') {
            fail('Enter the name of the supplier.');
        }
        $total = $isCo ? Brgy::lineTotals($projectId)['bid'] : r2(to_num($d['total_amount'] ?? 0));
        if ($total <= 0) {
            fail('Enter the total amount quoted' . ($isCo ? ' (the price schedule is empty)' : '') . '.');
        }
        if ($isCo && DB::value('SELECT id FROM brgy_canvass WHERE project_id = :p AND is_company = 1' . ($id ? ' AND id <> ' . (int)$id : ''), ['p' => $projectId])) {
            fail('The company\'s own quotation is already recorded.');
        }
        $remarks = in_array($d['remarks'] ?? '', ['passed', 'failed'], true) ? $d['remarks'] : null;
        $row = ['project_id' => $projectId, 'supplier_name' => mb_substr($name, 0, 160), 'is_company' => $isCo, 'vendor_id' => !empty($d['vendor_id']) ? (int)$d['vendor_id'] : null,
            'address' => mb_substr(trim((string)($d['address'] ?? '')), 0, 255) ?: null, 'philgeps_no' => mb_substr(trim((string)($d['philgeps_no'] ?? '')), 0, 40) ?: null,
            'contact_no' => mb_substr(trim((string)($d['contact_no'] ?? '')), 0, 60) ?: null, 'total_amount' => $total,
            'validity' => mb_substr(trim((string)($d['validity'] ?? '')), 0, 60) ?: null, 'brand' => mb_substr(trim((string)($d['brand'] ?? '')), 0, 120) ?: null,
            'delivery_period' => mb_substr(trim((string)($d['delivery_period'] ?? '')), 0, 60) ?: null, 'remarks' => $remarks,
            'received_date' => valid_date((string)($d['received_date'] ?? '')) ? $d['received_date'] : today(), 'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null,
            'updated_at' => date('Y-m-d H:i:s')];
        return DB::tx(function () use ($p, $row, $file, $id, $name) {
            if ($id) {
                DB::update('brgy_canvass', $row, 'id = :id AND project_id = :p', ['id' => $id, 'p' => $p['id']]);
            } else {
                $row += ['created_by' => Auth::id(), 'sort_order' => (int)DB::value('SELECT COUNT(*) FROM brgy_canvass WHERE project_id = :p', ['p' => $p['id']]) * 10 + 10];
                $id = DB::insert('brgy_canvass', $row);
            }
            if ($file) {
                $doc = BrgyDocs::store($file, ['scope' => 'project', 'project_id' => $p['id'], 'doc_type' => 'canvass', 'title' => 'Canvass form signed by ' . $name . ' - ' . $p['project_no'],
                    'issued_by' => $name, 'issue_date' => $row['received_date'], 'related_type' => 'canvass', 'related_id' => $id, 'status' => 'approved']);
                DB::update('brgy_canvass', ['document_id' => $doc], 'id = :id', ['id' => $id]);
            }
            Brgy::log('canvass', 'project', (int)$p['id'], $p['project_no'] . ' ' . $name, ['amount' => $row['total_amount'], 'page_key' => 'brgy.projects']);
            return $id;
        });
    }

    public static function deleteCanvass(int $projectId, int $id): void
    {
        self::need('edit', 'record the canvass');
        $p = Brgy::mustProject($projectId);
        if ($p['status'] !== 'open') {
            fail('The canvass is changed before the award only.');
        }
        DB::run('DELETE FROM brgy_canvass WHERE id = :id AND project_id = :p', ['id' => $id, 'p' => $projectId]);
        Brgy::log('canvass_delete', 'project', $projectId, $p['project_no'], ['page_key' => 'brgy.projects']);
    }

    /** The lowest quotation that passed */
    public static function lowest(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $r) {
            if ($r['remarks'] === 'passed' && ($best === null || (float)$r['total_amount'] < (float)$best['total_amount'])) {
                $best = $r;
            }
        }
        return $best;
    }

    /** The canvass is complete: the company's quotation is the lowest that passed (award) or it is not (lost) */
    private static function canvassResult(int $projectId): string
    {
        $p = Brgy::mustProject($projectId);
        $rows = self::canvass($projectId);
        $best = self::lowest($rows);
        $date = max(array_map(fn($r) => (string)$r['received_date'], $rows)) ?: today();
        if (!$best || !(int)$best['is_company']) {
            $mine = current(array_filter($rows, fn($r) => (int)$r['is_company'] === 1));
            $why = $mine && $mine['remarks'] === 'failed' ? 'The company\'s quotation failed the canvass.' : 'Another supplier quoted lower.';
            DB::update('brgy_projects', ['status' => 'lost', 'lost_reason' => 'Canvass: ' . $why, 'winning_bidder' => $best['supplier_name'] ?? null, 'winning_amount' => $best['total_amount'] ?? null,
                'sop_step' => null], 'id = :id', ['id' => $projectId]);
            Brgy::event($projectId, 'notice', 'Canvass: not awarded', ['details' => $why, 'direction' => 'in']);
            return 'The canvass did not go to the company: the transaction is recorded as lost.';
        }
        $t = Brgy::lineTotals($projectId);
        if (abs((float)$best['total_amount'] - $t['bid']) > 0.004) {
            fail('The company\'s quotation (' . money($best['total_amount']) . ') does not agree with the price schedule (' . money($t['bid']) . '). Save the quotation again after changing the prices.');
        }
        // the quotation is the company's offer: it is released as the bid, then awarded from the abstract and the purchase order
        $upd = ['bid_amount' => $t['bid'], 'est_cost' => $t['cost'], 'bid_status' => 'released', 'bid_version' => max(1, (int)$p['bid_version']), 'bid_submitted_at' => $date . ' 09:00:00'];
        if ($p['go_decision'] === 'pending') {
            $upd += ['go_decision' => 'go', 'go_reason' => 'Small value procurement (SOP)', 'go_by' => Auth::id(), 'go_at' => date('Y-m-d H:i:s')];
        }
        DB::update('brgy_projects', $upd, 'id = :id', ['id' => $projectId]);
        if ((int)$p['bid_version'] === 0) {
            Brgy::snapshotCosting(Brgy::mustProject($projectId), 'Quotation in the canvass');
        }
        Brgy::setPhase(Brgy::mustProject($projectId), 'evaluation', 'Canvass complete: the company quoted the lowest of ' . count($rows) . ' suppliers');
        $poDate = $p['po_date'] ?: $date;
        BrgyOps::recordAward($projectId, ['noa_ref' => $p['po_no'] ?: ($p['reference_no'] ?: $p['project_no']), 'noa_date' => $poDate, 'noa_received_date' => max($poDate, $date),
            'contract_amount' => $t['contract'], 'po_no' => $p['po_no'], 'contract_no' => $p['contract_no'], 'evidence_type' => 'po', 'sop_canvass' => 1]);
        $p = Brgy::mustProject($projectId);
        if ($p['po_no'] && $p['phase'] === 'award') {
            BrgyOps::startFulfillment($projectId);
        }
        return 'The company quoted the lowest of ' . count($rows) . ' suppliers: the purchase order is recorded as the award (' . money($t['contract']) . ').';
    }

    // ================================================================== payment (cheque) and delivery

    /** Taxes the barangay withholds from the payment (VAT and income tax, from the Withholding Tax Table) */
    public static function withholding(array $p, ?float $gross = null): array
    {
        $gross ??= Brgy::contractTotal($p) ?: Brgy::lineTotals((int)$p['id'])['bid'];
        $vatReg = Brgy::setting('brgy_bir_status') !== 'non_vat';
        $base = $vatReg && Brgy::on('brgy_prices_vat_inclusive') ? $gross / 1.12 : $gross;
        $ewtT = BrgyPost::whtType(Brgy::setting(in_array($p['category'], ['services', 'consultancy'], true) ? 'brgy_wht_services' : 'brgy_wht_goods'));
        $vatT = $vatReg ? BrgyPost::whtType(Brgy::setting('brgy_wht_vat')) : null;
        $ewtRate = $ewtT ? (float)$ewtT['rate'] : 0.0;
        $vatRate = $vatT ? (float)$vatT['rate'] : 0.0;
        $ewt = r2($base * $ewtRate / 100);
        $vat = r2($base * $vatRate / 100);
        return ['gross' => r2($gross), 'base' => r2($base), 'ewt' => $ewt, 'vat' => $vat, 'ewt_rate' => $ewtRate, 'vat_rate' => $vatRate, 'total' => r2($ewt + $vat), 'net' => r2($gross - $ewt - $vat)];
    }

    /** v34: the rates of the disbursement voucher, chosen on its Fill in the blanks */
    public const DV_VAT = ['5' => '5%', '3' => '3%'];
    public const DV_EWT = ['2' => '2%', '1' => '1%'];

    /**
     * v34: the taxes of the disbursement voucher from its gross amount, as the company computes them:
     *   VAT 5% = gross / 1.12 x 5%          VAT 3% = gross x 3%
     *   EWT 2% = gross / 1.12 x 2%          EWT 1% = gross x 1%, or gross / 1.12 x 1% beside the VAT of 5%
     *   (the company's DV: 29,750.00 with VAT 5% and EWT 1% = 1,328.13 + 265.63, check amount 28,156.24)
     * The check amount is the gross less the VAT and the EWT.
     */
    public static function dvCompute(float $gross, float $vatRate, float $ewtRate): array
    {
        $gross = r2(max(0, $gross));
        $net112 = $gross / 1.12;
        $vatOnNet = abs($vatRate - 5) < 0.001;
        $ewtOnNet = $ewtRate > 0 && !(abs($ewtRate - 1) < 0.001 && !$vatOnNet);
        $vat = $vatRate > 0 ? r2(($vatOnNet ? $net112 : $gross) * $vatRate / 100) : 0.0;
        $ewt = $ewtRate > 0 ? r2(($ewtOnNet ? $net112 : $gross) * $ewtRate / 100) : 0.0;
        $pct = fn(float $r) => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
        return ['gross' => $gross, 'base' => r2($net112), 'vat_rate' => $vatRate, 'ewt_rate' => $ewtRate, 'vat' => $vat, 'ewt' => $ewt, 'total' => r2($vat + $ewt),
            'net' => r2($gross - $vat - $ewt),
            'vat_how' => $vatRate > 0 ? number_format($gross, 2) . ($vatOnNet ? ' / 1.12' : '') . ' x ' . $pct($vatRate) : '',
            'ewt_how' => $ewtRate > 0 ? number_format($gross, 2) . ($ewtOnNet ? ' / 1.12' : '') . ' x ' . $pct($ewtRate) : ''];
    }

    /**
     * v34: the amounts of the disbursement voucher of a transaction. Once its gross amount, VAT or EWT is entered on the DV
     * (Fill in the blanks), they follow dvCompute(); until then, the withholding of Contracting Settings (as before).
     */
    public static function dvAmounts(array $p): array
    {
        $old = self::withholding($p);
        $entered = ($p['dv_gross'] ?? null) !== null || ($p['dv_vat_rate'] ?? null) !== null || ($p['dv_ewt_rate'] ?? null) !== null;
        if (!$entered) {
            $pct = fn(float $r) => rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
            return $old + ['entered' => false,
                'vat_how' => $old['vat'] > 0 ? number_format($old['base'], 2) . ' x ' . $pct($old['vat_rate']) : '',
                'ewt_how' => $old['ewt'] > 0 ? number_format($old['base'], 2) . ' x ' . $pct($old['ewt_rate']) : ''];
        }
        $gross = ($p['dv_gross'] ?? null) !== null ? (float)$p['dv_gross'] : $old['gross'];
        return self::dvCompute($gross, (float)($p['dv_vat_rate'] ?? 0), (float)($p['dv_ewt_rate'] ?? 0)) + ['entered' => true];
    }

    /** v34: keeps the gross amount, VAT and EWT entered on the disbursement voucher of a transaction */
    public static function saveDv(int $projectId, $gross, $vatRate, $ewtRate): array
    {
        if (!Brgy::v34Ready()) {
            return [];
        }
        $p = Brgy::mustProject($projectId);
        $raw = trim(str_replace([',', '₱', ' ', 'Php', 'PhP', 'PHP'], '', (string)$gross));
        if ($raw !== '' && (!is_numeric($raw) || (float)$raw < 0)) {
            fail('Enter the gross amount of the DV as an amount (for example 29,750.00).');
        }
        $vat = Brgy::rateKey(trim((string)$vatRate) === '' ? 0 : (float)$vatRate);
        $ewt = Brgy::rateKey(trim((string)$ewtRate) === '' ? 0 : (float)$ewtRate);
        $vatOk = $vat === '0' || isset(self::DV_VAT[$vat]) || $vat === Brgy::rateKey($p['dv_vat_rate'] ?? null);
        $ewtOk = $ewt === '0' || isset(self::DV_EWT[$ewt]) || $ewt === Brgy::rateKey($p['dv_ewt_rate'] ?? null);
        if (!$vatOk || !$ewtOk) {
            fail('Choose the VAT (5% or 3%) and the EWT (2% or 1%) of the DV from their lists.');
        }
        $set = ['dv_gross' => $raw === '' ? null : r2((float)$raw), 'dv_vat_rate' => (float)$vat, 'dv_ewt_rate' => (float)$ewt];
        $was = ['dv_gross' => $p['dv_gross'], 'dv_vat_rate' => $p['dv_vat_rate'], 'dv_ewt_rate' => $p['dv_ewt_rate']];
        $same = (string)$was['dv_gross'] === (string)($set['dv_gross'] === null ? '' : number_format($set['dv_gross'], 2, '.', ''))
            && Brgy::rateKey($was['dv_vat_rate']) === $vat && Brgy::rateKey($was['dv_ewt_rate']) === $ewt;
        if (!$same) {
            DB::update('brgy_projects', $set + ['updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
            Brgy::bumpVersion('brgy_projects', $projectId);
            Brgy::log('edit', 'project', $projectId, $p['project_no'] . ' DV amounts', ['before' => $was, 'after' => $set, 'page_key' => 'brgy.forms']);
        }
        return self::dvAmounts(Brgy::mustProject($projectId));
    }

    /** The cheque is ready (released by the barangay): the billing papers may now be prepared */
    public static function releasePayment(int $projectId, array $d): void
    {
        if (!Access::can('brgy.projects', 'edit') && !Access::can('brgy.collections', 'create')) {
            fail('Your role cannot record the payment.');
        }
        $p = Brgy::mustProject($projectId);
        $date = valid_date((string)($d['cheque_date'] ?? '')) ? $d['cheque_date'] : null;
        $no = mb_substr(trim((string)($d['cheque_no'] ?? '')), 0, 40);
        $amt = r2(to_num($d['cheque_amount'] ?? 0));
        if ($no === '' || !$date || $amt <= 0) {
            fail('Enter the cheque number, its date and its amount.');
        }
        // v34: the tradename the cheque is payable to: one of the tradenames (or the one of the transaction)
        $tn = null;
        if (Brgy::v34Ready() && array_key_exists('cheque_tradename', $d)) {
            $tn = mb_substr(trim((string)$d['cheque_tradename']), 0, 160);
            if ($tn !== '' && !isset(Brgy::tradenames()[$tn]) && $tn !== (string)$p['tradename'] && $tn !== (string)$p['cheque_tradename']) {
                fail('Choose the tradename of the cheque from the list (Barangay Contracts > Tradenames).');
            }
            $tn = $tn === '' ? null : $tn;
        }
        DB::tx(function () use ($p, $no, $date, $amt, $d, $tn) {
            $set = ['payment_status' => 'released', 'cheque_no' => $no, 'cheque_date' => $date, 'cheque_bank' => mb_substr(trim((string)($d['cheque_bank'] ?? '')), 0, 80) ?: null,
                'cheque_amount' => $amt, 'payment_released_at' => date('Y-m-d H:i:s'), 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
            if (Brgy::v34Ready() && array_key_exists('cheque_tradename', $d)) {
                $set['cheque_tradename'] = $tn;
            }
            if (!empty($d['dv_no'])) {
                $set['dv_no'] = mb_substr(trim((string)$d['dv_no']), 0, 40);
            }
            DB::update('brgy_projects', $set, 'id = :id', ['id' => $p['id']]);
            Brgy::bumpVersion('brgy_projects', (int)$p['id']);
            $w = self::dvAmounts($p);
            Brgy::event((int)$p['id'], 'payment', 'Cheque ready: ' . $no . ' for ' . money($amt), ['direction' => 'in',
                'details' => 'Dated ' . fdate($date) . ($set['cheque_bank'] ? ', ' . $set['cheque_bank'] : '') . ($tn ? ', payable to ' . $tn : '') . '. Expected net of taxes withheld: ' . money($w['net']) . '.']);
            Brgy::log('payment_released', 'project', (int)$p['id'], $p['project_no'] . ' cheque ' . $no, ['amount' => $amt, 'tradename' => $tn, 'page_key' => 'brgy.projects']);
            self::refreshPapers((int)$p['id'], ['txn_form', 'dv']);
        });
    }

    /** Payment pending: a follow-up with the barangay, kept on the timeline */
    public static function followUp(int $projectId, string $note, string $nextDate = ''): void
    {
        if (!Access::can('brgy.projects', 'edit') && !Access::can('brgy.collections', 'create')) {
            fail('Your role cannot follow up payments.');
        }
        if (trim($note) === '') {
            fail('Enter what the barangay said.');
        }
        $p = Brgy::mustProject($projectId);
        Brgy::event((int)$p['id'], 'payment', 'Payment follow-up: ' . mb_substr($note, 0, 120), ['details' => $note, 'direction' => 'out',
            'due_at' => valid_date($nextDate) ? $nextDate . ' 09:00:00' : null, 'status' => valid_date($nextDate) ? 'open' : 'done']);
    }

    /** Delivery and acceptance in one go, from the signed IAR (small value: everything delivered at once) */
    public static function deliverAll(int $projectId, array $d): int
    {
        $p = Brgy::mustProject($projectId);
        $iar = DB::row("SELECT * FROM brgy_project_papers WHERE project_id = :p AND paper_code = 'iar' AND document_id IS NOT NULL ORDER BY id LIMIT 1", ['p' => $projectId]);
        $date = valid_date((string)($d['delivery_date'] ?? '')) ? $d['delivery_date'] : ($p['iar_date'] ?: today());
        $acc = valid_date((string)($d['acceptance_date'] ?? '')) ? $d['acceptance_date'] : ($p['iar_date'] ?: $date);
        Brgy::refreshLines($projectId);
        $lines = [];
        foreach (Brgy::lines($projectId) as $l) {
            $left = r2((float)$l['qty'] - ((float)$l['qty_delivered'] - (float)$l['qty_rejected']));
            if ($left > 0) {
                $lines[$l['id']] = ['qty' => $left];
            }
        }
        if (!$lines) {
            fail('Everything on the contract has been delivered.');
        }
        return DB::tx(function () use ($p, $projectId, $lines, $date, $acc, $iar, $d) {
            $did = BrgyOps::saveDelivery(['project_id' => $projectId, 'delivery_date' => $date, 'kind' => $p['category'] === 'works' ? 'works' : ($p['category'] === 'goods' ? 'goods' : 'service'),
                'from_site_id' => $d['from_site_id'] ?? null, 'delivered_to' => $p['delivery_site'], 'received_by_name' => $p['inspector'], 'received_position' => $p['inspector_position'],
                'notes' => 'Delivered in full (SOP)', 'lines' => $lines]);
            $acceptLines = [];
            foreach (DB::all('SELECT id, qty FROM brgy_delivery_lines WHERE delivery_id = :d', ['d' => $did]) as $dl) {
                $acceptLines[$dl['id']] = ['accepted' => $dl['qty'], 'rejected' => 0];
            }
            BrgyOps::recordInspection($did, ['acceptance_date' => $acc, 'inspection_date' => $acc, 'acceptance_ref' => $p['iar_no'] ?: ('IAR ' . $p['project_no']),
                'inspectors' => $p['inspector'], 'acceptance_doc_id' => $iar['document_id'] ?? null, 'lines' => $acceptLines]);
            return $did;
        });
    }

    // ================================================================== lists for the dashboard and the report

    /** Transactions of the SOP still open, by where they stand */
    public static function board(): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all("SELECT p.id, p.project_no, p.title, p.process, p.sop_step, p.status, p.payment_status, p.contract_amount, p.abc_amount, p.bid_amount, p.cheque_no,
                               p.transmittal_no, p.released_at, p.transmittal_returned_at, p.txn_form_no, e.official_name AS entity_name, s.step_no, s.title AS step_title, s.action,
                               (SELECT COUNT(*) FROM brgy_project_steps ps WHERE ps.project_id = p.id AND ps.status <> 'pending') AS steps_done,
                               (SELECT COUNT(*) FROM brgy_sop_steps x WHERE x.process = p.process AND x.is_active = 1) AS steps_total
                          FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id LEFT JOIN brgy_sop_steps s ON s.code = p.sop_step
                         WHERE p.process IN ('svp', 'bidding') AND p.status IN ('open', 'awarded')
                         ORDER BY s.sort_order, p.id");
    }
}
