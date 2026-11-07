<?php
namespace Modules\Meetings\Services;

/**
 * Turns a meeting transcript or notes export into one normalised document, whatever the platform:
 * WebVTT (Zoom, Teams, Google Meet), SRT, plain text / Markdown (Otter, Zoom, Tactiq, tl;dv, Gemini notes), Word .docx (Google Meet transcripts,
 * Gemini notes and Teams downloads; ZipArchive with a pure-PHP fallback), HTML and JSON exports (Fireflies, generic segment lists).
 * Result: ['title','meeting_at','duration_min','participants' => [['name','email']],'text' (Speaker: line per turn),'summary','external_id','tool','format'].
 */
final class Parser {
  public const EXT = ['txt', 'vtt', 'srt', 'docx', 'md', 'markdown', 'html', 'htm', 'json'];
  public const MAX_BYTES = 8388608;
  private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

  public static function parse(string $content, string $name = ''): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = ['title' => null, 'meeting_at' => null, 'duration_min' => null, 'participants' => [], 'text' => '', 'summary' => null, 'external_id' => null, 'tool' => null, 'format' => 'text', 'file_name' => $name !== '' ? mb_substr(basename($name), 0, 190) : null];
    if ($ext === 'docx' || str_starts_with($content, "PK\x03\x04")) {
      $d = Docx::read($content);
      $doc = ['format' => 'docx', 'text' => self::speakers($d['text']), 'title' => $d['title']] + $base; }
    else {
      $s = self::utf8($content);
      $t = ltrim($s);
      $json = ($ext === 'json' || $t !== '' && ($t[0] === '{' || $t[0] === '[')) ? json_decode($t, true) : null;
      if (is_array($json)) { $doc = self::json($json) + $base; }
      elseif ($ext === 'vtt' || str_starts_with($t, 'WEBVTT')) { $doc = self::cues($s) + ['format' => 'vtt'] + $base; }
      elseif ($ext === 'srt' || preg_match('/^\d+\s*\R\d{1,2}:\d{2}:\d{2},\d{3}\s*-->/m', $t)) { $doc = self::cues($s) + ['format' => 'srt'] + $base; }
      elseif (in_array($ext, ['html', 'htm'], true) || preg_match('/<(html|body|div|p|br|table)\b/i', substr($t, 0, 4000))) { $doc = ['format' => 'html', 'text' => self::speakers(self::htmlText($s))] + $base; }
      else { $doc = ['format' => in_array($ext, ['md', 'markdown'], true) ? 'markdown' : 'text', 'text' => self::speakers($s)] + $base; } }
    return self::meta($doc, $name); }

  /* ---------- formats ---------- */

  /** WebVTT / SRT cues: timestamps and numbering dropped, voice tags (<v Name>) or "Name:" prefixes become speakers, consecutive turns merged. */
  private static function cues(string $s): array {
    $blocks = preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $s))) ?: [];
    $lines = []; $end = 0; $note = null;
    foreach ($blocks as $b) {
      $rows = explode("\n", trim($b)); $ti = null;
      if ($note === null && preg_match('/^NOTE\s+(.{3,190})$/s', trim($b), $nm) && !preg_match('/-->|^(confidence|duration|language)\b/i', $nm[1])) { $note = trim(preg_replace('/\s+/', ' ', $nm[1]) ?? ''); continue; }
      foreach ($rows as $i => $r) { if (preg_match('/((?:\d{1,2}:)?\d{1,2}:\d{2})[.,]\d{1,3}\s*-->\s*((?:\d{1,2}:)?\d{1,2}:\d{2})[.,]\d{1,3}/', $r, $m)) { $ti = $i; $end = max($end, self::secs($m[2])); break; } }
      if ($ti === null) { continue; }
      $text = trim(implode(' ', array_slice($rows, $ti + 1)));
      $who = null;
      if (preg_match('/^<v(?:\.[^\s>]*)?\s+([^>]+)>(.*)$/s', $text, $m)) { $who = trim($m[1]); $text = $m[2]; }
      $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      if (!$who && preg_match('/^([\p{L}][\p{L}\p{M} .\'\-]{0,58}):\s+(.+)$/su', $text, $m)) { $who = trim($m[1]); $text = $m[2]; }
      if ($text === '') { continue; }
      $n = count($lines);
      if ($n && $lines[$n - 1][0] === $who) { $lines[$n - 1][1] .= ' '.$text; } else { $lines[] = [$who, $text]; } }
    return ['text' => implode("\n", array_map(static fn ($l) => ($l[0] !== null ? $l[0].': ' : '').$l[1], $lines)), 'duration_min' => $end ? max(1, (int) round($end / 60)) : null, 'title' => $note]; }

  /** JSON exports: Fireflies (transcript object, GraphQL response or export file) or any list of {speaker, text} segments. */
  private static function json(array $j): array {
    $t = $j['data']['transcript'] ?? $j['transcript'] ?? $j;
    if (isset($j['data']['transcripts'][0])) { $t = $j['data']['transcripts'][0]; }
    if (!is_array($t)) { return ['format' => 'json', 'text' => self::speakers(self::utf8((string) $t)), 'title' => is_string($j['title'] ?? null) ? $j['title'] : null]; }
    if (isset($t['sentences']) && is_array($t['sentences'])) { return Fireflies::toDoc($t) + ['format' => 'json']; }
    $segs = null;
    foreach (['segments', 'utterances', 'transcript', 'entries', 'results', 'captions'] as $k) { if (isset($t[$k]) && is_array($t[$k]) && array_is_list($t[$k])) { $segs = $t[$k]; break; } }
    if ($segs === null && array_is_list($t)) { $segs = $t; }
    $lines = [];
    foreach ($segs ?? [] as $s) {
      if (!is_array($s)) { continue; }
      $who = $s['speaker_name'] ?? $s['speaker'] ?? $s['name'] ?? $s['participant'] ?? $s['user'] ?? null;
      if (is_array($who)) { $who = $who['name'] ?? $who['displayName'] ?? null; }
      $text = $s['text'] ?? $s['content'] ?? $s['transcript'] ?? $s['words'] ?? '';
      if (is_array($text)) { $text = implode(' ', array_map(static fn ($w) => is_array($w) ? (string) ($w['text'] ?? $w['word'] ?? '') : (string) $w, $text)); }
      $text = trim((string) $text);
      if ($text === '') { continue; }
      $n = count($lines);
      if ($n && $lines[$n - 1][0] === $who) { $lines[$n - 1][1] .= ' '.$text; } else { $lines[] = [is_string($who) ? trim($who) : null, $text]; } }
    $summary = $t['summary'] ?? null;
    return ['format' => 'json', 'text' => implode("\n", array_map(static fn ($l) => ($l[0] ? $l[0].': ' : '').$l[1], $lines)), 'title' => is_string($t['title'] ?? $t['topic'] ?? null) ? ($t['title'] ?? $t['topic']) : null,
      'meeting_at' => self::when($t['date'] ?? $t['start_time'] ?? $t['started_at'] ?? $t['created_at'] ?? null), 'duration_min' => is_numeric($t['duration'] ?? null) ? (int) round((float) $t['duration']) : null,
      'summary' => is_string($summary) ? $summary : (is_array($summary) ? (string) ($summary['overview'] ?? '') : null)]; }

  /** HTML (recap emails, exported notes) to readable text. */
  public static function htmlText(string $html): string {
    $h = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $h = preg_replace('#<li\b[^>]*>#i', "\n• ", $h) ?? $h;
    $h = preg_replace('#<(br|hr)\b[^>]*>|</(p|div|li|h[1-6]|tr|table|ul|ol|blockquote|section|article)>#i', "\n", $h) ?? $h;
    $h = preg_replace('#</t[dh]>#i', ' ', $h) ?? $h;
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t) ?? $t;
    $t = implode("\n", array_map('trim', explode("\n", $t)));
    return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t); }

  /**
   * Plain-text transcripts: "[Name] 00:01:02" (Zoom), "Name  0:05" (Otter, Teams), lone timestamp lines (Google Meet) become "Name: text" lines.
   * Other text (notes, recaps) is kept as is.
   */
  public static function speakers(string $s): string {
    $out = []; $who = null;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $s)) as $line) {
      $l = rtrim($line);
      if (preg_match('/^\s*\(?\d{1,2}:\d{2}(?::\d{2})?(?:[.,]\d+)?\)?\s*$/', $l)) { continue; }
      if (preg_match('/^\s*\[([^\]]{2,60})\]\s+\(?\d{1,2}:\d{2}(?::\d{2})?\)?\s*$/u', $l, $m) || preg_match('/^\s*([\p{Lu}][\p{L}\p{M}.\'\-]*(?:\s[\p{L}\p{M}.\'\-]+){0,4})\s+\(?\d{1,2}:\d{2}(?::\d{2})?\)?\s*$/u', $l, $m)) { $who = trim($m[1]); continue; }
      if ($who !== null && trim($l) !== '') { $out[] = $who.': '.trim($l); $who = null; continue; }
      $out[] = $l; }
    $t = implode("\n", $out);
    return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t); }

  /* ---------- metadata ---------- */

  /** Fill title, date, duration and participants from the text when the format did not carry them. */
  private static function meta(array $d, string $name): array {
    $text = (string) $d['text'];
    $head = mb_substr($text, 0, 2500);
    if (!$d['title']) {
      $talk = preg_match_all('/^[^:\n]{1,40}:\s/m', $head) >= 3;
      foreach (explode("\n", $head) as $l) {
        $l = trim(preg_replace('/^#+\s*|^(subject|title|meeting|topic)\s*:\s*/i', '', trim($l)) ?? '');
        if ($l === '' || preg_match('/^(webvtt|notes|transcript|summary|attendees|participants)\b\s*:?$/i', $l) || ($talk && preg_match('/^[^:]{1,40}:\s/', $l)) || preg_match('/^\d{1,2}:\d{2}/', $l)) { continue; }
        $d['title'] = $l; break; } }
    if (!$d['title'] && $name !== '') { $d['title'] = trim(preg_replace('/[_\-]+/', ' ', pathinfo($name, PATHINFO_FILENAME)) ?? ''); }
    $d['title'] = mb_substr(trim(strip_tags((string) ($d['title'] ?: 'Meeting'))), 0, 190) ?: 'Meeting';
    $d['meeting_at'] ??= self::findDate($d['title'].' '.$head) ?? ($name !== '' ? self::findDate(str_replace('_', ' ', $name)) : null);
    if (!$d['duration_min'] && preg_match('/\b(?:duration|length)\s*[:\-]?\s*(?:(\d+)\s*h(?:ours?|rs?)?\s*)?(?:(\d+)\s*m(?:in(?:ute)?s?)?)?/i', $head, $m) && ($m[1] ?? '') . ($m[2] ?? '') !== '') { $d['duration_min'] = (int) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0) ?: null; }
    $d['participants'] = self::people($text, $d['participants']);
    $d['tool'] ??= Recap::toolFromText($head);
    $d['text'] = trim($text);
    return $d; }

  /** Participants: given ones, attendee lines, every e-mail address and the speakers of the transcript (max 40). */
  public static function people(string $text, array $given = []): array {
    $out = []; $seen = [];
    $add = static function (?string $name, ?string $email) use (&$out, &$seen): void {
      $email = $email ? strtolower(trim($email, " <>.,;")) : null; $name = $name ? trim($name, " \t\"'<>,;") : null;
      if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = null; }
      if (!$email && (!$name || mb_strlen($name) < 2 || mb_strlen($name) > 60)) { return; }
      $k = $email ?: 'n:'.mb_strtolower($name);
      if (isset($seen[$k])) { if ($name && !$out[$seen[$k]]['name']) { $out[$seen[$k]]['name'] = $name; } return; }
      if (count($out) >= 40) { return; }
      $seen[$k] = count($out); $out[] = ['name' => $name ?: null, 'email' => $email]; };
    foreach ($given as $p) { is_array($p) ? $add($p['name'] ?? null, $p['email'] ?? null) : (str_contains((string) $p, '@') ? $add(null, (string) $p) : $add((string) $p, null)); }
    $scan = mb_substr($text, 0, 60000);
    if (preg_match_all('/(?:^|[\s,;:(])"?([^"<>\n,;:@()]{2,60}?)"?\s*<([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})>/im', $scan, $mm, PREG_SET_ORDER)) {
      foreach ($mm as $m) { $n = trim($m[1]); $add(!preg_match('/^(attendees|participants|invited|invitees|from|to|cc|organizer|email)$/i', $n) ? $n : null, $m[2]); } }
    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $scan, $mm)) { foreach ($mm[0] as $e) { $add(null, $e); } }
    if (preg_match('/^(?:attendees|participants|invited|invitees)\s*:\s*(.+)$/im', $scan, $m)) { foreach (preg_split('/\s*[,;]\s*|\s+and\s+/', $m[1]) ?: [] as $n) { if (!str_contains($n, '@')) { $add($n, null); } } }
    if (preg_match_all('/^([\p{L}][\p{L}\p{M} .\'\-]{1,58}):\s/mu', $scan, $sp)) { foreach (array_unique($sp[1]) as $n) { if (!preg_match('/^(attendees|participants|invited|invitees|from|to|cc|date|time|subject|title|duration|summary|notes|action items?|next steps|decisions|agenda|topic|organizer|location|https?)$/i', trim($n))) { $add($n, null); } } }
    return $out; }

  /** First date (and time) in a text: 2026-11-07 10:00, 07/11/2026, Nov 7, 2026 at 3:30 PM, 7 November 2026 10:00. */
  public static function findDate(string $s): ?string {
    $tm = '(?:,?\s*(?:at|@|-)?\s*(\d{1,2})[:.](\d{2})\s*([AaPp]\.?[Mm]\.?)?)?';
    $best = null;
    foreach (['/\b(20\d{2})[\-\/.](\d{1,2})[\-\/.](\d{1,2})(?:[T ,]+(\d{1,2}):(\d{2})\s*([AaPp][Mm])?)?/' => [1, 2, 3, 4, 5, 6],
      '/\b('.self::MONTHS.')\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(20\d{2})'.$tm.'/i' => [3, 1, 2, 4, 5, 6],
      '/\b(\d{1,2})(?:st|nd|rd|th)?\s+('.self::MONTHS.')\.?,?\s+(20\d{2})'.$tm.'/i' => [3, 2, 1, 4, 5, 6]] as $re => $o) {
      if (!preg_match($re, $s, $m, PREG_OFFSET_CAPTURE) || ($best && $m[0][1] >= $best[0])) { continue; }
      $g = static fn (int $i) => (string) ($m[$i][0] ?? '');
      $mon = ctype_digit($g($o[1])) ? (int) $g($o[1]) : self::month($g($o[1]));
      $best = [$m[0][1], [(int) $g($o[0]), $mon, (int) $g($o[2]), $g($o[3]), $g($o[4]), $g($o[5])]]; }
    if (!$best) { return null; }
    $c = $best[1];
    if (!checkdate($c[1], $c[2], $c[0])) { return null; }
    $h = $c[3] !== '' ? (int) $c[3] : 0; $i = $c[4] !== '' ? (int) $c[4] : 0; $ap = strtolower(str_replace('.', '', $c[5]));
    if ($ap === 'pm' && $h < 12) { $h += 12; } elseif ($ap === 'am' && $h === 12) { $h = 0; }
    return $h > 23 || $i > 59 ? null : sprintf('%04d-%02d-%02d %02d:%02d:00', $c[0], $c[1], $c[2], $h, $i); }

  /** Timestamps from exports: epoch milliseconds / seconds or any date string. */
  public static function when(mixed $v): ?string {
    if ($v === null || $v === '') { return null; }
    if (is_numeric($v)) { $n = (float) $v; $ts = (int) ($n > 1e11 ? $n / 1000 : $n); return $ts > 946684800 ? date('Y-m-d H:i:s', $ts) : null; }
    $ts = strtotime((string) $v);
    return $ts && $ts > 946684800 ? date('Y-m-d H:i:s', $ts) : null; }

  private static function month(string $m): int { return (int) array_search(strtolower(substr($m, 0, 3)), ['', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true); }
  private static function secs(string $t): int { $p = array_reverse(array_map('intval', explode(':', $t))); return ($p[0] ?? 0) + ($p[1] ?? 0) * 60 + ($p[2] ?? 0) * 3600; }

  /** Any input to UTF-8 (BOMs, UTF-16 exports from Teams, Windows-1252 text). */
  public static function utf8(string $s): string {
    if (str_starts_with($s, "\xEF\xBB\xBF")) { $s = substr($s, 3); }
    elseif (str_starts_with($s, "\xFF\xFE")) { $s = mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE'); }
    elseif (str_starts_with($s, "\xFE\xFF")) { $s = mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE'); }
    if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); }
    return str_replace(["\r\n", "\r"], "\n", $s); }

  /* ---------- comparison (dedupe) ---------- */

  /** Content fingerprint: lower-case words only, so the same meeting as VTT, text or docx hashes alike. */
  public static function hash(string $text): ?string { $n = self::norm($text); return $n === '' ? null : hash('sha256', $n); }
  public static function norm(string $text): string { return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(preg_replace('/\b\d{1,2}:\d{2}(:\d{2})?([.,]\d+)?\b/', ' ', $text) ?? '')) ?? '') ?? ''); }

  /** Share of word trigrams of the shorter text found in the other one (0..1). */
  public static function overlap(string $a, string $b): float {
    $g = static function (string $t): array { $w = explode(' ', self::norm(mb_substr($t, 0, 40000))); $o = []; for ($i = 0, $n = count($w) - 2; $i < $n; $i++) { $o[$w[$i].' '.$w[$i + 1].' '.$w[$i + 2]] = true; } return $o; };
    $x = $g($a); $y = $g($b);
    if (!$x || !$y) { return 0.0; }
    return count(array_intersect_key($x, $y)) / min(count($x), count($y)); }

  public static function sameTitle(string $a, string $b): bool {
    $n = static fn (string $t) => trim(preg_replace('/\b(fwd?|re|notes?|meeting (notes|summary|recap)( for)?|transcript|recap|summary|by gemini)\b/i', ' ', self::norm($t)) ?? '');
    [$x, $y] = [$n($a), $n($b)];
    if ($x === '' || $y === '') { return false; }
    if ($x === $y || str_contains($x, $y) || str_contains($y, $x)) { return true; }
    similar_text($x, $y, $pct);
    return $pct >= 80; } }
