<?php
namespace Core;

/**
 * Dependency-free PDF writer (A4, Helvetica, WinAnsi). Enough for invoices, quotations and payslips.
 * Coordinates are in points from the top-left corner.
 */
final class Pdf {
  private array $pages = [];
  private string $cur = '';
  private float $w = 595.28;
  private float $h = 841.89;
  private string $font = 'F1';
  private float $size = 10;

  public function __construct(private string $title = 'Document') { $this->addPage(); }

  public function addPage(): self { if ($this->cur !== '') { $this->pages[] = $this->cur; } $this->cur = ' '; return $this; }
  public function width(): float { return $this->w; }
  public function height(): float { return $this->h; }

  public function font(bool $bold = false, float $size = 10): self { $this->font = $bold ? 'F2' : 'F1'; $this->size = $size; return $this; }

  private static function color(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    return implode(' ', array_map(static fn ($i) => round(hexdec(substr($hex, $i, 2)) / 255, 3), [0, 2, 4])); }

  private static function esc(string $s): string {
    $c = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', str_replace(['₹', '€'], ['Rs.', 'EUR '], $s));
    $s = $c === false ? '' : $c;
    return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']); }

  /** Approximate Helvetica width (sufficient for right-alignment and wrapping). */
  public function textWidth(string $s): float {
    static $nw = [' ' => 278, '.' => 278, ',' => 278, ':' => 278, 'i' => 222, 'l' => 222, 'j' => 222, 'I' => 278, 'f' => 278, 't' => 278, 'r' => 333, '(' => 333, ')' => 333, '-' => 333, 'm' => 833, 'w' => 722, 'M' => 833, 'W' => 944];
    $u = 0;
    foreach (mb_str_split($s) as $ch) { $u += $nw[$ch] ?? (ctype_upper($ch) ? 667 : (ctype_digit($ch) ? 556 : 520)); }
    return $u * $this->size / 1000 * ($this->font === 'F2' ? 1.06 : 1); }

  public function text(float $x, float $y, string $s, string $color = '#111111', string $align = 'left'): self {
    if ($align === 'right') { $x -= $this->textWidth($s); }
    elseif ($align === 'center') { $x -= $this->textWidth($s) / 2; }
    $this->cur .= sprintf("BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", self::color($color), $this->font, $this->size, $x, $this->h - $y, self::esc($s));
    return $this; }

  /** Wrapped text block; returns the y after the last line. */
  public function paragraph(float $x, float $y, float $maxW, string $s, string $color = '#333333', float $lh = 1.4): float {
    foreach (preg_split('/\R/', $s) as $para) {
      $line = '';
      foreach (preg_split('/\s+/', $para) as $word) {
        $try = $line === '' ? $word : "$line $word";
        if ($this->textWidth($try) > $maxW && $line !== '') { $this->text($x, $y, $line, $color); $y += $this->size * $lh; $line = $word; }
        else { $line = $try; } }
      $this->text($x, $y, $line, $color);
      $y += $this->size * $lh; }
    return $y; }

  public function rect(float $x, float $y, float $w, float $h, string $fill = '#f2f2f5', ?string $stroke = null): self {
    $this->cur .= sprintf("%s rg %s %.2F %.2F %.2F %.2F re %s\n", self::color($fill), $stroke ? self::color($stroke).' RG' : '', $x, $this->h - $y - $h, $w, $h, $stroke ? 'B' : 'f');
    return $this; }

  public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#dddddd', float $wd = 0.6): self {
    $this->cur .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::color($color), $wd, $x1, $this->h - $y1, $x2, $this->h - $y2);
    return $this; }

  public function output(): string {
    $pages = [...$this->pages, $this->cur];
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $n = 5;
    foreach ($pages as $p) {
      $kids[] = $n.' 0 R';
      $objs[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$this->w.' '.$this->h.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($n + 1).' 0 R >>';
      $c = gzcompress($p);
      $objs[$n + 1] = '<< /Length '.strlen($c)." /Filter /FlateDecode >>\nstream\n".$c."\nendstream";
      $n += 2; }
    $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objs[$n] = '<< /Title ('.self::esc($this->title).') /Producer (Niyanta) /CreationDate (D:'.date('YmdHis').') >>';
    ksort($objs);
    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $off = [];
    foreach ($objs as $i => $o) { $off[$i] = strlen($out); $out .= "$i 0 obj\n$o\nendobj\n"; }
    $x = strlen($out);
    $out .= "xref\n0 ".($n + 1)."\n0000000000 65535 f \n";
    for ($i = 1; $i <= $n; $i++) { $out .= sprintf("%010d 00000 n \n", $off[$i] ?? 0); }
    return $out.'trailer << /Size '.($n + 1).' /Root 1 0 R /Info '.$n." 0 R >>\nstartxref\n$x\n%%EOF"; } }
