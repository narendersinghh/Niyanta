<div class="card crm-w">
  <div class="card-head"><div><h3>Overdue invoices</h3><p class="xs muted mb0"><?= $rows ? e(money($sum)).' waiting to be collected' : 'Payments that are past their due date' ?></p></div><a class="btn icon sm ghost" href="<?= e(url('/invoices?status=overdue')) ?>" title="All overdue invoices" aria-label="All overdue invoices"><?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$rows): ?><div class="list-item"><span class="crm-ic crm-c-mint"><?= icon('check-circle', 'sm') ?></span><div class="grow"><b class="small">All caught up</b><div class="meta">No overdue invoices, nice work!</div></div></div><?php else: ?>
  <div class="list"><?php foreach ($rows as $r): $d = max(0, \Modules\Crm\Services\Ui::daysPast((string) $r['due_date'])); ?>
    <a class="list-item" href="<?= e(url('/invoices/'.$r['id'])) ?>"><?= avatar($r['client'], null, 'sm') ?><div class="grow"><b class="small"><?= e($r['client']) ?></b><div class="meta"><?= e($r['number']) ?> · <span class="crm-late"><?= $d ?>d late</span></div></div><b class="small amt"><?= e(money($r['balance'])) ?></b></a>
  <?php endforeach ?></div>
  <?php endif ?>
</div>
