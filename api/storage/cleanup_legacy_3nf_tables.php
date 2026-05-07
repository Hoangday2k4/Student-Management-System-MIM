<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/common/define.php';

$dbPath = DB_PATH;
if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}" . PHP_EOL);
    exit(1);
}

$backupPath = dirname($dbPath) . '/ltweb_backup_before_cleanup_legacy_3nf_' . date('Ymd_His') . '.sqlite';
if (!copy($dbPath, $backupPath)) {
    fwrite(STDERR, "Cannot create backup: {$backupPath}" . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 10000');

try {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_legacy_3nf_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if (!$tables) {
        echo "No legacy 3NF tables found." . PHP_EOL;
        echo "Backup: {$backupPath}" . PHP_EOL;
        exit(0);
    }

    $pdo->beginTransaction();
    $pdo->exec('PRAGMA foreign_keys = OFF');

    foreach ($tables as $tableName) {
        $safeName = str_replace('"', '""', (string)$tableName);
        $pdo->exec('DROP TABLE IF EXISTS "' . $safeName . '"');
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->commit();

    echo "Dropped legacy tables: " . count($tables) . PHP_EOL;
    foreach ($tables as $tableName) {
        echo "- {$tableName}" . PHP_EOL;
    }
    echo "Backup: {$backupPath}" . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Backup kept at: {$backupPath}" . PHP_EOL);
    exit(1);
}
