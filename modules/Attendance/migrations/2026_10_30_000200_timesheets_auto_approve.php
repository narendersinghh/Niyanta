<?php
// Timesheets are approved automatically until rejected: backfill draft/submitted entries and weeks as approved
// (entries of rejected weeks stay rejected), create week rows for tracked weeks and link entries to them.
$ws = "DATE(te.started_at) - INTERVAL WEEKDAY(te.started_at) DAY";
return ['up' => [
"UPDATE time_entries te LEFT JOIN timesheets t ON t.user_id=te.user_id AND t.week_start=$ws SET te.status=IF(t.status='rejected','rejected','approved') WHERE te.is_running=0 AND te.status IN ('draft','submitted')",
"UPDATE timesheets SET status='approved', submitted_at=COALESCE(submitted_at, NOW()) WHERE status IN ('draft','submitted')",
"INSERT INTO timesheets (user_id, week_start, total_seconds, billable_seconds, status, submitted_at) SELECT te.user_id, $ws AS wk, SUM(te.duration_seconds), SUM(CASE WHEN te.billable=1 THEN te.duration_seconds ELSE 0 END), 'approved', NOW() FROM time_entries te WHERE te.is_running=0 GROUP BY te.user_id, wk ON DUPLICATE KEY UPDATE total_seconds=VALUES(total_seconds), billable_seconds=VALUES(billable_seconds)",
"UPDATE time_entries te JOIN timesheets t ON t.user_id=te.user_id AND t.week_start=$ws SET te.timesheet_id=t.id WHERE te.timesheet_id IS NULL OR te.timesheet_id<>t.id",
"ALTER TABLE timesheets MODIFY status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'approved'",
], 'down' => [
"ALTER TABLE timesheets MODIFY status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft'",
]];
