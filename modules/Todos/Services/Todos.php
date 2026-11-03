<?php
namespace Modules\Todos\Services;
use Core\{DB, Gate, Settings, View};

/**
 * Tasks & routines: record-level access, recurrence maths and occurrence generation.
 * A routine (todo_series) produces one `todos` row per occurrence day (due_date = occurrence), created by the daily
 * cron job and right away when a routine is created, edited or resumed. UNIQUE(series_id, occurrence) keeps it idempotent.
 */
final class Todos {
  public const PRIORITIES = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
  public const PRIO_PILL = ['high' => 'peach', 'medium' => 'lemon', 'low' => 'sky'];
  public const FREQ = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
  public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
  /** The generator never goes further back than this many days (missed cron runs). */
  public const CATCH_UP_DAYS = 7;
  /** Columns of a todo row joined with its creator, assignee and routine (alias t, c, a, s). */
  public const ROW_SQL = 'SELECT t.id, t.title, t.description, t.priority, t.assignee_id, t.created_by, t.due_date, t.status, t.completed_at, t.series_id, t.occurrence, c.name AS creator, a.name AS assignee, a.avatar AS assignee_avatar, s.frequency, s.workdays_only, s.weekdays, s.month_day FROM todos t JOIN users c ON c.id=t.created_by JOIN users a ON a.id=t.assignee_id LEFT JOIN todo_series s ON s.id=t.series_id';

  private static ?array $weekend = null;
  private static bool $css = false;

  /** Company weekly off days (ISO-N), setting general.weekend_days. */
  public static function weekend(): array { return self::$weekend ??= self::days((string) Settings::get('general.weekend_days', '6,7')); }
  public static function flush(): void { self::$weekend = null; }

  /** "3,1,5" or [3,1,5] => [1,3,5] (valid ISO weekdays only). */
  public static function days(string|array|null $v): array {
    $d = array_unique(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string) $v)), static fn ($x) => $x >= 1 && $x <= 7));
    sort($d);
    return array_values($d); }

  private static function next(string $d, int $n = 1): string { return date('Y-m-d', strtotime($d.' '.($n >= 0 ? '+' : '').$n.' day')); }

  /** Whether a routine has an occurrence on $date (Y-m-d). Pure: needs only the series row (and the weekly off days for "weekdays only"). */
  public static function occursOn(array $s, string $date, ?array $weekend = null): bool {
    if ($date < $s['start_date'] || (!empty($s['end_date']) && $date > $s['end_date'])) { return false; }
    $ts = strtotime($date);
    $dow = (int) date('N', $ts);
    return match ($s['frequency']) {
      'daily' => empty($s['workdays_only']) || !in_array($dow, $weekend ?? self::weekend(), true),
      'weekly' => in_array($dow, self::days($s['weekdays'] ?? ''), true),
      'monthly' => (int) date('j', $ts) === min(max(1, (int) $s['month_day']), (int) date('t', $ts)),
      default => false }; }

  /** First occurrence on or after $from (null when none within ~2 months or the routine has ended). */
  public static function nextOccurrence(array $s, ?string $from = null, ?array $weekend = null): ?string {
    $d = max($from ?? date('Y-m-d'), $s['start_date']);
    for ($i = 0; $i < 62; $i++, $d = self::next($d)) {
      if (!empty($s['end_date']) && $d > $s['end_date']) { return null; }
      if (self::occursOn($s, $d, $weekend)) { return $d; } }
    return null; }

  /** active | paused | ended */
  public static function state(array $s, ?string $today = null): string {
    $today ??= date('Y-m-d');
    if (!empty($s['end_date']) && $s['end_date'] < $today) { return 'ended'; }
    return (int) $s['active'] ? 'active' : 'paused'; }

  /** Human label: "Every weekday", "Mon, Wed", "Monthly on 15". */
  public static function repeatLabel(array $s): string {
    if ($s['frequency'] === 'daily') {
      if (empty($s['workdays_only'])) { return 'Every day'; }
      return self::weekend() === [6, 7] ? 'Every weekday' : 'Every working day'; }
    if ($s['frequency'] === 'weekly') {
      $d = self::days($s['weekdays'] ?? '');
      if (count($d) === 7) { return 'Every day'; }
      if ($d === [1, 2, 3, 4, 5]) { return 'Every weekday'; }
      if (count($d) === 1) { return 'Weekly on '.self::DAYS[$d[0]]; }
      return implode(', ', array_map(static fn ($x) => self::DAYS[$x], $d)); }
    $m = (int) $s['month_day'];
    return 'Monthly on '.$m.($m > 28 ? ' (or last day)' : ''); }

  /** Create the routine's occurrences between $from and $to (inclusive). Idempotent. Returns the number of rows created. */
  public static function generate(array $s, string $from, string $to): int {
    $n = 0; $now = date('Y-m-d H:i:s'); $wk = self::weekend();
    for ($d = max($from, $s['start_date']); $d <= $to; $d = self::next($d)) {
      if (!empty($s['end_date']) && $d > $s['end_date']) { break; }
      if (!self::occursOn($s, $d, $wk)) { continue; }
      $n += DB::query("INSERT IGNORE INTO todos (title, description, priority, assignee_id, created_by, due_date, status, series_id, occurrence, created_at, updated_at) VALUES (?,?,?,?,?,?,'open',?,?,?,?)",
        [$s['title'], $s['description'], $s['priority'], (int) $s['assignee_id'], (int) $s['created_by'], $d, (int) $s['id'], $d, $now, $now])->rowCount(); }
    return $n; }

  /** After create / edit / resume: today's occurrence right away, no back-fill of earlier days. */
  public static function sync(int $id, ?string $today = null): int {
    $today ??= date('Y-m-d');
    $s = DB::first('SELECT * FROM todo_series WHERE id=?', [$id]);
    if (!$s || !(int) $s['active']) { return 0; }
    $n = self::generate($s, $today, $today);
    if (!$s['last_generated'] || $s['last_generated'] < $today) { DB::update('todo_series', ['last_generated' => $today], 'id=?', [$id]); }
    return $n; }

  /** Cron (daily): occurrences of every active routine up to today, catching up missed days (at most CATCH_UP_DAYS back). */
  public static function generateDue(?string $today = null): string {
    $today ??= date('Y-m-d');
    $floor = self::next($today, -self::CATCH_UP_DAYS);
    $series = DB::all('SELECT * FROM todo_series WHERE active=1 AND start_date<=? AND (end_date IS NULL OR end_date>=?) AND (last_generated IS NULL OR last_generated<?)', [$today, $floor, $today]);
    $n = 0;
    foreach ($series as $s) {
      $from = max($floor, $s['last_generated'] ? self::next($s['last_generated']) : $s['start_date']);
      $n += self::generate($s, $from, $today);
      DB::update('todo_series', ['last_generated' => $today], 'id=?', [(int) $s['id']]); }
    return 'created '.$n.' from '.count($series).' routines'; }

  /* ---------- access ---------- */

  public static function canCreate(?array $u = null): bool { return Gate::any(['todos.use', 'todos.assign', 'todos.assign_all'], $u); }
  public static function canAssign(?array $u = null): bool { return Gate::any(['todos.assign', 'todos.assign_all'], $u); }

  /** SQL condition + params for todos / todo_series rows (alias $a) the user may see. */
  public static function scope(array $u, string $a = 't'): array {
    if (Gate::allows('todos.assign_all', $u)) { return ['1=1', []]; }
    $id = (int) $u['id'];
    $sql = "($a.assignee_id=? OR $a.created_by=?"; $p = [$id, $id];
    if (Gate::allows('todos.assign', $u)) { $sql .= " OR $a.assignee_id IN (SELECT id FROM users WHERE manager_id=?)"; $p[] = $id; }
    return [$sql.')', $p]; }

  private static function reportsTo(int $uid, int $mgr): bool { return (bool) DB::value('SELECT 1 FROM users WHERE id=? AND manager_id=?', [$uid, $mgr]); }

  /** May edit / delete the row (todo or routine): its creator, HR-wide assigners, or the assignee's manager holding todos.assign. */
  public static function canManage(array $u, array $row): bool {
    if ((int) $row['created_by'] === (int) $u['id'] || Gate::allows('todos.assign_all', $u)) { return true; }
    return Gate::allows('todos.assign', $u) && self::reportsTo((int) $row['assignee_id'], (int) $u['id']); }

  /** May see (and tick) the row: the assignee or anyone who may manage it. */
  public static function canSee(array $u, array $row): bool { return (int) $row['assignee_id'] === (int) $u['id'] || self::canManage($u, $row); }

  /** People the user may assign to: [id => name], "Me" first. Only active users whose role can use tasks. */
  public static function assignable(array $u): array {
    $id = (int) $u['id'];
    $me = [$id => 'Me'];
    $all = Gate::allows('todos.assign_all', $u);
    if (!$all && !Gate::allows('todos.assign', $u)) { return $me; }
    $sql = "SELECT u.id, u.name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN role_permissions rp ON rp.role_id=r.id AND rp.permission='todos.use' WHERE u.status='active' AND u.id<>? AND (r.slug='super_admin' OR rp.permission IS NOT NULL)".($all ? '' : ' AND u.manager_id=?').' ORDER BY u.name';
    return $me + DB::pairs($sql, $all ? [$id] : [$id, $id]); }

  /* ---------- presentation helpers ---------- */

  /** [label, tone] for a due date. */
  public static function due(?string $d, string $status = 'open'): array {
    if (!$d) { return ['', 'muted']; }
    $today = date('Y-m-d');
    $label = match ($d) { $today => 'Today', self::next($today) => 'Tomorrow', self::next($today, -1) => 'Yesterday', default => fdate($d) };
    return [$label, $status === 'open' && $d < $today ? 'late' : ($d === $today ? 'today' : 'muted')]; }

  /** Group open tasks: overdue, today, upcoming, none. */
  public static function groups(array $rows): array {
    $today = date('Y-m-d');
    $g = ['overdue' => [], 'today' => [], 'upcoming' => [], 'none' => []];
    foreach ($rows as $r) { $g[!$r['due_date'] ? 'none' : ($r['due_date'] < $today ? 'overdue' : ($r['due_date'] === $today ? 'today' : 'upcoming'))][] = $r; }
    return $g; }

  /** Stylesheet link for the dashboard widget (emitted once). */
  public static function css(): string {
    if (self::$css) { return ''; }
    self::$css = true;
    return '<link rel="stylesheet" href="'.e(asset('css/todos.css')).'">'; }

  /* ---------- hooks ---------- */

  /** Dashboard: my open tasks due today or overdue (two queries). */
  public static function widget(array $u): string {
    $uid = (int) $u['id']; $today = date('Y-m-d');
    $rows = DB::all(self::ROW_SQL." WHERE t.assignee_id=? AND t.status='open' AND t.due_date<=? ORDER BY t.due_date, FIELD(t.priority,'high','medium','low'), t.id LIMIT 5", [$uid, $today]);
    $total = count($rows) < 5 ? count($rows) : (int) DB::value("SELECT COUNT(*) FROM todos WHERE assignee_id=? AND status='open' AND due_date<=?", [$uid, $today]);
    return View::partial('todos::widgets/today', ['rows' => $rows, 'total' => $total, 'uid' => $uid, 'css' => self::css()]); }

  /** Global search: visible tasks by title. */
  public static function search(string $q, array $u): array {
    if (!feature_on('todos')) { return []; }
    [$w, $p] = self::scope($u);
    $rows = DB::all("SELECT t.id, t.title, t.status, t.due_date, t.assignee_id, t.created_by, a.name AS assignee FROM todos t JOIN users a ON a.id=t.assignee_id WHERE $w AND t.title LIKE ? ORDER BY t.status='done', t.due_date IS NULL, t.due_date DESC, t.id DESC LIMIT 8", [...$p, DB::like($q)]);
    $me = (int) $u['id'];
    return ['Tasks & routines' => array_map(static fn ($r) => ['title' => $r['title'], 'meta' => trim(((int) $r['assignee_id'] === $me ? 'Mine' : $r['assignee']).' · '.($r['status'] === 'done' ? 'Done' : 'Open').($r['due_date'] ? ' · due '.fdate($r['due_date']) : '')),
      'url' => '/todos'.((int) $r['assignee_id'] === $me ? '' : '?view=assigned&who='.((int) $r['created_by'] === $me ? 'mine' : 'team').'&status=all').'#todo-'.$r['id'], 'icon' => 'check-square'], $rows)]; } }
