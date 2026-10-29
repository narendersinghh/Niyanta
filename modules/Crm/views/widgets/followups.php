<?php use Modules\Crm\Controllers\JourneyController; $types = JourneyController::TYPES; $manage = can('crm.clients.manage'); ?>
<div class="card crm-w">
  <?php if ($rows): ?>
  <div class="card-head"><div><h3>Follow-ups due</h3><p class="xs muted mb0">Overdue and next 7 days across your clients</p></div><span class="crm-count"><?= count($rows) ?></span></div>
  <div class="list"><?php foreach ($rows as $r): $late = strtotime($r['follow_up_at']) < time(); $today = date('Y-m-d', strtotime($r['follow_up_at'])) === date('Y-m-d'); [, $ti, $tc] = $types[$r['type']] ?? $types['other']; ?>
    <div class="list-item">
      <span class="crm-ic solid crm-c-<?= e($tc) ?>"><?= icon($ti, 'sm') ?></span>
      <a class="grow" href="<?= e(url('/clients/'.$r['client_id'].'#journey')) ?>"><b class="small"><?= e($r['title']) ?></b><div class="meta"><?= e($r['client']) ?> · <span class="<?= $late ? 'crm-late' : '' ?>"><?= e($today ? 'Today '.ftime($r['follow_up_at']) : fdatetime($r['follow_up_at'])) ?></span></div></a>
      <?= $late ? '<span class="pill pill-red">Overdue</span>' : ($today ? '<span class="pill pill-lemon">Today</span>' : '') ?>
      <?php if ($manage): ?><form method="post" action="<?= e(url('/clients/'.$r['client_id'].'/activities/'.$r['id'].'/done')) ?>"><?= csrf_field() ?><button class="btn icon sm mint" title="Mark done" aria-label="Mark done"><?= icon('check', 'sm') ?></button></form><?php endif ?>
    </div>
  <?php endforeach ?></div>
  <?php else: ?>
  <div class="card-head"><div><h3>Recent client activity</h3><p class="xs muted mb0">No follow-ups due this week, here is what happened lately</p></div><a class="btn sm ghost" href="<?= e(url('/clients')) ?>">Clients <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$recent): ?><?= empty_state('No client activity yet', 'Log meetings and calls on a client profile to see them here.', 'clients-handshake') ?><?php else: ?>
  <div class="list"><?php foreach ($recent as $r): [$tl, $ti, $tc] = $types[$r['type']] ?? $types['other']; ?>
    <a class="list-item" href="<?= e(url('/clients/'.$r['client_id'].'#journey')) ?>"><span class="crm-ic solid crm-c-<?= e($tc) ?>"><?= icon($ti, 'sm') ?></span><div class="grow"><b class="small"><?= e($r['title']) ?></b><div class="meta"><?= e($r['client']) ?> · <?= e($tl) ?> · <?= e($r['user_name'] ?? 'System') ?></div></div><span class="xs muted nowrap"><?= e(ago($r['occurred_at'])) ?></span></a>
  <?php endforeach ?></div>
  <?php endif ?>
  <?php endif ?>
</div>
