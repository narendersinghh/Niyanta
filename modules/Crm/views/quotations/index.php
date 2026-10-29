<?php
$m = can('crm.quotations.manage');
$n = static fn ($s) => (int) ($stats[$s]['n'] ?? 0);
$val = static fn ($s) => (float) ($stats[$s]['v'] ?? 0);
$open = $val('draft') + $val('sent');
$decided = $n('accepted') + $n('rejected');
$win = $decided ? (int) round($n('accepted') / $decided * 100) : 0;
$filtered = ($_GET['q'] ?? '') !== '' || !empty($_GET['status']) || !empty($_GET['client_id']);
?>
<?= partial('partials/page_head', ['title' => 'Quotations', 'subtitle' => 'Proposals and estimates sent to clients.', 'crumbs' => [['Finance'], ['Quotations']], 'actions' => $m ? '<a class="btn primary" href="'.e(url('/quotations/create')).'">'.icon('plus', 'sm').' New quotation</a>' : '']) ?>
<div class="grid g4 mb">
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('send') ?></span><div><div class="num"><?= $n('sent') + $n('draft') ?></div><div class="lbl">Open · <?= e(money($open)) ?></div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= $n('accepted') ?></div><div class="lbl">Accepted · <?= e(money($val('accepted'))) ?></div></div></div>
  <div class="tile bg-pink"><span class="tile-ic"><?= icon('x') ?></span><div><div class="num"><?= $n('rejected') + $n('expired') ?></div><div class="lbl">Rejected / expired</div></div></div>
  <div class="tile dark"><span class="tile-ic"><?= icon('target') ?></span><div><div class="num"><?= $win ?>%</div><div class="lbl">Win rate</div></div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Number, title or client" class="grow">
    <select name="status" data-autosubmit><option value="">Any status</option><?php foreach (\Modules\Crm\Services\Finance::QUOTE_STATUSES as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= e(ucfirst($s)) ?></option><?php endforeach ?></select>
    <select name="client_id" data-autosubmit><option value="">All clients</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $_GET['client_id'] ?? '') ?>><?= e($cn) ?></option><?php endforeach ?></select>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn sm soft" href="<?= e(url('/quotations')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?>
    <?= empty_state($filtered ? 'No quotations match' : 'No quotations yet', $filtered ? 'Try different filters.' : 'Create an itemised quotation and convert it to an invoice once accepted.', 'money-wallet', $m && !$filtered ? '<a class="btn primary mt" href="'.e(url('/quotations/create')).'">'.icon('plus', 'sm').' New quotation</a>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Number</th><th>Client</th><th>Issued</th><th>Valid until</th><th class="right">Total</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $q): ?>
      <tr>
        <td><a class="row-link" href="<?= e(url('/quotations/'.$q['id'])) ?>"><?= e($q['number']) ?></a><div class="xs muted"><?= e(str_limit($q['title'] ?? '', 40)) ?></div></td>
        <td><a class="flex" href="<?= e(url('/clients/'.$q['client_id'])) ?>"><?= avatar($q['client_company'] ?: $q['client_name'], null, 'sm') ?><span class="small b"><?= e($q['client_company'] ?: $q['client_name']) ?></span></a></td>
        <td class="small"><?= e(fdate($q['issue_date'])) ?></td>
        <td class="small <?= $q['valid_until'] && $q['valid_until'] < date('Y-m-d') && in_array($q['status'], ['draft', 'sent'], true) ? 'crm-late' : '' ?>"><?= e(fdate($q['valid_until'])) ?></td>
        <td class="right nowrap b"><?= e(money($q['total'])) ?></td>
        <td><?= badge($q['status']) ?><?= $q['invoice_id'] ? ' <span class="pill pill-mint">'.icon('receipt', 'sm').' Invoiced</span>' : '' ?></td>
        <td class="actions"><a class="btn icon sm ghost" href="<?= e(url('/quotations/'.$q['id'])) ?>" title="Open"><?= icon('eye', 'sm') ?></a> <a class="btn icon sm ghost" href="<?= e(url('/quotations/'.$q['id'].'/pdf')) ?>" title="PDF"><?= icon('download', 'sm') ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
