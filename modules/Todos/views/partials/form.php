<?php
use Modules\Todos\Services\Todos as T;
/* Edit dialog for a task (mode todo) or routine (mode series); same field order and labels as the New task dialog (views/partials/task_dialog.php). $id dialog id, $heading, $mode todo|series, $action, $v values, $assignees [id => name], $return, $open (bool), $submit label */
$err = $open ? errors() : [];
$f = static fn (string $k) => $open && isset($err[$k]) ? '<div class="field-error">'.e($err[$k]).'</div>' : '';
$p = e($id);
?>
<dialog class="modal" id="<?= $p ?>" aria-labelledby="<?= $p ?>-t"<?= $open ? ' data-open' : '' ?>>
  <div class="modal-head"><div><h3 id="<?= $p ?>-t"><?= e($heading) ?></h3><?php if ($mode === 'series'): ?><p class="xs muted mb0">Changes apply to upcoming tasks, earlier ones stay as they are.</p><?php endif ?></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form td-form<?= $mode === 'todo' ? ' td-fixed' : '' ?>" method="post" action="<?= e(url($action)) ?>" data-repeat="<?= e($mode === 'todo' ? 'once' : (string) ($v['repeat'] ?? 'daily')) ?>"><?= csrf_field() ?><input type="hidden" name="_form" value="<?= $p ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
    <div class="field"><label for="<?= $p ?>-title">Title *</label><input id="<?= $p ?>-title" type="text" name="title" required maxlength="190" value="<?= e($v['title'] ?? '') ?>" placeholder="What needs to be done?" autofocus><?= $f('title') ?></div>
    <div class="field"><label for="<?= $p ?>-desc">Description</label><textarea id="<?= $p ?>-desc" name="description" rows="3" maxlength="5000" placeholder="Details, links, checklist…"><?= e($v['description'] ?? '') ?></textarea><?= $f('description') ?></div>
    <div class="form-grid">
      <?php if (count($assignees) > 1): ?><div class="field"><label for="<?= $p ?>-who">Assign to</label><select id="<?= $p ?>-who" name="assignee_id"><?php foreach ($assignees as $aid => $an): ?><option value="<?= (int) $aid ?>"<?= selected($aid, $v['assignee_id'] ?? '') ?>><?= e($an) ?></option><?php endforeach ?></select><?= $f('assignee_id') ?></div>
      <?php else: ?><input type="hidden" name="assignee_id" value="<?= (int) array_key_first($assignees) ?>"><?php endif ?>
      <div class="field"><label for="<?= $p ?>-prio">Priority</label><select id="<?= $p ?>-prio" name="priority"><?php foreach (T::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v['priority'] ?? 'medium') ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    </div>
    <?= partial('todos::partials/repeat', ['p' => $p, 'v' => $v, 'mode' => $mode, 'f' => $f]) ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> <?= e($submit) ?></button></div>
  </form>
</dialog>
