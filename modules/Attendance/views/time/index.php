<?php
use Modules\Attendance\Services\{AttendanceService, DesktopApp, Monitoring, TimeService, Timesheets};
/* $st (Tracker::state), $app (DesktopApp::latest), $install (one-line commands or null), $tokens, $monitoring, $consent, $policy, $retention, $shotsToday */
$hms = static fn (int $s): string => sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
$size = static fn (int $b): string => $b >= 1048576 ? round($b / 1048576).' MB' : ($b > 0 ? max(1, (int) round($b / 1024)).' KB' : '');
$run = $st['running']; $tot = $st['totals'];
$runElapsed = $run ? (int) $run['elapsed'] : 0;
$chip = ['tracking' => ['dot-in pulse', 'live'], 'paused' => ['dot-break', 'idle'], 'stopped' => ['dot-break', 'idle'], 'ended' => ['dot-done', ''], 'not_started' => ['dot-out', '']][$st['state']];
$osIc = ['windows' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 5.5 10.5 4.5v7H3zm8.5-1.1L21 3v8.5h-9.5zM3 12.5h7.5v7L3 18.5zm8.5 0H21V21l-9.5-1.3z"/></svg>',
  'mac' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16.4 12.7c0-2.4 2-3.5 2-3.6-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9s-1.8-.8-3-.8C7 7.4 5.6 8.3 4.8 9.7c-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.1 2.8-2.3.9-1.3 1.3-2.6 1.3-2.6s-2.6-1-2.6-3.7zM14.2 5.6c.6-.8 1.1-1.8 1-2.9-1 0-2.1.7-2.8 1.5-.6.7-1.1 1.8-1 2.8 1.1.1 2.2-.6 2.8-1.4z"/></svg>',
  'linux' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2c-2.2 0-3.6 1.8-3.6 4.4 0 1.5-.3 2.4-1.2 3.6C5.9 11.7 5 13.6 5 15.6c0 .5 0 1 .1 1.4-.6.4-1.6.9-1.6 1.8 0 1.2 1.7 1.3 2.8 1.8 1.1.6 1.9 1.4 2.8 1.4.8 0 1.3-.5 1.6-1h2.6c.3.5.8 1 1.6 1 .9 0 1.7-.8 2.8-1.4 1.1-.5 2.8-.6 2.8-1.8 0-.9-1-1.4-1.6-1.8.1-.4.1-.9.1-1.4 0-2-.9-3.9-2.2-5.6-.9-1.2-1.2-2.1-1.2-3.6C15.6 3.8 14.2 2 12 2zm-1.5 4.2c.5 0 .8.6.8 1.2s-.3 1-.8 1-.8-.5-.8-1 .3-1.2.8-1.2zm3 0c.5 0 .8.6.8 1.2s-.3 1-.8 1-.8-.5-.8-1 .3-1.2.8-1.2zM12 9.2c.9 0 2 .5 2 .9s-1.1 1.1-2 1.1-2-.7-2-1.1 1.1-.9 2-.9z"/></svg>'];
$osIc['deb'] = $osIc['linux'];
$archLbl = ['arm64' => 'Apple silicon', 'x64' => 'Intel', 'universal' => 'Universal'];
$me = (int) user()['id'];
$target = AttendanceService::workSeconds($me);
$workday = AttendanceService::dayWeight($me, date('Y-m-d')) > 0;
$pct = $workday ? min(100, (int) round(100 * $tot['tracked'] / $target)) : 0;
$ws = TimeService::weekStart(); $wk = Timesheets::summary($me, $ws);
$wpct = $wk['expected'] ? min(100, (int) round(100 * $wk['total'] / $wk['expected'])) : 0;
$wmax = max(1, $wk['per_day'], ...array_values($wk['days']));
$ver = $app['version'] ? '<span class="pill pill-lav">Latest '.e($app['version']).($app['date'] ? ' · '.e(fdate($app['date'])) : '').'</span>' : '';
ob_start(); ?>
      <?php if (!$app['ok']): $sa = user()['role_slug'] === 'super_admin'; ?><div class="alert info mb"><?= icon('info', 'sm') ?><div><?php if (!$app['connected']): ?>The desktop app isn't available yet. An administrator needs to connect GitHub in <?= $sa ? '<a href="'.e(url('/admin/updates#desktop-mirror')).'"><b>Settings › Updates</b></a>' : '<b>Settings › Updates</b>' ?>.<?php else: ?>The desktop app isn't available yet. It is being fetched in the background, please check back soon.<?= $sa ? ' Status and <b>Refresh now</b>: <a href="'.e(url('/admin/updates#desktop-mirror')).'"><b>Settings › Updates</b></a>.' : '' ?><?php endif ?></div></div><?php endif ?>
      <?php /* one panel per system (Windows, macOS, Linux), chosen with radio tabs (no JS needed; JS preselects the visitor's system) */
      $dkOs = ['windows' => ['Windows', 'Open <b>PowerShell</b> (Start menu, type <i>PowerShell</i>), paste and press Enter. Click through the setup (Next, Install, Finish).', ['windows']],
        'mac' => ['macOS', 'Open <b>Terminal</b> (Spotlight, type <i>Terminal</i>), paste and press Enter. It installs to Applications and opens the app.', ['mac']],
        'linux' => ['Linux', 'Paste into a terminal and press Enter. It installs to <span class="mono">~/.local/bin</span> and adds it to your apps menu.', ['linux', 'deb']]];
      $dkHelp = ['windows' => 'Run the .exe. If Windows says <i>Windows protected your PC</i>, click <b>More info</b>, then <b>Run anyway</b>.', 'mac' => 'Open the .dmg and drag the app to Applications. If macOS cannot verify it, open <b>System Settings › Privacy &amp; Security</b> and click <b>Open Anyway</b>.', 'linux' => 'Make the AppImage executable (<span class="mono">chmod +x</span>) and run it, or install the .deb with <span class="mono">sudo apt install ./niyanta-tracker-*.deb</span>.']; ?>
      <div class="dk" data-dk data-os="windows">
        <div class="dk-os" role="tablist" aria-label="Your computer"><?php foreach ($dkOs as $ok => [$ol]): ?><input type="radio" class="sr" name="dk-os" id="dk-<?= e($ok) ?>" value="<?= e($ok) ?>"<?= $ok === 'windows' ? ' checked' : '' ?>><label for="dk-<?= e($ok) ?>"><span class="os-ic os-<?= e($ok) ?>"><?= $osIc[$ok] ?></span><?= e($ol) ?></label><?php endforeach ?></div>
        <?php foreach ($dkOs as $ok => [$ol, $oh, $pks]): ?>
        <div class="dk-panel" data-dk-panel="<?= e($ok) ?>">
          <div class="dk-step"><span class="dk-n">1</span><div class="grow">
            <?php if (!empty($install)): $cmd = $install['commands'][$ok]; ?>
            <b>Install with one command</b> <span class="pill pill-mint">No security warnings</span>
            <div class="ol-cmd" data-ol="<?= e($ok) ?>"><code><?= e($cmd) ?></code><button type="button" class="btn sm primary" data-copy="<?= e($cmd) ?>" aria-label="Copy the <?= e($ol) ?> install command"><?= icon('copy', 'sm') ?> Copy</button></div>
            <p class="xs muted mb0"><?= $oh ?> Personal link, valid until <?= e(ftime(date('Y-m-d H:i:s', (int) $install['until']))) ?>.</p>
            <?php else: ?><b>Download and install</b><?php endif ?>
            <?php $files = array_merge(...array_map(static fn ($pk) => $app['platforms'][$pk] ?? [], $pks)); if ($files): ?>
            <details class="dk-files"<?= empty($install) ? ' open' : '' ?>><summary class="xs"><?= icon('download', 'sm') ?> <?= empty($install) ? 'Installer' : 'Or download the installer' ?><?= $files[0]['version'] ? ' · v'.e($files[0]['version']) : '' ?></summary>
              <div class="os-btns"><?php foreach ($files as $f): ?><a class="btn sm ghost" href="<?= e($f['url']) ?>" title="<?= e($f['name']) ?>" download><?= icon('download', 'sm') ?> <?= e($ok === 'mac' ? ($archLbl[$f['arch']] ?? $f['arch']).($f['ext'] === 'zip' ? ' (.zip)' : '') : ($ok === 'windows' ? '.exe' : ($f['ext'] === 'deb' ? '.deb' : 'AppImage'))) ?><?= $size((int) $f['size']) ? ' <span class="muted">'.e($size((int) $f['size'])).'</span>' : '' ?></a><?php endforeach ?></div>
              <p class="xs muted mb0"><?= $dkHelp[$ok] ?></p></details>
            <?php elseif (empty($install)): ?><p class="xs muted mb0"><?= $app['ok'] ? 'Not in this release.' : 'Not available yet.' ?></p><?php endif ?>
          </div></div>
        </div>
        <?php endforeach ?>
        <div class="dk-step" id="connect"><span class="dk-n">2</span><div class="grow"><b>Connect it</b><p class="xs muted mb0">Installed? Click the button: the app opens and signs in as you. It stays signed in even when you sign out of the website.</p>
          <form method="post" action="<?= e(url('/time/desktop/connect-link')) ?>" class="cx-actions"><?= csrf_field() ?><button class="btn primary sm"><?= icon('monitor', 'sm') ?> Connect the desktop app</button></form>
          <details class="dk-files"><summary class="xs">Sign in with email and password instead</summary><p class="xs muted">In the app, use your Niyanta email and password and this workspace address:</p>
            <div class="token-box"><code><?= e(absolute_url('/')) ?></code><button type="button" class="btn icon sm ghost" data-copy="<?= e(absolute_url('/')) ?>" aria-label="Copy workspace address"><?= icon('copy', 'sm') ?></button></div></details></div></div>
      </div>
<?php $desk = ob_get_clean(); ?>
<?= partial('attendance::partials/hub', ['tab' => 'tracker', 'title' => 'Time & Attendance', 'subtitle' => 'Your live status and today\'s time. Tracking runs in the Niyanta Tracker desktop app.']) ?>
<div class="ta-split">
  <div class="ta-col">
    <section class="tt-card live-card<?= $st['state'] === 'tracking' ? ' running' : '' ?>" data-live-status data-state="<?= e($st['state']) ?>" aria-live="polite" aria-label="Live status">
      <div class="tt-top">
        <span class="tt-chip <?= e($chip[1]) ?>" data-ls-chip><span class="dotc <?= e($chip[0]) ?>"></span><span data-ls-label><?= e($st['label']) ?></span></span>
        <span class="tt-chip"><?= icon('calendar', 'sm') ?> <?= e(date('D, d M')) ?></span>
      </div>
      <div class="tt-main">
        <div class="grow"><div class="tt-cap">Tracked today</div><div class="tt-digits" data-tick data-ls-total data-base="<?= (int) $tot['tracked'] ?>"<?= $st['state'] === 'tracking' ? '' : ' data-paused' ?>><?= e($hms((int) $tot['tracked'])) ?></div>
          <div class="tt-sub" data-ls-line><?php if ($run): ?>Tracking<?= $run['device'] ? ' on <b>'.e($run['device']).'</b>' : '' ?> · <b><?= e(feature_on('projects') ? ($run['project'] ?? 'No project') : 'Tracked time') ?></b><?= $run['task'] ? ' · '.e(str_limit($run['task'], 40)) : '' ?>
            <?php elseif ($st['state'] === 'paused'): ?>Paused since <b><?= e(ftime($st['day']['paused_since'])) ?></b>. Resume in the desktop app.
            <?php elseif ($st['state'] === 'stopped'): ?>Your day is open but nothing is being tracked. Is the desktop app running?
            <?php elseif ($st['state'] === 'ended'): ?>Day ended at <b><?= e(ftime($st['day']['clock_out'])) ?></b>. Nice work.
            <?php else: ?>Not started yet. Open Niyanta Tracker and click <b>Start</b>.<?php endif ?></div></div>
        <div class="live-entry<?= $run ? '' : ' hide' ?>" data-ls-entry><span class="xs">Current entry</span><b data-tick data-ls-entry-t data-base="<?= $runElapsed ?>"<?= $run ? '' : ' data-paused' ?>><?= e($hms($runElapsed)) ?></b></div>
      </div>
      <div class="tt-goal">
        <?php if ($workday): ?><div class="tt-goal-bar" role="progressbar" aria-label="Tracked time against today's target" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>"><i data-ls-prog data-target="<?= $target ?>" style="width:<?= $pct ?>%"></i></div>
        <div class="tt-goal-t"><span><b data-ls-pct><?= $pct ?>%</b> of today's <?= e(hours($target)) ?> target</span><span class="xs" data-ls-updated></span></div>
        <?php else: ?><div class="tt-goal-t"><span>Day off: no target today</span><span class="xs" data-ls-updated></span></div><?php endif ?>
      </div>
    </section>
    <section class="card wk-card" aria-labelledby="wk-h">
      <div class="card-head"><h3 id="wk-h">This week</h3><a class="btn sm ghost" href="<?= e(url('/timesheets')) ?>">Open timesheet <?= icon('chevron-right', 'sm') ?></a></div>
      <div class="wk-sum"><b><?= e(hours($wk['total'])) ?></b><span class="muted small"><?= $wk['expected'] ? 'of '.e(hours($wk['expected'])).' expected · '.$wpct.'%' : 'tracked' ?></span></div>
      <?php if ($wk['expected']): ?><div class="ta-meter" aria-hidden="true"><i style="width:<?= $wpct ?>%"></i></div><?php endif ?>
      <ol class="wk-days"><?php foreach ($wk['days'] as $d => $v): $off = $wk['off'][$d]; $isT = $d === date('Y-m-d'); ?>
        <li class="<?= $isT ? 'today' : '' ?><?= $off ? ' off' : '' ?><?= $d > date('Y-m-d') ? ' fut' : '' ?>"><a href="<?= e(url('/time/daily?date='.$d)) ?>"<?= $d > date('Y-m-d') ? ' tabindex="-1" aria-disabled="true"' : '' ?> aria-label="<?= e(date('l', strtotime($d)).': '.($v ? hours((int) $v) : 'nothing tracked').($off ? ', day off' : '')) ?>">
          <span class="wd-v"><?= $v ? e(hours((int) $v)) : '-' ?></span><span class="wd-bar" aria-hidden="true"><i style="height:<?= $v ? max(6, (int) round(100 * $v / $wmax)) : 0 ?>%"></i></span><span class="wd-d"><?= e(date('D', strtotime($d))) ?></span></a></li>
      <?php endforeach ?></ol>
    </section>
    <?php if (!$tokens): ?>
    <section class="card dl-card" id="desktop">
      <div class="card-head"><div><h3>Get the desktop app</h3><p class="small muted mb0">Install Niyanta Tracker, then connect it. It tracks your day for Windows, macOS and Linux.</p></div><?= $ver ?></div>
      <?= $desk ?>
    </section>
    <?php endif ?>
    <?php if ($tokens): ?>
    <section class="card dl-card dk-card" id="desktop">
      <div class="card-head"><div><h3>Add another computer</h3><p class="small muted mb0">Install Niyanta Tracker on another Windows, macOS or Linux computer.</p></div><?= $ver ?></div>
      <?= $desk ?>
    </section>
    <?php endif ?>
  </div>
  <div class="ta-col">
    <section class="card" aria-labelledby="td-h">
      <div class="card-head"><h3 id="td-h">Today</h3><span class="muted small"><?= $st['day']['clock_in'] ? 'Started '.e(ftime($st['day']['clock_in'])) : 'Not started' ?><?= $st['day']['clock_out'] ? ', ended '.e(ftime($st['day']['clock_out'])) : '' ?></span></div>
      <dl class="ta-kv">
        <div><dt><i class="dot-in"></i>Active</dt><dd data-ls-active><?= e(hours($tot['active'])) ?></dd></div>
        <div><dt><i class="dot-break"></i>Idle</dt><dd data-ls-idle><?= e(hours($tot['idle'])) ?></dd></div>
        <div><dt><i class="dot-leave"></i>Paused</dt><dd data-ls-paused><?= e(hours($tot['paused'])) ?></dd></div>
        <div class="tot"><dt>Tracked</dt><dd data-ls-tracked><?= e(hours($tot['tracked'])) ?></dd></div>
      </dl>
      <div class="ta-quick"><a class="btn sm soft" href="<?= e(url('/time/daily')) ?>"><?= icon('calendar', 'sm') ?> Today's timeline</a><?php if (Monitoring::enabled()): ?><a class="btn sm ghost" href="<?= e(url('/time/screenshots')) ?>"><?= icon('image', 'sm') ?> Screenshots</a><?php endif ?><a class="btn sm ghost" href="<?= e(url('/attendance')) ?>"><?= icon('clock', 'sm') ?> Attendance</a></div>
      <p class="xs muted mb0">Only tracked time counts as worked time.</p>
    </section>
    <?php if ($monitoring || $consent): ?>
    <section class="card<?= $consent ? '' : ' ta-attn' ?>" id="monitoring">
      <div class="card-head"><h3>Screen monitoring</h3><?= $consent ? '<span class="pill pill-mint">Consent given</span>' : '<span class="pill pill-lemon">Consent needed</span>' ?></div>
      <?php if (!$consent): ?>
        <div class="notice"><b><?= e($policy['title']) ?> (policy <?= e($policy['version']) ?>)</b><ul><?php foreach ($policy['points'] as $pt): ?><li><?= e($pt) ?></li><?php endforeach ?></ul></div>
        <p class="small muted mt">The desktop app shows this notice before the first capture. You can also accept it here.</p>
        <form method="post" action="<?= e(url('/time/consent')) ?>" class="form"><?= csrf_field() ?>
          <label class="check small"><input type="checkbox" name="agree" value="1" required> I have read and accept this monitoring notice.</label>
          <button class="btn primary sm"><?= icon('shield', 'sm') ?> Accept</button></form>
      <?php else: ?>
        <p class="small muted"><b><?= (int) $shotsToday ?></b> today · <?= e(Monitoring::intervalLabel()) ?> while tracking · kept <?= (int) $retention ?> days. Accepted <?= e(fdate($consent['consented_at'])) ?>.</p>
        <div class="flex between wrap"><a class="btn sm ghost" href="<?= e(url('/time/screenshots')) ?>"><?= icon('image', 'sm') ?> My screenshots</a>
          <form method="post" action="<?= e(url('/time/consent/revoke')) ?>" data-confirm="Revoke monitoring consent? The desktop app stops capturing immediately."><?= csrf_field() ?><button class="btn sm danger"><?= icon('x', 'sm') ?> Revoke consent</button></form></div>
      <?php endif ?>
    </section>
    <?php endif ?>
    <section class="card" id="devices">
      <div class="card-head"><h3>Your devices</h3><span class="muted small"><?= count($tokens) ?> connected</span></div>
      <?php if (!$tokens): ?><p class="small muted mb0">No computer is connected yet. Install the desktop app and click <b>Connect the desktop app</b>.</p><?php else: ?><div class="list"><?php foreach ($tokens as $tk): ?>
        <div class="list-item"><span class="tok-ic"><?= icon($tk['kind'] === 'desktop' ? 'monitor' : 'key', 'sm') ?></span><div class="grow"><b class="small"><?= e($tk['name']) ?></b><div class="meta"><?= e(trim(($tk['device_os'] ?? '').($tk['app_version'] ? ' · v'.$tk['app_version'] : ''), ' ·')) ?><?= $tk['device_os'] || $tk['app_version'] ? ' · ' : '' ?><?= $tk['last_used_at'] ? 'active '.e(ago($tk['last_used_at'])) : 'never used' ?></div></div>
          <form method="post" action="<?= e(url('/time/tokens/'.$tk['id'].'/revoke')) ?>" data-confirm="Disconnect <?= e($tk['name']) ?>? The tracker there signs out."><?= csrf_field() ?><button class="btn sm ghost">Disconnect</button></form></div>
      <?php endforeach ?></div><?php endif ?>
    </section>
  </div>
</div>

