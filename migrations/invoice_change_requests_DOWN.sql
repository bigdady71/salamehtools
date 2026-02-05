-- Rollback invoice change requests feature

DROP VIEW IF EXISTS v_pending_invoice_requests;
DROP TABLE IF EXISTS invoice_change_requests;
