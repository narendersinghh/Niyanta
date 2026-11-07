<?php
use Modules\Hr\Services\ProcessService as PS;
$hr = PS::isHr(); $today = date('Y-m-d');
$tabs = ['processes' => ['All processes', 'clipboard', null], 'review' => ['Files to review', 'eye', $counts['review']], 'requests' => ['Deletion requests', 'trash', $counts['requests']], 'due' => ['Due soon', 'clock', $counts['due']]];
$actions = $hr ? '<a class="btn ghost" href="'.e(url('/hr/process-templates')).'">'.icon('layers', 'sm').' Templates</a><a class="btn primary" href="'.e(url('/hr/employees')).'">'.icon('user-plus', 'sm').' Start for an employee</a>' : '';
$ret = '/hr/processes?view='.$view;
?>
<?= partial('partials/page_head', ['title' => 'Onboarding & exit', 'subtitle' => $hr ? 'Every joiner and leaver: documents, agreements, reviews and handover in one place.' : 'Onboarding and exit of your direct reports.', 'crumbs' => [[$hr ? 'HR Admin' : 'Team'], ['Onboarding & exit']], 'actions' => $actions]) ?>
<?= partial('hr::partials/emp_tabs', ['active' => 'processes']) ?>
<nav class="tabs mb" aria-label="Views"><?php foreach ($tabs as $k => [$l, $ic, $n]): ?><a class="<?= $view === $k ? 'active' : '' ?>" href="<?= e(url('/hr/processes?view='.$k)) ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?><?= $n ? ' <span class="pill pill-lemon">'.(int) $n.'</span>' : '' ?></a><?php endforeach ?></nav>
<?php if ($view === 'processes'): ?>
<div class="grid g4 mb">
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('flag') ?></span><div><div class="num"><?= (int) ($kpi['onb'] ?? 0) ?></div><div class="lbl">Onboarding in progress</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('log-out') ?></span><div><div class="num"><?= (int) ($kpi['ex'] ?? 0) ?></div><div class="lbl">Exits in progress</div></div></div>
  <a class="tile bg-lemon" href="<?= e(url('/hr/processes?view=review')) ?>"><span class="tile-ic"><?= icon('eye') ?></span><div><div class="num"><?= (int) $counts['review'] ?></div><div class="lbl">Files to review</div></div></a>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($kpi['done30'] ?? 0) ?></div><div class="lbl">Completed in 30 days</div></div></div>
</div>
<div class="card mb"><form class="hr-toolbar mb0" method="get"><input type="hidden" name="view" value="processes">
  <div class="chips"><?php foreach (['' => 'Both', 'onboarding' => 'Onboarding', 'exit' => 'Exit'] as $k => $l): ?><a class="chip<?= $kind === $k ? ' on' : '' ?>" href="<?= e(url('/hr/processes?'.http_build_query(array_filter(['kind' => $k, 'status' => $status])))) ?>"><?= e($l) ?></a><?php endforeach ?></div>
  <label class="hr-push small" for="pf-s">Status</label><select id="pf-s" name="status" data-autosubmit><option value="open"<?= selected('open', $status) ?>>In progress or paused</option><?php foreach (PS::STATUS as $k => [$l]): ?><option value="<?= e($k) ?>"<?= selected($k, $status) ?>><?= e($l) ?></option><?php endforeach ?><option value="all"<?= selected('all', $status) ?>>All</option></select><?php if ($kind): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif ?>
</form></div>
<?php if (!$p['items']): ?><div class="card"><?= empty_state('Nothing here yet', $hr ? 'Open an employee and start their onboarding or exit from the Onboarding & exit tab.' : 'None of your direct reports is onboarding or leaving right now.', 'rocket', $hr ? '<a class="btn primary" href="'.e(url('/hr/employees')).'">Go to employees</a>' : '') ?></div>
<?php else: ?>
<div class="card pad0"><div class="table-wrap"><table class="table">
  <thead><tr><th>Employee</th><th>Process</th><th>Key date</th><th>Progress</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($p['items'] as $r): $pc = (int) $r['total'] ? (int) round($r['done'] / $r['total'] * 100) : 0; ?>
    <tr><td><div class="flex"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b class="small"><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['designation'] ?: '') ?></div></div></div></td>
      <td><span class="pill pill-<?= $r['kind'] === 'exit' ? 'peach' : 'mint' ?>"><?= e(PS::KINDS[$r['kind']]) ?></span><div class="xs muted"><?= e($r['template_name'] ?: '') ?></div></td>
      <td class="small"><?= $r['kind'] === 'exit' ? 'Last day '.e(fdate($r['last_working_day'])) : 'Started '.e(fdate($r['start_date'])) ?></td>
      <td class="hr-prog"><div class="flex"><div class="progress grow" role="progressbar" aria-valuenow="<?= $pc ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pc ?>%"></span></div><span class="xs b"><?= (int) $r['done'] ?>/<?= (int) $r['total'] ?></span></div><?= (int) $r['waiting'] ? '<div class="xs muted">'.(int) $r['waiting'].' waiting for review</div>' : '' ?></td>
      <td><span class="pill pill-<?= e(PS::STATUS[$r['status']][1]) ?>"><?= e(PS::STATUS[$r['status']][0]) ?></span></td>
      <td><a class="btn sm ghost" href="<?= e(url('/hr/processes/'.$r['id'])) ?>">Open <?= icon('chevron-right', 'sm') ?></a></td></tr>
  <?php endforeach ?></tbody></table></div></div>
<?= pager($p) ?>
<?php endif ?>
<?php elseif ($view === 'review'): ?>
<?php if (!$rows): ?><div class="card"><?= empty_state('All reviewed', 'No uploaded files are waiting for a decision.', 'celebrate') ?></div><?php else: ?>
<div class="card"><div class="hr-ups"><?php foreach ($rows as $r): ?>
  <div class="hr-rev-row"><div class="flex"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div class="grow small"><b><?= e($r['name']) ?></b> · <?= $r['task_id'] ? '<a href="'.e(url('/hr/processes/'.$r['process_id'].'#task-'.$r['task_id'])).'">'.e($r['task_title']).'</a> <span class="xs muted">('.e(PS::KINDS[$r['kind']] ?? '').')</span>' : 'Signed: '.e($r['doc_title'] ?? 'company document') ?></div></div>
  <?= partial('hr::processes/_upload', ['up' => $r + ['uploader' => $r['name'], 'reviewer' => null, 'size' => null], 'req' => null, 'return' => $ret]) ?></div>
<?php endforeach ?></div></div><?php endif ?>
<?php elseif ($view === 'requests'): ?>
<?php if (!$rows): ?><div class="card"><?= empty_state('No deletion requests', 'When an employee asks to remove a file they uploaded, it shows up here.', 'empty-box') ?></div><?php else: ?>
<div class="card"><div class="list"><?php foreach ($rows as $r): ?>
  <div class="list-item hr-req"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div class="grow small"><b><?= e($r['name']) ?></b> wants to delete <b><?= $r['file_id'] ? '<a href="'.e(url('/files/'.$r['file_id'].'/view')).'" target="_blank" rel="noopener">'.e($r['file_name']).'</a>' : e($r['file_name']) ?></b><?= $r['task_title'] ? ' from <a href="'.e(url('/hr/processes/'.$r['process_id'].'#task-'.$r['task_id'])).'">'.e($r['task_title']).'</a>' : '' ?>
    <div class="hr-up-note warn"><?= icon('message', 'sm') ?> <?= e($r['reason']) ?></div><div class="xs muted">Asked <?= e(ago($r['created_at'])) ?><?= $r['uploaded_at'] ? ' · uploaded '.e(fdatetime($r['uploaded_at'])) : '' ?></div></div>
    <form method="post" action="<?= e(url('/hr/delete-requests/'.$r['id'].'/decide')) ?>" data-confirm="Delete <?= e($r['file_name']) ?>? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($ret) ?>"><input type="hidden" name="decision" value="approve"><button class="btn sm danger"><?= icon('trash', 'sm') ?> Delete</button></form>
    <details class="hr-pop"><summary class="btn sm ghost">Keep</summary><form class="hr-pop-body" method="post" action="<?= e(url('/hr/delete-requests/'.$r['id'].'/decide')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($ret) ?>"><input type="hidden" name="decision" value="reject"><label class="small b" for="kn-<?= (int) $r['id'] ?>">Note to the employee</label><textarea id="kn-<?= (int) $r['id'] ?>" name="note" rows="2" maxlength="500"></textarea><button class="btn sm primary">Keep the file</button></form></details></div>
<?php endforeach ?></div></div><?php endif ?>
<?php else: ?>
<?php if (!$rows): ?><div class="card"><?= empty_state('Nothing due', 'No open task is due in the next three days.', 'celebrate') ?></div><?php else: ?>
<div class="card pad0"><div class="table-wrap"><table class="table"><thead><tr><th>Task</th><th>Employee</th><th>Owner</th><th>Due</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $t): $late = $t['due_date'] < $today; ?><tr><td><a class="b small" href="<?= e(url('/hr/processes/'.$t['process_id'].'#task-'.$t['id'])) ?>"><?= e($t['title']) ?></a><div class="xs muted"><?= e(PS::KINDS[$t['kind']]) ?></div></td><td class="small"><?= e($t['name']) ?></td><td><span class="pill pill-sky"><?= e(PS::OWNERS[$t['owner']]) ?></span></td><td class="small<?= $late ? ' hr-late' : '' ?>"><?= e(fdate($t['due_date'])) ?></td><td><span class="pill pill-<?= e(PS::TASK_STATUS[$t['status']][1]) ?>"><?= e(PS::TASK_STATUS[$t['status']][0]) ?></span></td></tr><?php endforeach ?>
</tbody></table></div></div><?php endif ?>
<?php endif ?>
