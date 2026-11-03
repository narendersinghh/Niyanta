<?php
// Screenshot retention default raised from 30 to 60 days; workspaces still on the old default move to 60.
return ['up' => ["UPDATE settings SET `value`='60', updated_at=NOW() WHERE `key`='attendance.screenshot_retention_days' AND `value`='30'"], 'down' => []];
