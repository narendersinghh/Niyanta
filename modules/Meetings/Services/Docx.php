<?php
namespace Modules\Meetings\Services;

/** Text of a Word .docx (Google Meet transcripts, Gemini notes, Teams downloads). Uses ZipArchive, or a small pure-PHP ZIP reader when the extension is missing. */
final class Docx {
  private const MAX = 20971520;

  /** ['text', 'title'] (empty text when the file is not a readable docx). */
  public static function read(string $bin): array {
    $xml = self::entry($bin, 'word/document.xml');
    $core = self::entry($bin, 'docProps/core.xml');
    $title = $core && preg_match('#<dc:title>([^<]+)</dc:title>#', $core, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : null;
    return ['text' => $xml ? self::xmlText($xml) : '', 'title' => $title ?: null]; }

  /** Paragraphs, tabs and line breaks of WordprocessingML as plain text. */
  public static function xmlText(string $xml): string {
    $x = preg_replace(['#<w:tab\s*/>#', '#<w:(br|cr)\b[^>]*/>#', '#</w:p>#'], ["\t", "\n", "\n"], $xml) ?? $xml;
    $x = preg_replace('#<w:instrText\b[^>]*>.*?</w:instrText>#s', '', $x) ?? $x;
    $t = html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $t = implode("\n", array_map('rtrim', explode("\n", $t)));
    return trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? $t); }

  /** One file of a ZIP archive held in memory, or null. */
  public static function entry(string $bin, string $name, bool $native = true): ?string {
    if ($native && class_exists(\ZipArchive::class)) {
      $tmp = tempnam(sys_get_temp_dir(), 'nyx');
      if ($tmp !== false && file_put_contents($tmp, $bin) !== false) {
        try {
          $z = new \ZipArchive();
          if ($z->open($tmp) === true) {
            $st = $z->statName($name);
            $out = $st && $st['size'] <= self::MAX ? $z->getFromName($name) : false;
            $z->close();
            return $out === false ? null : $out; } }
        finally { @unlink($tmp); } } }
    return self::pure($bin, $name); }

  /** Pure-PHP reader: central directory lookup, stored (0) or deflated (8) entries. */
  public static function pure(string $bin, string $name): ?string {
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false || strlen($bin) < $eocd + 22) { return null; }
    $e = unpack('vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr($bin, $eocd + 4, 16));
    $p = (int) $e['offset'];
    for ($i = 0; $i < (int) $e['total'] && substr($bin, $p, 4) === "PK\x01\x02"; $i++) {
      $c = unpack('vmethod/Vtime/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen', substr($bin, $p + 10, 24));
      $local = unpack('Voff', substr($bin, $p + 42, 4))['off'];
      $n = substr($bin, $p + 46, $c['nlen']);
      $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
      if ($n !== $name) { continue; }
      if ($c['usize'] > self::MAX || substr($bin, $local, 4) !== "PK\x03\x04") { return null; }
      $l = unpack('vnlen/vxlen', substr($bin, $local + 26, 4));
      $data = substr($bin, $local + 30 + $l['nlen'] + $l['xlen'], $c['csize']);
      if ($c['method'] === 0) { return $data; }
      if ($c['method'] === 8) { $out = @gzinflate($data, self::MAX); return $out === false ? null : $out; }
      return null; }
    return null; } }
