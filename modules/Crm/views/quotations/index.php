<?php
use Modules\Crm\Services\{Hooks, Ui};
$m = can('crm.quotations.manage');
$n = static fn ($s) => (int) ($stats[$s]['n'] ?? 0);
$val = static fn ($s) => (float) ($stats[$s]['v'] ?? 0);
$open = $val('draft') + $val('sent');
$decided = $n('accepted') + $n('rejected');
$win = $decided ? (int) round($n('accepted') / $decided * 100) : 0;
$status = (string) ($_GET['status'] ?? '');
$tabs = ['' => 'All', 'draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'expired' => 'Expired'];
$filtered = ($_GET['q'] ?? '') !== '' || !empty($_GET['client_id']);
$today = date('Y-m-d');
$more = Ui::more([can('crm.finance.view') ? Ui::link('/invoices', 'receipt', 'Invoices') : '', can('crm.reports.view') ? Ui::link('/crm/reports', 'chart', 'Financial reports') : '']);
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => 'Quotations', 'subtitle' => 'Proposals and estimates, track every one from draft to accepted.', 'actions' => $more.($m ? '<a class="btn primary" href="'.e(url('/quotations/create')).'">'.icon('plus', 'sm').' New quotation</a>' : '')]) ?>
<div class="grid g4 mb">
  <?= Ui::tile('sky', 'send', Hooks::compact($open), 'Open · '.($n('sent') + $n('draft')).' awaiting reply', '/quotations?status=sent', money($open)) ?>
  <?= Ui::tile('mint', 'check-circle', Hooks::compact($val('accepted')), 'Accepted · '.$n('accepted').' quotation'.($n('accepted') === 1 ? '' : 's'), '/quotations?status=accepted', money($val('accepted'))) ?>
  <?= Ui::tile('pink', 'x', (string) ($n('rejected') + $n('expired')), 'Rejected or expired', '/quotations?status=rejected') ?>
  <?= Ui::tile('lav', 'target', $win.'%', 'Win rate (accepted vs rejected)') ?>
</div>
<div class="card crm-list">
  <nav class="crm-stabs" aria-label="Status"><?php foreach ($tabs as $k => $l): $c = $k === '' ? array_sum(array_map(static fn ($r) => (int) $r['n'], $stats)) : $n($k); ?><a href="<?= e(query_merge(['status' => $k ?: null, 'page' => null])) ?>" class="<?= $status === $k || ($k === '' && !isset($tabs[$status])) ? 'active' : '' ?>"><?= e($l) ?><?php if ($c): ?> <span class="crm-count"><?= $c ?></span><?php endif ?></a><?php endforeach ?></nav>
  <form class="crm-bar" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
    <label class="crm-search"><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search number, title or client" aria-label="Search quotations"></label>
    <select name="client_id" data-autosubmit aria-label="Client"><option value="">All clients</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $_GET['client_id'] ?? '') ?>><?= e($cn) ?></option><?php endforeach ?></select>
    <?php if ($filtered): ?><a class="crm-clear" href="<?= e(url('/quotations'.($status !== '' ? '?status='.rawurlencode($status) : ''))) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?>
    <?= $filtered || $status !== '' ? empty_state('No quotations here', 'Nothing matches this view right now.', 'empty-box') : empty_state('No quotations yet', 'Send an itemised estimate, then convert it to an invoice once the client accepts.', 'money-wallet', $m ? '<a class="btn primary" href="'.e(url('/quotations/create')).'">'.icon('plus', 'sm').' Create your first quotation</a>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table crm-rt">
    <thead><tr><th>Quotation</th><th>Client</th><th>Issued</th><th>Valid until</th><th class="amt">Amount</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $q): $late = $q['valid_until'] && $q['valid_until'] < $today && in_array($q['status'], ['draft', 'sent'], true); ?>
      <tr>
        <td class="rt-main"><a class="crm-num-link" href="<?= e(url('/quotations/'.$q['id'])) ?>"><?= e($q['number']) ?></a><div class="crm-sub"><?= e(str_limit($q['title'] ?? '', 40) ?: '-') ?></div></td>
        <td data-l="Client"><span class="crm-cell"><?= avatar($q['client_company'] ?: $q['client_name'], null, 'sm') ?><span class="small b truncate"><?= e($q['client_company'] ?: $q['client_name']) ?></span></span></td>
        <td class="small" data-l="Issued"><?= e(fdate($q['issue_date'])) ?></td>
        <td class="small <?= $late ? 'crm-late' : '' ?>" data-l="Valid until"><?= e(fdate($q['valid_until'])) ?><?= $late ? ' · lapsed' : '' ?></td>
        <td class="amt" data-l="Amount"><b><?= e(money($q['total'])) ?></b></td>
        <td class="rt-side"><?= badge($q['status']) ?><?= $q['invoice_id'] ? '<div class="crm-sub">'.icon('receipt', 'sm').' Invoiced</div>' : '' ?></td>
        <td class="actions rt-hide"><span class="crm-row-actions"><a class="crm-go" href="<?= e(url('/quotations/'.$q['id'].'/pdf')) ?>" title="Download PDF" aria-label="Download PDF"><?= icon('download', 'sm') ?></a><a class="crm-go" href="<?= e(url('/quotations/'.$q['id'])) ?>" title="Open" aria-label="Open"><?= icon('chevron-right', 'sm') ?></a></span></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
</div>
