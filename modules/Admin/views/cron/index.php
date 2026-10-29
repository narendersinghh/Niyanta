<?php
$line = '* * * * * '.$php.' '.$path.' >/dev/null 2>&1';
$state = ['healthy' => ['mint', 'Healthy', 'check-circle'], 'stale' => ['peach', 'Stale', 'alert'], 'never' => ['lemon', 'Never ran', 'alert']][$health['state']];
?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => 'cron']) ?>
<div class="set-main">
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'cron', 'actions' => '<form method="post" action="'.e(url('/admin/cron/run')).'">'.csrf_field().'<button class="btn primary">'.icon('play', 'sm').' Run all due jobs</button></form>']) ?>
  <div class="grid g4 mini-stats">
    <div class="tile bg-<?= $state[0] ?>"><span class="tile-ic"><?= icon($state[2]) ?></span><div><div class="num" style="font-size:1.6rem"><?= e($state[1]) ?></div><div class="lbl">Heartbeat <?= $health['heartbeat'] ? e(ago($health['heartbeat'])) : '—' ?></div></div></div>
    <div class="tile dark"><span class="tile-ic"><?= icon('list') ?></span><div><div class="num"><?= count($jobs) ?></div><div class="lbl">Registered jobs</div></div></div>
    <div class="tile bg-<?= $failed24 ? 'pink' : 'sky' ?>"><span class="tile-ic"><?= icon('activity') ?></span><div><div class="num"><?= (int) $failed24 ?></div><div class="lbl">Failed runs (24 h)</div></div></div>
    <div class="tile bg-lav"><span class="tile-ic"><?= icon('lock') ?></span><div><div class="num" style="font-size:1.6rem">On</div><div class="lbl">Overlap protection (flock)</div></div></div>
  </div>
  <?php if ($health['state'] !== 'healthy'): ?><div class="alert warn"><?= icon('alert', 'sm') ?><div><?= $health['state'] === 'never' ? 'The scheduler has never run.' : 'No heartbeat for '.e(ago($health['heartbeat'])).'.' ?> Add the cron entry below (or the web cron URL) so emails, reminders and backups run on time.</div></div><?php endif ?>
  <div class="card hero-mini"><div class="grow" style="min-width:0"><h3>Set up the system cron</h3><p class="small muted">Add this single line to your crontab (runs every minute; every 5 minutes also works):</p>
    <div class="codebox"><code><?= e($line) ?></code><button class="btn sm" data-copy="<?= e($line) ?>"><?= icon('copy', 'sm') ?> Copy</button></div>
    <div class="grid g2 mt"><div><h4>cPanel</h4><ol class="steps-list small prose"><li>Open <b>Advanced → Cron Jobs</b>.</li><li>Common settings: <b>Once Per Minute</b> (<code>* * * * *</code>).</li><li>Command: <code><?= e($php.' '.$path) ?></code></li><li>Click <b>Add New Cron Job</b> and check this page after a minute.</li></ol></div>
      <div><h4>Plesk / VPS</h4><ol class="steps-list small prose"><li>Plesk: <b>Scheduled Tasks → Add Task → Run a PHP script</b>.</li><li>VPS: run <code>crontab -e</code> as the web user and paste the line.</li><li>If your host uses a different PHP path, run <code>which php</code> to find it.</li></ol></div></div></div>
    <?= illus('clock-worker') ?></div>
  <div class="grid g2" style="align-items:start">
    <div class="card"><div class="card-head"><h3>Web cron fallback</h3><?= $webOn ? badge('active', 'Enabled') : badge('inactive', 'Disabled') ?></div>
      <p class="small muted">No crontab? Ask a free uptime/cron service to request this secret URL every 1–5 minutes. Requests are rate-limited and share the same lock.</p>
      <?php if ($webUrl): ?><div class="codebox"><code><?= e($webUrl) ?></code><button class="btn sm" data-copy="<?= e($webUrl) ?>"><?= icon('copy', 'sm') ?> Copy</button></div><?php endif ?>
      <form method="post" action="<?= e(url('/admin/cron/token')) ?>" data-confirm="Generate a new URL? The current URL stops working immediately." class="mt"><?= csrf_field() ?><button class="btn ghost sm"><?= icon('refresh', 'sm') ?> Regenerate secret URL</button></form></div>
    <?= partial('admin::settings/_form', ['tab' => 'cron', 'fields' => $fields, 'title' => 'Web cron access', 'bar' => false]) ?>
  </div>
  <div class="card"><div class="card-head"><h3>Scheduled jobs</h3><span class="xs muted"><?= icon('lock', 'sm') ?> A file lock (storage/cache/cron.lock) prevents duplicate concurrent runs</span></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Job</th><th>Schedule</th><th>Last run</th><th>Status</th><th>Output</th><th></th></tr></thead><tbody>
    <?php foreach ($jobs as $name => $j): $l = $j['last']; ?><tr>
      <td class="b mono small"><?= e($name) ?></td><td class="small nowrap"><?= e($j['schedule']) ?></td>
      <td class="small nowrap"><?= $l && $l['last_run_at'] ? e(fdatetime($l['last_run_at'])).'<div class="xs muted">'.e(ago($l['last_run_at'])).'</div>' : '<span class="muted">Never</span>' ?></td>
      <td><?= $l ? badge($l['last_status']) : badge('draft', 'Waiting') ?></td>
      <td class="xs muted"><span class="truncate" style="display:block;max-width:200px" title="<?= e($l['last_output'] ?? '') ?>"><?= e($l['last_output'] ?? '') ?></span></td>
      <td class="actions"><form method="post" action="<?= e(url('/admin/cron/run')) ?>"><?= csrf_field() ?><input type="hidden" name="job" value="<?= e($name) ?>"><button class="btn sm soft"><?= icon('play', 'sm') ?> Run now</button></form></td>
    </tr><?php endforeach ?></tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Recent runs</h3>
    <form method="get" class="filters" style="margin:0"><select name="job" data-autosubmit aria-label="Filter by job"><option value="">All jobs</option><?php foreach (array_keys($jobs) as $n): ?><option value="<?= e($n) ?>"<?= selected($n, $jobFilter) ?>><?= e($n) ?></option><?php endforeach ?></select></form></div>
    <?php if (!$logs['items']): ?><?= empty_state('No runs recorded yet', 'Runs appear here once cron starts.', 'clock-worker') ?><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Job</th><th>Status</th><th>Duration</th><th>Output</th></tr></thead><tbody>
    <?php foreach ($logs['items'] as $r): ?><tr><td class="small nowrap"><?= e(fdatetime($r['ran_at'])) ?></td><td class="mono small"><?= e($r['job']) ?></td><td><?= badge($r['status']) ?></td><td class="small"><?= number_format((int) $r['duration_ms']) ?> ms</td><td class="xs muted" style="max-width:340px;overflow-wrap:anywhere"><?= e(str_limit($r['output'], 200)) ?></td></tr><?php endforeach ?>
    </tbody></table></div><?= pager($logs) ?><?php endif ?></div>
</div>
</div>
