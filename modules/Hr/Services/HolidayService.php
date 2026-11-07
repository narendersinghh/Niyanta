<?php
namespace Modules\Hr\Services;
use Core\{Audit, DB, Modules, Settings};

/**
 * Holiday lists per year: default fixed holidays, creating next year's list and asking the configured AI model
 * (Settings › AI assistant, "Goraiya") for the dates of movable festivals. Every AI call is a one-shot completion
 * (no tools) through Modules\Ai\Services\Provider, logged in ai_usage as feature 'hr.holidays'. Without AI (module or
 * feature off, assistant switched off, no usable model, budget used up) everything still works with manual dates.
 * holidays.movable = date changes every year (festival); holidays.verified = 0 when a date was copied as a placeholder.
 */
final class HolidayService {
  /** Default fixed holidays: key => [name, month-day for fixed-date ones (null = movable), name pattern that counts as "already there"]. */
  public const DEFAULTS = [
    'republic' => ['Republic Day', '01-26', '/\brepublic\b/i'],
    'holika' => ['Holika Dahan', null, '/\bholika\b/i'],
    'dhulandi' => ['Rangwali Holi (Dhulandi)', null, '/\b(dhulandi|dhuleti|rangwali|holi)\b/i'],
    'independence' => ['Independence Day', '08-15', '/\bindependence\b/i'],
    'gandhi' => ['Gandhi Jayanti', '10-02', '/\bgandhi\b/i'],
    'diwali' => ['Diwali', null, '/^(?!.*\b(after|govardhan|padwa)\b).*\b(diwali|deepavali|lakshmi puja)\b/i'],
    'govardhan' => ['Day after Diwali (Govardhan Puja)', null, '/\b(govardhan|padwa|after diwali)\b/i'],
  ];
  /** Known dates of the movable defaults (as published in Indian holiday calendars; panchang-dependent, verify each year). */
  public const KNOWN = [
    2026 => ['holika' => '2026-03-03', 'dhulandi' => '2026-03-04', 'diwali' => '2026-11-08', 'govardhan' => '2026-11-09'],
    2027 => ['holika' => '2027-03-21', 'dhulandi' => '2027-03-22', 'diwali' => '2027-10-29', 'govardhan' => '2027-10-30'],
  ];
  /** Names whose date moves every year (lunar / regional calendars). */
  public const MOVABLE_RE = '/\b(holi|holika|dhulandi|diwali|deepavali|govardhan|padwa|bhai dooj|bhaidooj|dussehra|dasara|vijayadashami|navratri|durga|janmashtami|raksha|rakhi|ganesh|vinayaka?|eid|id-ul|bakrid|muharram|milad|ramzan|ramadan|good friday|easter|guru nanak|gurpurab|shivratri|ram navami|mahavir|buddha|onam|chhath|karva|pongal|ugadi|gudi|vishu|baisakhi|vaisakhi|lohri|sankranti|teej|basant|vasant)\b/i';

  /** Test hook: fn(string $system, string $prompt): string replaces the real AI call. */
  public static ?\Closure $complete = null;

  public static function isMovable(string $name): bool { return (bool) preg_match(self::MOVABLE_RE, $name); }

  /** Default key matching a holiday name, or null. */
  public static function defaultKey(string $name): ?string {
    foreach (self::DEFAULTS as $k => [, , $re]) { if ($k === 'dhulandi' && preg_match('/\bholika\b/i', $name)) { continue; } if (preg_match($re, $name)) { return $k; } }
    return null; }

  /** Built-in date of a default holiday in a year (fixed-date ones always, movable ones for years in KNOWN), else null. */
  public static function knownDate(string $name, int $year): ?string {
    $k = self::defaultKey($name);
    if (!$k) { return null; }
    $md = self::DEFAULTS[$k][1];
    return $md ? "$year-$md" : (self::KNOWN[$year][$k] ?? null); }

  /** Insert the default fixed holidays that are missing in each year (matched by name). Returns rows added. Movable ones without a known date are skipped. */
  public static function seedDefaults(array $years): int {
    $n = 0;
    foreach ($years as $y) {
      $y = (int) $y;
      $names = DB::column('SELECT name FROM holidays WHERE holiday_date BETWEEN ? AND ?', ["$y-01-01", "$y-12-31"]);
      foreach (self::DEFAULTS as $k => [$name, $md, $re]) {
        if (array_filter($names, static fn ($x) => self::defaultKey($x) === $k)) { continue; }
        $date = $md ? "$y-$md" : (self::KNOWN[$y][$k] ?? null);
        if (!$date) { continue; }
        $n += DB::query('INSERT IGNORE INTO holidays (holiday_date, name, optional, movable, verified) VALUES (?,?,0,?,1)', [$date, $name, $md ? 0 : 1])->rowCount(); } }
    LeaveService::flush();
    return $n; }

  /** [ok, reason]: whether the AI model can be asked (reason is a short hint for the UI when not). */
  public static function aiStatus(): array {
    if (self::$complete) { return [true, null]; }
    if (!class_exists(\Modules\Ai\Services\Assistant::class) || !Modules::enabled('ai') || (function_exists('feature_on') && !feature_on('ai'))) { return [false, 'The AI assistant (Goraiya) is switched off. Turn it on in Settings › Features to fix dates with AI.']; }
    [$ok, $why] = \Modules\Ai\Services\Assistant::status();
    if (!$ok) { return [false, $why === 'off' ? 'Goraiya is switched off. Turn it on in Settings › AI assistant to fix dates with AI.' : 'No AI model is set up. Add one in Settings › AI assistant (Goraiya) to fix dates with AI.']; }
    $budget = \Modules\Ai\Services\Usage::budget();
    if ($budget > 0 && \Modules\Ai\Services\Usage::monthUsed() >= $budget) { return [false, 'This month\'s AI budget is used up. It resets on the 1st.']; }
    return [true, null]; }

  /** One-shot completion on the configured model. Throws \RuntimeException with a friendly message. */
  public static function complete(string $system, string $prompt, ?int $uid): string {
    if (self::$complete) { return (string) (self::$complete)($system, $prompt); }
    [$ok, $why] = self::aiStatus();
    if (!$ok) { throw new \RuntimeException((string) $why); }
    $row = \Modules\Ai\Services\ModelRegistry::current();
    $m = \Modules\Ai\Services\ModelRegistry::withKey($row);
    try {
      $r = \Modules\Ai\Services\Provider::make($m)->chat($system, [['role' => 'user', 'content' => $prompt]], [], ['max_tokens' => max(1500, (int) ($m['max_tokens'] ?: 0)), 'timeout' => 45]);
      \Modules\Ai\Services\Usage::log($uid, (string) $m['provider'], (string) ($r['model'] ?: $m['model']), 'hr.holidays', 'ok', null, (int) $r['in'], (int) $r['out']);
      return (string) $r['text']; }
    catch (\Throwable $e) {
      \Modules\Ai\Services\Usage::log($uid, (string) $m['provider'], (string) $m['model'], 'hr.holidays', 'error', $e instanceof \Modules\Ai\Services\AiException ? ($e->detail ?: $e->getMessage()) : $e->getMessage());
      throw new \RuntimeException($e instanceof \Modules\Ai\Services\AiException ? $e->getMessage() : 'The AI model could not be reached. Please try again.'); } }

  /** Prompt asking for strict JSON dates of the given holiday names in a year (India). */
  public static function prompt(array $names, int $year): string {
    $list = implode("\n", array_map(static fn ($i, $n) => ($i + 1).'. '.$n, array_keys($names), $names));
    return "Give the date in $year of each holiday below as observed in India (the date most Indian companies list as the public holiday; for a multi-day festival the main day named).\n$list\n\n"
      .'Reply with JSON only, no other text, exactly in this shape: {"year": '.$year.', "holidays": [{"name": "<name exactly as given>", "date": "YYYY-MM-DD"}]}. Use null as the date when you are not sure.'; }

  /** Parse an AI reply into [given name => Y-m-d]; ignores unknown names, invalid dates and dates outside the year. */
  public static function parseDates(string $text, array $names, int $year): array {
    $t = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)) ?? '');
    $a = strpos($t, '{'); $b = strrpos($t, '}'); $c = strpos($t, '[');
    $j = null;
    if ($a !== false && $b !== false && ($c === false || $a < $c)) { $j = json_decode(substr($t, $a, $b - $a + 1), true); }
    if (!is_array($j) && $c !== false && ($e = strrpos($t, ']')) !== false) { $j = json_decode(substr($t, $c, $e - $c + 1), true); }
    if (!is_array($j)) { return []; }
    $items = isset($j['holidays']) && is_array($j['holidays']) ? $j['holidays'] : $j;
    $norm = static fn ($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $s) ?? ''));
    $byNorm = [];
    foreach ($names as $n) { $byNorm[$norm($n)] = $n; }
    $out = [];
    foreach ($items as $k => $it) {
      [$name, $date] = is_array($it) ? [$it['name'] ?? null, $it['date'] ?? null] : [is_string($k) ? $k : null, $it];
      $given = $byNorm[$norm($name)] ?? null;
      if (!$given || !is_string($date)) { continue; }
      $d = \DateTime::createFromFormat('!Y-m-d', $date);
      if (!$d || $d->format('Y-m-d') !== $date || (int) $d->format('Y') !== $year) { continue; }
      $out[$given] = $date; }
    return $out; }

  /** Ask the AI for the dates of holiday names in a year: ['dates' => [name => Y-m-d], 'error' => ?string]. */
  public static function aiDates(array $names, int $year, ?int $uid): array {
    $names = array_values(array_unique(array_filter(array_map('strval', $names), static fn ($n) => trim($n) !== '')));
    if (!$names) { return ['dates' => [], 'error' => null]; }
    try { $text = self::complete('You are a calendar assistant for Indian public holidays and festivals. You answer with strict JSON only.', self::prompt($names, $year), $uid); }
    catch (\Throwable $e) { return ['dates' => [], 'error' => $e->getMessage()]; }
    $dates = self::parseDates($text, $names, $year);
    return ['dates' => $dates, 'error' => $dates ? null : 'The AI reply had no usable dates. Please try again or edit the dates by hand.']; }

  /**
   * Create a year's list from the previous year: fixed-date holidays keep their day and month; movable festivals use the
   * built-in date, else the AI date (when $useAi and AI is available), else last year's date as an unverified placeholder.
   * Holidays already in the target year (same name) are skipped. Returns counts and an optional AI error.
   */
  public static function createYear(int $year, bool $useAi, ?int $uid): array {
    $res = ['created' => 0, 'ai' => 0, 'unverified' => 0, 'skipped' => 0, 'error' => null, 'source' => 0];
    $src = DB::all('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name', [($year - 1).'-01-01', ($year - 1).'-12-31']);
    $res['source'] = count($src);
    if (!$src) { return $res; }
    $have = array_map('strtolower', DB::column('SELECT name FROM holidays WHERE holiday_date BETWEEN ? AND ?', ["$year-01-01", "$year-12-31"]));
    $todo = array_values(array_filter($src, static fn ($h) => !in_array(strtolower($h['name']), $have, true)));
    $res['skipped'] = count($src) - count($todo);
    $plan = []; $ask = [];
    foreach ($todo as $h) {
      $mov = (int) $h['movable'] || self::isMovable($h['name']);
      $md = substr($h['holiday_date'], 5);
      $same = $md === '02-29' && !checkdate(2, 29, $year) ? "$year-02-28" : "$year-$md";
      $known = self::knownDate($h['name'], $year);
      if (!$mov) { $plan[] = [$h, $known ?? $same, 1]; }
      elseif ($known) { $plan[] = [$h, $known, 1]; }
      else { $plan[] = [$h, $same, 0]; $ask[] = $h['name']; } }
    if ($ask && $useAi && self::aiStatus()[0]) {
      $r = self::aiDates($ask, $year, $uid);
      $res['error'] = $r['error'];
      foreach ($plan as &$p) { if (!$p[2] && isset($r['dates'][$p[0]['name']])) { $p[1] = $r['dates'][$p[0]['name']]; $p[2] = 1; $res['ai']++; } }
      unset($p); }
    foreach ($plan as [$h, $date, $ok]) {
      $mov = (int) $h['movable'] || self::isMovable($h['name']) ? 1 : 0;
      if (DB::query('INSERT IGNORE INTO holidays (holiday_date, name, optional, movable, verified) VALUES (?,?,?,?,?)', [$date, $h['name'], (int) $h['optional'], $mov, $ok])->rowCount()) { $res['created']++; $res['unverified'] += $ok ? 0 : 1; } }
    LeaveService::flush();
    if ($res['created']) { Audit::log('hr.holiday.next_year', 'holiday_year', $year, $res, $uid); }
    return $res; }

  /** AI date check for a year's holidays: ['rows' => [[id, name, optional, old, new, changed]], 'error' => ?string]. */
  public static function fixPreview(int $year, ?int $uid): array {
    $rows = DB::all('SELECT id, name, holiday_date, optional FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name', ["$year-01-01", "$year-12-31"]);
    if (!$rows) { return ['rows' => [], 'error' => 'There are no holidays in '.$year.' to check.']; }
    $r = self::aiDates(array_column($rows, 'name'), $year, $uid);
    if ($r['error']) { return ['rows' => [], 'error' => $r['error']]; }
    $out = [];
    foreach ($rows as $h) {
      $new = $r['dates'][$h['name']] ?? null;
      $out[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'optional' => (int) $h['optional'], 'old' => $h['holiday_date'], 'new' => $new, 'changed' => $new !== null && $new !== $h['holiday_date']]; }
    return ['rows' => $out, 'error' => null]; }

  /** Apply chosen dates [holiday id => Y-m-d] inside a year; marks them verified. Returns rows changed. */
  public static function applyDates(array $map, int $year, ?int $uid): int {
    $n = 0;
    foreach ($map as $id => $date) {
      $date = (string) $date; $d = \DateTime::createFromFormat('!Y-m-d', $date);
      if (!$d || $d->format('Y-m-d') !== $date || (int) $d->format('Y') !== $year) { continue; }
      $h = DB::first('SELECT * FROM holidays WHERE id=? AND holiday_date BETWEEN ? AND ?', [(int) $id, "$year-01-01", "$year-12-31"]);
      if (!$h || DB::value('SELECT 1 FROM holidays WHERE holiday_date=? AND name=? AND id<>?', [$date, $h['name'], $h['id']])) { continue; }
      DB::update('holidays', ['holiday_date' => $date, 'verified' => 1], 'id=?', [$h['id']]);
      if ($date !== $h['holiday_date']) { $n++; Audit::log('hr.holiday.ai_fix', 'holiday', (int) $h['id'], ['name' => $h['name'], 'from' => $h['holiday_date'], 'to' => $date], $uid); } }
    LeaveService::flush();
    return $n; }

  /** Cron (daily, acts in December): create next year's list from this year's when it is still empty. */
  public static function cronNextYear(): string {
    if ((int) date('n') !== 12) { return 'Not December: nothing to do'; }
    if (!Settings::bool('hr.holidays.auto_next_year', true)) { return 'Automatic next-year holidays are switched off'; }
    $next = (int) date('Y') + 1;
    if (DB::value('SELECT 1 FROM holidays WHERE holiday_date BETWEEN ? AND ? LIMIT 1', ["$next-01-01", "$next-12-31"])) { return "$next holidays already exist"; }
    $r = self::createYear($next, true, null);
    return "Created {$r['created']} holiday(s) for $next ({$r['ai']} dated by AI, {$r['unverified']} to check)".($r['error'] ? '; AI: '.$r['error'] : ''); } }
