<?php
namespace Core;

/**
 * Saturday rule on top of the weekly off days: a working Saturday can be a full day, a half day, alternate (2nd & 4th or
 * 1st & 3rd Saturday of the month off) or alternate with the remaining Saturdays as half days.
 * Company default: setting general.saturday_rule; employees may override it with their own schedule (HR).
 */
final class WorkWeek {
  public const RULES = ['full' => 'Full working day', 'half' => 'Half day', 'alt24' => '2nd & 4th Saturday off', 'alt13' => '1st & 3rd Saturday off',
    'alt24h' => '2nd & 4th off, other Saturdays half day', 'alt13h' => '1st & 3rd off, other Saturdays half day'];
  public const SHORT = ['full' => '', 'half' => 'Sat half day', 'alt24' => '2nd & 4th Sat off', 'alt13' => '1st & 3rd Sat off', 'alt24h' => '2nd & 4th Sat off, others half', 'alt13h' => '1st & 3rd Sat off, others half'];

  public static function valid(?string $r): bool { return $r !== null && isset(self::RULES[$r]); }

  /** Company default Saturday rule. */
  public static function companyRule(): string { $r = (string) Settings::get('general.saturday_rule', 'full'); return self::valid($r) ? $r : 'full'; }

  /** Share of a normal working day the date counts for: 0 (off), 0.5 (half day) or 1, given ISO off days and the Saturday rule. */
  public static function weight(string|int $date, array $off, string $rule = 'full'): float {
    $t = is_int($date) ? $date : strtotime($date);
    $n = (int) date('N', $t);
    if (in_array($n, $off, true)) { return 0.0; }
    if ($n !== 6 || $rule === 'full' || !self::valid($rule)) { return 1.0; }
    $week = intdiv((int) date('j', $t) - 1, 7) + 1;
    if (str_starts_with($rule, 'alt') && in_array($week, $rule[3] === '2' ? [2, 4] : [1, 3], true)) { return 0.0; }
    return in_array($rule, ['half', 'alt24h', 'alt13h'], true) ? 0.5 : 1.0; }

  /** Short label to append to an off-days label, e.g. "Sun · 2nd & 4th Sat off" (empty for a full Saturday or when Saturday is off anyway). */
  public static function label(string $rule, array $off = []): string { return in_array(6, $off, true) ? '' : (self::SHORT[$rule] ?? ''); } }
