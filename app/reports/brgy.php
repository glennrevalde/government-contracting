<?php
declare(strict_types=1);

/**
 * Barangay Contracts reports: bids, compliance, submissions, contract status, deliveries, billing packs, receivables,
 * collections, change orders, retention, profitability, taxes withheld, papers, the cash forecast, the tie-out to
 * the ledger and the approval log.
 */

function brgy_rpt_empty(): array
{
    return ['columns' => [['key' => 'x', 'label' => '']], 'rows' => [], 'notes' => 'Run database/migrations/2026_10_29_barangay_contracting.sql to use the Barangay Contracts reports.'];
}

function brgy_rpt_project_link(array $r): ?string
{
    return Report::link('brgy.projects', ['a' => 'view', 'id' => $r['project_id'] ?? $r['id']]);
}

function brgy_rpt_entity_filter(): array
{
    return ['entity_id' => ['Procuring entity', fn() => Brgy::ready() ? Brgy::entities(false) : []]];
}

function brgy_rpt_entity_sql(array $f, string $col, array &$p): string
{
    if (($f['entity_id'] ?? '') === '') {
        return '';
    }
    $p['ent'] = (int)$f['entity_id'];
    return " AND $col = :ent";
}

/** Open receivable of a billing's invoice (and the date it fell due) */
function brgy_rpt_open(array $b): float
{
    if (!$b['invoice_txn_id']) {
        return 0.0;
    }
    $t = Txn::load((int)$b['invoice_txn_id']);
    return $t ? Balances::open($t) : 0.0;
}

// ---------------------------------------------------------------------------------------------- bids

Report::define('brgy_bid_register', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => brgy_rpt_entity_filter() + ['status' => ['Status', fn() => Brgy::STATUSES]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to'] . ' 23:59:59'];
        $sql = "SELECT p.*, e.official_name, m.name AS mode_name FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id LEFT JOIN brgy_proc_modes m ON m.id = p.mode_id
                 WHERE COALESCE(p.bid_deadline, p.posting_date, p.created_at) BETWEEN :f AND :t" . brgy_rpt_entity_sql($f, 'p.entity_id', $p);
        if (isset(Brgy::STATUSES[$f['status'] ?? ''])) {
            $sql .= ' AND p.status = :st';
            $p['st'] = $f['status'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY COALESCE(p.bid_deadline, p.posting_date), p.id', $p) as $r) {
            $rows[] = ['no' => $r['project_no'], 'title' => $r['title'], 'entity' => $r['official_name'], 'mode' => $r['mode_name'], 'category' => Brgy::label(Brgy::CATEGORIES, $r['category']),
                'ref' => $r['reference_no'], 'abc' => (float)$r['abc_amount'], 'bid' => (float)$r['bid_amount'], 'pct' => (float)$r['abc_amount'] > 0 && (float)$r['bid_amount'] > 0 ? round((float)$r['bid_amount'] / (float)$r['abc_amount'] * 100, 1) : '',
                'deadline' => $r['bid_deadline'], 'submitted' => $r['bid_submitted_at'], 'bid_status' => Brgy::WF[$r['bid_status']] ?? $r['bid_status'], 'status' => Brgy::label(Brgy::STATUSES, $r['status']),
                'phase' => Brgy::phaseLabel($r['phase']), '_links' => ['no' => brgy_rpt_project_link($r)]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Project'], ['key' => 'title', 'label' => 'Title'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'mode', 'label' => 'Mode'],
            ['key' => 'category', 'label' => 'Category'], ['key' => 'ref', 'label' => 'RFQ / ITB'], ['key' => 'abc', 'label' => 'ABC', 'type' => 'money'], ['key' => 'bid', 'label' => 'Bid', 'type' => 'money'],
            ['key' => 'pct', 'label' => '% of ABC', 'type' => 'pct'], ['key' => 'deadline', 'label' => 'Deadline', 'type' => 'datetime'], ['key' => 'submitted', 'label' => 'Submitted', 'type' => 'datetime'],
            ['key' => 'bid_status', 'label' => 'Bid'], ['key' => 'status', 'label' => 'Status'], ['key' => 'phase', 'label' => 'Phase']],
            'rows' => $rows, 'totals' => ['no' => 'TOTAL (' . count($rows) . ')', 'abc' => r2(array_sum(array_column($rows, 'abc'))), 'bid' => r2(array_sum(array_column($rows, 'bid')))],
            'notes' => 'Opportunities by bid deadline (or posting date when there is no deadline yet).'];
    },
]);

Report::define('brgy_win_loss', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => brgy_rpt_entity_filter(),
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to'] . ' 23:59:59'];
        $rows = [];
        $won = $lost = $other = 0;
        $wonAmt = $lostAmt = 0.0;
        $by = [];
        foreach (DB::all("SELECT p.*, e.official_name FROM brgy_projects p JOIN brgy_entities e ON e.id = p.entity_id
                           WHERE p.bid_submitted_at BETWEEN :f AND :t" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY p.bid_submitted_at', $p) as $r) {
            $res = in_array($r['status'], ['awarded', 'closed'], true) ? 'Won' : ($r['status'] === 'lost' ? 'Lost' : ($r['status'] === 'cancelled' ? 'Cancelled by the entity' : 'Waiting for the result'));
            $res === 'Won' ? $won++ : ($res === 'Lost' ? $lost++ : $other++);
            if ($res === 'Won') {
                $wonAmt += (float)$r['bid_amount'];
            } elseif ($res === 'Lost') {
                $lostAmt += (float)$r['bid_amount'];
            }
            $cat = Brgy::label(Brgy::CATEGORIES, $r['category']);
            $by[$cat] = ($by[$cat] ?? 0) + ($res === 'Won' ? (float)$r['bid_amount'] : 0);
            $rows[] = ['no' => $r['project_no'], 'title' => $r['title'], 'entity' => $r['official_name'], 'category' => $cat, 'submitted' => $r['bid_submitted_at'], 'bid' => (float)$r['bid_amount'],
                'result' => $res, 'rank' => $r['result_rank'], 'winner' => $res === 'Won' ? 'Our company' : $r['winning_bidder'], 'winning' => $res === 'Won' ? (float)$r['bid_amount'] : ($r['winning_amount'] !== null ? (float)$r['winning_amount'] : ''),
                'gap' => $res === 'Lost' && $r['winning_amount'] !== null && (float)$r['winning_amount'] > 0 ? round(((float)$r['bid_amount'] - (float)$r['winning_amount']) / (float)$r['winning_amount'] * 100, 1) : '',
                'reason' => $res === 'Lost' ? $r['lost_reason'] : ($res === 'Cancelled by the entity' ? $r['cancel_reason'] : ''), '_links' => ['no' => brgy_rpt_project_link($r)]];
        }
        $decided = $won + $lost;
        return ['columns' => [['key' => 'no', 'label' => 'Project'], ['key' => 'title', 'label' => 'Title'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'category', 'label' => 'Category'],
            ['key' => 'submitted', 'label' => 'Submitted', 'type' => 'date'], ['key' => 'bid', 'label' => 'Our bid', 'type' => 'money'], ['key' => 'result', 'label' => 'Result'], ['key' => 'rank', 'label' => 'Rank', 'type' => 'int'],
            ['key' => 'winner', 'label' => 'Awarded to'], ['key' => 'winning', 'label' => 'Winning bid', 'type' => 'money'], ['key' => 'gap', 'label' => 'Our bid above it', 'type' => 'pct'], ['key' => 'reason', 'label' => 'Reason']],
            'rows' => $rows, 'totals' => ['no' => 'TOTAL (' . count($rows) . ')', 'bid' => r2(array_sum(array_column($rows, 'bid')))],
            'chart' => ['bars' => ['Won' => $wonAmt, 'Lost' => $lostAmt], 'bars_title' => 'Bid amounts won and lost', 'pie' => array_filter($by), 'pie_title' => 'Won, by category'],
            'notes' => h($won . ' won, ' . $lost . ' lost, ' . $other . ' cancelled or waiting. Win rate ' . ($decided ? round($won / $decided * 100) . '% by count, ' . (($wonAmt + $lostAmt) > 0 ? round($wonAmt / ($wonAmt + $lostAmt) * 100) : 0) . '% by amount' : 'not yet available') . '.')];
    },
]);

Report::define('brgy_eligibility', [
    'filters' => [],
    'sortable' => false,
    'extra_filters' => ['scope' => ['Projects', fn() => ['open' => 'Open (bids being prepared or waiting)', 'awarded' => 'Contracts in force', 'all' => 'All but closed'], null]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $scope = $f['scope'] ?: 'open';
        $where = ['open' => "p.status = 'open'", 'awarded' => "p.status = 'awarded'", 'all' => "p.status IN ('open', 'awarded')"][$scope] ?? "p.status = 'open'";
        $rows = [];
        $state = ['ok' => 'In order', 'waived' => 'Not required', 'pending' => 'For review', 'returned' => 'Returned', 'missing' => 'Missing', 'expired' => 'Expired'];
        foreach (DB::all("SELECT p.* FROM brgy_projects p WHERE $where ORDER BY p.bid_deadline IS NULL, p.bid_deadline, p.id") as $p) {
            $p = Brgy::project((int)$p['id']);
            $s = Brgy::checklistSummary((int)$p['id']);
            $rows[] = ['req' => $p['project_no'] . ' ' . $p['title'] . ' (' . $p['entity_name'] . ', ' . ($p['bid_deadline'] ? 'deadline ' . fdatetime($p['bid_deadline']) : Brgy::phaseLabel($p['phase'])) . '): '
                . ($s['blocking'] ? $s['blocking'] . ' mandatory not in order' : 'mandatory papers due so far in order'), '_class' => 'group-row', '_links' => ['req' => brgy_rpt_project_link($p)]];
            foreach (Brgy::checklist((int)$p['id'], $p) as $r) {
                $later = !$r['due'] && !in_array($r['state'], ['ok', 'waived'], true);
                $rows[] = ['stage' => Brgy::label(Brgy::STAGES, $r['stage']), 'req' => $r['name'], 'kind' => Brgy::label(Brgy::REQ_TYPES, $r['requirement_type']),
                    'doc' => $r['doc_title'] ?: (string)$r['by_record'], 'valid' => $r['valid_until'], 'state' => $later ? 'Later stage' : $state[$r['state']],
                    '_class' => 'indent-1' . (!$later && $r['requirement_type'] === 'mandatory' && !in_array($r['state'], ['ok', 'waived'], true) ? ' text-danger' : '')];
            }
        }
        $papers = [];
        foreach (DB::all('SELECT * FROM brgy_doc_types WHERE is_company = 1 AND is_active = 1 ORDER BY sort_order') as $t) {
            $d = DB::row("SELECT * FROM brgy_documents WHERE scope = 'company' AND doc_type = :t AND is_current = 1 AND status <> 'void' ORDER BY COALESCE(valid_until, '9999-12-31') DESC LIMIT 1", ['t' => $t['code']]);
            $papers[] = $t['name'] . ': ' . (!$d ? 'not on file' : ($d['valid_until'] && $d['valid_until'] < today() ? 'expired ' . fdate($d['valid_until']) : ($d['valid_until'] ? 'valid until ' . fdate($d['valid_until']) : 'on file')));
        }
        return ['grouped' => true, 'columns' => [['key' => 'req', 'label' => 'Requirement'], ['key' => 'stage', 'label' => 'Stage'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'doc', 'label' => 'Document'],
            ['key' => 'valid', 'label' => 'Valid until', 'type' => 'date'], ['key' => 'state', 'label' => 'Status']], 'rows' => $rows,
            'notes' => '<b>Company papers:</b> ' . h(implode('; ', $papers)) . '.'];
    },
]);

Report::define('brgy_submissions', [
    'filters' => ['range'],
    'default_dates' => 'this_quarter',
    'extra_filters' => brgy_rpt_entity_filter() + ['sub_type' => ['What', fn() => Brgy::SUB_TYPES]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to'] . ' 23:59:59'];
        $sql = 'SELECT s.*, p.project_no, p.title, p.bid_deadline, e.official_name, u.full_name FROM brgy_submissions s JOIN brgy_projects p ON p.id = s.project_id JOIN brgy_entities e ON e.id = p.entity_id
                  LEFT JOIN users u ON u.id = s.submitted_by WHERE s.submitted_at BETWEEN :f AND :t' . brgy_rpt_entity_sql($f, 'p.entity_id', $p);
        if (isset(Brgy::SUB_TYPES[$f['sub_type'] ?? ''])) {
            $sql .= ' AND s.sub_type = :ty';
            $p['ty'] = $f['sub_type'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY s.submitted_at', $p) as $s) {
            $rows[] = ['when' => $s['submitted_at'], 'no' => $s['project_no'], 'entity' => $s['official_name'], 'what' => Brgy::label(Brgy::SUB_TYPES, $s['sub_type']), 'how' => Brgy::label(Brgy::SUB_METHODS, $s['method']),
                'to' => trim($s['recipient_name'] . ($s['recipient_office'] ? ', ' . $s['recipient_office'] : ''), ', '), 'tracking' => $s['tracking_no'], 'received' => trim($s['received_by'] . ' ' . fdatetime($s['received_at'])),
                'ack' => $s['ack_ref'], 'proof' => $s['proof_doc_id'] ? 'Yes' : 'No', 'ontime' => $s['sub_type'] === 'bid' && $s['bid_deadline'] ? ($s['submitted_at'] <= $s['bid_deadline'] ? 'On time' : 'LATE') : '',
                'by' => $s['full_name'], '_links' => ['no' => brgy_rpt_project_link($s)]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'Submitted', 'type' => 'datetime'], ['key' => 'no', 'label' => 'Project'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'what', 'label' => 'What'],
            ['key' => 'how', 'label' => 'How'], ['key' => 'to', 'label' => 'Addressed to'], ['key' => 'tracking', 'label' => 'Tracking / ref.'], ['key' => 'received', 'label' => 'Received by / at'], ['key' => 'ack', 'label' => 'Acknowledgment'],
            ['key' => 'proof', 'label' => 'Proof on file'], ['key' => 'ontime', 'label' => 'Deadline'], ['key' => 'by', 'label' => 'Submitted by']], 'rows' => $rows,
            'totals' => ['when' => 'TOTAL (' . count($rows) . ')'], 'notes' => 'What actually went to the procuring entities, with the proof of each submission.'];
    },
]);

// ---------------------------------------------------------------------------------------------- contracts

Report::define('brgy_contract_status', [
    'filters' => [],
    'extra_filters' => brgy_rpt_entity_filter() + ['scope' => ['Contracts', fn() => ['awarded' => 'In force', 'closed' => 'Closed', 'all' => 'All'], null]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = [];
        $st = ['awarded' => "('awarded')", 'closed' => "('closed')", 'all' => "('awarded', 'closed')"][$f['scope'] ?: 'awarded'] ?? "('awarded')";
        $rows = [];
        $sum = [];
        foreach (DB::all("SELECT p.id FROM brgy_projects p WHERE p.status IN $st" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY p.project_no', $p) as $x) {
            $r = Brgy::project((int)$x['id']);
            $m = Brgy::money($r);
            $row = ['no' => $r['project_no'], 'title' => $r['title'], 'entity' => $r['entity_name'], 'due' => $r['due_date'], 'contract' => $m['contract'], 'accepted' => $m['accepted'],
                'pct' => $m['contract'] > 0 ? round($m['accepted'] / $m['contract'] * 100, 1) : 0, 'billed' => $m['billed'], 'settled' => $m['settled'], 'cash' => $m['cash'], 'taxes' => $m['taxes'],
                'retention' => $m['retention_held'], 'ar' => $m['ar_open'], 'phase' => Brgy::phaseLabel($r['phase']), '_links' => ['no' => brgy_rpt_project_link($r)]];
            foreach (['contract', 'accepted', 'billed', 'settled', 'cash', 'taxes', 'retention', 'ar'] as $k) {
                $sum[$k] = ($sum[$k] ?? 0) + $row[$k];
            }
            $rows[] = $row;
        }
        return ['columns' => [['key' => 'no', 'label' => 'Contract'], ['key' => 'title', 'label' => 'Title'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'due', 'label' => 'Due', 'type' => 'date'],
            ['key' => 'contract', 'label' => 'Contract amount', 'type' => 'money'], ['key' => 'accepted', 'label' => 'Accepted', 'type' => 'money'], ['key' => 'pct', 'label' => '% accepted', 'type' => 'pct'],
            ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'], ['key' => 'settled', 'label' => 'Settled', 'type' => 'money'], ['key' => 'cash', 'label' => 'Cash collected', 'type' => 'money'],
            ['key' => 'taxes', 'label' => 'Taxes withheld', 'type' => 'money'], ['key' => 'retention', 'label' => 'Retention held', 'type' => 'money'], ['key' => 'ar', 'label' => 'Receivable', 'type' => 'money'], ['key' => 'phase', 'label' => 'Phase']],
            'rows' => $rows, 'totals' => ['no' => 'TOTAL (' . count($rows) . ')'] + array_map('r2', $sum),
            'notes' => 'Awarded, accepted, billed and collected for each contract. Receivable and retention come from the ledger (Customer:Job of the contract).'];
    },
]);

Report::define('brgy_delivery_variance', [
    'filters' => [],
    'sortable' => false,
    'extra_filters' => brgy_rpt_entity_filter(),
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = [];
        $rows = [];
        foreach (DB::all("SELECT p.id FROM brgy_projects p WHERE p.status = 'awarded'" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY p.due_date IS NULL, p.due_date', $p) as $x) {
            $pr = Brgy::project((int)$x['id']);
            $late = $pr['due_date'] && $pr['due_date'] < today() ? (int)round((strtotime(today()) - strtotime($pr['due_date'])) / 86400) : 0;
            $rows[] = ['line' => $pr['project_no'] . ' ' . $pr['title'] . ' (' . $pr['entity_name'] . '), due ' . (fdate($pr['due_date']) ?: 'not set') . ($late ? ', ' . $late . ' day(s) late' : ''), '_class' => 'group-row', '_links' => ['line' => brgy_rpt_project_link($pr)]];
            foreach (Brgy::lines((int)$pr['id']) as $l) {
                $left = max(0, (float)$l['qty'] - (float)$l['qty_accepted']);
                $ldExposure = $late && $left > 0 ? r2($left * (float)$l['unit_price'] * (float)$pr['ld_rate_pct'] / 100 * $late) : 0;
                $rows[] = ['line' => $l['line_no'] . '. ' . $l['description'], 'unit' => $l['unit'], 'contract' => (float)$l['qty'], 'delivered' => (float)$l['qty_delivered'], 'rejected' => (float)$l['qty_rejected'],
                    'accepted' => (float)$l['qty_accepted'], 'left' => $left, 'pct' => (float)$l['qty'] > 0 ? round((float)$l['qty_accepted'] / (float)$l['qty'] * 100, 1) : 0,
                    'value_left' => r2($left * (float)$l['unit_price']), 'ld' => $ldExposure ?: '', '_class' => 'indent-1'];
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'line', 'label' => 'Contract / line'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'contract', 'label' => 'Contract qty', 'type' => 'qty'],
            ['key' => 'delivered', 'label' => 'Delivered', 'type' => 'qty'], ['key' => 'rejected', 'label' => 'Rejected', 'type' => 'qty'], ['key' => 'accepted', 'label' => 'Accepted', 'type' => 'qty'],
            ['key' => 'left', 'label' => 'Still to be accepted', 'type' => 'qty'], ['key' => 'pct', 'label' => '% done', 'type' => 'pct'], ['key' => 'value_left', 'label' => 'Value left', 'type' => 'money'],
            ['key' => 'ld', 'label' => 'Liquidated damages exposure', 'type' => 'money']], 'rows' => $rows,
            'notes' => 'Liquidated damages exposure: the value not yet accepted x the rate of the contract x the days past the due date (an estimate; the entity computes the actual deduction).'];
    },
]);

Report::define('brgy_inspection', [
    'filters' => ['range'],
    'default_dates' => 'this_quarter',
    'extra_filters' => brgy_rpt_entity_filter(),
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to']];
        $rows = [];
        foreach (DB::all("SELECT d.*, p.project_no, p.id AS project_id, e.official_name,
                                 (SELECT COALESCE(SUM(ROUND(dl.qty_accepted * pl.unit_price, 2)), 0) FROM brgy_delivery_lines dl JOIN brgy_project_lines pl ON pl.id = dl.project_line_id WHERE dl.delivery_id = d.id) AS acc_value,
                                 (SELECT COALESCE(SUM(dl.qty_rejected), 0) FROM brgy_delivery_lines dl WHERE dl.delivery_id = d.id) AS rej_qty
                            FROM brgy_deliveries d JOIN brgy_projects p ON p.id = d.project_id JOIN brgy_entities e ON e.id = p.entity_id
                           WHERE d.status <> 'void' AND d.delivery_date BETWEEN :f AND :t" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY d.delivery_date, d.id', $p) as $d) {
            $days = $d['acceptance_date'] ? (int)round((strtotime($d['acceptance_date']) - strtotime($d['delivery_date'])) / 86400) : (int)round((strtotime(today()) - strtotime($d['delivery_date'])) / 86400);
            $rows[] = ['dr' => $d['dr_no'], 'no' => $d['project_no'], 'entity' => $d['official_name'], 'delivered' => $d['delivery_date'], 'received' => $d['received_by_name'], 'accepted_on' => $d['acceptance_date'],
                'days' => $days, 'ref' => $d['acceptance_ref'], 'inspectors' => $d['inspectors'], 'value' => (float)$d['acc_value'], 'rejected' => (float)$d['rej_qty'] ?: '', 'status' => Brgy::WF[$d['status']] ?? ucfirst($d['status']),
                'evidence' => $d['acceptance_doc_id'] ? 'Yes' : 'No', '_links' => ['dr' => Report::link('brgy.deliveries', ['a' => 'view', 'id' => $d['id']]), 'no' => brgy_rpt_project_link($d)]];
        }
        return ['columns' => [['key' => 'dr', 'label' => 'Delivery'], ['key' => 'no', 'label' => 'Project'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'delivered', 'label' => 'Delivered', 'type' => 'date'],
            ['key' => 'received', 'label' => 'Received by'], ['key' => 'accepted_on', 'label' => 'Accepted', 'type' => 'date'], ['key' => 'days', 'label' => 'Days to acceptance', 'type' => 'int'],
            ['key' => 'ref', 'label' => 'Inspection report'], ['key' => 'inspectors', 'label' => 'Inspected by'], ['key' => 'value', 'label' => 'Value accepted', 'type' => 'money'], ['key' => 'rejected', 'label' => 'Qty rejected', 'type' => 'qty'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'evidence', 'label' => 'Report on file']], 'rows' => $rows,
            'totals' => ['dr' => 'TOTAL (' . count($rows) . ')', 'value' => r2(array_sum(array_column($rows, 'value')))], 'notes' => 'Days to acceptance counts to today for deliveries still waiting.'];
    },
]);

Report::define('brgy_billing_pack', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => brgy_rpt_entity_filter() + ['incomplete' => ['Show', fn() => ['1' => 'Incomplete packs only'], 'All']],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to']];
        $rows = [];
        foreach (DB::all("SELECT b.*, p.project_no, e.official_name FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id JOIN brgy_entities e ON e.id = p.entity_id
                           WHERE b.status <> 'void' AND b.billing_date BETWEEN :f AND :t" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY b.billing_date, b.id', $p) as $b) {
            $pack = BrgyOps::packStatus($b);
            $req = array_filter($pack, fn($x) => $x['required']);
            $miss = array_filter($req, fn($x) => !$x['ok']);
            if (($f['incomplete'] ?? '') === '1' && !$miss) {
                continue;
            }
            $rows[] = ['no' => $b['billing_no'], 'project' => $b['project_no'], 'entity' => $b['official_name'], 'date' => $b['billing_date'], 'amount' => (float)$b['gross_amount'], 'status' => Brgy::WF[$b['status']] ?? $b['status'],
                'have' => (count($req) - count($miss)) . ' of ' . count($req), 'missing' => implode(', ', array_column($miss, 'name')), 'submitted' => $b['submitted_at'], 'ack' => $b['acknowledged_at'],
                'waiting' => $b['status'] === 'submitted' && $b['submitted_at'] ? (int)round((time() - strtotime((string)$b['submitted_at'])) / 86400) : '',
                '_links' => ['no' => Report::link('brgy.billing', ['a' => 'view', 'id' => $b['id']]), 'project' => brgy_rpt_project_link($b)]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Billing'], ['key' => 'project', 'label' => 'Project'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'status', 'label' => 'Status'], ['key' => 'have', 'label' => 'Required papers'], ['key' => 'missing', 'label' => 'Missing'],
            ['key' => 'submitted', 'label' => 'Submitted', 'type' => 'datetime'], ['key' => 'ack', 'label' => 'Acknowledged', 'type' => 'datetime'], ['key' => 'waiting', 'label' => 'Days waiting for acknowledgment', 'type' => 'int']],
            'rows' => $rows, 'totals' => ['no' => 'TOTAL (' . count($rows) . ')', 'amount' => r2(array_sum(array_column($rows, 'amount')))],
            'notes' => 'The papers a pack must hold are set on Contracting Settings.'];
    },
]);

Report::define('brgy_ar_aging', [
    'filters' => ['asof'],
    'sortable' => false,
    'extra_filters' => brgy_rpt_entity_filter(),
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $asof = $f['asof'];
        $p = ['d' => $asof];
        $rows = [];
        $tot = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'over' => 0, 'open' => 0];
        $bars = ['Current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, 'Over 90' => 0];
        $lastEntity = null;
        $sub = null;
        $flush = function () use (&$rows, &$sub, &$lastEntity) {
            if ($sub && $lastEntity !== null) {
                $rows[] = ['entity' => 'Total ' . $lastEntity] + array_map('r2', $sub) + ['_class' => 'subtotal-row'];
            }
        };
        foreach (DB::all("SELECT b.*, p.project_no, p.id AS project_id, e.official_name, t.txn_no, t.due_date AS inv_due, t.txn_date, t.id AS tid
                            FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id JOIN brgy_entities e ON e.id = p.entity_id JOIN transactions t ON t.id = b.invoice_txn_id
                           WHERE b.status IN ('invoiced', 'submitted', 'acknowledged') AND t.is_voided = 0 AND t.txn_date <= :d" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY e.official_name, t.due_date', $p) as $b) {
            $t = Txn::load((int)$b['tid']);
            $open = Balances::open($t, $asof);
            if ($open <= 0.004) {
                continue;
            }
            if ($b['official_name'] !== $lastEntity) {
                $flush();
                $lastEntity = $b['official_name'];
                $sub = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'over' => 0, 'open' => 0];
                $rows[] = ['entity' => $b['official_name'], '_class' => 'group-row'];
            }
            $days = $b['inv_due'] ? (int)floor((strtotime($asof) - strtotime((string)$b['inv_due'])) / 86400) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'over')));
            $row = ['entity' => $b['project_no'] . ', billing ' . $b['billing_no'], 'invoice' => $b['txn_no'], 'si' => $b['si_no'], 'date' => $b['txn_date'], 'due' => $b['inv_due'], 'days' => max(0, $days),
                'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'over' => 0, 'open' => $open, '_class' => 'indent-1',
                '_links' => ['entity' => Report::link('brgy.billing', ['a' => 'view', 'id' => $b['id']]), 'invoice' => Report::txnLink(['txn_type' => 'invoice', 'id' => $b['tid']])]];
            $row[$bucket] = $open;
            foreach (['current', 'd30', 'd60', 'd90', 'over', 'open'] as $k) {
                $sub[$k] += $row[$k];
                $tot[$k] += $row[$k];
            }
            $bars[['current' => 'Current', 'd30' => '1-30', 'd60' => '31-60', 'd90' => '61-90', 'over' => 'Over 90'][$bucket]] += $open;
            $rows[] = $row;
        }
        $flush();
        return ['grouped' => true, 'columns' => [['key' => 'entity', 'label' => 'Entity / billing'], ['key' => 'invoice', 'label' => 'Invoice'], ['key' => 'si', 'label' => 'Sales invoice no.'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'due', 'label' => 'Due', 'type' => 'date'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'current', 'label' => 'Current', 'type' => 'money'], ['key' => 'd30', 'label' => '1-30', 'type' => 'money'],
            ['key' => 'd60', 'label' => '31-60', 'type' => 'money'], ['key' => 'd90', 'label' => '61-90', 'type' => 'money'], ['key' => 'over', 'label' => 'Over 90', 'type' => 'money'], ['key' => 'open', 'label' => 'Open', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['entity' => 'TOTAL'] + array_map('r2', $tot), 'chart' => ['bars' => $bars],
            'notes' => 'Open invoices of the contracts, from the ledger, by procuring entity. Retention is not a receivable here (Retention and Warranty Tracker).'];
    },
]);

Report::define('brgy_collection_perf', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $rows = [];
        $pie = [];
        foreach (DB::all('SELECT id, official_name FROM brgy_entities ORDER BY official_name') as $e) {
            $billed = (float)DB::value("SELECT COALESCE(SUM(b.gross_amount), 0) FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE p.entity_id = :e AND b.status IN ('invoiced', 'submitted', 'acknowledged') AND b.billing_date BETWEEN :f AND :t",
                ['e' => $e['id'], 'f' => $f['from'], 't' => $f['to']]);
            $c = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(c.gross_applied), 0) AS settled, COALESCE(SUM(c.cash_received), 0) AS cash, COALESCE(SUM(c.ewt_amount + c.vatwh_amount), 0) AS taxes,
                                 COALESCE(AVG(DATEDIFF(c.collection_date, b.billing_date)), 0) AS days, COALESCE(AVG(DATEDIFF(c.collection_date, b.submitted_at)), 0) AS days_sub
                            FROM brgy_collections c JOIN brgy_billings b ON b.id = c.billing_id JOIN brgy_projects p ON p.id = c.project_id
                           WHERE p.entity_id = :e AND c.kind = 'billing' AND c.status = 'posted' AND c.collection_date BETWEEN :f AND :t", ['e' => $e['id'], 'f' => $f['from'], 't' => $f['to']]);
            $bounced = (int)DB::value("SELECT COUNT(*) FROM brgy_collections c JOIN brgy_projects p ON p.id = c.project_id WHERE p.entity_id = :e AND c.status = 'bounced' AND c.bounce_date BETWEEN :f AND :t", ['e' => $e['id'], 'f' => $f['from'], 't' => $f['to']]);
            $ar = 0.0;
            foreach (DB::all("SELECT b.invoice_txn_id FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id WHERE p.entity_id = :e AND b.status IN ('invoiced', 'submitted', 'acknowledged')", ['e' => $e['id']]) as $b) {
                $ar += brgy_rpt_open($b);
            }
            if ($billed == 0.0 && (float)$c['settled'] == 0.0 && $ar == 0.0) {
                continue;
            }
            $rows[] = ['entity' => $e['official_name'], 'billed' => r2($billed), 'settled' => r2((float)$c['settled']), 'cash' => r2((float)$c['cash']), 'taxes' => r2((float)$c['taxes']), 'n' => (int)$c['n'],
                'days' => (int)$c['n'] ? round((float)$c['days']) : '', 'days_sub' => (int)$c['n'] ? round((float)$c['days_sub']) : '', 'bounced' => $bounced ?: '', 'ar' => r2($ar),
                'rate' => $billed > 0 ? round((float)$c['settled'] / $billed * 100, 1) : '', '_links' => ['entity' => Report::link('brgy.entities', ['a' => 'view', 'id' => $e['id']])]];
            $pie[$e['official_name']] = r2((float)$c['cash']);
        }
        return ['columns' => [['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'], ['key' => 'settled', 'label' => 'Settled', 'type' => 'money'],
            ['key' => 'rate', 'label' => 'Settled / billed', 'type' => 'pct'], ['key' => 'cash', 'label' => 'Cash', 'type' => 'money'], ['key' => 'taxes', 'label' => 'Taxes withheld', 'type' => 'money'], ['key' => 'n', 'label' => 'Payments', 'type' => 'int'],
            ['key' => 'days', 'label' => 'Days from billing', 'type' => 'int'], ['key' => 'days_sub', 'label' => 'Days from submission', 'type' => 'int'], ['key' => 'bounced', 'label' => 'Returned checks', 'type' => 'int'],
            ['key' => 'ar', 'label' => 'Open now', 'type' => 'money']], 'rows' => $rows,
            'totals' => ['entity' => 'TOTAL', 'billed' => r2(array_sum(array_column($rows, 'billed'))), 'settled' => r2(array_sum(array_column($rows, 'settled'))), 'cash' => r2(array_sum(array_column($rows, 'cash'))),
                'taxes' => r2(array_sum(array_column($rows, 'taxes'))), 'ar' => r2(array_sum(array_column($rows, 'ar')))], 'chart' => ['pie' => array_filter($pie), 'pie_title' => 'Cash collected by entity'],
            'notes' => 'Billed and collected within the dates; days are the average time from the billing (and from its submission) to the payment.'];
    },
]);

Report::define('brgy_change_orders', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => brgy_rpt_entity_filter(),
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to']];
        $rows = [];
        foreach (DB::all("SELECT c.*, p.project_no, p.id AS project_id, p.contract_amount, e.official_name FROM brgy_change_orders c JOIN brgy_projects p ON p.id = c.project_id JOIN brgy_entities e ON e.id = p.entity_id
                           WHERE c.co_date BETWEEN :f AND :t" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY c.co_date, c.id', $p) as $c) {
            $rows[] = ['no' => $c['co_no'], 'project' => $c['project_no'], 'entity' => $c['official_name'], 'date' => $c['co_date'], 'title' => $c['title'], 'amount' => (float)$c['amount_change'],
                'pct' => (float)$c['contract_amount'] > 0 ? round((float)$c['amount_change'] / (float)$c['contract_amount'] * 100, 2) : '', 'days' => (int)$c['days_extension'] ?: '',
                'status' => Brgy::WF[$c['status']] ?? $c['status'], 'ext' => trim(($c['external_ref'] ?? '') . ' ' . fdate($c['external_date'])), 'applied' => $c['applied_at'], '_links' => ['project' => brgy_rpt_project_link($c)]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Change order'], ['key' => 'project', 'label' => 'Project'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'title', 'label' => 'What'], ['key' => 'amount', 'label' => 'Amount change', 'type' => 'money'], ['key' => 'pct', 'label' => '% of the original contract', 'type' => 'pct'], ['key' => 'days', 'label' => 'Days added', 'type' => 'int'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'ext', 'label' => 'Entity approval'], ['key' => 'applied', 'label' => 'Applied', 'type' => 'datetime']], 'rows' => $rows,
            'totals' => ['no' => 'TOTAL (' . count($rows) . ')', 'amount' => r2(array_sum(array_column(array_filter($rows, fn($r) => $r['status'] === Brgy::WF['applied']), 'amount')))],
            'notes' => 'The total counts applied change orders only: a change changes the contract once the entity approved it.'];
    },
]);

Report::define('brgy_retention', [
    'filters' => [],
    'extra_filters' => brgy_rpt_entity_filter(),
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = [];
        $rows = [];
        foreach (DB::all("SELECT p.id FROM brgy_projects p WHERE p.status IN ('awarded', 'closed') AND (p.retention_pct > 0 OR EXISTS (SELECT 1 FROM brgy_securities s WHERE s.project_id = p.id))"
                         . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY p.warranty_until IS NULL, p.warranty_until', $p) as $x) {
            $r = Brgy::project((int)$x['id']);
            $m = Brgy::money($r);
            $sec = DB::all("SELECT * FROM brgy_securities WHERE project_id = :p AND security_type IN ('performance', 'warranty') ORDER BY id", ['p' => $r['id']]);
            $rows[] = ['no' => $r['project_no'], 'entity' => $r['entity_name'], 'pct' => (float)$r['retention_pct'], 'deducted' => $m['retention_deducted'], 'released' => $m['retention_released'], 'held' => $m['retention_held'],
                'completion' => $r['completion_date'], 'warranty' => $r['warranty_until'], 'claim' => $m['retention_held'] > 0.004 ? ($r['warranty_until'] && $r['warranty_until'] <= today() ? 'Can be claimed now' : ($r['warranty_until'] ? 'From ' . fdate($r['warranty_until']) : 'Warranty date not set')) : '',
                'security' => implode('; ', array_map(fn($s) => Brgy::label(Brgy::SECURITY_TYPES, $s['security_type']) . ' ' . money($s['amount']) . ' ' . $s['status'] . ($s['valid_until'] ? ' (valid until ' . fdate($s['valid_until']) . ')' : ''), $sec)),
                '_links' => ['no' => brgy_rpt_project_link($r)]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Contract'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'pct', 'label' => 'Retention %', 'type' => 'pct'], ['key' => 'deducted', 'label' => 'Deducted', 'type' => 'money'],
            ['key' => 'released', 'label' => 'Released', 'type' => 'money'], ['key' => 'held', 'label' => 'Held (ledger)', 'type' => 'money'], ['key' => 'completion', 'label' => 'Completed', 'type' => 'date'],
            ['key' => 'warranty', 'label' => 'Warranty until', 'type' => 'date'], ['key' => 'claim', 'label' => 'Release'], ['key' => 'security', 'label' => 'Performance / warranty security']], 'rows' => $rows,
            'totals' => ['no' => 'TOTAL', 'deducted' => r2(array_sum(array_column($rows, 'deducted'))), 'released' => r2(array_sum(array_column($rows, 'released'))), 'held' => r2(array_sum(array_column($rows, 'held')))]];
    },
]);

Report::define('brgy_profitability', [
    'filters' => [],
    'extra_filters' => brgy_rpt_entity_filter() + ['scope' => ['Contracts', fn() => ['all' => 'In force and closed', 'awarded' => 'In force', 'closed' => 'Closed'], null]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = [];
        $st = ['awarded' => "('awarded')", 'closed' => "('closed')"][$f['scope'] ?? ''] ?? "('awarded', 'closed')";
        $rows = [];
        foreach (DB::all("SELECT p.id FROM brgy_projects p WHERE p.status IN $st" . brgy_rpt_entity_sql($f, 'p.entity_id', $p) . ' ORDER BY p.project_no', $p) as $x) {
            $r = Brgy::project((int)$x['id']);
            $m = Brgy::money($r);
            $estMargin = $m['contract'] > 0 ? round(($m['contract'] - $m['est_cost']) / $m['contract'] * 100, 1) : '';
            $rows[] = ['no' => $r['project_no'], 'title' => $r['title'], 'entity' => $r['entity_name'], 'contract' => $m['contract'], 'est_cost' => $m['est_cost'], 'est_margin' => $estMargin,
                'revenue' => $m['revenue'], 'cost' => $m['costs'], 'margin' => $m['margin'], 'margin_pct' => $m['margin_pct'] ?? '',
                'to_complete' => r2(max(0, $m['est_cost'] - $m['costs'])), 'status' => Brgy::label(Brgy::STATUSES, $r['status']), '_links' => ['no' => brgy_rpt_project_link($r)]];
        }
        $t = fn($k) => r2(array_sum(array_column($rows, $k)));
        return ['columns' => [['key' => 'no', 'label' => 'Contract'], ['key' => 'title', 'label' => 'Title'], ['key' => 'entity', 'label' => 'Procuring entity'], ['key' => 'contract', 'label' => 'Contract amount', 'type' => 'money'],
            ['key' => 'est_cost', 'label' => 'Estimated cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'est_margin', 'label' => 'Estimated margin', 'type' => 'pct', 'sensitive' => 'cost'],
            ['key' => 'revenue', 'label' => 'Revenue (ledger)', 'type' => 'money'], ['key' => 'cost', 'label' => 'Costs (ledger)', 'type' => 'money', 'sensitive' => 'cost'],
            ['key' => 'margin', 'label' => 'Margin', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin_pct', 'label' => 'Margin %', 'type' => 'pct', 'sensitive' => 'cost'],
            ['key' => 'to_complete', 'label' => 'Estimated cost to complete', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'status', 'label' => 'Status']], 'rows' => $rows,
            'totals' => ['no' => 'TOTAL', 'contract' => $t('contract'), 'est_cost' => $t('est_cost'), 'revenue' => $t('revenue'), 'cost' => $t('cost'), 'margin' => $t('margin'), 'to_complete' => $t('to_complete')],
            'notes' => 'Revenue and costs are the ledger lines on each contract\'s Customer:Job (and its class); bills and checks count when their lines name the job.'];
    },
]);

Report::define('brgy_wht_credits', [
    'filters' => ['range'],
    'default_dates' => 'this_quarter',
    'extra_filters' => brgy_rpt_entity_filter() + ['pending' => ['Form 2307', fn() => ['1' => 'Not received yet'], 'All']],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT c.*, p.project_no, p.id AS project_id, e.official_name, e.tin, b.billing_no FROM brgy_collections c JOIN brgy_projects p ON p.id = c.project_id JOIN brgy_entities e ON e.id = p.entity_id
                  LEFT JOIN brgy_billings b ON b.id = c.billing_id
                 WHERE c.kind = 'billing' AND c.status = 'posted' AND (c.ewt_amount + c.vatwh_amount) > 0 AND c.collection_date BETWEEN :f AND :t" . brgy_rpt_entity_sql($f, 'p.entity_id', $p);
        if (($f['pending'] ?? '') === '1') {
            $sql .= ' AND c.cert_2307 = 0';
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY c.collection_date, c.id', $p) as $c) {
            $q = 'Q' . (intdiv((int)date('n', strtotime($c['collection_date'])) - 1, 3) + 1) . ' ' . date('Y', strtotime($c['collection_date']));
            $rows[] = ['date' => $c['collection_date'], 'quarter' => $q, 'entity' => $c['official_name'], 'tin' => $c['tin'], 'no' => $c['collection_no'], 'billing' => $c['billing_no'], 'project' => $c['project_no'],
                'base' => r2((float)$c['gross_applied']), 'ewt' => (float)$c['ewt_amount'], 'vat' => (float)$c['vatwh_amount'], 'variance' => (float)$c['wht_variance'] ?: '',
                'cert' => (int)$c['cert_2307'] ? ($c['cert_2307_doc_id'] ? 'Received, filed' : 'Received') : 'Not yet',
                '_links' => ['no' => Report::link('brgy.collections', ['a' => 'view', 'id' => $c['id']]), 'project' => brgy_rpt_project_link($c)]];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Collected', 'type' => 'date'], ['key' => 'quarter', 'label' => 'Quarter'], ['key' => 'entity', 'label' => 'Withholding agent'], ['key' => 'tin', 'label' => 'TIN'],
            ['key' => 'no', 'label' => 'Collection'], ['key' => 'billing', 'label' => 'Billing'], ['key' => 'project', 'label' => 'Project'], ['key' => 'base', 'label' => 'Amount settled', 'type' => 'money'],
            ['key' => 'ewt', 'label' => 'Income tax withheld', 'type' => 'money'], ['key' => 'vat', 'label' => 'VAT withheld', 'type' => 'money'], ['key' => 'variance', 'label' => 'Difference from the table', 'type' => 'money'],
            ['key' => 'cert', 'label' => 'Form 2307']], 'rows' => $rows,
            'totals' => ['date' => 'TOTAL (' . count($rows) . ')', 'base' => r2(array_sum(array_column($rows, 'base'))), 'ewt' => r2(array_sum(array_column($rows, 'ewt'))), 'vat' => r2(array_sum(array_column($rows, 'vat')))],
            'notes' => 'Creditable taxes withheld by the procuring entities: claim them on the quarterly and annual returns with the Forms 2307 received. The same amounts are on the BIR reports of the accounting side.'];
    },
]);

Report::define('brgy_doc_expiry', [
    'filters' => [],
    'sortable' => false,
    'extra_filters' => ['days' => ['Expiring within', fn() => ['30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days'], null]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $days = in_array($f['days'] ?? '', ['30', '60', '90', '180'], true) ? (int)$f['days'] : (int)Brgy::setting('brgy_alert_days');
        $lim = date('Y-m-d', strtotime("+$days days"));
        $rows = [['what' => 'Papers expired or expiring by ' . fdate($lim), '_class' => 'group-row']];
        foreach (DB::all("SELECT d.*, p.project_no FROM brgy_documents d LEFT JOIN brgy_projects p ON p.id = d.project_id WHERE d.is_current = 1 AND d.status <> 'void' AND d.valid_until IS NOT NULL AND d.valid_until <= :l
                           ORDER BY d.valid_until", ['l' => $lim]) as $d) {
            $rows[] = ['what' => $d['title'], 'type' => Brgy::docTypeName($d['doc_type']), 'where' => $d['project_no'] ?: 'Company paper', 'date' => $d['valid_until'],
                'state' => $d['valid_until'] < today() ? 'Expired' : 'Expires in ' . (int)round((strtotime($d['valid_until']) - strtotime(today())) / 86400) . ' day(s)', '_class' => 'indent-1',
                '_links' => ['what' => Report::link('brgy.vault', ['a' => 'view', 'id' => $d['id']])]];
        }
        $rows[] = ['what' => 'Company papers not on file', '_class' => 'group-row'];
        foreach (DB::all('SELECT * FROM brgy_doc_types WHERE is_company = 1 AND is_active = 1 ORDER BY sort_order') as $t) {
            if (!DB::value("SELECT id FROM brgy_documents WHERE scope = 'company' AND doc_type = :t AND is_current = 1 AND status <> 'void' LIMIT 1", ['t' => $t['code']])) {
                $rows[] = ['what' => $t['name'], 'type' => Brgy::DOC_GROUPS[$t['doc_group']] ?? $t['doc_group'], 'where' => 'Company paper', 'state' => 'Not on file', '_class' => 'indent-1'];
            }
        }
        $rows[] = ['what' => 'Mandatory papers missing on open projects and contracts', '_class' => 'group-row'];
        foreach (DB::all("SELECT id FROM brgy_projects WHERE status IN ('open', 'awarded') ORDER BY bid_deadline IS NULL, bid_deadline, id") as $x) {
            $p = Brgy::project((int)$x['id']);
            foreach (Brgy::checklist((int)$p['id'], $p) as $r) {
                if ($r['due'] && $r['requirement_type'] === 'mandatory' && !in_array($r['state'], ['ok', 'waived'], true)) {
                    $rows[] = ['what' => $r['name'], 'type' => Brgy::label(Brgy::STAGES, $r['stage']), 'where' => $p['project_no'], 'date' => $p['status'] === 'open' ? substr((string)$p['bid_deadline'], 0, 10) : null,
                        'state' => ['missing' => 'Missing', 'expired' => 'Expired', 'pending' => 'For review', 'returned' => 'Returned'][$r['state']], '_class' => 'indent-1',
                        '_links' => ['where' => Report::link('brgy.projects', ['a' => 'view', 'id' => $p['id'], 'tab' => 'compliance'])]];
                }
            }
        }
        return ['grouped' => true, 'columns' => [['key' => 'what', 'label' => 'Paper'], ['key' => 'type', 'label' => 'Type / stage'], ['key' => 'where', 'label' => 'Company / project'], ['key' => 'date', 'label' => 'Valid until / needed by', 'type' => 'date'],
            ['key' => 'state', 'label' => 'Status']], 'rows' => $rows];
    },
]);

Report::define('brgy_cash_forecast', [
    'filters' => [],
    'sortable' => false,
    'extra_filters' => ['months' => ['Months ahead', fn() => ['3' => '3 months', '6' => '6 months', '12' => '12 months'], null]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $months = in_array($f['months'] ?? '', ['3', '6', '12'], true) ? (int)$f['months'] : 3;
        $end = date('Y-m-t', strtotime('+' . ($months - 1) . ' months'));
        // each entity's usual delay from billing to payment, from the collections so far
        $delay = DB::pairs("SELECT p.entity_id, ROUND(AVG(DATEDIFF(c.collection_date, b.billing_date))) FROM brgy_collections c JOIN brgy_billings b ON b.id = c.billing_id JOIN brgy_projects p ON p.id = c.project_id
                            WHERE c.status = 'posted' AND c.kind = 'billing' GROUP BY p.entity_id");
        $items = [];
        foreach (DB::all("SELECT b.*, p.project_no, p.entity_id, p.id AS project_id, e.official_name FROM brgy_billings b JOIN brgy_projects p ON p.id = b.project_id JOIN brgy_entities e ON e.id = p.entity_id
                           WHERE b.status IN ('invoiced', 'submitted', 'acknowledged')") as $b) {
            $open = brgy_rpt_open($b);
            if ($open <= 0.004) {
                continue;
            }
            $ratio = (float)$b['gross_amount'] > 0 ? $open / (float)$b['gross_amount'] : 0;
            $net = r2($open - ((float)$b['ewt_expected'] + (float)$b['vatwh_expected'] + (float)$b['retention_expected'] + (float)$b['ld_expected']) * $ratio);
            $d = isset($delay[$b['entity_id']]) ? date('Y-m-d', strtotime($b['billing_date'] . ' +' . (int)$delay[$b['entity_id']] . ' days')) : ($b['due_date'] ?: date('Y-m-d', strtotime($b['billing_date'] . ' +30 days')));
            $items[] = [max($d, today()), 'Billing ' . $b['billing_no'] . ' (' . $b['project_no'] . ', ' . $b['official_name'] . ')', 'Invoiced, open', $net, $b['project_id']];
        }
        foreach (DB::all("SELECT * FROM brgy_projects WHERE status IN ('awarded', 'closed')") as $p) {
            $m = Brgy::money($p);
            if ($m['retention_held'] > 0.004) {
                $items[] = [max($p['warranty_until'] ?: date('Y-m-d', strtotime('+1 year')), today()), 'Retention of ' . $p['project_no'], 'Retention release', $m['retention_held'], $p['id']];
            }
            $notBilled = $m['to_deliver'] + $m['unbilled_accepted'] + $m['billing_draft'];
            if ($p['status'] === 'awarded' && $notBilled > 0.004) {
                $when = $p['due_date'] && $p['due_date'] > today() ? $p['due_date'] : today();
                $rate = DB::row("SELECT COALESCE(SUM(net_amount), 0) AS n, COALESCE(SUM(gross_amount), 0) AS g FROM brgy_billings WHERE project_id = :p AND status <> 'void'", ['p' => $p['id']]);
                $items[] = [date('Y-m-d', strtotime($when . ' +' . (int)($delay[$p['entity_id']] ?? 45) . ' days')), ($m['to_deliver'] > 0.004 ? 'Still to deliver and bill on ' : 'Still to bill on ') . $p['project_no'], 'Not billed yet',
                    r2($notBilled * (1 - (float)$p['retention_pct'] / 100) * 0.96), $p['id']];
            }
        }
        usort($items, fn($a, $b) => strcmp($a[0], $b[0]));
        $rows = [];
        $bars = [];
        $cur = null;
        $sub = 0.0;
        $total = 0.0;
        foreach ($items as [$d, $what, $kind, $amt, $pid]) {
            $mo = $d > $end ? 'Later' : date('F Y', strtotime($d));
            if ($mo !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['what' => 'Total ' . $cur, 'amount' => r2($sub), '_class' => 'subtotal-row'];
                }
                $cur = $mo;
                $sub = 0.0;
                $rows[] = ['what' => $mo, '_class' => 'group-row'];
            }
            $sub += $amt;
            $total += $amt;
            $bars[$mo] = r2(($bars[$mo] ?? 0) + $amt);
            $rows[] = ['what' => $what, 'kind' => $kind, 'date' => $d, 'amount' => $amt, '_class' => 'indent-1', '_links' => ['what' => Report::link('brgy.projects', ['a' => 'view', 'id' => $pid, 'tab' => 'billing'])]];
        }
        if ($cur !== null) {
            $rows[] = ['what' => 'Total ' . $cur, 'amount' => r2($sub), '_class' => 'subtotal-row'];
        }
        return ['grouped' => true, 'columns' => [['key' => 'what', 'label' => 'Expected'], ['key' => 'kind', 'label' => 'From'], ['key' => 'date', 'label' => 'Expected on', 'type' => 'date'], ['key' => 'amount', 'label' => 'Net cash', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['what' => 'TOTAL', 'amount' => r2($total)], 'chart' => ['bars' => $bars, 'bars_title' => 'Expected cash by month'],
            'notes' => 'Net of the expected taxes withheld, retention and liquidated damages (about 4% of what is not billed yet). The date follows each entity\'s usual delay between billing and payment so far (45 days when there is no history); retention comes back after the warranty. An estimate, not a promise.'];
    },
]);

Report::define('brgy_reconciliation', [
    'filters' => [],
    'sortable' => false,
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $rows = [];
        $bad = 0;
        foreach (BrgyPost::reconcile() as $r) {
            $bad += $r['issues'] ? 1 : 0;
            $rows[] = ['no' => $r['project_no'], 'title' => $r['title'], 'billed_m' => $r['billed_module'], 'billed_l' => $r['billed_ledger'], 'applied_m' => $r['applied_module'], 'applied_l' => $r['applied_ledger'],
                'ret_m' => $r['retention_module'], 'ret_l' => $r['retention_ledger'], 'outside' => $r['outside'] ?: '', 'result' => $r['issues'] ? implode('; ', $r['issues']) : 'Agrees',
                '_class' => $r['issues'] ? 'text-danger' : '', '_links' => ['no' => brgy_rpt_project_link($r)]];
        }
        return ['columns' => [['key' => 'no', 'label' => 'Contract'], ['key' => 'title', 'label' => 'Title'], ['key' => 'billed_m', 'label' => 'Billed (contracts)', 'type' => 'money', 'band' => 'Billed'],
            ['key' => 'billed_l', 'label' => 'Invoiced (ledger)', 'type' => 'money', 'band' => 'Billed'], ['key' => 'applied_m', 'label' => 'Settled (contracts)', 'type' => 'money', 'band' => 'Collected'],
            ['key' => 'applied_l', 'label' => 'Applied (ledger)', 'type' => 'money', 'band' => 'Collected'], ['key' => 'ret_m', 'label' => 'Retention (contracts)', 'type' => 'money', 'band' => 'Retention'],
            ['key' => 'ret_l', 'label' => 'Retention (ledger)', 'type' => 'money', 'band' => 'Retention'], ['key' => 'outside', 'label' => 'Posted outside the module', 'type' => 'money'], ['key' => 'result', 'label' => 'Result']],
            'rows' => $rows, 'notes' => h($bad ? $bad . ' contract(s) do not agree with the ledger: look for an invoice, payment or credit memo voided or changed in the accounting pages.' : 'Every contract agrees with the ledger.')];
    },
]);

Report::define('brgy_approval_log', [
    'filters' => ['range', 'user'],
    'default_dates' => 'this_month',
    'extra_filters' => ['object_type' => ['Record', fn() => ['bid' => 'Bids', 'billing' => 'Billing packs', 'change_order' => 'Change orders', 'form' => 'Forms', 'closeout' => 'Closeouts', 'phase' => 'Phase changes', 'requirement' => 'Requirements']]],
    'run' => function (array $f): array {
        if (!Brgy::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = 'SELECT w.*, u.full_name, p.project_no, p.id AS pid FROM brgy_workflow w LEFT JOIN users u ON u.id = w.user_id LEFT JOIN brgy_projects p ON p.id = w.project_id WHERE w.created_at BETWEEN :f AND :t';
        if ($f['user_id']) {
            $sql .= ' AND w.user_id = :u';
            $p['u'] = $f['user_id'];
        }
        if (($f['object_type'] ?? '') !== '') {
            $sql .= ' AND w.object_type = :o';
            $p['o'] = $f['object_type'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY w.created_at, w.id', $p) as $w) {
            $ref = match ($w['object_type']) {
                'billing' => (string)DB::value('SELECT billing_no FROM brgy_billings WHERE id = :i', ['i' => $w['object_id']]),
                'change_order' => (string)DB::value('SELECT co_no FROM brgy_change_orders WHERE id = :i', ['i' => $w['object_id']]),
                'form' => (string)DB::value('SELECT form_no FROM brgy_forms WHERE id = :i', ['i' => $w['object_id']]),
                default => (string)$w['project_no'],
            };
            $rows[] = ['when' => $w['created_at'], 'user' => $w['full_name'], 'for' => $w['on_behalf_of'] ? Brgy::userName((int)$w['on_behalf_of']) : '', 'record' => ucfirst(str_replace('_', ' ', $w['object_type'])) . ' ' . $ref,
                'project' => $w['project_no'], 'action' => str_replace('_', ' ', $w['action']), 'from' => Brgy::WF[(string)$w['from_status']] ?? Brgy::label(Brgy::STATUSES + array_map(fn($x) => $x[1], Brgy::PHASES), $w['from_status']),
                'to' => Brgy::WF[(string)$w['to_status']] ?? Brgy::label(Brgy::STATUSES + array_map(fn($x) => $x[1], Brgy::PHASES), $w['to_status']), 'amount' => $w['amount'] !== null ? (float)$w['amount'] : '',
                'remarks' => $w['remarks'], '_links' => ['project' => $w['pid'] ? Report::link('brgy.projects', ['a' => 'view', 'id' => $w['pid'], 'tab' => 'timeline']) : null]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'When', 'type' => 'datetime'], ['key' => 'user', 'label' => 'User'], ['key' => 'for', 'label' => 'For (alternate of)'], ['key' => 'record', 'label' => 'Record'],
            ['key' => 'project', 'label' => 'Project'], ['key' => 'action', 'label' => 'Step'], ['key' => 'from', 'label' => 'From'], ['key' => 'to', 'label' => 'To'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'remarks', 'label' => 'Remarks']], 'rows' => $rows, 'totals' => ['when' => 'TOTAL (' . count($rows) . ')'],
            'notes' => 'Every maker, checker and approver step, with the alternate who acted for someone away. The full change history is in the Audit Trail.'];
    },
]);

// The monitoring system of the SOP: every transaction from its transaction form to the signed transmittal
Report::define('brgy_monitoring', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => brgy_rpt_entity_filter() + [
        'process' => ['Process', fn() => ['svp' => 'Small value procurement', 'bidding' => 'Bidding']],
        'stage' => ['Where it stands', fn() => ['open' => 'Not finished', 'waiting' => 'Waiting for payment', 'unreturned' => 'Transmittal not returned', 'done' => 'Closed']],
    ],
    'run' => function (array $f): array {
        if (!Brgy::ready() || !BrgySop::ready()) {
            return brgy_rpt_empty();
        }
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = "SELECT pr.*, e.official_name AS entity_name, s.step_no, s.title AS step_title,
                       (SELECT GROUP_CONCAT(DISTINCT b.si_no SEPARATOR ', ') FROM brgy_billings b WHERE b.project_id = pr.id AND b.status IN ('invoiced', 'submitted', 'acknowledged')) AS si_nos,
                       (SELECT GROUP_CONCAT(DISTINCT c.receipt_no SEPARATOR ', ') FROM brgy_collections c WHERE c.project_id = pr.id AND c.status = 'posted' AND c.kind = 'billing') AS or_nos,
                       (SELECT COALESCE(SUM(c.ewt_amount + c.vatwh_amount), 0) FROM brgy_collections c WHERE c.project_id = pr.id AND c.status = 'posted' AND c.kind = 'billing') AS wht,
                       (SELECT COUNT(*) FROM brgy_project_papers pp WHERE pp.project_id = pr.id AND pp.paper_code = 'form_2307' AND pp.document_id IS NOT NULL) AS f2307,
                       (SELECT COUNT(*) FROM brgy_project_papers pp WHERE pp.project_id = pr.id AND pp.document_id IS NOT NULL) AS scans,
                       (SELECT COUNT(*) FROM brgy_project_papers pp WHERE pp.project_id = pr.id AND pp.status = 'open') AS papers
                  FROM brgy_projects pr JOIN brgy_entities e ON e.id = pr.entity_id LEFT JOIN brgy_sop_steps s ON s.code = pr.sop_step
                 WHERE pr.process IN ('svp', 'bidding') AND COALESCE(pr.txn_date, DATE(pr.created_at)) BETWEEN :f AND :t";
        $sql .= brgy_rpt_entity_sql($f, 'pr.entity_id', $p);
        if (in_array($f['process'] ?? '', ['svp', 'bidding'], true)) {
            $sql .= ' AND pr.process = :pc';
            $p['pc'] = $f['process'];
        }
        $stage = (string)($f['stage'] ?? '');
        if ($stage === 'open') {
            $sql .= " AND pr.status IN ('open', 'awarded')";
        } elseif ($stage === 'waiting') {
            $sql .= " AND pr.status IN ('open', 'awarded') AND pr.payment_status <> 'released'";
        } elseif ($stage === 'unreturned') {
            $sql .= ' AND pr.released_at IS NOT NULL AND pr.transmittal_returned_at IS NULL';
        } elseif ($stage === 'done') {
            $sql .= " AND pr.status = 'closed'";
        }
        $rows = [];
        $types = BrgySop::txnTypes();
        $tot = ['gross' => 0.0, 'wht' => 0.0, 'cheque' => 0.0];
        foreach (DB::all($sql . ' ORDER BY COALESCE(pr.txn_date, DATE(pr.created_at)), pr.id', $p) as $r) {
            $gross = in_array($r['status'], ['awarded', 'closed'], true) ? Brgy::contractTotal($r) : (float)$r['bid_amount'];
            $wht = (float)$r['wht'] > 0 ? (float)$r['wht'] : 0.0;
            $tot['gross'] += $gross;
            $tot['wht'] += $wht;
            $tot['cheque'] += (float)$r['cheque_amount'];
            $rows[] = ['series' => $r['txn_form_no'], 'date' => $r['txn_date'], 'no' => $r['project_no'], 'entity' => $r['entity_name'], 'title' => $r['title'],
                'type' => $types[$r['txn_type']] ?? (string)$r['txn_type'], 'process' => $r['process'] === 'svp' ? 'Small value' : 'Bidding',
                'step' => in_array($r['status'], ['open', 'awarded'], true) ? ($r['step_no'] ? $r['step_no'] . '. ' . $r['step_title'] : 'All steps done') : Brgy::label(Brgy::STATUSES, $r['status']),
                'gross' => $gross, 'wht' => $wht ?: '', 'cheque' => $r['cheque_no'] ? $r['cheque_no'] : '', 'cheque_amt' => $r['cheque_amount'] !== null ? (float)$r['cheque_amount'] : '',
                'payment' => $r['payment_status'] === 'released' ? 'Released' : 'Waiting', 'si' => (string)$r['si_nos'], 'or' => (string)$r['or_nos'], 'f2307' => (int)$r['f2307'] ? 'Signed copies' : '',
                'scans' => (int)$r['scans'] . ' / ' . (int)$r['papers'], 'forwarded' => $r['forwarded_at'] ? substr((string)$r['forwarded_at'], 0, 10) : '',
                'released' => $r['released_at'] ? substr((string)$r['released_at'], 0, 10) . ' (' . ($r['release_method'] === 'pickup' ? 'pick-up' : 'sales rep') . ')' : '',
                'returned' => $r['transmittal_returned_at'] ? substr((string)$r['transmittal_returned_at'], 0, 10) : ($r['released_at'] ? 'Not yet' : ''),
                '_links' => ['no' => brgy_rpt_project_link(['id' => $r['id']])]];
        }
        return ['columns' => [['key' => 'series', 'label' => 'Series no.'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'no', 'label' => 'Project'], ['key' => 'entity', 'label' => 'Barangay'],
            ['key' => 'title', 'label' => 'Purpose / title'], ['key' => 'type', 'label' => 'Type'], ['key' => 'process', 'label' => 'Process'], ['key' => 'step', 'label' => 'Where it stands'],
            ['key' => 'gross', 'label' => 'Gross amount', 'type' => 'money'], ['key' => 'wht', 'label' => 'Taxes withheld', 'type' => 'money'], ['key' => 'cheque', 'label' => 'Cheque no.'],
            ['key' => 'cheque_amt', 'label' => 'Cheque amount', 'type' => 'money'], ['key' => 'payment', 'label' => 'Payment'], ['key' => 'si', 'label' => 'SI no.'], ['key' => 'or', 'label' => 'OR no.'],
            ['key' => 'f2307', 'label' => 'BIR 2307'], ['key' => 'scans', 'label' => 'Signed copies / papers'], ['key' => 'forwarded', 'label' => 'To accounting', 'type' => 'date'],
            ['key' => 'released', 'label' => 'Released'], ['key' => 'returned', 'label' => 'Transmittal returned']],
            'rows' => $rows, 'totals' => ['series' => 'TOTAL (' . count($rows) . ')', 'gross' => r2($tot['gross']), 'wht' => r2($tot['wht']), 'cheque_amt' => r2($tot['cheque'])],
            'notes' => 'The monitoring of the SOP (steps 6 and "Encode in monitoring"): each transaction from its transaction form to the signed transmittal. Payment "Waiting" means the SI, OR and 2307 are held.'];
    },
]);
