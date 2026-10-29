<?php
use Modules\Admin\Services\BackupService;
$ok = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'success'));
$last = $ok[0] ?? null;
$enabled = setting('backups.enabled', '1') === '1';
$db = (array) \Core\App::config('db', []);
?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => 'backups']) ?>
<div class="set-main">
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'backups', 'actions' => '<button class="btn primary" data-modal="new-backup">'.icon('plus', 'sm').' Create backup</button>']) ?>
  <div class="grid g4 mini-stats">
    <div class="tile dark"><span class="tile-ic"><?= icon('cloud') ?></span><div><div class="num"><?= count($ok) ?></div><div class="lbl">Stored backups (keep <?= (int) setting('backups.keep', 7) ?>)</div></div></div>
    <div class="tile bg-mint"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num" style="font-size:1.6rem"><?= $last ? e(ago($last['created_at'])) : 'Never' ?></div><div class="lbl">Last successful backup</div></div></div>
    <div class="tile bg-lav"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num" style="font-size:1.6rem"><?= $enabled ? e(setting('backups.time', '02:30')) : 'Off' ?></div><div class="lbl"><?= $enabled ? 'Daily · '.(setting('backups.include_uploads') === '1' ? 'full archive' : 'database only') : 'Automatic backups disabled' ?></div></div></div>
    <div class="tile bg-sky"><span class="tile-ic"><?= icon('database') ?></span><div><div class="num" style="font-size:1.6rem"><?= e(BackupService::size($total)) ?></div><div class="lbl"><?= $free !== null ? e(BackupService::size($free)).' free on disk' : 'Total size' ?></div></div></div>
  </div>
  <?php if (!$zip): ?><div class="alert warn"><?= icon('alert', 'sm') ?> The PHP zip extension is missing, so only database backups are available.</div><?php endif ?>
  <div class="card">
    <div class="card-head"><h3>Backup history</h3><span class="xs muted">Files are verified with SHA-256 before restore</span></div>
    <?php if (!$rows): ?><?= empty_state('No backups yet', 'Create your first backup now — it takes a few seconds.', 'backup-database', '<button class="btn primary" data-modal="new-backup">'.icon('plus', 'sm').' Create backup</button>') ?><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Backup</th><th>Type</th><th>Size</th><th>Created</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $b): ?><tr>
        <td><div class="b mono small"><?= e($b['filename']) ?></div><div class="xs muted"><?= e($b['note'] ?? '') ?><?= $b['sha256'] ? ' · sha256 '.e(substr($b['sha256'], 0, 12)).'…' : '' ?></div></td>
        <td><?= $b['type'] === 'full' ? '<span class="pill pill-lav">Full</span>' : '<span class="pill pill-sky">Database</span>' ?></td>
        <td class="nowrap"><?= $b['status'] === 'success' ? e(BackupService::size((int) $b['size'])) : '—' ?></td>
        <td class="nowrap small"><?= e(fdatetime($b['created_at'])) ?><div class="xs muted"><?= e($b['by_name'] ?? 'Scheduler') ?></div></td>
        <td><?= $b['status'] === 'success' && !$b['exists'] ? badge('failed', 'File missing') : badge($b['status']) ?></td>
        <td class="actions"><?php if ($b['exists']): ?><a class="btn icon sm ghost" href="<?= e(url('/admin/backups/'.$b['id'].'/download')) ?>" title="Download"><?= icon('download', 'sm') ?></a>
          <button class="btn icon sm ghost" data-modal="restore-<?= (int) $b['id'] ?>" title="Restore database"><?= icon('refresh', 'sm') ?></button><?php endif ?>
          <form method="post" action="<?= e(url('/admin/backups/'.$b['id'].'/delete')) ?>" data-confirm="Delete this backup permanently?" style="display:inline"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete"><?= icon('trash', 'sm') ?></button></form></td>
      </tr><?php endforeach ?></tbody>
    </table></div><?php endif ?>
  </div>
  <div class="grid g2" style="align-items:start">
    <?= partial('admin::settings/_form', ['tab' => 'backups', 'fields' => $fields, 'title' => 'Schedule & retention', 'bar' => false]) ?>
    <div class="card"><div class="card-head"><h3><?= icon('info') ?> How to restore manually</h3></div>
      <ol class="steps-list prose">
        <li>Download the backup. For a <b>full</b> backup, unzip it — the dump is <code>database.sql.gz</code>.</li>
        <li><b>phpMyAdmin:</b> select the database <code><?= e($db['name'] ?? 'your_db') ?></code> → <b>Import</b> → choose the <code>.sql.gz</code> file → Go. (Split very large files or use the CLI.)</li>
        <li><b>Command line:</b> <code>gunzip &lt; backup.sql.gz | mysql -u <?= e($db['user'] ?? 'user') ?> -p <?= e($db['name'] ?? 'your_db') ?></code></li>
        <li>Full backups also contain <code>storage/uploads/</code>, <code>assets/uploads/</code> and <code>config/config.php</code> — copy them back to the same paths.</li>
        <li>Keep the original <code>config/config.php</code> (its app key decrypts stored secrets).</li>
      </ol>
      <div class="alert info mt mb0"><?= icon('shield', 'sm') ?> In-app restore takes a fresh safety backup first and enables maintenance mode while it runs.</div></div>
  </div>
</div>
</div>
<dialog class="modal" id="new-backup"><div class="modal-head"><h3>Create backup</h3><button class="btn icon sm ghost" data-close><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url('/admin/backups')) ?>"><?= csrf_field() ?>
  <?= illus('backup-database', 'modal-illus') ?>
  <div class="field"><label>Type</label><select name="type"><option value="database">Database only (.sql.gz)</option><?php if ($zip): ?><option value="full">Full — database, uploads & config (.zip)</option><?php endif ?></select></div>
  <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="150" placeholder="e.g. Before importing clients"></div>
  <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('cloud', 'sm') ?> Start backup</button></div>
</form></dialog>
<?php foreach ($rows as $b): if (!$b['exists']) { continue; } ?>
<dialog class="modal" id="restore-<?= (int) $b['id'] ?>"><div class="modal-head"><h3>Restore database</h3><button class="btn icon sm ghost" data-close><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url('/admin/backups/'.$b['id'].'/restore')) ?>" data-typed="RESTORE"><?= csrf_field() ?>
  <div class="alert error mb0"><?= icon('alert', 'sm') ?><div>This replaces <b>all current data</b> with the contents of <span class="mono"><?= e($b['filename']) ?></span> from <?= e(fdatetime($b['created_at'])) ?>. Changes made since then will be lost.</div></div>
  <ul class="steps-list small"><li>A safety backup of the current database is taken first.</li><li>Maintenance mode is enabled while statements run, then switched off.</li><li>Uploaded files are not touched. Users may need to sign in again.</li></ul>
  <div class="field"><label for="rc-<?= (int) $b['id'] ?>">Type <b>RESTORE</b> to confirm</label><input id="rc-<?= (int) $b['id'] ?>" type="text" class="confirm-input" name="confirm" autocomplete="off" data-typed-input placeholder="RESTORE"></div>
  <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button type="submit" class="btn danger"><?= icon('refresh', 'sm') ?> Restore now</button></div>
</form></dialog>
<?php endforeach ?>
