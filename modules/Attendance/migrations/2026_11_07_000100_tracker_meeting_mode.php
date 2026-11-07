<?php
// Desktop tracker meeting mode: activity slots and screenshots taken while the person had meeting mode on are flagged, so
// their activity is kept (and visible to managers) but never counted in activity averages.
return ['up' => [
"ALTER TABLE activity_slots ADD COLUMN meeting TINYINT(1) NOT NULL DEFAULT 0 AFTER mouse_events",
"ALTER TABLE screenshots ADD COLUMN meeting TINYINT(1) NOT NULL DEFAULT 0 AFTER mouse_events",
], 'down' => [
"ALTER TABLE screenshots DROP COLUMN meeting",
"ALTER TABLE activity_slots DROP COLUMN meeting",
]];
