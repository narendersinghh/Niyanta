<?php
use Modules\Ai\Controllers\ActionController;
$t = ActionController::TYPES[$a['type']] ?? [$a['type'], 'zap', 'lav'];
$data = $a['data'];
$base = strtotime($a['created_at']);
$old = Core\Session::getFlash('old', []);
?>
<?= partial('partials/page_head', ['title' => $a['title'], 'subtitle' => $t[0].' · suggested '.fdatetime($a['created_at']), 'crumbs' => [['AI Assistant', '/ai'], ['Actions', '/ai/actions'], ['#'.$a['id']]], 'actions' => badge($a['status'] === 'executed' ? 'completed' : $a['status'], $a['status'] === 'executed' ? 'Done' : null)]) ?>
<?php if ($a['type'] === 'create_tasks'): ?>
<div class="grid g-side">
  <div>
    <?php if ($a['status'] === 'pending' && $canApprove): ?>
    <form class="card" method="post" action="<?= e(url('/ai/actions/'.$a['id'].'/approve')) ?>" data-confirm="Create the selected tasks in <?= e($data['project'] ?? 'this project') ?>?"><?= csrf_field() ?>
      <div class="card-head"><div><h3>Review suggested tasks</h3><p class="small muted mb0">Edit anything, untick what you don't want, then approve. Nothing is created until you do.</p></div><span class="pill pill-lav" data-ai-count></span></div>
      <?= error_for('tasks') ?>
      <div class="flex mb small"><a href="#" data-ai-all="1">Select all</a><span class="muted">·</span><a href="#" data-ai-all="0">Select none</a></div>
      <?php foreach ($data['tasks'] ?? [] as $i => $tk): $o = $old['tasks'][$i] ?? null; $due = $tk['due_in_days'] !== null ? date('Y-m-d', $base + 86400 * (int) $tk['due_in_days']) : ''; ?>
      <div class="ai-task">
        <label class="check"><input type="checkbox" name="tasks[<?= (int) $i ?>][include]" value="1" data-ai-include<?= checked($o === null || !empty($o['include'])) ?> aria-label="Include task <?= (int) $i + 1 ?>"></label>
        <div class="stack">
          <div class="row">
            <div class="field"><label class="lbl">Title</label><input type="text" name="tasks[<?= (int) $i ?>][title]" maxlength="190" value="<?= e($o['title'] ?? $tk['title']) ?>"></div>
            <div class="field"><label class="lbl">Priority</label><select name="tasks[<?= (int) $i ?>][priority]"><?php foreach (['low', 'medium', 'high', 'urgent'] as $pr): ?><option value="<?= $pr ?>"<?= selected($o['priority'] ?? $tk['priority'], $pr) ?>><?= ucfirst($pr) ?></option><?php endforeach ?></select></div>
            <div class="field"><label class="lbl">Due date</label><input type="date" name="tasks[<?= (int) $i ?>][due_date]" value="<?= e($o['due_date'] ?? $due) ?>"></div>
          </div>
          <div class="field"><label class="lbl">Description</label><textarea name="tasks[<?= (int) $i ?>][description]" maxlength="5000" rows="2"><?= e($o['description'] ?? $tk['description']) ?></textarea></div>
        </div>
      </div>
      <?php endforeach ?>
      <div class="form-actions mt"><button class="btn primary" type="submit" data-ai-approve><?= icon('check', 'sm') ?> Approve &amp; create tasks</button></div>
    </form>
    <form method="post" action="<?= e(url('/ai/actions/'.$a['id'].'/reject')) ?>" class="mt flex" data-confirm="Reject these suggestions? Nothing will be created."><?= csrf_field() ?><button class="btn danger" type="submit"><?= icon('x', 'sm') ?> Reject suggestion</button><span class="small muted">Nothing will be created.</span></form>
    <?php else: ?>
    <div class="card">
      <div class="card-head"><h3><?= $a['status'] === 'executed' ? 'Created tasks' : 'Suggested tasks' ?></h3><?= badge($a['status'] === 'executed' ? 'completed' : $a['status'], $a['status'] === 'executed' ? 'Done' : null) ?></div>
      <?php if ($a['status'] === 'pending'): ?><div class="alert warn"><?= icon('lock', 'sm') ?> You can no longer create tasks in this project, so this suggestion can only be rejected.</div><?php endif ?>
      <?php if ($a['result']): ?><div class="alert <?= $a['status'] === 'failed' ? 'error' : 'info' ?>"><?= icon('info', 'sm') ?> <?= e($a['result']) ?></div><?php endif ?>
      <div class="list"><?php foreach ($data['approved_tasks'] ?? $data['tasks'] ?? [] as $tk): ?>
        <div class="list-item"><span class="notif-ic soft-lemon"><?= icon('check-square', 'sm') ?></span><div class="grow"><b><?= e($tk['title']) ?></b><?php if (!empty($tk['description'])): ?><div class="meta"><?= e(str_limit($tk['description'], 160)) ?></div><?php endif ?></div><?= badge($tk['priority']) ?><span class="xs muted nowrap"><?= e(isset($tk['due_date']) ? fdate($tk['due_date']) : ($tk['due_in_days'] !== null ? 'in '.(int) $tk['due_in_days'].'d' : '')) ?></span></div>
      <?php endforeach ?></div>
      <?php if ($a['status'] === 'executed' && !empty($data['project_id'])): ?><a class="btn ghost mt" href="<?= e(url('/projects/'.(int) $data['project_id'])) ?>"><?= icon('folder', 'sm') ?> Open project</a><?php endif ?>
      <?php if ($a['status'] === 'pending'): ?><form method="post" action="<?= e(url('/ai/actions/'.$a['id'].'/reject')) ?>" class="mt"><?= csrf_field() ?><button class="btn danger" type="submit"><?= icon('x', 'sm') ?> Reject suggestion</button></form><?php endif ?>
    </div>
    <?php endif ?>
  </div>
  <div class="stack">
    <div class="card"><div class="card-head"><h4>Project</h4></div><div class="flex"><span class="notif-ic soft-lav"><?= icon('folder', 'sm') ?></span><b><?= e($data['project'] ?? '-') ?></b></div></div>
    <div class="card"><div class="card-head"><h4>Source text</h4></div><div class="ai-out ai-src"><?= e($data['source'] ?? '') ?></div><p class="xs muted mt mb0">Suggested by <?= e($data['model'] ?? 'AI') ?>. Review before approving.</p></div>
  </div>
</div>
<?php else: ?>
<div class="card">
  <dl class="kv">
    <dt>Type</dt><dd><?= e($t[0]) ?></dd>
    <dt>Status</dt><dd><?= badge($a['status'] === 'executed' ? 'completed' : $a['status'], $a['status'] === 'executed' ? 'Done' : null) ?></dd>
    <?php if ($a['result']): ?><dt>Result</dt><dd><?= e($a['result']) ?></dd><?php endif ?>
    <?php if (!empty($data['subject'])): ?><dt>Subject</dt><dd><?= e($data['subject']) ?></dd><?php endif ?>
    <?php if (!empty($data['to'])): ?><dt>Recipient</dt><dd><?= e($data['to']) ?></dd><?php endif ?>
    <dt>Decided</dt><dd><?= e(fdatetime($a['decided_at'])) ?></dd>
  </dl>
  <div class="flex mt">
    <?php if (!empty($data['client_id'])): ?><a class="btn ghost" href="<?= e(url('/clients/'.(int) $data['client_id'])) ?>"><?= icon('briefcase', 'sm') ?> Open client</a><?php endif ?>
    <?php if (!empty($data['invoice_id'])): ?><a class="btn ghost" href="<?= e(url('/invoices/'.(int) $data['invoice_id'])) ?>"><?= icon('receipt', 'sm') ?> Open invoice</a><?php endif ?>
  </div>
</div>
<?php endif ?>
