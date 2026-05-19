<?php
// Run from command line: php add-user.php "Name" 1234
// Hashes the PIN with bcrypt and inserts into groscan.db

$name = $argv[1] ?? '';
$pin = $argv[2] ?? '';

if (!preg_match('/^.{1,30}$/', $name) || !preg_match('/^\d{4,8}$/', $pin)) {
    echo "Usage: php add-user.php \"Name\" 1234\n";
    echo "Name must be 1-30 chars, PIN must be 4-8 digits.\n";
    exit(1);
}

$db = new PDO('sqlite:' . __DIR__ . '/groscan.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    pin_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

try {
    $stmt = $db->prepare("INSERT INTO users (name, pin_hash) VALUES (?, ?)");
    $stmt->execute([$name, password_hash($pin, PASSWORD_DEFAULT)]);
    echo "User '{$name}' added successfully.\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo "Error: Name '{$name}' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}
