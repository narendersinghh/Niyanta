<?php
use Modules\Crm\Services\{Hooks, Ui};
$m = can('crm.finance.manage');
$tabs = ['' => 'All', 'draft' => 'Draft', 'sent' => 'Sent', 'partial' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
$filtered = ($_GET['q'] ?? '') !== '' || !empty($_GET['client_id']) || !empty($_GET['from']) || !empty($_GET['to']);
$today = date('Y-m-d');
$more = Ui::more([can('crm.quotations.view') ? Ui::link('/quotations', 'file-text', 'Quotations') : '', can('crm.reports.view') ? Ui::link('/crm/reports', 'chart', 'Financial reports') : '', can('crm.reports.view') ? Ui::link('/crm/reports/outstanding.csv', 'download', 'Export outstanding (CSV)') : '']);
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => 'Invoices', 'subtitle' => 'Bill clients, record payments and keep cash flowing.', 'actions' => $more.($m ? '<a class="btn primary" href="'.e(url('/invoices/create')).'">'.icon('plus', 'sm').' New invoice</a>' : '')]) ?>
<div class="grid g4 mb">
  <?= Ui::tile('lav', 'wallet', Hooks::compact((float) $s['outstanding']), 'Outstanding · '.(int) $s['open_n'].' open', '/invoices?status=sent', money($s['outstanding'])) ?>
  <?= Ui::tile('peach', 'alert', Hooks::compact((float) $s['overdue']), 'Overdue · '.(int) $s['overdue_n'].' invoice'.((int) $s['overdue_n'] === 1 ? '' : 's'), '/invoices?status=overdue', money($s['overdue'])) ?>
  <?= Ui::tile('mint', 'trend-up', Hooks::compact((float) $s['collected']), 'Collected in '.date('F'), null, money($s['collected'])) ?>
  <?= Ui::tile('lemon', 'edit', (string) (int) $s['drafts'], 'Drafts to send', '/invoices?status=draft') ?>
</div>
<div class="card crm-list">
  <nav class="crm-stabs" aria-label="Status"><?php foreach ($tabs as $k => $l): $c = $k === '' ? array_sum($counts) : (int) ($counts[$k] ?? 0); ?><a href="<?= e(query_merge(['status' => $k ?: null, 'page' => null])) ?>" class="<?= $status === $k || ($k === '' && !isset($tabs[$status])) ? 'active' : '' ?>"><?= e($l) ?><?php if ($c): ?> <span class="crm-count"><?= $c ?></span><?php endif ?></a><?php endforeach ?></nav>
  <form class="crm-bar" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
    <label class="crm-search"><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search number, title or client" aria-label="Search invoices"></label>
    <select name="client_id" data-autosubmit aria-label="Client"><option value="">All clients</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $_GET['client_id'] ?? '') ?>><?= e($cn) ?></option><?php endforeach ?></select>
    <input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>" aria-label="Issued from" title="Issued from" data-autosubmit>
    <input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>" aria-label="Issued to" title="Issued to" data-autosubmit>
    <?php if ($filtered): ?><a class="crm-clear" href="<?= e(url('/invoices'.($status !== '' ? '?status='.rawurlencode($status) : ''))) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?>
    <?= $filtered || $status !== '' ? empty_state('No invoices here', 'Nothing matches this view right now.', 'empty-box') : empty_state('No invoices yet', 'Create your first invoice'.(feature_on('quotations') ? ', or convert an accepted quotation' : '').'.', 'invoice-paper', $m ? '<a class="btn primary" href="'.e(url('/invoices/create')).'">'.icon('plus', 'sm').' Create your first invoice</a>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table crm-rt">
    <thead><tr><th>Invoice</th><th>Client</th><th>Issued</th><th>Due</th><th class="amt">Total</th><th class="amt">Balance</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $i): $bal = $i['status'] === 'cancelled' ? 0 : (float) $i['total'] - (float) $i['amount_paid']; $late = $bal > 0 && $i['due_date'] && $i['due_date'] < $today && $i['status'] !== 'draft'; $paidPct = (float) $i['total'] > 0 ? (int) min(100, round((float) $i['amount_paid'] / (float) $i['total'] * 100)) : 0; ?>
      <tr>
        <td class="rt-main"><a class="crm-num-link" href="<?= e(url('/invoices/'.$i['id'])) ?>"><?= e($i['number']) ?></a><div class="crm-sub"><?= e(str_limit($i['title'] ?? '', 40) ?: '-') ?></div></td>
        <td data-l="Client"><span class="crm-cell"><?= avatar($i['client_company'] ?: $i['client_name'], null, 'sm') ?><span class="small b truncate"><?= e($i['client_company'] ?: $i['client_name']) ?></span></span></td>
        <td class="small" data-l="Issued"><?= e(fdate($i['issue_date'])) ?></td>
        <td class="small <?= $late ? 'crm-late' : '' ?>" data-l="Due"><span><?= e(fdate($i['due_date'])) ?><?php if ($late): ?><span class="crm-sub crm-late"> · <?= Ui::daysPast($i['due_date']) ?>d late</span><?php endif ?></span></td>
        <td class="amt" data-l="Total"><?= e(money($i['total'])) ?></td>
        <td class="amt" data-l="Balance"><div><b class="<?= $bal > 0 ? '' : 'muted' ?>"><?= e(money($bal)) ?></b><?php if ($paidPct > 0 && $bal > 0): ?><div class="crm-bal-bar" title="<?= $paidPct ?>% paid"><span style="width:<?= $paidPct ?>%"></span></div><?php endif ?></div></td>
        <td class="rt-side"><?= badge($i['status']) ?></td>
        <td class="actions rt-hide"><span class="crm-row-actions"><a class="crm-go" href="<?= e(url('/invoices/'.$i['id'].'/pdf')) ?>" title="Download PDF" aria-label="Download PDF"><?= icon('download', 'sm') ?></a><a class="crm-go" href="<?= e(url('/invoices/'.$i['id'])) ?>" title="Open" aria-label="Open"><?= icon('chevron-right', 'sm') ?></a></span></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
</div>
