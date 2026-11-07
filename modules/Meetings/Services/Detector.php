<?php
namespace Modules\Meetings\Services;
use Core\{DB, Settings};
use Modules\Crm\Services\ClientAccess;
use Modules\Projects\Services\ProjectAccess;

/**
 * Which client and project a meeting belongs to, shared by every source (upload preview, notes inbox, Fireflies).
 * Order: explicit target (plus-address, project page, user's choice) > participant e-mails that are client contacts or on a client's
 * own domain (generic mail domains ignored) > client / project names in the title, then the text (prefers the client's active projects)
 * > the uploader's or sender's own projects. Only clients and projects the acting user may see are considered (null user = all).
 * Returns ['client_id','project_id','confidence' => high|medium|low|none,'reasons' => [..],'client_name','project_name'].
 */
final class Detector {
  public const GENERIC = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.co.uk', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com',
    'zoho.com', 'zohomail.in', 'yandex.com', 'gmx.com', 'gmx.net', 'mail.com', 'rediffmail.com', 'fastmail.com', 'hey.com', 'qq.com', '163.com', 'web.de', 'google.com', 'zoom.us', 'fireflies.ai', 'otter.ai', 'tldv.io', 'tactiq.io', 'read.ai', 'microsoft.com'];
  private const ACTIVE = ['active', 'planning', 'on_hold'];

  public static function detect(array $ctx): array {
    $u = $ctx['user'] ?? null;
    $clients = self::clients($u); $projects = self::projects($u);
    $out = ['client_id' => null, 'project_id' => null, 'confidence' => 'none', 'reasons' => []];
    $title = (string) ($ctx['title'] ?? ''); $text = mb_substr((string) ($ctx['text'] ?? ''), 0, 50000);
    /* 1. explicit target */
    $pid = (int) ($ctx['project_id'] ?? 0); $cid = (int) ($ctx['client_id'] ?? 0);
    if ($pid && isset($projects[$pid])) {
      $p = $projects[$pid];
      return self::finish(['client_id' => $p['client_id'], 'project_id' => $pid, 'confidence' => 'high', 'reasons' => [$ctx['explicit_reason'] ?? 'Logged from project '.$p['name']]], $clients, $projects); }
    if ($cid && isset($clients[$cid])) {
      $out = ['client_id' => $cid, 'project_id' => null, 'confidence' => 'high', 'reasons' => [$ctx['explicit_reason'] ?? 'Chosen client '.$clients[$cid]['label']]];
      [$p, $why] = self::pickProject($cid, $title, $text, $projects, $u);
      if ($p) { $out['project_id'] = $p; $out['reasons'][] = $why; }
      return self::finish($out, $clients, $projects); }
    /* 2. participants */
    $own = self::ownDomains();
    $emails = array_values(array_unique(array_filter(array_map(static fn ($e) => strtolower(trim((string) $e)), $ctx['emails'] ?? []), static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array(self::domain($e), $own, true))));
    $score = []; $why = []; $strong = [];
    if ($emails && $clients) {
      $ids = array_keys($clients);
      $contacts = DB::all('SELECT LOWER(email) AS email, client_id FROM client_contacts WHERE email IS NOT NULL AND email<>\'\' AND LOWER(email) IN '.DB::in($emails).' AND client_id IN '.DB::in($ids), [...$emails, ...$ids]);
      foreach ($clients as $id => $c) { if ($c['email'] && in_array(strtolower($c['email']), $emails, true)) { $contacts[] = ['email' => strtolower($c['email']), 'client_id' => $id]; } }
      foreach ($contacts as $r) { $id = (int) $r['client_id']; if (!isset($why[$id]['contact'])) { $score[$id] = ($score[$id] ?? 0) + 100; $why[$id]['contact'] = 'Participant '.$r['email'].' is a contact of '.$clients[$id]['label']; $strong[$id] = true; } }
      if (!$contacts) {
        $doms = array_values(array_unique(array_filter(array_map([self::class, 'domain'], $emails), static fn ($d) => $d && !in_array($d, self::GENERIC, true))));
        $map = $doms ? self::domainMap($doms, $ids) : [];
        foreach ($map as $d => $cids) {
          $cids = array_values(array_unique($cids));
          foreach ($cids as $id) { if (!isset($why[$id]['domain'])) { $score[$id] = ($score[$id] ?? 0) + (count($cids) === 1 ? 70 : 30); $why[$id]['domain'] = 'Participant from '.$d.', the domain of '.$clients[$id]['label']; if (count($cids) === 1) { $strong[$id] = true; } } } } } }
    /* 3. names in the title, then the text */
    $pm = [];
    foreach ($projects as $id => $p) {
      if (mb_strlen($p['name']) < 3) { continue; }
      if (self::mentions($title, $p['name'])) { $pm[$id] = [80 + (in_array($p['status'], self::ACTIVE, true) ? 10 : 0), 'Title mentions project '.$p['name']]; }
      elseif (self::mentions($text, $p['name'])) { $pm[$id] = [25 + (in_array($p['status'], self::ACTIVE, true) ? 5 : 0), 'Notes mention project '.$p['name']]; } }
    foreach ($clients as $id => $c) {
      foreach (array_unique(array_filter([$c['company'], $c['company'] ? null : $c['name']])) as $n) {
        if (mb_strlen($n) < 3) { continue; }
        if (self::mentions($title, $n)) { $score[$id] = ($score[$id] ?? 0) + 50; $why[$id]['title'] = 'Title mentions '.$c['label']; break; }
        if (self::mentions($text, $n)) { $score[$id] = ($score[$id] ?? 0) + 20; $why[$id]['text'] = 'Notes mention '.$c['label']; break; } } }
    foreach ($pm as $id => [$s]) { if ($projects[$id]['client_id'] && isset($clients[$projects[$id]['client_id']])) { $score[$projects[$id]['client_id']] = ($score[$projects[$id]['client_id']] ?? 0) + (int) ($s / 2); } }
    arsort($score);
    $best = $score ? array_key_first($score) : null;
    $tie = $best !== null && count($score) > 1 && array_values($score)[1] === $score[$best];
    if ($best !== null && !$tie) {
      $out['client_id'] = $best; $out['reasons'] = array_values($why[$best] ?? []);
      $cand = array_filter($pm, static fn ($id) => (int) $projects[$id]['client_id'] === $best, ARRAY_FILTER_USE_KEY);
      if ($cand) { uasort($cand, static fn ($a, $b) => $b[0] <=> $a[0]); $p = array_key_first($cand); $out['project_id'] = $p; $out['reasons'][] = $cand[$p][1]; $titled = $cand[$p][0] >= 80; }
      else { [$p, $pwhy, $sure] = self::pickProject($best, '', '', $projects, $u); if ($p) { $out['project_id'] = $p; $out['reasons'][] = $pwhy; } $titled = $sure; }
      $strongC = !empty($strong[$best]); $projTitle = $out['project_id'] && ($cand[$out['project_id']][0] ?? 0) >= 80;
      $out['confidence'] = $strongC && $titled ? 'high' : ($strongC || isset($why[$best]['title']) || $projTitle ? 'medium' : 'low');
      return self::finish($out, $clients, $projects); }
    /* project named without a clear client (internal project, or tie between clients) */
    if ($pm) {
      uasort($pm, static fn ($a, $b) => $b[0] <=> $a[0]);
      $p = array_key_first($pm);
      $out = ['client_id' => $projects[$p]['client_id'], 'project_id' => $p, 'confidence' => $pm[$p][0] >= 80 ? 'medium' : 'low', 'reasons' => [$pm[$p][1]]];
      return self::finish($out, $clients, $projects); }
    if ($tie) { $out['reasons'][] = 'Several clients match, pick one'; }
    /* 4. the uploader's / sender's own active projects */
    $mine = $u ? self::mine((int) $u['id'], $projects) : [];
    if (count($mine) === 1) { $p = $mine[0]; $out = ['client_id' => $projects[$p]['client_id'], 'project_id' => $p, 'confidence' => 'low', 'reasons' => ['Your only active project is '.$projects[$p]['name']]]; }
    return self::finish($out, $clients, $projects); }

  /** Project of a known client: named in the title / text, else its only active project. [id, reason, sure] */
  private static function pickProject(int $cid, string $title, string $text, array $projects, ?array $u): array {
    $own = array_filter($projects, static fn ($p) => (int) $p['client_id'] === $cid);
    foreach ([$title, $text] as $i => $t) { if ($t === '') { continue; } foreach ($own as $id => $p) { if (mb_strlen($p['name']) >= 3 && self::mentions($t, $p['name'])) { return [$id, ($i ? 'Notes mention' : 'Title mentions').' project '.$p['name'], $i === 0]; } } }
    $active = array_keys(array_filter($own, static fn ($p) => in_array($p['status'], self::ACTIVE, true)));
    if (count($active) === 1) { return [$active[0], 'Only active project of this client: '.$own[$active[0]]['name'], true]; }
    if (!$active) { return [null, '', true]; }
    if ($u && count($m = array_values(array_intersect($active, self::mine((int) $u['id'], $projects)))) === 1) { return [$m[0], 'Your project with this client: '.$own[$m[0]]['name'], false]; }
    return [null, '', false]; }

  private static function finish(array $o, array $clients, array $projects): array {
    $o['client_name'] = $o['client_id'] ? ($clients[$o['client_id']]['label'] ?? null) : null;
    $o['project_name'] = $o['project_id'] ? ($projects[$o['project_id']]['name'] ?? null) : null;
    $o['reasons'] = array_values(array_filter(array_unique($o['reasons'])));
    return $o; }

  /** Whole-word, case-insensitive mention. */
  public static function mentions(string $hay, string $name): bool {
    if ($hay === '' || mb_stripos($hay, $name) === false) { return false; }
    return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $hay); }

  public static function domain(string $email): string { return strtolower(substr(strrchr($email, '@') ?: '', 1)); }

  /** Our own domains (team members, the notes inbox): never evidence for a client. */
  public static function ownDomains(): array {
    static $d = null;
    if ($d !== null && !defined('NY_TESTING')) { return $d; }
    $list = DB::column("SELECT DISTINCT LOWER(SUBSTRING_INDEX(email, '@', -1)) FROM users WHERE status='active'");
    if ($in = (string) Settings::get('meetings.inbox_address', '')) { $list[] = self::domain($in); }
    return $d = array_values(array_diff(array_unique(array_filter($list)), self::GENERIC)); }

  /** domain => client ids, from client e-mails, contact e-mails and websites. */
  private static function domainMap(array $doms, array $ids): array {
    $map = [];
    $in = DB::in($ids);
    foreach (DB::all('SELECT id, email, website FROM clients WHERE id IN '.$in, $ids) as $c) {
      foreach ([$c['email'] ? self::domain($c['email']) : null, $c['website'] ? preg_replace('/^www\./', '', strtolower((string) parse_url((string) $c['website'], PHP_URL_HOST))) : null] as $d) { if ($d && in_array($d, $doms, true)) { $map[$d][] = (int) $c['id']; } } }
    foreach (DB::all("SELECT client_id, LOWER(SUBSTRING_INDEX(email, '@', -1)) AS d FROM client_contacts WHERE email LIKE '%@%' AND client_id IN ".$in, $ids) as $r) { if (in_array($r['d'], $doms, true)) { $map[$r['d']][] = (int) $r['client_id']; } }
    return $map; }

  /** Visible clients: id => [name, company, email, label]. */
  public static function clients(?array $u): array {
    [$w, $p] = $u ? ClientAccess::where($u, 'c.id') : ['1=1', []];
    $out = [];
    foreach (DB::all("SELECT c.id, c.name, c.company, c.email FROM clients c WHERE c.status<>'inactive' AND $w ORDER BY c.id LIMIT 5000", $p) as $r) { $out[(int) $r['id']] = $r + ['label' => $r['company'] ?: $r['name']]; }
    return $out; }

  /** Visible projects (not cancelled) whose client is visible too: id => [name, client_id, status]. */
  public static function projects(?array $u): array {
    if (!feature_on('projects')) { return []; }
    [$w, $p] = $u ? ProjectAccess::scope($u, 'p.id') : ['1=1', []];
    /* a project counts only when its client is visible too (logs of hidden clients cannot be written or read) */
    if ($u) { [$cw, $cp] = ClientAccess::where($u, 'p.client_id'); $w = "($w) AND (p.client_id IS NULL OR $cw)"; $p = [...$p, ...$cp]; }
    $out = [];
    foreach (DB::all("SELECT p.id, p.name, p.client_id, p.status FROM projects p WHERE p.status<>'cancelled' AND $w ORDER BY p.id DESC LIMIT 5000", $p) as $r) { $out[(int) $r['id']] = ['name' => $r['name'], 'client_id' => $r['client_id'] ? (int) $r['client_id'] : null, 'status' => $r['status']]; }
    return $out; }

  /** Active projects the user manages or belongs to (within the visible set). */
  private static function mine(int $uid, array $projects): array {
    $ids = array_map('intval', DB::column("SELECT p.id FROM projects p WHERE p.status IN ('active','planning','on_hold') AND (p.manager_id=? OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id=p.id AND pm.user_id=?))", [$uid, $uid]));
    return array_values(array_filter($ids, static fn ($id) => isset($projects[$id]))); } }
