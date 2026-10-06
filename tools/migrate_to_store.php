<?php
/**
 * Moves the credit_manager ledger into Community Store. Run through the Concrete CLI:
 *
 *   concrete/bin/concrete c5:exec packages/credit_manager/tools/migrate_to_store.php -- [--apply] [--limit=N] [--report-dir=DIR] [--cutover=YYYY-MM-DD]
 *
 * Without --apply nothing is written; the reports (migration_report.csv, possible_duplicates.csv,
 * reconciliation.csv) land in --report-dir (default: packages/credit_manager/data/migration).
 *
 * @var array $args
 * @var \Concrete\Core\Application\Application $app
 */
use CreditManager\Migration\StoreMigration;

$options = ['apply' => false, 'limit' => 0, 'reportDir' => __DIR__ . '/../data/migration', 'cutover' => null];
foreach (isset($args) && is_array($args) ? $args : [] as $arg) {
    if ($arg === '--apply') {
        $options['apply'] = true;
    } elseif ($arg === '--dry-run') {
        $options['apply'] = false;
    } elseif (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int) substr($arg, 8);
    } elseif (strpos($arg, '--report-dir=') === 0) {
        $options['reportDir'] = substr($arg, 13);
    } elseif (strpos($arg, '--cutover=') === 0) {
        $options['cutover'] = substr($arg, 10);
    } else {
        fwrite(STDERR, "Unknown argument $arg\n");
        return 2;
    }
}
if ($options['cutover'] === null) {
    unset($options['cutover']);
}
if (!isset($app)) {
    $app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
}
$migration = new StoreMigration($app, $options, function ($line) {
    echo $line, "\n";
});
return $migration->run();
