<?php
namespace Core;

/** Mail is queued and sent by cron via SMTP (STARTTLS/SSL, AUTH LOGIN) or PHP mail() fallback. */
final class Mailer {
  public static function queue(string $to, string $subject, string $html, ?string $toName = null): int {
    return DB::insert('mail_queue', ['to_email' => $to, 'to_name' => $toName, 'subject' => mb_substr($subject, 0, 250), 'body_html' => $html, 'status' => 'pending', 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s')]); }

  /** Wrap content in the branded email template. */
  public static function template(string $title, string $bodyHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string {
    $app = e(setting('branding.app_name', 'Niyanta'));
    $c = e(setting('branding.primary_color', '#5B4CF5'));
    $cta = $ctaUrl ? '<p style="margin:24px 0"><a href="'.e($ctaUrl).'" style="background:'.$c.';color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:600">'.e($ctaLabel ?? 'Open').'</a></p>' : '';
    return '<div style="background:#f4f4f7;padding:32px;font-family:Arial,sans-serif"><div style="max-width:560px;margin:auto;background:#fff;border-radius:18px;padding:32px"><div style="font-weight:800;font-size:20px;color:#111">'.$app.'</div><h2 style="color:#111">'.e($title).'</h2><div style="color:#444;line-height:1.6">'.$bodyHtml.'</div>'.$cta.'</div></div>'; }

  /** Process pending queue (cron). */
  public static function processQueue(int $limit = 20): int {
    $sent = 0;
    foreach (DB::all("SELECT * FROM mail_queue WHERE status='pending' AND attempts<5 ORDER BY id LIMIT ".(int) $limit) as $m) {
      try { self::send($m['to_email'], $m['subject'], $m['body_html'], $m['to_name']); DB::update('mail_queue', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'attempts' => $m['attempts'] + 1], 'id=?', [$m['id']]); $sent++; }
      catch (\Throwable $e) { DB::update('mail_queue', ['attempts' => $m['attempts'] + 1, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'status' => $m['attempts'] + 1 >= 5 ? 'failed' : 'pending'], 'id=?', [$m['id']]); } }
    return $sent; }

  public static function send(string $to, string $subject, string $html, ?string $toName = null): void {
    $from = (string) Settings::get('email.from_address', 'no-reply@'.($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = (string) Settings::get('email.from_name', Settings::get('branding.app_name', 'Niyanta'));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Invalid recipient'); }
    $enc = static fn (string $s) => '=?UTF-8?B?'.base64_encode($s).'?=';
    $headers = ['From' => $enc($fromName).' <'.$from.'>', 'To' => ($toName ? $enc($toName).' ' : '').'<'.$to.'>', 'Subject' => $enc($subject), 'Date' => date('r'), 'Message-ID' => '<'.bin2hex(random_bytes(12)).'@'.(explode('@', $from)[1] ?? 'localhost').'>', 'MIME-Version' => '1.0', 'Content-Type' => 'text/html; charset=UTF-8', 'Content-Transfer-Encoding' => 'base64'];
    $body = chunk_split(base64_encode($html));
    if (Settings::get('email.driver', 'smtp') !== 'smtp' || !Settings::get('email.smtp_host')) {
      $h = $headers; unset($h['To'], $h['Subject']);
      if (!mail($to, $headers['Subject'], $body, implode("\r\n", array_map(static fn ($k, $v) => "$k: $v", array_keys($h), $h)))) { throw new \RuntimeException('mail() failed'); }
      return; }
    self::smtp($from, $to, implode("\r\n", array_map(static fn ($k, $v) => "$k: $v", array_keys($headers), $headers))."\r\n\r\n".$body); }

  private static function smtp(string $from, string $to, string $data): void {
    $host = (string) Settings::get('email.smtp_host');
    $port = (int) Settings::get('email.smtp_port', 587);
    $sec = (string) Settings::get('email.smtp_encryption', 'tls');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { throw new \RuntimeException("SMTP connect failed: $errstr"); }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') { break; } } return $r; };
    $cmd = static function (string $c, array $ok) use ($fp, $read): string { fwrite($fp, $c."\r\n"); $r = $read(); if (!in_array((int) substr($r, 0, 3), $ok, true)) { throw new \RuntimeException('SMTP error: '.trim($r)); } return $r; };
    $read();
    $ehlo = 'EHLO '.(gethostname() ?: 'localhost');
    $cmd($ehlo, [250]);
    if ($sec === 'tls') { $cmd('STARTTLS', [220]); if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) { throw new \RuntimeException('STARTTLS failed'); } $cmd($ehlo, [250]); }
    if ($user = (string) Settings::get('email.smtp_username')) { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($user), [334]); $cmd(base64_encode((string) Settings::get('email.smtp_password')), [235]); }
    $cmd('MAIL FROM:<'.$from.'>', [250]);
    $cmd('RCPT TO:<'.$to.'>', [250, 251]);
    $cmd('DATA', [354]);
    $cmd(preg_replace('/^\./m', '..', $data)."\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp); } }
