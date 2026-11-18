<?php
declare(strict_types=1);

/**
 * Gives an installation's database its new name: accounting_system becomes government_contracting_system.
 *
 *   php tools/rename_database.php            shows what would be renamed; nothing is changed
 *   php tools/rename_database.php --yes      renames
 *
 * Options:  --from=NAME   the database to rename (default: the one in config/config.php; when config.php
 *                         already names the new database and it does not exist yet, accounting_system)
 *           --to=NAME     the new name (default: government_contracting_system)
 *
 * MySQL has no RENAME DATABASE, so the new database is created with the same character set and every table
 * is moved into it with one RENAME TABLE statement (the rows never leave the server and nothing is copied).
 * The audit trail protection triggers are taken off before the move and put back on the moved tables, and the
 * old database, empty by then, is dropped. With several companies, the platform (master) database and the
 * company databases whose names start with the old name follow (accounting_system_master becomes
 * government_contracting_system_master, accounting_system_company_002 becomes ..._company_002), the
 * platform's list of company databases and the multi-company switch file are updated, and new companies get
 * the new prefix. Last, config/config.php is changed to the new name.
 *
 * Back up first. Run it while nobody is working in the program. The MySQL user of config/config.php needs
 * CREATE, DROP, ALTER, INSERT and TRIGGER on these databases (root on XAMPP has them). Works on MySQL 5.5+.
 * When the user is not allowed, use phpMyAdmin instead: select the database > Operations > "Rename database
 * to", then change 'name' in config/config.php.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

const NEW_DEFAULT = 'government_contracting_system';
const OLD_DEFAULT = 'accounting_system';

$opts = getopt('', ['from:', 'to:', 'yes']);
$apply = isset($opts['yes']);
$configName = (string)$GLOBALS['CONFIG']['db']['name'];
$to = trim((string)($opts['to'] ?? NEW_DEFAULT));

try {
    $pdo = server_pdo();
} catch (Throwable $e) {
    out('The MySQL server cannot be reached with the user in config/config.php: ' . $e->getMessage());
    exit(1);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$exists = function (string $db) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $st->execute([$db]);
    return (int)$st->fetchColumn() > 0;
};
$count = function (string $what, string $db) use ($pdo): int {
    $sql = [
        'tables'   => "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
        'views'    => 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?',
        'triggers' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
        'routines' => 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?',
        'events'   => 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?',
    ][$what];
    $st = $pdo->prepare($sql);
    $st->execute([$db]);
    return (int)$st->fetchColumn();
};

// ---------------------------------------------------------------- which database
$from = trim((string)($opts['from'] ?? $configName));
if (!isset($opts['from']) && $from === $to && !$exists($to) && $exists(OLD_DEFAULT)) {
    // config.php of the new version is already in place, the database still has its old name
    $from = OLD_DEFAULT;
}
foreach (['from' => $from, 'to' => $to] as $k => $n) {
    if (!DB::validName($n)) {
        out("--$k: \"$n\" is not a valid database name (letters, digits and _ only, at most 64).");
        exit(1);
    }
}
if ($from === $to) {
    out($exists($to) ? "The database is already named $to. Nothing to do." : "Neither $to nor " . OLD_DEFAULT . ' was found on this server. Give --from=<the current name>.');
    exit($exists($to) ? 0 : 1);
}
if (!$exists($from)) {
    out("There is no database named $from on this server." . ($exists($to) ? " $to is there already: nothing to do." : ''));
    exit($exists($to) ? 0 : 1);
}

// ---------------------------------------------------------------- the plan: [old, new, what]
$plan = [];
$renamed = function (string $name) use ($from, $to): ?string {
    return str_starts_with($name, $from . '_') ? substr($to . substr($name, strlen($from)), 0, 64) : null;
};
$flagFile = APP_ROOT . '/storage/platform/multi_company.json';
$flag = is_file($flagFile) ? json_decode((string)file_get_contents($flagFile), true) : null;
$multi = is_array($flag) && ($flag['primary_db'] ?? '') === $from && !empty($flag['master_db']);
$masterOld = $masterNew = null;
$companyDbs = [];
$prefix = null;
$notes = [];

if ($multi) {
    $configMaster = trim((string)($GLOBALS['CONFIG']['master']['name'] ?? ''));
    $masterOld = $configMaster !== '' ? $configMaster : (string)$flag['master_db'];
    if (!$exists($masterOld)) {
        out("Several companies are switched on, but their platform database $masterOld is not on this server. Nothing was changed.");
        exit(1);
    }
    $m = new PDO('mysql:host=' . $GLOBALS['CONFIG']['db']['host'] . ';port=' . $GLOBALS['CONFIG']['db']['port'] . ';dbname=' . $masterOld . ';charset=utf8mb4',
        $GLOBALS['CONFIG']['db']['user'], $GLOBALS['CONFIG']['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($m->query('SELECT company_id, db_name, is_primary FROM company_databases ORDER BY company_id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['db_name'] === $from) {
            continue;
        }
        $new = $renamed((string)$r['db_name']);
        if ($new === null) {
            $notes[] = 'Company ' . $r['company_id'] . ' keeps its database ' . $r['db_name'] . ' (its name does not start with ' . $from . '_).';
            continue;
        }
        if ($exists((string)$r['db_name'])) {
            $companyDbs[] = [(string)$r['db_name'], $new, 'company ' . $r['company_id']];
        } else {
            $notes[] = 'Company ' . $r['company_id'] . ': its database ' . $r['db_name'] . ' is not on this server; only its record is renamed.';
            $companyDbs[] = [(string)$r['db_name'], $new, 'record only'];
        }
    }
    $masterNew = $configMaster === '' ? $renamed($masterOld) : null;
    if ($configMaster !== '') {
        $notes[] = "The platform database $masterOld is named in config/config.php ('master'); it keeps its name.";
    } elseif ($masterNew === null) {
        $notes[] = "The platform database $masterOld keeps its name (it does not start with {$from}_).";
    }
    $st = $m->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'db_prefix'");
    $p = $st ? (string)$st->fetchColumn() : '';
    if ($p !== '' && ($np = $renamed($p)) !== null) {
        $prefix = [$p, $np];
    }
    $m = null;
}
foreach ($companyDbs as $c) {
    if ($c[2] !== 'record only') {
        $plan[] = $c;
    }
}
$plan[] = [$from, $to, $multi ? 'company 1 (the first company)' : 'the database'];
if ($masterNew !== null) {
    $plan[] = [$masterOld, $masterNew, 'platform (master) database'];
}

// ---------------------------------------------------------------- checks
$problems = [];
foreach ($plan as [$old, $new, $what]) {
    if (!DB::validName($new)) {
        $problems[] = "$new is not a valid database name.";
    } elseif ($exists($new) && ($count('tables', $new) || $count('views', $new))) {
        $problems[] = "A database named $new is already on this server and is not empty (rename or drop it first).";
    }
    foreach (['views', 'routines', 'events'] as $k) {
        if ($count($k, $old)) {
            $problems[] = "$old has $k; this tool moves tables and triggers only. Rename it in phpMyAdmin (Operations > Rename database to).";
        }
    }
}

out(($apply ? 'Renaming' : 'What would be renamed') . ' (' . ($multi ? 'several companies' : 'one company') . '):');
foreach ($plan as [$old, $new, $what]) {
    out(sprintf('  %-34s -> %-40s %s, %d tables, %d triggers', $old, $new, $what, $count('tables', $old), $count('triggers', $old)));
}
foreach ($companyDbs as $c) {
    if ($c[2] === 'record only') {
        out(sprintf('  %-34s -> %-40s %s', $c[0], $c[1], 'platform record only'));
    }
}
if ($prefix) {
    out('  New companies\' database prefix: ' . $prefix[0] . ' -> ' . $prefix[1]);
}
out('  config/config.php: database ' . $configName . ($configName === $to ? ' (already the new name)' : ' -> ' . $to));
foreach ($notes as $n) {
    out('  Note: ' . $n);
}
if ($problems) {
    out('');
    out('Nothing was changed:');
    foreach (array_unique($problems) as $p) {
        out('  - ' . $p);
    }
    exit(1);
}
if (!$apply) {
    out('');
    out('Nothing was changed. Back up the database(s), make sure nobody is working in the program, then run:');
    out('  php tools/rename_database.php' . (isset($opts['from']) ? ' --from=' . $from : '') . (isset($opts['to']) ? ' --to=' . $to : '') . ' --yes');
    exit(0);
}

// ---------------------------------------------------------------- the move
$move = function (string $old, string $new) use ($pdo, $count): void {
    $st = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $st->execute([$old]);
    [$charset, $collation] = $st->fetch(PDO::FETCH_NUM);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$new` CHARACTER SET $charset COLLATE $collation");

    // a table with triggers cannot move to another database: take them off, put them back after the move
    $st = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME');
    $st->execute([$old]);
    $triggers = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $row = $pdo->query("SHOW CREATE TRIGGER `$old`.`$t`")->fetch(PDO::FETCH_ASSOC);
        $sql = (string)($row['SQL Original Statement'] ?? array_values($row)[2]);
        $triggers[$t] = preg_replace('/^\s*CREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER/i', 'CREATE TRIGGER', $sql);
    }
    $putBack = function (string $db) use ($pdo, $triggers): array {
        $failed = [];
        $pdo->exec("USE `$db`");
        foreach ($triggers as $t => $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                $failed[] = "$t: " . $e->getMessage();
            }
        }
        return $failed;
    };
    foreach (array_keys($triggers) as $t) {
        $pdo->exec("DROP TRIGGER `$old`.`$t`");
    }
    $st = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    $st->execute([$old]);
    $tables = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($tables) {
        try {
            $pdo->exec('RENAME TABLE ' . implode(', ', array_map(fn($t) => "`$old`.`$t` TO `$new`.`$t`", $tables)));
        } catch (Throwable $e) {
            $putBack($old);
            throw new RuntimeException("Moving the tables of $old failed, nothing of it was moved (its triggers are back in place): " . $e->getMessage());
        }
    }
    if ($failed = $putBack($new)) {
        out("  Warning: these triggers could not be put back in $new: " . implode('; ', $failed));
        out('  The audit trail protection triggers can be put back with database/triggers.sql (phpMyAdmin > SQL) by a MySQL user allowed to create triggers.');
    }
    if ($count('tables', $old) === 0 && $count('triggers', $old) === 0 && $count('views', $old) === 0) {
        $pdo->exec("DROP DATABASE `$old`");
    } else {
        out("  $old still holds objects and was left in place.");
    }
    out(sprintf('  %s -> %s: %d tables, %d triggers', $old, $new, count($tables), count($triggers)));
};

$done = [];
try {
    foreach ($plan as [$old, $new]) {
        $move($old, $new);
        $done[] = "$old -> $new";
    }
} catch (Throwable $e) {
    out('');
    out('Stopped: ' . $e->getMessage());
    if ($done) {
        out('Already renamed: ' . implode(', ', $done) . '. Run the tool again with the same options to finish the rest.');
    }
    exit(1);
}

// ---------------------------------------------------------------- the records that name the databases
if ($multi) {
    $master = $masterNew ?? $masterOld;
    $m = new PDO('mysql:host=' . $GLOBALS['CONFIG']['db']['host'] . ';port=' . $GLOBALS['CONFIG']['db']['port'] . ';dbname=' . $master . ';charset=utf8mb4',
        $GLOBALS['CONFIG']['db']['user'], $GLOBALS['CONFIG']['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $upd = $m->prepare('UPDATE company_databases SET db_name = ? WHERE db_name = ?');
    $upd->execute([$to, $from]);
    foreach ($companyDbs as [$old, $new]) {
        $upd->execute([$new, $old]);
    }
    if ($prefix) {
        $m->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'db_prefix'")->execute([$prefix[1]]);
    }
    $m->prepare("INSERT INTO platform_audit (actor_type, actor_name, action, details, ip_address) VALUES ('system', 'command line', 'databases_renamed', ?, NULL)")
        ->execute([mb_substr('Renamed from the command line: ' . implode(', ', $done), 0, 60000)]);
    $flag['primary_db'] = $to;
    $flag['master_db'] = $master;
    $flag['renamed_at'] = date('c');
    if (@file_put_contents($flagFile, json_encode($flag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        out("  Warning: storage/platform/multi_company.json could not be written. Put \"primary_db\": \"$to\" and \"master_db\": \"$master\" in it by hand.");
    } else {
        out('  storage/platform/multi_company.json updated.');
    }
}

$cfgFile = APP_ROOT . '/config/config.php';
$cfg = (string)file_get_contents($cfgFile);
$re = "/(getenv\\('DB_NAME'\\)\\s*\\?:\\s*')" . preg_quote($from, '/') . "(')/";
if ($configName === $to) {
    out('  config/config.php already names ' . $to . '.');
} elseif (preg_match($re, $cfg) && @file_put_contents($cfgFile, preg_replace($re, '${1}' . $to . '${2}', $cfg, 1)) !== false) {
    out('  config/config.php: the database is now ' . $to . '.');
} else {
    out("  Change config/config.php by hand: 'name' => '$to' under 'db'.");
}
if (getenv('DB_NAME')) {
    out("  The environment variable DB_NAME is set on this server: change it to $to as well.");
}

// a line in the company's own audit trail
try {
    $c = Sql::to($to);
    $c->insert('audit_trail', ['username' => 'system', 'ip_address' => null, 'workstation' => 'CLI', 'module' => 'admin', 'action' => 'database_renamed',
        'record_type' => 'database', 'record_ref' => mb_substr($from . ' -> ' . $to, 0, 160), 'reason' => 'tools/rename_database.php']);
} catch (Throwable $e) {
    // the audit line is a courtesy; the rename itself is done
}
out('');
out('Done. Everyone logs in again as usual.');
exit(0);
