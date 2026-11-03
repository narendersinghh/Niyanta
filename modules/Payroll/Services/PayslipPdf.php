<?php
namespace Modules\Payroll\Services;
use Core\Pdf;

/** Renders a payslip row (joined with employee + run data) into a professional A4 PDF. */
final class PayslipPdf {
  public static function render(array $s): string {
    $company = company_name();
    $period = PayrollService::monthLabel($s['period_month']);
    $earn = PayrollService::earningsFor($s);
    $ded = json_decode((string) $s['deductions'], true) ?: [];
    $pdf = new Pdf("Payslip $period - ".$s['name']);
    $W = $pdf->width(); $L = 40; $R = $W - 40;
    $pdf->rect(0, 0, $W, 104, '#16161d');
    $pdf->rect(0, 104, $W, 5, '#c8b6ff');
    $pdf->font(true, 20)->text($L, 50, $company, '#ffffff');
    $pdf->font(false, 9.5)->text($L, 70, 'Salary statement', '#a3a3b2');
    $pdf->font(true, 11)->text($R, 46, 'PAYSLIP', '#c8b6ff', 'right');
    $pdf->font(true, 16)->text($R, 68, $period, '#ffffff', 'right');
    $pdf->font(false, 8.5)->text($R, 86, 'Slip #'.str_pad((string) $s['id'], 6, '0', STR_PAD_LEFT), '#a3a3b2', 'right');
    $y = 134;
    $pdf->rect($L, $y, $R - $L, 108, '#f4f1ff');
    $left = [['Employee', $s['name']], ['Employee code', $s['employee_code'] ?: '-'], ['Designation', $s['designation'] ?: '-'], ['Department', $s['department'] ?: '-']];
    $right = $s['pay_type'] === 'hourly'
      ? [['Pay period', $period], ['Pay type', 'Hourly'], ['Hourly rate', money($s['rate'])], ['Hours worked', number_format((float) $s['worked_hours'], 2).' h']]
      : [['Pay period', $period], ['Working days', self::num($s['working_days'])], ['Paid days', self::num($s['paid_days'])], ...(feature_on('leave') || (float) $s['unpaid_leave_days'] > 0 ? [['Unpaid leave (LOP)', self::num($s['unpaid_leave_days'])]] : [])];
    foreach ([[$left, $L + 16], [$right, $L + 272]] as [$rows, $x]) {
      $yy = $y + 24;
      foreach ($rows as [$k, $v]) { $pdf->font(false, 8.5)->text($x, $yy, strtoupper($k), '#7b7b8c'); $pdf->font(true, 10)->text($x + 100, $yy, mb_substr((string) $v, 0, 34), '#16161d'); $yy += 22; } }
    $y += 136;
    $colW = ($R - $L - 16) / 2;
    if ($hrs = PayrollService::hoursLines($s)) {
      $rows = (int) ceil(count($hrs) / 2);
      $pdf->rect($L, $y, $R - $L, 26, '#f4f1ff');
      $pdf->font(true, 10)->text($L + 12, $y + 17, 'Hours & rate', '#4b3be8');
      $pdf->font(true, 8.5)->text($R - 12, $y + 17, $s['pay_type'] === 'hourly' ? 'PAID BY THE HOUR' : 'SALARY FROM TRACKED TIME', '#4b3be8', 'right');
      foreach (array_values($hrs) as $i => [$k, $v]) {
        $x = $L + ($i % 2) * ($colW + 16); $yy = $y + 26 + intdiv($i, 2) * 22;
        $pdf->font(false, 9)->text($x + 12, $yy + 15, $k, '#5e5e6e');
        $pdf->font(true, 9.5)->text($x + $colW - 12, $yy + 15, $v, '#16161d', 'right');
        $pdf->line($x, $yy + 22, $x + $colW, $yy + 22, '#eaeaf0'); }
      $y += 26 + $rows * 22 + 20; }
    $endE = self::table($pdf, $L, $y, $colW, 'Earnings', $earn, 'Gross earnings', (float) $s['gross'], '#e2f8ee', '#13795b');
    $endD = self::table($pdf, $L + $colW + 16, $y, $colW, 'Deductions', $ded, 'Total deductions', (float) $s['total_deductions'], '#ffeaea', '#c22b2b');
    $y = max($endE, $endD) + 26;
    $pdf->rect($L, $y, $R - $L, 78, '#e2f8ee');
    $pdf->rect($L, $y, 5, 78, '#13795b');
    $pdf->font(true, 10)->text($L + 22, $y + 26, 'NET PAY', '#13795b');
    $pdf->font(false, 9)->paragraph($L + 22, $y + 46, 300, PayrollService::inWords((float) $s['net']), '#0c3325', 1.35);
    $pdf->font(true, 24)->text($R - 20, $y + 46, money($s['net']), '#0c3325', 'right');
    $y += 100;
    if ((float) $s['total_deductions'] > (float) $s['gross']) { $pdf->font(false, 8.5)->text($L, $y, 'Note: deductions exceeded gross earnings for this period; net pay has been capped at zero.', '#c22b2b'); $y += 18; }
    if (!empty($s['bank_name'])) { $pdf->font(false, 9)->text($L, $y, 'Paid to: '.$s['bank_name'].(!empty($s['bank_ifsc']) ? ' (IFSC '.$s['bank_ifsc'].')' : ''), '#5e5e6e'); }
    $fy = $pdf->height() - 50;
    $foot = trim((string) setting('payroll.payslip_footer', ''));
    if ($foot !== '') { $pdf->font(false, 8.5)->paragraph($L, $fy - 80, $R - $L, mb_substr(preg_replace('/\s+/', ' ', $foot), 0, 400), '#5e5e6e'); }
    $pdf->line($L, $fy - 16, $R, $fy - 16, '#eaeaf0');
    $pdf->font(false, 8)->text($L, $fy, 'This is a computer-generated payslip and does not require a signature.', '#7b7b8c');
    $pdf->font(false, 8)->text($R, $fy, 'Generated '.date('d M Y, h:i A'), '#7b7b8c', 'right');
    return $pdf->output(); }

  /** Day counts carry a unit (also avoids a bare "0", which Core\\Pdf::esc() would drop as falsy). */
  private static function num(mixed $v): string { $n = (float) $v; return ($n == floor($n) ? (string) (int) $n : number_format($n, 1)).($n == 1.0 ? ' day' : ' days'); }

  /** Draws a two-column amount table and returns the y below it. */
  private static function table(Pdf $pdf, float $x, float $y, float $w, string $title, array $rows, string $totalLabel, float $total, string $fill, string $ink): float {
    $pdf->rect($x, $y, $w, 26, $fill);
    $pdf->font(true, 10)->text($x + 12, $y + 17, $title, $ink);
    $pdf->font(true, 8.5)->text($x + $w - 12, $y + 17, 'AMOUNT', $ink, 'right');
    $y += 26;
    if (!$rows) { $pdf->font(false, 9.5)->text($x + 12, $y + 18, 'None', '#7b7b8c'); $y += 28; }
    foreach ($rows as $r) {
      $pdf->font(false, 9.5)->text($x + 12, $y + 18, mb_substr((string) $r['name'], 0, 38), '#16161d');
      $pdf->font(false, 9.5)->text($x + $w - 12, $y + 18, money($r['amount']), '#16161d', 'right');
      $y += 28;
      $pdf->line($x, $y, $x + $w, $y, '#eaeaf0'); }
    $pdf->rect($x, $y, $w, 30, '#f6f6fa');
    $pdf->font(true, 10)->text($x + 12, $y + 19, $totalLabel, '#16161d');
    $pdf->font(true, 10)->text($x + $w - 12, $y + 19, money($total), '#16161d', 'right');
    return $y + 30; } }
