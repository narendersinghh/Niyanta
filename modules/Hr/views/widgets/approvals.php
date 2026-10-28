<div class="card">
  <div class="card-head"><h3><?= icon('plane') ?> Leave approvals</h3><a class="btn sm <?= $count ? 'primary' : 'ghost' ?>" href="<?= e(url('/hr/leave/approvals')) ?>"><?= $count ? (int) $count.' pending' : 'Open' ?> <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$rows): ?>
    <div class="flex"><div style="width:92px;flex:none"><?= illus('celebrate') ?></div><div><b>All caught up</b><p class="small muted mb0">No leave requests are waiting for your review.</p></div></div>
  <?php else: ?>
  <div class="list"><?php foreach ($rows as $r): ?>
    <a class="list-item" href="<?= e(url('/hr/leave/approvals')) ?>"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div class="grow"><b class="small"><?= e($r['name']) ?></b><div class="meta"><?= e($r['type_name']) ?> · <?= e(fdate($r['start_date'], 'd M')) ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.e(fdate($r['end_date'], 'd M')) : '' ?><?= $r['half_day'] ? ' · ½ day' : '' ?></div></div><span class="pill pill-<?= e($r['color']) ?>"><?= e((float) $r['days']) ?>d</span></a>
  <?php endforeach ?></div>
  <?php if ($count > count($rows)): ?><a class="xs muted" href="<?= e(url('/hr/leave/approvals')) ?>">+<?= $count - count($rows) ?> more waiting</a><?php endif ?>
  <?php endif ?>
</div>
