<?php
// FridgeStare Database Reset
// Run from command line only: php reset-db.php
// This deletes ALL data and recreates the default user.

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$dbPath = __DIR__ . '/groscan.db';

if (!file_exists($dbPath)) {
    die("Database not found at: $dbPath\n");
}

echo "WARNING: This will delete ALL inventory, ledger entries, products, users, and rate limits.\n";
echo "The database will be reset to its first-run state (Default user / PIN 1234).\n";
echo "Type 'yes' to continue: ";
$handle = fopen('php://stdin', 'r');
$input = trim(fgets($handle));
fclose($handle);

if ($input !== 'yes') {
    echo "Aborted.\n";
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=OFF");

    $db->exec("DELETE FROM inventory");
    $db->exec("DELETE FROM ledger");
    $db->exec("DELETE FROM products");
    $db->exec("DELETE FROM rate_limits");
    $db->exec("DELETE FROM sessions");
    $db->exec("DELETE FROM users");

    $hash = password_hash('1234', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (name, pin_hash) VALUES (?, ?)");
    $stmt->execute(['Default user', $hash]);

    echo "Database reset complete.\n";
    echo "  - All inventory, ledger, products, rate limits, sessions cleared\n";
    echo "  - Default user restored: PIN 1234\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
