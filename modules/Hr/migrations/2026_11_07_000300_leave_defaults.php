<?php
// Default setup, only where missing: "Paid Leave" (PL) accruing 2.5 days a month that also covers working-hour shortages,
// and the default fixed holidays for the current and next year (see HolidayService::DEFAULTS / KNOWN).
// The test runner (NY_TESTING) skips the holiday seed so other suites keep a calendar without company holidays.
return ['up' => static function (\PDO $pdo): void {
  $pdo->exec("INSERT INTO leave_types (name, code, description, annual_quota, allowance, monthly_accrual, carry_forward, carry_limit, max_balance, allow_half_day, needs_approval, notice_days, paid, color, active, shortage_cover)
    SELECT 'Paid Leave', 'PL', 'Use for festivals, personal work or medical emergencies. Also covers a shortage of working hours in a month.', 0, 'monthly', 2.5, 'all', NULL, NULL, 1, 1, 0, 1, 'mint', 1, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE code='PL')");
  if (defined('NY_TESTING')) { return; }
  $y = (int) date('Y');
  \Modules\Hr\Services\HolidayService::seedDefaults([$y, $y + 1]); },
'down' => ["DELETE FROM leave_types WHERE code='PL' AND NOT EXISTS (SELECT 1 FROM leave_requests r WHERE r.leave_type_id=leave_types.id)"]];
