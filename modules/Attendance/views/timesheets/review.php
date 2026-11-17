<?php
/* Review: rejected time entries of the reviewer's team waiting for a fix (never their own), by person and date range, with re-approve.
   Entries are approved as they are logged; reviewers reject an entry or a day from the Day or Week view. $p (page), $person, $people, $from, $to, $stats */
use Modules\Attendance\Services\{Timesheets, TimeService};
$pj = feature_on('projects');
?>
<?= partial('attendance::partials/hub', ['tab' => 'review', 'title' => 'Time & Attendance', 'subtitle' => 'Time is approved as it is logged. Rejected entries wait here until they are fixed or approved again.']) ?>
<section class="ta-stats" aria-label="Rejected time">
  <div class="ta-stat c-red<?= (int) $stats['n'] ? ' is-alert' : ' is-zero' ?>"><span class="k"><i></i>Awaiting fixes</span><span class="v"><?= (int) $stats['n'] ?></span><span class="s"><?= (int) $stats['n'] ? 'rejected entr'.((int) $stats['n'] === 1 ? 'y' : 'ies') : 'nothing to follow up' ?></span></div>
  <div class="ta-stat c-lav"><span class="k"><i></i>Rejected time</span><span class="v"><?= e(hours((int) $stats['secs'])) ?></span><span class="s">not counted as approved</span></div>
  <div class="ta-stat c-sky"><span class="k"><i></i>People</span><span class="v"><?= (int) $stats['people'] ?></span><span class="s"><?= (int) $stats['days'] ?> day<?= (int) $stats['days'] === 1 ? '' : 's' ?> with rejected time</span></div>
</section>
<section class="card">
  <div class="ap-filters">
    <p class="small muted mb0">To reject time, open a person's <b>Day</b> or <b>Week</b> and click an entry, or use <b>Reject day</b>.</p>
    <form method="get" action="<?= e(url('/timesheets/review')) ?>">
      <select name="user" data-autosubmit aria-label="Person"><option value="">Everyone</option><?php foreach ($people as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($id, $person) ?>><?= e($n) ?></option><?php endforeach ?></select>
      <label class="rvw-d"><span class="xs muted">From</span><input type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" data-autosubmit aria-label="From date"></label>
      <label class="rvw-d"><span class="xs muted">To</span><input type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" data-autosubmit aria-label="To date"></label>
      <?php if ($person || $from || $to): ?><a class="btn sm ghost" href="<?= e(url('/timesheets/review')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
    </form>
  </div>
  <?php if (!$p['items']): ?><?= empty_state('No rejected time', $person || $from || $to ? 'Nothing rejected matches these filters.' : 'Every entry of your team is approved.', 'celebrate') ?><?php else: ?>
  <div class="table-wrap"><table class="table ap-table rvw-table">
    <thead><tr><th>Person</th><th>Entry</th><th class="right">Time</th><th>Reason</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $t): $d = substr($t['started_at'], 0, 10); $day = url('/time/daily?user='.$t['user_id'].'&date='.$d); $locked = Timesheets::payrollLocked($d); ?><tr>
      <td class="ap-p"><div class="flex"><?= avatar($t['name'], $t['avatar'], 'sm') ?><div class="grow"><b class="truncate d-block"><?= e($t['name']) ?></b><div class="xs muted truncate"><?= e($t['designation'] ?: '-') ?></div></div></div></td>
      <td class="rvw-e"><a href="<?= e($day) ?>"><b><?= e(fdate($d, 'D, d M')) ?></b> <span class="muted"><?= e(ftime($t['started_at'])) ?> - <?= e(ftime((string) $t['ended_at'])) ?></span></a><span class="xs muted d-block truncate"><?= e(TimeService::label($t)) ?><?= $pj && $t['task_title'] ? ' · '.e(str_limit($t['task_title'], 50)) : '' ?><?= $t['description'] ? ' · '.e(str_limit($t['description'], 60)) : '' ?></span></td>
      <td class="right ap-t"><b><?= e(hours((int) $t['duration_seconds'])) ?></b></td>
      <td class="rvw-r"><span class="pill pill-red"><?= icon('alert', 'sm') ?> Rejected</span><div class="small"><?= e(str_limit((string) ($t['review_note'] ?: 'No reason given.'), 140)) ?></div><div class="xs muted"><?= $t['reviewer_name'] ? 'by '.e($t['reviewer_name']) : '' ?><?= $t['reviewed_at'] ? ' · '.e(fdatetime($t['reviewed_at'])) : '' ?></div></td>
      <td class="actions"><a class="btn icon sm ghost" href="<?= e($day) ?>" aria-label="Open the day of <?= e($t['name']) ?>, <?= e(fdate($d)) ?>" title="Open day"><?= icon('eye', 'sm') ?></a>
        <?php if (!$locked): ?><form method="post" action="<?= e(url('/time/entries/'.$t['id'].'/approve')) ?>"><?= csrf_field() ?><button class="btn sm mint"><?= icon('check', 'sm') ?> Approve</button></form><?php else: ?><span class="muted" title="Payroll for this month is finalized"><?= icon('lock', 'sm') ?></span><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</section>
