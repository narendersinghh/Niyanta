<?php
namespace Modules\Ai\Services;
use Core\DB;

/**
 * Who the current user may ask about, and name/email resolution strictly inside that set.
 * HR and Super Admins: everyone. Managers: themselves, direct reports and members of projects they manage
 * (the Attendance team rule). Everyone else: only themselves.
 */
final class People {
  private static array $memo = [];

  public static function isHr(array $u): bool { return Scope::isSuper($u) || ($u['role_slug'] ?? '') === 'hr' || Scope::can('hr.employees.view', $u); }

  /** Person ids visible to the user (always including self), or null for everyone. */
  public static function scope(array $u): ?array {
    if (self::isHr($u)) { return null; }
    $uid = (int) $u['id'];
    return self::$memo[$uid] ??= (function () use ($u, $uid) {
      $manager = Scope::can('attendance.view_all', $u) || Scope::can('hr.leave.approve', $u) || Scope::can('projects.reports.approve', $u) || Scope::can('attendance.timesheets.approve', $u);
      if (!$manager) { return [$uid]; }
      $ids = class_exists('Modules\\Attendance\\Services\\Team') ? \Modules\Attendance\Services\Team::memberIds($u) : array_map('intval', DB::column("SELECT id FROM users WHERE manager_id=? AND status='active'", [$uid]));
      return $ids === null ? null : array_values(array_unique([$uid, ...array_map('intval', $ids)])); })(); }

  public static function inScope(array $u, int $id): bool { $s = self::scope($u); return $s === null || in_array($id, $s, true); }

  /** Direct manager relation (users.manager_id). */
  public static function isDirectReport(array $u, int $id): bool { return (int) DB::value('SELECT manager_id FROM users WHERE id=?', [$id]) === (int) $u['id']; }

  /**
   * Resolve a person reference ("me", a name, first name, possessive or email) within $ids (null = everyone).
   * Returns ['person' => row] | ['error' => text] | ['candidates' => [...]]. Unknown and not-visible look the same.
   */
  public static function resolve(array $u, mixed $ref, ?array $ids): array {
    $q = trim(preg_replace("/['’]s$/u", '', trim(is_scalar($ref) ? (string) $ref : '')) ?? '');
    $self = DB::first('SELECT id, name, email, designation, department, manager_id, status FROM users WHERE id=?', [(int) $u['id']]);
    if ($q === '' || in_array(mb_strtolower($q), ['me', 'myself', 'i', 'mine', 'self', 'my', 'own'], true) || mb_strtolower($q) === mb_strtolower((string) $self['name']) || mb_strtolower($q) === mb_strtolower((string) $self['email'])) { return ['person' => $self]; }
    $q = mb_substr($q, 0, 80);
    $where = "status<>'invited'"; $p = [];
    if ($ids !== null) { if (!$ids) { return ['error' => 'not_found']; } $where .= ' AND id IN '.DB::in($ids); array_push($p, ...$ids); }
    $base = "SELECT id, name, email, designation, department, manager_id, status FROM users WHERE $where";
    if (filter_var($q, FILTER_VALIDATE_EMAIL)) { $r = DB::first($base.' AND email=?', [...$p, mb_strtolower($q)]); return $r ? ['person' => $r] : ['error' => 'not_found']; }
    $words = array_slice(array_values(array_filter(preg_split('/\s+/u', $q) ?: [], static fn ($w) => mb_strlen($w) >= 2)), 0, 4);
    if (!$words) { return ['error' => 'not_found']; }
    $sql = $base; $pp = $p;
    foreach ($words as $w) { $sql .= ' AND (name LIKE ? OR name LIKE ? OR email LIKE ?)'; array_push($pp, DB::like($w).'%', '% '.DB::like($w).'%', DB::like($w).'%@%'); }
    $rows = DB::all($sql." ORDER BY status='active' DESC, name LIMIT 8", $pp);
    if (!$rows) {
      // Typo tolerance among visible people only.
      $all = DB::all($base." AND status='active' ORDER BY name LIMIT 600", $p);
      $best = []; $first = mb_strtolower($words[0]);
      foreach ($all as $r) { foreach (preg_split('/\s+/', mb_strtolower($r['name'])) ?: [] as $part) { $d = levenshtein($first, $part); if ($d <= (mb_strlen($first) > 5 ? 2 : 1)) { $best[$r['id']] = $r; } } }
      $rows = array_values($best); }
    if (!$rows) { return ['error' => 'not_found']; }
    if (count($rows) > 1) {
      $exact = array_values(array_filter($rows, static fn ($r) => mb_strtolower($r['name']) === mb_strtolower($q)));
      if (count($exact) === 1) { return ['person' => $exact[0]]; }
      $active = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'active'));
      if (count($active) === 1) { return ['person' => $active[0]]; }
      return ['candidates' => array_map(static fn ($r) => ['name' => $r['name'], 'designation' => $r['designation'], 'department' => $r['department']], array_slice($rows, 0, 6))]; }
    return ['person' => $rows[0]]; }

  /** Names of people in scope (for the system prompt), capped. */
  public static function names(array $u, int $max = 40): array {
    $s = self::scope($u);
    if ($s === null) { return []; }
    return DB::column("SELECT name FROM users WHERE status='active' AND id<>? AND id IN ".DB::in($s ?: [0]).' ORDER BY name LIMIT '.$max, [(int) $u['id'], ...($s ?: [0])]); }

  public static function flush(): void { self::$memo = []; } }
