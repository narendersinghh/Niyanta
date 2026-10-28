<?php
return ['up' => [
"ALTER TABLE time_entries ADD COLUMN discarded_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER idle_seconds",
"ALTER TABLE screenshots ADD KEY idx_ss_entry (time_entry_id)",
"ALTER TABLE attendance ADD KEY idx_att_open (clock_out, work_date)",
"ALTER TABLE time_entries ADD KEY idx_te_running_hb (is_running, last_heartbeat_at)",
], 'down' => [
"ALTER TABLE time_entries DROP KEY idx_te_running_hb",
"ALTER TABLE attendance DROP KEY idx_att_open",
"ALTER TABLE screenshots DROP KEY idx_ss_entry",
"ALTER TABLE time_entries DROP COLUMN discarded_seconds",
]];
