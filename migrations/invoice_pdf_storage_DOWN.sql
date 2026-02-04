-- Migration DOWN: Remove pdf_path column from invoices table
-- Date: 2026-01-27

-- Remove index
DROP INDEX IF EXISTS idx_invoices_pdf_path ON invoices;

-- Remove columns
ALTER TABLE invoices
DROP COLUMN IF EXISTS pdf_generated_at;

ALTER TABLE invoices
DROP COLUMN IF EXISTS pdf_path;
