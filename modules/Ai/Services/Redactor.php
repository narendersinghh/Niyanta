<?php
namespace Modules\Ai\Services;

/** Replaces personal identifiers with placeholders before text leaves the server. */
final class Redactor {
  public static function redact(string $s): string {
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
