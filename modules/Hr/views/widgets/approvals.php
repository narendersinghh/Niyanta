<?php use Modules\Hr\Services\{LeaveChanges as LC, LeaveService as LS}; /* $count pending, $cxl cancellation requests, $rows (up to 5, pending first) */ ?>
<div class="card lv-wcard">
  <div class="card-head"><div><h3><?= icon('check-circle') ?> Leave requests waiting</h3><div class="xs muted"><?= $count ? (int) $count.' to approve or reject' : 'Nothing to approve' ?><?= $cxl ? ' · '.(int) $cxl.' cancellation request'.($cxl === 1 ? '' : 's') : '' ?></div></div><a class="btn sm <?= $count + $cxl ? 'primary' : 'ghost' ?>" href="<?= e(url('/hr/leave/approvals'.(!$count && $cxl ? '?status=cancel' : ''))) ?>"><?= $count ? 'Review '.(int) $count : 'Open' ?> <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$rows): ?>
    <div class="list-item"><span class="avatar bg-mint"><?= icon('check', 'sm') ?></span><div class="grow"><b class="small">All caught up</b><div class="meta">No leave requests are waiting for your review.</div></div></div>
  <?php else: ?>
  <div class="list"><?php foreach ($rows as $r): $c = $r['status'] === 'approved'; ?>
    <a class="list-item" href="<?= e(url(LC::url((int) $r['id']))) ?>"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div class="grow"><b class="small"><?= e($r['name']) ?></b><div class="meta"><?= $c ? 'Wants to cancel · ' : '' ?><?= e($r['type_name']) ?> · <?= e(LC::range($r['start_date'], $r['end_date'], (bool) $r['half_day'])) ?> · applied <?= e(ago($r['created_at'])) ?></div></div><span class="pill pill-<?= $c ? 'peach' : e($r['color']) ?>"><?= e(LS::num($r['days'])) ?>d</span></a>
  <?php endforeach ?></div>
  <?php if ($count + $cxl > count($rows)): ?><a class="xs muted" href="<?= e(url('/hr/leave/approvals')) ?>">+<?= $count + $cxl - count($rows) ?> more waiting</a><?php endif ?>
  <?php endif ?>
</div>
