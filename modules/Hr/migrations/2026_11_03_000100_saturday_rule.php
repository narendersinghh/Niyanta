<?php
// Saturday rule per employee (full, half, alt24, alt13, alt24h, alt13h); NULL = company default (general.saturday_rule).
return ['up' => [
"ALTER TABLE employee_profiles ADD COLUMN saturday_rule VARCHAR(8) NULL AFTER weekly_off_days",
"INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES ('general.saturday_rule', 'full', NOW())",
], 'down' => [
"DELETE FROM settings WHERE `key`='general.saturday_rule'",
"ALTER TABLE employee_profiles DROP COLUMN saturday_rule",
]];
