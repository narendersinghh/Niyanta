<?php
// Desktop tracker: device metadata on agent tokens, the device on each time entry, multi-display screenshots,
// and the screenshot interval moved from minutes (attendance.screenshot_interval) to seconds.
return ['up' => [
"ALTER TABLE agent_tokens ADD COLUMN kind ENUM('token','desktop') NOT NULL DEFAULT 'token' AFTER name, ADD COLUMN device_os VARCHAR(40) NULL AFTER kind, ADD COLUMN app_version VARCHAR(40) NULL AFTER device_os, ADD COLUMN last_ip VARCHAR(45) NULL AFTER last_used_at",
"ALTER TABLE time_entries MODIFY source ENUM('timer','manual','agent','desktop') NOT NULL DEFAULT 'timer', ADD COLUMN agent_token_id INT UNSIGNED NULL AFTER source",
"ALTER TABLE screenshots MODIFY source ENUM('browser','agent','desktop') NOT NULL DEFAULT 'browser', ADD COLUMN display_index TINYINT UNSIGNED NULL AFTER activity_pct, ADD COLUMN agent_token_id INT UNSIGNED NULL AFTER display_index",
"INSERT IGNORE INTO settings (`key`, `value`, updated_at) SELECT 'attendance.screenshot_interval_seconds', LEAST(3600, GREATEST(10, CAST(o.`value` AS UNSIGNED) * 60)), NOW() FROM settings o WHERE o.`key`='attendance.screenshot_interval' AND o.`value` REGEXP '^[0-9]+$'",
], 'down' => [
"DELETE FROM settings WHERE `key`='attendance.screenshot_interval_seconds'",
"ALTER TABLE screenshots DROP COLUMN agent_token_id, DROP COLUMN display_index",
"ALTER TABLE time_entries DROP COLUMN agent_token_id",
"ALTER TABLE agent_tokens DROP COLUMN last_ip, DROP COLUMN app_version, DROP COLUMN device_os, DROP COLUMN kind",
]];
