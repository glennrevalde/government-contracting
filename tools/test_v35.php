<?php
declare(strict_types=1);

/**
 * Version 35: checks of the Zip code and the Treasurer name of a customer (Customer Center), run through the code the
 * pages use: the columns, the import of customers from CSV and the related columns of custom reports.
 *
 *   php tools/test_v35.php --yes [--company=N]
 *
 * It WRITES one test customer ("V35 Check ..."), so run it on a copy of the database or on the demo database,
 * never on the books of a company in use. The test customer is removed at the end.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['yes', 'company:']);
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This writes a test customer. Run it on a copy of the database: php tools/test_v35.php --yes\n");
    exit(2);
}
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/views/layout.php';
Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if (Schema::pending()) {
    fwrite(STDERR, 'Run the pending migrations first: ' . implode(', ', Schema::pending()) . "\n");
    exit(2);
}
$pass = 0;
$failures = [];
$ok = function (string $what, bool $cond, string $detail = '') use (&$pass, &$failures) {
    if ($cond) {
        $pass++;
        echo "  ok    $what\n";
    } else {
        $failures[] = $what . ($detail !== '' ? " ($detail)" : '');
        echo "  FAIL  $what" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
};
$admin = (int)(DB::value("SELECT id FROM users WHERE username = 'admin'") ?: DB::value("SELECT MIN(id) FROM users WHERE status = 'active'"));
Auth::actAs($admin);

echo "Version 35 checks\n\n1. The columns of a customer\n";
$cols = DB::all("SELECT column_name AS c, column_type AS t, is_nullable AS n FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name IN ('treasurer_name', 'zip_code')");
$byName = array_column($cols, null, 'c');
$ok('customers.treasurer_name VARCHAR(120), may be blank', ($byName['treasurer_name']['t'] ?? '') === 'varchar(120)' && ($byName['treasurer_name']['n'] ?? '') === 'YES');
$ok('customers.zip_code VARCHAR(10), may be blank', ($byName['zip_code']['t'] ?? '') === 'varchar(10)' && ($byName['zip_code']['n'] ?? '') === 'YES');
$name = 'V35 Check ' . date('His');
$id = DB::insert('customers', ['name' => $name, 'contact_person' => 'Ana Cruz', 'treasurer_name' => 'Pedro T. Tesorero', 'billing_address' => '12 Rizal Ave., Cebu City', 'zip_code' => '6000', 'is_active' => 1]);
$c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
$ok('A customer keeps its treasurer name and zip code', $c['treasurer_name'] === 'Pedro T. Tesorero' && $c['zip_code'] === '6000');

echo "\n2. Import from CSV (Utilities > Import from CSV > Customers)\n";
$t = Importer::type('customers');
$ok('The customer template has the columns treasurer_name and zip_code', isset($t['columns']['treasurer_name'], $t['columns']['zip_code']));
$ok('... and its sample line has a value for every column', count($t['sample']) === count($t['columns']), count($t['sample']) . ' / ' . count($t['columns']));
$src = file_get_contents(APP_ROOT . '/modules/admin/import.php');
$ok('An imported line writes both (and a line that leaves them blank keeps what is saved)', str_contains($src, "'treasurer_name' => 'treasurer_name'") && str_contains($src, "'zip_code' => 'zip_code'"));

echo "\n3. Custom reports: the related columns of a customer\n";
$ok('Treasurer name and Zip code are offered among the customer\'s columns', isset(ReportFields::KINDS['customer'][1]['treasurer'], ReportFields::KINDS['customer'][1]['zip']));
$rows = ReportFields::fill([['_ref' => ['customer' => $id]]], [['key' => 'rf_customer_treasurer', 'related' => 1], ['key' => 'rf_customer_zip', 'related' => 1]]);
$ok('... filled from the customer record', ($rows[0]['rf_customer_treasurer'] ?? '') === 'Pedro T. Tesorero' && ($rows[0]['rf_customer_zip'] ?? '') === '6000',
    json_encode($rows[0]));

echo "\n4. The Customer Center\n";
$page = file_get_contents(APP_ROOT . '/modules/customers/center.php');
$ok('The form has the boxes Treasurer name and Zip code', str_contains($page, "input_field('treasurer_name', 'Treasurer name'") && str_contains($page, "input_field('zip_code', 'Zip code'"));
$ok('The list finds a customer by its treasurer', str_contains($page, 'c.treasurer_name LIKE :q'));
$ok('The exported list has the columns Treasurer and Zip code', str_contains($page, "'Treasurer', 'Phone'") && str_contains($page, "'Billing address', 'Zip code'"));

DB::run('DELETE FROM customers WHERE id = :id', ['id' => $id]);
echo "\n" . $pass . ' passed, ' . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo '  - ' . $f . "\n";
}
exit($failures ? 1 : 0);
