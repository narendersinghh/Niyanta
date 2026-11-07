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
      <?php if (!empty($install)): $olInfo = [
        'windows' => ['Windows', 'Open <b>PowerShell</b> (Start menu, type <i>PowerShell</i>), paste and press Enter. It saves the setup to your Downloads folder and opens it: click through it (Next, Install, Finish) and the app starts.'],
        'mac' => ['macOS', 'Open <b>Terminal</b> (Spotlight, type <i>Terminal</i>), paste and press Enter. It installs to Applications and opens the app.'],
        'linux' => ['Linux', 'Paste into a terminal and press Enter. It installs the AppImage to <span class="mono">~/.local/bin</span>, adds it to your apps menu and opens it.']]; ?>
      <div class="oneline" data-oneline>
        <div class="ol-head"><span class="ol-ic"><?= icon('zap', 'sm') ?></span><div class="grow"><h4>Install with one command (no security warnings) <span class="pill pill-mint">Recommended</span></h4><p class="small mb0">Copy the command for your system, paste it and press Enter. That is all.</p><p class="xs muted mb0">The command downloads the app straight from this workspace and checks it, so Chrome, Windows SmartScreen and macOS Gatekeeper do not get in the way. It is personal and works until <b><?= e(ftime(date('Y-m-d H:i:s', (int) $install['until']))) ?></b>; reload the page for a new one.</p></div></div>
        <div class="ol-list" data-ol-list>
          <?php foreach ($olInfo as $ok => [$ol, $oh]): $cmd = $install['commands'][$ok]; ?>
          <div class="ol-item" data-ol="<?= e($ok) ?>">
            <div class="ol-top"><span class="os-ic os-<?= e($ok) ?>"><?= $osIc[$ok] ?></span><b><?= e($ol) ?></b><span class="pill pill-mint os-you hide">Your system</span></div>
            <div class="ol-cmd"><code><?= e($cmd) ?></code><button type="button" class="btn sm primary" data-copy="<?= e($cmd) ?>" aria-label="Copy the <?= e($ol) ?> install command"><?= icon('copy', 'sm') ?> Copy</button></div>
            <p class="xs muted mb0"><?= $oh ?></p></div>
          <?php endforeach ?>
        </div>
        <button type="button" class="btn ghost sm ol-more hide" data-ol-more><?= icon('chevron-down', 'sm') ?> Commands for other systems</button>
      </div>
      <?php endif ?>
      <?php $dl = !empty($install); /* with a one-line command, the download files are the fallback, folded away (closed by default) */ ?>
      <?php if ($dl): ?><details class="dl-more" data-dl-more><summary><span class="dl-more-ic"><?= icon('download', 'sm') ?></span><span class="grow"><b>Other ways to install: download files</b><span class="xs muted">Installers for every system, with step-by-step help</span></span><span class="dl-more-chev"><?= icon('chevron-down', 'sm') ?></span></summary><div class="dl-more-body"><?php endif ?>
      <div class="os-grid" data-os-grid>
        <?php foreach (DesktopApp::PLATFORMS as $pk => [$pl, $hint]): $files = $app['platforms'][$pk]; $f0 = $files[0] ?? null; ?>
        <div class="os-card" data-os="<?= e($pk) ?>">
          <div class="os-top"><span class="os-ic os-<?= e($pk) ?>"><?= $osIc[$pk] ?></span><div class="grow"><b><?= e($pl) ?></b><div class="xs muted"><?= e($hint) ?></div></div><span class="pill pill-mint os-you hide">Your system</span></div>
          <?php if ($f0): ?>
            <div class="os-meta xs"><span>v<?= e($f0['version']) ?></span><?= $size((int) $f0['size']) ? '<span>'.e($size((int) $f0['size'])).'</span>' : '' ?><span><?= e(fdate($f0['date'])) ?></span></div>
            <div class="os-btns"><?php foreach ($files as $f): ?><a class="btn sm <?= $f === $f0 ? 'primary' : 'ghost' ?>" href="<?= e($f['url']) ?>" title="<?= e($f['name']) ?>" download><?= icon('download', 'sm') ?> <?= e($pk === 'mac' ? ($archLbl[$f['arch']] ?? $f['arch']).($f['ext'] === 'zip' ? ' (.zip)' : '') : ($pk === 'windows' ? 'Download .exe' : ($pk === 'deb' ? 'Download .deb' : 'Download AppImage'))) ?></a><?php endforeach ?></div>
          <?php else: ?><div class="os-meta xs muted"><?= $app['ok'] ? 'Not in this release' : 'Not available yet' ?></div><div class="os-btns"><span class="btn sm ghost is-disabled" aria-disabled="true"><?= icon('download', 'sm') ?> Coming soon</span></div><?php endif ?>
        </div>
        <?php endforeach ?>
      </div>
        <div class="dl-howto"><h4><?= icon('package', 'sm') ?> Install from the download button</h4><ol class="steps-list small">
          <li data-os-step="windows"><b>Windows:</b> run the .exe. If Chrome says the file isn't commonly downloaded, open Downloads (<span class="mono">Ctrl+J</span>) and choose <b>Keep</b>. If Windows shows <i>Windows protected your PC</i>, click <b>More info</b>, then <b>Run anyway</b>.<?= !empty($install) ? ' The one-line command above avoids both.' : '' ?></li>
          <li data-os-step="mac"><b>macOS:</b> open the .dmg and drag Niyanta Tracker to Applications. If macOS says it cannot verify the developer, open <b>System Settings › Privacy &amp; Security</b> and click <b>Open Anyway</b>. If it says the app is <i>damaged</i>, run <span class="mono">xattr -cr "/Applications/Niyanta Tracker.app"</span> in Terminal once<?= !empty($install) ? ', or simply use the one-line command above' : '' ?>. Allow <i>Screen Recording</i> and <i>Accessibility</i> when asked.</li>
          <li data-os-step="linux"><b>Linux:</b> make the AppImage executable (<span class="mono">chmod +x</span>) and run it, or install the .deb with <span class="mono">sudo apt install ./niyanta-tracker-*.deb</span>.</li>
          <li><b>Updates:</b> the app tells you when a new version is out. Click <b>Update now</b>: it downloads, restarts by itself and your tracking continues.</li></ol></div>
      <?php if ($dl): ?></div></details><?php endif ?>
      <div class="dl-connect" id="connect">
        <h4><?= icon('link', 'sm') ?> <?= $tokens ? 'Connect another computer' : 'Then connect the desktop app' ?></h4><p class="small mb0">Installed? Click the button: Niyanta Tracker opens and signs in as you. Nothing to type.</p>
        <form method="post" action="<?= e(url('/time/desktop/connect-link')) ?>" class="cx-actions"><?= csrf_field() ?><button class="btn primary"><?= icon('monitor', 'sm') ?> Connect the desktop app</button></form>
        <p class="xs muted">The app stays signed in even when you sign out of the website. Remove a computer any time under <a href="#devices">Your devices</a>.</p>
        <details class="dl-more"><summary><span class="grow"><span class="xs muted">Sign in with email and password instead</span></span><span class="dl-more-chev"><?= icon('chevron-down', 'sm') ?></span></summary><div class="dl-more-body"><p class="small mb">In the app, sign in with your Niyanta email and password and this workspace address:</p>
          <div class="token-box"><code><?= e(absolute_url('/')) ?></code><button type="button" class="btn icon sm ghost" data-copy="<?= e(absolute_url('/')) ?>" aria-label="Copy workspace address"><?= icon('copy', 'sm') ?></button></div></div></details></div>
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
    <?php if (!$tokens): ?>
    <section class="card dl-card" id="desktop">
      <div class="card-head"><div><h3>Get the desktop app</h3><p class="small muted mb0">Install Niyanta Tracker, then connect it. It tracks your day for Windows, macOS and Linux.</p></div><?= $ver ?></div>
      <?= $desk ?>
    </section>
    <?php endif ?>
    <section class="card wk-card" aria-labelledby="wk-h">
      <div class="card-head"><h3 id="wk-h">This week</h3><a class="btn sm ghost" href="<?= e(url('/timesheets')) ?>">Open timesheet <?= icon('chevron-right', 'sm') ?></a></div>
      <div class="wk-sum"><b><?= e(hours($wk['total'])) ?></b><span class="muted small"><?= $wk['expected'] ? 'of '.e(hours($wk['expected'])).' expected · '.$wpct.'%' : 'tracked' ?></span></div>
      <?php if ($wk['expected']): ?><div class="ta-meter" aria-hidden="true"><i style="width:<?= $wpct ?>%"></i></div><?php endif ?>
      <ol class="wk-days"><?php foreach ($wk['days'] as $d => $v): $off = $wk['off'][$d]; $isT = $d === date('Y-m-d'); ?>
        <li class="<?= $isT ? 'today' : '' ?><?= $off ? ' off' : '' ?><?= $d > date('Y-m-d') ? ' fut' : '' ?>"><a href="<?= e(url('/time/daily?date='.$d)) ?>"<?= $d > date('Y-m-d') ? ' tabindex="-1" aria-disabled="true"' : '' ?> aria-label="<?= e(date('l', strtotime($d)).': '.($v ? hours((int) $v) : 'nothing tracked').($off ? ', day off' : '')) ?>">
          <span class="wd-v"><?= $v ? e(hours((int) $v)) : '-' ?></span><span class="wd-bar" aria-hidden="true"><i style="height:<?= $v ? max(6, (int) round(100 * $v / $wmax)) : 0 ?>%"></i></span><span class="wd-d"><?= e(date('D', strtotime($d))) ?></span></a></li>
      <?php endforeach ?></ol>
    </section>
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
<?php if ($tokens): ?>
<details class="dl-more ta-desk" id="desktop" data-hash-open>
  <summary><span class="dl-more-ic"><?= icon('download', 'sm') ?></span><span class="grow"><b>Get the desktop app on another computer</b><span class="xs muted">Install commands, download files and connect another computer</span></span><?= $ver ?><span class="dl-more-chev"><?= icon('chevron-down', 'sm') ?></span></summary>
  <div class="dl-more-body dl-card"><?= $desk ?></div>
</details>
<?php endif ?>
