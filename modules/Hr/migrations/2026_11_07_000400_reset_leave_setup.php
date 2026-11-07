<?php
// One-time reset (asked for, no backup kept): drop every existing leave type, balance, quota, ledger entry, leave request
// and holiday, then set up the new default: "Paid Leave" (PL) accruing 2.5 days a month (carried forward, half days allowed,
// also covers a monthly working-hour shortage) and the default fixed holidays for the current and next year.
// The test runner (NY_TESTING) gets the leave type only, so other suites keep a calendar without company holidays.
return ['up' => static function (\PDO $pdo): void {
  $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
  foreach (['leave_ledger', 'leave_requests', 'leave_balances', 'employee_leave_quotas', 'leave_types', 'holidays'] as $t) { $pdo->exec("DELETE FROM $t"); }
  $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
  $pdo->exec("INSERT INTO leave_types (name, code, description, annual_quota, allowance, monthly_accrual, carry_forward, carry_limit, max_balance, allow_half_day, needs_approval, notice_days, paid, color, active, shortage_cover)
    VALUES ('Paid Leave', 'PL', 'Use for festivals, personal work or medical emergencies. Also covers a shortage of working hours in a month.', 0, 'monthly', 2.5, 'all', NULL, NULL, 1, 1, 0, 1, 'mint', 1, 1)");
  if (class_exists(\Modules\Hr\Services\LeaveService::class)) { \Modules\Hr\Services\LeaveService::flush(); }
  if (defined('NY_TESTING')) { return; }
  $y = (int) date('Y');
  \Modules\Hr\Services\HolidayService::seedDefaults([$y, $y + 1]); },
'down' => []];
