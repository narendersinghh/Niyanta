<?php $own = $target === (int) user()['id']; ?>
<?= partial('partials/page_head', ['title' => $own ? 'My screenshots' : 'Screenshots · '.$targetName, 'subtitle' => 'Captured only while a timer was running, with consent. Kept for '.$retention.' days.', 'crumbs' => [['Work'], ['Time Tracker', '/time'], ['Screenshots']]]) ?>
<?php if (!$enabled): ?><div class="alert warn"><?= icon('info', 'sm') ?> Screenshot monitoring is currently turned off. Existing images remain until their retention period ends.</div><?php endif ?>
<div class="card">
  <form class="filters" method="get">
    <?php if ($people): ?><select name="user" data-autosubmit aria-label="Person"><?php foreach ($people as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($id, $target) ?>><?= e($n) ?><?= (int) $id === (int) user()['id'] ? ' (me)' : '' ?></option><?php endforeach ?></select><?php endif ?>
    <input type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" data-autosubmit aria-label="Date">
    <?php if ($date): ?><a class="btn sm ghost" href="<?= e(url('/time/screenshots'.($own ? '' : '?user='.$target))) ?>">All dates</a><?php endif ?>
    <span class="muted small"><?= (int) $p['total'] ?> screenshots</span>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No screenshots', $own ? 'Screenshots appear here when screen capture is enabled during a running timer.' : 'Nothing was captured for this person in the selected period.', 'security-shield') ?><?php else: ?>
  <div class="shots">
    <?php foreach ($p['items'] as $s): $src = url('/time/screenshots/'.$s['id'].'/image'); ?>
    <figure><a href="<?= e($src) ?>" target="_blank" rel="noopener"><img src="<?= e($src) ?>" alt="Screenshot <?= e(fdatetime($s['taken_at'])) ?>" loading="lazy"></a>
      <figcaption><div class="shot-meta"><b><?= e(ftime($s['taken_at'])) ?></b><span><?= e(fdate($s['taken_at'], 'd M')) ?></span></div>
        <div class="shot-meta"><span class="truncate"><?= e($s['project_name'] ?? ($s['description'] ?: 'No project')) ?></span><?php if ($s['activity_pct'] !== null): ?><span class="pill pill-<?= $s['activity_pct'] >= 60 ? 'mint' : ($s['activity_pct'] >= 30 ? 'lemon' : 'peach') ?>"><?= (int) $s['activity_pct'] ?>%</span><?php endif ?></div>
        <div class="xs"><?= $s['source'] === 'agent' ? 'Desktop agent' : 'Browser' ?></div></figcaption></figure>
    <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
