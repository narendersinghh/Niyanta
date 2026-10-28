<?php
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE backups (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(190) NOT NULL, type ENUM('database','full') NOT NULL DEFAULT 'database', size BIGINT UNSIGNED NOT NULL DEFAULT 0, sha256 CHAR(64) NULL, status ENUM('running','success','failed') NOT NULL DEFAULT 'running', note VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, KEY idx_bk_created (created_at))$t",
"CREATE TABLE update_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, from_version VARCHAR(30) NOT NULL, to_version VARCHAR(60) NULL, channel ENUM('release','commit','upload') NOT NULL, ref VARCHAR(120) NULL, status ENUM('running','success','failed','rolled_back') NOT NULL DEFAULT 'running', log MEDIUMTEXT NULL, backup_id INT UNSIGNED NULL, started_by INT UNSIGNED NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL)$t",
], 'down' => ['DROP TABLE IF EXISTS update_logs, backups']];
