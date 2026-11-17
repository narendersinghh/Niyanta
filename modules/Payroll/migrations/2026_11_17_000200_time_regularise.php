<?php
// Time regularisation per employee (one setting per person, chosen when HR edits the salary): no row or NULL = company setting (payroll.grace_minutes, 90 by default), 0 = off, N = up to N minutes.
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => ["CREATE TABLE IF NOT EXISTS payroll_employee_settings (user_id INT UNSIGNED NOT NULL PRIMARY KEY, grace_minutes SMALLINT UNSIGNED NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NULL, CONSTRAINT fk_pes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t"],
  'down' => ['DROP TABLE IF EXISTS payroll_employee_settings']];
