<?= partial('partials/page_head', ['title' => 'Leave approvals', 'subtitle' => $all ? 'You can review requests from everyone in the organisation.' : 'Requests from your direct reports.', 'crumbs' => [['Leave', '/hr/leave'], ['Approvals']]]) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="card">
  <div class="filters">
    <div class="chips"><?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e(query_merge(['status' => $k, 'page' => 1])) ?>"><?= e($l) ?><?= $k === 'pending' && $pendingCount ? ' · '.(int) $pendingCount : '' ?></a><?php endforeach ?></div>
    <form method="get" class="flex hr-push"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee"></form>
  </div>
  <?php if (!$p['items']): ?><?= empty_state($status === 'pending' ? 'All caught up!' : 'Nothing here', $status === 'pending' ? 'No leave requests are waiting for your review.' : 'No requests match this filter.', $status === 'pending' ? 'celebrate' : 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="right">Days</th><th>Balance left</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r): $key = $r['user_id'].'-'.$r['leave_type_id'].'-'.substr($r['start_date'], 0, 4); $left = $bal[$key] ?? null; ?><tr>
      <td><div class="flex"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b><?= e($r['name']) ?></b><div class="xs muted"><?= e(trim(($r['designation'] ?? '').($r['department'] ? ' · '.$r['department'] : ''), ' ·')) ?></div></div></div></td>
      <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?php if ($r['reason']): ?><div class="xs muted mt hr-reason" title="<?= e($r['reason']) ?>"><?= e(str_limit($r['reason'], 60)) ?></div><?php endif ?></td>
      <td class="small nowrap"><?= fdate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date']) : '' ?><?= $r['half_day'] ? ' <span class="pill pill-gray">½</span>' : '' ?><div class="xs muted"><?= e(ago($r['created_at'])) ?></div></td>
      <td class="right b"><?= e((float) $r['days']) ?></td>
      <td class="small"><?php if (!(int) $r['paid']): ?><span class="muted">Unpaid</span><?php elseif ($left === null): ?><span class="pill pill-red">Not allocated</span><?php else: ?><span class="<?= $r['status'] === 'pending' && $left < (float) $r['days'] ? 'pill pill-red' : '' ?>"><?= e($left) ?> days</span><?php endif ?></td>
      <td><?= badge($r['status']) ?><?php if ($r['reviewer']): ?><div class="xs muted">by <?= e($r['reviewer']) ?></div><?php endif ?></td>
      <td class="actions"><?php if ($r['status'] === 'pending'): ?><button type="button" class="btn sm primary" data-modal="rv-<?= (int) $r['id'] ?>"><?= icon('check', 'sm') ?> Review</button><?php elseif ($r['review_note']): ?><span class="xs muted" title="<?= e($r['review_note']) ?>"><?= e(str_limit($r['review_note'], 40)) ?></span><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php foreach ($p['items'] as $r): if ($r['status'] !== 'pending') { continue; } ?>
  <dialog class="modal" id="rv-<?= (int) $r['id'] ?>">
    <div class="modal-head"><h3>Review leave</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/'.$r['id'].'/review')) ?>"><?= csrf_field() ?>
      <div class="flex"><?= avatar($r['name'], $r['avatar']) ?><div class="grow"><b><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['type_name']) ?> · <?= fdate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date']) : '' ?><?= $r['half_day'] ? ' · half day' : '' ?></div></div><span class="pill pill-<?= e($r['color']) ?>"><?= e((float) $r['days']) ?> day(s)</span></div>
      <?php if ($r['reason']): ?><div class="tl-card small"><?= nl2br(e($r['reason'])) ?></div><?php endif ?>
      <div class="field"><label>Note to <?= e(explode(' ', $r['name'])[0]) ?></label><textarea name="review_note" rows="3" maxlength="500" placeholder="Optional"></textarea></div>
      <div class="form-actions"><button class="btn danger" name="decision" value="reject"><?= icon('x', 'sm') ?> Reject</button><button class="btn mint" name="decision" value="approve"><?= icon('check', 'sm') ?> Approve</button></div>
    </form>
  </dialog>
  <?php endforeach ?>
  <?php endif ?>
</div>
