-- Migration: Create lebanon_cities table for city/governorate lookup
-- Run this migration then import your CSV data

CREATE TABLE IF NOT EXISTS lebanon_cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city_name VARCHAR(100) NOT NULL,
    governorate VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_governorate (governorate),
    INDEX idx_city_name (city_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- After running this migration, import your CSV using phpMyAdmin or:
-- LOAD DATA INFILE 'your_file.csv'
-- INTO TABLE lebanon_cities
-- FIELDS TERMINATED BY ','
-- ENCLOSED BY '"'
-- LINES TERMINATED BY '\n'
-- IGNORE 1 ROWS
-- (city_name, governorate);
