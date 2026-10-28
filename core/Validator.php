<?php
namespace Core;

/**
 * Rules separated by "|": required, nullable, string, email, url, numeric, integer, boolean, date, datetime, time,
 * min:n, max:n (length for strings, value for numbers), in:a,b, regex:/x/, phone, exists:table,column, unique:table,column[,ignoreId], confirmed, array
 */
final class Validator {
  public static function make(array $data, array $rules, array $labels = []): array {
    $out = []; $err = [];
    foreach ($rules as $field => $ruleStr) {
      $rs = is_array($ruleStr) ? $ruleStr : explode('|', $ruleStr);
      $v = $data[$field] ?? null;
      if (is_string($v)) { $v = trim($v); }
      $label = $labels[$field] ?? ucfirst(str_replace(['_id', '_'], ['', ' '], $field));
      $empty = $v === null || $v === '' || $v === [];
      if ($empty) {
        if (in_array('required', $rs, true)) { $err[$field] = "$label is required."; }
        elseif (in_array('boolean', $rs, true)) { $out[$field] = 0; }
        else { $out[$field] = in_array('array', $rs, true) ? [] : null; }
        continue; }
      $numeric = in_array('numeric', $rs, true) || in_array('integer', $rs, true);
      foreach ($rs as $r) {
        [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
        $msg = match ($name) {
          'string' => is_string($v) ? null : "$label must be text.",
          'array' => is_array($v) ? null : "$label is invalid.",
          'email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : "$label must be a valid email.",
          'url' => filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) ? null : "$label must be a valid URL.",
          'numeric' => is_numeric($v) ? null : "$label must be a number.",
          'integer' => filter_var($v, FILTER_VALIDATE_INT) !== false ? null : "$label must be a whole number.",
          'boolean' => null,
          'date' => self::isDate($v, 'Y-m-d') ? null : "$label must be a valid date.",
          'datetime' => self::isDate(str_replace('T', ' ', $v), 'Y-m-d H:i') || self::isDate(str_replace('T', ' ', $v), 'Y-m-d H:i:s') ? null : "$label must be a valid date & time.",
          'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v) ? null : "$label must be a valid time.",
          'min' => $numeric ? ((float) $v >= (float) $arg ? null : "$label must be at least $arg.") : (mb_strlen((string) $v) >= (int) $arg ? null : "$label must be at least $arg characters."),
          'max' => $numeric ? ((float) $v <= (float) $arg ? null : "$label may not exceed $arg.") : (mb_strlen(is_array($v) ? '' : (string) $v) <= (int) $arg ? null : "$label may not exceed $arg characters."),
          'in' => in_array((string) $v, explode(',', (string) $arg), true) ? null : "$label is invalid.",
          'regex' => preg_match((string) $arg, (string) $v) ? null : "$label format is invalid.",
          'phone' => preg_match('/^\+?[0-9 ()\-]{6,20}$/', (string) $v) ? null : "$label must be a valid phone number.",
          'exists' => self::exists($arg, $v) ? null : "$label is invalid.",
          'unique' => self::unique($arg, $v) ? null : "$label is already taken.",
          'confirmed' => ($data[$field.'_confirmation'] ?? null) === $v ? null : "$label confirmation does not match.",
          default => null };
        if ($msg) { $err[$field] = $msg; break; } }
      if (!isset($err[$field])) {
        if (in_array('boolean', $rs, true)) { $v = in_array($v, [1, '1', 'on', 'true', true, 'yes'], true) ? 1 : 0; }
        elseif (in_array('integer', $rs, true)) { $v = (int) $v; }
        elseif (in_array('datetime', $rs, true)) { $v = str_replace('T', ' ', $v); if (strlen($v) === 16) { $v .= ':00'; } }
        elseif (in_array('email', $rs, true)) { $v = strtolower($v); }
        $out[$field] = $v; } }
    if ($err) { throw new ValidationException($err); }
    return $out; }

  private static function isDate(string $v, string $f): bool { $d = \DateTime::createFromFormat($f, $v); return $d && $d->format($f) === $v; }
  private static function exists(string $arg, mixed $v): bool { [$t, $c] = explode(',', $arg) + [1 => 'id']; return (bool) DB::value('SELECT 1 FROM '.DB::id($t).' WHERE '.DB::id($c).'=? LIMIT 1', [$v]); }
  private static function unique(string $arg, mixed $v): bool {
    $p = explode(',', $arg);
    $sql = 'SELECT 1 FROM '.DB::id($p[0]).' WHERE '.DB::id($p[1] ?? 'id').'=?';
    $b = [$v];
    if (!empty($p[2])) { $sql .= ' AND id<>?'; $b[] = (int) $p[2]; }
    return !DB::value($sql.' LIMIT 1', $b); } }
