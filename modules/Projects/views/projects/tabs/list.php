<?php
use Modules\Projects\Services\ProjectService as PS;
$th = static function (string $key, string $label, string $cls = '') use ($sort, $dir): string {
  $next = $sort === $key && $dir === 'ASC' ? 'desc' : 'asc';
  $arrow = $sort === $key ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '';
  return '<th'.($cls ? ' class="'.$cls.'"' : '').($sort === $key ? ' aria-sort="'.($dir === 'ASC' ? 'ascending' : 'descending').'"' : '').'><a class="sort'.($sort === $key ? ' on' : '').'" href="'.e(query_merge(['sort' => $key, 'dir' => $next, 'page' => 1])).'">'.e($label).$arrow.'</a></th>'; };
?>
<form class="toolbar" method="get" role="search">
  <input type="hidden" name="tab" value="list"><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e(strtolower($dir)) ?>">
  <label class="search-in"><?= icon('search') ?><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search tasks" aria-label="Search tasks"></label>
  <select name="assignee" data-autosubmit aria-label="Assignee"><option value="">Anyone</option><option value="none"<?= selected('none', $f['assignee']) ?>>Unassigned</option><?php foreach ($members as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $f['assignee']) ?>><?= e($un) ?></option><?php endforeach ?></select>
  <select name="status" data-autosubmit aria-label="Status"><option value="">Any status</option><option value="open"<?= selected('open', $f['status']) ?>>Open</option><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $f['status']) ?>><?= e($l) ?></option><?php endforeach ?></select>
  <select name="priority" data-autosubmit aria-label="Priority"><option value="">Any priority</option><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $f['priority']) ?>><?= e($l) ?></option><?php endforeach ?></select>
  <select name="due" data-autosubmit aria-label="Due date"><option value="">Any due date</option><option value="overdue"<?= selected('overdue', $f['due']) ?>>Overdue</option><option value="week"<?= selected('week', $f['due']) ?>>Due this week</option><option value="none"<?= selected('none', $f['due']) ?>>No due date</option></select>
  <?php if (array_filter($f)): ?><a class="clear" href="<?= e(url('/projects/'.$pr['id'].'?tab=list')) ?>">Clear filters</a><?php endif ?>
  <noscript><button class="btn ghost sm">Apply</button></noscript>
  <span class="xs muted ml-a"><?= (int) $tp['total'] ?> task<?= $tp['total'] == 1 ? '' : 's' ?></span>
</form>
<div class="card">
  <?php if (!$tp['items']): ?><?= empty_state(array_filter($f) ? 'No tasks match' : 'No tasks yet', array_filter($f) ? 'Try other filters or clear them.' : ($canTasks ? 'Add the first task to start planning the work.' : 'Tasks will be listed here once they are created.'), 'empty-box', array_filter($f) ? '<a class="btn ghost mt" href="'.e(url('/projects/'.$pr['id'].'?tab=list')).'">Clear filters</a>' : ($canTasks ? '<button type="button" class="btn primary mt" data-modal="task-new" data-status="todo">'.icon('plus', 'sm').' Add task</button>' : '')) ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><?= $th('title', 'Task') ?><?= $th('assignee', 'Assignee') ?><?= $th('priority', 'Priority') ?><?= $th('due', 'Due') ?><th class="num-r">Est.</th><?= $th('status', 'Status') ?></tr></thead>
    <tbody><?php foreach ($tp['items'] as $t): $meta = array_filter([$t['milestone'] ? icon('flag', 'sm').' '.e($t['milestone']) : '', $t['subs'] ? (int) $t['subs'].' subtask'.($t['subs'] > 1 ? 's' : '') : '', $t['billable'] ? '' : 'Non-billable']); ?>
      <tr>
        <td><div class="task-cell p-<?= e($t['priority']) ?>"><span class="bar"></span><div><a class="row-link" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a><?php if ($meta): ?><div class="xs muted"><?= implode(' · ', $meta) ?></div><?php endif ?></div></div></td>
        <td><?php if ($t['assignee']): ?><span class="tcell"><?= avatar($t['assignee'], $t['avatar'], 'sm') ?> <?= e($t['assignee']) ?></span><?php else: ?><span class="muted">Unassigned</span><?php endif ?></td>
        <td><?= badge($t['priority'], PS::PRIORITIES[$t['priority']]) ?></td>
        <td class="nowrap"><?= PS::dueChip($t['due_date'], $t['status']) ?></td>
        <td class="num-r"><?= $t['estimated_hours'] !== null ? e((float) $t['estimated_hours']).'h' : '<span class="muted">-</span>' ?></td>
        <td><?php if ($t['editable']): ?>
          <form method="post" action="<?= e(url('/tasks/'.$t['id'].'/status')) ?>" class="status-form"><?= csrf_field() ?><?= PS::returnField() ?><select name="status" data-autosubmit class="status-select st-<?= e($t['status']) ?>" aria-label="Status of <?= e($t['title']) ?>"><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['status']) ?>><?= e($l) ?></option><?php endforeach ?></select></form>
        <?php else: ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($tp) ?>
  <?php endif ?>
</div>
