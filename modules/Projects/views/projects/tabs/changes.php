<?php
$sum = ['approved' => 0, 'pending' => 0];
$cost = 0.0; $hrs = 0.0; $days = 0;
foreach ($crs as $c) { if (in_array($c['status'], ['approved', 'implemented'], true)) { $cost += (float) $c['cost_impact']; $hrs += (float) $c['hours_impact']; $days += (int) $c['deadline_impact_days']; $sum['approved']++; } elseif ($c['status'] === 'pending') { $sum['pending']++; } }
?>
<div class="grid g4 mb">
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= $sum['pending'] ?></div><div class="lbl">Pending decisions</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= $sum['approved'] ?></div><div class="lbl">Approved / implemented</div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num num-sm"><?= ($cost > 0 ? '+' : '').e(money($cost)) ?></div><div class="lbl">Approved cost impact</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('timer') ?></span><div><div class="num num-sm"><?= ($hrs > 0 ? '+' : '').e((float) $hrs) ?>h · <?= ($days > 0 ? '+' : '').$days ?>d</div><div class="lbl">Hours &amp; deadline impact</div></div></div>
</div>
<div class="card">
  <div class="card-head"><div><h3>Change requests</h3><p class="xs muted mb0">Every scope change, with its cost, effort and deadline impact.</p></div><?php if ($canCr): ?><button type="button" class="btn primary sm" data-modal="cr-new"><?= icon('plus', 'sm') ?> Log change request</button><?php endif ?></div>
  <?php if (!$crs): ?><?= empty_state('No change requests', 'Scope changes requested by the client will be tracked here.', 'clients-handshake') ?><?php else: ?>
  <div class="cr-list">
    <?php foreach ($crs as $c): ?>
    <article class="cr-item">
      <div class="flex between wrap">
        <div><b><?= e($c['title']) ?></b><div class="xs muted">Requested <?= $c['requested_by'] ? 'by '.e($c['requested_by']).' ' : '' ?>on <?= e(fdate($c['requested_on'])) ?><?= $c['creator'] ? ' · logged by '.e($c['creator']) : '' ?></div></div>
        <?= badge($c['status']) ?>
      </div>
      <?php if ($c['description']): ?><p class="small mt-s mb0"><?= nl2br(e($c['description'])) ?></p><?php endif ?>
      <div class="chips mt-s">
        <span class="chip"><?= icon('wallet', 'sm') ?> <?= $c['cost_impact'] === null ? 'No cost impact' : e(((float) $c['cost_impact'] > 0 ? '+' : '').money($c['cost_impact'])) ?></span>
        <span class="chip"><?= icon('timer', 'sm') ?> <?= $c['hours_impact'] === null ? 'No effort impact' : e(((float) $c['hours_impact'] > 0 ? '+' : '').(float) $c['hours_impact'].'h') ?></span>
        <span class="chip"><?= icon('calendar', 'sm') ?> <?= $c['deadline_impact_days'] === null ? 'No deadline impact' : e(((int) $c['deadline_impact_days'] > 0 ? '+' : '').(int) $c['deadline_impact_days'].' days') ?></span>
      </div>
      <?php if ($c['decided_name']): ?><div class="xs muted mt-s"><?= e(ucfirst($c['status'])) ?> by <?= e($c['decided_name']) ?> · <?= e(fdatetime($c['decided_at'])) ?></div><?php endif ?>
      <?php if ($canCr && $c['status'] !== 'implemented'): ?>
      <div class="flex wrap mt-s">
        <?php if ($c['status'] === 'pending'): ?>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/status')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="status" value="approved"><?php if ($canManage): ?><label class="check xs"><input type="checkbox" name="apply" value="1" checked> Apply impact to budget, estimate &amp; deadline</label><?php endif ?><button class="btn sm mint"><?= icon('check', 'sm') ?> Approve</button></form>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/status')) ?>" data-confirm="Reject this change request?"><?= csrf_field() ?><input type="hidden" name="status" value="rejected"><button class="btn sm danger"><?= icon('x', 'sm') ?> Reject</button></form>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/delete')) ?>" data-confirm="Delete this change request?"><?= csrf_field() ?><button class="btn sm ghost" aria-label="Delete"><?= icon('trash', 'sm') ?></button></form>
        <?php elseif ($c['status'] === 'approved'): ?>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="implemented"><button class="btn sm soft"><?= icon('package', 'sm') ?> Mark implemented</button></form>
        <?php elseif ($c['status'] === 'rejected'): ?>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="pending"><button class="btn sm ghost"><?= icon('refresh', 'sm') ?> Reopen</button></form>
        <?php endif ?>
      </div>
      <?php endif ?>
    </article>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php if ($canCr): ?>
<dialog class="modal" id="cr-new">
  <div class="modal-head"><h3>Log change request</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/projects/'.$pr['id'].'/change-requests')) ?>"><?= csrf_field() ?>
    <div class="field"><label>Title *</label><input type="text" name="title" required maxlength="190" placeholder="e.g. Add multilingual support"></div>
    <div class="field"><label>Description</label><textarea name="description" rows="3" placeholder="What changed and why"></textarea></div>
    <div class="form-grid">
      <div class="field"><label>Requested by</label><input type="text" name="requested_by" maxlength="150" placeholder="Client contact"></div>
      <div class="field"><label>Requested on</label><input type="date" name="requested_on" value="<?= e(date('Y-m-d')) ?>"></div>
    </div>
    <div class="form-grid g3">
      <div class="field"><label>Cost impact (<?= e(currency_symbol()) ?>)</label><input type="number" step="0.01" name="cost_impact"></div>
      <div class="field"><label>Hours impact</label><input type="number" step="0.25" name="hours_impact"></div>
      <div class="field"><label>Deadline impact (days)</label><input type="number" step="1" name="deadline_impact_days"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Log request</button></div>
  </form>
</dialog>
<?php endif ?>
