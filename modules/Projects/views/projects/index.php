<?php
use Modules\Projects\Services\ProjectService as PS;
$f = ['status' => $_GET['status'] ?? '', 'client' => $_GET['client'] ?? '', 'q' => $_GET['q'] ?? ''];
$total = array_sum($stats);
$actions = (can('projects.manage') ? '<a class="btn primary" href="'.e(url('/projects/create')).'">'.icon('plus', 'sm').' New project</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Projects', 'subtitle' => 'Every engagement, its team, timeline and progress in one place.', 'crumbs' => [['Work'], ['Projects']], 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <a class="tile bg-mint" href="<?= e(url('/projects?status=active')) ?>"><span class="tile-ic"><?= icon('zap') ?></span><div><div class="num"><?= (int) ($stats['active'] ?? 0) ?></div><div class="lbl">Active</div></div></a>
  <a class="tile bg-lav" href="<?= e(url('/projects?status=planning')) ?>"><span class="tile-ic"><?= icon('target') ?></span><div><div class="num"><?= (int) ($stats['planning'] ?? 0) + (int) ($stats['on_hold'] ?? 0) ?></div><div class="lbl">Planning &amp; on hold</div></div></a>
  <a class="tile bg-sky" href="<?= e(url('/projects?status=completed')) ?>"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($stats['completed'] ?? 0) ?></div><div class="lbl">Completed</div></div></a>
  <div class="tile dark"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) $overdue ?></div><div class="lbl">Past deadline</div></div><?= illus('rocket', 'corner') ?></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="hidden" name="view" value="<?= e($viewMode) ?>">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, code or client">
    <select name="status" data-autosubmit><option value="">All statuses</option><option value="open"<?= selected('open', $f['status']) ?>>Open (not closed)</option><?php foreach (PS::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $f['status']) ?>><?= e($l) ?></option><?php endforeach ?></select>
    <?php if ($clients): ?><select name="client" data-autosubmit><option value="">All clients</option><?php foreach ($clients as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($id, $f['client']) ?>><?= e($n) ?></option><?php endforeach ?></select><?php endif ?>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <div class="tabs view-toggle" role="group" aria-label="Layout">
      <a href="<?= e(query_merge(['view' => 'cards', 'page' => 1])) ?>" class="<?= $viewMode === 'cards' ? 'active' : '' ?>" title="Cards"><?= icon('grid', 'sm') ?></a>
      <a href="<?= e(query_merge(['view' => 'table', 'page' => 1])) ?>" class="<?= $viewMode === 'table' ? 'active' : '' ?>" title="Table"><?= icon('list', 'sm') ?></a>
    </div>
  </form>
  <?php if (!$p['items']): ?>
    <?= empty_state($total ? 'No projects match' : 'No projects yet', $total ? 'Try clearing the filters.' : (can('projects.manage') ? 'Kick things off by creating your first project.' : 'You will see projects here once you are added to a team.'), 'kanban-board', !$total && can('projects.manage') ? '<a class="btn primary mt" href="'.e(url('/projects/create')).'">'.icon('plus', 'sm').' New project</a>' : '') ?>
  <?php elseif ($viewMode === 'cards'): ?>
  <div class="pgrid">
    <?php foreach ($p['items'] as $pr): $c = $counts[$pr['id']] ?? ['total' => 0, 'done' => 0]; $ms = $members[$pr['id']] ?? []; $late = $pr['deadline'] && $pr['deadline'] < date('Y-m-d') && !in_array($pr['status'], ['completed', 'cancelled'], true); ?>
    <a class="pcard ac-<?= e($pr['color']) ?>" href="<?= e(url('/projects/'.$pr['id'])) ?>">
      <div class="pc-top">
        <div class="grow">
          <div class="flex"><?= badge($pr['status'], PS::STATUSES[$pr['status']]) ?><?php if (in_array($pr['priority'], ['high', 'urgent'], true)): ?><?= badge($pr['priority'], PS::PRIORITIES[$pr['priority']]) ?><?php endif ?></div>
          <h3 class="pc-title"><?= e($pr['name']) ?></h3>
          <div class="xs pc-sub"><?= e($pr['client_name'] ?: 'Internal project') ?><?= $pr['code'] ? ' · '.e($pr['code']) : '' ?></div>
        </div>
        <div class="ring" style="--v:<?= (int) $pr['progress'] ?>" data-label="<?= (int) $pr['progress'] ?>%"></div>
      </div>
      <div class="pc-body">
        <div class="flex between small"><span class="muted"><?= icon('check-square', 'sm') ?> <?= (int) $c['done'] ?>/<?= (int) $c['total'] ?> tasks</span><span class="due<?= $late ? ' late' : '' ?>"><?= icon('calendar', 'sm') ?> <?= e($pr['deadline'] ? fdate($pr['deadline'], 'd M Y') : 'No deadline') ?></span></div>
        <div class="progress mt"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div>
        <div class="flex between mt">
          <div class="avatars"><?php foreach (array_slice($ms, 0, 4) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?><?php if (count($ms) > 4): ?><span class="avatar sm more">+<?= count($ms) - 4 ?></span><?php endif ?><?php if (!$ms): ?><span class="xs muted">No members yet</span><?php endif ?></div>
          <?php if ($pr['manager_name']): ?><span class="xs muted flex" title="Project manager"><?= icon('user', 'sm') ?> <?= e($pr['manager_name']) ?></span><?php endif ?>
        </div>
      </div>
    </a>
    <?php endforeach ?>
  </div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Project</th><th>Client</th><th>Manager</th><th>Team</th><th>Progress</th><th>Deadline</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($p['items'] as $pr): $ms = $members[$pr['id']] ?? []; $late = $pr['deadline'] && $pr['deadline'] < date('Y-m-d') && !in_array($pr['status'], ['completed', 'cancelled'], true); ?>
      <tr>
        <td><a class="row-link flex" href="<?= e(url('/projects/'.$pr['id'])) ?>"><span class="dotc bg-<?= e($pr['color']) ?>"></span><span><?= e($pr['name']) ?><?php if ($pr['code']): ?><div class="xs muted"><?= e($pr['code']) ?></div><?php endif ?></span></a></td>
        <td><?= e($pr['client_name'] ?? '—') ?></td>
        <td><?= e($pr['manager_name'] ?? '—') ?></td>
        <td><div class="avatars"><?php foreach (array_slice($ms, 0, 4) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?><?php if (count($ms) > 4): ?><span class="avatar sm more">+<?= count($ms) - 4 ?></span><?php endif ?></div></td>
        <td style="min-width:140px"><div class="flex"><div class="progress grow"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div><span class="xs b"><?= (int) $pr['progress'] ?>%</span></div></td>
        <td class="nowrap"><span class="due<?= $late ? ' late' : '' ?>"><?= e(fdate($pr['deadline'])) ?></span></td>
        <td><?= badge($pr['status'], PS::STATUSES[$pr['status']]) ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?php endif ?>
  <?= pager($p) ?>
</div>
