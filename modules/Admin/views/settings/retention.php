<?php
use Modules\Admin\Services\BackupService;
$labels = ['audit' => 'Audit log', 'notifications' => 'Notifications', 'mail_queue' => 'Emails', 'cron_logs' => 'Scheduler runs', 'ai_usage' => 'AI usage', 'sessions' => 'Sessions', 'rate_limits' => 'Rate-limit counters', 'password_resets' => 'Expired reset links'];
$tables = ['audit_logs' => ['Audit log', 'history', 'lav'], 'notifications' => ['Notifications', 'bell', 'sky'], 'mail_queue' => ['Email queue', 'mail', 'peach'], 'cron_logs' => ['Scheduler runs', 'clock', 'lemon'], 'screenshots' => ['Screenshots', 'camera', 'pink'], 'ai_usage' => ['AI usage', 'sparkles', 'mint']];
$run = '<form method="post" action="'.e(url('/admin/settings/retention/run')).'" data-no-lock="1" data-busy="Cleaning up…" data-confirm="Delete everything older than the retention periods now? This cannot be undone.">'.csrf_field().'<button class="btn primary">'.icon('play', 'sm').' Run cleanup now</button></form>';
?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'retention', 'actions' => $run]) ?>
<div class="card ret-summary">
  <div class="ret-last"><span class="ic-chip" style="--c:var(--<?= $last ? 'mint' : 'lemon' ?>)"><?= icon($last ? 'check-circle' : 'clock') ?></span>
    <div><div class="xs muted">Last cleanup</div><b><?= $last ? e(ago($last['at'])).' — removed '.number_format((int) $last['total']) : 'Not run yet' ?></b><div class="xs muted"><?= $last ? e(fdatetime($last['at'])) : 'Runs nightly at 03:10 through the scheduler' ?></div></div></div>
  <?php if ($last && array_filter($last['counts'] ?? [])): ?><div class="chips"><?php foreach (array_filter($last['counts']) as $k => $n): ?><span class="chip"><?= e($labels[$k] ?? $k) ?> <b><?= number_format((int) $n) ?></b></span><?php endforeach ?></div><?php endif ?>
</div>
<div class="ret-tables">
<?php foreach ($tables as $t => [$label, $ic, $c]): if (!isset($sizes[$t])) { continue; } ?>
  <div class="ret-t"><span class="ic-chip sm" style="--c:var(--<?= e($c) ?>)"><?= icon($ic, 'sm') ?></span><div><b><?= number_format($sizes[$t][0]) ?></b><span class="xs muted"><?= e($label) ?> · <?= e(BackupService::size($sizes[$t][1])) ?></span></div></div>
<?php endforeach ?>
</div>
<div class="alert warn"><?= icon('alert', 'sm') ?><div><b>Deletion is permanent.</b> The nightly job removes rows and files older than these periods; they cannot be restored except from a backup taken before. Set a value to <b>0</b> to keep that data forever. Unread notifications, pending emails and data that is still in use are never removed.</div></div>
<?= partial('admin::settings/_form', ['tab' => 'retention', 'fields' => $fields, 'sections' => $sections, 'submit' => 'Save retention']) ?>
