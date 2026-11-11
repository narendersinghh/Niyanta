<?php
/* New meeting / edit meeting dialog. $ctx: client_id, project_id (fixed when set), return; $form: Schedule::formData; $m: meeting row when editing, $people: its attendees. */
use Modules\Meetings\Services\Schedule;
$edit = (bool) $m; $did = $edit ? 'mt-edit' : 'mt-new';
$mine = old('mt_form') === $did; $err = $mine ? errors() : [];
$v = static fn (string $k, mixed $d = '') => $mine ? old($k, $d) : $d;
$fixC = $ctx['client_id'] ?? null; $fixP = $ctx['project_id'] ?? null;
$selC = $v('client_id', $m['client_id'] ?? $fixC); $selP = $v('project_id', $m['project_id'] ?? $fixP);
$dt = static fn (?string $x) => $x ? date('Y-m-d\TH:i', strtotime($x)) : '';
$when = $v('occurred_at', $edit ? $dt($m['occurred_at']) : date('Y-m-d\TH:i', (int) (floor(time() / 300) * 300)));
$dur = (int) $v('duration_min', $edit && $m['ends_at'] && $m['ends_at'] > $m['occurred_at'] ? (int) round((strtotime($m['ends_at']) - strtotime($m['occurred_at'])) / 60) : 30);
if (!isset(Schedule::DURATIONS[$dur])) { $dur = 30; }
$users = array_map('intval', (array) $v('users', array_column(array_filter($people, static fn ($p) => $p['kind'] === 'user'), 'id')));
$cons = array_map('intval', (array) $v('contacts', array_column(array_filter($people, static fn ($p) => $p['kind'] === 'contact'), 'id')));
$ef = static fn (string $k) => isset($err[$k]) ? '<div class="field-error">'.e($err[$k]).'</div>' : '';
$fixedName = $fixC ? ($form['clients'][$fixC] ?? 'This client') : null;
$fixedProj = $fixP ? (array_column($form['projects'], 'name', 'id')[$fixP] ?? 'This project') : null;
$me = (int) (\Core\Auth::id() ?? 0);
?>
<dialog class="modal mt-plan" id="<?= $did ?>"<?= $err ? ' data-open' : '' ?> aria-labelledby="<?= $did ?>-t">
  <form method="post" action="<?= e(url($edit ? '/meetings/event/'.(int) $m['id'] : '/meetings/new')) ?>" class="mt-plan-form" data-mt-plan><?= csrf_field() ?>
    <input type="hidden" name="mt_form" value="<?= $did ?>"><input type="hidden" name="return" value="<?= e($ctx['return'] ?? 'meetings') ?>">
    <div class="modal-head"><div><h3 id="<?= $did ?>-t"><?= $edit ? 'Edit meeting' : 'New meeting' ?></h3><p class="xs muted mb0"><?= $edit ? 'Reschedule, change attendees or move it to another project. Attendees are notified.' : 'Saved as a meeting in the client log. Team members you add are notified.' ?></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <div class="field"><label for="<?= $did ?>-title">Title *</label><input id="<?= $did ?>-title" type="text" name="title" maxlength="190" required value="<?= e($v('title', $m['title'] ?? '')) ?>" placeholder="e.g. Weekly sync, Design review, Kick-off"><?= $ef('title') ?></div>
      <div class="form-grid mt-when">
        <div class="field"><label for="<?= $did ?>-at">Date &amp; time *</label><input id="<?= $did ?>-at" type="datetime-local" name="occurred_at" required value="<?= e($when) ?>"><?= $ef('occurred_at') ?></div>
        <div class="field"><label for="<?= $did ?>-dur">Duration</label><select id="<?= $did ?>-dur" name="duration_min"><?php foreach (Schedule::DURATIONS as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $dur) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      </div>
      <div class="form-grid" data-mt-pair>
        <?php if ($fixC && !$edit): ?><input type="hidden" name="client_id" value="<?= (int) $fixC ?>" data-mt-client><div class="field"><div class="mt-lrow"><span class="lbl">Client</span></div><div class="mt-fixed"><?= icon('briefcase', 'sm') ?> <?= e($fixedName) ?></div></div>
        <?php elseif ($fixP && !$fixC && !$edit): ?><input type="hidden" name="client_id" value="" data-mt-client><div class="field"><div class="mt-lrow"><span class="lbl">Client</span></div><div class="mt-fixed muted"><?= icon('briefcase', 'sm') ?> Internal project</div></div>
        <?php else: ?><div class="field"><div class="mt-lrow"><label for="<?= $did ?>-client">Client</label><input type="search" class="mt-find sm" placeholder="Search" aria-label="Search clients" data-mt-find="#<?= $did ?>-client"></div><select id="<?= $did ?>-client" name="client_id" data-mt-client><option value="">No client (internal project)</option><?php foreach ($form['clients'] as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $selC) ?>><?= e($cn) ?></option><?php endforeach ?></select><?= $ef('client_id') ?></div><?php endif ?>
        <?php if ($fixP && !$edit): ?><input type="hidden" name="project_id" value="<?= (int) $fixP ?>"><div class="field"><div class="mt-lrow"><span class="lbl">Project</span></div><div class="mt-fixed"><?= icon('folder', 'sm') ?> <?= e($fixedProj) ?></div></div>
        <?php elseif (feature_on('projects')): ?><div class="field"><div class="mt-lrow"><label for="<?= $did ?>-project">Project <span class="muted">optional</span></label></div><select id="<?= $did ?>-project" name="project_id" data-mt-project><option value="">No project (client activity)</option><?php foreach ($form['projects'] as $pr): ?><option value="<?= (int) $pr['id'] ?>" data-client="<?= (int) $pr['client_id'] ?>"<?= selected($pr['id'], $selP) ?>><?= e($pr['name']) ?></option><?php endforeach ?></select><?= $ef('project_id') ?></div><?php endif ?>
      </div>
      <fieldset class="mt-people"><legend>Attendees <span class="muted">optional</span></legend>
        <div class="mt-people-grid">
          <div><div class="mt-people-h"><?= icon('users', 'sm') ?> Team <input type="search" class="mt-find sm" placeholder="Search" aria-label="Search team members" data-mt-filter></div>
            <div class="mt-pick" role="group" aria-label="Team members"><?php foreach ($form['team'] as $t): ?><label class="mt-chk" data-name="<?= e(mb_strtolower($t['name'])) ?>"><input type="checkbox" name="users[]" value="<?= (int) $t['id'] ?>"<?= checked(in_array((int) $t['id'], $users, true)) ?>> <span><?= e($t['name']) ?><?= (int) $t['id'] === $me ? ' <small class="muted">(you)</small>' : '' ?></span></label><?php endforeach ?></div></div>
          <div><div class="mt-people-h"><?= icon('user', 'sm') ?> Client contacts</div>
            <div class="mt-pick" role="group" aria-label="Client contacts" data-mt-contacts><?php foreach ($form['contacts'] as $c): ?><label class="mt-chk" data-client="<?= (int) $c['client_id'] ?>"><input type="checkbox" name="contacts[]" value="<?= (int) $c['id'] ?>"<?= checked(in_array((int) $c['id'], $cons, true)) ?>> <span><?= e($c['name']) ?><?= $c['designation'] ? ' <small class="muted">'.e($c['designation']).'</small>' : '' ?></span></label><?php endforeach ?><p class="xs muted mt-none" data-mt-nocontacts>Pick a client with contacts to add them.</p></div></div>
        </div>
      </fieldset>
      <div class="field"><label for="<?= $did ?>-body">Agenda / notes</label><textarea id="<?= $did ?>-body" name="body" rows="3" maxlength="5000" placeholder="What will you discuss? Links, decisions needed…"><?= e($v('body', $m['body'] ?? '')) ?></textarea><?= $ef('body') ?></div>
      <div class="form-grid">
        <div class="field"><label for="<?= $did ?>-fu">Follow-up <span class="muted">optional</span></label><input id="<?= $did ?>-fu" type="datetime-local" name="follow_up_at" value="<?= e($v('follow_up_at', $dt($m['follow_up_at'] ?? null))) ?>"><?= $ef('follow_up_at') ?><div class="hint">Shows in the client's follow-ups.</div></div>
        <?php if (!$edit): ?><label class="check mt-later"><input type="checkbox" name="notes_later" value="1"<?= checked($v('notes_later')) ?>> <span><b>Add transcript or notes next</b><small class="muted">After saving, paste or upload them, or copy the notes e-mail address.</small></span></label><?php endif ?>
      </div>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> <?= $edit ? 'Save changes' : 'Save meeting' ?></button></div>
    </div>
  </form>
</dialog>
