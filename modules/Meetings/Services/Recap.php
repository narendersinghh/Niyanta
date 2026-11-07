<?php
namespace Modules\Meetings\Services;

/** Recap e-mails and notes from meeting tools: which tool sent them, a clean title, and the text without footers and boilerplate. */
final class Recap {
  /** tool => [label, sender domains, text markers] */
  public const TOOLS = [
    'gemini' => ['Google Gemini notes', ['gemini-notes@google.com'], ['notes by gemini', 'gemini in meet', 'take notes for me']],
    'meet' => ['Google Meet', ['meet-recordings-noreply@google.com', 'calendar-notification@google.com'], ['google meet', 'meet.google.com']],
    'zoom' => ['Zoom AI Companion', ['zoom.us', 'zoom.com'], ['zoom ai companion', 'ai companion', 'meeting summary with ai companion']],
    'teams' => ['Microsoft Teams', ['teams.microsoft.com', 'microsoft.com', 'microsoftonline.com'], ['microsoft teams', 'teams meeting']],
    'fireflies' => ['Fireflies', ['fireflies.ai'], ['fireflies.ai', 'fireflies', 'fred from fireflies']],
    'otter' => ['Otter', ['otter.ai'], ['otter.ai', 'otter assistant', 'otterpilot']],
    'tldv' => ['tl;dv', ['tldv.io'], ['tl;dv', 'tldv.io']],
    'tactiq' => ['Tactiq', ['tactiq.io'], ['tactiq']],
    'readai' => ['Read.ai', ['read.ai'], ['read ai', 'read.ai']],
  ];
  public const SOURCES = ['upload' => 'File upload', 'paste' => 'Pasted notes', 'email' => 'Notes inbox', 'fireflies' => 'Fireflies', 'pipe' => 'Notes inbox'];

  public static function label(?string $tool, string $source = 'upload'): string { return $tool && isset(self::TOOLS[$tool]) ? self::TOOLS[$tool][0] : (self::SOURCES[$source] ?? 'Upload'); }

  /** Tool from the sender address (or forwarded sender), else from markers in the text. */
  public static function tool(?string $from, string $text = ''): ?string {
    $from = strtolower((string) $from);
    if ($from !== '') { foreach (self::TOOLS as $k => [, $senders]) { foreach ($senders as $s) { if ($from === $s || str_ends_with($from, '@'.$s) || str_ends_with($from, '.'.$s)) { return $k; } } } }
    return self::toolFromText($text); }

  public static function toolFromText(string $text): ?string {
    $t = mb_strtolower(mb_substr($text, 0, 6000));
    foreach (self::TOOLS as $k => [, , $marks]) { foreach ($marks as $m) { if (str_contains($t, $m)) { return $k; } } }
    return null; }

  /** Meeting title from a recap subject: prefixes such as Fwd:, "Notes:", "Meeting summary for", "Your meeting recap -" removed. */
  public static function title(string $subject): string {
    $s = trim($subject);
    for ($i = 0; $i < 4; $i++) {
      $n = preg_replace(['/^\s*(fwd?|fw|re|aw|tr)\s*:\s*/i', '/^\s*(notes?|meeting notes|transcript|recap|summary)\s*(by gemini)?\s*[:\-–]\s*/iu', '/^\s*(meeting (summary|assets|recap|notes)|your meeting (recap|notes|summary))\s*(for|of|from|[:\-–])\s*/iu',
        '/\s*[\-–:|]\s*(notes by gemini|meeting recap|meeting notes|meeting summary|recap|transcript|by fireflies\.ai|fireflies\.ai)\s*$/iu', '/\s+(are|is) ready!?$/i', '/^\s*["“](.+)["”]\s*$/u'], ['', '', '', '', '', '$1'], $s) ?? $s;
      if ($n === $s) { break; }
      $s = trim($n); }
    return mb_substr($s !== '' ? $s : 'Meeting notes', 0, 190); }

  /** Fireflies transcript id from a recap link (app.fireflies.ai/view/Title::ID), used to dedupe against the API sync. */
  public static function firefliesId(string $text): ?string { return preg_match('#app\.fireflies\.ai/view/[^\s"\'<>]*?::([A-Za-z0-9]{8,40})#', $text, $m) ? $m[1] : null; }

  /** Text without forward headers, footers, unsubscribe and "view in app" lines. */
  public static function clean(string $text): string {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $out = []; $n = count($lines); $skipHead = false;
    $footer = '/^(you (are )?receiv(ed|ing) this|unsubscribe|manage (your )?(notification|email|e-mail)|notification settings|email preferences|this (email|message) was sent|was this (summary )?helpful|rate this|how (was|did) (this|we)|powered by|download the (app|.* app)|get the app|privacy policy|terms of service|©|\(c\) 20|copyright|google llc|zoom (video )?communications|fireflies\.ai corp|otter\.ai, inc|sent from my|to stop receiving|view this email in|you can change your settings)/i';
    $noise = '/^(view in (browser|app)|open in (fireflies|otter|tl;?dv|tactiq|read\.?ai|zoom)|view (full )?(recap|transcript|meeting|recording|notes)|share (this|recap)|reply to this email|\[image[^\]]*\]|https?:\/\/\S+|<https?:\/\/\S+>)$/i';
    foreach ($lines as $i => $raw) {
      $l = trim($raw);
      if (preg_match('/^-{3,}\s*forwarded message\s*-{3,}$|^begin forwarded message:?$/i', $l)) { $skipHead = true; continue; }
      if ($skipHead) { if ($l === '') { $skipHead = false; } continue; }
      if (preg_match($footer, $l)) { if ($i > $n * 0.3) { break; } continue; }
      if (preg_match($noise, $l)) { continue; }
      $out[] = rtrim($raw); }
    $t = implode("\n", $out);
    return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t); }

  /** Original sender / subject / date of a message forwarded inline ("---------- Forwarded message ---------"). */
  public static function forwarded(string $text): ?array {
    if (!preg_match('/(?:-{3,}\s*forwarded message\s*-{3,}|begin forwarded message:?)\s*\n(.{0,1500})/is', $text, $m)) { return null; }
    $get = static fn (string $k) => preg_match('/^\s*\*?'.$k.'\*?\s*:\s*(.+)$/im', $m[1], $x) ? trim($x[1]) : null;
    $from = $get('From'); $email = $from && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $e) ? strtolower($e[0]) : null;
    return ['from' => $email, 'subject' => $get('Subject'), 'date' => $get('Date')]; } }
