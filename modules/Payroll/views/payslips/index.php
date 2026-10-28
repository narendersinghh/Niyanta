<?php $max = $trend ? max(1.0, ...array_map(static fn ($t) => (float) $t['net'], $trend)) : 1.0; ?>
<?= partial('partials/page_head', ['title' => 'My Payslips', 'subtitle' => 'Your monthly salary statements, ready to view and download.', 'crumbs' => [['People'], ['My Payslips']]]) ?>
<?php if (!$p['total']): ?>
  <div class="card"><?= empty_state('No payslips yet', 'Your payslips will appear here as soon as payroll for a month is finalized. You will also get a notification.', 'money-wallet') ?></div>
<?php else: ?>
<div class="grid g-main mb">
  <div class="card hero pay-hero">
    <div>
      <span class="pill pill-peach">Latest · <?= e(date('F Y', strtotime($latest['period_month']))) ?></span>
      <div class="pay-hero-amt mt"><?= e(money($latest['net'])) ?></div>
      <p class="muted">Net pay credited for <?= e(date('F Y', strtotime($latest['period_month']))) ?></p>
      <div class="flex wrap"><a class="btn primary" href="<?= e(url('/payslips/'.$latest['id'])) ?>"><?= icon('eye', 'sm') ?> View payslip</a><a class="btn ghost" href="<?= e(url('/payslips/'.$latest['id'].'/pdf')) ?>"><?= icon('download', 'sm') ?> Download PDF</a></div>
    </div>
    <?= illus('money-wallet') ?>
  </div>
  <div class="card">
    <div class="card-head"><h3>Net pay trend</h3><span class="muted small">last <?= count($trend) ?> month<?= count($trend) === 1 ? '' : 's' ?></span></div>
    <div class="bars"><?php foreach ($trend as $i => $t): ?><div class="bar<?= $i === count($trend) - 1 ? ' hi' : '' ?>"><i style="height:<?= max(4, (int) round((float) $t['net'] / $max * 100)) ?>%" data-v="<?= e(number_format((float) $t['net'] / 1000, 1)) ?>k"></i><small><?= e(date('M', strtotime($t['period_month']))) ?></small></div><?php endforeach ?></div>
  </div>
</div>
<div class="grid g3 mb">
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('trend-up') ?></span><div><div class="num pay-num"><?= e(money($ytd['gross'])) ?></div><div class="lbl">Gross earned in <?= date('Y') ?></div></div></div>
  <div class="tile bg-pink"><span class="tile-ic"><?= icon('minus') ?></span><div><div class="num pay-num"><?= e(money($ytd['deductions'])) ?></div><div class="lbl">Deductions in <?= date('Y') ?></div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e(money($ytd['net'])) ?></div><div class="lbl">Net received · <?= (int) $ytd['n'] ?> payslip<?= (int) $ytd['n'] === 1 ? '' : 's' ?></div></div></div>
</div>
<div class="card">
  <div class="card-head"><h3>All payslips</h3></div>
  <div class="table-wrap"><table class="table pay-table">
    <thead><tr><th>Period</th><th>Paid for</th><th class="r">Gross</th><th class="r">Deductions</th><th class="r">Net pay</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $s): ?>
      <tr>
        <td><div class="flex"><span class="pay-dot soft-peach"><?= icon('file-text', 'sm') ?></span><a class="row-link" href="<?= e(url('/payslips/'.$s['id'])) ?>"><?= e(date('F Y', strtotime($s['period_month']))) ?></a></div></td>
        <td class="small muted"><?= $s['pay_type'] === 'hourly' ? e(number_format((float) $s['worked_hours'], 2)).' hours' : e(rtrim(rtrim(number_format((float) $s['paid_days'], 1), '0'), '.')).' of '.e(rtrim(rtrim(number_format((float) $s['working_days'], 1), '0'), '.')).' days' ?></td>
        <td class="r nowrap"><?= e(money($s['gross'])) ?></td>
        <td class="r nowrap"><?= e(money($s['total_deductions'])) ?></td>
        <td class="r nowrap"><b><?= e(money($s['net'])) ?></b></td>
        <td><?= badge($s['run_status'] === 'paid' ? 'paid' : 'finalized', $s['run_status'] === 'paid' ? 'Paid' : 'Published') ?></td>
        <td class="actions"><a class="btn icon sm ghost" href="<?= e(url('/payslips/'.$s['id'])) ?>" title="View"><?= icon('eye', 'sm') ?></a><a class="btn icon sm ghost" href="<?= e(url('/payslips/'.$s['id'].'/pdf')) ?>" title="Download PDF"><?= icon('download', 'sm') ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
</div>
<?php endif ?>
