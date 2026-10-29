<?php
$lines = static fn (?string $s) => array_values(array_filter(array_map(static fn ($l) => ltrim(trim($l), "-*• \t"), preg_split('/\r?\n/', (string) $s))));
$period = fdate($r['period_start']).($r['period_end'] !== $r['period_start'] ? ' – '.fdate($r['period_end']) : '');
$labels = ['submitted' => 'In review', 'approved' => 'Approved', 'rejected' => 'Needs changes'];
$actions = $mine && $r['status'] !== 'approved' ? '<a class="btn primary" href="'.e(url('/work-reports/'.$r['id'].'/edit')).'">'.icon('edit', 'sm').' '.($r['status'] === 'rejected' ? 'Edit &amp; resubmit' : 'Edit report').'</a><details class="dd"><summary class="btn ghost more-btn" aria-label="More report actions" title="More">'.icon('more').'</summary><div class="dd-menu"><form method="post" action="'.e(url('/work-reports/'.$r['id'].'/delete')).'" data-confirm="Delete this report?">'.csrf_field().'<button class="danger-text">'.icon('trash', 'sm').' Delete report</button></form></div></details>' : '';
$block = static function (string $title, string $ic, string $color, array $items, string $cls, string $itemIcon, string $none): string {
  $h = '<section class="card ac-'.$color.'"><div class="sec-head"><span class="sec-ic">'.icon($ic).'</span><h3>'.e($title).'</h3>'.($items ? '<span class="pill pill-gray ml-a">'.count($items).'</span>' : '').'</div>';
  if (!$items) { return $h.'<p class="small muted mb0">'.e($none).'</p></section>'; }
  $h .= '<ul class="checklist small '.$cls.'">';
  foreach ($items as $l) { $h .= '<li>'.icon($itemIcon, 'sm').' <span>'.e($l).'</span></li>'; }
  return $h.'</ul></section>'; };
?>
<div class="pj">
<?= partial('partials/page_head', ['title' => ucfirst($r['type']).' report', 'subtitle' => $period.($r['project_name'] && feature_on('projects') ? ' · '.$r['project_name'] : ''), 'crumbs' => [['Work'], ['Work reports', '/work-reports'.($mine ? '' : '?tab=team')], [$period]], 'actions' => $actions]) ?>
<div class="grid g-side">
  <div class="stack">
    <section class="card">
      <div class="flex between wrap mb"><div class="author"><?= avatar($r['author'], $r['author_avatar']) ?><div><b><?= e($r['author']) ?></b><div class="xs muted"><?= e($r['designation'] ?: 'Team member') ?> · submitted <?= e(ago($r['created_at'])) ?></div></div></div><?= badge($r['status'], $labels[$r['status']] ?? null) ?></div>
      <h4>Summary</h4>
      <div class="prose"><?= nl2br(e($r['summary'])) ?></div>
    </section>
    <?= $block('Accomplishments', 'check-circle', 'mint', $lines($r['accomplishments']), '', 'check', 'Nothing listed.') ?>
    <div class="grid g2">
      <?= $block('Blockers', 'alert', 'peach', $lines($r['blockers']), 'warn', 'alert', 'No blockers, smooth sailing.') ?>
      <?= $block('Next steps', 'arrow-right', 'sky', $lines($r['next_steps']), 'next', 'chevron-right', 'Nothing planned yet.') ?>
    </div>
    <?php if ($tasks): ?>
    <section class="card ac-lav"><div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('check-square') ?></span><h3>Tasks completed in this period</h3></div><span class="pill pill-mint"><?= count($tasks) ?></span></div>
      <div class="urows"><?php foreach ($tasks as $t): ?><a class="urow" href="<?= e(url('/tasks/'.$t['id'])) ?>"><span class="dotc bg-mint"></span><div class="grow"><b><?= e($t['title']) ?></b><div class="meta"><?= e($t['project']) ?></div></div><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?></div></section>
    <?php endif ?>
  </div>
  <aside class="stack">
    <?php if ($canReview): ?>
    <section class="card ac-lemon">
      <div class="sec-head"><span class="sec-ic"><?= icon('eye') ?></span><h3>Your review</h3></div>
      <form class="form" method="post" action="<?= e(url('/work-reports/'.$r['id'].'/review')) ?>"><?= csrf_field() ?>
        <div class="field<?= error_for('review_note') ? ' has-error' : '' ?>"><label for="rv-note">Note <span class="muted">(required when asking for changes)</span></label><textarea id="rv-note" name="review_note" rows="3" maxlength="500" placeholder="Feedback for <?= e(explode(' ', $r['author'])[0]) ?>"><?= e(old('review_note')) ?></textarea><?= error_for('review_note') ?></div>
        <div class="review-acts"><button name="decision" value="approved" class="btn primary"><?= icon('check', 'sm') ?> Approve</button><button name="decision" value="rejected" class="btn ghost"><?= icon('refresh', 'sm') ?> Request changes</button></div>
      </form>
    </section>
    <?php endif ?>
    <section class="card">
      <h4>Details</h4>
      <div class="kv-list">
        <div class="kv-row"><span><?= icon('clipboard', 'sm') ?> Type</span><span><?= e(ucfirst($r['type'])) ?></span></div>
        <div class="kv-row"><span><?= icon('calendar', 'sm') ?> Period</span><span><?= e($period) ?></span></div>
        <?php if (feature_on('projects')): ?><div class="kv-row"><span><?= icon('folder', 'sm') ?> Project</span><span><?= e($r['project_name'] ?? 'General') ?></span></div><?php endif ?>
        <div class="kv-row"><span><?= icon('clock', 'sm') ?> Hours reported</span><b><?= $r['hours'] !== null ? e((float) $r['hours']).'h' : '-' ?></b></div>
        <?php if ($logged !== null): ?><div class="kv-row"><span><?= icon('timer', 'sm') ?> Time entries</span><b><?= e(hours($logged)) ?></b></div><?php endif ?>
        <?php if ($r['reviewer']): ?><div class="kv-row"><span><?= icon('user', 'sm') ?> Reviewed by</span><span><?= e($r['reviewer']) ?><span class="xs muted db"><?= e(fdatetime($r['reviewed_at'])) ?></span></span></div><?php endif ?>
      </div>
      <?php if ($r['review_note']): ?><div class="alert <?= $r['status'] === 'rejected' ? 'error' : 'ok' ?> mt mb0 small"><?= icon('message', 'sm') ?><div><?= e($r['review_note']) ?></div></div><?php endif ?>
    </section>
  </aside>
</div>
</div>
