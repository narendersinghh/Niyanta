<?php
// "Sign in with your browser" for Niyanta Tracker: one-time connect codes (sha256 only), see Services/DesktopConnect.php.
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE IF NOT EXISTS desktop_connect_codes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, code_hash CHAR(64) NOT NULL, challenge VARCHAR(64) NULL, state VARCHAR(64) NULL, device_name VARCHAR(100) NULL, device_os VARCHAR(10) NULL, purpose ENUM('web','browser','install') NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_ip VARCHAR(45) NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_dcc_hash (code_hash), KEY idx_dcc_expires (expires_at), KEY idx_dcc_user (user_id), CONSTRAINT fk_dcc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t",
], 'down' => ['DROP TABLE IF EXISTS desktop_connect_codes']];
