<?php
use Modules\Meetings\Services\{Recap, Ui};
$id = (int) $t['id'];
$src = Recap::label($t['tool'], $t['source']);
$lines = $t['transcript'] ? explode("\n", (string) $t['transcript']) : [];
$sec = static fn (string $h, array $items) => $items ? '<div class="mt-sum-sec"><h4>'.e($h).'</h4><ul>'.implode('', array_map(static fn ($x) => '<li>'.e($x).'</li>', $items)).'</ul></div>' : '';
$guess = static function (string $owner, array $people): ?int { $f = mb_strtolower(trim(explode(' ', trim($owner))[0] ?? '')); if ($f === '') { return null; } foreach ($people as $pid => $n) { if (str_starts_with(mb_strtolower((string) $n), $f)) { return (int) $pid; } } return null; };
$head = $canDelete ? '<form method="post" action="'.e(url('/meetings/'.$id.'/delete')).'" data-confirm="Delete this transcript? The meeting log and its summary stay.">'.csrf_field().'<button class="btn ghost">'.icon('trash', 'sm').' Delete transcript</button></form>' : '';
if ($logUrl) { $head = '<a class="btn primary" href="'.e(url($logUrl)).'">'.icon('activity', 'sm').' Open the log</a>'.$head; }
?>
<div class="mt">
<?= partial('partials/page_head', ['title' => $t['title'], 'subtitle' => implode(' · ', array_filter([$t['meeting_at'] ? fdatetime($t['meeting_at']) : 'Date unknown', $t['duration_min'] ? (int) $t['duration_min'].' min' : null, $src, $t['owner_name'] ? 'Added by '.$t['owner_name'] : null])), 'crumbs' => [['Meetings', '/meetings'], [str_limit($t['title'], 40), null]], 'actions' => $head]) ?>
<div class="grid g-main">
  <div class="stack">
    <section class="card mt-summary">
      <div class="card-head"><div class="flex"><span class="mt-ic"><?= icon('sparkles', 'sm') ?></span><h3>Goraiya summary</h3></div><?= Ui::summaryBadge($t['summary_status']) ?></div>
      <?php if ($t['summary_status'] === 'done' && $summary): ?>
        <p class="xs muted"><?= icon('info', 'sm') ?> AI summary from <?= e($src) ?>, review for accuracy.</p>
        <?php if ($summary['overview'] !== ''): ?><p><?= e($summary['overview']) ?></p><?php endif ?>
        <?= $sec('Key decisions', $summary['decisions']) ?><?= $sec('Client requests & requirements', $summary['client_requests']) ?><?= $sec('Open questions', $summary['open_questions']) ?>
        <?php if ($summary['next_meeting'] !== ''): ?><p class="small"><b>Next meeting:</b> <?= e($summary['next_meeting']) ?></p><?php endif ?>
      <?php elseif (in_array($t['summary_status'], ['new', 'summarising'], true)): ?><p class="small muted mb0">Goraiya is writing the summary in the background. It appears here and in the log within a minute or two.</p>
      <?php elseif ($t['summary_status'] === 'failed'): ?><div class="alert error"><?= icon('alert') ?><div><?= e($t['summary_error'] ?: 'The summary failed.') ?></div></div>
      <?php else: ?><p class="small muted mb0"><?= $ai ? 'No summary yet.' : 'AI summaries are off or no AI model is set up, so this meeting is kept without a summary.' ?></p><?php endif ?>
      <?php if ($canEdit && $ai && $t['summary_status'] !== 'summarising' && $t['summary_status'] !== 'new' && ($t['transcript'] || $t['source_summary'])): ?><form method="post" action="<?= e(url('/meetings/'.$id.'/summarise')) ?>" class="mt"><?= csrf_field() ?><button class="btn sm soft"><?= icon('refresh', 'sm') ?> <?= $t['summary_status'] === 'failed' ? 'Retry summary' : ($t['summary_status'] === 'done' ? 'Re-run summary' : 'Create summary') ?></button></form><?php endif ?>
    </section>
    <?php if ($summary && $summary['action_items']): ?>
    <section class="card" id="actions">
      <div class="card-head"><div><h3>Action items</h3><p class="xs muted mb0">Tick items to create <?= $taskOk ? 'project tasks' : '' ?><?= $taskOk && $todoOk ? ' or ' : '' ?><?= $todoOk ? 'to-dos' : '' ?> with an assignee and due date.</p></div></div>
      <?php if ($canEdit && ($taskOk || $todoOk)): ?><form method="post" action="<?= e(url('/meetings/'.$id.'/actions')) ?>" class="form"><?= csrf_field() ?><?php endif ?>
      <div class="mt-actions">
        <?php foreach ($summary['action_items'] as $i => $a): $done = $actions[$i] ?? null; $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['due']) ? $a['due'] : ''; ?>
        <div class="mt-action<?= $done ? ' done' : '' ?>">
          <?php if ($done): ?><span class="mt-ic ok"><?= icon('check', 'sm') ?></span><div class="grow"><b class="small"><?= e($a['text']) ?></b><div class="xs muted"><a href="<?= e(url($done[0] === 'task' ? '/tasks/'.(int) $done[1] : '/todos')) ?>"><?= $done[0] === 'task' ? 'Project task created' : 'To-do created' ?></a></div></div>
          <?php elseif ($canEdit && ($taskOk || $todoOk)): ?>
            <label class="check"><input type="checkbox" name="items[<?= (int) $i ?>][on]" value="1"></label>
            <div class="grow"><input type="text" name="items[<?= (int) $i ?>][title]" value="<?= e($a['text']) ?>" maxlength="255" aria-label="Task title"><div class="xs muted"><?= e(implode(' · ', array_filter([$a['owner'] ? 'Owner: '.$a['owner'] : '', $a['due'] ? 'Due: '.$a['due'] : '']))) ?></div>
              <div class="mt-action-opts">
                <select name="items[<?= (int) $i ?>][kind]" aria-label="Create as" data-mt-kind><?php if ($taskOk): ?><option value="task">Project task</option><?php endif ?><?php if ($todoOk): ?><option value="todo">To-do</option><?php endif ?></select>
                <?php if ($taskOk): ?><select name="items[<?= (int) $i ?>][assignee]" aria-label="Assignee" data-mt-for="task"><option value="">Unassigned</option><?php $g = $guess((string) $a['owner'], $team); foreach ($team as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($g, $uid) ?>><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
                <?php if ($todoOk): ?><select name="items[<?= (int) $i ?>][assignee]" aria-label="Assignee" data-mt-for="todo"<?= $taskOk ? ' disabled class="hide"' : '' ?>><?php foreach ($assignable as $uid => $un): ?><option value="<?= (int) $uid ?>"><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
                <input type="date" name="items[<?= (int) $i ?>][due]" value="<?= e($due) ?>" aria-label="Due date">
              </div></div>
          <?php else: ?><span class="mt-ic"><?= icon('check-square', 'sm') ?></span><div class="grow"><b class="small"><?= e($a['text']) ?></b><div class="xs muted"><?= e(implode(' · ', array_filter([$a['owner'], $a['due']]))) ?></div></div><?php endif ?>
        </div>
        <?php endforeach ?>
      </div>
      <?php if ($canEdit && ($taskOk || $todoOk)): ?><?= error_for('items') ?><div class="form-actions"><button class="btn primary sm"><?= icon('plus', 'sm') ?> Create selected</button></div></form><?php endif ?>
      <?php if (!$taskOk && $t['project_id'] === null): ?><p class="xs muted mt mb0">Link the meeting to a project to create project tasks.</p><?php endif ?>
    </section>
    <?php endif ?>
    <section class="card">
      <details class="mt-transcript"<?= $t['summary_status'] === 'done' ? '' : ' open' ?>>
        <summary><span class="flex"><span class="mt-ic"><?= icon('file-text', 'sm') ?></span><b>Full transcript</b> <span class="xs muted"><?= number_format((int) $t['chars']) ?> characters<?= $t['file_name'] ? ' · '.e($t['file_name']) : '' ?></span></span><?= icon('chevron-down', 'sm') ?></summary>
        <?php if ($t['purged_at']): ?><p class="small muted">The full text was removed on <?= e(fdate($t['purged_at'])) ?> by the retention policy. The summary in the log stays.</p>
        <?php else: ?><div class="mt-lines"><?php foreach (array_slice($lines, 0, 4000) as $l): if (trim($l) === '') { continue; } ?><?php if (preg_match('/^([^:]{1,60}):\s+(.+)$/u', $l, $m)): ?><p><b><?= e($m[1]) ?></b> <?= e($m[2]) ?></p><?php else: ?><p><?= e($l) ?></p><?php endif ?><?php endforeach ?></div><?php endif ?>
        <?php if ($t['source_summary']): ?><div class="mt-sum-sec"><h4>Notes from <?= e($src) ?></h4><p class="small"><?= nl2br(e(str_limit((string) $t['source_summary'], 6000))) ?></p></div><?php endif ?>
      </details>
    </section>
  </div>
  <div class="stack">
    <section class="card">
      <div class="card-head"><h3>Logged on</h3><?= Ui::confidence($t['confidence']) ?></div>
      <dl class="kv">
        <dt>Client</dt><dd><?= $t['client_id'] ? '<a href="'.e(url('/clients/'.(int) $t['client_id'])).'">'.e($t['client_name']).'</a>' : '<span class="muted">-</span>' ?></dd>
        <dt>Project</dt><dd><?= $t['project_id'] ? '<a href="'.e(url('/projects/'.(int) $t['project_id'])).'">'.e($t['project_name']).'</a>' : '<span class="muted">Non-project</span>' ?></dd>
        <dt>Log</dt><dd><?= $logUrl ? '<a href="'.e(url($logUrl)).'">'.e($t['log_title']).'</a>' : ($t['status'] === 'dismissed' ? 'Dismissed' : '<span class="pill pill-lemon">To sort</span>') ?></dd>
      </dl>
      <?php if ($reasons): ?><div class="mt-reasons"><b class="xs">Why</b><ul class="xs"><?php foreach ($reasons as $r): ?><li><?= e($r) ?></li><?php endforeach ?></ul></div><?php endif ?>
      <?php if ($t['status'] === 'to_sort' && $canEdit && can('crm.clients.manage')): ?>
      <form method="post" action="<?= e(url('/meetings/'.$id.'/assign')) ?>" class="form mt" data-mt-assign><?= csrf_field() ?>
        <?= partial('meetings::partials/assign', ['clientOptions' => $clientOptions, 'projectRows' => $projectRows, 'selClient' => $t['client_id'], 'selProject' => $t['project_id'], 'prefix' => 'mt-s']) ?>
        <button class="btn primary sm"><?= icon('check', 'sm') ?> Save log</button>
      </form>
      <?php endif ?>
    </section>
    <section class="card">
      <div class="card-head"><h3>Details</h3></div>
      <dl class="kv">
        <dt>Source</dt><dd><?= e($src) ?><?= $t['sender_email'] ? '<div class="xs muted">'.e($t['sender_email']).'</div>' : '' ?></dd>
        <dt>Added</dt><dd><?= e(fdatetime($t['created_at'])) ?></dd>
        <?php if ($t['external_id']): ?><dt>Reference</dt><dd class="mono xs"><?= e($t['external_id']) ?></dd><?php endif ?>
      </dl>
      <?php if ($people): ?><h4 class="small mt">Participants</h4><div class="chips"><?php foreach (array_slice($people, 0, 30) as $p): ?><span class="pill pill-gray" title="<?= e((string) $p['email']) ?>"><?= e($p['name'] ?: $p['email']) ?></span><?php endforeach ?></div><?php endif ?>
    </section>
    <p class="xs muted"><?= icon('shield', 'sm') ?> <?= e(Ui::PRIVACY) ?></p>
  </div>
</div>
</div>
