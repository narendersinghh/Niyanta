<?php
$id = (int) $doc['id'];
$m = can('crm.quotations.manage');
$st = $doc['status'];
$locked = (bool) $doc['invoice_id'];
$actions = '<a class="btn ghost" href="'.e(url('/quotations/'.$id.'/pdf')).'">'.icon('download', 'sm').' PDF</a>';
if ($m && !$locked) { $actions .= '<a class="btn ghost" href="'.e(url('/quotations/'.$id.'/edit')).'">'.icon('edit', 'sm').' Edit</a>'; }
if ($m && !$locked && $st === 'accepted') { $actions .= '<form method="post" action="'.e(url('/quotations/'.$id.'/convert')).'" data-confirm="Create a draft invoice from this quotation?">'.csrf_field().'<button class="btn primary">'.icon('receipt', 'sm').' Convert to invoice</button></form>'; }
if ($m && !$locked) { $actions .= '<form method="post" action="'.e(url('/quotations/'.$id.'/delete')).'" data-confirm="Delete this quotation?">'.csrf_field().'<button class="btn icon danger" title="Delete">'.icon('trash', 'sm').'</button></form>'; }
$flow = ['draft' => ['Draft', 'edit'], 'sent' => ['Sent', 'send'], 'accepted' => ['Accepted', 'check-circle'], 'rejected' => ['Rejected', 'x'], 'expired' => ['Expired', 'clock']];
$expired = $doc['valid_until'] && $doc['valid_until'] < date('Y-m-d') && in_array($st, ['draft', 'sent'], true);
?>
<?= partial('partials/page_head', ['title' => 'Quotation '.$doc['number'], 'subtitle' => ($doc['client_company'] ?: $doc['client_name']).($doc['title'] ? ' · '.$doc['title'] : ''), 'crumbs' => [['Quotations', '/quotations'], [$doc['number']]], 'actions' => $actions]) ?>
<?php if ($inv): ?><div class="alert ok"><?= icon('check-circle') ?><div>Converted to invoice <a class="b" href="<?= e(url('/invoices/'.$inv['id'])) ?>"><?= e($inv['number']) ?></a> (<?= e(ucfirst($inv['status'])) ?>). The quotation is now locked.</div></div>
<?php elseif ($expired): ?><div class="alert warn"><?= icon('clock') ?><div>This quotation passed its validity date (<?= e(fdate($doc['valid_until'])) ?>). Mark it expired or extend the date.</div></div><?php endif ?>
<div class="grid g-side">
  <div><?= partial('crm::partials/doc_paper', ['kind' => 'quotation', 'doc' => $doc, 'items' => $items, 'client' => $client]) ?></div>
  <div class="stack">
    <div class="card crm-balance quote">
      <div class="flex between"><span class="lbl">Quotation value</span><?= badge($st) ?></div>
      <div class="crm-big"><?= e(money($doc['total'])) ?></div>
      <div class="xs mt"><?= icon('calendar', 'sm') ?> Valid until <?= e(fdate($doc['valid_until'])) ?></div>
    </div>
    <?php if ($m && !$locked): ?>
    <div class="card">
      <div class="card-head"><h3>Status</h3></div>
      <div class="crm-flow">
        <?php foreach ($flow as $k => [$l, $ic]): ?>
        <form method="post" action="<?= e(url('/quotations/'.$id.'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($k) ?>"><button class="crm-step <?= $st === $k ? 'on' : '' ?>"<?= $st === $k ? ' disabled' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?></button></form>
        <?php endforeach ?>
      </div>
      <p class="xs muted mt mb0">Once the client accepts, convert it to an invoice in one click.</p>
    </div>
    <?php endif ?>
    <div class="card">
      <div class="card-head"><h3>Client</h3></div>
      <a class="flex" href="<?= e(url('/clients/'.(int) $client['id'])) ?>"><?= avatar($client['company'] ?: $client['name'], null) ?><div class="grow"><b><?= e($client['company'] ?: $client['name']) ?></b><div class="xs muted"><?= e($client['email'] ?: $client['phone'] ?: '') ?></div></div><?= icon('chevron-right', 'sm') ?></a>
      <hr><dl class="kv small"><dt>Created</dt><dd><?= e(fdatetime($doc['created_at'])) ?></dd><dt>Updated</dt><dd><?= e(fdatetime($doc['updated_at'])) ?></dd></dl>
    </div>
  </div>
</div>
