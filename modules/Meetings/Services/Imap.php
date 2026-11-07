<?php
namespace Modules\Meetings\Services;

/**
 * Minimal IMAP4rev1 client over stream_socket_client, so the notes inbox works without the php-imap extension:
 * SSL/TLS (993) or STARTTLS (143), LOGIN, SELECT, UID SEARCH UNSEEN, UID FETCH BODY.PEEK[] (literals), UID STORE +FLAGS (\Seen), LOGOUT.
 * Every read honours a timeout. TLS certificates are verified.
 */
final class Imap {
  private int $tag = 0;
  /** @param resource $s */
  private function __construct(private $s, private int $timeout) {}

  public static function connect(string $host, int $port, string $enc = 'ssl', int $timeout = 20): self {
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) { throw new \RuntimeException('Invalid IMAP host.'); }
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $s = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) { throw new \RuntimeException('Could not connect to the IMAP server '.$host.':'.$port.' ('.($err ?: 'error '.$errno).').'); }
    $c = self::fromStream($s, $timeout);
    if ($enc === 'tls') {
      $c->cmd('STARTTLS');
      if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new \RuntimeException('The IMAP server did not accept STARTTLS.'); } }
    return $c; }

  /** @param resource $s an open stream that is about to send the server greeting (tests use a socket pair). */
  public static function fromStream($s, int $timeout = 20): self {
    stream_set_timeout($s, $timeout);
    $c = new self($s, $timeout);
    $g = $c->line();
    if (!str_starts_with($g, '* OK') && !str_starts_with($g, '* PREAUTH')) { throw new \RuntimeException('Unexpected IMAP greeting.'); }
    return $c; }

  public function login(string $user, string $pass): void { $this->cmd('LOGIN '.self::q($user).' '.self::q($pass), true); }
  public function select(string $folder): void { $this->cmd('SELECT '.self::q($folder)); }

  /** UIDs of unseen messages, oldest first. */
  public function unseen(): array {
    [$lines] = $this->cmd('UID SEARCH UNSEEN');
    $ids = [];
    foreach ($lines as $l) { if (preg_match('/^\* SEARCH\b(.*)$/i', $l, $m)) { foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $x) { if (ctype_digit($x)) { $ids[] = (int) $x; } } } }
    sort($ids);
    return $ids; }

  /** Raw RFC 822 message (without setting \Seen). */
  public function fetch(int $uid, int $max = 26214400): string {
    [, $lits] = $this->cmd('UID FETCH '.$uid.' (BODY.PEEK[])', false, $max);
    return $lits[0] ?? ''; }

  public function seen(int $uid): void { $this->cmd('UID STORE '.$uid.' +FLAGS.SILENT (\\Seen)'); }
  public function logout(): void { try { $this->cmd('LOGOUT'); } catch (\Throwable) {} @fclose($this->s); }

  /** Send a tagged command; returns [untagged lines, literals]. Throws on NO/BAD or timeouts. */
  private function cmd(string $c, bool $secret = false, int $max = 26214400): array {
    $tag = 'A'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
    if (@fwrite($this->s, $tag.' '.$c."\r\n") === false) { throw new \RuntimeException('IMAP connection lost.'); }
    $lines = []; $lits = [];
    while (true) {
      $l = $this->line();
      while (preg_match('/\{(\d+)\}$/', $l, $m)) {
        $n = (int) $m[1];
        if ($n > $max) { throw new \RuntimeException('Message too large.'); }
        $lits[] = $this->read($n);
        $l = substr($l, 0, -strlen($m[0])).'{literal}'.$this->line(); }
      if (str_starts_with($l, $tag.' ')) {
        if (!preg_match('/^'.$tag.' OK\b/i', $l)) { throw new \RuntimeException($secret ? 'The IMAP server rejected the login. Check the username and password.' : 'IMAP command failed: '.mb_substr(substr($l, strlen($tag) + 1), 0, 160)); }
        return [$lines, $lits]; }
      $lines[] = $l; } }

  private function line(): string {
    $l = fgets($this->s, 65536);
    if ($l === false) { $this->fail(); }
    return rtrim($l, "\r\n"); }

  private function read(int $n): string {
    $buf = '';
    while (strlen($buf) < $n) { $x = fread($this->s, min(65536, $n - strlen($buf))); if ($x === false || $x === '') { $this->fail(); } $buf .= $x; }
    return $buf; }

  private function fail(): never { $m = stream_get_meta_data($this->s); throw new \RuntimeException(!empty($m['timed_out']) ? 'The IMAP server did not answer in time.' : 'IMAP connection closed.'); }

  private static function q(string $s): string { return '"'.addcslashes($s, "\"\\").'"'; } }
