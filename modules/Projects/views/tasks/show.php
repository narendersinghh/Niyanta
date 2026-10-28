<?php
use Modules\Projects\Services\ProjectService as PS;
$id = (int) $t['id']; $me = (int) user()['id'];
$est = $t['estimated_hours'] !== null ? (float) $t['estimated_hours'] : null;
$actH = ($sec['total'] + $subSec) / 3600;
$pct = $est ? min(100, (int) round($actH / $est * 100)) : 0;
$openDeps = array_filter($deps, static fn ($d) => $d['status'] !== 'done');
$subDone = count(array_filter($subs, static fn ($s) => $s['status'] === 'done'));
$late = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'done';
$size = static fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
?>
<div class="crumbs"><a href="<?= e(url('/projects')) ?>">Projects</a><span>/</span><a href="<?= e(url('/projects/'.$t['project_id'].'?tab=board')) ?>"><?= e($t['project_name']) ?></a><?php if ($t['parent_id']): ?><span>/</span><a href="<?= e(url('/tasks/'.$t['parent_id'])) ?>"><?= e($t['parent_title']) ?></a><?php endif ?></div>
<section class="card thero ac-<?= e($t['project_color']) ?> mb">
  <div class="grow">
    <div class="flex wrap"><?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?><?= badge($t['priority'], PS::PRIORITIES[$t['priority']].' priority') ?><?php if ($late): ?><?= badge('overdue', 'Overdue') ?><?php endif ?><?php if ($openDeps): ?><span class="pill pill-red"><?= icon('lock', 'sm') ?> Blocked by <?= count($openDeps) ?></span><?php endif ?><span class="pill pill-gray"><?= $t['billable'] ? 'Billable' : 'Non-billable' ?></span></div>
    <h1 class="mt"><?= e($t['title']) ?></h1>
    <div class="flex wrap small muted"><span><?= icon('folder', 'sm') ?> <?= e($t['project_name']) ?></span><?php if ($t['milestone']): ?><span><?= icon('flag', 'sm') ?> <?= e($t['milestone']) ?></span><?php endif ?><span><?= icon('user', 'sm') ?> Created by <?= e($t['creator'] ?? '—') ?> · <?= e(ago($t['created_at'])) ?></span></div>
  </div>
  <?php if ($canManage): ?>
  <div class="flex">
    <button type="button" class="btn ghost" data-modal="task-edit"><?= icon('edit', 'sm') ?> Edit</button>
    <form method="post" action="<?= e(url('/tasks/'.$id.'/delete')) ?>" data-confirm="Delete this task<?= $subs ? ' and its '.count($subs).' subtask(s)' : '' ?>?"><?= csrf_field() ?><button class="btn icon danger" aria-label="Delete task"><?= icon('trash', 'sm') ?></button></form>
  </div>
  <?php endif ?>
</section>
<div class="grid g-side">
  <div class="stack">
    <div class="card">
      <h3>Description</h3>
      <?php if ($t['description']): ?><div class="prose"><?= nl2br(e($t['description'])) ?></div><?php else: ?><p class="muted mb0">No description.</p><?php endif ?>
    </div>
    <?php if (!$t['parent_id']): ?>
    <div class="card">
      <div class="card-head"><h3>Subtasks</h3><?php if ($subs): ?><span class="pill pill-mint"><?= $subDone ?>/<?= count($subs) ?> done</span><?php endif ?></div>
      <?php if ($subs): ?><div class="progress mb"><span style="width:<?= (int) round($subDone / count($subs) * 100) ?>%"></span></div><?php endif ?>
      <div class="sub-list">
        <?php foreach ($subs as $s): $sEdit = $canEdit || (int) $s['assignee_id'] === $me; ?>
        <div class="sub-item<?= $s['status'] === 'done' ? ' is-done' : '' ?>">
          <?php if ($sEdit): ?><form method="post" action="<?= e(url('/tasks/'.$s['id'].'/status')) ?>"><?= csrf_field() ?><?= PS::returnField() ?><input type="hidden" name="status" value="<?= $s['status'] === 'done' ? 'todo' : 'done' ?>"><button class="tick" aria-label="<?= $s['status'] === 'done' ? 'Mark as not done' : 'Mark as done' ?>"><?= icon('check', 'sm') ?></button></form><?php else: ?><span class="tick" aria-hidden="true"><?= icon('check', 'sm') ?></span><?php endif ?>
          <a class="grow" href="<?= e(url('/tasks/'.$s['id'])) ?>"><?= e($s['title']) ?></a>
          <?= $s['due_date'] ? PS::dueChip($s['due_date'], $s['status']) : '' ?>
          <?= $s['assignee'] ? avatar($s['assignee'], $s['avatar'], 'sm') : '' ?>
        </div>
        <?php endforeach ?>
        <?php if (!$subs): ?><p class="muted small">Break this task into smaller steps.</p><?php endif ?>
      </div>
      <?php if ($canEdit): ?>
      <form class="inline-add" method="post" action="<?= e(url('/tasks/'.$id.'/subtasks')) ?>"><?= csrf_field() ?>
        <input type="text" name="title" required maxlength="255" placeholder="Add a subtask…" aria-label="Subtask title">
        <?php if ($canManage): ?><select name="assignee_id" aria-label="Assignee"><option value="">Same assignee</option><?php foreach ($assignees as $uid => $un): ?><option value="<?= (int) $uid ?>"><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
        <input type="date" name="due_date" aria-label="Due date">
        <button class="btn sm primary"><?= icon('plus', 'sm') ?> Add</button>
      </form>
      <?php endif ?>
    </div>
    <?php endif ?>
    <div class="card">
      <div class="card-head"><h3>Dependencies</h3><?php if ($openDeps): ?><span class="pill pill-red"><?= count($openDeps) ?> open</span><?php elseif ($deps): ?><span class="pill pill-mint">All clear</span><?php endif ?></div>
      <p class="xs muted">This task can only move to <b>Done</b> once everything it waits on is done.</p>
      <?php if ($deps): ?><div class="list"><?php foreach ($deps as $d): ?>
        <div class="list-item"><span class="dotc bg-<?= e(PS::TASK_COLORS[$d['status']]) ?>"></span><a class="grow" href="<?= e(url('/tasks/'.$d['id'])) ?>"><b><?= e($d['title']) ?></b><div class="meta">Waiting on · <?= e(PS::TASK_STATUSES[$d['status']]) ?><?= $d['due_date'] ? ' · due '.e(fdate($d['due_date'])) : '' ?></div></a>
          <?php if ($canManage): ?><form method="post" action="<?= e(url('/tasks/'.$id.'/dependencies/'.$d['id'].'/delete')) ?>"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Remove dependency"><?= icon('x', 'sm') ?></button></form><?php endif ?></div>
      <?php endforeach ?></div><?php endif ?>
      <?php if ($blocking): ?><div class="xs muted mt-s">Blocks: <?php foreach ($blocking as $i => $b): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('/tasks/'.$b['id'])) ?>"><?= e($b['title']) ?></a><?php endforeach ?></div><?php endif ?>
      <?php if (!$deps && !$blocking): ?><p class="muted small mb0">No dependencies.</p><?php endif ?>
      <?php if ($canManage && $candidates): ?>
      <form class="inline-add mt" method="post" action="<?= e(url('/tasks/'.$id.'/dependencies')) ?>"><?= csrf_field() ?>
        <select name="depends_on_id" required aria-label="Depends on"><option value="">This task waits on…</option><?php foreach ($candidates as $cid => $ct): ?><option value="<?= (int) $cid ?>"><?= e($ct) ?></option><?php endforeach ?></select>
        <button class="btn sm soft"><?= icon('link', 'sm') ?> Add dependency</button>
      </form>
      <?php endif ?>
    </div>
    <div class="card" id="comments">
      <div class="card-head"><h3>Comments</h3><span class="pill pill-gray"><?= count($comments) ?></span></div>
      <div class="comments">
        <?php foreach ($comments as $c): ?>
        <div class="comment<?= (int) $c['user_id'] === $me ? ' mine' : '' ?>">
          <?= avatar($c['name'] ?? 'Deleted user', $c['avatar'] ?? null, 'sm') ?>
          <div class="bubble"><div class="flex between"><b class="small"><?= e($c['name'] ?? 'Deleted user') ?></b><span class="flex"><span class="xs muted" title="<?= e(fdatetime($c['created_at'])) ?>"><?= e(ago($c['created_at'])) ?></span>
            <?php if ((int) $c['user_id'] === $me || $canManage): ?><form method="post" action="<?= e(url('/tasks/'.$id.'/comments/'.$c['id'].'/delete')) ?>" data-confirm="Delete this comment?"><?= csrf_field() ?><button class="link-btn xs" aria-label="Delete comment"><?= icon('trash', 'sm') ?></button></form><?php endif ?></span></div>
            <div class="small"><?= nl2br(e($c['body'])) ?></div></div>
        </div>
        <?php endforeach ?>
        <?php if (!$comments): ?><p class="muted small">No comments yet. Start the conversation.</p><?php endif ?>
      </div>
      <?php if ($canEdit): ?>
      <form class="comment-form" method="post" action="<?= e(url('/tasks/'.$id.'/comments')) ?>"><?= csrf_field() ?>
        <?= avatar(user()['name'], user()['avatar'], 'sm') ?><textarea name="body" rows="2" required maxlength="5000" placeholder="Write a comment…" aria-label="Comment"></textarea><button class="btn primary sm"><?= icon('send', 'sm') ?> Send</button>
      </form>
      <?php endif ?>
    </div>
    <div class="card" id="files">
      <div class="card-head"><h3>Attachments</h3><span class="pill pill-gray"><?= count($files) ?></span></div>
      <?php if ($files): ?><div class="file-list"><?php foreach ($files as $f): ?>
        <div class="file-item"><span class="file-ext"><?= e(mb_substr(strtoupper(pathinfo($f['original_name'], PATHINFO_EXTENSION) ?: 'FILE'), 0, 4)) ?></span><div class="grow"><a class="b truncate" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><div class="xs muted"><?= e($size((int) $f['size'])) ?> · <?= e($f['uploader'] ?? '') ?> · <?= e(ago($f['created_at'])) ?></div></div>
          <a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" aria-label="Download"><?= icon('download', 'sm') ?></a>
          <?php if ($canEdit && ($canManage || (int) $f['uploaded_by'] === $me)): ?><form method="post" action="<?= e(url('/files/'.$f['id'].'/delete')) ?>" data-confirm="Delete this attachment?"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?></div>
      <?php endforeach ?></div><?php elseif (!$canEdit): ?><p class="muted small mb0">No attachments.</p><?php endif ?>
      <?php if ($canEdit): ?>
      <form class="inline-add mt" method="post" action="<?= e(url('/tasks/'.$id.'/files')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
        <label class="dropzone slim"><?= icon('paperclip') ?><span class="small b" data-file-name>Attach a file</span><input type="file" name="file" required data-file-label></label>
        <button class="btn sm primary"><?= icon('upload', 'sm') ?> Upload</button>
      </form>
      <?php endif ?>
    </div>
  </div>
  <div class="stack">
    <?php if ($canEdit): ?>
    <div class="card">
      <h4>Status</h4>
      <form method="post" action="<?= e(url('/tasks/'.$id.'/status')) ?>" class="seg"><?= csrf_field() ?>
        <?php foreach (PS::TASK_STATUSES as $k => $l): ?><button name="status" value="<?= e($k) ?>" class="seg-btn st-<?= e($k) ?><?= $t['status'] === $k ? ' on' : '' ?>"<?= $k === 'done' && $openDeps && $t['status'] !== 'done' ? ' title="Blocked by unfinished dependencies"' : '' ?>><?= e($l) ?></button><?php endforeach ?>
      </form>
    </div>
    <?php endif ?>
    <div class="card">
      <h4>Details</h4>
      <dl class="kv">
        <dt>Assignee</dt><dd><?php if ($t['assignee']): ?><span class="flex"><?= avatar($t['assignee'], $t['assignee_avatar'], 'sm') ?> <?= e($t['assignee']) ?></span><?php else: ?>Unassigned<?php endif ?></dd>
        <dt>Priority</dt><dd><?= badge($t['priority'], PS::PRIORITIES[$t['priority']]) ?></dd>
        <dt>Start</dt><dd><?= e(fdate($t['start_date'])) ?></dd>
        <dt>Due</dt><dd><?= PS::dueChip($t['due_date'], $t['status']) ?></dd>
        <dt>Milestone</dt><dd><?= e($t['milestone'] ?? '—') ?></dd>
        <dt>Billable</dt><dd><?= $t['billable'] ? 'Yes' : 'No' ?></dd>
        <?php if ($t['completed_at']): ?><dt>Completed</dt><dd><?= e(fdatetime($t['completed_at'])) ?></dd><?php endif ?>
      </dl>
    </div>
    <div class="card time-card">
      <div class="card-head"><h4>Time</h4><span class="pill pill-lav"><?= $est !== null ? e((float) $est).'h estimated' : 'No estimate' ?></span></div>
      <div class="flex"><div class="ring" style="--v:<?= $pct ?>;--c:<?= $est && $actH > $est ? 'var(--red)' : 'var(--primary)' ?>" data-label="<?= $est ? $pct.'%' : '—' ?>"></div>
        <div><div class="stat"><b><?= e(hours($sec['total'] + $subSec)) ?></b><span class="xs muted">logged<?= $subSec ? ' (incl. '.e(hours($subSec)).' on subtasks)' : '' ?></span></div></div></div>
      <div class="split-bar mt" title="Billable vs non-billable"><span class="bg-mint" style="flex:<?= max(1, $sec['billable']) ?>"></span><span class="bg-gray" style="flex:<?= max($sec['total'] ? 0 : 1, $sec['total'] - $sec['billable']) ?>"></span></div>
      <div class="flex between xs mt-s"><span><span class="dotc bg-mint"></span> Billable <?= e(hours($sec['billable'])) ?></span><span><span class="dotc bg-gray"></span> Non-billable <?= e(hours($sec['total'] - $sec['billable'])) ?></span></div>
      <?php if ($est && $actH > $est): ?><div class="alert warn mt mb0 small"><?= icon('alert', 'sm') ?> Over estimate by <?= e(hours((int) (($actH - $est) * 3600))) ?></div><?php endif ?>
    </div>
  </div>
</div>
<?php if ($canManage): ?>
<dialog class="modal" id="task-edit" aria-labelledby="task-edit-title">
  <div class="modal-head"><h3 id="task-edit-title">Edit task</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/tasks/'.$id)) ?>"><?= csrf_field() ?>
    <div class="field"><label>Title *</label><input type="text" name="title" required maxlength="255" value="<?= e($t['title']) ?>"></div>
    <div class="field"><label>Description</label><textarea name="description" rows="4"><?= e($t['description']) ?></textarea></div>
    <div class="form-grid">
      <div class="field"><label>Status</label><select name="status"><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['status']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Priority</label><select name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['priority']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Assignee</label><select name="assignee_id"><option value="">Unassigned</option><?php foreach ($assignees as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $t['assignee_id']) ?>><?= e($un) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Milestone</label><select name="milestone_id"><option value="">None</option><?php foreach ($milestoneOpts as $mid => $mt): ?><option value="<?= (int) $mid ?>"<?= selected($mid, $t['milestone_id']) ?>><?= e($mt) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?= e($t['start_date']) ?>"></div>
      <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= e($t['due_date']) ?>"></div>
      <div class="field"><label>Estimated hours</label><input type="number" name="estimated_hours" step="0.25" min="0" value="<?= e($t['estimated_hours']) ?>"></div>
      <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="billable" value="1"<?= checked($t['billable']) ?>> Billable to client</label></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save task</button></div>
  </form>
</dialog>
<?php endif ?>
