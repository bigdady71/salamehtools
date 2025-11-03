-- Phase 1 UP Migration: Settings module + Auto-Import infrastructure
-- Run this to create the settings and import_runs tables

-- Settings table for persisted key/value configuration
CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(120) PRIMARY KEY,
    v TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Import runs tracking table for auditing and idempotency
CREATE TABLE IF NOT EXISTS import_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('products') NOT NULL,
    source_path TEXT NOT NULL,
    checksum CHAR(64) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    rows_ok INT DEFAULT 0,
    rows_updated INT DEFAULT 0,
    rows_skipped INT DEFAULT 0,
    ok TINYINT(1) DEFAULT 0,
    message TEXT,
    INDEX idx_kind_checksum (kind, checksum(64)),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default settings for product import watcher
INSERT INTO settings(k, v) VALUES
    ('import.products.watch_path', ''),
    ('import.products.enabled', '0')
ON DUPLICATE KEY UPDATE v=VALUES(v);
