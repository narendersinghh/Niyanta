<?php use Modules\Hr\Services\LeaveService as LS;
$chips = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All']; ?>
<?= partial('partials/page_head', ['title' => 'Leave approvals', 'subtitle' => $all ? 'Review leave requests from everyone in the organisation.' : 'Review leave requests from your direct reports.', 'crumbs' => [['HR Admin'], ['Leave approvals']], 'actions' => '<a class="btn ghost" href="'.e(url('/hr/leave/calendar')).'">'.icon('calendar', 'sm').' Team calendar</a>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="grid g4 mb">
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $pendingCount ?></div><div class="lbl">Waiting for review</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($stats['approved'] ?? 0) ?></div><div class="lbl">Approved this month</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('x') ?></span><div><div class="num"><?= (int) ($stats['rejected'] ?? 0) ?></div><div class="lbl">Rejected this month</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= (int) ($stats['out_today'] ?? 0) ?></div><div class="lbl">On leave today</div></div></div>
</div>
<div class="card">
  <form class="hr-toolbar" method="get">
    <div class="chips"><?php foreach ($chips as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e(url('/hr/leave/approvals').query_merge(['status' => $k, 'page' => 1])) ?>"><?= e($l) ?><?= $k === 'pending' && $pendingCount ? ' <span class="n">'.(int) $pendingCount.'</span>' : '' ?></a><?php endforeach ?></div>
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="hr-search hr-push"><span class="sr">Search employee</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee"></label>
  </form>
  <?php if (!$p['items']): ?><?= empty_state($status === 'pending' ? 'All caught up!' : 'Nothing here', $status === 'pending' ? 'No leave requests are waiting for your review.' : 'No requests match this filter.', $status === 'pending' ? 'celebrate' : 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="num">Days</th><th class="num">Balance left</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r): $key = $r['user_id'].'-'.$r['leave_type_id'].'-'.substr($r['start_date'], 0, 4); $left = $bal[$key] ?? null; ?><tr>
      <td><div class="hr-who"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b class="small"><?= e($r['name']) ?></b><div class="xs muted"><?= e(trim(($r['designation'] ?? '').($r['department'] ? ' · '.$r['department'] : ''), ' ·') ?: '—') ?></div></div></div></td>
      <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?php if ($r['reason']): ?><div class="xs muted mt hr-reason" title="<?= e($r['reason']) ?>"><?= e(str_limit($r['reason'], 60)) ?></div><?php endif ?></td>
      <td class="nowrap"><b class="small"><?= fdate($r['start_date'], 'd M') ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date'], 'd M Y') : fdate($r['start_date'], ' Y') ?></b><?= $r['half_day'] ? ' <span class="pill pill-gray">½</span>' : '' ?><div class="xs muted">Asked <?= e(ago($r['created_at'])) ?></div></td>
      <td class="num b"><?= e(LS::num($r['days'])) ?></td>
      <td class="num small"><?php if (!(int) $r['paid']): ?><span class="muted">Unpaid</span><?php elseif ($left === null): ?><?= $r['status'] === 'pending' ? '<span class="pill pill-red">Not allocated</span>' : '<span class="muted">—</span>' ?><?php elseif ($r['status'] === 'pending' && $left < (float) $r['days']): ?><span class="pill pill-red"><?= e(LS::num($left)) ?> days</span><?php else: ?><?= e(LS::num($left)) ?> days<?php endif ?></td>
      <td><?= badge($r['status']) ?><?php if ($r['reviewer']): ?><div class="xs muted">by <?= e($r['reviewer']) ?></div><?php endif ?></td>
      <td class="actions"><?php if ($r['status'] === 'pending'): ?><button type="button" class="btn sm primary" data-modal="rv-<?= (int) $r['id'] ?>"><?= icon('check', 'sm') ?> Review</button><?php elseif ($r['review_note']): ?><span class="xs muted" title="<?= e($r['review_note']) ?>"><?= e(str_limit($r['review_note'], 40)) ?></span><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php foreach ($p['items'] as $r): if ($r['status'] !== 'pending') { continue; } ?>
  <dialog class="modal" id="rv-<?= (int) $r['id'] ?>" aria-label="Review leave of <?= e($r['name']) ?>">
    <div class="modal-head"><h3>Review leave</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/'.$r['id'].'/review')) ?>"><?= csrf_field() ?>
      <div class="hr-who"><?= avatar($r['name'], $r['avatar']) ?><div class="grow"><b><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['type_name']) ?> · <?= fdate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date']) : '' ?><?= $r['half_day'] ? ' · half day' : '' ?></div></div><span class="pill pill-<?= e($r['color']) ?>"><?= e(LS::num($r['days'])) ?> day(s)</span></div>
      <?php if ($r['reason']): ?><div class="hr-note"><?= icon('message', 'sm') ?><div><?= nl2br(e($r['reason'])) ?></div></div><?php endif ?>
      <div class="field"><label for="rn-<?= (int) $r['id'] ?>">Note to <?= e(explode(' ', $r['name'])[0]) ?></label><textarea id="rn-<?= (int) $r['id'] ?>" name="review_note" rows="3" maxlength="500" placeholder="Optional — shown in their notification"></textarea></div>
      <div class="form-actions"><button class="btn danger" name="decision" value="reject" data-confirm="Reject this leave request?"><?= icon('x', 'sm') ?> Reject</button><button class="btn primary" name="decision" value="approve"><?= icon('check', 'sm') ?> Approve</button></div>
    </form>
  </dialog>
  <?php endforeach ?>
  <?php endif ?>
</div>
