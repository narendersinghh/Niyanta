<?php
use Modules\Admin\Services\BackupService;
$h = $health; $ok = count(array_filter($h['checks'], static fn ($c) => $c[1])); $pct = (int) round($ok / max(1, count($h['checks'])) * 100);
$cron = $h['cron']; $cronPill = ['healthy' => ['mint', 'Healthy'], 'stale' => ['red', 'Stale'], 'never' => ['lemon', 'Never ran']][$cron['state']];
$reqFail = array_filter($h['requirements'], static fn ($r) => !$r[1]);
?>
<div class="card hero-mini">
  <div class="flex wrap" style="gap:1.4rem">
    <div class="health-ring" style="--v:<?= $pct ?>"><div><span><b><?= $ok ?>/<?= count($h['checks']) ?></b><span class="xs muted">checks</span></span></div></div>
    <div><span class="pill pill-<?= $pct === 100 ? 'mint' : ($pct >= 60 ? 'lemon' : 'red') ?>"><?= $pct === 100 ? 'All systems go' : ($pct >= 60 ? 'Needs a little attention' : 'Action required') ?></span>
      <h2><?= e(setting('branding.app_name', 'Niyanta')) ?> v<?= e($h['version']) ?></h2>
      <p class="small muted mb0">PHP <?= e($h['php']) ?> · <?= e(str_contains(strtolower($h['mysql']), 'mariadb') ? 'MariaDB' : 'MySQL') ?> <?= e(preg_replace('/-.*/', '', $h['mysql'])) ?> · <?= e($h['timezone']) ?><?= $h['maintenance'] ? ' · <b>Maintenance mode is ON</b>' : '' ?></p></div>
  </div>
  <?= illus('security-shield') ?>
</div>
<?php if ($h['maintenance']): ?><div class="alert warn"><?= icon('alert', 'sm') ?> Maintenance mode is on (storage/maintenance.flag). Only Super Admins can use the app until it is removed.</div><?php endif ?>
<div class="grid g4">
  <div class="tile dark"><span class="tile-ic"><?= icon('package') ?></span><div><div class="num">v<?= e($h['version']) ?></div><div class="lbl"><?= !empty($lastCheck['available']) ? 'Update '.e($lastCheck['version']).' available' : 'Installed version' ?></div></div></div>
  <a class="tile bg-<?= $cron['state'] === 'healthy' ? 'mint' : 'peach' ?>" href="<?= e(url('/admin/cron')) ?>"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num" style="font-size:1.6rem"><?= e($cronPill[1]) ?></div><div class="lbl">Cron · <?= $cron['heartbeat'] ? e(ago($cron['heartbeat'])) : 'no heartbeat yet' ?></div></div></a>
  <a class="tile bg-lav" href="<?= e(url('/admin/backups')) ?>"><span class="tile-ic"><?= icon('cloud') ?></span><div><div class="num" style="font-size:1.6rem"><?= $h['last_backup'] ? e(ago($h['last_backup']['created_at'])) : 'None' ?></div><div class="lbl">Last backup<?= $h['last_backup'] ? ' · '.e(BackupService::size((int) $h['last_backup']['size'])) : '' ?></div></div></a>
  <a class="tile bg-<?= $h['pending'] ? 'lemon' : 'sky' ?>" href="<?= e(url('/admin/updates')) ?>"><span class="tile-ic"><?= icon('layers') ?></span><div><div class="num"><?= count($h['pending']) ?></div><div class="lbl">Pending migrations</div></div></a>
</div>
<div class="grid g2">
  <div class="card"><div class="card-head"><h3>Health checks</h3><span class="pill pill-gray"><?= $pct ?>%</span></div>
    <div class="checks"><?php foreach ($h['checks'] as [$label, $pass, $hint]): ?><div class="chk <?= $pass ? 'ok' : 'bad' ?>"><span class="dotic"><?= icon($pass ? 'check' : 'alert', 'sm') ?></span><div><b class="small"><?= e($label) ?></b><?php if (!$pass): ?><div class="h"><?= e($hint) ?></div><?php endif ?></div></div><?php endforeach ?></div></div>
  <div class="card"><div class="card-head"><h3>System information</h3></div>
    <dl class="kv">
      <dt>App URL</dt><dd class="mono"><?= e((string) \Core\App::config('app_url', '')) ?></dd>
      <dt>PHP</dt><dd><?= e($h['php']) ?> (<?= e($h['sapi']) ?>, <?= e($h['os']) ?>)<?= $h['opcache'] ? ' · OPcache on' : '' ?></dd>
      <dt>Database</dt><dd><?= e($h['mysql']) ?></dd>
      <dt>Debug mode</dt><dd><?= $h['debug'] ? badge('failed', 'On — disable in production') : badge('success', 'Off') ?></dd>
      <dt>Mail driver</dt><dd><?= e($h['mail_driver'] === 'smtp' ? 'SMTP ('.setting('email.smtp_host', 'not set').')' : 'PHP mail()') ?></dd>
      <dt>Users</dt><dd><?= (int) ($counts['active'] ?? 0) ?> active<?= !empty($counts['inactive']) ? ', '.(int) $counts['inactive'].' inactive' : '' ?></dd>
      <dt>Modules</dt><dd><?= (int) $modulesOn ?> enabled</dd>
      <dt>Disk free</dt><dd><?= $h['disk_free'] !== null ? e(BackupService::size($h['disk_free'])).($h['disk_total'] ? ' of '.e(BackupService::size($h['disk_total'])) : '') : '—' ?></dd>
      <dt>PHP limits</dt><dd class="small">memory <?= e($h['limits']['memory_limit']) ?> · upload <?= e($h['limits']['upload_max_filesize']) ?> · post <?= e($h['limits']['post_max_size']) ?> · time <?= e($h['limits']['max_execution_time']) ?>s</dd>
      <dt>Update check</dt><dd><?= !empty($lastCheck['checked_at']) ? e(ago($lastCheck['checked_at'])).(!empty($lastCheck['error']) ? ' — failed' : '') : 'Never' ?></dd>
    </dl></div>
</div>
<div class="grid g2">
  <div class="card"><div class="card-head"><h3>Server requirements</h3><?= $reqFail ? badge('pending', count($reqFail).' missing') : badge('success', 'All met') ?></div>
    <div class="checks compact"><?php foreach ($h['requirements'] as [$label, $pass, $required]): ?><div class="chk <?= $pass ? 'ok' : ($required ? 'req' : 'bad') ?>"><span class="dotic"><?= icon($pass ? 'check' : 'x', 'sm') ?></span><div class="grow small"><?= e($label) ?></div><?php if (!$pass): ?><span class="pill pill-<?= $required ? 'red' : 'lemon' ?>"><?= $required ? 'Required' : 'Optional' ?></span><?php endif ?></div><?php endforeach ?></div></div>
  <div class="card"><div class="card-head"><h3>Writable folders</h3></div>
    <div class="checks compact"><?php foreach ($h['writable'] as $dir => $w): ?><div class="chk <?= $w ? 'ok' : 'req' ?>"><span class="dotic"><?= icon($w ? 'check' : 'x', 'sm') ?></span><div class="grow small mono"><?= e($dir) ?>/</div><?= $w ? '' : badge('failed', 'Not writable') ?></div><?php endforeach ?></div>
    <div class="quick mt"><a class="soft-lav" href="<?= e(url('/admin/backups')) ?>"><?= icon('cloud', 'sm') ?> Backups</a><a class="soft-mint" href="<?= e(url('/admin/updates')) ?>"><?= icon('refresh', 'sm') ?> Updates</a><a class="soft-peach" href="<?= e(url('/admin/cron')) ?>"><?= icon('clock', 'sm') ?> Cron</a><a class="soft-sky" href="<?= e(url('/admin/audit')) ?>"><?= icon('history', 'sm') ?> Audit log</a></div></div>
</div>
