<?php /* Settings › Updates: the server's mirror of the newest Niyanta Tracker release. $app (DesktopApp::latest), $sync (DesktopApp::syncStatus), $repo */
$size = static fn (int $b): string => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
$files = array_merge(...array_values($app['platforms']));
$total = array_sum(array_map(static fn ($f) => (int) $f['size'], $files));
?>
<div class="card" id="desktop-mirror">
  <div class="card-head"><div><h3><?= icon('monitor', 'sm') ?> Desktop app (Niyanta Tracker)</h3><p class="small muted mb0">Releases tagged <span class="mono">tracker-v*</span> in <span class="mono"><?= e($repo) ?></span> are copied to this server, so people download the app from Time Tracking and the app updates itself from here.</p></div>
    <?php if ($app['ok']): ?><span class="pill pill-mint"><?= icon('check-circle', 'sm') ?> v<?= e($app['version']) ?> ready</span><?php elseif (!$app['connected']): ?><span class="pill pill-lemon">GitHub not connected</span><?php else: ?><span class="pill pill-lemon">Not downloaded yet</span><?php endif ?></div>
  <?php if (!$app['connected']): ?><div class="alert warn"><?= icon('info', 'sm') ?><div>Add a <b>GitHub access token</b> above (fine-grained, read-only access to <i>Contents</i> of <span class="mono"><?= e($repo) ?></span>). The same token is used for app updates. See docs/UPDATES.md.</div></div><?php endif ?>
  <div class="list mb">
    <div class="list-item"><span class="grow small">Downloaded version</span><b class="small"><?= $app['ok'] ? 'v'.e($app['version']).' · '.count($files).' installer'.(count($files) === 1 ? '' : 's').' · '.e($size($total)) : 'none' ?></b></div>
    <div class="list-item"><span class="grow small">Copied to this server</span><span class="small muted"><?= $app['synced_at'] ? e(fdatetime($app['synced_at'])) : 'never' ?></span></div>
    <div class="list-item"><span class="grow small">Last check</span><span class="small muted"><?= !empty($sync['at']) ? e(fdatetime($sync['at'])).(empty($sync['ok']) ? '' : ' · OK') : 'never' ?></span></div>
  </div>
  <?php if (!empty($sync['error'])): ?><div class="alert <?= empty($sync['ok']) ? 'error' : 'info' ?>"><?= icon(empty($sync['ok']) ? 'alert' : 'info', 'sm') ?><div><?= e($sync['error']) ?></div></div><?php endif ?>
  <div class="flex between wrap"><span class="xs muted">Checked every 30 minutes by the scheduler; a new version is downloaded once and the newest two are kept in storage/desktop.</span>
    <form method="post" action="<?= e(url('/admin/updates/desktop-refresh')) ?>"><?= csrf_field() ?><button class="btn sm ghost"<?= $app['connected'] ? '' : ' disabled' ?>><?= icon('refresh', 'sm') ?> Refresh now</button></form></div>
</div>
