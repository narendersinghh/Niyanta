<?php
use Modules\Admin\Services\BackupService;
$c = $check; $avail = !empty($c['available']) && empty($c['error']);
$channelLabel = ['release' => 'Stable releases', 'commit' => 'Latest commit'][setting('updates.channel', 'release')] ?? 'Stable releases';
?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => 'updates']) ?>
<div class="set-main">
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'updates']) ?>
  <div class="card hero-mini">
    <div><span class="pill pill-<?= $avail ? 'peach' : 'mint' ?>"><?= $avail ? 'Update available' : (!empty($c['checked_at']) && empty($c['error']) ? 'Up to date' : 'Not checked yet') ?></span>
      <h2>Niyanta v<?= e(NY_VERSION) ?><?= setting('updates.current_commit') ? ' <span class="pill pill-gray mono">'.e(substr((string) setting('updates.current_commit'), 0, 7)).'</span>' : '' ?></h2>
      <p class="small muted">PHP <?= e(PHP_VERSION) ?> · Channel: <b><?= e($channelLabel) ?></b> · Source: <b class="mono"><?= $repo ? e(implode('/', $repo)) : 'not configured' ?></b><br>Last check: <?= !empty($c['checked_at']) ? e(fdatetime($c['checked_at'])).' ('.e(ago($c['checked_at'])).')' : 'never' ?></p>
      <div class="flex wrap"><form method="post" action="<?= e(url('/admin/updates/check')) ?>"><?= csrf_field() ?><button class="btn primary"<?= $repo && $curl ? '' : ' disabled' ?>><?= icon('refresh', 'sm') ?> Check for updates</button></form>
        <?php if ($avail): ?><button class="btn mint" data-modal="apply-update"><?= icon('download', 'sm') ?> Install <?= e($c['version']) ?></button><?php endif ?>
        <button class="btn ghost" data-modal="upload-update"><?= icon('upload', 'sm') ?> Upload package</button></div></div>
    <?= illus('upgrade-cloud') ?>
  </div>
  <?php if (!empty($c['error'])): ?><div class="alert error"><?= icon('alert', 'sm') ?> Last check failed: <?= e($c['error']) ?></div><?php endif ?>
  <?php if (!$zip): ?><div class="alert warn"><?= icon('alert', 'sm') ?> The PHP zip extension is required to install updates.</div><?php endif ?>
  <?php if (!$curl): ?><div class="alert warn"><?= icon('alert', 'sm') ?> cURL is not available, online checks are disabled. Use "Upload package" instead.</div><?php endif ?>
  <?php if (!empty($c['version']) && empty($c['error'])): ?>
  <div class="card"><div class="card-head"><div><h3><?= e($c['name'] ?? $c['version']) ?></h3><div class="xs muted"><?= e($c['channel'] === 'release' ? 'Release '.$c['ref'] : 'Commit '.substr((string) $c['ref'], 0, 12).' on '.($c['branch'] ?? 'main')) ?><?= !empty($c['published_at']) ? ' · '.e(fdatetime($c['published_at'])) : '' ?> · <?= e($c['package'] ?? '') ?><?= !empty($c['size']) ? ' ('.e(BackupService::size((int) $c['size'])).')' : '' ?></div></div>
    <?php if (!empty($c['html_url'])): ?><a class="btn ghost sm" href="<?= e($c['html_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external', 'sm') ?> GitHub</a><?php endif ?></div>
    <div class="notes"><?= e(trim((string) ($c['notes'] ?? '')) ?: 'No changelog provided.') ?></div>
    <p class="xs muted mt mb0"><?= icon('shield', 'sm') ?> Integrity: <?= !empty($c['digest']) ? 'published SHA-256 digest' : (!empty($c['checksum_url']) ? 'checksums file in the release' : (!empty($c['size']) ? 'GitHub-reported size + package structure' : 'package structure (niyanta.json, core/version.php)')) ?>.</p></div>
  <?php endif ?>
  <div class="grid g2" style="align-items:start">
    <?= partial('admin::settings/_form', ['tab' => 'updates', 'fields' => $fields, 'title' => 'Update source', 'bar' => false]) ?>
    <div class="card"><div class="card-head"><h3>Database migrations</h3><?= $pending ? badge('pending', count($pending).' pending') : badge('success', 'Up to date') ?></div>
      <?php if ($pending): ?><div class="list"><?php foreach (array_slice($pending, 0, 12) as $m): ?><div class="list-item"><span class="pill pill-lav"><?= e($m['module']) ?></span><span class="mono xs grow truncate"><?= e($m['name']) ?></span></div><?php endforeach ?></div>
        <?php if (count($pending) > 12): ?><p class="xs muted">…and <?= count($pending) - 12 ?> more</p><?php endif ?>
        <form method="post" action="<?= e(url('/admin/updates/migrate')) ?>" data-confirm="Run <?= count($pending) ?> pending migration(s) now? Take a backup first if unsure." class="mt"><?= csrf_field() ?><button class="btn primary"><?= icon('play', 'sm') ?> Run pending migrations</button></form>
      <?php else: ?><p class="small muted mb0">The database schema matches the installed code. Migrations run automatically after each update; you can also run them from the CLI with <code class="mono">php cron.php --migrate</code>.</p><?php endif ?></div>
  </div>
  <div class="card"><div class="card-head"><h3>Update history</h3></div>
    <?php if (!$history): ?><?= empty_state('No updates yet', 'Installed updates and their step-by-step logs appear here.', 'rocket') ?><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Version</th><th>Channel</th><th>Started</th><th>By</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($history as $h): ?><tr><td class="muted">#<?= (int) $h['id'] ?></td><td class="b"><?= e($h['from_version']) ?> <?= icon('arrow-right', 'sm') ?> <?= e($h['to_version'] ?? '?') ?></td><td><span class="pill pill-gray"><?= e($h['channel']) ?></span><div class="xs muted mono truncate" style="max-width:180px"><?= e($h['ref'] ?? '') ?></div></td><td class="small nowrap"><?= e(fdatetime($h['started_at'])) ?></td><td class="small"><?= e($h['by_name'] ?? '-') ?></td><td><?= badge(['rolled_back' => 'overdue'][$h['status']] ?? $h['status'], ucwords(str_replace('_', ' ', $h['status']))) ?></td><td class="actions"><a class="btn sm ghost" href="<?= e(url('/admin/updates/'.$h['id'])) ?>"><?= icon('file-text', 'sm') ?> Log</a></td></tr><?php endforeach ?>
    </tbody></table></div><?php endif ?></div>
  <div class="card"><div class="card-head"><h3><?= icon('alert') ?> Recovery procedures</h3><span class="xs muted">Full guide: docs/UPDATES.md</span></div>
    <div class="grid g2"><ol class="steps-list prose">
      <li><b>Automatic rollback:</b> if anything fails after files start changing, the previous code is restored from <code>storage/updates/code-backup-&lt;id&gt;.zip</code> and maintenance mode is switched off.</li>
      <li><b>Database:</b> every update takes a full backup first (listed in Backups). Restore it from the Backups page if a migration misbehaved.</li>
      <li><b>Stuck in maintenance:</b> delete <code>storage/maintenance.flag</code> via FTP or your file manager.</li></ol>
      <ol class="steps-list prose" start="4">
      <li><b>Manual code rollback:</b> unzip the code backup over the app folder, keeping <code>config/</code>, <code>storage/</code> and <code>assets/uploads/</code>.</li>
      <li><b>Pending migrations:</b> run them here or with <code>php cron.php --migrate</code>.</li>
      <li><b>Preserved on update:</b> <code><?= e(implode(', ', (array) ($manifest['preserve'] ?? []))) ?></code>.</li></ol></div></div>
</div>
</div>
<?php foreach ([['apply-update', '/admin/updates/apply', 'Install update '.($c['version'] ?? ''), false], ['upload-update', '/admin/updates/upload', 'Upload update package', true]] as [$mid, $action, $heading, $file]): ?>
<dialog class="modal" id="<?= e($mid) ?>"><div class="modal-head"><h3><?= e($heading) ?></h3><button class="btn icon sm ghost" data-close><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url($action)) ?>" data-typed="UPDATE"<?= $file ? ' enctype="multipart/form-data"' : '' ?>><?= csrf_field() ?>
  <?php if ($file): ?><div class="field"><label for="pkg">Package (.zip)</label><input type="file" id="pkg" name="package" accept=".zip,application/zip" required><div class="hint">A Niyanta release zip (must contain niyanta.json and core/version.php). Max <?= e(BackupService::size($uploadMax)) ?> by PHP limits.</div></div><?php endif ?>
  <ol class="steps-list small"><li>Preflight checks: zip support, writable folders, disk space, PHP version, package integrity.</li><li>Full backup (database, uploads, config) + code backup.</li><li>Maintenance mode on → files installed (config, storage and uploads preserved) → migrations → caches cleared.</li><li>Any failure after copying restores the previous code automatically.</li></ol>
  <label class="check small"><input type="checkbox" name="force" value="1"> Allow reinstall / downgrade to an older version</label>
  <div class="field"><label for="<?= e($mid) ?>-c">Type <b>UPDATE</b> to confirm</label><input id="<?= e($mid) ?>-c" type="text" class="confirm-input" name="confirm" autocomplete="off" data-typed-input placeholder="UPDATE"></div>
  <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button type="submit" class="btn primary"><?= icon('download', 'sm') ?> Start update</button></div>
</form></dialog>
<?php endforeach ?>
