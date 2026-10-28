<?php
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE ai_usage (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, provider VARCHAR(30) NOT NULL, model VARCHAR(100) NOT NULL, feature VARCHAR(60) NOT NULL, input_tokens INT UNSIGNED NOT NULL DEFAULT 0, output_tokens INT UNSIGNED NOT NULL DEFAULT 0, status ENUM('ok','error','blocked') NOT NULL DEFAULT 'ok', error VARCHAR(500) NULL, created_at DATETIME NOT NULL, KEY idx_aiu_user (user_id, created_at), KEY idx_aiu_created (created_at))$t",
"CREATE TABLE ai_actions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, type VARCHAR(40) NOT NULL, title VARCHAR(190) NOT NULL, payload JSON NOT NULL, status ENUM('pending','approved','rejected','executed','failed') NOT NULL DEFAULT 'pending', result VARCHAR(500) NULL, decided_by INT UNSIGNED NULL, decided_at DATETIME NULL, created_at DATETIME NOT NULL, KEY idx_aia_user (user_id, status), CONSTRAINT fk_aia_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t",
], 'down' => ['DROP TABLE IF EXISTS ai_actions, ai_usage']];
