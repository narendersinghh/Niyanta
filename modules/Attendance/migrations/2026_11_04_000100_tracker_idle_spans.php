<?php
// Desktop tracker: idle spans the person answered (keep or discard), with their exact times, so a discard removes exactly
// that span from the entries it overlaps (time_entries.discarded_seconds) and reports can show idle time per span.
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE time_idle_spans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, time_entry_id INT UNSIGNED NULL, started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, seconds INT UNSIGNED NOT NULL DEFAULT 0, action ENUM('keep','discard') NOT NULL, created_at DATETIME NOT NULL, KEY idx_tis_user (user_id, started_at), KEY idx_tis_entry (time_entry_id, action), CONSTRAINT fk_tis_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_tis_entry FOREIGN KEY (time_entry_id) REFERENCES time_entries(id) ON DELETE CASCADE)$t",
], 'down' => ['DROP TABLE IF EXISTS time_idle_spans']];
