<?php
/* Task list of one process with the actions the viewer may take. $proc, $tasks, $uploads [task_id => rows], $docs [doc_id => row], $requests [upload_id => row], $return */
use Modules\Hr\Services\ProcessService as PS;
$me = (int) user()['id']; $emp = (int) $proc['user_id']; $self = $emp === $me; $hr = PS::isHr() && !$self; $mod = PS::canModerate($emp); $active = $proc['status'] === 'active';
$today = date('Y-m-d'); $maxMb = (int) setting('storage.max_upload_mb', 10);
$typeIc = ['upload' => 'upload', 'sign' => 'edit', 'ack' => 'book', 'task' => 'check-square', 'provide' => 'download'];
$typeTone = ['upload' => 'sky', 'sign' => 'lav', 'ack' => 'lemon', 'task' => 'mint', 'provide' => 'peach'];
$ret = '<input type="hidden" name="return" value="'.e($return).'">';
$accept = '.'.implode(',.', PS::EXT);
$upForm = static function (int $tid, string $label, bool $provide = false) use ($ret, $accept, $maxMb): string {
  return '<form class="hr-upform" method="post" enctype="multipart/form-data" action="'.e(url('/hr/process-tasks/'.$tid.'/upload')).'">'.csrf_field().$ret.($provide ? '<input type="hidden" name="provide" value="1">' : '')
    .'<label class="hr-pick"><span class="sr">'.e($label).'</span><input type="file" name="file" accept="'.e($accept).'" required></label><button class="btn sm primary">'.icon('upload', 'sm').' '.e($label).'</button><span class="xs muted">PDF, image or Word, up to '.$maxMb.' MB</span></form>'; };
if ($proc['status'] === 'paused'): ?><div class="alert info"><?= icon('pause', 'sm') ?> Paused by HR<?= $proc['paused_at'] ? ' on '.e(fdate($proc['paused_at'])) : '' ?>. Files can be viewed but not added until it is resumed.</div><?php endif ?>
<?php if ($proc['status'] === 'cancelled'): ?><div class="alert warn"><?= icon('x', 'sm') ?> Cancelled<?= $proc['cancelled_at'] ? ' on '.e(fdate($proc['cancelled_at'])) : '' ?><?= $proc['cancel_reason'] ? ': '.e($proc['cancel_reason']) : '' ?>. Tasks and files are kept for the record.</div><?php endif ?>
<div class="hr-tasks">
<?php foreach ($tasks as $t): $tid = (int) $t['id']; $ups = $uploads[$tid] ?? []; $subs = array_values(array_filter($ups, static fn ($u) => $u['purpose'] === 'submission')); $prov = array_values(array_filter($ups, static fn ($u) => $u['purpose'] === 'provided'));
  [$stl, $stc] = PS::TASK_STATUS[$t['status']]; $open = !in_array($t['status'], ['done', 'skipped'], true); $late = $open && $t['due_date'] && $t['due_date'] < $today; $doc = $t['company_doc_id'] ? ($docs[(int) $t['company_doc_id']] ?? null) : null;
  $mineTask = $t['owner'] === 'employee' && $self; $canUp = $active && in_array($t['task_type'], ['upload', 'sign'], true) && $t['status'] !== 'skipped' && ($self || $mod); ?>
  <article class="hr-task st-<?= e($t['status']) ?><?= $mineTask && $open ? ' mine' : '' ?>" id="task-<?= $tid ?>">
    <div class="hr-task-head">
      <span class="hr-task-ic soft-<?= e($typeTone[$t['task_type']]) ?>" aria-hidden="true"><?= icon($t['status'] === 'done' ? 'check' : $typeIc[$t['task_type']], 'sm') ?></span>
      <div class="grow">
        <h4><?= e($t['title']) ?></h4>
        <div class="hr-task-meta"><span><?= e(PS::TYPES[$t['task_type']]) ?></span><span class="pill pill-<?= $t['owner'] === 'employee' ? 'lav' : 'sky' ?>"><?= $mineTask ? 'You' : e(PS::OWNERS[$t['owner']]) ?></span><?php if ($t['due_date']): ?><span class="<?= $late ? 'hr-late' : '' ?>"><?= icon('calendar', 'sm') ?> <?= $late ? 'Overdue since ' : 'Due ' ?><?= e(fdate($t['due_date'])) ?></span><?php endif ?></div>
      </div>
      <span class="pill pill-<?= e($stc) ?>"><?= e($stl) ?></span>
      <?php if ($hr && !in_array($proc['status'], ['completed', 'cancelled'], true)): ?><details class="dd"><summary class="btn icon sm ghost" aria-label="Task options"><?= icon('more-v', 'sm') ?></summary><div class="dd-menu">
        <button type="button" data-modal="te-<?= $tid ?>"><?= icon('edit', 'sm') ?> Edit task</button>
        <?php if ($active): ?><form method="post" action="<?= e(url('/hr/process-tasks/'.$tid.'/skip')) ?>"><?= csrf_field().$ret ?><button><?= icon($t['status'] === 'skipped' ? 'refresh' : 'minus', 'sm') ?> <?= $t['status'] === 'skipped' ? 'Needed again' : 'Not needed' ?></button></form><?php endif ?>
        <?php if (!$ups): ?><form method="post" action="<?= e(url('/hr/process-tasks/'.$tid.'/delete')) ?>" data-confirm="Remove “<?= e($t['title']) ?>” from this <?= e(strtolower(PS::KINDS[$proc['kind']])) ?>?"><?= csrf_field().$ret ?><button><?= icon('trash', 'sm') ?> Remove task</button></form><?php endif ?>
      </div></details><?php endif ?>
    </div>
    <?php if ($t['description']): ?><p class="hr-task-desc"><?= e($t['description']) ?></p><?php endif ?>
    <div class="hr-task-body">
    <?php if (in_array($t['task_type'], ['sign', 'ack'], true) && $doc): ?>
      <div class="hr-srcdoc"><?= icon('book', 'sm') ?><div class="grow small"><b><?= e($doc['title']) ?></b><div class="xs muted"><?= $t['task_type'] === 'sign' ? 'Download, sign, then upload the signed copy below.' : 'Company document' ?></div></div><?php if ($doc['file_id']): ?><a class="btn sm soft" href="<?= e(url('/files/'.$doc['file_id'].'/view')) ?>" target="_blank" rel="noopener"><?= icon('eye', 'sm') ?> Open</a><a class="btn icon sm ghost" href="<?= e(url('/files/'.$doc['file_id'])) ?>" title="Download" aria-label="Download <?= e($doc['title']) ?>"><?= icon('download', 'sm') ?></a><?php endif ?></div>
    <?php elseif ($t['task_type'] === 'ack'): ?>
      <div class="hr-srcdoc"><?= icon('book', 'sm') ?><div class="grow small"><b>Company policies</b><div class="xs muted">Read the published policies and handbook.</div></div><?php if (route_on('/hr/documents')): ?><a class="btn sm soft" href="<?= e(url('/hr/documents?category=policy')) ?>"><?= icon('eye', 'sm') ?> Open documents</a><?php endif ?></div>
    <?php endif ?>
    <?php if (in_array($t['task_type'], ['sign', 'provide'], true)): ?>
      <?php if ($prov): ?><div class="hr-ups"><div class="xs b muted"><?= $t['task_type'] === 'sign' ? 'Document to sign' : 'Your document' ?></div><?php foreach ($prov as $up): ?><?= partial('hr::processes/_upload', ['up' => $up, 'req' => null, 'return' => $return]) ?><?php endforeach ?></div>
      <?php elseif ($t['task_type'] === 'provide'): ?><p class="xs muted mb0"><?= $hr ? 'Upload the letter here; the employee is notified and can download it.' : 'HR will add this document here for you to download.' ?></p>
      <?php elseif (!$doc): ?><p class="xs muted mb0"><?= $hr ? 'Attach the document the employee should sign, or link a company document in Edit task.' : 'HR will attach the document to sign here.' ?></p><?php endif ?>
      <?php if ($hr && $active && ($t['task_type'] === 'provide' || !$doc)): ?><?= $upForm($tid, $t['task_type'] === 'provide' ? 'Upload for employee' : 'Attach document to sign', true) ?><?php endif ?>
    <?php endif ?>
    <?php if (in_array($t['task_type'], ['upload', 'sign'], true)): ?>
      <?php if ($subs): ?><div class="hr-ups"><?php if ($t['task_type'] === 'sign'): ?><div class="xs b muted">Signed copy</div><?php endif ?><?php foreach ($subs as $up): ?><?= partial('hr::processes/_upload', ['up' => $up, 'req' => $requests[(int) $up['id']] ?? null, 'return' => $return]) ?><?php endforeach ?></div><?php endif ?>
      <?php if ($canUp && ($self || !$subs || $t['status'] !== 'done')): ?><?= $upForm($tid, $t['task_type'] === 'sign' ? ($subs ? 'Upload another signed copy' : 'Upload signed copy') : ($subs ? 'Upload another file' : ($self ? 'Upload' : 'Upload for employee'))) ?><?php endif ?>
    <?php endif ?>
    <?php if ($t['task_type'] === 'ack'): ?>
      <?php if ($t['status'] === 'done'): ?><p class="xs muted mb0"><?= icon('check', 'sm') ?> Acknowledged <?= e(fdatetime($t['done_at'])) ?></p>
      <?php elseif ($self && $active): ?><form method="post" action="<?= e(url('/hr/process-tasks/'.$tid.'/ack')) ?>" data-confirm="Confirm that you have read <?= e($doc['title'] ?? 'the company policies') ?>?"><?= csrf_field().$ret ?><button class="btn sm primary"><?= icon('check', 'sm') ?> I have read and accept</button></form>
      <?php else: ?><p class="xs muted mb0">Waiting for the employee to acknowledge.</p><?php endif ?>
    <?php endif ?>
    <?php if ($t['task_type'] === 'task'): ?>
      <?php if ($active && PS::canAct($t)): ?><form method="post" action="<?= e(url('/hr/process-tasks/'.$tid.'/toggle')) ?>"><?= csrf_field().$ret ?><button class="btn sm <?= $t['status'] === 'done' ? 'ghost' : 'mint' ?>"><?= icon($t['status'] === 'done' ? 'refresh' : 'check', 'sm') ?> <?= $t['status'] === 'done' ? 'Mark as not done' : 'Mark as done' ?></button></form><?php endif ?>
    <?php endif ?>
    <?php if ($t['status'] === 'done' && $t['task_type'] !== 'ack' && $t['done_at']): ?><p class="xs muted mb0">Completed <?= e(fdatetime($t['done_at'])) ?><?= $t['done_by_name'] ? ' by '.e($t['done_by_name']) : '' ?></p><?php endif ?>
    </div>
  </article>
  <?php if ($hr): ?>
  <dialog class="modal" id="te-<?= $tid ?>"><div class="modal-head"><h3>Edit task</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/process-tasks/'.$tid)) ?>"><?= csrf_field().$ret ?>
      <div class="field"><label for="te-t-<?= $tid ?>">Title *</label><input id="te-t-<?= $tid ?>" name="title" maxlength="190" value="<?= e($t['title']) ?>" required></div>
      <div class="field"><label for="te-d-<?= $tid ?>">Instructions</label><textarea id="te-d-<?= $tid ?>" name="description" rows="2" maxlength="1000"><?= e($t['description']) ?></textarea></div>
      <div class="form-grid">
        <div class="field"><label for="te-o-<?= $tid ?>">Owner</label><select id="te-o-<?= $tid ?>" name="owner"<?= in_array($t['task_type'], ['upload', 'sign', 'ack'], true) ? ' disabled' : '' ?>><?php foreach (PS::OWNERS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['owner']) ?>><?= e($l) ?></option><?php endforeach ?></select><?php if (in_array($t['task_type'], ['upload', 'sign', 'ack'], true)): ?><input type="hidden" name="owner" value="employee"><?php endif ?></div>
        <div class="field"><label for="te-du-<?= $tid ?>">Due date</label><input id="te-du-<?= $tid ?>" type="date" name="due_date" value="<?= e($t['due_date']) ?>"></div>
      </div>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save</button></div></form></dialog>
  <?php endif ?>
<?php endforeach ?>
<?php if (!$tasks): ?><?= empty_state('No tasks', 'This '.strtolower(PS::KINDS[$proc['kind']]).' has no tasks yet.', 'empty-box') ?><?php endif ?>
</div>
