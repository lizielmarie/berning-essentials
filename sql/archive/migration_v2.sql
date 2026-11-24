-- ==============================================================================
-- BERNING STORE — DATABASE MIGRATION V2.0
-- Comprehensive Schema Enhancements for POS & Store Management System
-- ==============================================================================

USE `berning_store`;

-- 1. Create `customers` table
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

-- Seed default Walk-in Customer (ID 1)
INSERT INTO `customers` (`customer_id`, `customer_name`, `phone`, `email`, `address`, `status`)
SELECT 1, 'Walk-in Customer', 'N/A', 'walkin@berningstore.local', 'Storefront / Cash Counter', 'active'
WHERE NOT EXISTS (SELECT 1 FROM `customers` WHERE `customer_id` = 1);

-- 2. Create `stock_adjustments` table
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

-- 3. Create `audit_logs` table
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

-- 4. Create `sessions` table
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

-- 5. Modify `users` table: increase password length for bcrypt, add status and audit columns
ALTER TABLE `users` 
    MODIFY COLUMN `password` VARCHAR(255) NOT NULL;

-- Conditionally add columns if they do not already exist
-- (Using procedural checks or ALTER TABLE with IGNORE/safe statements in PHP migration runner)
