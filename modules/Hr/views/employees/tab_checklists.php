<?php
use Modules\Hr\Services\ProcessService as PS;
$manage = can('hr.employees.manage'); $eid = (int) $emp['id']; $hrS = PS::isHr() && !$self;
$latest = []; foreach ($procs as $pr) { $latest[$pr['kind']] ??= $pr; }
$shown = array_map(static fn ($x) => (int) $x['id'], array_values($latest));
$history = array_values(array_filter($procs, static fn ($x) => !in_array((int) $x['id'], $shown, true)));
$return = '/hr/employees/'.$eid.'?tab=checklists';
?>
<div class="grid g2 hr-top">
  <?php foreach (['onboarding' => ['Onboarding', 'rocket', 'mint', 'flag'], 'exit' => ['Exit', 'coffee-break', 'peach', 'log-out']] as $kind => [$label, $ill, $col, $ic]):
    $cur = $latest[$kind] ?? null; $isOpen = $cur && in_array($cur['status'], ['active', 'paused'], true); $pc = $cur && (int) $cur['total'] ? (int) round($cur['done'] / $cur['total'] * 100) : 0; ?>
  <div class="card hr-proc-card">
    <div class="card-head"><h3><?= icon($ic, 'sm') ?> <?= e($label) ?></h3><?php if ($cur): ?><span class="pill pill-<?= e(PS::STATUS[$cur['status']][1]) ?>"><?= e(PS::STATUS[$cur['status']][0]) ?></span><?php endif ?></div>
    <?php if ($cur): ?>
      <div class="flex mb"><span class="ring" style="--v:<?= $pc ?>;--c:var(--<?= $col ?>)" data-label="<?= $pc ?>%"></span><div class="grow"><b><?= (int) $cur['done'] ?> of <?= (int) $cur['total'] ?> tasks done</b>
        <div class="small muted"><?= $kind === 'exit' ? 'Last working day '.e(fdate($cur['last_working_day'])).($cur['exit_type'] ? ' · '.e(PS::EXIT_TYPES[$cur['exit_type']]) : '') : 'Started '.e(fdate($cur['start_date'])) ?><?= $cur['template_name'] ? ' · '.e($cur['template_name']) : '' ?></div>
        <?php if ($cur['status'] === 'cancelled' && $cur['cancel_reason']): ?><div class="xs muted">Cancelled: <?= e($cur['cancel_reason']) ?></div><?php endif ?></div>
        <a class="btn sm soft" href="<?= e(url('/hr/processes/'.$cur['id'])) ?>">Open <?= icon('chevron-right', 'sm') ?></a></div>
      <?php if ($hrS && $isOpen): ?>
      <div class="hr-proc-quick">
        <?php if ($cur['status'] === 'active'): ?><form method="post" action="<?= e(url('/hr/processes/'.$cur['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><input type="hidden" name="action" value="pause"><button class="btn sm ghost"><?= icon('pause', 'sm') ?> Pause</button></form>
        <?php else: ?><form method="post" action="<?= e(url('/hr/processes/'.$cur['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><input type="hidden" name="action" value="resume"><button class="btn sm ghost"><?= icon('play', 'sm') ?> Resume</button></form><?php endif ?>
        <details class="hr-pop"><summary class="btn sm ghost"><?= icon('x', 'sm') ?> Turn off</summary><form class="hr-pop-body" method="post" action="<?= e(url('/hr/processes/'.$cur['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><input type="hidden" name="action" value="cancel"><label class="small b" for="cr-<?= $kind ?>">Reason *</label><textarea id="cr-<?= $kind ?>" name="reason" rows="2" maxlength="500" required placeholder="e.g. Started by mistake"></textarea><p class="xs muted mb0">Cancels it and keeps the tasks and files. You can reopen it later.</p><button class="btn sm danger">Cancel <?= e(strtolower($label)) ?></button></form></details>
        <?php if (!(int) $cur['uploads']): ?><form method="post" action="<?= e(url('/hr/processes/'.$cur['id'].'/remove')) ?>" data-confirm="Remove this <?= e(strtolower($label)) ?> completely? Use this only if it was started by mistake; nothing is kept."><?= csrf_field() ?><button class="btn sm ghost hr-neg"><?= icon('trash', 'sm') ?> Remove</button></form><?php endif ?>
      </div>
      <?php endif ?>
      <?php if ($hrS && !$isOpen): ?><details class="hr-pop-wide mt"><summary class="btn sm ghost"><?= icon('plus', 'sm') ?> Start a new <?= e(strtolower($label)) ?></summary><?= partial('hr::processes/_start', ['emp' => $emp, 'kind' => $kind, 'list' => $templates[$kind] ?? []]) ?></details><?php endif ?>
    <?php else: ?>
      <div class="empty">
        <?= illus($ill, 'empty-illus') ?>
        <h4><?= $kind === 'onboarding' ? 'No onboarding started' : 'No exit planned' ?></h4>
        <p class="small muted"><?= $kind === 'onboarding' ? ($hrS ? 'Start from a template: the employee uploads ID, bank and education documents, signs agreements and reads policies right here.' : 'HR starts onboarding when you join.') : ($hrS ? 'Plan the exit: handover, assets, access, settlement and the relieving letter, with an optional automatic deactivation.' : 'Nothing to do here.') ?></p>
      </div>
      <?php if ($hrS): ?><?= partial('hr::processes/_start', ['emp' => $emp, 'kind' => $kind, 'list' => $templates[$kind] ?? []]) ?><?php elseif ($self && $manage): ?><p class="xs muted">Another HR person needs to start your own <?= e(strtolower($label)) ?>.</p><?php endif ?>
    <?php endif ?>
  </div>
  <?php endforeach ?>
</div>
<?php foreach ($open as $kind => $d): ?>
<section class="mt" aria-label="<?= e(PS::KINDS[$kind]) ?> tasks">
  <div class="hr-sec-head"><h3><?= e(PS::KINDS[$kind]) ?> tasks</h3><a class="btn sm ghost" href="<?= e(url('/hr/processes/'.$d['proc']['id'])) ?>"><?= icon('external', 'sm') ?> Full page</a></div>
  <?= partial('hr::processes/_tasks', ['proc' => $d['proc'], 'tasks' => $d['tasks'], 'uploads' => $d['uploads'], 'docs' => $d['docs'], 'requests' => $d['requests'], 'return' => $return]) ?>
</section>
<?php endforeach ?>
<?php if ($history): ?>
<div class="card mt"><h3 class="small">Earlier records</h3><div class="list"><?php foreach ($history as $h): ?><a class="list-item" href="<?= e(url('/hr/processes/'.$h['id'])) ?>"><span class="pill pill-<?= e(PS::STATUS[$h['status']][1]) ?>"><?= e(PS::STATUS[$h['status']][0]) ?></span><div class="grow small"><b><?= e(PS::KINDS[$h['kind']]) ?></b> · <?= e(fdate($h['started_at'])) ?><?= $h['cancel_reason'] ? ' · '.e($h['cancel_reason']) : '' ?></div><span class="xs muted"><?= (int) $h['done'] ?>/<?= (int) $h['total'] ?></span><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?></div></div>
<?php endif ?>
<?php foreach (['onboarding' => 'Earlier onboarding checklist', 'offboarding' => 'Earlier offboarding checklist'] as $type => $label): $items = $checklists[$type] ?? []; if (!$items) { continue; } $dn = count(array_filter($items, static fn ($i) => $i['done_at'] !== null)); ?>
<details class="card mt"><summary class="b small"><?= e($label) ?> <span class="pill pill-gray"><?= $dn ?>/<?= count($items) ?></span></summary>
  <?php if ($manage): ?><div class="flex mt"><span class="xs muted grow">From the old checklist system, replaced by Onboarding &amp; exit.</span><form method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/checklists/'.$type.'/delete')) ?>" data-confirm="Remove this earlier checklist? Its <?= count($items) ?> items are deleted."><?= csrf_field() ?><button class="btn sm danger"><?= icon('trash', 'sm') ?> Remove</button></form></div><?php endif ?>
  <div class="hr-check mt"><?php foreach ($items as $i): $ok = $i['done_at'] !== null; ?>
    <div class="hr-check-item<?= $ok ? ' done' : '' ?>"><?php if ($manage): ?><form method="post" action="<?= e(url('/hr/checklist-items/'.$i['id'].'/toggle')) ?>"><?= csrf_field() ?><button class="hr-tick" title="<?= $ok ? 'Mark as not done' : 'Mark as done' ?>" aria-pressed="<?= $ok ? 'true' : 'false' ?>"><?= icon('check', 'sm') ?></button></form><?php else: ?><span class="hr-tick" aria-hidden="true"><?= icon('check', 'sm') ?></span><?php endif ?>
      <div class="grow"><div class="b"><?= e($i['title']) ?></div><?php if ($ok): ?><div class="xs muted">Done <?= fdatetime($i['done_at']) ?><?= $i['done_by_name'] ? ' by '.e($i['done_by_name']) : '' ?></div><?php endif ?></div></div>
  <?php endforeach ?></div></details>
<?php endforeach ?>
<?php if ($manage && !$self): ?>
<div class="card hr-danger-zone mt">
  <h3><?= icon('lock') ?> Account access</h3>
  <?php if ($emp['status'] === 'inactive'): ?>
    <p class="small mb0">This account is <b>inactive</b>: the person can no longer sign in.</p>
  <?php else: ?>
    <p class="small">When the employee has left, deactivate the account. They are signed out everywhere and pending leave is cancelled. History is kept. An exit with automatic deactivation does this on the last working day.</p>
    <form method="post" action="<?= e(url('/hr/employees/'.$eid.'/deactivate')) ?>" data-confirm="Deactivate <?= e($emp['name']) ?>? They will be signed out immediately."><?= csrf_field() ?><button class="btn danger"><?= icon('lock', 'sm') ?> Deactivate account now</button></form>
  <?php endif ?>
</div>
<?php endif ?>
