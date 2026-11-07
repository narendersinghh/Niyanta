<?php
/* Client log of this project: client_activities with project_id = this project, newest first (same cards as the client Journey). */
$base = '/projects/'.(int) $pr['id'];
$mt = feature_on('meetings') && can('meetings.use') && class_exists(\Modules\Meetings\Services\Ui::class);
?>
<div class="crm">
<div class="card">
  <div class="card-head"><div><h3>Client log</h3><p class="xs muted mb0"><?= $clientLabel ? 'Meetings, calls and requirements with <a href="'.e(url('/clients/'.(int) $pr['client_id'])).'">'.e($clientLabel).'</a> for this project. They also show on the client\'s Journey.' : 'Internal project: meetings, calls and decisions for this project.' ?></p></div>
    <?php if ($canLog): ?><button type="button" class="btn primary sm" data-modal="activity-modal" data-activity-new><?= icon('plus', 'sm') ?> Log activity</button><?php endif ?></div>
  <?php if ($mt && $canLog): ?><?= \Modules\Meetings\Services\Ui::addressHint('p', (int) $pr['id']) ?><?php endif ?>
  <?php if (!$acts): ?>
    <?= empty_state('No log entries yet', 'Log meetings, calls, requirements and follow-ups for this project'.($clientLabel ? ' with '.$clientLabel : '').'.', 'calendar-person', $canLog ? '<button type="button" class="btn primary" data-modal="activity-modal" data-activity-new>'.icon('plus', 'sm').' Log first activity</button>' : '') ?>
  <?php else: ?>
  <?= partial('crm::partials/journey_list', ['acts' => $acts, 'base' => $base, 'manage' => $canLog, 'grouped' => false, 'showProject' => false, 'transcripts' => $transcripts]) ?>
  <?php if ($jp > 1 || $logMore): ?><div class="flex between mt">
    <?php if ($jp > 1): ?><a class="btn sm ghost" href="<?= e(url($base.'?tab=log&jp='.($jp - 1))) ?>"><?= icon('chevron-left', 'sm') ?> Newer</a><?php else: ?><span></span><?php endif ?>
    <?php if ($logMore): ?><a class="btn sm ghost" href="<?= e(url($base.'?tab=log&jp='.($jp + 1))) ?>">Older <?= icon('chevron-right', 'sm') ?></a><?php endif ?>
  </div><?php endif ?>
  <?php endif ?>
</div>
</div>
