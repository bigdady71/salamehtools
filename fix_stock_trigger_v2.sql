-- Drop and recreate the trigger to prevent duplicate stock movements
DROP TRIGGER IF EXISTS trg_invoice_item_to_smv;

DELIMITER $$

CREATE TRIGGER trg_invoice_item_to_smv
AFTER INSERT ON invoice_items
FOR EACH ROW
BEGIN
  DECLARE v_sales_rep BIGINT UNSIGNED;
  DECLARE v_invoice_status VARCHAR(50);
  DECLARE v_existing_movement INT DEFAULT 0;

  -- Get sales rep and invoice status
  SELECT sales_rep_id, status INTO v_sales_rep, v_invoice_status
  FROM invoices
  WHERE id = NEW.invoice_id;

  -- Only create stock movement for issued or paid invoices
  IF v_sales_rep IS NOT NULL AND v_invoice_status IN ('issued', 'paid') THEN

    -- Check if a stock movement already exists for this order_item_id
    -- to prevent duplicate movements when invoice items are re-synced
    IF NEW.order_item_id IS NOT NULL THEN
      SELECT COUNT(*) INTO v_existing_movement
      FROM s_stock_movements
      WHERE order_item_id = NEW.order_item_id
        AND salesperson_id = v_sales_rep
        AND product_id = NEW.product_id
        AND reason = 'sale';
    END IF;

    -- Only insert if no movement exists yet
    IF v_existing_movement = 0 THEN
      INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, order_item_id, note)
      VALUES (v_sales_rep, NEW.product_id, -NEW.quantity, 'sale', NEW.order_item_id, 'auto from invoice_items');
    END IF;

  END IF;
END$$

DELIMITER ;
