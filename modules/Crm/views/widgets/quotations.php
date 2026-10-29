<div class="card crm-w">
  <div class="card-head"><div><h3>Open quotations</h3><p class="xs muted mb0"><?= (int) $sum['n'] ?> awaiting a decision · <?= e(money($sum['v'])) ?></p></div><a class="btn icon sm ghost" href="<?= e(url('/quotations?status=sent')) ?>" title="All quotations" aria-label="All quotations"><?= icon('arrow-right', 'sm') ?></a></div>
  <div class="list"><?php foreach ($rows as $r): $lapsed = $r['valid_until'] && $r['valid_until'] < date('Y-m-d'); ?>
    <a class="list-item" href="<?= e(url('/quotations/'.$r['id'])) ?>"><span class="crm-ic crm-c-lemon"><?= icon('file-text', 'sm') ?></span><div class="grow"><b class="small"><?= e($r['client']) ?></b><div class="meta"><?= e($r['number']) ?> · <?= $r['valid_until'] ? '<span class="'.($lapsed ? 'crm-late' : '').'">'.($lapsed ? 'lapsed ' : 'valid to ').e(fdate($r['valid_until'])).'</span>' : e(ucfirst($r['status'])) ?></div></div><b class="small amt"><?= e(money($r['total'])) ?></b></a>
  <?php endforeach ?></div>
</div>
