-- Ensure customers table has a user_id column and proper foreign key to users.
-- This migration is idempotent and safe to run multiple times.

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE customers
    MODIFY COLUMN user_id BIGINT UNSIGNED NULL;

ALTER TABLE customers
    ADD UNIQUE INDEX IF NOT EXISTS ux_customers_user_id (user_id);

-- Add FK to users if missing.
SET @fk_name := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customers'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME = 'fk_customers_user'
    LIMIT 1
);

SET @stmt := IF(
    @fk_name IS NULL,
    'ALTER TABLE customers ADD CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE add_fk FROM @stmt;
EXECUTE add_fk;
DEALLOCATE PREPARE add_fk;
