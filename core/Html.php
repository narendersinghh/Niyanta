<?php
namespace Core;

/**
 * Allow-list HTML sanitizer for rich text written in the browser (project briefs). Never trust the client: every save goes through clean().
 * The input is parsed with DOMDocument (no network, no entity expansion) and rebuilt from scratch, so only listed tags and attributes survive:
 * links are http(s)/mailto or this app's own file URLs (always rel="noopener noreferrer" target="_blank"), images only this app's file URLs,
 * checklists are <ul data-checklist><li data-checked="true|false">, table cells keep small colspan/rowspan. Everything else is dropped or unwrapped.
 */
final class Html {
  public const MAX = 512000;
  private const TAGS = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img'];
  private const VOID = ['br', 'hr', 'img'];
  /** Dropped with everything inside them. */
  private const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'base', 'textarea', 'select', 'option', 'button', 'input', 'audio', 'video', 'canvas', 'picture', 'source', 'track', 'map', 'xml', 'colgroup', 'col', 'caption'];
  /** Containers turned into a paragraph when they hold only inline content (otherwise unwrapped). */
  private const BLOCKS = ['div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'figcaption', 'address', 'center', 'dd', 'dt', 'dl', 'form', 'fieldset', 'details', 'summary'];
  private const MAP = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'strike' => 's', 'ins' => 'u', 'tt' => 'code', 'kbd' => 'code', 'samp' => 'code', 'mark' => 'strong', 'tfoot' => 'tbody'];
  private const BLOCKISH = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr', 'table', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'dl', 'form', 'fieldset', 'details', 'address', 'center'];

  /** @var null|callable(int): bool */
  private static $fileOk = null;

  /**
   * Sanitize $html. $fileOk(int $fileId): bool decides which app files may be linked or shown as images (default: any file id; FileController still checks access).
   * Throws \LengthException when the input is larger than MAX bytes.
   */
  public static function clean(string $html, ?callable $fileOk = null): string {
    if (strlen($html) > self::MAX) { throw new \LengthException('The document is too large (max '.(int) (self::MAX / 1024).' KB of HTML).'); }
    $html = trim(mb_scrub(str_replace("\0", '', $html), 'UTF-8'));
    if ($html === '') { return ''; }
    self::$fileOk = $fileOk;
    $doc = new \DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="ny-rte-root">'.$html.'</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $ok ? $doc->getElementById('ny-rte-root') : null;
    $out = $root ? self::blocks($root, 0) : '';
    self::$fileOk = null;
    $out = trim((string) preg_replace("#^(?:<p>(?:[\\s\u{00A0}]|<br>)*</p>)+|(?:<p>(?:[\\s\u{00A0}]|<br>)*</p>)+\$#u", "", $out));
    return self::isEmpty($out) ? '' : $out; }

  /** True when the HTML has no visible text and no image, rule or table. */
  public static function isEmpty(string $html): bool {
    return !preg_match('#<(?:img|hr|table)\b#', $html) && trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === ''; }

  /** Plain text of sanitized HTML (blocks become line breaks), for excerpts and search. */
  public static function text(string $html): string {
    $t = preg_replace('#<(?:br|hr)\b[^>]*>|</(?:p|h[2-4]|li|blockquote|pre|tr)>#i', "\n", $html);
    $t = html_entity_decode(strip_tags((string) preg_replace('#</t[dh]>#i', ' ', (string) $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace(["/[ \t\u{00A0}]+/u", "/\n\s*\n+/"], [' ', "\n"], $t)); }

  public static function excerpt(string $html, int $n = 280): string { return str_limit(preg_replace('/\s+/u', ' ', self::text($html)), $n); }

  /** Plain text to safe paragraphs (blank lines split paragraphs, single newlines become <br>). */
  public static function paragraphs(string $text): string {
    $out = '';
    foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $p) { if (trim($p) !== '') { $out .= '<p>'.implode('<br>', array_map(static fn ($l) => e(trim($l)), preg_split('/\R/u', trim($p)))).'</p>'; } }
    return $out; }

  /** App file id from a URL of this app (/files/N or /files/N/view, relative or absolute on this host), else null. */
  public static function fileId(string $url): ?int {
    $url = trim($url);
    $p = parse_url($url);
    if ($p === false || isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) { return null; }
    if (isset($p['scheme']) || isset($p['host'])) {
      $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? parse_url((string) App::config('app_url', ''), PHP_URL_HOST) ?? ''));
      $h = strtolower(($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : ''));
      if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || $host === '' || ($h !== $host && strtolower($p['host'] ?? '') !== $host)) { return null; } }
    $base = preg_quote(Request::base(), '#');
    return preg_match('#^'.$base.'/files/([1-9]\d{0,9})(/view)?$#', (string) ($p['path'] ?? ''), $m) ? (int) $m[1] : null; }

  private static function fileAllowed(?int $id): bool { return $id !== null && (self::$fileOk === null || (self::$fileOk)($id)); }

  /** Children of the root or of an unwrapped container; loose inline runs between blocks are wrapped in a paragraph. */
  private static function blocks(\DOMNode $root, int $depth): string {
    $out = ''; $run = '';
    $flush = static function () use (&$out, &$run): void { $r = preg_replace('#(?:<br>)+$#', '', trim($run)); if (!self::isEmpty($r)) { $out .= '<p>'.$r.'</p>'; } $run = ''; };
    foreach ($root->childNodes as $c) {
      $h = self::node($c, $depth + 1);
      if (preg_match('#^<(?:p|h[2-4]|ul|ol|blockquote|pre|hr|table)\b#', $h)) { $flush(); $out .= $h; } else { $run .= $h; } }
    $flush();
    return $out; }

  private static function children(\DOMNode $n, int $depth): string {
    $s = '';
    foreach ($n->childNodes as $c) { $s .= self::node($c, $depth + 1); }
    return $s; }

  private static function node(\DOMNode $n, int $depth): string {
    if ($n instanceof \DOMText) { return htmlspecialchars($n->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    if (!$n instanceof \DOMElement) { return ''; }
    $tag = strtolower($n->nodeName);
    if (str_contains($tag, ':')) { return self::children($n, $depth); }
    if (in_array($tag, self::DROP, true)) { return ''; }
    if ($depth > 60) { return htmlspecialchars($n->textContent, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    $tag = self::MAP[$tag] ?? $tag;
    if (in_array($tag, self::BLOCKS, true)) {
      foreach ($n->childNodes as $c) { if ($c instanceof \DOMElement && in_array(strtolower($c->nodeName), self::BLOCKISH, true)) { return self::blocks($n, $depth); } }
      $inner = self::children($n, $depth);
      if (self::isEmpty($inner)) { return ''; }
      $parent = $n->parentNode instanceof \DOMElement ? strtolower($n->parentNode->nodeName) : '';
      return in_array($parent, ['li', 'td', 'th', 'p', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $inner.'<br>' : '<p>'.$inner.'</p>'; }
    if (!in_array($tag, self::TAGS, true)) { return self::children($n, $depth); }
    if (in_array($tag, ['p', 'h2', 'h3', 'h4'], true)) { foreach ($n->childNodes as $c) { if ($c instanceof \DOMElement && in_array(strtolower($c->nodeName), self::BLOCKISH, true)) { return self::blocks($n, $depth); } } }
    $attrs = '';
    switch ($tag) {
      case 'a':
        $href = trim($n->getAttribute('href'));
        $fid = self::fileId($href);
        if ($fid !== null) { if (!self::fileAllowed($fid)) { return self::children($n, $depth); } $href = url('/files/'.$fid.(str_ends_with(parse_url($href, PHP_URL_PATH) ?: '', '/view') ? '/view' : '')); }
        elseif (!self::safeHref($href)) { return self::children($n, $depth); }
        $attrs = ' href="'.e($href).'" rel="noopener noreferrer" target="_blank"';
        break;
      case 'img':
        $fid = self::fileId($n->getAttribute('src'));
        if (!self::fileAllowed($fid)) { return ''; }
        $alt = mb_substr(trim(preg_replace('/\s+/u', ' ', $n->getAttribute('alt'))), 0, 200);
        return '<img src="'.e(url('/files/'.$fid.'/view')).'" alt="'.e($alt).'">';
      case 'ul':
        if ($n->hasAttribute('data-checklist')) { $attrs = ' data-checklist'; }
        break;
      case 'li':
        if ($n->parentNode instanceof \DOMElement && $n->parentNode->hasAttribute('data-checklist')) { $attrs = ' data-checked="'.($n->getAttribute('data-checked') === 'true' ? 'true' : 'false').'"'; }
        break;
      case 'td': case 'th':
        foreach (['colspan', 'rowspan'] as $a) { $v = $n->getAttribute($a); if (ctype_digit($v) && (int) $v > 1 && (int) $v <= 20) { $attrs .= ' '.$a.'="'.(int) $v.'"'; } }
        break;
      case 'b': if (preg_match('/font-weight\s*:\s*(normal|[1-4]00)\b/i', $n->getAttribute('style'))) { return self::children($n, $depth); } break; }
    if (in_array($tag, self::VOID, true)) { return '<'.$tag.$attrs.'>'; }
    $inner = self::children($n, $depth);
    if ($inner === '' && in_array($tag, ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'code', 'ul', 'ol', 'table', 'thead', 'tbody', 'tr', 'p', 'h2', 'h3', 'h4', 'blockquote', 'pre'], true)) { return ''; }
    if (in_array($tag, ['h2', 'h3', 'h4'], true) && self::isEmpty($inner)) { return ''; }
    return '<'.$tag.$attrs.'>'.$inner.'</'.$tag.'>'; }

  /** http(s) URLs with a host, or mailto: with an address. No javascript:, data:, vbscript:, protocol-relative or relative URLs. */
  public static function safeHref(string $href): bool {
    if ($href === '' || strlen($href) > 2000 || preg_match('/[\x00-\x20\x7f<>"\\\\]/', $href)) { return false; }
    if (preg_match('#^mailto:[^@\s]+@[^@\s]+$#i', $href)) { return true; }
    $p = parse_url($href);
    return $p !== false && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && !empty($p['host']) && preg_match('#^https?://#i', $href) === 1; } }
