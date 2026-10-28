<?php
use Modules\Ai\Services\AiClient;
$u = user();
$first = explode(' ', $u['name'])[0];
$ringCls = $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '');
$clientOpts = static function (bool $optional) use ($clients): string {
  $h = '<option value="">'.($optional ? 'No client (optional)' : 'Choose a client…').'</option>';
  foreach ($clients as $c) { $h .= '<option value="'.(int) $c['id'].'">'.e($c['name'].($c['company'] ? ' — '.$c['company'] : '')).'</option>'; }
  return $h; };
$modalHead = static fn (string $key, array $t): string => '<div class="modal-head"><div class="flex"><span class="ai-ic bg-'.e($t[2]).'">'.icon($t[3]).'</span><div><h3>'.e($t[0]).'</h3><div class="xs muted">'.e($t[1]).'</div></div></div><button type="button" class="btn icon ghost sm" data-close aria-label="Close">'.icon('x', 'sm').'</button></div>';
$result = static fn (string $extra = ''): string => '<div class="ai-result hide" data-ai-result><div class="ai-msg"><span class="ai-avatar">'.icon('bot', 'sm').'</span><div class="ai-bubble"><div class="ai-meta"><span><b>Assistant</b> · <span data-ai-meta>thinking…</span></span><span class="pill pill-lav">Draft · review before use</span></div><div class="ai-out" data-ai-out aria-live="polite"></div><div class="ai-acts"><button type="button" class="btn sm soft" data-copy="" data-ai-copy disabled>'.icon('copy', 'sm').' Copy</button><button type="button" class="btn sm ghost" data-ai-regen>'.icon('refresh', 'sm').' Regenerate</button></div>'.$extra.'</div></div></div>';
?>
<div class="card hero ai-hero mb">
  <div>
    <span class="pill pill-lav"><?= icon('sparkles', 'sm') ?> <?= e(AiClient::providers()[AiClient::provider()] ?? 'AI') ?> · <?= e(AiClient::model()) ?></span>
    <h1>Hi <?= e($first) ?>, how can I help today?</h1>
    <p class="muted mb0">Summaries, drafts, reports and smart search over the records you already have access to. Everything I produce is a draft — nothing is saved or sent until you approve it.</p>
    <?php if (can('ai.search')): ?>
    <form class="ai-ask" method="get" action="<?= e(url('/ai/search')) ?>" role="search"><?= icon('search', 'sm') ?><input type="search" name="q" maxlength="300" placeholder="Ask anything, e.g. “overdue invoices for Acme”" aria-label="Ask your workspace"><button class="btn primary sm"><?= icon('sparkles', 'sm') ?> Ask</button></form>
    <div class="ai-chips"><?php foreach (['My overdue tasks', 'Active clients in retail', 'Projects due this month', 'Unpaid invoices'] as $ex): ?><a href="<?= e(url('/ai/search').'?q='.rawurlencode($ex)) ?>"><?= e($ex) ?></a><?php endforeach ?></div>
    <?php endif ?>
  </div>
  <?= illus('ai-robot') ?>
</div>
<?php if (!$ready): ?><div class="alert warn"><?= icon('alert', 'sm') ?><div>The AI provider has no API key yet, so tools will return an error.<?php if ($u['role_slug'] === 'super_admin'): ?> <a href="<?= e(url('/ai/settings')) ?>"><b>Add a key in AI settings</b></a>.<?php else: ?> Please ask your administrator to configure it.<?php endif ?></div></div><?php endif ?>
<div class="ai-stats">
  <div class="card ai-meter">
    <span class="ring <?= $ringCls ?>" style="--v:<?= (int) $pct ?>" data-label="<?= $budget ? (int) $pct.'%' : '∞' ?>"></span>
    <div><div class="xs muted b">TEAM USAGE · <?= e(strtoupper(date('F'))) ?></div><b style="font-size:1.35rem"><?= e(number_format($used)) ?> <span class="small muted">/ <?= $budget ? e(number_format($budget)) : 'no limit' ?> tokens</span></b>
      <div class="xs muted"><?= $daily ? 'You have used '.(int) $today.' of '.(int) $daily.' requests today.' : 'You have made '.(int) $today.' requests today.' ?></div></div>
  </div>
  <a class="card ai-stat" href="<?= e(url('/ai/actions')) ?>"><span class="ai-ic bg-lemon"><?= icon('clipboard') ?></span><div><b><?= (int) $pending ?></b><span class="small muted">Pending approvals</span></div></a>
  <div class="card ai-stat"><span class="ai-ic bg-mint"><?= icon('shield') ?></span><div><b class="small" style="font-size:1rem"><?= setting('ai.redact_pii', '1') === '1' ? 'Privacy guard on' : 'Privacy guard off' ?></b><span class="xs muted">Only records you can see are shared<?= setting('ai.redact_pii', '1') === '1' ? '; emails & numbers are masked' : '' ?>.</span></div></div>
</div>
<?php if ($tools): ?>
<div class="ai-section"><div><h2>AI tools</h2><p class="small muted mb0">Pick a tool to get started.</p></div><a class="btn ghost sm" href="<?= e(url('/ai/actions')) ?>"><?= icon('history', 'sm') ?> My AI actions</a></div>
<div class="ai-tools mb">
  <?php foreach ($tools as $key => $t): ?>
  <button type="button" class="ai-tool soft-<?= e($t[2]) ?>" data-modal="ai-m-<?= e($key) ?>"><span class="ai-ic bg-<?= e($t[2]) ?>"><?= icon($t[3]) ?></span><h3><?= e($t[0]) ?></h3><p><?= e($t[1]) ?></p><span class="go">Open <?= icon('arrow-right', 'sm') ?></span></button>
  <?php endforeach ?>
  <?php if (can('ai.search')): ?><a class="ai-tool soft-mint" href="<?= e(url('/ai/search')) ?>"><span class="ai-ic bg-mint"><?= icon('search') ?></span><h3>Ask your workspace</h3><p>Plain-English search across clients, projects, tasks, invoices and people.</p><span class="go">Search <?= icon('arrow-right', 'sm') ?></span></a><?php endif ?>
</div>
<?php elseif (can('ai.search')): ?>
<div class="card"><?= empty_state('Ask your workspace', 'Use the search box above to find clients, projects, tasks and more in plain English.', 'ai-robot') ?></div>
<?php endif ?>

<?php if (isset($tools['client_summary'])): $t = $tools['client_summary']; ?>
<dialog class="modal wide ai-modal" id="ai-m-client_summary"><?= $modalHead('client_summary', $t) ?><div class="modal-body">
  <form class="form" method="post" action="<?= e(url('/ai/run/client_summary')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="cs-client">Client</label><select id="cs-client" name="client_id" required><?= $clientOpts(false) ?></select><div class="hint">Uses the profile, last 30 journey entries<?= can('crm.finance.view') ? ' and open invoices' : '' ?>.</div></div>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Summarise</button></div>
  </form><?= $result() ?></div></dialog>
<?php endif ?>

<?php if (isset($tools['meeting'])): $t = $tools['meeting']; ?>
<dialog class="modal wide ai-modal" id="ai-m-meeting"><?= $modalHead('meeting', $t) ?><div class="modal-body">
  <form class="form" method="post" action="<?= e(url('/ai/run/meeting')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="mt-notes">Meeting notes</label><textarea id="mt-notes" name="notes" rows="7" minlength="20" maxlength="12000" required placeholder="Paste your raw notes or transcript…"></textarea></div>
    <?php if ($clients): ?><div class="field"><label for="mt-client">Client</label><select id="mt-client" name="client_id" data-ai-client><?= $clientOpts(true) ?></select><div class="hint">Pick a client to be able to save the summary to their journey.</div></div><?php endif ?>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Summarise meeting</button></div>
  </form>
  <?= $result($canManageClients ? '<form class="ai-followup form hide" method="post" action="'.e(url('/ai/meeting/save')).'" data-ai-save data-confirm="Add this summary to the client journey?">'.csrf_field().'<input type="hidden" name="client_id" data-ai-fill="client_id"><div class="ai-note">'.icon('info', 'sm').' Review and edit before saving. It is only added when you click save.</div><div class="field"><label>Title</label><input type="text" name="title" maxlength="190" required value="Meeting summary – '.e(date('d M Y')).'"></div><div class="field"><label>Summary</label><textarea name="body" rows="8" maxlength="12000" required data-ai-fill="text"></textarea></div><label class="check"><input type="checkbox" name="is_internal" value="1"> Internal note (hidden from client-facing views)</label><div class="form-actions"><button class="btn mint" type="submit">'.icon('check', 'sm').' Save to client journey</button></div></form>' : '') ?>
</div></dialog>
<?php endif ?>

<?php if (isset($tools['email'])): $t = $tools['email']; ?>
<dialog class="modal wide ai-modal" id="ai-m-email"><?= $modalHead('email', $t) ?><div class="modal-body">
  <form class="form" method="post" action="<?= e(url('/ai/run/email')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="em-purpose">What is the email about?</label><textarea id="em-purpose" name="purpose" rows="3" minlength="5" maxlength="1000" required placeholder="e.g. Share the revised timeline for the website launch and ask for approval by Friday"></textarea></div>
    <div class="form-grid">
      <div class="field"><label for="em-rec">Recipient context</label><input id="em-rec" type="text" name="recipient" maxlength="2000" placeholder="e.g. Priya, marketing head, prefers short emails"></div>
      <div class="field"><label for="em-tone">Tone</label><select id="em-tone" name="tone"><?php foreach (Modules\Ai\Controllers\AssistantController::TONES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
      <?php if ($clients): ?><div class="field full"><label for="em-client">Client (optional)</label><select id="em-client" name="client_id"><?= $clientOpts(true) ?></select><div class="hint">Adds the client name and last 5 interactions as context.</div></div><?php endif ?>
    </div>
    <div class="ai-note"><?= icon('lock', 'sm') ?> Drafts are never sent automatically — copy it into your mail app.</div>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Draft email</button></div>
  </form><?= $result() ?></div></dialog>
<?php endif ?>

<?php if (isset($tools['followups'])): $t = $tools['followups']; ?>
<dialog class="modal wide ai-modal" id="ai-m-followups"><?= $modalHead('followups', $t) ?><div class="modal-body">
  <form class="form" method="post" action="<?= e(url('/ai/run/followups')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="fu-client">Client</label><select id="fu-client" name="client_id" required><?= $clientOpts(false) ?></select></div>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Suggest follow-ups</button></div>
  </form><?= $result() ?></div></dialog>
<?php endif ?>

<?php if (isset($tools['tasks'])): $t = $tools['tasks']; ?>
<dialog class="modal wide ai-modal" id="ai-m-tasks"><?= $modalHead('tasks', $t) ?><div class="modal-body">
  <?php if (!$taskProjects): ?><?= empty_state('No open projects', 'You need an active project you can manage tasks in.', 'kanban-board') ?><?php else: ?>
  <form class="form" method="post" action="<?= e(url('/ai/run/tasks')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="tk-project">Project</label><select id="tk-project" name="project_id" required><option value="">Choose a project…</option><?php foreach ($taskProjects as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="tk-notes">Text to turn into tasks</label><textarea id="tk-notes" name="notes" rows="7" minlength="10" maxlength="8000" required placeholder="Paste an email, brief, meeting notes or a list…"></textarea></div>
    <div class="ai-note"><?= icon('shield', 'sm') ?> Nothing is created yet: you will review, edit and approve each task on the next screen.</div>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Suggest tasks</button></div>
  </form><?= $result() ?><?php endif ?></div></dialog>
<?php endif ?>

<?php if (isset($tools['project_report'])): $t = $tools['project_report']; ?>
<dialog class="modal wide ai-modal" id="ai-m-project_report"><?= $modalHead('project_report', $t) ?><div class="modal-body">
  <form class="form" method="post" action="<?= e(url('/ai/run/project_report')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="pr-project">Project</label><select id="pr-project" name="project_id" required><option value="">Choose a project…</option><?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e(str_replace('_', ' ', $p['status'])) ?>)</option><?php endforeach ?></select></div>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Build report</button></div>
  </form><?= $result() ?></div></dialog>
<?php endif ?>

<?php if (isset($tools['reminder'])): $t = $tools['reminder']; ?>
<dialog class="modal wide ai-modal" id="ai-m-reminder"><?= $modalHead('reminder', $t) ?><div class="modal-body">
  <?php if (!$invoices): ?><?= empty_state('No overdue invoices', 'Great news — nothing is overdue among the invoices you can see.', 'money-wallet') ?><?php else: ?>
  <form class="form" method="post" action="<?= e(url('/ai/run/reminder')) ?>" data-ai-tool><?= csrf_field() ?>
    <div class="field"><label for="rm-inv">Overdue invoice</label><select id="rm-inv" name="invoice_id" required><option value="">Choose an invoice…</option><?php foreach ($invoices as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['number'].' — '.$i['client'].' — '.money((float) $i['total'] - (float) $i['amount_paid']).' — due '.fdate($i['due_date'])) ?></option><?php endforeach ?></select></div>
    <div class="form-actions"><button class="btn primary" type="submit"><?= icon('sparkles', 'sm') ?> Draft reminder</button></div>
  </form>
  <?= $result($canSend ? '<form class="ai-followup form hide" method="post" action="'.e(url('/ai/reminder/queue')).'" data-ai-queue data-confirm="Queue this email to the client now?">'.csrf_field().'<input type="hidden" name="invoice_id" data-ai-fill="invoice_id"><div class="ai-note">'.icon('mail', 'sm').' To: <b data-ai-fill-text="to"></b></div><div class="field"><label>Subject</label><input type="text" name="subject" maxlength="250" required data-ai-fill="subject"></div><div class="field"><label>Message</label><textarea name="body" rows="9" maxlength="10000" required data-ai-fill="body"></textarea></div><label class="check"><input type="checkbox" name="confirm" value="1" required> I have reviewed this email and want to send it to the client</label><div class="form-actions"><button class="btn primary" type="submit">'.icon('send', 'sm').' Queue email to client</button></div></form><div class="ai-note hide mt" data-ai-noemail>'.icon('alert', 'sm').' This client has no email address on file, so the reminder can only be copied.</div>' : '') ?>
  <?php endif ?>
</div></dialog>
<?php endif ?>
