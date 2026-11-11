<?php
/* Leave corrections: approvers edit or revoke requests (days returned to the balance as 'reversal' ledger entries), employees ask to cancel approved leave,
   HR books leave on behalf of an employee. leave_request_events keeps the visible history (who changed what, from / to, note). */
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"ALTER TABLE leave_ledger MODIFY kind ENUM('opening','allocation','accrual','adjustment','usage','carry_forward','expiry','shortage','reversal') NOT NULL",
"ALTER TABLE leave_requests ADD COLUMN created_by INT UNSIGNED NULL AFTER review_note, ADD COLUMN cancel_requested_at DATETIME NULL AFTER created_by, ADD COLUMN cancel_reason VARCHAR(500) NULL AFTER cancel_requested_at, ADD COLUMN updated_at DATETIME NULL AFTER created_at",
"CREATE TABLE leave_request_events (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_id INT UNSIGNED NOT NULL, actor_id INT UNSIGNED NULL, action VARCHAR(20) NOT NULL, note VARCHAR(500) NULL, changes TEXT NULL, created_at DATETIME NOT NULL,
  KEY idx_lre_req (request_id, id), CONSTRAINT fk_lre_req FOREIGN KEY (request_id) REFERENCES leave_requests(id) ON DELETE CASCADE, CONSTRAINT fk_lre_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL)$t",
], 'down' => ['DROP TABLE IF EXISTS leave_request_events', 'ALTER TABLE leave_requests DROP COLUMN created_by, DROP COLUMN cancel_requested_at, DROP COLUMN cancel_reason, DROP COLUMN updated_at',
  "DELETE FROM leave_ledger WHERE kind='reversal'", "ALTER TABLE leave_ledger MODIFY kind ENUM('opening','allocation','accrual','adjustment','usage','carry_forward','expiry','shortage') NOT NULL"]];
