<?php
use Modules\Crm\Services\Hooks;
$m = can('crm.finance.manage');
$tabs = ['' => 'All', 'draft' => 'Draft', 'sent' => 'Sent', 'partial' => 'Partial', 'paid' => 'Paid', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled'];
$filtered = ($_GET['q'] ?? '') !== '' || !empty($_GET['client_id']) || !empty($_GET['from']) || !empty($_GET['to']);
$today = date('Y-m-d');
?>
<?= partial('partials/page_head', ['title' => 'Invoices', 'subtitle' => 'Bill clients, record payments and keep cash flowing.', 'crumbs' => [['Finance'], ['Invoices']], 'actions' => $m ? '<a class="btn primary" href="'.e(url('/invoices/create')).'">'.icon('plus', 'sm').' New invoice</a>' : '']) ?>
<div class="grid g4 mb">
  <div class="tile dark"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num"><?= e(Hooks::compact((float) $s['outstanding'])) ?></div><div class="lbl">Outstanding · <?= (int) $s['open_n'] ?> open</div></div></div>
  <a class="tile bg-peach" href="<?= e(url('/invoices?status=overdue')) ?>"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= e(Hooks::compact((float) $s['overdue'])) ?></div><div class="lbl">Overdue · <?= (int) $s['overdue_n'] ?> invoice<?= (int) $s['overdue_n'] === 1 ? '' : 's' ?></div></div></a>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('trend-up') ?></span><div><div class="num"><?= e(Hooks::compact((float) $s['collected'])) ?></div><div class="lbl">Collected in <?= e(date('F')) ?></div></div></div>
  <a class="tile bg-lemon" href="<?= e(url('/invoices?status=draft')) ?>"><span class="tile-ic"><?= icon('edit') ?></span><div><div class="num"><?= (int) $s['drafts'] ?></div><div class="lbl">Drafts to send</div></div></a>
</div>
<div class="card">
  <div class="flex between wrap mb">
    <div class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= e(query_merge(['status' => $k ?: null, 'page' => null])) ?>" class="<?= $status === $k || ($k === '' && !isset($tabs[$status])) ? 'active' : '' ?>"><?= e($l) ?><?php $c = $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0); if ($c): ?> <span class="crm-count"><?= $c ?></span><?php endif ?></a><?php endforeach ?></div>
  </div>
  <form class="filters" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Number, title or client" class="grow">
    <select name="client_id" data-autosubmit><option value="">All clients</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $_GET['client_id'] ?? '') ?>><?= e($cn) ?></option><?php endforeach ?></select>
    <input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>" aria-label="Issued from" title="Issued from">
    <input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>" aria-label="Issued to" title="Issued to">
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn sm soft" href="<?= e(url('/invoices'.($status !== '' ? '?status='.rawurlencode($status) : ''))) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?>
    <?= empty_state($filtered || $status !== '' ? 'No invoices here' : 'No invoices yet', $filtered || $status !== '' ? 'Nothing matches this view right now.' : 'Create your first invoice — or convert an accepted quotation.', 'invoice-paper', $m && !$filtered && $status === '' ? '<a class="btn primary mt" href="'.e(url('/invoices/create')).'">'.icon('plus', 'sm').' New invoice</a>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Number</th><th>Client</th><th>Issued</th><th>Due</th><th class="right">Total</th><th class="right">Balance</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $i): $bal = $i['status'] === 'cancelled' ? 0 : (float) $i['total'] - (float) $i['amount_paid']; $late = $bal > 0 && $i['due_date'] && $i['due_date'] < $today && $i['status'] !== 'draft'; ?>
      <tr>
        <td><a class="row-link" href="<?= e(url('/invoices/'.$i['id'])) ?>"><?= e($i['number']) ?></a><div class="xs muted"><?= e(str_limit($i['title'] ?? '', 40)) ?></div></td>
        <td><a class="flex" href="<?= e(url('/clients/'.$i['client_id'])) ?>"><?= avatar($i['client_company'] ?: $i['client_name'], null, 'sm') ?><span class="small b"><?= e($i['client_company'] ?: $i['client_name']) ?></span></a></td>
        <td class="small"><?= e(fdate($i['issue_date'])) ?></td>
        <td class="small <?= $late ? 'crm-late' : '' ?>"><?= e(fdate($i['due_date'])) ?><?php if ($late): ?><div class="xs"><?= (int) floor((strtotime($today) - strtotime($i['due_date'])) / 86400) ?>d late</div><?php endif ?></td>
        <td class="right nowrap"><?= e(money($i['total'])) ?></td>
        <td class="right nowrap"><b><?= e(money($bal)) ?></b><?php if ((float) $i['amount_paid'] > 0 && $bal > 0): ?><div class="progress crm-mini-progress"><span style="width:<?= (int) min(100, round((float) $i['amount_paid'] / max(0.01, (float) $i['total']) * 100)) ?>%"></span></div><?php endif ?></td>
        <td><?= badge($i['status']) ?></td>
        <td class="actions"><a class="btn icon sm ghost" href="<?= e(url('/invoices/'.$i['id'])) ?>" title="Open"><?= icon('eye', 'sm') ?></a> <a class="btn icon sm ghost" href="<?= e(url('/invoices/'.$i['id'].'/pdf')) ?>" title="Download PDF"><?= icon('download', 'sm') ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
