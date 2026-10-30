<?php
// Desktop tracker: real keyboard/mouse activity per slot (the screenshot interval), keystroke and mouse counts on screenshots,
// and idle spans saved automatically by the app (time_idle_spans.action 'auto'; keep/discard prompts are gone).
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE activity_slots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, time_entry_id INT UNSIGNED NULL, slot_start DATETIME NOT NULL, slot_seconds SMALLINT UNSIGNED NOT NULL, active_seconds SMALLINT UNSIGNED NOT NULL, keystrokes INT UNSIGNED NULL, mouse_events INT UNSIGNED NULL, agent_token_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_as_user_slot (user_id, slot_start), KEY idx_as_user (user_id, slot_start), KEY idx_as_entry (time_entry_id), CONSTRAINT fk_as_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t",
"ALTER TABLE screenshots ADD COLUMN keystrokes INT UNSIGNED NULL AFTER activity_pct, ADD COLUMN mouse_events INT UNSIGNED NULL AFTER keystrokes",
"ALTER TABLE time_idle_spans MODIFY action ENUM('keep','discard','auto') NOT NULL",
], 'down' => [
"DELETE FROM time_idle_spans WHERE action='auto'",
"ALTER TABLE time_idle_spans MODIFY action ENUM('keep','discard') NOT NULL",
"ALTER TABLE screenshots DROP COLUMN mouse_events, DROP COLUMN keystrokes",
"DROP TABLE IF EXISTS activity_slots",
]];
