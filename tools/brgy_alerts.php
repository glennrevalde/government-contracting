<?php
declare(strict_types=1);

/**
 * Barangay Contracts: e-mails the day's alerts (papers expiring, bids due, approvals waiting, deliveries not accepted,
 * billings not acknowledged, overdue receivables, retention to claim, figures that do not agree with the ledger) to the
 * addresses on Contracting Settings > "Daily alert e-mail to".
 *
 * Run once a day, e.g.  15 6 * * *  php /path/to/government-contracting-system/tools/brgy_alerts.php
 * With several companies the same line covers every active company (or add --company=N for one).
 * Uses PHP mail(); configure sendmail / SMTP in php.ini for your server.  --print shows the message without sending.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['company:', 'print']);
if (Master::enabled() && !isset($opts['company'])) {
    $ids = Master::sql()->column("SELECT id FROM companies WHERE status = 'active' ORDER BY id");
    if (!function_exists('passthru')) {
        out('passthru() is switched off: run this script once per company with --company=N (' . implode(', ', $ids) . ').');
        exit(1);
    }
    foreach ($ids as $cid) {
        out('--- company ' . $cid);
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --company=' . (int)$cid . (isset($opts['print']) ? ' --print' : ''));
    }
    exit(0);
}
$tenant = Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if ($tenant && $tenant['status'] !== 'active') {
    out('Company ' . $tenant['id'] . ' is not active: nothing sent.');
    exit(0);
}
if (!Brgy::ready()) {
    out('Barangay Contracts is not installed in this database (run the 2026_10_29 migration).');
    exit(0);
}
$to = array_filter(array_map('trim', explode(',', Brgy::setting('brgy_alert_email'))), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
if (!$to && !isset($opts['print'])) {
    out('No address on Contracting Settings > "Daily alert e-mail to": nothing sent.');
    exit(0);
}
// the alerts as an administrator sees them (every project, every approval waiting)
$admin = (int)DB::value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.is_admin = 1 AND u.status = 'active' ORDER BY u.id LIMIT 1");
if ($admin) {
    Auth::actAs($admin);
}
$alerts = array_filter(Brgy::alerts(), fn($a) => !str_contains($a['text'], 'waits for your'));
$company = Settings::get('company_name');
$base = rtrim((string)($GLOBALS['CONFIG']['app']['url'] ?? ''), '/');
$lines = [];
foreach (['danger' => 'NEEDS ACTION NOW', 'warning' => 'COMING UP', 'info' => 'FOR YOUR INFORMATION'] as $level => $head) {
    $mine = array_filter($alerts, fn($a) => $a['level'] === $level);
    if (!$mine) {
        continue;
    }
    $lines[] = $head;
    foreach ($mine as $a) {
        $lines[] = '  - ' . $a['text'];
    }
    $lines[] = '';
}
$waiting = count(Brgy::queue());
$body = 'Barangay Contracts, ' . $company . ', ' . date('l, j F Y') . "\n\n"
    . ($lines ? implode("\n", $lines) : "Nothing needs attention today.\n\n")
    . ($waiting ? $waiting . " record(s) are in the approval queue.\n\n" : '')
    . 'Open the Contracting Dashboard' . ($base !== '' ? ': ' . $base . '/index.php?p=brgy.dashboard' : ' in the program') . ".\n";
$subject = 'Barangay Contracts: ' . count(array_filter($alerts, fn($a) => $a['level'] === 'danger')) . ' to act on, ' . count($alerts) . ' alert(s) - ' . $company;
if (isset($opts['print'])) {
    out('To: ' . implode(', ', $to));
    out('Subject: ' . $subject);
    out('');
    out($body);
    exit(0);
}
$from = Settings::get('company_email') ?: 'no-reply@localhost';
$ok = @mail(implode(', ', $to), $subject, $body, "From: $from\r\nContent-Type: text/plain; charset=utf-8");
out($ok ? 'Sent to ' . implode(', ', $to) . ' (' . count($alerts) . ' alert(s)).' : 'mail() failed: check the mail settings of PHP on this server.');
Audit::log('email', 'brgy', 'alerts', null, 'Daily contracting alerts', ['reason' => ($ok ? 'Sent to ' : 'Failed to send to ') . implode(', ', $to)]);
exit($ok ? 0 : 1);
