-- Migration: Add pdf_path column to invoices table for storing PDF file references
-- Date: 2026-01-27
-- Description: Enables tracking of generated PDF invoices stored on disk

-- Add pdf_path column to invoices table if it doesn't exist
ALTER TABLE invoices
ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(500) NULL DEFAULT NULL COMMENT 'Relative path to stored PDF file in storage/invoices/' AFTER status;

-- Add index for faster lookups on pdf_path
CREATE INDEX IF NOT EXISTS idx_invoices_pdf_path ON invoices(pdf_path);

-- Add pdf_generated_at timestamp for tracking when PDF was created
ALTER TABLE invoices
ADD COLUMN IF NOT EXISTS pdf_generated_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when PDF was generated' AFTER pdf_path;
