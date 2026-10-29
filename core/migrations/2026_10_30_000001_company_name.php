<?php
// "Niyanta" is now the fixed product name; the customer's name moves to company.name.
return ['up' => [
"INSERT INTO settings (`key`, `value`, updated_at) SELECT 'company.name', `value`, NOW() FROM settings WHERE `key`='branding.app_name' AND `value`<>'' AND `value`<>'Niyanta' AND NOT EXISTS (SELECT 1 FROM settings s2 WHERE s2.`key`='company.name')",
"DELETE FROM settings WHERE `key` IN ('branding.app_name','branding.tagline','branding.login_headline')",
], 'down' => []];
