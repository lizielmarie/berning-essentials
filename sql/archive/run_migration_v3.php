<?php
/**
 * Run Migration V3: Owner Module & Users schema enhancement
 */
require_once file_exists(__DIR__ . '/../backend/db.php') ? __DIR__ . '/../backend/db.php' : __DIR__ . '/../api/db.php';

try {
    echo "Running migration_v3_owner.sql...\n";
    $sql = file_get_contents(__DIR__ . '/migration_v3_owner.sql');
    $pdo->exec($sql);
    echo "Migration V3 executed successfully.\n";

    // Verify columns on users
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Users columns: " . implode(', ', $cols) . "\n";

    // Check count of owners
    $ownerCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Owner'")->fetchColumn();
    echo "Current Owner count: $ownerCount (expected 0 before first registration)\n";

    // Check remaining users
    $users = $pdo->query("SELECT user_id, username, full_name, role FROM users")->fetchAll();
    echo "Remaining active staff accounts:\n";
    foreach ($users as $u) {
        echo "- ID {$u['user_id']}: {$u['full_name']} ({$u['username']}) - {$u['role']}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
