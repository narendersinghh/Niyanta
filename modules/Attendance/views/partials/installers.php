<?php /* Settings › Attendance & time tracking: uploaded tracker installers + GitHub source status. $installers, $app */
use Modules\Attendance\Services\DesktopApp;
$size = static fn (int $b): string => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
$gh = DesktopApp::repo();
$ghFiles = array_filter(array_merge(...array_values($app['platforms'])), static fn ($f) => $f['source'] === 'github');
?>
<section class="card set-card" id="desktop">
  <header class="set-card-head flex between wrap"><div><h3>Desktop app installers</h3><p>Uploaded installers are stored privately (storage/desktop/&lt;version&gt;/) and downloadable only by signed-in people who track time.</p></div>
    <?php if ($app['version']): ?><span class="pill pill-mint"><?= icon('check-circle', 'sm') ?> Latest <?= e($app['version']) ?></span><?php else: ?><span class="pill pill-lemon">No installers yet</span><?php endif ?></header>
  <?php if ($installers): ?>
  <div class="table-wrap mb"><table class="table">
    <thead><tr><th>Platform</th><th>Version</th><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
    <tbody><?php foreach (array_reverse($installers) as $f): ?><tr>
      <td><b><?= e(DesktopApp::PLATFORMS[$f['platform']][0] ?? $f['platform']) ?></b><div class="xs muted"><?= e(DesktopApp::ARCHES[$f['arch']] ?? $f['arch']) ?></div></td>
      <td><span class="pill pill-lav"><?= e($f['version']) ?></span></td><td class="small mono"><?= e($f['name']) ?></td><td class="small"><?= e($size((int) $f['size'])) ?></td><td class="small muted"><?= e(fdatetime($f['uploaded_at'])) ?></td>
      <td><form method="post" action="<?= e(url('/attendance/desktop/delete')) ?>" data-confirm="Remove <?= e($f['name']) ?>? People will no longer be able to download it."><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><button class="btn icon sm ghost" aria-label="Remove installer"><?= icon('trash', 'sm') ?></button></form></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?php endif ?>
  <form class="form-grid" method="post" action="<?= e(url('/attendance/desktop/upload')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="field"><label for="inst-version">Version</label><input id="inst-version" type="text" name="version" required pattern="\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?" placeholder="1.0.0" value="<?= e($app['version'] ?? '1.0.0') ?>"><div class="hint">Same as the app's package version, e.g. 1.2.0.</div></div>
    <div class="field"><label for="inst-file">Installer file</label><input id="inst-file" type="file" name="installer" required accept=".exe,.dmg,.zip,.AppImage,.deb"><div class="hint">.exe (Windows), .dmg or -mac .zip (macOS), .AppImage or .deb (Linux). Platform and architecture come from the file name. Server limit: <?= e((string) ini_get('upload_max_filesize')) ?>.</div></div>
    <div class="field full"><button class="btn primary"><?= icon('upload', 'sm') ?> Upload installer</button></div>
  </form>
  <div class="alert info mt mb0"><?= icon('info', 'sm') ?><div><b>Automatic releases:</b> <?php if ($gh): ?>GitHub <span class="mono"><?= e($gh) ?></span> · <?= $ghFiles ? count($ghFiles).' installer'.(count($ghFiles) > 1 ? 's' : '').' found'.($app['release_url'] ? ' (<a href="'.e($app['release_url']).'" target="_blank" rel="noopener noreferrer">release page</a>)' : '') : 'no tracker-v* release with installers found yet' ?>. Tag a commit <span class="mono">tracker-v1.0.0</span> and the "Desktop tracker" workflow builds and attaches the installers.<?php else: ?>set a GitHub repository above (or under Updates) to offer installers built by the "Desktop tracker" workflow automatically.<?php endif ?></div></div>
</section>
