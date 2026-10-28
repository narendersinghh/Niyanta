<div class="card">
  <div class="card-head"><div><h3>Overdue invoices</h3><?php if ($rows): ?><p class="xs muted mb0"><?= e(money($sum)) ?> waiting to be collected</p><?php endif ?></div><a class="btn sm ghost" href="<?= e(url('/invoices?status=overdue')) ?>"><?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$rows): ?><div class="empty" style="padding:10px 0 4px"><?= illus('celebrate', 'empty-illus') ?><b>All caught up</b><p class="small muted mb0">No overdue invoices. Nice work!</p></div><?php else: ?>
  <div class="list"><?php foreach ($rows as $r): $d = max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $r['due_date'])) / 86400)); ?>
    <a class="list-item" href="<?= e(url('/invoices/'.$r['id'])) ?>"><span class="notif-ic" style="background:var(--peach-s);color:var(--peach-d)"><?= icon('receipt', 'sm') ?></span><div class="grow"><b class="small"><?= e($r['client']) ?></b><div class="meta"><?= e($r['number']) ?> · <span style="color:var(--red-d)"><?= $d ?>d late</span></div></div><b class="small nowrap"><?= e(money($r['balance'])) ?></b></a>
  <?php endforeach ?></div>
  <?php endif ?>
</div>
