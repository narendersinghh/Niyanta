<?php
$actions = ($canSubmit ? '<a class="btn ghost" href="'.e(url('/work-reports/create?type=weekly')).'">'.icon('calendar', 'sm').' Weekly report</a><a class="btn primary" href="'.e(url('/work-reports/create')).'">'.icon('plus', 'sm').' Daily report</a>' : '');
$mine = $stats['mine'];
?>
<?= partial('partials/page_head', ['title' => 'Work reports', 'subtitle' => 'Daily & weekly updates: what got done, what is blocked, what is next.', 'crumbs' => [['Work'], ['Work reports']], 'actions' => $actions]) ?>
<?php if ($canSubmit && !$stats['submittedToday'] && $tab === 'mine' && (int) date('N') <= 5): ?>
<div class="card hero mb report-hero"><div><span class="pill pill-lav"><?= e(date('l, d M')) ?></span><h2 class="mt">How did today go?</h2><p class="muted">You have logged <b><?= e(hours($stats['week'])) ?></b> this week. Take two minutes to share today's progress with your manager.</p><a class="btn primary" href="<?= e(url('/work-reports/create')) ?>"><?= icon('edit', 'sm') ?> Write today's report</a></div><?= illus('reports-chart') ?></div>
<?php endif ?>
<div class="grid g4 mb">
  <?php if ($canApprove): ?><a class="tile bg-lemon" href="<?= e(url('/work-reports?tab=team&status=submitted')) ?>"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $stats['pending'] ?></div><div class="lbl">Awaiting your review</div></div></a><?php endif ?>
  <?php if ($canSubmit): ?>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('send') ?></span><div><div class="num"><?= (int) ($mine['submitted'] ?? 0) ?></div><div class="lbl">My reports pending</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($mine['approved'] ?? 0) ?></div><div class="lbl">Approved</div></div></div>
  <div class="tile dark"><span class="tile-ic"><?= icon('timer') ?></span><div><div class="num num-sm"><?= e(hours($stats['week'])) ?></div><div class="lbl">Logged this week</div></div></div>
  <?php endif ?>
</div>
<div class="card">
  <div class="flex between wrap mb">
    <?php if ($canApprove && $canSubmit): ?><nav class="tabs"><a href="<?= e(url('/work-reports')) ?>" class="<?= $tab === 'mine' ? 'active' : '' ?>"><?= icon('user', 'sm') ?> My reports</a><a href="<?= e(url('/work-reports?tab=team')) ?>" class="<?= $tab === 'team' ? 'active' : '' ?>"><?= icon('users', 'sm') ?> Team reports<?= $stats['pending'] ? ' <span class="pill pill-lemon">'.(int) $stats['pending'].'</span>' : '' ?></a></nav><?php else: ?><h3 class="mb0"><?= $tab === 'team' ? 'Team reports' : 'My reports' ?></h3><?php endif ?>
    <form class="filters mb0" method="get">
      <?php if ($tab === 'team'): ?><input type="hidden" name="tab" value="team"><select name="user" data-autosubmit aria-label="Person"><option value="">Everyone</option><?php foreach ($people as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $_GET['user'] ?? '') ?>><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
      <select name="type" data-autosubmit aria-label="Type"><option value="">Daily &amp; weekly</option><option value="daily"<?= selected('daily', $_GET['type'] ?? '') ?>>Daily</option><option value="weekly"<?= selected('weekly', $_GET['type'] ?? '') ?>>Weekly</option></select>
      <select name="status" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $_GET['status'] ?? '') ?>><?= $l ?></option><?php endforeach ?></select>
    </form>
  </div>
  <?php if (!$p['items']): ?><?= empty_state($tab === 'team' ? 'No team reports' : 'No reports yet', $tab === 'team' ? 'Reports from your team will appear here.' : 'Your submitted daily and weekly reports will show up here.', 'reports-chart', $tab === 'mine' && $canSubmit ? '<a class="btn primary mt" href="'.e(url('/work-reports/create')).'">'.icon('plus', 'sm').' Write a report</a>' : '') ?><?php else: ?>
  <div class="report-list">
    <?php foreach ($p['items'] as $r): ?>
    <a class="report-item" href="<?= e(url('/work-reports/'.$r['id'])) ?>">
      <div class="r-date <?= $r['type'] === 'weekly' ? 'bg-lav' : 'bg-sky' ?>"><b><?= e(date('d', strtotime($r['period_start']))) ?></b><span><?= e(date('M', strtotime($r['period_start']))) ?></span></div>
      <div class="grow">
        <div class="flex wrap"><?php if ($tab === 'team'): ?><span class="flex b"><?= avatar($r['author'], $r['avatar'], 'sm') ?> <?= e($r['author']) ?></span><?php endif ?><span class="pill pill-gray"><?= e(ucfirst($r['type'])) ?></span><span class="xs muted"><?= e(fdate($r['period_start'])) ?><?= $r['period_end'] !== $r['period_start'] ? ' – '.e(fdate($r['period_end'])) : '' ?><?= $r['project'] ? ' · '.e($r['project']) : '' ?></span></div>
        <p class="small mb0 mt-s"><?= e(str_limit($r['summary'], 180)) ?></p>
        <?php if ($r['status'] === 'rejected' && $r['review_note']): ?><p class="xs late-text mb0"><?= icon('alert', 'sm') ?> <?= e(str_limit($r['review_note'], 120)) ?></p><?php endif ?>
      </div>
      <div class="r-side"><?= badge($r['status']) ?><span class="xs muted"><?= $r['hours'] !== null ? e((float) $r['hours']).'h' : '' ?></span></div>
    </a>
    <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
