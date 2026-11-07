<?php
namespace Modules\Ai\Services;

/** Replaces personal identifiers with placeholders before text leaves the server; secrets are always stripped from tool results. */
final class Redactor {
  /**
   * Credentials that may sit in free text (client notes, task descriptions, meeting summaries): API keys and tokens of common
   * providers, bearer / basic auth headers, JWTs, private key blocks and "password: ..." style pairs. Always applied to tool output.
   */
  public static function secrets(string $s): string {
    $s = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----[^"]*?(?:-----END [A-Z ]*PRIVATE KEY-----|(?="|$))/', '[secret]', $s) ?? $s;
    $s = preg_replace('/\b(?:sk|pk|rk)-(?:ant-|proj-|live-|test-)?[A-Za-z0-9_\-]{12,}|\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}|\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}|\bxox[abposr]-[A-Za-z0-9\-]{10,}|\b(?:AKIA|ASIA)[A-Z0-9]{16}\b|\bAIza[A-Za-z0-9_\-]{30,}|\bglpat-[A-Za-z0-9_\-]{16,}|\bey[A-Za-z0-9_\-]{10,}\.ey[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/', '[secret]', $s) ?? $s;
    $s = preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/\-]{12,}=*/i', '$1 [secret]', $s) ?? $s;
    $val = '(?!\[)(\'?[^\s"\'\\\\]{3,100})'; // never consumes quotes or backslashes, so it is safe on JSON text too
    $s = preg_replace('/\b(pass(?:word|wd|code|phrase)|pwd|otp)(\s*[:=]\s*|\s+is\s+)'.$val.'/i', '$1$2[secret]', $s) ?? $s;
    return preg_replace('/\b(secret|api[_\- ]?key|access[_\- ]?key|auth[_\- ]?token|token|pin)(\s*[:=]\s*)'.$val.'/i', '$1$2[secret]', $s) ?? $s; }

  public static function redact(string $s): string {
    $s = self::secrets($s);
    $s = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $s) ?? $s;
    $s = preg_replace('/\b(?:\d{4}[ \-]){3}\d{4}\b/', '[card-number]', $s) ?? $s;
    $s = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/', '[account-number]', $s) ?? $s;
    $s = preg_replace('/\b[A-Z]{4}0[A-Z0-9]{6}\b/', '[ifsc]', $s) ?? $s;
    $s = preg_replace('/\b[A-Z]{5}\d{4}[A-Z]\b/', '[tax-id]', $s) ?? $s;
    $s = preg_replace('/\b\d{2}[A-Z]{5}\d{4}[A-Z]\d[A-Z\d]{2}\b/', '[tax-id]', $s) ?? $s;
    return preg_replace_callback('/(?<![\w.\/\-])\+?\(?\d[\d\s().\-]{6,}\d(?![\w\/\-])/', static function (array $m): string {
      $v = $m[0];
      if (preg_match('/^\d{4}-\d{2}-\d{2}$|^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}$|^\d+\.\d{1,2}$/', $v)) { return $v; }
      $n = strlen(preg_replace('/\D+/', '', $v));
      if (ctype_digit($v) && $n >= 9) { return '[account-number]'; }
      if ($n >= 8 && $n <= 15) { return '[phone]'; }
      return $n > 15 ? '[account-number]' : $v; }, $s) ?? $s; } }
