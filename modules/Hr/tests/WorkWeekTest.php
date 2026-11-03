<?php
use Core\{DB, Settings, WorkWeek};
use Modules\Hr\Services\LeaveService as LS;

/* November 2026: Saturdays are the 7th (1st), 14th (2nd), 21st (3rd) and 28th (4th). */
return [
  'saturday rules weigh each Saturday of the month' => function (): void {
    $w = static fn (string $d, string $r) => WorkWeek::weight($d, [7], $r);
    assert_eq(1.0, $w('2026-11-14', 'full'));
    assert_eq(0.5, $w('2026-11-14', 'half'));
    assert_eq([1.0, 0.0, 1.0, 0.0], array_map(static fn ($d) => $w($d, 'alt24'), ['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28']));
    assert_eq([0.0, 1.0, 0.0, 1.0], array_map(static fn ($d) => $w($d, 'alt13'), ['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28']));
    assert_eq([0.5, 0.0, 0.5, 0.0], array_map(static fn ($d) => $w($d, 'alt24h'), ['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28']));
    assert_eq(0.0, WorkWeek::weight('2026-11-14', [6, 7], 'half'), 'Saturday off overrides the rule');
    assert_eq(1.0, $w('2026-11-13', 'alt24h'), 'other weekdays unaffected');
    assert_eq(0.0, $w('2026-11-15', 'full'), 'Sunday off'); },
  'working days follow the company Saturday rule' => function (): void {
    $prev = Settings::get('general.weekend_days', '6,7');
    Settings::setMany(['general.weekend_days' => '7', 'general.saturday_rule' => 'alt24']); LS::flush();
    $holidays = (float) DB::value("SELECT COUNT(*) FROM holidays WHERE optional=0 AND holiday_date BETWEEN '2026-11-01' AND '2026-11-30' AND DAYOFWEEK(holiday_date)<>1");
    assert_eq(30 - 5 - 2 - $holidays, LS::workingDays('2026-11-01', '2026-11-30'), '5 Sundays and two alternate Saturdays off');
    Settings::setMany(['general.saturday_rule' => 'half']); LS::flush();
    assert_eq(30 - 5 - 2 - $holidays, LS::workingDays('2026-11-01', '2026-11-30'), 'four half Saturdays = two days');
    assert_true(LS::isWeekend('2026-11-15') && !LS::isWeekend('2026-11-14'));
    Settings::setMany(['general.weekend_days' => $prev, 'general.saturday_rule' => 'full']); LS::flush(); },
  'employee override uses their own Saturday rule' => function (): void {
    $u = make_user('employee', 'sat@workweek.test');
    DB::query('INSERT INTO employee_profiles (user_id, weekly_off_days, saturday_rule) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE weekly_off_days=VALUES(weekly_off_days), saturday_rule=VALUES(saturday_rule)', [$u, '7', 'alt13']);
    LS::flush();
    assert_eq('alt13', LS::scheduleFor($u)['saturday']);
    assert_true(!LS::isWorkingDayFor($u, '2026-11-07') && LS::isWorkingDayFor($u, '2026-11-14'));
    assert_eq(1.0, LS::workingDaysFor($u, '2026-11-14', '2026-11-15')); },
];
