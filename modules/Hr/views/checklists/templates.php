<?php
use Modules\Hr\Services\ProcessService as PS;
$typeIc = ['upload' => 'upload', 'sign' => 'edit', 'ack' => 'book', 'task' => 'check-square', 'provide' => 'download'];
$by = ['onboarding' => [], 'exit' => []]; foreach ($all as $t) { $by[$t['kind']][] = $t; }
$taskForm = static function (?array $t, string $kind) use ($companyDocs): string { ob_start(); $f = 'tt'.($t['id'] ?? 'new'); ?>
  <div class="form-grid">
    <div class="field full"><label for="<?= $f ?>-t">Title *</label><input id="<?= $f ?>-t" name="title" maxlength="190" value="<?= e($t['title'] ?? '') ?>" required placeholder="e.g. Submit passport photo"></div>
    <div class="field"><label for="<?= $f ?>-y">Type</label><select id="<?= $f ?>-y" name="task_type"><?php foreach (PS::TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['task_type'] ?? 'upload') ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="<?= $f ?>-o">Owner</label><select id="<?= $f ?>-o" name="owner"><?php foreach (PS::OWNERS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['owner'] ?? 'employee') ?>><?= e($l) ?></option><?php endforeach ?></select><div class="hint">Uploads, signatures and acknowledgements always belong to the employee; HR files to HR.</div></div>
    <div class="field"><label for="<?= $f ?>-d">Due (days)</label><input id="<?= $f ?>-d" type="number" name="due_days" min="-365" max="365" value="<?= (int) ($t['due_days'] ?? 0) ?>" required><div class="hint"><?= $kind === 'exit' ? 'From the last working day; negative = before it.' : 'From the start date.' ?></div></div>
    <div class="field"><label for="<?= $f ?>-c">Company document</label><select id="<?= $f ?>-c" name="company_doc_id"><option value="">None</option><?php foreach ($companyDocs as $k => $l): ?><option value="<?= (int) $k ?>"<?= selected($k, $t['company_doc_id'] ?? '') ?>><?= e($l) ?></option><?php endforeach ?></select><div class="hint">Sign: the employee downloads it, signs and uploads the copy. Acknowledge: they confirm reading it.</div></div>
    <div class="field full"><label for="<?= $f ?>-s">Instructions</label><textarea id="<?= $f ?>-s" name="description" rows="2" maxlength="1000"><?= e($t['description'] ?? '') ?></textarea></div>
  </div>
<?php return (string) ob_get_clean(); };
?>
<?= partial('partials/page_head', ['title' => 'Onboarding & exit templates', 'subtitle' => 'Tasks copied to an employee when you start their onboarding or exit. Started processes keep their own copy.', 'crumbs' => [['HR Admin'], ['Onboarding & exit', '/hr/processes'], ['Templates']], 'actions' => '<button type="button" class="btn primary" data-modal="tpl-new">'.icon('plus', 'sm').' New template</button>']) ?>
<div class="grid g-side hr-top">
  <aside>
    <?php foreach (['onboarding' => 'Onboarding', 'exit' => 'Exit'] as $k => $l): ?>
    <div class="card mb"><h3 class="small"><?= icon($k === 'exit' ? 'log-out' : 'flag', 'sm') ?> <?= e($l) ?></h3><div class="list">
      <?php foreach ($by[$k] as $t): ?><a class="list-item<?= $tpl && (int) $tpl['id'] === (int) $t['id'] ? ' on' : '' ?>" href="<?= e(url('/hr/process-templates?id='.$t['id'])) ?>"<?= $tpl && (int) $tpl['id'] === (int) $t['id'] ? ' aria-current="page"' : '' ?>><div class="grow small"><b><?= e($t['name']) ?></b><div class="xs muted"><?= (int) $t['tasks'] ?> tasks</div></div><?php if ((int) $t['is_default']): ?><span class="pill pill-mint">Default</span><?php endif ?></a><?php endforeach ?>
      <?php if (!$by[$k]): ?><p class="xs muted">No template yet.</p><?php endif ?>
    </div></div>
    <?php endforeach ?>
  </aside>
  <div>
  <?php if (!$tpl): ?><div class="card"><?= empty_state('No templates', 'Create an onboarding or exit template to get started.', 'empty-box') ?></div><?php else: $tid = (int) $tpl['id']; ?>
    <form class="card form mb" method="post" action="<?= e(url('/hr/process-templates/'.$tid)) ?>"><?= csrf_field() ?>
      <div class="card-head mb0"><h3><?= e($tpl['name']) ?></h3><span class="pill pill-<?= $tpl['kind'] === 'exit' ? 'peach' : 'mint' ?>"><?= e(PS::KINDS[$tpl['kind']]) ?></span></div>
      <div class="form-grid">
        <div class="field"><label for="tp-n">Name *</label><input id="tp-n" name="name" maxlength="150" value="<?= e($tpl['name']) ?>" required></div>
        <div class="field"><label for="tp-d">Description</label><input id="tp-d" name="description" maxlength="500" value="<?= e($tpl['description']) ?>"></div>
        <label class="check small full"><input type="checkbox" name="is_default" value="1"<?= checked((int) $tpl['is_default']) ?><?= (int) $tpl['is_default'] ? ' disabled' : '' ?>> Default <?= e(strtolower(PS::KINDS[$tpl['kind']])) ?> template (preselected when starting)</label><?php if ((int) $tpl['is_default']): ?><input type="hidden" name="is_default" value="1"><?php endif ?>
      </div>
      <div class="form-actions"><button class="btn primary sm"><?= icon('check', 'sm') ?> Save</button></div>
    </form>
    <?php if (!(int) $tpl['is_default']): ?><form class="mb" method="post" action="<?= e(url('/hr/process-templates/'.$tid.'/delete')) ?>" data-confirm="Delete the template “<?= e($tpl['name']) ?>”? Processes already started keep their tasks."><?= csrf_field() ?><button class="btn sm ghost hr-neg"><?= icon('trash', 'sm') ?> Delete template</button></form><?php endif ?>
    <div class="card">
      <div class="card-head"><h3>Tasks</h3><span class="pill pill-lav"><?= count($tasks) ?></span></div>
      <div class="hr-tpl-tasks">
      <?php foreach ($tasks as $i => $t): $id = (int) $t['id']; ?>
        <div class="hr-tpl-task" id="tt-<?= $id ?>">
          <span class="hr-task-ic soft-lav" aria-hidden="true"><?= icon($typeIc[$t['task_type']], 'sm') ?></span>
          <div class="grow"><b class="small"><?= e($t['title']) ?></b><div class="hr-task-meta"><span><?= e(PS::TYPES[$t['task_type']]) ?></span><span class="pill pill-sky"><?= e(PS::OWNERS[$t['owner']]) ?></span><span><?= icon('calendar', 'sm') ?> <?= (int) $t['due_days'] === 0 ? ($tpl['kind'] === 'exit' ? 'On the last day' : 'On the start date') : (abs((int) $t['due_days']).' day'.(abs((int) $t['due_days']) === 1 ? '' : 's').((int) $t['due_days'] < 0 ? ' before' : ' after')) ?></span><?php if ($t['doc_title']): ?><span><?= icon('book', 'sm') ?> <?= e($t['doc_title']) ?></span><?php endif ?></div></div>
          <form method="post" action="<?= e(url('/hr/template-tasks/'.$id.'/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn icon sm ghost" title="Move up" aria-label="Move up"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-down', 'sm hr-flip') ?></button></form>
          <form method="post" action="<?= e(url('/hr/template-tasks/'.$id.'/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn icon sm ghost" title="Move down" aria-label="Move down"<?= $i === count($tasks) - 1 ? ' disabled' : '' ?>><?= icon('chevron-down', 'sm') ?></button></form>
          <button type="button" class="btn icon sm ghost" data-modal="tte-<?= $id ?>" title="Edit" aria-label="Edit task"><?= icon('edit', 'sm') ?></button>
          <form method="post" action="<?= e(url('/hr/template-tasks/'.$id.'/delete')) ?>" data-confirm="Remove “<?= e($t['title']) ?>” from the template?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Remove" aria-label="Remove task"><?= icon('trash', 'sm') ?></button></form>
        </div>
        <dialog class="modal" id="tte-<?= $id ?>"><div class="modal-head"><h3>Edit template task</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
          <form class="modal-body form" method="post" action="<?= e(url('/hr/template-tasks/'.$id)) ?>"><?= csrf_field() ?><?= $taskForm($t, $tpl['kind']) ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save</button></div></form></dialog>
      <?php endforeach ?>
      <?php if (!$tasks): ?><p class="small muted">No tasks yet. Add the first one below.</p><?php endif ?>
      </div>
      <details class="mt" id="add"><summary class="btn ghost sm"><?= icon('plus', 'sm') ?> Add task</summary>
        <form class="form mt" method="post" action="<?= e(url('/hr/process-templates/'.$tid.'/tasks')) ?>"><?= csrf_field() ?><?= $taskForm(null, $tpl['kind']) ?><div class="form-actions"><button class="btn primary"><?= icon('plus', 'sm') ?> Add to template</button></div></form>
      </details>
    </div>
  <?php endif ?>
  </div>
</div>
<dialog class="modal" id="tpl-new"><div class="modal-head"><h3>New template</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/process-templates')) ?>"><?= csrf_field() ?>
    <div class="field"><label for="nt-k">For</label><select id="nt-k" name="kind"><option value="onboarding">Onboarding</option><option value="exit">Exit</option></select></div>
    <div class="field"><label for="nt-n">Name *</label><input id="nt-n" name="name" maxlength="150" required placeholder="e.g. Intern onboarding"></div>
    <div class="field"><label for="nt-d">Description</label><input id="nt-d" name="description" maxlength="500"></div>
    <div class="field"><label for="nt-c">Copy tasks from</label><select id="nt-c" name="copy_from"><option value="">Start empty</option><?php foreach ($all as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e(PS::KINDS[$t['kind']].': '.$t['name']) ?></option><?php endforeach ?></select><div class="hint">Only a template of the same kind is copied.</div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Create</button></div></form></dialog>
