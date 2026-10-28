<?php
namespace Modules\Projects\Services;
use Core\{DB, View};

/** Dashboard widgets (all scoped to the user's visible projects). */
final class Widgets {
  private static ?array $counts = null;
  private static bool $css = false;

  /** The dashboard layout only loads app.css; emit the module stylesheet once (body <link> is valid HTML5). */
  private static function css(): string {
    if (self::$css) { return ''; }
    self::$css = true;
    return '<link rel="stylesheet" href="'.e(asset('css/projects.css')).'">'; }

  /** One query for the three task KPIs of the current user. */
  private static function counts(array $u): array {
    if (self::$counts !== null) { return self::$counts; }
    [$w, $p] = ProjectAccess::scope($u, 't.project_id');
    $today = date('Y-m-d'); $sun = date('Y-m-d', strtotime('sunday this week'));
    $r = DB::first("SELECT COUNT(*) AS open, IFNULL(SUM(t.due_date<=?),0) AS week, IFNULL(SUM(t.due_date<?),0) AS late FROM tasks t WHERE $w AND t.assignee_id=? AND t.status<>'done'", [$sun, $today, ...$p, (int) $u['id']]);
    return self::$counts = array_map('intval', $r); }

  private static function tile(string $color, string $icon, int $num, string $label, string $url, string $sub = ''): string {
    return '<a class="tile bg-'.$color.'" href="'.e(url($url)).'"><span class="tile-ic">'.icon($icon).'</span><div><div class="num">'.$num.'</div><div class="lbl">'.e($label).($sub ? ' <span class="tile-sub">'.e($sub).'</span>' : '').'</div></div></a>'; }

  public static function openTasks(array $u): string { return self::tile('lav', 'check-square', self::counts($u)['open'], 'Open tasks', '/tasks'); }

  public static function dueThisWeek(array $u): string {
    $c = self::counts($u);
    return self::tile('lemon', 'calendar', $c['week'], 'Due this week', '/tasks', $c['late'] ? '· '.$c['late'].' overdue' : ''); }

  public static function activeProjects(array $u): string {
    [$w, $p] = ProjectAccess::scope($u, 'p.id');
    return self::tile('mint', 'folder', (int) DB::value("SELECT COUNT(*) FROM projects p WHERE $w AND p.status='active'", $p), 'Active projects', '/projects?status=active'); }

  public static function myTasks(array $u): string {
    [$w, $p] = ProjectAccess::scope($u, 'p.id');
    $tasks = DB::all("SELECT t.id, t.title, t.status, t.priority, t.due_date, p.name AS project, p.color FROM tasks t JOIN projects p ON p.id=t.project_id WHERE $w AND t.assignee_id=? AND t.status<>'done' ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority,'urgent','high','medium','low') LIMIT 6", [...$p, (int) $u['id']]);
    return View::partial('projects::widgets/my_tasks', ['tasks' => $tasks, 'counts' => self::counts($u), 'css' => self::css()]); }

  public static function projectProgress(array $u): string {
    [$w, $p] = ProjectAccess::scope($u, 'p.id');
    $projects = DB::all("SELECT p.id, p.name, p.color, p.progress, p.deadline, p.status, c.name AS client FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE $w AND p.status IN ('active','planning','on_hold') ORDER BY p.deadline IS NULL, p.deadline LIMIT 5", $p);
    return View::partial('projects::widgets/progress', ['projects' => $projects, 'css' => self::css()]); } }
