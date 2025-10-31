-- Adds invoice_ready flag to orders for invoice readiness tracking
ALTER TABLE `orders`
    ADD COLUMN `invoice_ready` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_lbp`;

-- Backfill existing orders as not ready
UPDATE `orders` SET `invoice_ready` = 0 WHERE `invoice_ready` IS NULL;
