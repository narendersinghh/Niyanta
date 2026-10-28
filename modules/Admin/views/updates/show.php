<?php
$lines = explode("\n", rtrim((string) $u['log']));
$secs = $u['finished_at'] ? max(0, strtotime($u['finished_at']) - strtotime($u['started_at'])) : null;
?>
<?= partial('partials/page_head', ['title' => 'Update #'.$u['id'], 'subtitle' => $u['from_version'].' → '.($u['to_version'] ?? '?').' via '.$u['channel'], 'crumbs' => [['System'], ['Updates', '/admin/updates'], ['#'.$u['id']]], 'actions' => '<a class="btn ghost" href="'.e(url('/admin/updates')).'">'.icon('arrow-left', 'sm').' Back to updates</a>']) ?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => 'updates']) ?>
<div class="set-main">
  <div class="grid g4">
    <div class="tile <?= $u['status'] === 'success' ? 'bg-mint' : ($u['status'] === 'running' ? 'bg-sky' : 'bg-peach') ?>"><span class="tile-ic"><?= icon($u['status'] === 'success' ? 'check-circle' : 'alert') ?></span><div><div class="num" style="font-size:1.5rem"><?= e(ucwords(str_replace('_', ' ', $u['status']))) ?></div><div class="lbl">Status</div></div></div>
    <div class="tile bg-lav"><span class="tile-ic"><?= icon('package') ?></span><div><div class="num" style="font-size:1.5rem"><?= e($u['to_version'] ?? '?') ?></div><div class="lbl">from <?= e($u['from_version']) ?></div></div></div>
    <div class="tile bg-lemon"><span class="tile-ic"><?= icon('timer') ?></span><div><div class="num" style="font-size:1.5rem"><?= $secs !== null ? $secs.'s' : '—' ?></div><div class="lbl">Duration</div></div></div>
    <a class="tile dark" href="<?= e(url('/admin/backups')) ?>"><span class="tile-ic"><?= icon('cloud') ?></span><div><div class="num" style="font-size:1.5rem"><?= $u['backup_id'] ? '#'.(int) $u['backup_id'] : '—' ?></div><div class="lbl truncate"><?= e($u['backup_file'] ?? 'Pre-update backup') ?></div></div></a>
  </div>
  <div class="card"><div class="card-head"><h3>Details</h3></div>
    <dl class="kv"><dt>Channel</dt><dd><?= e($u['channel']) ?></dd><dt>Reference</dt><dd class="mono"><?= e($u['ref'] ?? '—') ?></dd><dt>Started</dt><dd><?= e(fdatetime($u['started_at'])) ?> by <?= e($u['by_name'] ?? 'system') ?></dd><dt>Finished</dt><dd><?= e(fdatetime($u['finished_at'])) ?></dd></dl></div>
  <div class="card"><div class="card-head"><h3>Step log</h3><button class="btn sm ghost" data-copy="<?= e((string) $u['log']) ?>"><?= icon('copy', 'sm') ?> Copy</button></div>
    <pre class="log-view"><?php foreach ($lines as $l): ?><span class="<?= str_contains($l, 'FAILED') ? 'err' : (str_contains($l, 'successfully') || str_contains($l, 'verified') ? 'ok' : '') ?>"><?= e($l) ?></span>
<?php endforeach ?></pre>
    <?php if ($u['status'] === 'rolled_back'): ?><div class="alert warn mt mb0"><?= icon('alert', 'sm') ?> The code was rolled back. If a migration ran before the failure, restore the pre-update backup from the Backups page.</div><?php endif ?></div>
</div>
</div>
