<?php
$sum = ['approved' => 0, 'pending' => 0];
$cost = 0.0; $hrs = 0.0; $days = 0;
foreach ($crs as $c) { if (in_array($c['status'], ['approved', 'implemented'], true)) { $cost += (float) $c['cost_impact']; $hrs += (float) $c['hours_impact']; $days += (int) $c['deadline_impact_days']; $sum['approved']++; } elseif ($c['status'] === 'pending') { $sum['pending']++; } }
$sign = static fn (float $v) => $v > 0 ? '+' : '';
$impact = static function (?string $v, string $icon, string $none, callable $fmt): string {
  if ($v === null) { return '<span class="muted">'.icon($icon, 'sm').' '.e($none).'</span>'; }
  $n = (float) $v;
  return '<span class="'.($n > 0 ? 'up' : ($n < 0 ? 'down' : '')).'">'.icon($icon, 'sm').' '.e(($n > 0 ? '+' : '').$fmt($n)).'</span>'; };
?>
<div class="grid g4 mb">
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= $sum['pending'] ?></div><div class="lbl">Awaiting decision</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= $sum['approved'] ?></div><div class="lbl">Approved / implemented</div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num num-sm"><?= $sign($cost).e(money($cost)) ?></div><div class="lbl">Approved cost impact</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('timer') ?></span><div><div class="num num-sm"><?= $sign($hrs).e((float) $hrs) ?>h · <?= $sign($days).$days ?>d</div><div class="lbl">Effort &amp; deadline impact</div></div></div>
</div>
<div class="card">
  <div class="card-head"><div><h3>Change requests</h3><p class="xs muted mb0">Every scope change with its cost, effort and deadline impact.</p></div></div>
  <?php if (!$crs): ?><?= empty_state('No change requests', 'When the client asks for something outside the agreed scope, log it here to keep budget and deadline honest.', 'empty-box', $canCr ? '<button type="button" class="btn primary mt" data-modal="cr-new">'.icon('plus', 'sm').' Log change request</button>' : '') ?><?php else: ?>
  <div class="cr-list">
    <?php foreach ($crs as $c): ?>
    <article class="cr-item st-<?= e($c['status']) ?>">
      <div class="flex between wrap">
        <div class="grow"><b><?= e($c['title']) ?></b><div class="xs muted">Requested <?= $c['requested_by'] ? 'by '.e($c['requested_by']).' ' : '' ?>on <?= e(fdate($c['requested_on'])) ?><?= $c['creator'] ? ' · logged by '.e($c['creator']) : '' ?></div></div>
        <?= badge($c['status']) ?>
      </div>
      <?php if ($c['description']): ?><p class="small mb0"><?= nl2br(e($c['description'])) ?></p><?php endif ?>
      <div class="cr-impact">
        <?= $impact($c['cost_impact'], 'wallet', 'No cost impact', static fn ($n) => money($n)) ?>
        <?= $impact($c['hours_impact'], 'timer', 'No effort impact', static fn ($n) => ((float) $n).'h') ?>
        <?= $impact($c['deadline_impact_days'], 'calendar', 'No deadline impact', static fn ($n) => ((int) $n).' days') ?>
      </div>
      <?php if ($c['decided_name']): ?><div class="xs muted"><?= e(ucfirst($c['status'])) ?> by <?= e($c['decided_name']) ?> · <?= e(fdatetime($c['decided_at'])) ?></div><?php endif ?>
      <?php if ($canCr && $c['status'] !== 'implemented'): ?>
      <div class="cr-acts">
        <?php if ($c['status'] === 'pending'): ?>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/status')) ?>" class="flex wrap"><?= csrf_field() ?><input type="hidden" name="status" value="approved"><button class="btn sm primary"><?= icon('check', 'sm') ?> Approve</button><?php if ($canManage): ?><label class="check xs"><input type="checkbox" name="apply" value="1" checked> Apply impact to budget, estimate &amp; deadline</label><?php endif ?></form>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/status')) ?>" data-confirm="Reject this change request?" class="ml-a"><?= csrf_field() ?><input type="hidden" name="status" value="rejected"><button class="btn sm ghost"><?= icon('x', 'sm') ?> Reject</button></form>
          <form method="post" action="<?= e(url('/change-requests/'.$c['id'].'/delete')) ?>" data-confirm="Delete this change request?"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Delete change request" title="Delete"><?= icon('trash', 'sm') ?></button></form>
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
<dialog class="modal" id="cr-new" aria-labelledby="cr-new-title">
  <div class="modal-head"><div><h3 id="cr-new-title">Log change request</h3><p class="xs muted mb0">Use negative numbers when the change reduces cost, effort or time.</p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/projects/'.$pr['id'].'/change-requests')) ?>"><?= csrf_field() ?>
    <div class="field"><label for="cr-title">Title *</label><input id="cr-title" type="text" name="title" required maxlength="190" placeholder="e.g. Add multilingual support"></div>
    <div class="field"><label for="cr-desc">Description</label><textarea id="cr-desc" name="description" rows="3" placeholder="What changed and why"></textarea></div>
    <div class="form-grid">
      <div class="field"><label for="cr-by">Requested by</label><input id="cr-by" type="text" name="requested_by" maxlength="150" placeholder="Client contact"></div>
      <div class="field"><label for="cr-on">Requested on</label><input id="cr-on" type="date" name="requested_on" value="<?= e(date('Y-m-d')) ?>"></div>
    </div>
    <div class="form-grid g3">
      <div class="field"><label for="cr-cost">Cost impact (<?= e(currency_symbol()) ?>)</label><input id="cr-cost" type="number" step="0.01" name="cost_impact"></div>
      <div class="field"><label for="cr-hrs">Hours impact</label><input id="cr-hrs" type="number" step="0.25" name="hours_impact"></div>
      <div class="field"><label for="cr-days">Deadline impact (days)</label><input id="cr-days" type="number" step="1" name="deadline_impact_days"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Log request</button></div>
  </form>
</dialog>
<?php endif ?>
