-- Fix stock movement trigger to only create movements for non-draft invoices
-- This prevents the "s_stock would go negative" error when creating orders with draft invoices

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_invoice_item_to_smv`$$

CREATE TRIGGER `trg_invoice_item_to_smv` AFTER INSERT ON `invoice_items` FOR EACH ROW
BEGIN
  DECLARE v_sales_rep BIGINT UNSIGNED;
  DECLARE v_invoice_status VARCHAR(50);

  -- Get sales rep and invoice status
  SELECT sales_rep_id, status INTO v_sales_rep, v_invoice_status
  FROM invoices
  WHERE id = NEW.invoice_id;

  -- Only create stock movements for issued/paid invoices, not draft/pending
  -- This prevents stock validation errors when creating initial orders
  IF v_sales_rep IS NOT NULL AND v_invoice_status IN ('issued', 'paid') THEN
    INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, order_item_id, note)
    VALUES (v_sales_rep, NEW.product_id, -NEW.quantity, 'sale', NEW.order_item_id, 'auto from invoice_items');
  END IF;
END$$

DELIMITER ;
