<?php
use Modules\Projects\Services\ProjectService as PS;
$time = PS::on('time');
$mine = $stats['mine'];
$actions = $canSubmit ? '<a class="btn primary" href="'.e(url('/work-reports/create')).'">'.icon('plus', 'sm').' New report</a><details class="dd"><summary class="btn ghost more-btn" aria-label="More report actions" title="More"><span class="sr">More</span>'.icon('more').'</summary><div class="dd-menu"><a href="'.e(url('/work-reports/create?type=weekly')).'">'.icon('calendar', 'sm').' Weekly report</a>'.($canApprove ? '<a href="'.e(url('/work-reports?tab=team&status=submitted')).'">'.icon('check-circle', 'sm').' Review pending reports</a>' : '').'</div></details>' : ($canApprove ? '<a class="btn primary" href="'.e(url('/work-reports?tab=team&status=submitted')).'">'.icon('check-circle', 'sm').' Review pending</a>' : '');
$filters = ['type' => (string) ($_GET['type'] ?? ''), 'status' => (string) ($_GET['status'] ?? ''), 'user' => (string) ($_GET['user'] ?? '')];
?>
<div class="pj">
<?= partial('partials/page_head', ['title' => 'Work reports', 'subtitle' => 'Daily & weekly updates: what got done, what is blocked, what is next.', 'crumbs' => [['Work'], ['Work reports']], 'actions' => $actions]) ?>
<?php if ($canSubmit && !$stats['submittedToday'] && $tab === 'mine' && (int) date('N') <= 5): ?>
<section class="card hero mb report-hero"><div><span class="pill"><?= icon('calendar', 'sm') ?> <?= e(date('l, d M')) ?></span><h2 class="mt">How did today go?</h2><p><?= $time ? 'You have logged <b>'.e(hours($stats['week'])).'</b> this week. ' : '' ?>Take two minutes to share today's progress with your manager.</p><div class="hero-actions"><a class="btn primary" href="<?= e(url('/work-reports/create')) ?>"><?= icon('edit', 'sm') ?> Write today's report</a></div></div><?= illus('reports-chart') ?></section>
<?php endif ?>
<div class="grid g4 mb">
  <?php if ($canApprove): ?><a class="tile bg-lemon" href="<?= e(url('/work-reports?tab=team&status=submitted')) ?>"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $stats['pending'] ?></div><div class="lbl">Awaiting your review</div></div></a><?php endif ?>
  <?php if ($canSubmit): ?>
  <a class="tile bg-sky" href="<?= e(url('/work-reports?status=submitted')) ?>"><span class="tile-ic"><?= icon('send') ?></span><div><div class="num"><?= (int) ($mine['submitted'] ?? 0) ?></div><div class="lbl">My reports in review</div></div></a>
  <a class="tile bg-mint" href="<?= e(url('/work-reports?status=approved')) ?>"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($mine['approved'] ?? 0) ?></div><div class="lbl">Approved</div></div></a>
  <?php if ($time): ?><div class="tile bg-lav"><span class="tile-ic"><?= icon('timer') ?></span><div><div class="num num-sm"><?= e(hours($stats['week'])) ?></div><div class="lbl">Logged this week</div></div></div>
  <?php else: ?><a class="tile bg-peach" href="<?= e(url('/work-reports?status=rejected')) ?>"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) ($mine['rejected'] ?? 0) ?></div><div class="lbl">Need changes</div></div></a><?php endif ?>
  <?php endif ?>
</div>
<form class="toolbar" method="get">
  <?php if ($canApprove && $canSubmit): ?><nav class="tabs" aria-label="Whose reports"><a href="<?= e(url('/work-reports')) ?>" class="<?= $tab === 'mine' ? 'active' : '' ?>"><?= icon('user', 'sm') ?> Mine</a><a href="<?= e(url('/work-reports?tab=team')) ?>" class="<?= $tab === 'team' ? 'active' : '' ?>"><?= icon('users', 'sm') ?> Team<?= $stats['pending'] ? ' <span class="tab-n hot">'.(int) $stats['pending'].'</span>' : '' ?></a></nav><?php endif ?>
  <?php if ($tab === 'team'): ?><input type="hidden" name="tab" value="team"><select name="user" data-autosubmit aria-label="Person"><option value="">Everyone</option><?php foreach ($people as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $filters['user']) ?>><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
  <select name="type" data-autosubmit aria-label="Type"><option value="">Daily &amp; weekly</option><option value="daily"<?= selected('daily', $filters['type']) ?>>Daily</option><option value="weekly"<?= selected('weekly', $filters['type']) ?>>Weekly</option></select>
  <select name="status" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['submitted' => 'In review', 'approved' => 'Approved', 'rejected' => 'Needs changes'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $filters['status']) ?>><?= $l ?></option><?php endforeach ?></select>
  <?php if (array_filter($filters)): ?><a class="clear" href="<?= e(url('/work-reports'.($tab === 'team' ? '?tab=team' : ''))) ?>">Clear filters</a><?php endif ?>
  <noscript><button class="btn ghost sm">Apply</button></noscript>
  <span class="xs muted ml-a"><?= (int) $p['total'] ?> report<?= $p['total'] == 1 ? '' : 's' ?></span>
</form>
<div class="card">
  <?php if (!$p['items']): ?><?= empty_state(array_filter($filters) ? 'No reports match' : ($tab === 'team' ? 'No team reports yet' : 'No reports yet'), array_filter($filters) ? 'Try other filters.' : ($tab === 'team' ? 'Reports submitted by your team will appear here for review.' : 'Your daily and weekly reports will show up here.'), 'reports-chart', $tab === 'mine' && $canSubmit && !array_filter($filters) ? '<a class="btn primary mt" href="'.e(url('/work-reports/create')).'">'.icon('plus', 'sm').' Write a report</a>' : '') ?><?php else: ?>
  <div class="rlist">
    <?php foreach ($p['items'] as $r): ?>
    <a class="ritem" href="<?= e(url('/work-reports/'.$r['id'])) ?>">
      <span class="r-date ac-<?= $r['type'] === 'weekly' ? 'lav' : 'sky' ?>"><b><?= e(date('d', strtotime($r['period_start']))) ?></b><span><?= e(date('M', strtotime($r['period_start']))) ?></span></span>
      <div class="grow">
        <div class="r-top"><?php if ($tab === 'team'): ?><span class="who"><?= avatar($r['author'], $r['avatar'], 'sm') ?> <?= e($r['author']) ?></span><?php endif ?><span class="pill pill-<?= $r['type'] === 'weekly' ? 'lav' : 'sky' ?>"><?= e(ucfirst($r['type'])) ?></span><span><?= e(fdate($r['period_start'])) ?><?= $r['period_end'] !== $r['period_start'] ? ' – '.e(fdate($r['period_end'])) : '' ?></span><?php if ($r['project']): ?><span>· <?= e($r['project']) ?></span><?php endif ?></div>
        <p class="r-sum"><?= e(str_limit($r['summary'], 220)) ?></p>
        <?php if ($r['status'] === 'rejected' && $r['review_note']): ?><p class="xs late-text mb0 mt-s"><?= icon('alert', 'sm') ?> <?= e(str_limit($r['review_note'], 120)) ?></p><?php endif ?>
      </div>
      <div class="r-side"><?= badge($r['status'], ['submitted' => 'In review', 'approved' => 'Approved', 'rejected' => 'Needs changes'][$r['status']] ?? null) ?><?php if ($r['hours'] !== null): ?><span class="h"><?= e((float) $r['hours']) ?>h</span><?php endif ?></div>
    </a>
    <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
</div>
