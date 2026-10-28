<?php
$lines = static fn (?string $s) => array_values(array_filter(array_map(static fn ($l) => ltrim(trim($l), "-*• \t"), preg_split('/\r?\n/', (string) $s))));
$period = fdate($r['period_start']).($r['period_end'] !== $r['period_start'] ? ' – '.fdate($r['period_end']) : '');
$actions = $mine && $r['status'] !== 'approved' ? '<a class="btn ghost" href="'.e(url('/work-reports/'.$r['id'].'/edit')).'">'.icon('edit', 'sm').' '.($r['status'] === 'rejected' ? 'Edit &amp; resubmit' : 'Edit').'</a><form method="post" action="'.e(url('/work-reports/'.$r['id'].'/delete')).'" data-confirm="Delete this report?">'.csrf_field().'<button class="btn icon danger" aria-label="Delete">'.icon('trash', 'sm').'</button></form>' : '';
?>
<?= partial('partials/page_head', ['title' => ucfirst($r['type']).' report', 'subtitle' => $period, 'crumbs' => [['Work reports', '/work-reports'.($mine ? '' : '?tab=team')], [$period]], 'actions' => $actions]) ?>
<div class="grid g-side">
  <div class="stack">
    <div class="card">
      <div class="flex between wrap mb"><div class="flex"><?= avatar($r['author'], $r['author_avatar']) ?><div><b><?= e($r['author']) ?></b><div class="xs muted"><?= e($r['designation'] ?: 'Team member') ?> · submitted <?= e(ago($r['created_at'])) ?></div></div></div><?= badge($r['status']) ?></div>
      <h4>Summary</h4>
      <div class="prose"><?= nl2br(e($r['summary'])) ?></div>
    </div>
    <div class="grid g2">
      <div class="card soft-mint flat noborder"><h4><?= icon('check-circle', 'sm') ?> Accomplishments</h4><?php if ($a = $lines($r['accomplishments'])): ?><ul class="checklist small"><?php foreach ($a as $l): ?><li><?= icon('check', 'sm') ?> <?= e($l) ?></li><?php endforeach ?></ul><?php else: ?><p class="small muted mb0">—</p><?php endif ?></div>
      <div class="card soft-peach flat noborder"><h4><?= icon('alert', 'sm') ?> Blockers</h4><?php if ($b = $lines($r['blockers'])): ?><ul class="checklist small"><?php foreach ($b as $l): ?><li><?= icon('alert', 'sm') ?> <?= e($l) ?></li><?php endforeach ?></ul><?php else: ?><p class="small muted mb0">No blockers 🎉</p><?php endif ?></div>
    </div>
    <div class="card soft-sky flat noborder"><h4><?= icon('arrow-right', 'sm') ?> Next steps</h4><?php if ($n = $lines($r['next_steps'])): ?><ul class="checklist small"><?php foreach ($n as $l): ?><li><?= icon('chevron-right', 'sm') ?> <?= e($l) ?></li><?php endforeach ?></ul><?php else: ?><p class="small muted mb0">—</p><?php endif ?></div>
    <?php if ($tasks): ?>
    <div class="card"><div class="card-head"><h4>Tasks completed in this period</h4><span class="pill pill-mint"><?= count($tasks) ?></span></div>
      <div class="list"><?php foreach ($tasks as $t): ?><a class="list-item" href="<?= e(url('/tasks/'.$t['id'])) ?>"><span class="dotc bg-mint"></span><div class="grow"><b><?= e($t['title']) ?></b><div class="meta"><?= e($t['project']) ?></div></div><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?></div></div>
    <?php endif ?>
  </div>
  <div class="stack">
    <div class="card">
      <h4>Details</h4>
      <dl class="kv">
        <dt>Type</dt><dd><?= e(ucfirst($r['type'])) ?></dd>
        <dt>Period</dt><dd><?= e($period) ?></dd>
        <dt>Project</dt><dd><?= e($r['project_name'] ?? 'General') ?></dd>
        <dt>Hours reported</dt><dd><?= $r['hours'] !== null ? e((float) $r['hours']).'h' : '—' ?></dd>
        <dt>Time entries</dt><dd><?= e(hours($logged)) ?></dd>
        <?php if ($r['reviewer']): ?><dt>Reviewed by</dt><dd><?= e($r['reviewer']) ?><div class="xs muted"><?= e(fdatetime($r['reviewed_at'])) ?></div></dd><?php endif ?>
      </dl>
      <?php if ($r['review_note']): ?><div class="alert <?= $r['status'] === 'rejected' ? 'error' : 'ok' ?> mt mb0 small"><?= icon('message', 'sm') ?><div><?= e($r['review_note']) ?></div></div><?php endif ?>
    </div>
    <?php if ($canReview): ?>
    <div class="card">
      <h4>Review</h4>
      <form class="form" method="post" action="<?= e(url('/work-reports/'.$r['id'].'/review')) ?>"><?= csrf_field() ?>
        <div class="field"><label>Note <span class="muted">(required when rejecting)</span></label><textarea name="review_note" rows="3" maxlength="500" placeholder="Feedback for <?= e(explode(' ', $r['author'])[0]) ?>"></textarea><?= error_for('review_note') ?></div>
        <div class="flex"><button name="decision" value="approved" class="btn mint grow"><?= icon('check', 'sm') ?> Approve</button><button name="decision" value="rejected" class="btn danger grow"><?= icon('x', 'sm') ?> Reject</button></div>
      </form>
    </div>
    <?php endif ?>
  </div>
</div>
