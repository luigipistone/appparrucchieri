<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

$root = dirname(__DIR__);
$migrationsPath = $root . '/migrations';
$files = glob($migrationsPath . '/*.sql') ?: [];
sort($files, SORT_STRING);

if (!$files) {
    fwrite(STDERR, "Nessuna migration trovata in $migrationsPath\n");
    exit(1);
}

$hostDsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($hostDsn, DB_USER, DB_PASS, $options);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . str_replace('`', '``', DB_NAME) . '`');
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(32) PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $applied = array_flip($applied);

    foreach ($files as $file) {
        $version = preg_replace('/^([0-9]+).*/', '$1', basename($file));
        if (isset($applied[$version])) {
            echo "SKIP $version " . basename($file) . "\n";
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Impossibile leggere $file");
        }

        echo "APPLY $version " . basename($file) . "\n";
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version, description) VALUES (?, ?)');
        $stmt->execute([$version, basename($file)]);
    }

    echo "Migrations completate.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Errore migration: ' . $e->getMessage() . "\n");
    exit(1);
}
