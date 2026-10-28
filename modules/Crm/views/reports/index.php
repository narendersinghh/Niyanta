<?php
use Modules\Crm\Services\Hooks;
$maxPair = max(1, ...array_map(static fn ($m) => max($m['invoiced'], $m['collected']), array_values($months) ?: [['invoiced' => 0, 'collected' => 0]]));
$maxCol = max(1, ...array_map(static fn ($m) => $m['collected'], array_values($months) ?: [['collected' => 0]]));
$rate = $tot['invoiced'] > 0 ? min(100, (int) round($tot['collected'] / $tot['invoiced'] * 100)) : 0;
$agingRows = [['b_current', 'Not yet due', 'mint'], ['b30', '1–30 days', 'lemon'], ['b60', '31–60 days', 'peach'], ['b90', '61–90 days', 'pink'], ['b90p', '90+ days', 'red']];
$agingTotal = max(0.01, array_sum(array_map('floatval', $aging)));
$topMax = $top ? max(1, (float) $top[0]['outstanding']) : 1;
$fyStart = (int) date('n') >= 4 ? date('Y-04-01') : date('Y-04-01', strtotime('-1 year'));
$presets = ['This month' => [date('Y-m-01'), date('Y-m-d')], 'Last 3 months' => [date('Y-m-01', strtotime('-2 months', strtotime(date('Y-m-01')))), date('Y-m-d')], 'Financial year' => [$fyStart, date('Y-m-d')], 'Last 12 months' => [date('Y-m-01', strtotime('-11 months', strtotime(date('Y-m-01')))), date('Y-m-d')]];
$curMonth = date('Y-m');
?>
<?= partial('partials/page_head', ['title' => 'Financial reports', 'subtitle' => 'Revenue, collections and receivables across '.(\Modules\Crm\Services\ClientAccess::unrestricted(user()) ? 'all clients' : 'your clients').'.', 'crumbs' => [['Finance'], ['Reports']], 'actions' => '<a class="btn ghost" href="'.e(url('/crm/reports/outstanding.csv')).'">'.icon('download', 'sm').' Outstanding CSV</a>']) ?>
<div class="card mb crm-range">
  <form class="filters mb0" method="get">
    <label class="small b" for="r-from">From</label><input id="r-from" type="date" name="from" value="<?= e($from) ?>">
    <label class="small b" for="r-to">To</label><input id="r-to" type="date" name="to" value="<?= e($to) ?>">
    <button class="btn sm primary"><?= icon('filter', 'sm') ?> Apply</button>
    <div class="chips grow" style="justify-content:flex-end"><?php foreach ($presets as $l => [$a, $b]): ?><a class="chip <?= $a === $from && $b === $to ? 'on' : '' ?>" href="<?= e(url('/crm/reports?from='.$a.'&to='.$b)) ?>"><?= e($l) ?></a><?php endforeach ?></div>
  </form>
</div>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('receipt') ?></span><div><div class="num"><?= e(Hooks::compact((float) $tot['invoiced'])) ?></div><div class="lbl">Invoiced · <?= (int) $tot['n'] ?> invoices</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('trend-up') ?></span><div><div class="num"><?= e(Hooks::compact((float) $tot['collected'])) ?></div><div class="lbl">Collected</div></div></div>
  <div class="tile dark"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num"><?= e(Hooks::compact((float) $tot['outstanding'])) ?></div><div class="lbl">Outstanding today</div></div></div>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('pie') ?></span><div><div class="num"><?= $rate ?>%</div><div class="lbl">Collection rate</div></div></div>
</div>
<div class="grid g2 mb">
  <div class="card">
    <div class="card-head"><div><h3>Revenue collected</h3><p class="xs muted mb0">Payments received per month</p></div><span class="pill pill-mint"><?= e(money($tot['collected'])) ?></span></div>
    <?php if (!$tot['collected']): ?><?= empty_state('No payments in this period', 'Record payments on invoices to see collections here.', 'reports-chart') ?><?php else: ?>
    <div class="bars crm-bars"><?php foreach ($months as $k => $mo): ?><div class="bar <?= $k === $curMonth ? 'hi' : 'crm-col' ?>" title="<?= e($mo['label'].': '.money($mo['collected'])) ?>"><i style="height:<?= round($mo['collected'] / $maxCol * 100, 1) ?>%"<?= $mo['collected'] > 0 ? ' data-v="'.e(Hooks::compact($mo['collected'])).'"' : '' ?>></i><small><?= e($mo['label']) ?></small></div><?php endforeach ?></div>
    <?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Invoiced vs collected</h3><p class="xs muted mb0">By invoice issue month vs payment month</p></div><div class="flex xs"><span class="dotc bg-lav"></span>Invoiced <span class="dotc bg-mint"></span>Collected</div></div>
    <?php if (!$tot['invoiced'] && !$tot['collected']): ?><?= empty_state('Nothing invoiced yet', 'Sent invoices in this period will appear here.', 'invoice-paper') ?><?php else: ?>
    <div class="crm-pairs"><?php foreach ($months as $mo): ?><div class="crm-pair" title="<?= e($mo['label'].' — invoiced '.money($mo['invoiced']).', collected '.money($mo['collected'])) ?>"><div class="crm-pair-bars"><i class="bg-lav" style="height:<?= round($mo['invoiced'] / $maxPair * 100, 1) ?>%"></i><i class="bg-mint" style="height:<?= round($mo['collected'] / $maxPair * 100, 1) ?>%"></i></div><small><?= e($mo['label']) ?></small></div><?php endforeach ?></div>
    <?php endif ?>
  </div>
</div>
<div class="grid g-main mb">
  <div class="card">
    <div class="card-head"><div><h3>Outstanding by client</h3><p class="xs muted mb0">Top 10 balances on sent, partial & overdue invoices</p></div><a class="btn sm ghost" href="<?= e(url('/crm/reports/outstanding.csv')) ?>"><?= icon('download', 'sm') ?> CSV</a></div>
    <?php if (!$top): ?><?= empty_state('All settled', 'No client owes you anything right now.', 'celebrate') ?><?php else: ?>
    <div class="list"><?php foreach ($top as $i => $t): ?>
      <a class="list-item" href="<?= e(url('/clients/'.$t['id'].'#finance')) ?>">
        <span class="crm-rank"><?= $i + 1 ?></span><?= avatar($t['company'] ?: $t['name'], null, 'sm') ?>
        <div class="grow"><div class="flex between"><b class="truncate"><?= e($t['company'] ?: $t['name']) ?></b><b class="nowrap"><?= e(money($t['outstanding'])) ?></b></div>
          <div class="progress mt crm-mini-progress"><span class="<?= (int) $t['overdue_n'] ? 'crm-bar-late' : '' ?>" style="width:<?= round((float) $t['outstanding'] / $topMax * 100, 1) ?>%"></span></div>
          <div class="meta"><?= (int) $t['n'] ?> open invoice<?= (int) $t['n'] === 1 ? '' : 's' ?><?= (int) $t['overdue_n'] ? ' · <span class="crm-late">'.(int) $t['overdue_n'].' overdue</span>' : '' ?><?= $t['oldest_due'] ? ' · oldest due '.e(fdate($t['oldest_due'])) : '' ?></div></div>
      </a>
    <?php endforeach ?></div>
    <?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Receivables aging</h3><p class="xs muted mb0">Days past due date, as of today</p></div></div>
    <div class="crm-stack-bar"><?php foreach ($agingRows as [$k, $l, $c]): $v = (float) $aging[$k]; if ($v <= 0) { continue; } ?><i class="crm-ag-<?= $c ?>" style="width:<?= round($v / $agingTotal * 100, 2) ?>%" title="<?= e($l.': '.money($v)) ?>"></i><?php endforeach ?></div>
    <div class="list mt"><?php foreach ($agingRows as [$k, $l, $c]): $v = (float) $aging[$k]; ?>
      <div class="list-item"><span class="dotc crm-ag-<?= $c ?>"></span><span class="grow small b"><?= e($l) ?></span><span class="xs muted"><?= $agingTotal > 0.01 ? round($v / $agingTotal * 100) : 0 ?>%</span><b class="small nowrap"><?= e(money($v)) ?></b></div>
    <?php endforeach ?></div>
  </div>
</div>
<?php if ($projOn): ?>
<div class="card">
  <div class="card-head"><div><h3>Project financials</h3><p class="xs muted mb0">Lifetime budget vs invoiced vs collected (sent invoices linked to a project)</p></div></div>
  <?php if (!$projects): ?><?= empty_state('No project billing yet', 'Link invoices to projects, or set project budgets, to compare them here.', 'kanban-board') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Project</th><th>Client</th><th class="right">Budget</th><th class="right">Invoiced</th><th class="right">Collected</th><th style="width:180px">Billed of budget</th></tr></thead>
    <tbody><?php foreach ($projects as $pr): $b = (float) $pr['budget']; $pct = $b > 0 ? (int) round((float) $pr['invoiced'] / $b * 100) : null; ?>
      <tr>
        <td><a class="row-link" href="<?= e(url('/projects/'.$pr['id'])) ?>"><?= e($pr['name']) ?></a> <?= badge($pr['status']) ?></td>
        <td class="small"><?= e($pr['client_company'] ?: $pr['client_name']) ?></td>
        <td class="right nowrap"><?= $b > 0 ? e(money($b)) : '<span class="muted">—</span>' ?></td>
        <td class="right nowrap"><?= e(money($pr['invoiced'])) ?></td>
        <td class="right nowrap crm-pos b"><?= e(money($pr['collected'])) ?></td>
        <td><?php if ($pct !== null): ?><div class="flex"><div class="progress grow"><span class="<?= $pct > 100 ? 'crm-bar-late' : '' ?>" style="width:<?= min(100, $pct) ?>%"></span></div><span class="xs b"><?= $pct ?>%</span></div><?php else: ?><span class="xs muted">No budget</span><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?php endif ?>
</div>
<?php endif ?>
