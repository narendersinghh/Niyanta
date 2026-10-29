<?php
namespace Modules\Crm\Services;
use Core\Pdf;

/** Branded A4 PDF for invoices & quotations built on Core\Pdf: indigo header band with coral accent, deep-indigo table head, totals, notes. */
final class DocumentPdf {
  private const L = 40.0;
  private const R = 555.0;
  private const ACCENT = '#FF6F59';
  private const INK = '#1E1A4A';

  public static function render(string $kind, array $doc, array $items, array $client, array $payments = []): string {
    $isInv = $kind === 'invoice';
    $app = (string) setting('branding.app_name', 'Niyanta');
    $brand = self::hex((string) (setting('appearance.primary_color') ?: setting('branding.primary_color', '#5B4CF5')), '#5B4CF5');
    $pdf = new Pdf(($isInv ? 'Invoice ' : 'Quotation ').$doc['number']);
    $money = static fn ($v) => money($v);
    self::band($pdf, $brand, $app, $isInv ? 'INVOICE' : 'QUOTATION', $doc['number']);
    // From / bill to / meta
    $y = 150;
    $pdf->font(true, 8)->text(self::L, $y, 'BILL TO', '#74728c');
    $pdf->font(true, 12)->text(self::L, $y + 18, (string) ($client['company'] ?: $client['name']), '#17162e');
    $yy = $y + 34;
    $pdf->font(false, 9.5);
    $lines = array_filter([$client['company'] ? 'Attn: '.$client['name'] : null, $client['address'] ?? null, trim(implode(', ', array_filter([$client['city'] ?? '', $client['state'] ?? '', $client['postal_code'] ?? '']))), $client['country'] ?? null, $client['email'] ?? null, $client['phone'] ?? null, !empty($client['tax_id']) ? 'Tax ID: '.$client['tax_id'] : null]);
    foreach ($lines as $l) { foreach (self::wrap($pdf, (string) $l, 250) as $w) { $pdf->text(self::L, $yy, $w, '#44444f'); $yy += 13; } }
    $from = array_filter([(string) setting('company.address', ''), (string) setting('company.email', ''), (string) setting('company.phone', ''), ($t = (string) setting('company.tax_id', '')) ? 'Tax ID: '.$t : '']);
    $meta = $isInv
      ? ['Issue date' => fdate($doc['issue_date']), 'Due date' => fdate($doc['due_date']), 'Status' => ucwords(str_replace('_', ' ', $doc['status']))]
      : ['Issue date' => fdate($doc['issue_date']), 'Valid until' => fdate($doc['valid_until']), 'Status' => ucwords($doc['status'])];
    if (!empty($doc['project_name'])) { $meta['Project'] = $doc['project_name']; }
    $my = $y;
    $pdf->font(true, 8)->text(340, $my, 'DETAILS', '#74728c');
    $my += 18;
    foreach ($meta as $k => $v) { $pdf->font(false, 9.5)->text(340, $my, $k, '#74728c'); $pdf->font(true, 9.5)->text(self::R - 6, $my, str_limit((string) $v, 34), '#17162e', 'right'); $my += 15; }
    if ($from) { $my += 6; $pdf->font(true, 8)->text(340, $my, 'FROM', '#74728c'); $my += 14; $pdf->font(false, 9); foreach ($from as $f) { foreach (self::wrap($pdf, $f, 215) as $w) { $pdf->text(340, $my, $w, '#44444f'); $my += 12; } } }
    $y = max($yy, $my) + 18;
    if (!empty($doc['title'])) { $pdf->font(true, 11)->text(self::L, $y, (string) $doc['title'], '#17162e'); $y += 18; }
    // Items table
    $y = self::tableHead($pdf, $y, $brand);
    $n = 0;
    foreach ($items as $it) {
      $pdf->font(false, 9.5);
      $desc = self::wrap($pdf, (string) $it['description'], 232);
      $h = max(22, count($desc) * 12 + 10);
      if ($y + $h > 760) { self::footer($pdf, $app); $pdf->addPage(); $y = self::tableHead($pdf, 50, $brand); $pdf->font(false, 9.5); }
      if ($n++ % 2) { $pdf->rect(self::L, $y, self::R - self::L, $h, '#f6f6fb'); }
      $pdf->text(self::L + 8, $y + 15, (string) $n, '#74728c');
      $ly = $y + 15;
      foreach ($desc as $d) { $pdf->text(self::L + 30, $ly, $d, '#17162e'); $ly += 12; }
      $pdf->text(355, $y + 15, rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.'), '#17162e', 'right');
      $pdf->text(435, $y + 15, money($it['unit_price'], false), '#17162e', 'right');
      $pdf->text(478, $y + 15, rtrim(rtrim(number_format((float) $it['tax_rate'], 2, '.', ''), '0'), '.').'%', '#44444f', 'right');
      $pdf->font(true, 9.5)->text(self::R - 8, $y + 15, money($it['amount'], false), '#17162e', 'right');
      $y += $h; }
    $pdf->line(self::L, $y, self::R, $y, '#dcdce6', 0.8);
    // Totals
    $rows = [['Subtotal', $money($doc['subtotal'])], ['Tax', $money($doc['tax_total'])]];
    if ((float) $doc['discount'] > 0) { $rows[] = ['Discount', '- '.$money($doc['discount'])]; }
    $need = count($rows) * 18 + 40 + ($isInv ? 60 : 0);
    if ($y + $need > 770) { self::footer($pdf, $app); $pdf->addPage(); $y = 50; }
    $ty = $y + 20;
    foreach ($rows as [$k, $v]) { $pdf->font(false, 10)->text(350, $ty, $k, '#55555f'); $pdf->text(self::R - 8, $ty, $v, '#17162e', 'right'); $ty += 18; }
    $pdf->rect(340, $ty - 6, self::R - 340, 30, $brand);
    $pdf->font(true, 12)->text(350, $ty + 13, 'Total', '#ffffff')->text(self::R - 8, $ty + 13, $money($doc['total']), '#ffffff', 'right');
    $ty += 40;
    if ($isInv) {
      $bal = max(0, (float) $doc['total'] - (float) $doc['amount_paid']);
      $pdf->font(false, 10)->text(350, $ty, 'Amount paid', '#55555f')->text(self::R - 8, $ty, $money($doc['amount_paid']), '#0a8573', 'right');
      $ty += 10;
      $pdf->rect(340, $ty, self::R - 340, 28, $bal > 0 ? '#ffebe7' : '#ddf6f1');
      $pdf->font(true, 11)->text(350, $ty + 18, 'Balance due', $bal > 0 ? '#d4432c' : '#0a8573')->text(self::R - 8, $ty + 18, $money($bal), $bal > 0 ? '#d4432c' : '#0a8573', 'right');
      $ty += 40; }
    // Payments + notes/terms (left column, below the table)
    $ly = $y + 20;
    if ($isInv && $payments) {
      $pdf->font(true, 8)->text(self::L, $ly, 'PAYMENTS RECEIVED', '#74728c'); $ly += 14;
      foreach (array_slice($payments, 0, 6) as $p) { $pdf->font(false, 9)->text(self::L, $ly, fdate($p['paid_on']).'  ·  '.(Finance::METHODS[$p['method']] ?? $p['method']).($p['reference'] ? '  ·  '.str_limit($p['reference'], 24) : ''), '#44444f')->text(300, $ly, $money($p['amount']), '#0a8573', 'right'); $ly += 13; }
      $ly += 8; }
    $y = max($ly, $ty) + 4;
    foreach (['Notes' => $doc['notes'] ?? '', 'Terms & conditions' => $doc['terms'] ?? ''] as $label => $txt) {
      if (trim((string) $txt) === '') { continue; }
      $pdf->font(false, 9);
      $wl = [];
      foreach (preg_split('/\R/', (string) $txt) as $para) { array_push($wl, ...self::wrap($pdf, $para, self::R - self::L - 25)); }
      if ($y + 20 + min(count($wl), 8) * 12 > 780) { self::footer($pdf, $app); $pdf->addPage(); $y = 50; }
      $pdf->font(true, 8)->text(self::L, $y, strtoupper($label), '#74728c'); $y += 14;
      $pdf->font(false, 9);
      foreach ($wl as $w) { if ($y > 785) { self::footer($pdf, $app); $pdf->addPage(); $y = 50; $pdf->font(false, 9); } $pdf->text(self::L, $y, $w, '#44444f'); $y += 12; }
      $y += 10; }
    self::footer($pdf, $app);
    return $pdf->output(); }

  private static function band(Pdf $pdf, string $brand, string $app, string $label, string $number): void {
    $pdf->rect(0, 0, $pdf->width(), 118, $brand);
    $pdf->rect(0, 118, $pdf->width(), 5, self::ACCENT);
    $pdf->rect(self::L, 34, 34, 34, '#ffffff');
    $pdf->font(true, 18)->text(self::L + 17, 57, mb_strtoupper(mb_substr($app, 0, 1)), $brand, 'center');
    $pdf->font(true, 20)->text(self::L + 46, 50, $app, '#ffffff');
    $pdf->font(false, 9)->text(self::L + 46, 66, (string) setting('branding.tagline', ''), '#f1edff');
    $pdf->font(true, 24)->text(self::R, 56, $label, '#ffffff', 'right');
    $pdf->font(false, 11)->text(self::R, 76, '# '.$number, '#f1edff', 'right'); }

  private static function tableHead(Pdf $pdf, float $y, string $brand): float {
    $pdf->rect(self::L, $y, self::R - self::L, 24, self::INK);
    $pdf->font(true, 8.5);
    foreach ([[self::L + 8, '#', 'left'], [self::L + 30, 'DESCRIPTION', 'left'], [355, 'QTY', 'right'], [435, 'RATE', 'right'], [478, 'TAX', 'right'], [self::R - 8, 'AMOUNT', 'right']] as [$x, $t, $a]) { $pdf->text($x, $y + 15.5, $t, '#ffffff', $a); }
    $pdf->rect(self::L, $y + 24, self::R - self::L, 2, self::ACCENT);
    return $y + 26; }

  private static function footer(Pdf $pdf, string $app): void {
    $pdf->line(self::L, 805, self::R, 805, '#e4e4ec');
    $pdf->font(false, 8)->text(self::L, 820, 'Thank you for your business.', '#74728c')->text(self::R, 820, $app.'  ·  Generated '.date('d M Y'), '#74728c', 'right'); }

  private static function wrap(Pdf $pdf, string $s, float $maxW): array {
    $out = []; $line = '';
    foreach (preg_split('/\s+/', trim($s)) ?: [] as $word) {
      $try = $line === '' ? $word : "$line $word";
      if ($line !== '' && $pdf->textWidth($try) > $maxW) { $out[] = $line; $line = $word; }
      else { $line = $try; } }
    if ($line !== '') { $out[] = $line; }
    return $out ?: ['']; }

  private static function hex(string $c, string $d): string { return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : $d; } }
