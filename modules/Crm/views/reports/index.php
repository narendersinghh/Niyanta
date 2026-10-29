<?php
use Modules\Crm\Services\{ClientAccess, Hooks, Ui};
$maxPair = max(1, ...array_map(static fn ($m) => max($m['invoiced'], $m['collected']), array_values($months) ?: [['invoiced' => 0, 'collected' => 0]]));
$maxCol = max(1, ...array_map(static fn ($m) => $m['collected'], array_values($months) ?: [['collected' => 0]]));
$rate = $tot['invoiced'] > 0 ? min(100, (int) round($tot['collected'] / $tot['invoiced'] * 100)) : 0;
$agingRows = [['b_current', 'Not yet due', 'mint'], ['b30', '1–30 days', 'lemon'], ['b60', '31–60 days', 'peach'], ['b90', '61–90 days', 'pink'], ['b90p', '90+ days', 'red']];
$agingSum = array_sum(array_map('floatval', $aging));
$agingTotal = max(0.01, $agingSum);
$topMax = $top ? max(1, (float) $top[0]['outstanding']) : 1;
$fyStart = (int) date('n') >= 4 ? date('Y-04-01') : date('Y-04-01', strtotime('-1 year'));
$presets = ['This month' => [date('Y-m-01'), date('Y-m-d')], 'Last 3 months' => [date('Y-m-01', strtotime('-2 months', strtotime(date('Y-m-01')))), date('Y-m-d')], 'Financial year' => [$fyStart, date('Y-m-d')], 'Last 12 months' => [date('Y-m-01', strtotime('-11 months', strtotime(date('Y-m-01')))), date('Y-m-d')]];
$curMonth = date('Y-m');
$clientsLink = can('crm.clients.view');
$more = Ui::more([can('crm.finance.view') ? Ui::link('/invoices', 'receipt', 'Invoices') : '', can('crm.finance.view') ? Ui::link('/invoices?status=overdue', 'alert', 'Overdue invoices') : '']);
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => 'Financial reports', 'subtitle' => 'Revenue, collections and receivables across '.(ClientAccess::unrestricted(user()) ? 'all clients' : 'your clients').' · '.fdate($from).' – '.fdate($to), 'actions' => $more.'<a class="btn primary" href="'.e(url('/crm/reports/outstanding.csv')).'">'.icon('download', 'sm').' Export outstanding</a>']) ?>
<div class="card crm-range">
  <form method="get">
    <label class="small b" for="r-from">From</label><input id="r-from" type="date" name="from" value="<?= e($from) ?>">
    <label class="small b" for="r-to">To</label><input id="r-to" type="date" name="to" value="<?= e($to) ?>">
    <button class="btn sm soft"><?= icon('filter', 'sm') ?> Apply</button>
  </form>
  <div class="chips"><?php foreach ($presets as $l => [$a, $b]): ?><a class="chip <?= $a === $from && $b === $to ? 'on' : '' ?>" href="<?= e(url('/crm/reports?from='.$a.'&to='.$b)) ?>"><?= e($l) ?></a><?php endforeach ?></div>
</div>
<div class="grid g4 mb">
  <?= Ui::tile('lav', 'receipt', Hooks::compact((float) $tot['invoiced']), 'Invoiced · '.(int) $tot['n'].' invoice'.((int) $tot['n'] === 1 ? '' : 's'), null, money($tot['invoiced'])) ?>
  <?= Ui::tile('mint', 'trend-up', Hooks::compact((float) $tot['collected']), 'Collected in period', null, money($tot['collected'])) ?>
  <?= Ui::tile('peach', 'wallet', Hooks::compact((float) $tot['outstanding']), 'Outstanding today', can('crm.finance.view') ? '/invoices?status=overdue' : null, money($tot['outstanding'])) ?>
  <?= Ui::tile('sky', 'pie', $rate.'%', 'Collection rate') ?>
</div>
<div class="grid g2 mb">
  <div class="card">
    <div class="card-head"><div><h3>Revenue collected</h3><p class="xs muted mb0">Payments received per month</p></div><span class="pill pill-mint"><?= e(money($tot['collected'])) ?></span></div>
    <?php if (!$tot['collected']): ?><?= empty_state('No payments in this period', 'Record payments on invoices to see collections here.', 'reports-chart') ?><?php else: ?>
    <div class="crm-bars"><?php foreach ($months as $k => $mo): ?><div class="bar <?= $k === $curMonth ? 'hi' : '' ?>" title="<?= e($mo['label'].': '.money($mo['collected'])) ?>"><i style="height:<?= round($mo['collected'] / $maxCol * 100, 1) ?>%"<?= $mo['collected'] > 0 ? ' data-v="'.e(Hooks::compact($mo['collected'])).'"' : '' ?>></i><small><?= e($mo['label']) ?></small></div><?php endforeach ?></div>
    <?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Invoiced vs collected</h3><p class="xs muted mb0">By issue month vs payment month</p></div><div class="crm-legend"><span><i style="background:var(--lav)"></i>Invoiced</span><span><i style="background:var(--mint)"></i>Collected</span></div></div>
    <?php if (!$tot['invoiced'] && !$tot['collected']): ?><?= empty_state('Nothing invoiced yet', 'Sent invoices in this period will appear here.', 'invoice-paper') ?><?php else: ?>
    <div class="crm-pairs"><?php foreach ($months as $mo): ?><div class="crm-pair" title="<?= e($mo['label'].' — invoiced '.money($mo['invoiced']).', collected '.money($mo['collected'])) ?>"><div class="crm-pair-bars"><i class="pi" style="height:<?= round($mo['invoiced'] / $maxPair * 100, 1) ?>%"></i><i class="pc" style="height:<?= round($mo['collected'] / $maxPair * 100, 1) ?>%"></i></div><small><?= e($mo['label']) ?></small></div><?php endforeach ?></div>
    <?php endif ?>
  </div>
</div>
<div class="grid g-main mb">
  <div class="card">
    <div class="card-head"><div><h3>Outstanding by client</h3><p class="xs muted mb0">Top 10 balances on sent, partial and overdue invoices</p></div></div>
    <?php if (!$top): ?><?= empty_state('All settled', 'No client owes you anything right now.', 'money-wallet') ?><?php else: ?>
    <div class="list"><?php foreach ($top as $i => $t): $tag = $clientsLink ? 'a' : 'div'; ?>
      <<?= $tag ?> class="list-item"<?= $clientsLink ? ' href="'.e(url('/clients/'.$t['id'].'#finance')).'"' : '' ?>>
        <span class="crm-rank"><?= $i + 1 ?></span><?= avatar($t['company'] ?: $t['name'], null, 'sm') ?>
        <div class="grow"><div class="flex between"><b class="truncate"><?= e($t['company'] ?: $t['name']) ?></b><b class="amt"><?= e(money($t['outstanding'])) ?></b></div>
          <div class="progress crm-mini-progress" style="max-width:none"><span class="<?= (int) $t['overdue_n'] ? 'crm-bar-late' : '' ?>" style="width:<?= round((float) $t['outstanding'] / $topMax * 100, 1) ?>%"></span></div>
          <div class="meta"><?= (int) $t['n'] ?> open invoice<?= (int) $t['n'] === 1 ? '' : 's' ?><?= (int) $t['overdue_n'] ? ' · <span class="crm-late">'.(int) $t['overdue_n'].' overdue</span>' : '' ?><?= $t['oldest_due'] ? ' · oldest due '.e(fdate($t['oldest_due'])) : '' ?></div></div>
      </<?= $tag ?>>
    <?php endforeach ?></div>
    <?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><div><h3>Receivables aging</h3><p class="xs muted mb0">Days past due date, as of today</p></div></div>
    <div class="crm-stack-bar"><?php foreach ($agingRows as [$k, $l, $c]): $v = (float) $aging[$k]; if ($v <= 0) { continue; } ?><i class="crm-ag-<?= $c ?>" style="width:<?= round($v / $agingTotal * 100, 2) ?>%" title="<?= e($l.': '.money($v)) ?>"></i><?php endforeach ?></div>
    <div class="list mt"><?php foreach ($agingRows as [$k, $l, $c]): $v = (float) $aging[$k]; ?>
      <div class="list-item"><span class="dotc crm-ag-<?= $c ?>"></span><span class="grow small b"><?= e($l) ?></span><span class="xs muted"><?= $agingSum > 0 ? round($v / $agingTotal * 100) : 0 ?>%</span><b class="small amt"><?= e(money($v)) ?></b></div>
    <?php endforeach ?></div>
  </div>
</div>
<?php if ($projOn): ?>
<div class="card crm-list">
  <div class="crm-bar"><div class="grow"><h3 class="mb0">Project financials</h3><div class="crm-sub">Lifetime budget vs invoiced vs collected (sent invoices linked to a project)</div></div></div>
  <?php if (!$projects): ?><?= empty_state('No project billing yet', 'Link invoices to projects, or set project budgets, to compare them here.', 'reports-chart') ?><?php else: ?>
  <div class="table-wrap"><table class="table crm-rt">
    <thead><tr><th>Project</th><th>Client</th><th class="amt">Budget</th><th class="amt">Invoiced</th><th class="amt">Collected</th><th style="width:190px">Billed of budget</th></tr></thead>
    <tbody><?php foreach ($projects as $pr): $b = (float) $pr['budget']; $pct = $b > 0 ? (int) round((float) $pr['invoiced'] / $b * 100) : null; ?>
      <tr>
        <td class="rt-main"><?= can('projects.view') ? '<a class="b" href="'.e(url('/projects/'.$pr['id'])).'">'.e($pr['name']).'</a>' : '<b>'.e($pr['name']).'</b>' ?> <?= badge($pr['status']) ?></td>
        <td class="small" data-l="Client"><?= e($pr['client_company'] ?: $pr['client_name']) ?></td>
        <td class="amt" data-l="Budget"><?= $b > 0 ? e(money($b)) : '<span class="muted">—</span>' ?></td>
        <td class="amt" data-l="Invoiced"><?= e(money($pr['invoiced'])) ?></td>
        <td class="amt crm-pos b" data-l="Collected"><?= e(money($pr['collected'])) ?></td>
        <td data-l="Billed"><?php if ($pct !== null): ?><div class="flex"><div class="progress grow"><span class="<?= $pct > 100 ? 'crm-bar-late' : '' ?>" style="width:<?= min(100, $pct) ?>%"></span></div><span class="xs b"><?= $pct ?>%</span></div><?php else: ?><span class="xs muted">No budget</span><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?php endif ?>
</div>
<?php endif ?>
</div>
