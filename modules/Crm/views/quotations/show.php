<?php
use Modules\Crm\Services\Ui;
$id = (int) $doc['id'];
$m = can('crm.quotations.manage');
$canConvert = $m && can('crm.finance.manage');
$st = $doc['status'];
$locked = (bool) $doc['invoice_id'];
$expired = $doc['valid_until'] && $doc['valid_until'] < date('Y-m-d') && in_array($st, ['draft', 'sent'], true);
$client_label = $doc['client_company'] ?: $doc['client_name'];
$status = static fn (string $to, string $icon, string $label, bool $primary = false) => '<form method="post" action="'.e(url('/quotations/'.$id.'/status')).'">'.csrf_field().'<input type="hidden" name="status" value="'.e($to).'"><button class="btn '.($primary ? 'primary' : 'ghost sm').'">'.icon($icon, 'sm').' '.e($label).'</button></form>';
$primary = '';
if ($m && !$locked) {
  $primary = match ($st) {
    'draft' => $status('sent', 'send', 'Mark as sent', true),
    'sent' => $status('accepted', 'check-circle', 'Mark accepted', true),
    'accepted' => $canConvert ? '<form method="post" action="'.e(url('/quotations/'.$id.'/convert')).'" data-confirm="Create a draft invoice from this quotation?">'.csrf_field().'<button class="btn primary">'.icon('receipt', 'sm').' Convert to invoice</button></form>' : '',
    default => '' }; }
$pdf = '<a class="btn '.($primary === '' ? 'primary' : 'ghost').'" href="'.e(url('/quotations/'.$id.'/pdf')).'">'.icon('download', 'sm').' PDF</a>';
$more = $m && !$locked ? Ui::more([Ui::link('/quotations/'.$id.'/edit', 'edit', 'Edit quotation'), Ui::post('/quotations/'.$id.'/delete', 'trash', 'Delete quotation', 'Delete this quotation?', true)]) : '';
// Progress: draft -> sent -> accepted (or rejected / expired as the final step).
$bad = in_array($st, ['rejected', 'expired'], true);
$order = ['draft' => 0, 'sent' => 1, 'accepted' => 2, 'rejected' => 2, 'expired' => 2];
$cur = $order[$st] ?? 0;
$steps = [['Draft', 'edit'], ['Sent', 'send'], $bad ? [ucfirst($st), $st === 'rejected' ? 'x' : 'clock'] : ['Accepted', 'check-circle']];
if (feature_on('invoices') || $doc['invoice_id']) { $steps[] = [feature_on('invoices') ? 'Invoiced' : 'Converted', 'receipt']; }
if ($locked) { $cur = 3; }
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => 'Quotation '.$doc['number'], 'subtitle' => $client_label.($doc['title'] ? ' · '.$doc['title'] : ''), 'crumbs' => [['Quotations', '/quotations'], [$doc['number']]], 'actions' => $pdf.$more.$primary]) ?>
<?php if ($inv && !feature_on('invoices')): ?><div class="alert ok"><?= icon('check-circle') ?><div>This quotation was converted and is now locked.</div></div>
<?php elseif ($inv): ?><div class="alert ok"><?= icon('check-circle') ?><div>Converted to invoice <?= can('crm.finance.view') ? '<a class="b" href="'.e(url('/invoices/'.$inv['id'])).'">'.e($inv['number']).'</a>' : '<b>'.e($inv['number']).'</b>' ?> (<?= e(ucfirst($inv['status'])) ?>). This quotation is now locked.</div></div>
<?php elseif ($expired): ?><div class="alert warn"><?= icon('clock') ?><div>This quotation passed its validity date (<?= e(fdate($doc['valid_until'])) ?>). Mark it expired or edit it to extend the date.</div></div>
<?php elseif ($st === 'accepted' && $m && !$canConvert && feature_on('invoices')): ?><div class="alert info"><?= icon('info') ?><div>Accepted. Invoicing is not available to you, so it cannot be converted here.</div></div><?php endif ?>
<div class="grid g-side">
  <div><?= partial('crm::partials/doc_paper', ['kind' => 'quotation', 'doc' => $doc, 'items' => $items, 'client' => $client]) ?></div>
  <div class="stack">
    <div class="card crm-bal <?= $bad ? 'is-muted' : ($st === 'accepted' || $locked ? 'is-paid' : '') ?>">
      <div class="flex between"><span class="crm-bal-lbl">Quotation value</span><?= badge($st) ?></div>
      <div class="crm-big"><?= e(money($doc['total'])) ?></div>
      <span class="crm-bal-due"><?= icon('calendar', 'sm') ?> Valid until <?= e(fdate($doc['valid_until'])) ?></span>
    </div>
    <div class="card">
      <div class="card-head"><h3>Progress</h3></div>
      <div class="crm-steps">
        <?php foreach ($steps as $i => [$l, $ic]): $cls = $i < $cur ? 'done' : ($i === $cur ? ($bad && $i === 2 ? 'bad' : ($locked ? 'done' : 'cur')) : ''); ?>
        <div class="crm-stp <?= $cls ?>"><i><?= icon($cls === 'done' ? 'check' : $ic, 'sm') ?></i><?= e($l) ?></div>
        <?php endforeach ?>
      </div>
      <?php if ($m && !$locked): ?>
      <div class="crm-status-btns">
        <?php foreach (['draft' => ['edit', 'Draft'], 'sent' => ['send', 'Sent'], 'accepted' => ['check-circle', 'Accepted'], 'rejected' => ['x', 'Rejected'], 'expired' => ['clock', 'Expired']] as $k => [$ic, $l]): if ($k === $st) { continue; } ?><?= $status($k, $ic, $l) ?><?php endforeach ?>
      </div>
      <p class="xs muted mt mb0">Change the status as the client responds.<?= $canConvert ? ' Accepted quotations convert to an invoice in one click.' : '' ?></p>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Client</h3></div>
      <?php if (can('crm.clients.view')): ?><a class="crm-party" href="<?= e(url('/clients/'.(int) $client['id'])) ?>"><?php else: ?><div class="crm-party"><?php endif ?>
        <?= avatar($client['company'] ?: $client['name'], null) ?><div class="grow"><b><?= e($client['company'] ?: $client['name']) ?></b><div class="crm-sub"><?= e($client['email'] ?: $client['phone'] ?: '') ?></div></div><?= can('crm.clients.view') ? icon('chevron-right', 'sm').'</a>' : '</div>' ?>
      <dl class="crm-facts"><dt>Created</dt><dd><?= e(fdatetime($doc['created_at'])) ?></dd><dt>Updated</dt><dd><?= e(fdatetime($doc['updated_at'])) ?></dd></dl>
    </div>
  </div>
</div>
</div>
