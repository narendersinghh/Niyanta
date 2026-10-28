<?php
use Core\Modules;
use Modules\Projects\Services\ProjectService as PS;
$id = (int) $pr['id'];
$total = array_sum($sc); $done = (int) ($sc['done'] ?? 0);
$late = $pr['deadline'] && $pr['deadline'] < date('Y-m-d') && !in_array($pr['status'], ['completed', 'cancelled'], true);
$daysLeft = $pr['deadline'] ? (int) floor((strtotime($pr['deadline']) - strtotime(date('Y-m-d'))) / 86400) : null;
$tabs = ['overview' => ['Overview', 'grid'], 'board' => ['Board', 'kanban'], 'list' => ['List', 'list'], 'milestones' => ['Milestones', 'flag'], 'changes' => ['Change requests', 'refresh'], 'files' => ['Files', 'paperclip']];
$vars = get_defined_vars();
?>
<div class="crumbs"><a href="<?= e(url('/projects')) ?>">Projects</a><span>/</span><span><?= e($pr['name']) ?></span></div>
<section class="card phero ac-<?= e($pr['color']) ?> mb">
  <div class="phero-main">
    <div class="flex wrap"><?= badge($pr['status'], PS::STATUSES[$pr['status']]) ?><?= badge($pr['priority'], PS::PRIORITIES[$pr['priority']].' priority') ?><?php if ($pr['code']): ?><span class="pill pill-gray mono"><?= e($pr['code']) ?></span><?php endif ?></div>
    <h1 class="mt"><?= e($pr['name']) ?></h1>
    <div class="flex wrap small phero-meta">
      <span><?= icon('briefcase', 'sm') ?> <?php if ($pr['client_id'] && Modules::enabled('crm') && can('crm.clients.view')): ?><a href="<?= e(url('/clients/'.$pr['client_id'])) ?>"><?= e($pr['client_name']) ?></a><?php else: ?><?= e($pr['client_name'] ?: 'Internal project') ?><?php endif ?></span>
      <span><?= icon('calendar', 'sm') ?> <?= e(fdate($pr['start_date'])) ?> → <span class="<?= $late ? 'late-text' : '' ?>"><?= e(fdate($pr['deadline'])) ?></span></span>
      <?php if ($pr['manager_name']): ?><span class="flex"><?= avatar($pr['manager_name'], $pr['manager_avatar'], 'sm') ?> <?= e($pr['manager_name']) ?></span><?php endif ?>
    </div>
  </div>
  <div class="phero-side">
    <div class="ring lg" style="--v:<?= (int) $pr['progress'] ?>" data-label="<?= (int) $pr['progress'] ?>%"></div>
    <div class="phero-stats">
      <div><b><?= $done ?>/<?= $total ?></b><span>tasks done</span></div>
      <div><b class="<?= $late ? 'late-text' : '' ?>"><?= $daysLeft === null ? '—' : ($daysLeft < 0 ? abs($daysLeft).'d late' : $daysLeft.'d') ?></b><span><?= $daysLeft !== null && $daysLeft < 0 ? 'overdue' : 'left' ?></span></div>
      <div class="avatars"><?php foreach (array_slice($team, 0, 5) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?><?php if (count($team) > 5): ?><span class="avatar sm more">+<?= count($team) - 5 ?></span><?php endif ?></div>
    </div>
    <?php if ($canManage || $canDelete): ?>
    <details class="dd"><summary class="btn icon ghost" aria-label="Project actions"><?= icon('more') ?></summary><div class="dd-menu">
      <?php if ($canManage): ?><a href="<?= e(url('/projects/'.$id.'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit project</a><?php endif ?>
      <?php if ($canDelete): ?><form method="post" action="<?= e(url('/projects/'.$id.'/delete')) ?>" data-confirm="Delete this project with all its tasks, milestones and files? This cannot be undone."><?= csrf_field() ?><button class="danger-text"><?= icon('trash', 'sm') ?> Delete project</button></form><?php endif ?>
    </div></details>
    <?php endif ?>
  </div>
</section>
<div class="flex between wrap mb">
  <nav class="tabs" aria-label="Project sections"><?php foreach ($tabs as $k => [$l, $ic]): ?><a href="<?= e(url('/projects/'.$id.'?tab='.$k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= icon($ic, 'sm') ?> <?= e($l) ?></a><?php endforeach ?></nav>
  <?php if ($canTasks && in_array($tab, ['board', 'list', 'overview'], true)): ?><button type="button" class="btn primary" data-modal="task-new" data-status="todo"><?= icon('plus', 'sm') ?> Add task</button><?php endif ?>
</div>
<?= partial('projects::projects/tabs/'.$tab, $vars) ?>
<?php if ($canTasks && in_array($tab, ['board', 'list', 'overview'], true)): ?>
<?= partial('projects::projects/task_modal', ['id' => $id, 'assignees' => $assignees, 'milestoneOpts' => $milestoneOpts]) ?>
<?php endif ?>
