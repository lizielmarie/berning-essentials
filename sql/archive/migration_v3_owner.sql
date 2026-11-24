-- ========================================================================
-- BERNING STORE — DATABASE MIGRATION V3: OWNER MODULE & ENHANCED AUTH
-- ========================================================================

-- 1. Add email and phone to `users` table if not exists
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(100) UNIQUE NULL AFTER username;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER email;

-- 2. Safely reassign legacy seed references for user_id = 1 to user_id = 2 (Liziel Cashier/Manager)
-- so user_id = 1 can be cleanly removed, allowing the real store owner to register as the first owner
UPDATE stock_in SET received_by = 2 WHERE received_by = 1;
UPDATE stock_adjustments SET user_id = 2 WHERE user_id = 1;
UPDATE audit_logs SET user_id = 2 WHERE user_id = 1;
DELETE FROM sessions WHERE user_id = 1;

-- 3. Delete the legacy seed default owner account so the first owner can register
DELETE FROM users WHERE role = 'Owner' AND username = 'berning_owner';
