<?php
// Grace time overrides: the company setting (payroll.grace_minutes, 90 by default) applies to everyone; a row exists only for an employee whose
// grace time HR changed in the salary form (0 = off, N = up to N minutes). Choosing the company setting again removes the row.
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => ["CREATE TABLE IF NOT EXISTS payroll_employee_settings (user_id INT UNSIGNED NOT NULL PRIMARY KEY, grace_minutes SMALLINT UNSIGNED NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NULL, CONSTRAINT fk_pes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t"],
  'down' => ['DROP TABLE IF EXISTS payroll_employee_settings']];
