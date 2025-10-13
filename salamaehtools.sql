-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 13, 2025 at 08:57 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `salamaehtools`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_table` varchar(100) DEFAULT NULL,
  `target_id` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `assigned_sales_rep_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location` varchar(191) DEFAULT NULL,
  `shop_type` varchar(100) DEFAULT NULL,
  `account_balance_lbp` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_id` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `driver_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `customer_contact_phone` varchar(50) DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `expected_at` datetime DEFAULT NULL,
  `status` enum('scheduled','preparing','ready','in_transit','delivered','failed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates`
--

CREATE TABLE `exchange_rates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `base_currency` varchar(10) NOT NULL,
  `quote_currency` varchar(10) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `valid_from` datetime NOT NULL,
  `valid_to` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `idempotency_keys`
--

CREATE TABLE `idempotency_keys` (
  `key` varchar(191) NOT NULL,
  `request_fingerprint` varchar(191) DEFAULT NULL,
  `response_code` int(11) DEFAULT NULL,
  `response_body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_body`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sales_rep_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('draft','issued','paid','voided') NOT NULL DEFAULT 'issued',
  `total_usd` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_lbp` decimal(18,2) NOT NULL DEFAULT 0.00,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `invoices`
--
DELIMITER $$
CREATE TRIGGER `trg_invoice_after_insert` AFTER INSERT ON `invoices` FOR EACH ROW BEGIN
  INSERT INTO salesperson_ar(invoice_id, salesperson_id, amount_usd, amount_lbp, status)
  VALUES (NEW.id, NEW.sales_rep_id, NEW.total_usd, NEW.total_lbp,
          CASE
            WHEN NEW.total_usd = 0 AND NEW.total_lbp = 0 THEN 'settled'
            ELSE 'open'
          END);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_invoice_after_update` AFTER UPDATE ON `invoices` FOR EACH ROW BEGIN
  IF (OLD.total_usd <> NEW.total_usd OR OLD.total_lbp <> NEW.total_lbp) THEN
    -- Recompute remaining = new totals - paid so far
    UPDATE salesperson_ar ar
    JOIN (
      SELECT
        p.invoice_id,
        COALESCE(SUM(p.amount_usd),0) AS paid_usd,
        COALESCE(SUM(p.amount_lbp),0) AS paid_lbp
      FROM payments p
      WHERE p.invoice_id = NEW.id
      GROUP BY p.invoice_id
    ) x ON x.invoice_id = ar.invoice_id
    SET
      ar.amount_usd = GREATEST(NEW.total_usd - x.paid_usd, 0),
      ar.amount_lbp = GREATEST(NEW.total_lbp - x.paid_lbp, 0),
      ar.status = CASE
                    WHEN GREATEST(NEW.total_usd - x.paid_usd,0)=0
                      AND GREATEST(NEW.total_lbp - x.paid_lbp,0)=0 THEN 'settled'
                    WHEN x.paid_usd>0 OR x.paid_lbp>0 THEN 'partial'
                    ELSE 'open'
                  END,
      ar.settled_at = CASE
                        WHEN GREATEST(NEW.total_usd - x.paid_usd,0)=0
                          AND GREATEST(NEW.total_lbp - x.paid_lbp,0)=0
                        THEN NOW() ELSE NULL
                      END;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `order_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(14,3) NOT NULL,
  `unit_price_usd` decimal(12,2) NOT NULL,
  `unit_price_lbp` decimal(18,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `invoice_items`
--
DELIMITER $$
CREATE TRIGGER `trg_invoice_item_to_smv` AFTER INSERT ON `invoice_items` FOR EACH ROW BEGIN
  DECLARE v_sales_rep BIGINT UNSIGNED;
  SELECT sales_rep_id INTO v_sales_rep FROM invoices WHERE id = NEW.invoice_id;

  -- Only if sales rep known
  IF v_sales_rep IS NOT NULL THEN
    INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, order_item_id, note)
    VALUES (v_sales_rep, NEW.product_id, -NEW.quantity, 'sale', NEW.order_item_id, 'auto from invoice_items');
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(100) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_number` varchar(50) DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `sales_rep_id` bigint(20) UNSIGNED DEFAULT NULL,
  `exchange_rate_id` bigint(20) UNSIGNED DEFAULT NULL,
  `total_usd` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_lbp` decimal(18,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `qr_token_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` decimal(14,3) NOT NULL,
  `unit_price_usd` decimal(12,2) NOT NULL,
  `unit_price_lbp` decimal(18,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_status_events`
--

CREATE TABLE `order_status_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('on_hold','approved','preparing','ready','in_transit','delivered','cancelled','returned') NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `method` enum('cash','qr_cash','card','bank','other') NOT NULL,
  `amount_usd` decimal(14,2) DEFAULT 0.00,
  `amount_lbp` decimal(18,2) DEFAULT 0.00,
  `received_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `qr_token_id` bigint(20) UNSIGNED DEFAULT NULL,
  `external_ref` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `payments`
--
DELIMITER $$
CREATE TRIGGER `trg_payment_after_insert` AFTER INSERT ON `payments` FOR EACH ROW BEGIN
  -- Ensure AR row exists (defensive)
  INSERT IGNORE INTO salesperson_ar(invoice_id, salesperson_id, amount_usd, amount_lbp, status)
  SELECT i.id, i.sales_rep_id, i.total_usd, i.total_lbp,
         CASE WHEN i.total_usd=0 AND i.total_lbp=0 THEN 'settled' ELSE 'open' END
  FROM invoices i WHERE i.id = NEW.invoice_id;

  UPDATE salesperson_ar ar
  JOIN invoices i ON i.id = ar.invoice_id
  LEFT JOIN (
    SELECT p.invoice_id,
           COALESCE(SUM(p.amount_usd),0) AS paid_usd,
           COALESCE(SUM(p.amount_lbp),0) AS paid_lbp
    FROM payments p
    WHERE p.invoice_id = NEW.invoice_id
    GROUP BY p.invoice_id
  ) x ON x.invoice_id = ar.invoice_id
  SET
    ar.amount_usd = GREATEST(i.total_usd - x.paid_usd, 0),
    ar.amount_lbp = GREATEST(i.total_lbp - x.paid_lbp, 0),
    ar.status = CASE
                  WHEN GREATEST(i.total_usd - x.paid_usd,0)=0
                    AND GREATEST(i.total_lbp - x.paid_lbp,0)=0 THEN 'settled'
                  WHEN x.paid_usd>0 OR x.paid_lbp>0 THEN 'partial'
                  ELSE 'open'
                END,
    ar.settled_at = CASE
                      WHEN GREATEST(i.total_usd - x.paid_usd,0)=0
                        AND GREATEST(i.total_lbp - x.paid_lbp,0)=0
                      THEN NOW() ELSE NULL
                    END;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `code_clean` varchar(100) DEFAULT NULL,
  `item_name` varchar(191) NOT NULL,
  `second_name` varchar(191) DEFAULT NULL,
  `topcat` varchar(100) DEFAULT NULL,
  `midcat` varchar(100) DEFAULT NULL,
  `topcat_name` varchar(191) DEFAULT NULL,
  `midcat_name` varchar(191) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `sale_price_usd` decimal(12,2) DEFAULT NULL,
  `wholesale_price_usd` decimal(12,2) DEFAULT NULL,
  `min_quantity` decimal(12,3) NOT NULL DEFAULT 1.000,
  `minqty_disc1` decimal(5,2) DEFAULT NULL,
  `minqty_disc2` decimal(5,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity_on_hand` decimal(14,3) NOT NULL DEFAULT 0.000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qr_tokens`
--

CREATE TABLE `qr_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(191) NOT NULL,
  `order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `invoice_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `redeemed_at` datetime DEFAULT NULL,
  `redeemed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salesperson_ar`
--

CREATE TABLE `salesperson_ar` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `salesperson_id` bigint(20) UNSIGNED NOT NULL,
  `amount_usd` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_lbp` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','partial','settled','written_off') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `settled_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `s_stock`
--

CREATE TABLE `s_stock` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `salesperson_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `qty_on_hand` decimal(14,3) NOT NULL DEFAULT 0.000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `s_stock_movements`
--

CREATE TABLE `s_stock_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `salesperson_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `delta_qty` decimal(14,3) NOT NULL,
  `reason` enum('load','sale','return','adjustment','transfer_in','transfer_out') NOT NULL,
  `order_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `s_stock_movements`
--
DELIMITER $$
CREATE TRIGGER `trg_smv_after_insert` AFTER INSERT ON `s_stock_movements` FOR EACH ROW BEGIN
  -- 1) van stock rollup
  UPDATE s_stock
     SET qty_on_hand = qty_on_hand + NEW.delta_qty
   WHERE salesperson_id = NEW.salesperson_id
     AND product_id     = NEW.product_id;

  -- 2) global stock: only sales reduce warehouse stock
  IF NEW.reason = 'sale' THEN
    IF (SELECT quantity_on_hand FROM products WHERE id=NEW.product_id) + NEW.delta_qty < 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='products would go negative on sale';
    END IF;

    UPDATE products
       SET quantity_on_hand = quantity_on_hand + NEW.delta_qty  -- NEW.delta_qty is negative
     WHERE id = NEW.product_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_smv_before_insert` BEFORE INSERT ON `s_stock_movements` FOR EACH ROW BEGIN
  IF NEW.reason IN ('load','transfer_in') AND NEW.delta_qty <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='load/transfer_in require positive delta_qty';
  END IF;
  IF NEW.reason IN ('sale','return','transfer_out') AND NEW.delta_qty >= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sale/return/transfer_out require negative delta_qty';
  END IF;

  -- Ensure s_stock row exists, then check future balance
  INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand)
  VALUES (NEW.salesperson_id, NEW.product_id, 0)
  ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand;

  IF (SELECT qty_on_hand FROM s_stock
      WHERE salesperson_id=NEW.salesperson_id AND product_id=NEW.product_id) + NEW.delta_qty < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='s_stock would go negative';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `role` enum('admin','sales_rep','warehouse','accountant','viewer') NOT NULL DEFAULT 'sales_rep',
  `permission_level` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `password_hash` varchar(191) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_invoice_balances`
-- (See below for the actual view)
--
CREATE TABLE `v_invoice_balances` (
`invoice_id` bigint(20) unsigned
,`invoice_number` varchar(50)
,`sales_rep_id` bigint(20) unsigned
,`total_usd` decimal(14,2)
,`total_lbp` decimal(18,2)
,`paid_usd` decimal(36,2)
,`paid_lbp` decimal(40,2)
,`balance_usd` decimal(37,2)
,`balance_lbp` decimal(41,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `webhook_outbox`
--

CREATE TABLE `webhook_outbox` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `aggregate_id` bigint(20) UNSIGNED NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_error` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure for view `v_invoice_balances`
--
DROP TABLE IF EXISTS `v_invoice_balances`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_invoice_balances`  AS SELECT `i`.`id` AS `invoice_id`, `i`.`invoice_number` AS `invoice_number`, `i`.`sales_rep_id` AS `sales_rep_id`, `i`.`total_usd` AS `total_usd`, `i`.`total_lbp` AS `total_lbp`, coalesce(sum(`p`.`amount_usd`),0) AS `paid_usd`, coalesce(sum(`p`.`amount_lbp`),0) AS `paid_lbp`, `i`.`total_usd`- coalesce(sum(`p`.`amount_usd`),0) AS `balance_usd`, `i`.`total_lbp`- coalesce(sum(`p`.`amount_lbp`),0) AS `balance_lbp` FROM (`invoices` `i` left join `payments` `p` on(`p`.`invoice_id` = `i`.`id`)) GROUP BY `i`.`id`, `i`.`invoice_number`, `i`.`sales_rep_id`, `i`.`total_usd`, `i`.`total_lbp` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_action_created` (`action`,`created_at`),
  ADD KEY `idx_audit_target` (`target_table`,`target_id`),
  ADD KEY `fk_audit_actor` (`actor_user_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_customers_rep` (`assigned_sales_rep_id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deliveries_order` (`order_id`),
  ADD KEY `idx_deliveries_status` (`status`),
  ADD KEY `fk_deliveries_driver` (`driver_user_id`);

--
-- Indexes for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fx_pair_from` (`base_currency`,`quote_currency`,`valid_from`);

--
-- Indexes for table `idempotency_keys`
--
ALTER TABLE `idempotency_keys`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `idx_invoices_order` (`order_id`),
  ADD KEY `idx_invoices_salesrep` (`sales_rep_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_items_invoice` (`invoice_id`),
  ADD KEY `fk_invoice_items_order_item` (`order_item_id`),
  ADD KEY `fk_invoice_items_product` (`product_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_notifications_unread` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `idx_orders_customer` (`customer_id`),
  ADD KEY `idx_orders_salesrep` (`sales_rep_id`),
  ADD KEY `idx_orders_created` (`created_at`),
  ADD KEY `fk_orders_fx` (`exchange_rate_id`),
  ADD KEY `fk_orders_qr` (`qr_token_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_items_order` (`order_id`),
  ADD KEY `idx_order_items_product` (`product_id`);

--
-- Indexes for table `order_status_events`
--
ALTER TABLE `order_status_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_status_order_created` (`order_id`,`created_at`),
  ADD KEY `fk_order_status_actor` (`actor_user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`),
  ADD KEY `idx_payments_received_at` (`received_at`),
  ADD KEY `fk_payments_user` (`received_by_user_id`),
  ADD KEY `fk_payments_qr` (`qr_token_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indexes for table `qr_tokens`
--
ALTER TABLE `qr_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `fk_qr_order` (`order_id`),
  ADD KEY `fk_qr_invoice` (`invoice_id`),
  ADD KEY `fk_qr_redeemed_user` (`redeemed_by_user_id`);

--
-- Indexes for table `salesperson_ar`
--
ALTER TABLE `salesperson_ar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ar_invoice` (`invoice_id`),
  ADD KEY `idx_ar_salesperson` (`salesperson_id`);

--
-- Indexes for table `s_stock`
--
ALTER TABLE `s_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stock_rep_product` (`salesperson_id`,`product_id`),
  ADD KEY `fk_s_stock_product` (`product_id`);

--
-- Indexes for table `s_stock_movements`
--
ALTER TABLE `s_stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_smv_rep_product` (`salesperson_id`,`product_id`,`created_at`),
  ADD KEY `fk_smv_product` (`product_id`),
  ADD KEY `fk_smv_order_item` (`order_item_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Indexes for table `webhook_outbox`
--
ALTER TABLE `webhook_outbox`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_outbox_status_next` (`status`,`next_attempt_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_status_events`
--
ALTER TABLE `order_status_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qr_tokens`
--
ALTER TABLE `qr_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salesperson_ar`
--
ALTER TABLE `salesperson_ar`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `s_stock`
--
ALTER TABLE `s_stock`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `s_stock_movements`
--
ALTER TABLE `s_stock_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webhook_outbox`
--
ALTER TABLE `webhook_outbox`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_rep` FOREIGN KEY (`assigned_sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `fk_deliveries_driver` FOREIGN KEY (`driver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_deliveries_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_salesrep` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_fx` FOREIGN KEY (`exchange_rate_id`) REFERENCES `exchange_rates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_qr` FOREIGN KEY (`qr_token_id`) REFERENCES `qr_tokens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_salesrep` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `order_status_events`
--
ALTER TABLE `order_status_events`
  ADD CONSTRAINT `fk_order_status_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_status_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_qr` FOREIGN KEY (`qr_token_id`) REFERENCES `qr_tokens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_user` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `qr_tokens`
--
ALTER TABLE `qr_tokens`
  ADD CONSTRAINT `fk_qr_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_qr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_qr_redeemed_user` FOREIGN KEY (`redeemed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `salesperson_ar`
--
ALTER TABLE `salesperson_ar`
  ADD CONSTRAINT `fk_ar_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ar_salesperson` FOREIGN KEY (`salesperson_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `s_stock`
--
ALTER TABLE `s_stock`
  ADD CONSTRAINT `fk_s_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_s_stock_rep` FOREIGN KEY (`salesperson_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `s_stock_movements`
--
ALTER TABLE `s_stock_movements`
  ADD CONSTRAINT `fk_smv_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_smv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_smv_rep` FOREIGN KEY (`salesperson_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
