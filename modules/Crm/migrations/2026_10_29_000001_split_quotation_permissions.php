<?php
// Quotations became their own feature: mirror finance grants (permission rows themselves are added by Modules::syncPermissions).
return ['up' => [
"INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'crm.quotations.view' FROM role_permissions WHERE permission='crm.finance.view'",
"INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'crm.quotations.manage' FROM role_permissions WHERE permission='crm.finance.manage'",
], 'down' => ["DELETE FROM role_permissions WHERE permission IN ('crm.quotations.view','crm.quotations.manage')", "DELETE FROM permissions WHERE `key` IN ('crm.quotations.view','crm.quotations.manage')"]];
