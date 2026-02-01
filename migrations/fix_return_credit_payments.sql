-- ============================================================================
-- FIX: Add missing return_credit payments for existing customer returns
-- ============================================================================
-- This script creates payment records for credit returns that were created
-- before the payment creation code was added.
-- Run this once to fix historical data.
-- ============================================================================

-- Insert return_credit payments for completed credit returns that don't have payments yet
INSERT INTO payments (invoice_id, method, amount_usd, amount_lbp, received_by_user_id, received_at)
SELECT
    cr.invoice_id,
    'return_credit',
    cr.total_usd,
    cr.total_lbp,
    cr.sales_rep_id,
    cr.created_at
FROM customer_returns cr
WHERE cr.refund_method = 'credit'
  AND cr.status = 'completed'
  AND cr.invoice_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.invoice_id = cr.invoice_id
        AND p.method = 'return_credit'
        AND p.amount_usd = cr.total_usd
  );

-- Show what was inserted
SELECT 'Return credit payments created for:' as message;
SELECT cr.return_number, cr.total_usd, cr.invoice_id
FROM customer_returns cr
WHERE cr.refund_method = 'credit'
  AND cr.status = 'completed'
  AND cr.invoice_id IS NOT NULL;
