<?php
use Modules\Projects\Services\ProjectService as PS;
$id = (int) $pr['id'];
$total = array_sum($sc); $done = (int) ($sc['done'] ?? 0);
[$cd, $tone] = PS::countdown($pr['deadline'], $pr['status']);
$initials = mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($pr['name'])) ?: [], 0, 2))));
$canUpload = can('projects.manage') || can('projects.tasks.manage') || can('projects.tasks.update_own');
$tabs = ['overview' => ['Overview', 'grid', null], 'board' => ['Board', 'kanban', $total], 'list' => ['List', 'list', null], 'milestones' => ['Milestones', 'flag', $nums['ms']], 'changes' => ['Change requests', 'refresh', $nums['cr']], 'files' => ['Files', 'paperclip', $nums['files']]] + ($logView ? ['log' => ['Client log', 'activity', $nums['log']]] : []);
$taskTab = in_array($tab, ['board', 'list', 'overview'], true);
$primary = match (true) {
  $taskTab && $canTasks => '<button type="button" class="btn primary" data-modal="task-new" data-status="todo">'.icon('plus', 'sm').' Add task</button>',
  $tab === 'milestones' && $canManage => '<button type="button" class="btn primary" data-modal="ms-new">'.icon('plus', 'sm').' Add milestone</button>',
  $tab === 'changes' && $canCr => '<button type="button" class="btn primary" data-modal="cr-new">'.icon('plus', 'sm').' Log change request</button>',
  $tab === 'files' && $canUpload => '<button type="button" class="btn primary" data-modal="file-new">'.icon('upload', 'sm').' Upload file</button>',
  $tab === 'log' && $logWrite => '<button type="button" class="btn primary" data-modal="activity-modal" data-activity-new>'.icon('plus', 'sm').' Log activity</button>',
  default => '' };
$more = ($canManage ? '<a href="'.e(url('/projects/'.$id.'/edit')).'">'.icon('edit', 'sm').' Edit project</a>' : '')
  .($logWrite && $tab !== 'log' ? '<button type="button" data-modal="activity-modal" data-activity-new>'.icon('activity', 'sm').' Log activity</button>' : '')
  .($canTasks && !$taskTab ? '<a href="'.e(url('/projects/'.$id.'?tab=board')).'">'.icon('kanban', 'sm').' Open board</a>' : '')
  .(PS::on('report_submit') ? '<a href="'.e(url('/work-reports/create?project='.$id)).'">'.icon('clipboard', 'sm').' Write work report</a>' : '')
  .($canDelete ? '<div class="sep"></div><form method="post" action="'.e(url('/projects/'.$id.'/delete')).'" data-confirm="Delete this project with all its tasks, milestones and files? This cannot be undone.">'.csrf_field().'<button class="danger-text">'.icon('trash', 'sm').' Delete project</button></form>' : '');
$vars = get_defined_vars();
?>
<div class="pj">
<header class="phead ac-<?= e($pr['color']) ?>">
  <div class="phead-main">
    <span class="phead-ic" aria-hidden="true"><?= e($initials) ?></span>
    <div class="grow">
      <nav class="crumbs" aria-label="Breadcrumb"><span>Work</span><span>/</span><a href="<?= e(url('/projects')) ?>">Projects</a><span>/</span><span><?= e(str_limit($pr['name'], 40)) ?></span></nav>
      <h1><?= e($pr['name']) ?></h1>
      <div class="phead-meta">
        <?= badge($pr['status'], PS::STATUSES[$pr['status']]) ?>
        <?php if (in_array($pr['priority'], ['high', 'urgent'], true)): ?><?= badge($pr['priority'], PS::PRIORITIES[$pr['priority']].' priority') ?><?php endif ?>
        <?php if ($pr['code']): ?><span class="mono"><?= e($pr['code']) ?></span><?php endif ?>
        <span><?= icon('briefcase', 'sm') ?> <?= PS::client($pr['client_id'] ? (int) $pr['client_id'] : null, $pr['client_name']) ?></span>
        <span><?= icon('calendar', 'sm') ?> <?= e(fdate($pr['start_date'], 'd M')) ?> – <?= e(fdate($pr['deadline'])) ?> <span class="pill pill-<?= e($tone) ?>"><?= e($cd) ?></span></span>
        <?php if ($pr['manager_name']): ?><span title="Project manager"><?= avatar($pr['manager_name'], $pr['manager_avatar'], 'sm') ?> <?= e($pr['manager_name']) ?></span><?php endif ?>
        <span class="mini-prog" title="<?= $done ?> of <?= $total ?> tasks done"><span class="progress"><span style="width:<?= (int) $pr['progress'] ?>%"></span></span><b><?= (int) $pr['progress'] ?>%</b></span>
      </div>
    </div>
  </div>
  <?php $mtBtn = $logWrite && class_exists(\Modules\Meetings\Services\Ui::class) ? \Modules\Meetings\Services\Ui::newButton(['client_id' => $pr['client_id'] ? (int) $pr['client_id'] : null, 'project_id' => $id], $primary ? 'btn soft' : 'btn primary') : ''; ?>
  <?php if ($primary || $more || $mtBtn): ?>
  <div class="head-actions">
    <?= $mtBtn ?><?= $primary ?>
    <?php if ($more): ?><details class="dd"><summary class="btn ghost more-btn" aria-label="More project actions" title="More actions"><?= icon('more') ?></summary><div class="dd-menu"><?= $more ?></div></details><?php endif ?>
  </div>
  <?php endif ?>
</header>
<div class="tabs-wrap"><nav class="tabs" aria-label="Project sections"><?php foreach ($tabs as $k => [$l, $ic, $n]): ?><a href="<?= e(url('/projects/'.$id.'?tab='.$k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?><?php if ($n): ?><span class="tab-n<?= $k === 'changes' ? ' hot' : '' ?>"><?= (int) $n ?></span><?php endif ?></a><?php endforeach ?></nav></div>
<?= partial('projects::projects/tabs/'.$tab, $vars) ?>
<?php if ($logWrite): ?><div class="crm"><?= partial('crm::partials/activity_modal', ['action' => '/projects/'.$id.'/activities', 'fixedProject' => ['id' => $id, 'name' => $pr['name']], 'clientId' => $pr['client_id'] ? (int) $pr['client_id'] : null]) ?></div><?= class_exists(\Modules\Meetings\Services\Ui::class) ? \Modules\Meetings\Services\Ui::newDialog(['client_id' => $pr['client_id'] ? (int) $pr['client_id'] : null, 'project_id' => $id, 'return' => 'project']) : '' ?><?php endif ?>
<?php if ($canTasks && $taskTab): ?>
<?= partial('partials/task_dialog', ['project' => $id, 'return' => '/projects/'.$id.'?'.(($q = (string) ($_SERVER['QUERY_STRING'] ?? '')) !== '' ? $q : 'tab='.$tab)]) ?>
<?php endif ?>
</div>
