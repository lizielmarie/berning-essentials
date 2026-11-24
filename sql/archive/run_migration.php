<?php
/**
 * BERNING STORE — V2.0 SCHEMA MIGRATION RUNNER
 */
require_once file_exists(__DIR__ . '/../backend/db.php') ? __DIR__ . '/../backend/db.php' : __DIR__ . '/../api/db.php';

echo "=== Starting Berning Store Migration V2.0 ===\n\n";

try {
    // 1. Create tables
    echo "1. Creating tables...\n";

    // Customers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customers` (
            `customer_id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_name` VARCHAR(100) NOT NULL,
            `phone` VARCHAR(20) NULL,
            `email` VARCHAR(100) NULL,
            `address` TEXT NULL,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_cust_name` (`customer_name`),
            INDEX `idx_cust_phone` (`phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  - Table `customers` created or verified.\n";

    // Seed default Walk-in Customer
    $checkWalkIn = $pdo->query("SELECT COUNT(*) FROM `customers` WHERE `customer_id` = 1")->fetchColumn();
    if ($checkWalkIn == 0) {
        $pdo->exec("
            INSERT INTO `customers` (`customer_id`, `customer_name`, `phone`, `email`, `address`, `status`)
            VALUES (1, 'Walk-in Customer', 'N/A', 'walkin@berningstore.local', 'Storefront / Cash Counter', 'active')
        ");
        echo "  - Seeded default Walk-in Customer (ID 1).\n";
    }

    // Stock adjustments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `stock_adjustments` (
            `adjustment_id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `adjustment_type` ENUM('in', 'out', 'adjustment') NOT NULL,
            `quantity` INT NOT NULL,
            `reason` TEXT NOT NULL,
            `reference_note` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_adj_product` (`product_id`),
            INDEX `idx_adj_user` (`user_id`),
            INDEX `idx_adj_date` (`created_at`),
            CONSTRAINT `fk_adj_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_adj_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  - Table `stock_adjustments` created or verified.\n";

    // Audit logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `audit_logs` (
            `log_id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `action` VARCHAR(50) NOT NULL,
            `entity_type` VARCHAR(50) NOT NULL,
            `entity_id` INT NULL,
            `details` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_audit_user` (`user_id`),
            INDEX `idx_audit_action` (`action`),
            INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
            INDEX `idx_audit_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  - Table `audit_logs` created or verified.\n";

    // Sessions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sessions` (
            `session_id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `token` VARCHAR(255) NOT NULL UNIQUE,
            `role` VARCHAR(50) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            INDEX `idx_sess_token` (`token`),
            INDEX `idx_sess_user` (`user_id`),
            CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  - Table `sessions` created or verified.\n";

    // Helper function to check column
    function columnExists($pdo, $table, $column) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = :table 
              AND COLUMN_NAME = :column
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    echo "\n2. Updating existing table columns...\n";

    // Users modifications
    $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `password` VARCHAR(255) NOT NULL");
    echo "  - `users.password` lengthened to VARCHAR(255).\n";

    if (!columnExists($pdo, 'users', 'status')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
        echo "  - Added `users.status`.\n";
    }
    if (!columnExists($pdo, 'users', 'last_login')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `last_login` DATETIME NULL");
        echo "  - Added `users.last_login`.\n";
    }
    if (!columnExists($pdo, 'users', 'created_at')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        echo "  - Added `users.created_at`.\n";
    }

    // Suppliers modifications
    if (!columnExists($pdo, 'suppliers', 'status')) {
        $pdo->exec("ALTER TABLE `suppliers` ADD COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
        echo "  - Added `suppliers.status`.\n";
    }
    if (!columnExists($pdo, 'suppliers', 'created_at')) {
        $pdo->exec("ALTER TABLE `suppliers` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        echo "  - Added `suppliers.created_at`.\n";
    }

    // Products modifications
    if (!columnExists($pdo, 'products', 'image_url')) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `image_url` VARCHAR(255) NULL AFTER `expiry_date`");
        echo "  - Added `products.image_url`.\n";
    }
    if (!columnExists($pdo, 'products', 'description')) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `description` TEXT NULL AFTER `image_url`");
        echo "  - Added `products.description`.\n";
    }
    if (!columnExists($pdo, 'products', 'status')) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `status` ENUM('active', 'discontinued') NOT NULL DEFAULT 'active' AFTER `description`");
        echo "  - Added `products.status`.\n";
    }

    // Sales modifications
    if (!columnExists($pdo, 'sales', 'customer_id')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `customer_id` INT NULL AFTER `cashier_id`");
        // Add foreign key
        $pdo->exec("ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL ON UPDATE CASCADE");
        echo "  - Added `sales.customer_id` with FK.\n";
    }
    if (!columnExists($pdo, 'sales', 'payment_method')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `payment_method` VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER `total_amount`");
        echo "  - Added `sales.payment_method`.\n";
    }
    if (!columnExists($pdo, 'sales', 'cash_received')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `cash_received` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `payment_method`");
        echo "  - Added `sales.cash_received`.\n";
    }
    if (!columnExists($pdo, 'sales', 'change_amount')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `change_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cash_received`");
        echo "  - Added `sales.change_amount`.\n";
    }
    if (!columnExists($pdo, 'sales', 'discount_amount')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `change_amount`");
        echo "  - Added `sales.discount_amount`.\n";
    }
    if (!columnExists($pdo, 'sales', 'tax_amount')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
        echo "  - Added `sales.tax_amount`.\n";
    }
    if (!columnExists($pdo, 'sales', 'subtotal_amount')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`");
        echo "  - Added `sales.subtotal_amount`.\n";
    }
    if (!columnExists($pdo, 'sales', 'status')) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `status` ENUM('completed', 'voided') NOT NULL DEFAULT 'completed' AFTER `subtotal_amount`");
        echo "  - Added `sales.status`.\n";
    }

    // Backfill existing sales defaults
    $pdo->exec("
        UPDATE `sales`
        SET 
            `customer_id` = 1,
            `subtotal_amount` = CASE WHEN `subtotal_amount` = 0 THEN `total_amount` ELSE `subtotal_amount` END,
            `cash_received` = CASE WHEN `cash_received` = 0 THEN `total_amount` ELSE `cash_received` END
        WHERE `customer_id` IS NULL OR `subtotal_amount` = 0
    ");
    echo "  - Backfilled existing sales rows (assigned customer_id=1, subtotal, cash_received).\n";

    // 3. Migrate passwords to bcrypt hashes
    echo "\n3. Migrating passwords to bcrypt...\n";
    $users = $pdo->query("SELECT user_id, username, password FROM users")->fetchAll();
    foreach ($users as $u) {
        $rawPass = $u['password'];
        // Check if already bcrypt (starts with $2y$)
        if (strpos($rawPass, '$2y$') !== 0) {
            $hashed = password_hash($rawPass, PASSWORD_BCRYPT);
            $upd = $pdo->prepare("UPDATE users SET password = :p WHERE user_id = :id");
            $upd->execute([':p' => $hashed, ':id' => $u['user_id']]);
            echo "  - User {$u['username']} (ID {$u['user_id']}): password successfully migrated to bcrypt hash.\n";
        } else {
            echo "  - User {$u['username']} (ID {$u['user_id']}): already hashed.\n";
        }
    }

    // 4. Log migration to audit_logs
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
        VALUES (1, 'MIGRATION_V2', 'SYSTEM', 0, 'Schema migration V2.0 executed successfully', '127.0.0.1')
    ");
    $stmt->execute();
    echo "\n4. Migration recorded in audit_logs.\n";

    echo "\n=== Migration V2.0 Completed Successfully! ===\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
