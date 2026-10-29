<?php
use Modules\Projects\Services\ProjectService as PS;
$f = ['status' => (string) ($_GET['status'] ?? ''), 'client' => (string) ($_GET['client'] ?? ''), 'q' => (string) ($_GET['q'] ?? '')];
$total = array_sum($stats);
$manage = can('projects.manage');
$today = date('Y-m-d');
$actions = $manage ? '<a class="btn primary" href="'.e(url('/projects/create')).'">'.icon('plus', 'sm').' New project</a>' : '';
$initials = static fn (string $n) => mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
/** [used, budget, pct] or null — cost = billable hours × hourly rate. */
$budget = static function (array $pr) use ($sec): ?array {
  $b = (float) $pr['budget']; $rate = (float) $pr['hourly_rate'];
  if ($b <= 0 || !$rate || !PS::on('time')) { return null; }
  $used = ($sec[$pr['id']]['billable'] ?? 0) / 3600 * $rate;
  return [$used, $b, (int) round($used / $b * 100)]; };
$showClient = PS::on('crm') || $clients;
?>
<div class="pj">
<?= partial('partials/page_head', ['title' => 'Projects', 'subtitle' => 'Every engagement with its team, timeline, budget and progress.', 'crumbs' => [['Work'], ['Projects']], 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <a class="tile bg-mint" href="<?= e(url('/projects?status=active')) ?>"><span class="tile-ic"><?= icon('zap') ?></span><div><div class="num"><?= (int) ($stats['active'] ?? 0) ?></div><div class="lbl">Active projects</div></div></a>
  <a class="tile bg-lav" href="<?= e(url('/projects?status=planning')) ?>"><span class="tile-ic"><?= icon('target') ?></span><div><div class="num"><?= (int) ($stats['planning'] ?? 0) + (int) ($stats['on_hold'] ?? 0) ?></div><div class="lbl">Planning &amp; on hold</div></div></a>
  <a class="tile bg-sky" href="<?= e(url('/projects?status=completed')) ?>"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($stats['completed'] ?? 0) ?></div><div class="lbl">Completed</div></div></a>
  <a class="tile bg-peach" href="<?= e(url('/projects?status=open')) ?>"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) $overdue ?></div><div class="lbl">Past deadline</div></div></a>
</div>
<form class="toolbar" method="get" role="search">
  <input type="hidden" name="view" value="<?= e($viewMode) ?>">
  <label class="search-in"><?= icon('search') ?><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, code or client" aria-label="Search projects"></label>
  <select name="status" data-autosubmit aria-label="Status"><option value="">All statuses</option><option value="open"<?= selected('open', $f['status']) ?>>Open (not closed)</option><?php foreach (PS::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $f['status']) ?>><?= e($l) ?></option><?php endforeach ?></select>
  <?php if ($clients): ?><select name="client" data-autosubmit aria-label="Client"><option value="">All clients</option><?php foreach ($clients as $cid => $n): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $f['client']) ?>><?= e($n) ?></option><?php endforeach ?></select><?php endif ?>
  <?php if (array_filter($f)): ?><a class="clear" href="<?= e(url('/projects?view='.$viewMode)) ?>">Clear filters</a><?php endif ?>
  <noscript><button class="btn ghost sm">Apply</button></noscript>
  <nav class="tabs view-toggle ml-a" aria-label="Layout">
    <a href="<?= e(query_merge(['view' => 'cards', 'page' => 1])) ?>" class="<?= $viewMode === 'cards' ? 'active' : '' ?>" title="Card view" aria-label="Card view"><?= icon('grid', 'sm') ?></a>
    <a href="<?= e(query_merge(['view' => 'table', 'page' => 1])) ?>" class="<?= $viewMode === 'table' ? 'active' : '' ?>" title="Table view" aria-label="Table view"><?= icon('list', 'sm') ?></a>
  </nav>
</form>
<?php if (!$p['items']): ?>
  <div class="card"><?= empty_state($total ? 'No projects match these filters' : 'No projects yet', $total ? 'Try a different search or clear the filters to see everything.' : ($manage ? 'Create your first project to plan milestones, assign tasks and track progress.' : 'Projects you are added to will show up here.'), $total ? 'empty-box' : 'rocket', $total ? '<a class="btn ghost mt" href="'.e(url('/projects')).'">Clear filters</a>' : ($manage ? '<a class="btn primary mt" href="'.e(url('/projects/create')).'">'.icon('plus', 'sm').' New project</a>' : '')) ?></div>
<?php elseif ($viewMode === 'cards'): ?>
<div class="pgrid">
  <?php foreach ($p['items'] as $pr): $c = $counts[$pr['id']] ?? ['total' => 0, 'done' => 0]; $ms = $members[$pr['id']] ?? []; [$cd, $tone] = PS::countdown($pr['deadline'], $pr['status']); $bu = $budget($pr); ?>
  <article class="pcard ac-<?= e($pr['color']) ?>">
    <div class="pc-head">
      <span class="p-ic"><?= e($initials($pr['name'])) ?></span>
      <div class="grow">
        <h3 class="pc-title"><a href="<?= e(url('/projects/'.$pr['id'])) ?>"><?= e($pr['name']) ?></a></h3>
        <div class="pc-sub"><?= PS::client($pr['client_id'] ? (int) $pr['client_id'] : null, $pr['client_name']) ?><?= $pr['code'] ? ' · <span class="mono">'.e($pr['code']).'</span>' : '' ?></div>
      </div>
      <div class="ring pring" style="--v:<?= (int) $pr['progress'] ?>" data-label="<?= (int) $pr['progress'] ?>%" role="img" aria-label="<?= (int) $pr['progress'] ?>% complete"></div>
    </div>
    <div class="pc-pills"><?= badge($pr['status'], PS::STATUSES[$pr['status']]) ?><?php if (in_array($pr['priority'], ['high', 'urgent'], true)): ?><?= badge($pr['priority'], PS::PRIORITIES[$pr['priority']].' priority') ?><?php endif ?></div>
    <div class="pc-stats">
      <div class="pc-stat"><span>Tasks done</span><b><?= (int) $c['done'] ?> / <?= (int) $c['total'] ?></b></div>
      <div class="pc-stat"><span><?= $pr['deadline'] ? e(fdate($pr['deadline'], 'd M Y')) : 'Deadline' ?></span><b class="<?= e($tone) ?>"><?= e($cd) ?></b></div>
    </div>
    <div class="pc-budget">
      <?php if ($bu): ?>
      <div class="flex between"><span class="muted">Budget used</span><span><b><?= e(money($bu[0])) ?></b> <span class="muted">of <?= e(money($bu[1])) ?></span></span></div>
      <div class="progress<?= $bu[2] >= 100 ? ' over' : ($bu[2] >= 80 ? ' warn' : '') ?>" title="<?= $bu[2] ?>% of budget"><span style="width:<?= min(100, $bu[2]) ?>%"></span></div>
      <?php elseif ((float) $pr['budget'] > 0): ?>
      <div class="flex between"><span class="muted">Budget</span><b><?= e(money($pr['budget'])) ?></b></div>
      <div class="progress" title="Progress"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div>
      <?php else: ?>
      <div class="flex between"><span class="muted">Progress</span><b><?= (int) $pr['progress'] ?>%</b></div>
      <div class="progress"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div>
      <?php endif ?>
    </div>
    <div class="pc-foot">
      <?= PS::stack($ms, 4, 'No members yet') ?>
      <?php if ($pr['manager_name']): ?><span class="mgr" title="Project manager"><?= avatar($pr['manager_name'], $pr['manager_avatar'], 'sm') ?><span class="truncate"><?= e($pr['manager_name']) ?></span></span><?php endif ?>
    </div>
  </article>
  <?php endforeach ?>
</div>
<div class="mt"><?= pager($p) ?></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap"><table class="table ptable">
    <thead><tr><th>Project</th><?php if ($showClient): ?><th>Client</th><?php endif ?><th>Manager</th><th>Team</th><th class="num-r">Tasks</th><th>Progress</th><th class="num-r">Budget</th><th>Deadline</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($p['items'] as $pr): $c = $counts[$pr['id']] ?? ['total' => 0, 'done' => 0]; $ms = $members[$pr['id']] ?? []; [$cd, $tone] = PS::countdown($pr['deadline'], $pr['status']); $bu = $budget($pr); ?>
      <tr class="ac-<?= e($pr['color']) ?>">
        <td class="wrap"><a class="row-link pname" href="<?= e(url('/projects/'.$pr['id'])) ?>"><span class="p-ic"><?= e($initials($pr['name'])) ?></span><span><?= e($pr['name']) ?><?php if ($pr['code']): ?><span class="xs muted mono db"><?= e($pr['code']) ?></span><?php endif ?></span></a></td>
        <?php if ($showClient): ?><td><?= PS::client($pr['client_id'] ? (int) $pr['client_id'] : null, $pr['client_name'], '—') ?></td><?php endif ?>
        <td><?php if ($pr['manager_name']): ?><span class="tcell"><?= avatar($pr['manager_name'], $pr['manager_avatar'], 'sm') ?> <?= e($pr['manager_name']) ?></span><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td><?= PS::stack($ms, 3, '—') ?></td>
        <td class="num-r"><?= (int) $c['done'] ?>/<?= (int) $c['total'] ?></td>
        <td><div class="mini-prog"><div class="progress"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div><b><?= (int) $pr['progress'] ?>%</b></div></td>
        <td class="num-r"><?php if ($bu): ?><?= e(money($bu[1])) ?><div class="xs <?= $bu[2] >= 100 ? 'late-text' : 'muted' ?>"><?= $bu[2] ?>% used</div><?php elseif ((float) $pr['budget'] > 0): ?><?= e(money($pr['budget'])) ?><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td><?php if ($pr['deadline']): ?><div class="small"><?= e(fdate($pr['deadline'])) ?></div><?php endif ?><span class="pill pill-<?= e($tone) ?>"><?= e($cd) ?></span></td>
        <td><?= badge($pr['status'], PS::STATUSES[$pr['status']]) ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
</div>
<?php endif ?>
</div>
