<?php
namespace Modules\Projects\Services;
use Core\{Audit, DB, Html, Storage};

/** Project brief: one sanitized rich text document per project with the last KEEP revisions (History / Restore). */
final class ProjectBrief {
  public const KEEP = 20;

  public static function get(int $pid): ?array {
    return DB::first('SELECT b.*, u.name AS updated_name, (SELECT MAX(r.id) FROM project_brief_revisions r WHERE r.project_id=b.project_id) AS version FROM project_briefs b LEFT JOIN users u ON u.id=b.updated_by WHERE b.project_id=?', [$pid]); }

  /** Images and file links in a brief may only point at this project's own files. */
  public static function fileGuard(int $pid): callable {
    $ok = [];
    return static function (int $fid) use ($pid, &$ok): bool {
      return $ok[$fid] ??= (bool) DB::value("SELECT 1 FROM files WHERE id=? AND module='projects' AND entity_type='project' AND entity_id=?", [$fid, $pid]); }; }

  public static function clean(int $pid, string $html): string { return Html::clean($html, self::fileGuard($pid)); }

  /**
   * Sanitize and store a new version. Returns ['changed' => bool, 'version' => int|null]. Identical content adds no revision.
   * $action is 'save' or 'restore' ($from = the restored revision id).
   */
  public static function save(int $pid, string $html, int $uid, string $action = 'save', ?int $from = null): array {
    $clean = self::clean($pid, $html);
    $cur = DB::first('SELECT body_html FROM project_briefs WHERE project_id=?', [$pid]);
    if ($cur && $cur['body_html'] === $clean) { return ['changed' => false, 'version' => self::get($pid)['version'] ?? null, 'html' => $clean]; }
    $now = date('Y-m-d H:i:s');
    $rev = DB::transaction(static function () use ($pid, $clean, $uid, $action, $from, $now, $cur): int {
      if ($cur) { DB::update('project_briefs', ['body_html' => $clean, 'updated_by' => $uid, 'updated_at' => $now], 'project_id=?', [$pid]); }
      else { DB::insert('project_briefs', ['project_id' => $pid, 'body_html' => $clean, 'updated_by' => $uid, 'updated_at' => $now]); }
      $rev = DB::insert('project_brief_revisions', ['project_id' => $pid, 'body_html' => $clean, 'user_id' => $uid, 'action' => $action, 'restored_from' => $from, 'created_at' => $now]);
      $keep = DB::column('SELECT id FROM project_brief_revisions WHERE project_id=? ORDER BY id DESC LIMIT '.self::KEEP, [$pid]);
      DB::query('DELETE FROM project_brief_revisions WHERE project_id=? AND id NOT IN '.DB::in($keep), [$pid, ...$keep]);
      return $rev; });
    Audit::log('projects.brief.'.($action === 'restore' ? 'restore' : 'save'), 'project', $pid, ['revision' => $rev, 'bytes' => strlen($clean)] + ($from ? ['restored_from' => $from] : []), $uid);
    return ['changed' => true, 'version' => $rev, 'html' => $clean]; }

  public static function revisions(int $pid): array {
    return DB::all('SELECT r.id, r.user_id, r.action, r.restored_from, r.created_at, LENGTH(r.body_html) AS bytes, u.name AS user_name FROM project_brief_revisions r LEFT JOIN users u ON u.id=r.user_id WHERE r.project_id=? ORDER BY r.id DESC LIMIT '.self::KEEP, [$pid]); }

  public static function revision(int $pid, int $rid): ?array {
    return DB::first('SELECT r.*, u.name AS user_name FROM project_brief_revisions r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.project_id=?', [$rid, $pid]); }

  /** Starting content for an empty brief: the project's description, scope and deliverables as headings and paragraphs / a list. */
  public static function prefill(array $pr): string {
    $h = '';
    if (trim((string) ($pr['description'] ?? '')) !== '') { $h .= '<h2>Overview</h2>'.Html::paragraphs((string) $pr['description']); }
    if (trim((string) ($pr['scope'] ?? '')) !== '') { $h .= '<h2>Scope</h2>'.self::listOrText((string) $pr['scope']); }
    if (trim((string) ($pr['deliverables'] ?? '')) !== '') { $h .= '<h2>Deliverables</h2>'.self::listOrText((string) $pr['deliverables'], true); }
    return $h; }

  /** Lines starting with -, *, • or "1." (or every line, when $list) become a bulleted list; other text stays paragraphs. */
  private static function listOrText(string $text, bool $list = false): string {
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', trim($text)) ?: []), static fn ($l) => $l !== ''));
    $bullet = '/^(?:[-*•\x{2013}]|\d{1,3}[.)])\s+/u';
    if (!$list && count(array_filter($lines, static fn ($l) => preg_match($bullet, $l))) < max(2, (int) ceil(count($lines) / 2))) { return Html::paragraphs($text); }
    return '<ul>'.implode('', array_map(static fn ($l) => '<li>'.e(preg_replace($bullet, '', $l)).'</li>', $lines)).'</ul>'; }

  /** Data for the Brief tab: mode read | edit | rev, the document, history and the project's files. */
  public static function tab(array $pr, \Core\Request $req, bool $canEdit): array {
    $pid = (int) $pr['id'];
    $brief = self::get($pid);
    $rev = ($r = $req->int('rev')) > 0 ? self::revision($pid, $r) : null;
    $mode = $rev ? 'rev' : ($canEdit && $req->str('mode') === 'edit' ? 'edit' : 'read');
    $prefill = $brief ? '' : self::prefill($pr);
    return ['brief' => $brief, 'briefMode' => $mode, 'briefRev' => $rev, 'briefPrefill' => $prefill, 'briefRevisions' => self::revisions($pid), 'files' => Storage::files('project', $pid)]; } }
