<?php
/* "Log activity" dialog shared by the client page and the project page.
   $action (POST target for new entries), $projectOptions [id => name] (client page, optional select) or $fixedProject ['id', 'name'] (project page, hidden),
   $clientId (context for meeting transcript detection, null for internal projects) */
use Modules\Crm\Controllers\JourneyController;
$types = JourneyController::TYPES;
$projectOptions ??= []; $fixedProject ??= null; $clientId ??= null;
$meeting = feature_on('meetings') && can('meetings.use') && class_exists(\Modules\Meetings\Services\Ui::class);
?>
<dialog class="modal" id="activity-modal">
  <form method="post" action="<?= e(url($action)) ?>" data-new-action="<?= e(url($action)) ?>" data-activity-form<?= $meeting ? ' enctype="multipart/form-data"' : '' ?>><?= csrf_field() ?>
    <div class="modal-head"><h3 data-modal-title data-new="Log activity" data-edit="Edit journey entry">Log activity</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <?php if ($fixedProject): ?><input type="hidden" name="project_id" value="<?= (int) $fixedProject['id'] ?>"><div class="crm-fixed-proj"><?= icon('folder', 'sm') ?> <span>Project</span> <b><?= e($fixedProject['name']) ?></b></div><?php endif ?>
      <div class="field"><label>Type</label><div class="crm-typepick">
        <?php foreach ($types as $tk => [$tl, $ti, $tc]): if ($tk === 'payment' || $tk === 'assignment') { continue; } ?><label class="crm-type crm-c-<?= e($tc) ?>"><input type="radio" name="type" value="<?= e($tk) ?>"<?= checked($tk === 'meeting') ?>><span><?= icon($ti, 'sm') ?> <?= e($tl) ?></span></label><?php endforeach ?>
        <label class="crm-type crm-c-sky hide" data-system-type><input type="radio" name="type" value="assignment"><span><?= icon('user-plus', 'sm') ?> Team assignment</span></label>
        <label class="crm-type crm-c-mint hide" data-system-type><input type="radio" name="type" value="payment"><span><?= icon('wallet', 'sm') ?> Payment</span></label>
      </div></div>
      <?php if ($meeting): ?><?= \Modules\Meetings\Services\Ui::attachSection(['client_id' => $clientId, 'project_id' => $fixedProject['id'] ?? null, 'project_name' => $fixedProject['name'] ?? null]) ?><?php endif ?>
      <div class="field"><label for="a-title">Title *</label><input id="a-title" type="text" name="title" required maxlength="190" placeholder="e.g. Kick-off meeting for the Q3 campaign"></div>
      <div class="form-grid">
        <div class="field"><label for="a-when">When *</label><input id="a-when" type="datetime-local" name="occurred_at" required value="<?= e(date('Y-m-d\TH:i')) ?>" data-default="<?= e(date('Y-m-d\TH:i')) ?>"></div>
        <div class="field"><label for="a-fu">Follow-up on</label><input id="a-fu" type="datetime-local" name="follow_up_at"><div class="hint">The account team is reminded on the day.</div></div>
        <?php if (!$fixedProject && $projectOptions): ?><div class="field full"><label for="a-proj">Project <span class="muted xs">optional</span></label><select id="a-proj" name="project_id" data-meeting-project><option value="">No project (non-project activity)</option><?php foreach ($projectOptions as $pid => $pn): ?><option value="<?= (int) $pid ?>"><?= e($pn) ?></option><?php endforeach ?></select><div class="hint">Entries with a project also show on that project's Client log.</div></div><?php endif ?>
      </div>
      <div class="field"><label for="a-body">Details</label><textarea id="a-body" name="body" rows="4" maxlength="5000" placeholder="What was discussed, decisions, next steps…"></textarea></div>
      <label class="flex"><span class="switch"><input type="checkbox" name="is_internal" value="1"><span></span></span><span><b class="small">Internal only</b><span class="xs muted">, team-only context, never shared with the client</span></span></label>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save entry</button></div>
    </div>
  </form>
</dialog>
