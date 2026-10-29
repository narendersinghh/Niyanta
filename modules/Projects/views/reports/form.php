<?php
use Modules\Projects\Services\ProjectService as PS;
$id = $r['id']; $v = static fn (string $k, $d = '') => old($k, $r[$k] ?? $d);
$type = $v('type', 'daily');
$time = PS::on('time');
$sec = static fn (string $ic, string $color, string $title, string $help) => '<div class="fsec-head ac-'.$color.'"><span class="sec-ic">'.icon($ic).'</span><div><h3>'.e($title).'</h3><p>'.e($help).'</p></div></div>';
$rejected = $id && $r['status'] === 'rejected';
?>
<div class="pj">
<?= partial('partials/page_head', ['title' => $id ? 'Edit work report' : 'New work report', 'subtitle' => $rejected ? 'Update the report and resubmit it for review.' : 'Share progress, wins and blockers with your manager.', 'crumbs' => [['Work'], ['Work reports', '/work-reports'], [$id ? 'Edit' : 'New report']]]) ?>
<?php if ($rejected && $r['review_note']): ?><div class="alert error"><?= icon('alert', 'sm') ?><div><b>Reviewer note:</b> <?= e($r['review_note']) ?></div></div><?php endif ?>
<form method="post" action="<?= e(url($id ? '/work-reports/'.$id : '/work-reports')) ?>" data-report-form="<?= $id ? 'edit' : 'new' ?>" data-time="<?= $time ? 1 : 0 ?>"><?= csrf_field() ?>
  <div class="grid g-side">
    <div class="stack">
      <section class="card form">
        <?= $sec('calendar', 'sky', 'Period', 'Pick the day, or any day in the week for a weekly report.') ?>
        <div class="form-grid g3">
          <div class="field"><label>Report type</label>
            <div class="type-seg" role="radiogroup" aria-label="Report type"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly'] as $k => $l): ?><label><input type="radio" name="type" value="<?= $k ?>"<?= checked($type === $k) ?> class="sr"><?= icon($k === 'daily' ? 'clock' : 'calendar', 'sm') ?> <?= $l ?></label><?php endforeach ?></div></div>
          <div class="field<?= error_for('period_start') ? ' has-error' : '' ?>"><label for="r-date"><span data-date-label><?= $type === 'weekly' ? 'Week of' : 'Date' ?></span> *</label><input id="r-date" type="date" name="period_start" value="<?= e($v('period_start', date('Y-m-d'))) ?>" max="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required><div class="hint" data-period-label><?= e(fdate($r['period_start'])) ?><?= $r['period_end'] !== $r['period_start'] ? ' – '.e(fdate($r['period_end'])) : '' ?></div><?= error_for('period_start') ?></div>
          <div class="field<?= error_for('hours') ? ' has-error' : '' ?>"><label for="r-hours">Hours worked</label><input id="r-hours" type="number" name="hours" step="0.25" min="0" max="168" value="<?= e($v('hours')) ?>" data-hours placeholder="e.g. 8"><div class="hint" data-hours-hint><?= $time ? 'Prefilled from your time entries.' : 'Optional.' ?></div><?= error_for('hours') ?></div>
          <?php if (feature_on('projects')): ?><div class="field full<?= error_for('project_id') ? ' has-error' : '' ?>"><label for="r-project">Project <span class="muted">(optional)</span></label><select id="r-project" name="project_id"><option value="">General / multiple projects</option><?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $v('project_id')) ?>><?= e($pn) ?></option><?php endforeach ?></select><?= error_for('project_id') ?></div><?php endif ?>
        </div>
      </section>
      <section class="card form">
        <?= $sec('edit', 'lav', 'Update', 'Outcomes over activities, one item per line in the lists.') ?>
        <div class="field<?= error_for('summary') ? ' has-error' : '' ?>"><label for="r-summary">Summary *</label><textarea id="r-summary" name="summary" rows="3" required maxlength="5000" placeholder="A short overview of the day or week"><?= e($v('summary')) ?></textarea><?= error_for('summary') ?></div>
        <div class="field"><label for="r-acc">Accomplishments</label><textarea id="r-acc" name="accomplishments" rows="4" placeholder="What you completed, one item per line"><?= e($v('accomplishments')) ?></textarea></div>
        <div class="form-grid">
          <div class="field"><label for="r-block">Blockers</label><textarea id="r-block" name="blockers" rows="3" placeholder="Anything slowing you down?"><?= e($v('blockers')) ?></textarea><div class="hint">Your manager sees these first.</div></div>
          <div class="field"><label for="r-next">Next steps</label><textarea id="r-next" name="next_steps" rows="3" placeholder="What's planned next"><?= e($v('next_steps')) ?></textarea></div>
        </div>
      </section>
    </div>
    <aside class="stack">
      <section class="card side-tip"><?= illus('reports-chart') ?><h4>Good reports are short</h4><p class="small muted mb0">List outcomes rather than activities, flag blockers early and keep next steps concrete. Your reporting manager is notified when you submit.</p></section>
    </aside>
  </div>
  <div class="form-bar">
    <span class="hint">Summary and date are required.</span>
    <a class="btn ghost" href="<?= e(url($id ? '/work-reports/'.$id : '/work-reports')) ?>">Cancel</a>
    <button class="btn primary"><?= icon('send', 'sm') ?> <?= $id ? ($rejected ? 'Resubmit' : 'Save changes') : 'Submit report' ?></button>
  </div>
</form>
</div>
