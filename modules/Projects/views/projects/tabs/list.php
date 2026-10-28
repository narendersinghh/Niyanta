<?php
use Modules\Projects\Services\ProjectService as PS;
$th = static function (string $key, string $label) use ($sort, $dir): string {
  $next = $sort === $key && $dir === 'ASC' ? 'desc' : 'asc';
  $arrow = $sort === $key ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '';
  return '<th><a class="sort'.($sort === $key ? ' on' : '').'" href="'.e(query_merge(['sort' => $key, 'dir' => $next, 'page' => 1])).'">'.e($label).$arrow.'</a></th>'; };
?>
<div class="card">
  <form class="filters" method="get">
    <input type="hidden" name="tab" value="list">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search tasks">
    <select name="assignee" data-autosubmit><option value="">Anyone</option><option value="none"<?= selected('none', $f['assignee']) ?>>Unassigned</option><?php foreach ($members as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $f['assignee']) ?>><?= e($un) ?></option><?php endforeach ?></select>
    <select name="status" data-autosubmit><option value="">Any status</option><option value="open"<?= selected('open', $f['status']) ?>>Open</option><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $f['status']) ?>><?= e($l) ?></option><?php endforeach ?></select>
    <select name="priority" data-autosubmit><option value="">Any priority</option><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $f['priority']) ?>><?= e($l) ?></option><?php endforeach ?></select>
    <select name="due" data-autosubmit><option value="">Any due date</option><option value="overdue"<?= selected('overdue', $f['due']) ?>>Overdue</option><option value="week"<?= selected('week', $f['due']) ?>>Due this week</option><option value="none"<?= selected('none', $f['due']) ?>>No due date</option></select>
    <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e(strtolower($dir)) ?>">
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <?php if (array_filter($f)): ?><a class="btn sm soft" href="<?= e(url('/projects/'.$pr['id'].'?tab=list')) ?>">Clear</a><?php endif ?>
  </form>
  <?php if (!$tp['items']): ?><?= empty_state('No tasks found', array_filter($f) ? 'Try other filters.' : 'Tasks you add will be listed here.', 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><?= $th('title', 'Task') ?><?= $th('assignee', 'Assignee') ?><?= $th('priority', 'Priority') ?><?= $th('due', 'Due') ?><th>Est.</th><?= $th('status', 'Status') ?></tr></thead>
    <tbody><?php foreach ($tp['items'] as $t): ?>
      <tr>
        <td><a class="row-link" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a><div class="xs muted"><?= $t['milestone'] ? icon('flag', 'sm').' '.e($t['milestone']) : '' ?><?= $t['subs'] ? ' · '.(int) $t['subs'].' subtasks' : '' ?><?= $t['billable'] ? '' : ' · non-billable' ?></div></td>
        <td><?php if ($t['assignee']): ?><span class="flex nowrap"><?= avatar($t['assignee'], $t['avatar'], 'sm') ?> <?= e($t['assignee']) ?></span><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td><?= badge($t['priority'], PS::PRIORITIES[$t['priority']]) ?></td>
        <td class="nowrap"><?= PS::dueChip($t['due_date'], $t['status']) ?></td>
        <td class="small"><?= $t['estimated_hours'] !== null ? e((float) $t['estimated_hours']).'h' : '—' ?></td>
        <td><?php if ($t['editable']): ?>
          <form method="post" action="<?= e(url('/tasks/'.$t['id'].'/status')) ?>" class="status-form"><?= csrf_field() ?><?= PS::returnField() ?><select name="status" data-autosubmit class="status-select st-<?= e($t['status']) ?>" aria-label="Status"><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['status']) ?>><?= e($l) ?></option><?php endforeach ?></select></form>
        <?php else: ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($tp) ?>
  <?php endif ?>
</div>
