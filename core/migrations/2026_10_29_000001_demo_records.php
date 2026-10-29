<?php
// Tracks rows created by "Import demo data" so they can be removed cleanly later.
return ['up' => ["CREATE TABLE demo_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, table_name VARCHAR(64) NOT NULL, record_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, KEY idx_demo_table (table_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"], 'down' => ['DROP TABLE IF EXISTS demo_records']];
