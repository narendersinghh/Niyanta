<?php
use Modules\Hr\Services\ProcessService as PS;
$p = $proc; $pid = (int) $p['id']; $emp = (int) $p['user_id']; $self = $emp === (int) user()['id']; $hr = PS::isHr() && !$self;
[$done, $total, $pct] = PS::progress($tasks); [$stl, $stc] = PS::STATUS[$p['status']]; $kl = PS::KINDS[$p['kind']]; $exit = $p['kind'] === 'exit';
$waiting = count(array_filter($tasks, static fn ($t) => $t['status'] === 'submitted'));
$mine = count(array_filter($tasks, static fn ($t) => $t['owner'] === 'employee' && in_array($t['status'], ['pending', 'rejected'], true)));
$crumbs = $self ? [['My HR'], ['My profile', '/hr/me'], [$kl]] : (can('hr.employees.view') ? [['HR Admin'], ['Onboarding & exit', '/hr/processes'], [$p['emp_name']]] : [['Team'], ['Onboarding & exit', '/hr/processes'], [$p['emp_name']]]);
$actions = ($hr || can('hr.employees.view')) && !$self ? '<a class="btn ghost" href="'.e(url('/hr/employees/'.$emp.'?tab=checklists')).'">'.icon('id-card', 'sm').' HR profile</a>' : '';
$return = '/hr/processes/'.$pid;
?>
<?= partial('partials/page_head', ['title' => $self ? 'My '.strtolower($kl) : $kl.': '.$p['emp_name'], 'subtitle' => $self ? ($exit ? 'Your exit tasks, documents to return or download, in one place.' : 'Upload the documents HR needs, sign agreements and read the policies.') : trim(($p['designation'] ?: 'Team member').($p['department'] ? ' · '.$p['department'] : '')), 'crumbs' => $crumbs, 'actions' => $actions]) ?>
<div class="grid g-main hr-top">
  <div>
    <div class="card hr-proc-hero <?= $exit ? 'exit' : 'onb' ?> mb">
      <span class="ring" style="--v:<?= $pct ?>;--c:var(--<?= $exit ? 'peach' : 'mint' ?>)" data-label="<?= $pct ?>%"></span>
      <div class="grow">
        <div class="chips"><span class="pill pill-<?= e($stc) ?>"><?= e($stl) ?></span><span class="pill pill-<?= $exit ? 'peach' : 'mint' ?>"><?= icon($exit ? 'log-out' : 'flag', 'sm') ?> <?= e($kl) ?></span><?php if ($exit && $p['exit_type']): ?><span class="pill pill-gray"><?= e(PS::EXIT_TYPES[$p['exit_type']]) ?></span><?php endif ?></div>
        <h2 class="mt0"><?= $done ?> of <?= $total ?> tasks done</h2>
        <div class="small muted"><?= $exit ? 'Last working day '.e(fdate($p['last_working_day'])) : 'Started '.e(fdate($p['start_date'])) ?><?= $waiting ? ' · '.$waiting.' waiting for review' : '' ?><?= $mine && $p['status'] === 'active' ? ' · '.$mine.' with '.($self ? 'you' : 'the employee') : '' ?></div>
        <?php if ($exit && (int) $p['deactivate_on_lwd']): ?><div class="xs mt"><?= icon('lock', 'sm') ?> <?= $p['deactivated_at'] ? 'Account deactivated '.e(fdatetime($p['deactivated_at'])) : 'Account is deactivated automatically at the end of the last working day.' ?></div><?php endif ?>
      </div>
    </div>
    <?php if ($hr && $p['status'] === 'active' && $total && $done === $total): ?><div class="alert ok"><?= icon('check-circle', 'sm') ?><div class="grow">Every task is done. Mark the <?= e(strtolower($kl)) ?> as complete.</div><form method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn sm primary">Mark complete</button></form></div><?php endif ?>
    <?= partial('hr::processes/_tasks', compact('proc', 'tasks', 'uploads', 'docs', 'requests', 'return')) ?>
    <?php if ($hr && in_array($p['status'], ['active', 'paused'], true)): ?>
    <details class="card hr-add-task mt" id="add-task"><summary class="b"><?= icon('plus', 'sm') ?> Add a task to this <?= e(strtolower($kl)) ?></summary>
      <form class="form mt" method="post" action="<?= e(url('/hr/processes/'.$pid.'/tasks')) ?>"><?= csrf_field() ?>
        <div class="form-grid">
          <div class="field full"><label for="nt-t">Title *</label><input id="nt-t" name="title" maxlength="190" required placeholder="e.g. Submit passport photo"></div>
          <div class="field"><label for="nt-ty">Type</label><select id="nt-ty" name="task_type"><?php foreach (PS::TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="nt-o">Owner</label><select id="nt-o" name="owner"><?php foreach (PS::OWNERS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select><div class="hint">Uploads, signatures and acknowledgements are always the employee's.</div></div>
          <div class="field"><label for="nt-d">Due date</label><input id="nt-d" type="date" name="due_date"></div>
          <div class="field"><label for="nt-doc">Company document</label><select id="nt-doc" name="company_doc_id"><option value="">None</option><?php foreach ($companyDocs ?? [] as $k => $l): ?><option value="<?= (int) $k ?>"><?= e($l) ?></option><?php endforeach ?></select><div class="hint">For sign or acknowledge tasks.</div></div>
          <div class="field full"><label for="nt-ds">Instructions</label><textarea id="nt-ds" name="description" rows="2" maxlength="1000"></textarea></div>
        </div>
        <div class="form-actions"><button class="btn primary"><?= icon('plus', 'sm') ?> Add task</button></div></form>
    </details>
    <?php endif ?>
  </div>
  <aside>
    <div class="card mb">
      <div class="flex mb"><?= avatar($p['emp_name'], $p['emp_avatar'], 'md') ?><div class="grow"><b><?= e($p['emp_name']) ?></b><div class="xs muted"><?= e($p['emp_email']) ?></div></div><?= badge($p['emp_status']) ?></div>
      <dl class="kv small">
        <dt>Template</dt><dd><?= e($p['template_name'] ?: '-') ?></dd>
        <dt>Started</dt><dd><?= e(fdate($p['started_at'])) ?><?= $p['started_by_name'] ? ' by '.e($p['started_by_name']) : '' ?></dd>
        <?php if (!$exit): ?><dt>Start date</dt><dd><?= e(fdate($p['start_date'])) ?></dd><?php endif ?>
        <dt>Manager</dt><dd><?= e($p['manager_name'] ?? '-') ?></dd>
        <?php if ($p['completed_at']): ?><dt>Completed</dt><dd><?= e(fdate($p['completed_at'])) ?></dd><?php endif ?>
        <?php if ($p['note']): ?><dt>Note</dt><dd><?= e($p['note']) ?></dd><?php endif ?>
      </dl>
    </div>
    <?php if ($hr): ?>
    <div class="card mb hr-proc-actions">
      <h3><?= icon('sliders', 'sm') ?> Manage</h3>
      <?php if ($p['status'] === 'active'): ?>
        <form method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>" data-confirm="Mark this <?= e(strtolower($kl)) ?> complete<?= $done < $total ? ' with '.($total - $done).' task(s) still open' : '' ?>?<?= $exit && (int) $p['deactivate_on_lwd'] && $p['last_working_day'] < date('Y-m-d') ? ' The account will be deactivated now.' : '' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn primary block"><?= icon('check-circle', 'sm') ?> Mark complete</button></form>
        <form method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pause"><button class="btn ghost block"><?= icon('pause', 'sm') ?> Pause</button></form>
      <?php elseif ($p['status'] === 'paused'): ?>
        <form method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn primary block"><?= icon('play', 'sm') ?> Resume</button></form>
        <form method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>" data-confirm="Mark this <?= e(strtolower($kl)) ?> complete?"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn ghost block"><?= icon('check-circle', 'sm') ?> Mark complete</button></form>
      <?php else: ?>
        <form method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button class="btn primary block"><?= icon('refresh', 'sm') ?> Reopen</button></form>
      <?php endif ?>
      <?php if (in_array($p['status'], ['active', 'paused'], true)): ?>
        <details class="hr-pop block"><summary class="btn ghost block"><?= icon('x', 'sm') ?> Cancel (turn off)</summary><form class="hr-pop-body" method="post" action="<?= e(url('/hr/processes/'.$pid.'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><label class="small b" for="cx-r">Reason *</label><textarea id="cx-r" name="reason" rows="2" maxlength="500" required placeholder="e.g. Started by mistake, joining postponed"></textarea><p class="xs muted mb0">Tasks and files are kept. You can reopen it later.</p><button class="btn sm danger">Cancel <?= e(strtolower($kl)) ?></button></form></details>
      <?php endif ?>
      <?php $files = array_sum(array_map(static fn ($l) => count(array_filter($l, static fn ($u) => (int) $u['task_id'] > 0)), $uploads)); ?>
      <?php if (!$files): ?><form method="post" action="<?= e(url('/hr/processes/'.$pid.'/remove')) ?>" data-confirm="Remove this <?= e(strtolower($kl)) ?> completely? Use this only if it was started by mistake; nothing is kept."><?= csrf_field() ?><button class="btn ghost block hr-neg"><?= icon('trash', 'sm') ?> Remove completely</button></form><p class="xs muted mb0">No files were added yet, so it can be removed without a trace (audit-logged).</p><?php else: ?><p class="xs muted mb0"><?= $files ?> file(s) added: it can be cancelled but not removed.</p><?php endif ?>
    </div>
    <?php if ($exit): ?>
    <form class="card form mb" method="post" action="<?= e(url('/hr/processes/'.$pid.'/exit')) ?>" data-confirm="Save the exit details? With automatic deactivation ticked, <?= e($p['emp_name']) ?> is signed out and deactivated at the end of the last working day."><?= csrf_field() ?>
      <h3><?= icon('log-out', 'sm') ?> Exit details</h3>
      <div class="field"><label for="ex-t">Exit type</label><select id="ex-t" name="exit_type"><?php foreach (PS::EXIT_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $p['exit_type']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="ex-l">Last working day</label><input id="ex-l" type="date" name="last_working_day" value="<?= e($p['last_working_day']) ?>" required><div class="hint">Open task due dates move with it.</div></div>
      <label class="check small"><input type="checkbox" name="deactivate_on_lwd" value="1"<?= checked((int) $p['deactivate_on_lwd']) ?>> Deactivate the account at the end of the last working day</label>
      <div class="field"><label for="ex-n">Note</label><input id="ex-n" name="note" maxlength="1000" value="<?= e($p['note']) ?>"></div>
      <div class="form-actions"><button class="btn primary sm"><?= icon('check', 'sm') ?> Save</button></div>
    </form>
    <?php endif ?>
    <?php endif ?>
    <?php if ($others): ?>
    <div class="card"><h3 class="small">Other onboarding &amp; exit records</h3><div class="list"><?php foreach ($others as $o): ?><a class="list-item" href="<?= e(url('/hr/processes/'.$o['id'])) ?>"><span class="pill pill-<?= e(PS::STATUS[$o['status']][1]) ?>"><?= e(PS::STATUS[$o['status']][0]) ?></span><div class="grow small"><b><?= e(PS::KINDS[$o['kind']]) ?></b><div class="xs muted"><?= e(fdate($o['started_at'])) ?></div></div><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?></div></div>
    <?php endif ?>
  </aside>
</div>
