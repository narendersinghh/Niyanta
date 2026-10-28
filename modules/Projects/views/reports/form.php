<?php
$id = $r['id']; $v = static fn (string $k, $d = '') => old($k, $r[$k] ?? $d);
$type = $v('type', 'daily');
?>
<?= partial('partials/page_head', ['title' => $id ? 'Edit work report' : 'New work report', 'subtitle' => $id && $r['status'] === 'rejected' ? 'Update the report and resubmit it for review.' : 'Share progress, wins and blockers with your manager.', 'crumbs' => [['Work reports', '/work-reports'], [$id ? 'Edit' : 'New']]]) ?>
<?php if ($id && $r['status'] === 'rejected' && $r['review_note']): ?><div class="alert error"><?= icon('alert', 'sm') ?><div><b>Reviewer note:</b> <?= e($r['review_note']) ?></div></div><?php endif ?>
<form class="grid g-side" method="post" action="<?= e(url($id ? '/work-reports/'.$id : '/work-reports')) ?>" data-report-form="<?= $id ? 'edit' : 'new' ?>"><?= csrf_field() ?>
  <div class="card form">
    <div class="form-grid g3">
      <div class="field"><label>Report type</label>
        <div class="seg type-seg"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly'] as $k => $l): ?><label class="seg-btn<?= $type === $k ? ' on' : '' ?>"><input type="radio" name="type" value="<?= $k ?>"<?= checked($type === $k) ?> class="sr"><?= $l ?></label><?php endforeach ?></div></div>
      <div class="field"><label><span data-date-label><?= $type === 'weekly' ? 'Week of' : 'Date' ?></span> *</label><input type="date" name="period_start" value="<?= e($v('period_start', date('Y-m-d'))) ?>" max="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required><div class="hint" data-period-label><?= e(fdate($r['period_start'])) ?><?= $r['period_end'] !== $r['period_start'] ? ' – '.e(fdate($r['period_end'])) : '' ?></div><?= error_for('period_start') ?></div>
      <div class="field"><label>Hours worked</label><input type="number" name="hours" step="0.25" min="0" max="168" value="<?= e($v('hours')) ?>" data-hours><div class="hint" data-hours-hint>Prefilled from your time entries.</div><?= error_for('hours') ?></div>
      <div class="field full"><label>Project <span class="muted">(optional)</span></label><select name="project_id"><option value="">General / multiple projects</option><?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $v('project_id')) ?>><?= e($pn) ?></option><?php endforeach ?></select><?= error_for('project_id') ?></div>
      <div class="field full"><label>Summary *</label><textarea name="summary" rows="3" required maxlength="5000" placeholder="A short overview of the day/week"><?= e($v('summary')) ?></textarea><?= error_for('summary') ?></div>
      <div class="field full"><label><?= icon('check-circle', 'sm') ?> Accomplishments</label><textarea name="accomplishments" rows="4" placeholder="What you completed — one item per line"><?= e($v('accomplishments')) ?></textarea></div>
      <div class="field full"><label><?= icon('alert', 'sm') ?> Blockers</label><textarea name="blockers" rows="3" placeholder="Anything slowing you down?"><?= e($v('blockers')) ?></textarea></div>
      <div class="field full"><label><?= icon('arrow-right', 'sm') ?> Next steps</label><textarea name="next_steps" rows="3" placeholder="What's planned next"><?= e($v('next_steps')) ?></textarea></div>
    </div>
    <div class="form-actions"><a class="btn ghost" href="<?= e(url($id ? '/work-reports/'.$id : '/work-reports')) ?>">Cancel</a><button class="btn primary"><?= icon('send', 'sm') ?> <?= $id ? ($r['status'] === 'rejected' ? 'Resubmit' : 'Save changes') : 'Submit report' ?></button></div>
  </div>
  <div class="stack">
    <div class="card soft-mint side-tip"><?= illus('clock-worker') ?><h4 class="mt">Good reports are short</h4><p class="small mb0">List outcomes rather than activities, flag blockers early and keep next steps concrete. Your reporting manager is notified when you submit.</p></div>
  </div>
</form>
