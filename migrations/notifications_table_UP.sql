-- Create notifications table for sales rep notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    reference_id INT DEFAULT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
