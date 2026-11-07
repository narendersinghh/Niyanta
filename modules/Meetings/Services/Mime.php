<?php
namespace Modules\Meetings\Services;

/**
 * Minimal RFC 822 / MIME parser for the notes inbox: unfolds headers, decodes encoded words, walks multipart trees
 * (mixed, alternative, related, forwarded message/rfc822), base64 and quoted-printable bodies and converts charsets to UTF-8.
 * Result: headers, from, from_name, to, cc, recipients, subject, date, message_id, text, html, attachments [name, type, content].
 */
final class Mime {
  private const MAX_PARTS = 60;

  public static function parse(string $raw): array {
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    [$head, $body] = self::split($raw);
    $h = self::headers($head);
    $from = self::addresses($h['from'] ?? '');
    $to = array_column(self::addresses($h['to'] ?? ''), 'email'); $cc = array_column(self::addresses($h['cc'] ?? ''), 'email');
    $rcpt = $to; foreach (['cc', 'delivered-to', 'x-original-to', 'envelope-to', 'x-forwarded-to', 'resent-to'] as $k) { $rcpt = array_merge($rcpt, array_column(self::addresses($h[$k] ?? ''), 'email')); }
    $m = ['headers' => $h, 'from' => $from[0]['email'] ?? null, 'from_name' => $from[0]['name'] ?? null, 'to' => $to, 'cc' => $cc, 'recipients' => array_values(array_unique($rcpt)),
      'subject' => trim((string) ($h['subject'] ?? '')), 'date' => isset($h['date']) && ($ts = strtotime($h['date'])) ? date('Y-m-d H:i:s', $ts) : null, 'message_id' => trim((string) ($h['message-id'] ?? ''), " <>") ?: null,
      'text' => '', 'html' => '', 'attachments' => []];
    $n = 0;
    self::walk($h, $body, $m, 0, $n);
    $m['text'] = trim($m['text']); $m['html'] = trim($m['html']);
    return $m; }

  /** Readable body: text/plain, else the HTML part as text. */
  public static function bodyText(array $m): string { return $m['text'] !== '' ? $m['text'] : ($m['html'] !== '' ? Parser::htmlText($m['html']) : ''); }

  private static function split(string $raw): array { $p = strpos($raw, "\n\n"); return $p === false ? [$raw, ''] : [substr($raw, 0, $p), substr($raw, $p + 2)]; }

  /** Lower-case header name => decoded value (first occurrence wins, except Received-like repeats which are joined). */
  public static function headers(string $head): array {
    $out = [];
    foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $head) ?? $head) as $line) {
      if (!str_contains($line, ':')) { continue; }
      [$k, $v] = explode(':', $line, 2);
      $k = strtolower(trim($k)); $v = self::decodeHeader(trim($v));
      if (isset($out[$k])) { if (in_array($k, ['to', 'cc', 'delivered-to', 'x-original-to', 'authentication-results'], true)) { $out[$k] .= ', '.$v; } continue; }
      $out[$k] = $v; }
    return $out; }

  public static function decodeHeader(string $v): string {
    if (!str_contains($v, '=?')) { return $v; }
    if (function_exists('iconv_mime_decode')) { $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'); if ($d !== false) { return $d; } }
    return mb_decode_mimeheader($v); }

  /** [['name', 'email']] from an address header. */
  public static function addresses(string $v): array {
    $out = [];
    if (preg_match_all('/(?:"?([^",<>]*?)"?\s*)?<?([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})>?/i', $v, $mm, PREG_SET_ORDER)) { foreach ($mm as $m) { $out[] = ['name' => trim($m[1] ?? '') ?: null, 'email' => strtolower($m[2])]; } }
    return $out; }

  /** Content-Type / Content-Disposition value: [main, params]. */
  private static function params(string $v): array {
    $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v) ?: [$v];
    $main = strtolower(trim(array_shift($parts)));
    $p = [];
    foreach ($parts as $x) { if (str_contains($x, '=')) { [$k, $val] = explode('=', $x, 2); $k = strtolower(trim($k)); $val = trim(trim($val), '"'); if (str_ends_with($k, '*')) { $k = rtrim($k, '*'); $val = rawurldecode(preg_replace("/^[^']*'[^']*'/", '', $val) ?? $val); } $p[$k] = self::decodeHeader($val); } }
    return [$main, $p]; }

  private static function walk(array $h, string $body, array &$m, int $depth, int &$n): void {
    if ($depth > 6 || ++$n > self::MAX_PARTS) { return; }
    [$type, $tp] = self::params($h['content-type'] ?? 'text/plain');
    [$disp, $dp] = self::params($h['content-disposition'] ?? '');
    if (str_starts_with($type, 'multipart/') && !empty($tp['boundary'])) {
      $b = preg_quote($tp['boundary'], '/');
      $chunks = preg_split('/^--'.$b.'(?:--)?[ \t]*$/m', $body) ?: [];
      array_shift($chunks);
      foreach ($chunks as $c) { $c = ltrim($c, "\n"); if (trim($c) === '') { continue; } [$ph, $pb] = self::split($c); self::walk(self::headers($ph), $pb, $m, $depth + 1, $n); }
      return; }
    if ($type === 'message/rfc822') { [$ih, $ib] = self::split(ltrim($body, "\n")); $inner = self::headers($ih); $m['forwarded'][] = ['from' => self::addresses($inner['from'] ?? '')[0]['email'] ?? null, 'subject' => $inner['subject'] ?? null, 'date' => $inner['date'] ?? null]; self::walk($inner, $ib, $m, $depth + 1, $n); return; }
    $data = self::decodeBody($body, strtolower(trim($h['content-transfer-encoding'] ?? '')));
    $fname = $dp['filename'] ?? $tp['name'] ?? null;
    if ($disp === 'attachment' || ($fname && !in_array($type, ['text/plain', 'text/html'], true))) {
      $m['attachments'][] = ['name' => basename(str_replace('\\', '/', (string) ($fname ?: 'attachment'))), 'type' => $type, 'content' => $data, 'size' => strlen($data)];
      return; }
    $cs = $tp['charset'] ?? 'utf-8';
    if ($type === 'text/plain') { $m['text'] .= ($m['text'] !== '' ? "\n\n" : '').self::charset($data, $cs); }
    elseif ($type === 'text/html') { $m['html'] .= ($m['html'] !== '' ? "\n" : '').self::charset($data, $cs); } }

  public static function decodeBody(string $b, string $enc): string {
    return match ($enc) {
      'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $b) ?? '', false),
      'quoted-printable' => quoted_printable_decode($b),
      default => $b }; }

  public static function charset(string $s, string $cs): string {
    $cs = strtolower(trim($cs, " \"'")) ?: 'utf-8';
    if (in_array($cs, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) { return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); }
    $known = array_map('strtolower', mb_list_encodings());
    if (in_array($cs, $known, true)) { return mb_convert_encoding($s, 'UTF-8', $cs); }
    $out = function_exists('iconv') ? @iconv($cs, 'UTF-8//IGNORE', $s) : false;
    return $out !== false ? $out : mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); } }
