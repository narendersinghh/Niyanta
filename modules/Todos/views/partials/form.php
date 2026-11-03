<?php
/* Edit dialog for a to-do (mode todo) or routine (mode series); same fields, order and labels as the New task dialog (views/partials/task_fields.php). $id dialog id, $heading, $mode todo|series, $action, $v values, $assignees [id => name], $return, $open (bool), $submit label; optional $project (name, routine of a project), $pid, $milestones, $showProject, $repeatText (to-do). */
$err = $open ? errors() : [];
$f = static fn (string $k) => $open && isset($err[$k]) ? '<div class="field-error">'.e($err[$k]).'</div>' : '';
$p = e($id);
$pid = (int) ($pid ?? 0);
$todo = $mode === 'todo';
$statuses = $todo ? Modules\Projects\Services\ProjectService::TASK_STATUSES : Modules\Todos\Services\Todos::STAGES;
?>
<dialog class="modal tk-dialog" id="<?= $p ?>" aria-labelledby="<?= $p ?>-t"<?= $open ? ' data-open' : '' ?>>
  <div class="modal-head"><div><h3 id="<?= $p ?>-t"><?= e($heading) ?></h3><?php if (!$todo): ?><p class="xs muted mb0">Changes apply to upcoming tasks, earlier ones stay as they are. Each repeat starts as To do.</p><?php endif ?></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form td-form tk-form" method="post" action="<?= e(url($action)) ?>" data-repeat="<?= e($todo ? 'once' : (string) ($v['repeat'] ?? 'daily')) ?>"><?= csrf_field() ?><input type="hidden" name="_form" value="<?= $p ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
    <?= partial('partials/task_fields', ['p' => $p, 'v' => $v, 'f' => $f, 'projectName' => $project ?? null, 'showProject' => $showProject ?? true, 'pid' => $pid, 'people' => $assignees, 'unassigned' => (bool) $pid, 'milestones' => $milestones ?? [],
      'statuses' => $statuses, 'repeat' => $todo ? 'fixed' : 'series', 'repeatText' => $repeatText ?? 'Once', 'dueLock' => $todo ? (!empty($v['occurrence']) ? 'Part of a routine, due on its day.' : null) : 'Each repeat is due on its own day.', 'offHint' => 'Only for project tasks', 'descMax' => $pid ? 20000 : 5000]) ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> <?= e($submit) ?></button></div>
  </form>
</dialog>
