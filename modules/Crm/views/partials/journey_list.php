<?php
/* Journey timeline shared by the client page and the project page Client log.
   $acts rows, $base (entry routes prefix, e.g. /clients/5 or /projects/9), $manage, $grouped (project headings), $showProject (pill per entry),
   $projNames [id => name], $projCounts [id|'none' => n], $transcripts [activity id => transcript id] */
use Modules\Crm\Controllers\JourneyController;
use Modules\Crm\Services\{Journey, Ui};
$types = JourneyController::TYPES;
$grouped ??= false; $showProject ??= true; $projNames ??= []; $projCounts ??= []; $transcripts ??= [];
$lastDay = null; $lastGroup = false;
$dayLabel = static fn (string $day) => $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : fdate($day, 'l, d M Y'));
?>
<div class="crm-tl<?= $grouped ? ' crm-tl-grouped' : '' ?>">
  <?php foreach ($acts as $a): [$tl, $ti, $tc] = $types[$a['type']] ?? $types['other']; $day = date('Y-m-d', strtotime($a['occurred_at'])); $gk = $a['project_id'] === null ? 'none' : (string) (int) $a['project_id']; ?>
  <?php if ($grouped && $gk !== $lastGroup): $lastGroup = $gk; $lastDay = null; $n = (int) ($projCounts[$gk] ?? 0); ?>
    <div class="crm-proj-h<?= $gk === 'none' ? ' none' : '' ?>"><span class="crm-ic <?= $gk === 'none' ? 'crm-c-lav' : 'crm-c-sky' ?>"><?= icon($gk === 'none' ? 'briefcase' : 'folder', 'sm') ?></span>
      <?php if ($gk === 'none'): ?><b>Non-project activity</b><?php else: ?><a href="<?= e(url('/projects/'.$gk)) ?>"><?= e($a['project_name'] ?? ($projNames[(int) $gk] ?? 'Project #'.$gk)) ?></a><?php endif ?>
      <span class="crm-count" title="Entries"><?= $n ?></span></div>
  <?php endif ?>
  <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="crm-day"><?= e($dayLabel($day)) ?></div><?php endif ?>
  <div class="crm-tl-item crm-c-<?= e($tc) ?>" id="log-<?= (int) $a['id'] ?>">
    <span class="crm-ic solid crm-c-<?= e($tc) ?>"><?= icon($ti, 'sm') ?></span>
    <div class="crm-tl-card crm-c-<?= e($tc) ?> <?= $a['is_internal'] ? 'internal' : '' ?>">
      <div class="crm-tl-head">
        <div class="grow"><div class="crm-tl-type"><?= e($tl) ?><?= $a['is_internal'] ? ' · '.icon('lock', 'sm').' Internal' : '' ?></div><b><?= e($a['title']) ?></b></div>
        <div class="crm-tl-tools">
          <span class="xs muted nowrap" title="<?= e(fdatetime($a['occurred_at'])) ?>"><?= e(ftime($a['occurred_at'])) ?></span>
          <?php if ($manage): ?><?= Ui::more(['<button type="button" data-modal="activity-modal" data-activity="'.e(Journey::editData($a, $base)).'">'.icon('edit', 'sm').' Edit entry</button>', Ui::post($base.'/activities/'.$a['id'].'/delete', 'trash', 'Delete entry', 'Delete this journey entry?', true)], 'Entry actions') ?><?php endif ?>
        </div>
      </div>
      <?php if ($a['body']): ?><p class="crm-tl-body"><?= nl2br(e($a['body'])) ?></p><?php endif ?>
      <div class="crm-tl-foot">
        <span class="flex"><?= avatar($a['user_name'] ?? 'System', $a['user_avatar'] ?? null, 'sm') ?><?= e($a['user_name'] ?? 'System') ?></span>
        <?php if ($showProject && !$grouped && $a['project_name']): ?><a class="pill pill-sky" href="<?= e(url('/projects/'.(int) $a['project_id'])) ?>"><?= icon('folder', 'sm') ?> <?= e($a['project_name']) ?></a><?php elseif ($showProject && !$grouped && $projCounts && $a['project_id'] === null): ?><span class="pill pill-gray"><?= icon('briefcase', 'sm') ?> Non-project</span><?php endif ?>
        <?php if (isset($transcripts[(int) $a['id']])): ?><a class="pill pill-lav" href="<?= e(url('/meetings/'.(int) $transcripts[(int) $a['id']])) ?>"><?= icon('file-text', 'sm') ?> View full transcript</a><?php endif ?>
        <?php if ($a['follow_up_at']): $late = !$a['follow_up_done'] && strtotime($a['follow_up_at']) < time(); ?>
          <span class="pill <?= $a['follow_up_done'] ? 'pill-mint' : ($late ? 'pill-red' : 'pill-lemon') ?>"><?= icon($a['follow_up_done'] ? 'check' : 'clock', 'sm') ?> Follow-up <?= e(fdatetime($a['follow_up_at'])) ?><?= $a['follow_up_done'] ? ' · done' : '' ?></span>
          <?php if (!$a['follow_up_done'] && $manage): ?><form method="post" action="<?= e(url($base.'/activities/'.$a['id'].'/done')) ?>"><?= csrf_field() ?><button class="btn sm soft"><?= icon('check', 'sm') ?> Mark done</button></form><?php endif ?>
        <?php endif ?>
      </div>
    </div>
  </div>
  <?php endforeach ?>
</div>
