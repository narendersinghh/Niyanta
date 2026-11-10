<?php
// Stay signed in for 7 days: move the old defaults (2 hours idle, 12 hours absolute) to 7 days; custom values are kept.
return ['up' => [
  "UPDATE settings SET `value`='10080', updated_at=NOW() WHERE `key`='security.session_idle_minutes' AND `value` IN ('120','')",
  "UPDATE settings SET `value`='168', updated_at=NOW() WHERE `key`='security.session_absolute_hours' AND `value` IN ('12','')",
], 'down' => []];
