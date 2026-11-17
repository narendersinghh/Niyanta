<?php
// Per-entry review replaces week review: the reason, reviewer and time live on each time entry. Entries rejected with their week
// take over the week's note and reviewer; week rows stay only as the totals cache (always approved). Safe to run twice.
$ws = "DATE(te.started_at) - INTERVAL WEEKDAY(te.started_at) DAY";
return ['up' => [
"ALTER TABLE time_entries ADD COLUMN IF NOT EXISTS review_note VARCHAR(500) NULL AFTER status, ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED NULL AFTER review_note, ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewed_by",
"CREATE INDEX IF NOT EXISTS idx_te_status ON time_entries (status, user_id)",
"UPDATE time_entries te JOIN timesheets t ON t.user_id=te.user_id AND t.week_start=$ws SET te.review_note=COALESCE(te.review_note, t.review_note, 'Rejected with the week.'), te.reviewed_by=COALESCE(te.reviewed_by, t.reviewed_by), te.reviewed_at=COALESCE(te.reviewed_at, t.reviewed_at) WHERE te.status='rejected' AND t.status='rejected'",
"UPDATE timesheets SET status='approved', reviewed_by=NULL, reviewed_at=NULL, review_note=NULL WHERE status<>'approved'",
], 'down' => [
"DROP INDEX IF EXISTS idx_te_status ON time_entries",
"ALTER TABLE time_entries DROP COLUMN IF EXISTS reviewed_at, DROP COLUMN IF EXISTS reviewed_by, DROP COLUMN IF EXISTS review_note",
]];
