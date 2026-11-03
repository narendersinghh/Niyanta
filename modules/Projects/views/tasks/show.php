<?php
use Modules\Projects\Services\ProjectService as PS;
$id = (int) $t['id']; $me = (int) user()['id'];
$time = PS::on('time');
$est = $t['estimated_hours'] !== null ? (float) $t['estimated_hours'] : null;
$logged = ($sec['total'] + $subSec);
$actH = $logged / 3600;
$pct = $est ? (int) round($actH / $est * 100) : 0;
$openDeps = array_filter($deps, static fn ($d) => $d['status'] !== 'done');
$subDone = count(array_filter($subs, static fn ($s) => $s['status'] === 'done'));
$late = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'done';
$isDone = $t['status'] === 'done';
$size = static fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
$primary = '';
if ($canEdit) {
  $primary = '<form method="post" action="'.e(url('/tasks/'.$id.'/status')).'">'.csrf_field().'<input type="hidden" name="status" value="'.($isDone ? 'todo' : 'done').'">'
    .($isDone ? '<button class="btn ghost">'.icon('refresh', 'sm').' Reopen task</button>' : '<button class="btn primary"'.($openDeps ? ' disabled title="Finish the tasks this one waits on first"' : '').'>'.icon('check', 'sm').' Mark as done</button>').'</form>'; }
$more = ($canManage ? '<button type="button" data-modal="task-edit">'.icon('edit', 'sm').' Edit task</button>' : '')
  .'<a href="'.e(url('/projects/'.$t['project_id'].'?tab=board')).'">'.icon('kanban', 'sm').' Open project board</a>'
  .($canManage ? '<div class="sep"></div><form method="post" action="'.e(url('/tasks/'.$id.'/delete')).'" data-confirm="Delete this task'.($subs ? ' and its '.count($subs).' subtask(s)' : '').'?">'.csrf_field().'<button class="danger-text">'.icon('trash', 'sm').' Delete task</button></form>' : '');
?>
<div class="pj">
<header class="phead ac-<?= e($t['project_color']) ?>">
  <div class="phead-main">
    <span class="phead-ic thead-ic" aria-hidden="true"><?= icon($isDone ? 'check-circle' : 'check-square') ?></span>
    <div class="grow">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="<?= e(url('/projects')) ?>">Projects</a><span>/</span><a href="<?= e(url('/projects/'.$t['project_id'].'?tab=board')) ?>"><?= e(str_limit($t['project_name'], 32)) ?></a><?php if ($t['parent_id']): ?><span>/</span><a href="<?= e(url('/tasks/'.$t['parent_id'])) ?>"><?= e(str_limit($t['parent_title'], 32)) ?></a><?php endif ?><span>/</span><span>Task #<?= $id ?></span></nav>
      <h1><?= e($t['title']) ?></h1>
      <div class="phead-meta">
        <?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?>
        <span class="prio p-<?= e($t['priority']) ?>"><?= e(PS::PRIORITIES[$t['priority']]) ?> priority</span>
        <?php if ($late): ?><?= badge('overdue', 'Overdue') ?><?php endif ?>
        <?php if ($openDeps): ?><span class="blocked"><?= icon('lock', 'sm') ?> Blocked by <?= count($openDeps) ?></span><?php endif ?>
        <span><?= icon('folder', 'sm') ?> <a href="<?= e(url('/projects/'.$t['project_id'])) ?>"><?= e($t['project_name']) ?></a></span>
        <?php if ($t['milestone']): ?><span><?= icon('flag', 'sm') ?> <?= e($t['milestone']) ?></span><?php endif ?>
      </div>
    </div>
  </div>
  <div class="head-actions"><?= $primary ?><details class="dd"><summary class="btn ghost more-btn" aria-label="More task actions" title="More actions"><?= icon('more') ?></summary><div class="dd-menu"><?= $more ?></div></details></div>
</header>
<div class="tlayout">
  <div class="stack">
    <section class="card">
      <div class="sec-head"><span class="sec-ic"><?= icon('file-text') ?></span><h3>Description</h3></div>
      <?php if ($t['description']): ?><div class="prose"><?= nl2br(e($t['description'])) ?></div><?php else: ?><p class="muted mb0">No description yet.<?= $canManage ? ' Use <b>Edit task</b> to add details and acceptance criteria.' : '' ?></p><?php endif ?>
    </section>
    <?php if (!$t['parent_id']): ?>
    <section class="card">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic ac-mint"><?= icon('check-square') ?></span><h3>Subtasks</h3></div><?php if ($subs): ?><span class="pill pill-mint"><?= $subDone ?>/<?= count($subs) ?> done</span><?php endif ?></div>
      <?php if ($subs): ?><div class="progress mb"><span style="width:<?= (int) round($subDone / count($subs) * 100) ?>%"></span></div><?php endif ?>
      <div class="sub-list">
        <?php foreach ($subs as $s): $sEdit = $canEdit || (int) $s['assignee_id'] === $me; $sd = $s['status'] === 'done'; ?>
        <div class="sub-item<?= $sd ? ' is-done' : '' ?>">
          <?php if ($sEdit): ?><form method="post" action="<?= e(url('/tasks/'.$s['id'].'/status')) ?>" class="qcheck"><?= csrf_field() ?><?= PS::returnField() ?><input type="hidden" name="status" value="<?= $sd ? 'todo' : 'done' ?>"><button class="tick" aria-label="<?= $sd ? 'Mark as not done' : 'Mark as done' ?>: <?= e($s['title']) ?>"><?= icon('check', 'sm') ?></button></form><?php else: ?><span class="tick" aria-hidden="true"><?= icon('check', 'sm') ?></span><?php endif ?>
          <a class="grow" href="<?= e(url('/tasks/'.$s['id'])) ?>"><?= e($s['title']) ?></a>
          <?= PS::dueChip($s['due_date'], $s['status'], false) ?>
          <?= $s['assignee'] ? avatar($s['assignee'], $s['avatar'], 'sm') : '' ?>
        </div>
        <?php endforeach ?>
        <?php if (!$subs): ?><p class="muted small mb0">Break this task into smaller steps<?= $canEdit ? ', add the first one below.' : '.' ?></p><?php endif ?>
      </div>
      <?php if ($canEdit): ?>
      <form class="inline-add" method="post" action="<?= e(url('/tasks/'.$id.'/subtasks')) ?>"><?= csrf_field() ?>
        <input type="text" name="title" required maxlength="255" placeholder="Add a subtask…" aria-label="Subtask title">
        <?php if ($canManage): ?><select name="assignee_id" aria-label="Subtask assignee"><option value="">Same assignee</option><?php foreach ($assignees as $uid => $un): ?><option value="<?= (int) $uid ?>"><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
        <input type="date" name="due_date" aria-label="Subtask due date">
        <button class="btn sm soft"><?= icon('plus', 'sm') ?> Add</button>
      </form>
      <?php endif ?>
    </section>
    <?php endif ?>
    <section class="card">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic ac-peach"><?= icon('link') ?></span><h3>Dependencies</h3></div><?php if ($openDeps): ?><span class="pill pill-red"><?= count($openDeps) ?> open</span><?php elseif ($deps): ?><span class="pill pill-mint">All clear</span><?php endif ?></div>
      <?php if ($deps): ?><div class="urows"><?php foreach ($deps as $dp): ?>
        <div class="urow"><span class="dotc st-<?= e($dp['status']) ?>" style="background:var(--sc)"></span><a class="grow" href="<?= e(url('/tasks/'.$dp['id'])) ?>"><b><?= e($dp['title']) ?></b><div class="meta">Waiting on · <?= e(PS::TASK_STATUSES[$dp['status']]) ?><?= $dp['due_date'] ? ' · due '.e(fdate($dp['due_date'])) : '' ?></div></a>
          <?php if ($canManage): ?><form method="post" action="<?= e(url('/tasks/'.$id.'/dependencies/'.$dp['id'].'/delete')) ?>"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Remove dependency on <?= e($dp['title']) ?>" title="Remove"><?= icon('x', 'sm') ?></button></form><?php endif ?></div>
      <?php endforeach ?></div><?php endif ?>
      <?php if ($blocking): ?><p class="xs muted mt-s mb0"><?= icon('lock', 'sm') ?> Blocks: <?php foreach ($blocking as $i => $b): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('/tasks/'.$b['id'])) ?>"><?= e($b['title']) ?></a><?php endforeach ?></p><?php endif ?>
      <?php if (!$deps && !$blocking): ?><p class="muted small mb0">No dependencies. A task can only move to <b>Done</b> once everything it waits on is done.</p><?php endif ?>
      <?php if ($canManage && $candidates): ?>
      <form class="inline-add" method="post" action="<?= e(url('/tasks/'.$id.'/dependencies')) ?>"><?= csrf_field() ?>
        <select name="depends_on_id" required aria-label="Depends on"><option value="">This task waits on…</option><?php foreach ($candidates as $cid => $ct): ?><option value="<?= (int) $cid ?>"><?= e($ct) ?></option><?php endforeach ?></select>
        <button class="btn sm soft"><?= icon('link', 'sm') ?> Add dependency</button>
      </form>
      <?php endif ?>
    </section>
    <section class="card" id="files">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic ac-sky"><?= icon('paperclip') ?></span><h3>Attachments</h3></div><span class="pill pill-gray"><?= count($files) ?></span></div>
      <?php if ($files): ?><div class="file-list"><?php foreach ($files as $f): ?>
        <div class="file-item"><span class="file-ext"><?= e(mb_substr(strtoupper(pathinfo($f['original_name'], PATHINFO_EXTENSION) ?: 'FILE'), 0, 4)) ?></span><div class="grow"><a class="b truncate" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><div class="xs muted"><?= e($size((int) $f['size'])) ?> · <?= e($f['uploader'] ?? '') ?> · <?= e(ago($f['created_at'])) ?></div></div>
          <a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" aria-label="Download <?= e($f['original_name']) ?>" title="Download"><?= icon('download', 'sm') ?></a>
          <?php if ($canEdit && ($canManage || (int) $f['uploaded_by'] === $me)): ?><form method="post" action="<?= e(url('/files/'.$f['id'].'/delete')) ?>" data-confirm="Delete this attachment?"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Delete <?= e($f['original_name']) ?>" title="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?></div>
      <?php endforeach ?></div><?php elseif (!$canEdit): ?><p class="muted small mb0">No attachments.</p><?php endif ?>
      <?php if ($canEdit): ?>
      <form class="inline-add" method="post" action="<?= e(url('/tasks/'.$id.'/files')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
        <label class="dropzone slim"><?= icon('paperclip') ?><span class="small b" data-file-name>Attach a file</span><input type="file" name="file" required data-file-label></label>
        <button class="btn sm soft"><?= icon('upload', 'sm') ?> Upload</button>
      </form>
      <?php endif ?>
    </section>
    <section class="card" id="comments">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic ac-pink"><?= icon('message') ?></span><h3>Comments</h3></div><span class="pill pill-gray"><?= count($comments) ?></span></div>
      <div class="comments">
        <?php foreach ($comments as $c): ?>
        <div class="comment<?= (int) $c['user_id'] === $me ? ' mine' : '' ?>">
          <?= avatar($c['name'] ?? 'Deleted user', $c['avatar'] ?? null, 'sm') ?>
          <div class="bubble"><div class="flex between"><b class="small"><?= e($c['name'] ?? 'Deleted user') ?></b><span class="flex"><span class="xs muted" title="<?= e(fdatetime($c['created_at'])) ?>"><?= e(ago($c['created_at'])) ?></span>
            <?php if ((int) $c['user_id'] === $me || $canManage): ?><form method="post" action="<?= e(url('/tasks/'.$id.'/comments/'.$c['id'].'/delete')) ?>" data-confirm="Delete this comment?"><?= csrf_field() ?><button class="link-btn" aria-label="Delete comment" title="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?></span></div>
            <div class="small"><?= nl2br(e($c['body'])) ?></div></div>
        </div>
        <?php endforeach ?>
        <?php if (!$comments): ?><p class="muted small mb0">No comments yet. Ask a question or share an update.</p><?php endif ?>
      </div>
      <?php if ($canEdit): ?>
      <form class="comment-form" method="post" action="<?= e(url('/tasks/'.$id.'/comments')) ?>"><?= csrf_field() ?>
        <?= avatar(user()['name'], user()['avatar'], 'sm') ?><textarea name="body" rows="2" required maxlength="5000" placeholder="Write a comment…" aria-label="Comment"></textarea><button class="btn primary sm"><?= icon('send', 'sm') ?> Send</button>
      </form>
      <?php endif ?>
    </section>
  </div>
  <aside class="tprops">
    <section class="card">
      <h4>Status</h4>
      <?php if ($canEdit): ?>
      <form method="post" action="<?= e(url('/tasks/'.$id.'/status')) ?>" class="status-seg"><?= csrf_field() ?>
        <?php foreach (PS::TASK_STATUSES as $k => $l): ?><button name="status" value="<?= e($k) ?>" class="st-<?= e($k) ?><?= $t['status'] === $k ? ' on' : '' ?>"<?= $t['status'] === $k ? ' aria-pressed="true"' : '' ?><?= $k === 'done' && $openDeps && !$isDone ? ' title="Blocked by unfinished dependencies"' : '' ?>><?= e($l) ?></button><?php endforeach ?>
      </form>
      <?php else: ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?><p class="xs muted mt-s mb0">Only the assignee or a project manager can change the status.</p><?php endif ?>
    </section>
    <section class="card">
      <h4>Properties</h4>
      <div class="kv-list">
        <div class="kv-row"><span><?= icon('user', 'sm') ?> Assignee</span><span><?php if ($t['assignee']): ?><span class="tcell"><?= avatar($t['assignee'], $t['assignee_avatar'], 'sm') ?> <?= e($t['assignee']) ?></span><?php else: ?><span class="muted">Unassigned</span><?php endif ?></span></div>
        <div class="kv-row"><span><?= icon('flag', 'sm') ?> Priority</span><span><?= badge($t['priority'], PS::PRIORITIES[$t['priority']]) ?></span></div>
        <div class="kv-row"><span><?= icon('calendar', 'sm') ?> Due</span><span><?= PS::dueChip($t['due_date'], $t['status']) ?></span></div>
        <div class="kv-row"><span><?= icon('play', 'sm') ?> Start</span><span><?= e(fdate($t['start_date'])) ?></span></div>
        <div class="kv-row"><span><?= icon('target', 'sm') ?> Milestone</span><span><?= e($t['milestone'] ?? '-') ?></span></div>
        <div class="kv-row"><span><?= icon('timer', 'sm') ?> Estimate</span><span><?= $est !== null ? e((float) $est).'h' : '-' ?></span></div>
        <div class="kv-row"><span><?= icon('wallet', 'sm') ?> Billable</span><span><?= $t['billable'] ? 'Yes' : 'No' ?></span></div>
        <div class="kv-row"><span><?= icon('history', 'sm') ?> Created</span><span class="small"><?= e($t['creator'] ?? '-') ?> · <?= e(ago($t['created_at'])) ?></span></div>
        <?php if ($t['completed_at']): ?><div class="kv-row"><span><?= icon('check-circle', 'sm') ?> Completed</span><span class="small"><?= e(fdatetime($t['completed_at'])) ?></span></div><?php endif ?>
      </div>
    </section>
    <?php if ($time): ?>
    <section class="card">
      <div class="card-head"><h4 class="mb0">Time tracked</h4><span class="pill pill-lav"><?= $est !== null ? e((float) $est).'h estimate' : 'No estimate' ?></span></div>
      <div class="time-box"><div class="ring" style="--v:<?= min(100, $pct) ?>;--c:<?= $est && $actH > $est ? 'var(--red)' : 'var(--primary)' ?>" data-label="<?= $est ? $pct.'%' : '-' ?>"></div>
        <div><b><?= e(hours($logged)) ?></b><span class="xs muted">logged<?= $subSec ? ' (incl. '.e(hours($subSec)).' on subtasks)' : '' ?></span></div></div>
      <?php if ($sec['total']): ?>
      <div class="split-bar" title="Billable vs non-billable"><span class="bg-mint" style="flex:<?= max(1, $sec['billable']) ?>"></span><span class="bg-gray" style="flex:<?= max(0, $sec['total'] - $sec['billable']) ?>"></span></div>
      <div class="legend"><span><span class="dotc" style="background:var(--mint)"></span> Billable <?= e(hours($sec['billable'])) ?></span><span><span class="dotc" style="background:var(--surface-3)"></span> Non-billable <?= e(hours($sec['total'] - $sec['billable'])) ?></span></div>
      <?php endif ?>
      <?php if ($est && $actH > $est): ?><div class="alert warn mt mb0 small"><?= icon('alert', 'sm') ?> Over estimate by <?= e(hours((int) (($actH - $est) * 3600))) ?></div><?php endif ?>
    </section>
    <?php endif ?>
  </aside>
</div>
<?php if ($canManage): ?>
<dialog class="modal" id="task-edit" aria-labelledby="task-edit-title">
  <div class="modal-head"><h3 id="task-edit-title">Edit task</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/tasks/'.$id)) ?>"><?= csrf_field() ?>
    <div class="field"><label for="te-title">Title *</label><input id="te-title" type="text" name="title" required maxlength="255" value="<?= e($t['title']) ?>"></div>
    <div class="field"><label for="te-desc">Description</label><textarea id="te-desc" name="description" rows="3"><?= e($t['description']) ?></textarea></div>
    <div class="field"><label>Project</label><div class="small"><?= e($t['project_name']) ?></div></div>
    <div class="form-grid">
      <div class="field"><label for="te-assignee">Assign to</label><select id="te-assignee" name="assignee_id"><option value="">Unassigned</option><?php foreach ($assignees as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $t['assignee_id']) ?>><?= e($un) ?></option><?php endforeach ?></select><div class="hint">Project members and the manager.</div></div>
      <div class="field"><label for="te-prio">Priority</label><select id="te-prio" name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['priority']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="te-status">Status</label><select id="te-status" name="status"><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['status']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="te-ms">Milestone</label><select id="te-ms" name="milestone_id"><option value="">None</option><?php foreach ($milestoneOpts as $mid => $mt): ?><option value="<?= (int) $mid ?>"<?= selected($mid, $t['milestone_id']) ?>><?= e($mt) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="te-start">Start date</label><input id="te-start" type="date" name="start_date" value="<?= e($t['start_date']) ?>"></div>
      <div class="field"><label for="te-due">Due date</label><input id="te-due" type="date" name="due_date" value="<?= e($t['due_date']) ?>"></div>
      <div class="field"><label for="te-est">Estimate (hours)</label><input id="te-est" type="number" name="estimated_hours" step="0.25" min="0" value="<?= e($t['estimated_hours']) ?>"></div>
      <div class="field"><label>Billing</label><label class="check"><input type="checkbox" name="billable" value="1"<?= checked($t['billable']) ?>> Billable to client</label></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save task</button></div>
  </form>
</dialog>
<?php endif ?>
</div>
