<?php
use Modules\Admin\Services\BackupService;
$colors = ['overview' => 'mint', 'general' => 'sky', 'users' => 'lav', 'modules' => 'lemon', 'appearance' => 'pink', 'email' => 'peach', 'ai' => 'lav', 'hr' => 'mint', 'payroll' => 'lemon', 'storage' => 'sky', 'security' => 'peach'];
$sub = in_array($tab, ['overview', 'appearance'], true) ? 'admin::settings/'.$tab : null;
?>
<?= partial('partials/page_head', ['title' => 'Settings', 'subtitle' => 'Configure your workspace. Only Super Admins can see this area; every change is audit-logged.', 'crumbs' => [['System'], ['Settings', '/admin/settings'], [$tabs[$tab][0]]]]) ?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => $tab]) ?>
<div class="set-main">
<?php if ($sub): ?><?= partial($sub, get_defined_vars()) ?><?php else: ?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'color' => $colors[$tab] ?? 'lav']) ?>
<?php switch ($tab):
  case 'users': ?>
  <div class="grid g2">
    <div class="card"><div class="card-head"><h3>People & access</h3></div>
      <div class="list"><?php foreach ($roles as $r): ?><div class="list-item"><span class="avatar bg-<?= $r['slug'] === 'super_admin' ? 'dark' : 'lav' ?> sm"><?= icon($r['slug'] === 'super_admin' ? 'shield' : 'user', 'sm') ?></span><div class="grow"><b><?= e($r['name']) ?></b></div><span class="pill pill-gray"><?= (int) $r['n'] ?> active</span></div><?php endforeach ?></div>
    </div>
    <div class="card soft-lav" style="border:0"><h3>Manage access</h3><p class="small muted">Add people, assign roles and fine-tune the <?= (int) $permCount ?> granular permissions enforced on every route.</p>
      <div class="flex wrap"><a class="btn primary sm" href="<?= e(url('/admin/users')) ?>"><?= icon('users', 'sm') ?> Users</a><a class="btn ghost sm" href="<?= e(url('/admin/roles')) ?>"><?= icon('key', 'sm') ?> Roles & permissions</a></div></div>
  </div>
  <?= partial('admin::settings/_form', ['tab' => 'users', 'fields' => $fields, 'title' => 'Password & sign-in policy']) ?>
<?php break; case 'modules': $pal = ['lav', 'mint', 'lemon', 'peach', 'sky', 'pink']; $i = 0; ?>
  <div class="card"><div class="card-head"><h3><?= count(array_filter(array_keys($modules), [\Core\Modules::class, 'enabled'])) ?> of <?= count($modules) ?> modules enabled</h3><a class="btn primary sm" href="<?= e(url('/admin/modules')) ?>"><?= icon('puzzle', 'sm') ?> Manage modules</a></div>
    <?php foreach ($modules as $k => $m): $on = \Core\Modules::enabled($k); ?><div class="mod-row"><span class="mi bg-<?= $pal[$i++ % 6] ?>"><?= icon($m['icon'] ?? 'puzzle', 'sm') ?></span><div class="grow"><b><?= e($m['name']) ?></b><div class="xs muted truncate"><?= e($m['description'] ?? '') ?></div></div><span class="pill pill-gray">v<?= e($m['version'] ?? '1.0') ?></span><?= !empty($m['core']) ? badge('active', 'Core') : ($on ? badge('active', 'Enabled') : badge('inactive', 'Disabled')) ?></div><?php endforeach ?>
  </div>
<?php break; case 'email': ?>
  <?= partial('admin::settings/_form', ['tab' => 'email', 'fields' => $fields]) ?>
  <div class="card hero-mini"><div><h3>Send a test email</h3><p class="small muted mb">Sends a message to <b><?= e(user()['email']) ?></b> right now (not queued) using the saved settings, and shows any error.</p>
    <form method="post" action="<?= e(url('/admin/settings/email/test')) ?>"><?= csrf_field() ?><button class="btn primary"><?= icon('send', 'sm') ?> Send test email</button></form></div><?= illus('mail-envelope') ?></div>
<?php break; case 'ai': ?>
  <div class="card hero-mini"><div><span class="pill pill-<?= setting('ai.enabled') === '1' ? 'mint' : 'gray' ?>"><?= setting('ai.enabled') === '1' ? 'AI is on' : 'AI is off' ?></span><h2>AI assistant</h2>
    <p class="small muted">Provider: <b><?= e(ucfirst((string) setting('ai.provider', 'anthropic'))) ?></b> · Monthly budget: <b><?= e(number_format((int) setting('ai.monthly_token_budget', 0))) ?></b> tokens · Per-user limit: <b><?= e(setting('ai.user_daily_requests', '50')) ?></b>/day · PII redaction: <b><?= setting('ai.redact_pii', '1') === '1' ? 'on' : 'off' ?></b></p>
    <?php if ($aiOn): ?><a class="btn primary" href="<?= e(url('/ai/settings')) ?>"><?= icon('sliders', 'sm') ?> Open AI settings</a><?php else: ?><p class="small mb">The AI module is disabled.</p><a class="btn ghost" href="<?= e(url('/admin/modules')) ?>"><?= icon('puzzle', 'sm') ?> Enable it in Modules</a><?php endif ?></div><?= illus('ai-robot') ?></div>
  <?php if ($aiView && $aiOn): ?><div class="alert info"><?= icon('info', 'sm') ?> API keys are stored encrypted and are never shown again after saving.</div><?php endif ?>
<?php break; case 'storage': $max = max(1, array_sum(array_column($usage, 1))); ?>
  <div class="grid g3">
    <div class="tile bg-sky"><span class="tile-ic"><?= icon('database') ?></span><div><div class="num"><?= e(BackupService::size(array_sum(array_column($usage, 1)))) ?></div><div class="lbl">Used by Niyanta data</div></div></div>
    <div class="tile bg-mint"><span class="tile-ic"><?= icon('server') ?></span><div><div class="num"><?= $health['disk_free'] !== null ? e(BackupService::size($health['disk_free'])) : '—' ?></div><div class="lbl">Free disk space</div></div></div>
    <div class="tile bg-lemon"><span class="tile-ic"><?= icon('upload') ?></span><div><div class="num"><?= e($health['limits']['upload_max_filesize']) ?></div><div class="lbl">PHP upload limit (post <?= e($health['limits']['post_max_size']) ?>)</div></div></div>
  </div>
  <div class="card"><div class="card-head"><h3>Disk usage</h3><span class="xs muted">Private folders, never served directly</span></div>
    <div class="usage"><?php foreach ($usage as $label => [$dir, $bytes, $c]): ?><div class="row"><div><b><?= e($label) ?></b><div class="xs muted mono"><?= e($dir) ?></div></div><div class="progress"><span style="width:<?= round($bytes / $max * 100, 1) ?>%;background:var(--<?= e($c) ?>)"></span></div><div class="right b"><?= e(BackupService::size($bytes)) ?></div></div><?php endforeach ?></div></div>
  <?= partial('admin::settings/_form', ['tab' => 'storage', 'fields' => $fields, 'title' => 'Upload limits']) ?>
<?php break; case 'security': ?>
  <div class="grid g3">
    <div class="tile dark"><span class="tile-ic"><?= icon('shield') ?></span><div><div class="num"><?= (int) $failed ?></div><div class="lbl">Failed sign-ins (7 days)</div></div></div>
    <div class="tile bg-peach"><span class="tile-ic"><?= icon('lock') ?></span><div><div class="num"><?= (int) $denied ?></div><div class="lbl">Access denied (7 days)</div></div></div>
    <a class="tile bg-lav" href="<?= e(url('/admin/audit')) ?>"><span class="tile-ic"><?= icon('history') ?></span><div><div class="b" style="font-size:1.2rem">Audit log</div><div class="lbl">Review every change <?= icon('arrow-right', 'sm') ?></div></div></a>
  </div>
  <?= partial('admin::settings/_form', ['tab' => 'security', 'fields' => $fields]) ?>
  <div class="card danger-card"><div class="flex between wrap"><div class="grow"><h3><?= icon('log-out') ?> Sign out everyone</h3><p class="small muted mb0">Immediately ends every session of all <?= (int) $sessions ?> other user(s) on every device — use after a suspected compromise or a password policy change. You stay signed in.</p></div>
    <form method="post" action="<?= e(url('/admin/settings/security/logout-all')) ?>" data-confirm="Sign out all other users on every device now?"><?= csrf_field() ?><button class="btn danger"><?= icon('log-out', 'sm') ?> Force sign-out all users</button></form></div></div>
<?php break; default: ?>
  <?= partial('admin::settings/_form', ['tab' => $tab, 'fields' => $fields]) ?>
<?php endswitch ?>
<?php endif ?>
</div>
</div>
