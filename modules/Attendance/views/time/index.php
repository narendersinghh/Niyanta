<?php
use Modules\Attendance\Services\TimeService;
$hms = static fn (int $s): string => sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
$elapsed = $running ? TimeService::elapsed($running) : 0;
$prev = date('Y-m-d', strtotime($ws.' -7 days')); $next = date('Y-m-d', strtotime($ws.' +7 days'));
$isThisWeek = $ws === TimeService::weekStart();
$actions = '<button class="btn ghost" data-modal="entry-dialog" data-entry-new>'.icon('plus', 'sm').' Add time</button><a class="btn ghost" href="'.e(url('/timesheets?week='.$ws)).'">'.icon('calendar', 'sm').' Timesheet</a>'.($monitoring ? '<a class="btn soft" href="'.e(url('/time/screenshots')).'">'.icon('image', 'sm').' My screenshots</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Time Tracker', 'subtitle' => 'Track what you work on: start a timer or log time manually. Time is approved automatically.', 'crumbs' => [['Work'], ['Time Tracker']], 'actions' => $actions]) ?>
<div class="grid g-side">
  <div class="stack">
    <div class="tt-card<?= $running ? ' running' : '' ?>" data-tracker data-running="<?= $running ? 1 : 0 ?>" data-idle-min="<?= (int) $idleMinutes ?>">
      <div class="tt-top">
        <div class="flex wrap"><?php if ($running): ?><span class="tt-chip live" data-state-live><span class="dotc dot-in pulse"></span>Tracking</span><span class="tt-chip idle hide" data-state-idle><span class="dotc dot-break pulse"></span>Idle, are you there?</span><?php else: ?><span class="tt-chip"><span class="dotc dot-out"></span>Ready</span><?php endif ?></div>
        <span class="tt-chip"><?= icon('calendar', 'sm') ?> <?= e(date('D, d M')) ?></span>
      </div>
      <?php if ($running): ?>
        <div class="tt-main">
          <div><div class="tt-digits" data-tick data-timer data-base="<?= $elapsed ?>"><?= e($hms($elapsed)) ?></div><div class="tt-sub">Started at <b><?= e(ftime($running['started_at'])) ?></b><?= $running['source'] === 'agent' ? ' · via desktop agent' : '' ?></div></div>
          <form method="post" action="<?= e(url('/time/stop')) ?>"><?= csrf_field() ?><button class="tt-btn stop" aria-label="Stop timer" title="Stop timer"><?= icon('stop') ?></button></form>
        </div>
        <div class="tt-run-meta">
          <span class="pj"><?= e($running['project_name'] ?? 'No project') ?><?php if ($running['task_title']): ?> <span class="muted">· <?= e($running['task_title']) ?></span><?php endif ?></span>
          <span class="muted small"><?= e($running['description'] ?: 'No description') ?></span>
          <span class="flex wrap"><?= $running['billable'] ? '<span class="pill pill-lemon">Billable</span>' : '<span class="pill pill-gray">Non-billable</span>' ?><span class="small muted" data-idle-total>Idle recorded: <?= e(hours($running['idle_seconds'])) ?></span></span>
        </div>
        <div class="tt-foot"><span><?= icon('info', 'sm') ?> Keep this tab open while tracking. Without activity for <?= (int) $idleMinutes ?> min you are marked idle; with no heartbeat for 10 min the timer stops.</span></div>
      <?php else: ?>
        <form method="post" action="<?= e(url('/time/start')) ?>"><?= csrf_field() ?>
          <div class="tt-main">
            <div><div class="tt-digits">00:00:00</div><div class="tt-sub">What are you working on?</div></div>
            <button class="tt-btn go" aria-label="Start timer" title="Start timer"><?= icon('play') ?></button>
          </div>
          <div class="tt-fields">
            <select name="project_id" data-project-select data-task-target="tt-task" aria-label="Project"><option value="">No project</option><?php foreach ($projects as $id => $n): ?><option value="<?= (int) $id ?>"><?= e($n) ?></option><?php endforeach ?></select>
            <select name="task_id" id="tt-task" aria-label="Task" disabled><option value="">Select a project first</option></select>
            <input type="text" name="description" class="full" maxlength="500" placeholder="Describe the work (optional)">
          </div>
          <div class="tt-foot"><label class="flex"><span class="switch"><input type="checkbox" name="billable" value="1" checked><span></span></span> Billable</label><span>Today: <b><?= e(hours($stats['total'])) ?></b> tracked</span></div>
        </form>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Week entries</h3>
        <div class="wk-nav"><a class="btn icon sm ghost" href="<?= e(url('/time?week='.$prev)) ?>" aria-label="Previous week"><?= icon('chevron-left', 'sm') ?></a><span class="range small"><?= e(fdate($ws, 'd M')) ?> - <?= e(fdate(date('Y-m-d', strtotime($ws.' +6 days')), 'd M Y')) ?></span><?php if (!$isThisWeek): ?><a class="btn icon sm ghost" href="<?= e(url('/time?week='.$next)) ?>" aria-label="Next week"><?= icon('chevron-right', 'sm') ?></a><?php endif ?>
          <span class="pill pill-lav"><?= e(hours($weekTotal + $elapsed)) ?></span><?php if ($ts): ?><a href="<?= e(url('/timesheets?week='.$ws)) ?>"><?= $ts['status'] === 'rejected' ? badge('rejected', 'Week rejected') : badge('approved', 'Week approved') ?></a><?php endif ?></div></div>
      <?php if ($ts && $ts['status'] === 'rejected'): ?><div class="alert error"><?= icon('alert', 'sm') ?><div class="grow">This week was rejected<?= $ts['review_note'] ? ': <b>'.e($ts['review_note']).'</b>' : '.' ?> Fix the entries, then <a href="<?= e(url('/timesheets?week='.$ws)) ?>"><b>resubmit the week</b></a>.</div></div><?php endif ?>
      <?= partial('attendance::partials/entries', ['days' => $days, 'ws' => $ws, 'editable' => true]) ?>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Today</h3><span class="muted small"><?= e(date('d M')) ?></span></div>
      <div class="mini-tiles">
        <div class="mini m-mint"><span class="lbl"><?= icon('activity', 'sm') ?> Active</span><span class="val"><?= e(hours($stats['active'])) ?></span></div>
        <div class="mini m-lemon"><span class="lbl"><?= icon('pause', 'sm') ?> Idle</span><span class="val"><?= e(hours($stats['idle'])) ?></span></div>
        <div class="mini m-peach"><span class="lbl"><?= icon('coffee', 'sm') ?> Break</span><span class="val"><?= e(hours($stats['break'])) ?></span></div>
        <div class="mini m-lav"><span class="lbl"><?= icon('clock', 'sm') ?> Total</span><span class="val"><?= e(hours($stats['total'])) ?></span></div>
      </div>
      <div class="flex between mt">
        <span class="small"><?= ['out' => 'You have not clocked in today.', 'in' => 'You are clocked in.', 'break' => 'You are on a break.', 'done' => 'You have clocked out.'][$attState] ?></span>
        <?php if ($attState === 'out' && can('attendance.track')): ?><form method="post" action="<?= e(url('/attendance/clock-in')) ?>"><?= csrf_field() ?><button class="btn sm primary"><?= icon('log-in', 'sm') ?> Clock in</button></form><?php else: ?><a class="btn sm ghost" href="<?= e(url('/attendance')) ?>">Attendance</a><?php endif ?>
      </div>
    </div>
    <?php if ($monitoring): ?>
    <div class="card" id="monitoring">
      <div class="card-head"><h3>Screen monitoring</h3><?= $consent ? '<span class="pill pill-mint">Consent given</span>' : '<span class="pill pill-lemon">Consent needed</span>' ?></div>
      <?php if (!$consent): ?>
        <?= illus('security-shield', 'idle-art') ?>
        <div class="notice">
          <b>Monitoring notice (policy <?= e(\Modules\Attendance\Services\Monitoring::POLICY_VERSION) ?>)</b>
          <ul>
            <li>Screenshots are taken <b>only while your timer is running</b> and only after you click "Enable screen capture" and choose what to share.</li>
            <li>One image every <b><?= (int) $interval ?> minute<?= $interval > 1 ? 's' : '' ?></b>, scaled down, with your activity level.</li>
            <li>You can always see your own screenshots. Your manager or HR may view them if they hold monitoring access.</li>
            <li>Images are stored privately and deleted automatically after <b><?= (int) $retention ?> days</b>.</li>
            <li>You can revoke consent at any time. Capture stops immediately.</li>
          </ul>
        </div>
        <form method="post" action="<?= e(url('/time/consent')) ?>" class="form mt"><?= csrf_field() ?>
          <label class="check small"><input type="checkbox" name="agree" value="1" required> I have read and accept this monitoring notice.</label>
          <button class="btn primary"><?= icon('shield', 'sm') ?> Accept & continue</button>
        </form>
      <?php else: ?>
        <p class="small muted">Accepted <?= e(fdatetime($consent['consented_at'])) ?> · every <?= (int) $interval ?> min · kept <?= (int) $retention ?> days · <?= (int) $shotsToday ?> today.</p>
        <?php if ($running): ?>
          <div class="cap-box" data-capture data-interval="<?= (int) $interval ?>">
            <div><span class="rec hide" data-cap-on>Recording</span><span class="small muted" data-cap-off>Screen capture is off</span><div class="xs muted" data-cap-last></div></div>
            <div class="flex"><button type="button" class="btn sm primary" data-cap-start><?= icon('camera', 'sm') ?> Enable screen capture</button><button type="button" class="btn sm danger hide" data-cap-stop><?= icon('stop', 'sm') ?> Stop</button></div>
          </div>
        <?php else: ?><div class="alert info mb0"><?= icon('info', 'sm') ?> Start a timer to enable screen capture.</div><?php endif ?>
        <p class="xs muted mt">Browser capture works only while this tab is open and permission is granted. Full-desktop capture requires the separately authorised desktop agent.</p>
        <div class="flex between"><a class="btn sm ghost" href="<?= e(url('/time/screenshots')) ?>"><?= icon('image', 'sm') ?> My screenshots</a>
          <form method="post" action="<?= e(url('/time/consent/revoke')) ?>" data-confirm="Revoke monitoring consent? Screen capture will stop."><?= csrf_field() ?><button class="btn sm danger"><?= icon('x', 'sm') ?> Revoke consent</button></form></div>
      <?php endif ?>
    </div>
    <?php endif ?>
    <div class="card" id="agent">
      <div class="card-head"><h3>Desktop agent</h3><span class="pill pill-sky"><?= icon('monitor', 'sm') ?> API</span></div>
      <p class="small muted">Personal tokens let an authorised desktop agent start/stop your timer, send activity heartbeats<?= $monitoring ? ' and screenshots (with your consent)' : '' ?>. Treat a token like a password.</p>
      <?php if ($newToken): ?>
        <div class="alert ok"><?= icon('check-circle', 'sm') ?> Token for "<?= e($newToken['name']) ?>". Copy it now, it won't be shown again.</div>
        <div class="token-box mb"><code><?= e($newToken['token']) ?></code><button type="button" class="btn icon sm ghost" data-copy="<?= e($newToken['token']) ?>" aria-label="Copy token"><?= icon('copy', 'sm') ?></button></div>
      <?php endif ?>
      <?php if ($tokens): ?><div class="list mb"><?php foreach ($tokens as $tk): ?>
        <div class="list-item"><span class="tok-ic"><?= icon('key', 'sm') ?></span><div class="grow"><b class="small"><?= e($tk['name']) ?></b><div class="meta">Created <?= e(fdate($tk['created_at'])) ?> · <?= $tk['last_used_at'] ? 'used '.e(ago($tk['last_used_at'])) : 'never used' ?></div></div>
          <form method="post" action="<?= e(url('/time/tokens/'.$tk['id'].'/revoke')) ?>" data-confirm="Revoke this token? The agent using it will stop working."><?= csrf_field() ?><button class="btn sm danger">Revoke</button></form></div>
      <?php endforeach ?></div><?php endif ?>
      <form method="post" action="<?= e(url('/time/tokens')) ?>" class="flex"><?= csrf_field() ?><input type="text" name="name" maxlength="100" placeholder="Device name, e.g. Work laptop" required aria-label="Device name"><button class="btn sm primary"><?= icon('plus', 'sm') ?> Create</button></form>
      <?= error_for('name') ?>
    </div>
  </div>
</div>
<dialog class="modal" id="idle-dialog">
  <div class="modal-body center">
    <?= illus('coffee-break', 'idle-art') ?>
    <h3>Welcome back!</h3>
    <p class="muted">You were idle for <b data-idle-mins>0 min</b> while your timer was running. Keep that time or remove it from this entry?</p>
    <div class="flex" style="justify-content:center"><button type="button" class="btn ghost" data-idle-keep>Keep time</button><button type="button" class="btn primary" data-idle-discard><?= icon('trash', 'sm') ?> Discard idle time</button></div>
  </div>
</dialog>
<?= partial('attendance::partials/entry_dialog', ['projects' => $projects]) ?>
