<?= partial('hr::leave/_balances', ['balances' => $balances, 'year' => $year]) ?>
<div class="card">
  <div class="card-head"><h3>Leave history</h3><?php if ($self && can('hr.leave.request')): ?><a class="btn sm primary" href="<?= e(url('/hr/leave')) ?>"><?= icon('plus', 'sm') ?> Request leave</a><?php endif ?></div>
  <?php if (!$history['items']): ?><?= empty_state('No leave taken yet', 'Requests will show up here.', 'leave-beach') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Type</th><th>Dates</th><th class="right">Days</th><th>Reason</th><th>Status</th><th>Reviewed by</th></tr></thead>
    <tbody><?php foreach ($history['items'] as $r): ?><tr>
      <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span></td>
      <td class="nowrap small"><?= fdate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date']) : '' ?><?= $r['half_day'] ? ' <span class="pill pill-gray">½ day</span>' : '' ?></td>
      <td class="right b"><?= e((float) $r['days']) ?></td>
      <td class="small"><?= e(str_limit($r['reason'], 60)) ?></td>
      <td><?= badge($r['status']) ?></td>
      <td class="small"><?= e($r['reviewer'] ?? '—') ?><?php if ($r['review_note']): ?><div class="xs muted"><?= e(str_limit($r['review_note'], 60)) ?></div><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($history) ?>
  <?php endif ?>
</div>
