<?php
use Modules\Meetings\Services\{Schedule, Ui};
$id = (int) $a['id'];
$cancelled = $a['meeting_status'] === 'cancelled';
$future = strtotime($a['occurred_at']) > time();
$dur = $a['ends_at'] && $a['ends_at'] > $a['occurred_at'] ? (int) round((strtotime($a['ends_at']) - strtotime($a['occurred_at'])) / 60) : null;
$state = $cancelled ? '<span class="pill pill-red">Cancelled</span>' : ($a['transcript_id'] ? '<span class="pill pill-mint">Notes added</span>' : ($future ? '<span class="pill pill-sky">Upcoming</span>' : '<span class="pill pill-lemon">Waiting for notes</span>'));
$head = '';
if ($canEdit) { $head .= '<button type="button" class="btn ghost" data-modal="mt-edit">'.icon('edit', 'sm').' '.($future ? 'Edit or reschedule' : 'Edit').'</button><button type="button" class="btn ghost" data-modal="mt-cancel">'.icon($cancelled ? 'refresh' : 'x', 'sm').' '.($cancelled ? 'Restore' : 'Cancel meeting').'</button>'; }
if ($a['transcript_id']) { $head .= '<a class="btn primary" href="'.e(url('/meetings/'.(int) $a['transcript_id'])).'">'.icon('file-text', 'sm').' Notes &amp; summary</a>'; }
elseif ($canEdit && !$cancelled) { $head .= '<a class="btn primary" href="#notes">'.icon('file-text', 'sm').' Add notes / transcript</a>'; }
?>
<div class="mt">
<?= partial('partials/page_head', ['title' => $a['title'], 'subtitle' => Schedule::when($a).' · '.Schedule::where($a), 'crumbs' => [['Meetings', can('meetings.use') ? '/meetings?tab=upcoming' : null], [str_limit($a['title'], 40), null]], 'actions' => $head]) ?>
<div class="grid g-main">
  <div class="stack">
    <section class="card">
      <div class="card-head"><div class="flex"><span class="mt-ic"><?= icon('calendar', 'sm') ?></span><h3>Meeting</h3></div><?= $state ?></div>
      <dl class="kv mt-kv">
        <dt>When</dt><dd><?= e(fdatetime($a['occurred_at'])) ?><?= $dur ? ' · '.e(Schedule::DURATIONS[$dur] ?? $dur.' min') : '' ?></dd>
        <?php if ($a['client_id']): ?><dt>Client</dt><dd><a href="<?= e(url('/clients/'.(int) $a['client_id'])) ?>"><?= e($a['client_name']) ?></a></dd><?php endif ?>
        <?php if ($a['project_id']): ?><dt>Project</dt><dd><a href="<?= e(url('/projects/'.(int) $a['project_id'].'?tab=log')) ?>"><?= e($a['project_name']) ?></a></dd><?php endif ?>
        <dt>Organiser</dt><dd><?= e($a['owner_name'] ?: 'Unknown') ?></dd>
        <?php if ($a['follow_up_at']): ?><dt>Follow-up</dt><dd><?= e(fdatetime($a['follow_up_at'])) ?><?= $a['follow_up_done'] ? ' · done' : '' ?></dd><?php endif ?>
      </dl>
      <?php if ($a['body'] && $a['body'] !== \Modules\Meetings\Services\MeetingService::PENDING): ?><h4 class="mt-sub">Agenda / notes</h4><p class="small mt-body"><?= nl2br(e($a['body'])) ?></p><?php endif ?>
      <p class="xs muted mb0"><?= icon('activity', 'sm') ?> Saved in the <a href="<?= e(url($logUrl)) ?>">client log</a>.</p>
    </section>
    <?php if (!$a['transcript_id'] && $canEdit && !$cancelled): ?>
    <section class="card" id="notes">
      <div class="card-head"><div><h3>Add notes / transcript</h3><p class="xs muted mb0"><?= $future ? 'After the meeting, add' : 'Add' ?> the transcript or your notes<?= $ai ? '. Goraiya summarises them into this log and lists the action items' : '' ?>.</p></div></div>
      <form method="post" action="<?= e(url('/meetings/event/'.$id.'/notes')) ?>" enctype="multipart/form-data" class="form"><?= csrf_field() ?>
        <?= Ui::attachSection(['client_id' => $a['client_id'], 'project_id' => $a['project_id'], 'project_name' => $a['project_name'], 'standalone' => true]) ?><?= error_for('mt_text') ?><?= error_for('mt_file') ?>
        <div class="form-actions"><button class="btn primary"><?= icon('check', 'sm') ?> Save notes</button></div>
      </form>
    </section>
    <?php endif ?>
  </div>
  <div class="stack">
    <section class="card">
      <div class="card-head"><h3>Attendees</h3><span class="crm-count"><?= count($people) ?></span></div>
      <?php if (!$people): ?><p class="small muted mb0">No attendees added.<?= $canEdit ? ' Edit the meeting to invite team members and client contacts.' : '' ?></p><?php else: ?>
      <div class="list"><?php foreach ($people as $p): ?><div class="list-item"><?= avatar($p['name'], $p['avatar']) ?><div class="grow"><b class="small"><?= e($p['name']) ?></b><div class="meta"><?= $p['kind'] === 'user' ? 'Team' : 'Client contact' ?><?= $p['email'] ? ' · '.e($p['email']) : '' ?></div></div></div><?php endforeach ?></div>
      <?php endif ?>
    </section>
  </div>
</div>
<?php if ($canEdit): ?>
<?= partial('meetings::partials/new_meeting', ['ctx' => ['return' => 'event'], 'form' => $form, 'm' => $a, 'people' => $people]) ?>
<dialog class="modal" id="mt-cancel" aria-labelledby="mt-cancel-t">
  <div class="modal-head"><h3 id="mt-cancel-t"><?= $cancelled ? 'Restore meeting' : 'Cancel meeting' ?></h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/meetings/event/'.$id.'/cancel')) ?>"><?= csrf_field() ?>
    <p class="small muted mb0"><?= $cancelled ? 'The meeting is planned again and attendees are told.' : 'The meeting stays in the client log marked as cancelled. Team attendees are told.' ?></p>
    <div class="field"><label for="mt-cancel-note">Note to attendees</label><input id="mt-cancel-note" type="text" name="note" maxlength="500" placeholder="Optional, e.g. Client asked to move it to next week"></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Back</button><button class="btn <?= $cancelled ? 'primary' : 'danger' ?>"><?= icon($cancelled ? 'refresh' : 'x', 'sm') ?> <?= $cancelled ? 'Restore meeting' : 'Cancel meeting' ?></button></div>
  </form>
</dialog>
<?php endif ?>
</div>
