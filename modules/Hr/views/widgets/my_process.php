<?php use Modules\Hr\Services\ProcessService as PS; $today = date('Y-m-d'); ?>
<?php foreach ($procs as $p): [$done, $total, $pct] = $p['progress']; $exit = $p['kind'] === 'exit'; ?>
<div class="card hr-my-proc <?= $exit ? 'exit' : 'onb' ?>">
  <div class="card-head"><h3><?= icon($exit ? 'log-out' : 'flag') ?> <?= $exit ? 'Your exit' : 'Your onboarding' ?></h3><span class="pill pill-<?= e(PS::STATUS[$p['status']][1]) ?>"><?= e(PS::STATUS[$p['status']][0]) ?></span></div>
  <div class="flex mb"><span class="ring" style="--v:<?= $pct ?>;--c:var(--<?= $exit ? 'peach' : 'mint' ?>)" data-label="<?= $pct ?>%"></span><div class="grow"><b><?= $done ?> of <?= $total ?> tasks done</b><div class="small muted"><?= $exit ? 'Last working day '.e(fdate($p['last_working_day'])) : ($p['mine'] ? count($p['mine']).' task'.(count($p['mine']) === 1 ? '' : 's').' of yours still open' : 'Nothing waiting for you right now') ?></div></div></div>
  <?php if ($p['mine']): ?><div class="list"><?php foreach (array_slice($p['mine'], 0, 5) as $t): [$sl, $sc] = PS::TASK_STATUS[$t['status']]; $late = $t['due_date'] && $t['due_date'] < $today && $t['status'] !== 'submitted'; ?>
    <a class="list-item" href="<?= e(url('/hr/processes/'.$p['id'].'#task-'.$t['id'])) ?>"><span class="pill pill-<?= e($sc) ?>"><?= icon($t['task_type'] === 'ack' ? 'book' : ($t['task_type'] === 'task' ? 'check-square' : 'upload'), 'sm') ?></span><div class="grow"><b class="small"><?= e($t['title']) ?></b><div class="meta<?= $late ? ' hr-late' : '' ?>"><?= e($sl) ?><?= $t['due_date'] ? ' · '.($late ? 'overdue since ' : 'due ').e(fdate($t['due_date'])) : '' ?></div></div><?= icon('chevron-right', 'sm') ?></a>
  <?php endforeach ?></div><?php endif ?>
  <a class="btn primary sm mt" href="<?= e(url('/hr/processes/'.$p['id'])) ?>"><?= icon($exit ? 'download' : 'upload', 'sm') ?> <?= $exit ? 'Open my exit tasks' : 'Upload documents & finish tasks' ?></a>
</div>
<?php endforeach ?>
