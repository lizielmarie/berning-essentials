<?php
/**
 * ==============================================================================
 * BERNING ESSENTIALS — AUTOMATIC DATABASE INITIALIZATION & MIGRATION SCRIPT
 * ==============================================================================
 * Connects using centralized backend/config.php, verifies required tables,
 * and if missing, applies sql/infinityfree_production_import.sql or schema.sql.
 * ==============================================================================
 */
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../backend/db.php';

try {
    echo "=========================================================\n";
    echo "BERNING ESSENTIALS — DATABASE INITIALIZATION\n";
    echo "=========================================================\n\n";

    echo "Checking database tables...\n";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME IN ('products', 'users', 'sales', 'categories')
    ");
    $stmt->execute();
    $existingCount = (int)$stmt->fetchColumn();

    if ($existingCount >= 4) {
        echo "Database already fully initialized (all core tables present).\n";
        
        // Show summary
        $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $prodCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $catCount  = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        $saleCount = $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();
        
        echo "- Users registered: $userCount\n";
        echo "- Products catalog: $prodCount\n";
        echo "- Categories: $catCount\n";
        echo "- Sales recorded: $saleCount\n\n";
        echo "Ready for production!\n";
        exit(0);
    }

    echo "Tables not found. Initializing database schema from infinityfree_production_import.sql...\n";
    $importFile = __DIR__ . '/infinityfree_production_import.sql';
    if (!file_exists($importFile)) {
        $importFile = __DIR__ . '/schema.sql';
    }
    if (!file_exists($importFile)) {
        throw new Exception("Neither infinityfree_production_import.sql nor schema.sql found in " . __DIR__);
    }

    $sql = file_get_contents($importFile);
    // Strip BOM if present
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    
    // Execute SQL script
    $pdo->exec($sql);

    echo "Database initialized successfully! All tables and seed records are ready.\n";
    exit(0);

} catch (\Throwable $e) {
    http_response_code(500);
    echo "Database setup error: " . $e->getMessage() . "\n";
    exit(1);
}
