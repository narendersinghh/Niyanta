<?php
namespace Modules\Ai\Services;

/** Robust extraction & validation of the JSON the model returns (task lists, search filter specs). */
final class Json {
  /** Strip code fences / prose around the first JSON object or array and decode it. */
  public static function extract(string $text): mixed {
    $t = trim($text);
    if (preg_match('/```(?:json|JSON)?\s*(.*?)```/s', $t, $m)) { $t = trim($m[1]); }
    $d = json_decode($t, true);
    if ($d !== null || $t === 'null') { return $d; }
    $start = strcspn($t, '[{');
    if ($start >= strlen($t)) { return null; }
    $close = $t[$start] === '[' ? ']' : '}';
    $end = strrpos($t, $close);
    if ($end === false || $end <= $start) { return null; }
    $d = json_decode(substr($t, $start, $end - $start + 1), true);
    if ($d === null) { $d = json_decode(preg_replace('/,\s*([\]}])/', '$1', substr($t, $start, $end - $start + 1)) ?? '', true); }
    return $d; }

  private static function clean(mixed $v, int $max): string {
    if (!is_scalar($v)) { return ''; }
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags((string) $v)) ?? '');
    return mb_substr($s, 0, $max); }

  /** @return list<array{title:string,description:string,priority:string,due_in_days:?int}> */
  public static function tasks(string $text, int $limit = 20): array {
    $d = self::extract($text);
    if (is_array($d) && isset($d['tasks']) && is_array($d['tasks'])) { $d = $d['tasks']; }
    if (!is_array($d) || !array_is_list($d)) { throw new AiException('The AI did not return a usable task list. Try again or rephrase the text.', 'Unparseable task JSON: '.mb_substr($text, 0, 200)); }
    $map = ['critical' => 'urgent', 'highest' => 'urgent', 'normal' => 'medium', 'med' => 'medium', 'lowest' => 'low'];
    $out = [];
    foreach ($d as $t) {
      if (is_string($t)) { $t = ['title' => $t]; }
      if (!is_array($t)) { continue; }
      $title = self::clean($t['title'] ?? $t['name'] ?? '', 190);
      if (mb_strlen($title) < 2) { continue; }
      $p = strtolower(self::clean($t['priority'] ?? 'medium', 20));
      $p = $map[$p] ?? $p;
      $due = $t['due_in_days'] ?? null;
      $due = is_numeric($due) ? max(0, min(365, (int) $due)) : null;
      $out[] = ['title' => $title, 'description' => self::clean($t['description'] ?? '', 2000), 'priority' => in_array($p, ['low', 'medium', 'high', 'urgent'], true) ? $p : 'medium', 'due_in_days' => $due];
      if (count($out) >= $limit) { break; } }
    if (!$out) { throw new AiException('The AI did not find any tasks in that text.', 'Empty task list'); }
    return $out; }

  public const ENTITIES = ['clients', 'projects', 'tasks', 'invoices', 'employees'];
  public const STATUSES = [
    'clients' => ['lead', 'active', 'on_hold', 'inactive'],
    'projects' => ['planning', 'active', 'on_hold', 'completed', 'cancelled'],
    'tasks' => ['todo', 'in_progress', 'review', 'done'],
    'invoices' => ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'],
    'employees' => ['active', 'inactive']];

  /** Validate a natural-language filter spec against the whitelist. Unknown values are dropped, never passed through. */
  public static function filters(mixed $spec): array {
    if (is_string($spec)) { $spec = self::extract($spec); }
    if (!is_array($spec)) { throw new AiException('The AI could not interpret that question.', 'Unparseable filter spec'); }
    $syn = ['client' => 'clients', 'customer' => 'clients', 'customers' => 'clients', 'project' => 'projects', 'task' => 'tasks', 'todo' => 'tasks', 'todos' => 'tasks', 'invoice' => 'invoices', 'bill' => 'invoices', 'bills' => 'invoices', 'employee' => 'employees', 'staff' => 'employees', 'people' => 'employees', 'team' => 'employees', 'users' => 'employees'];
    $e = strtolower(self::clean($spec['entity'] ?? '', 30));
    $e = $syn[$e] ?? $e;
    if (!in_array($e, self::ENTITIES, true)) { throw new AiException('I can search clients, projects, tasks, invoices and employees. Try asking about one of those.', 'Invalid entity: '.$e); }
    $kw = $spec['keywords'] ?? '';
    if (is_array($kw)) { $kw = implode(' ', array_filter($kw, 'is_scalar')); }
    $kw = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}@.\-\s]/u', ' ', self::clean($kw, 100)) ?? '') ?? '');
    $st = strtolower(str_replace(' ', '_', self::clean($spec['status'] ?? '', 30)));
    $date = static function ($v): ?string { $v = is_string($v) ? trim($v) : ''; $d = \DateTime::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v ? $v : null; };
    $from = $date($spec['date_from'] ?? null); $to = $date($spec['date_to'] ?? null);
    if ($from && $to && $from > $to) { [$from, $to] = [$to, $from]; }
    $bool = static fn ($v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true';
    return ['entity' => $e, 'keywords' => $kw, 'status' => in_array($st, self::STATUSES[$e], true) ? $st : null, 'date_from' => $from, 'date_to' => $to, 'assignee_me' => $bool($spec['assignee_me'] ?? false), 'overdue' => $bool($spec['overdue'] ?? false)]; } }
