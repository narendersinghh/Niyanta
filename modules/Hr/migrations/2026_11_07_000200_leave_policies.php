<?php
// Configurable leave policies (allowance, accrual, carry forward, caps, request rules, eligibility, shortage cover),
// an auditable balance ledger, and holiday flags (movable festival date, date verified).
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"ALTER TABLE leave_types ADD COLUMN description VARCHAR(255) NULL AFTER code,
  ADD COLUMN allowance ENUM('none','yearly','monthly') NOT NULL DEFAULT 'yearly' AFTER annual_quota,
  ADD COLUMN monthly_accrual DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER allowance,
  ADD COLUMN carry_forward ENUM('none','limit','all') NOT NULL DEFAULT 'none' AFTER monthly_accrual,
  ADD COLUMN carry_limit DECIMAL(5,1) NULL AFTER carry_forward,
  ADD COLUMN max_balance DECIMAL(6,1) NULL AFTER carry_limit,
  ADD COLUMN allow_half_day TINYINT(1) NOT NULL DEFAULT 1 AFTER max_balance,
  ADD COLUMN needs_approval TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_half_day,
  ADD COLUMN notice_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER needs_approval,
  ADD COLUMN max_consecutive DECIMAL(5,1) NULL AFTER notice_days,
  ADD COLUMN min_service_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_consecutive,
  ADD COLUMN employment_types VARCHAR(120) NULL AFTER min_service_days,
  ADD COLUMN shortage_cover TINYINT(1) NOT NULL DEFAULT 0 AFTER employment_types",
"UPDATE leave_types SET allowance=IF(paid=1 AND annual_quota>0, 'yearly', 'none')",
// Monthly accruals such as 1.25 days need two decimals
"ALTER TABLE leave_balances MODIFY allocated DECIMAL(6,2) NOT NULL DEFAULT 0, MODIFY used DECIMAL(6,2) NOT NULL DEFAULT 0",
// Every credit and debit of a balance. Sum of credits per user/type/year = leave_balances.allocated, debits = used.
// period: Y-m for accrual/shortage/usage, YYYY for allocation/carry/expiry; the unique key makes cron runs idempotent.
"CREATE TABLE leave_ledger (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, leave_type_id INT UNSIGNED NOT NULL, year SMALLINT UNSIGNED NOT NULL,
  kind ENUM('opening','allocation','accrual','adjustment','usage','carry_forward','expiry','shortage') NOT NULL, days DECIMAL(6,2) NOT NULL, period VARCHAR(7) NULL,
  ref_id INT UNSIGNED NULL, note VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  UNIQUE KEY uq_ll_once (user_id, leave_type_id, year, kind, period), KEY idx_ll_user (user_id, year), KEY idx_ll_period (kind, period),
  CONSTRAINT fk_ll_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_ll_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE)$t",
// Opening entries so existing balances are fully itemised
"INSERT INTO leave_ledger (user_id, leave_type_id, year, kind, days, period, note, created_at) SELECT user_id, leave_type_id, year, 'opening', allocated, 'open', 'Balance before the ledger', NOW() FROM leave_balances WHERE allocated<>0",
"INSERT INTO leave_ledger (user_id, leave_type_id, year, kind, days, period, note, created_at) SELECT user_id, leave_type_id, year, 'usage', -used, 'open', 'Used before the ledger', NOW() FROM leave_balances WHERE used<>0",
"ALTER TABLE holidays ADD COLUMN movable TINYINT(1) NOT NULL DEFAULT 0 AFTER optional, ADD COLUMN verified TINYINT(1) NOT NULL DEFAULT 1 AFTER movable",
'UPDATE holidays SET movable=1 WHERE name REGEXP \'(?i)\\\\b(holi|holika|dhulandi|diwali|deepavali|govardhan|padwa|bhai dooj|bhaidooj|dussehra|dasara|vijayadashami|navratri|durga|janmashtami|raksha|rakhi|ganesh|vinayaka?|eid|id-ul|bakrid|muharram|milad|ramzan|ramadan|good friday|easter|guru nanak|gurpurab|shivratri|ram navami|mahavir|buddha|onam|chhath|karva|pongal|ugadi|gudi|vishu|baisakhi|vaisakhi|lohri|sankranti|teej|basant|vasant)\\\\b\'',
], 'down' => ['DROP TABLE IF EXISTS leave_ledger', 'ALTER TABLE holidays DROP COLUMN movable, DROP COLUMN verified',
  'ALTER TABLE leave_types DROP COLUMN description, DROP COLUMN allowance, DROP COLUMN monthly_accrual, DROP COLUMN carry_forward, DROP COLUMN carry_limit, DROP COLUMN max_balance, DROP COLUMN allow_half_day, DROP COLUMN needs_approval, DROP COLUMN notice_days, DROP COLUMN max_consecutive, DROP COLUMN min_service_days, DROP COLUMN employment_types, DROP COLUMN shortage_cover']];
