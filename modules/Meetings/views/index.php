<?php
use Modules\Meetings\Services\{Inbox, Recap, Schedule, Ui};
$tabs = ['upcoming' => ['Upcoming', 'calendar', $counts['upcoming']], 'sort' => ['To review / To sort', 'flag', $counts['sort'] + $counts['review']], 'recent' => ['Recent', 'history', null], 'connections' => ['Connections', 'link', null]];
$canLog = can('crm.clients.manage');
$when = static fn (array $r) => $r['meeting_at'] ? fdatetime($r['meeting_at']) : 'Added '.ago($r['created_at']);
$src = static fn (array $r) => Recap::label($r['tool'], $r['source']);
$actions = ($canPlan ? '<button type="button" class="btn ghost" data-modal="mt-upload">'.icon('upload', 'sm').' Upload transcript</button>'.Ui::newButton() : '<button type="button" class="btn primary" data-modal="mt-upload">'.icon('upload', 'sm').' Upload transcript</button>').\Modules\Admin\Services\SettingsSchema::settingsLink('meetings');
?>
<div class="mt">
<?= partial('partials/page_head', ['title' => 'Meetings', 'subtitle' => 'Transcripts and notes from any meeting platform become client logs, summarised by Goraiya. Niyanta never records audio.', 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <a class="tile bg-sky" href="<?= e(url('/meetings?tab=upcoming')) ?>"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num"><?= (int) $counts['upcoming'] ?></div><div class="lbl">Upcoming meetings</div></div></a>
  <a class="tile bg-lemon" href="<?= e(url('/meetings?tab=sort')) ?>"><span class="tile-ic"><?= icon('flag') ?></span><div><div class="num"><?= (int) $counts['sort'] ?></div><div class="lbl">To sort: pick a client or project</div></div></a>
  <a class="tile bg-lav" href="<?= e(url('/meetings?tab=sort#review')) ?>"><span class="tile-ic"><?= icon('sparkles') ?></span><div><div class="num"><?= (int) $counts['review'] ?></div><div class="lbl">Summaries to review</div></div></a>
  <a class="tile bg-mint" href="<?= e(url('/meetings?tab=recent')) ?>"><span class="tile-ic"><?= icon('message') ?></span><div><div class="num"><?= (int) $counts['total'] ?></div><div class="lbl">Meetings you can see</div></div></a>
</div>
<?php if (!$ai): ?><div class="alert info"><?= icon('info') ?><div>AI summaries are off or no AI model is set up, so transcripts are stored and attached to logs without a summary.<?= \Core\Auth::isSuperAdmin() ? ' <a href="'.e(url('/ai/settings')).'">Set up the AI assistant</a>.' : '' ?></div></div><?php endif ?>
<nav class="tabs mb" aria-label="Meetings sections"><?php foreach ($tabs as $k => [$l, $ic, $n]): ?><a href="<?= e(url('/meetings?tab='.$k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?><?php if ($n): ?> <span class="pill pill-lemon"><?= (int) $n ?></span><?php endif ?></a><?php endforeach ?></nav>

<?php if ($tab === 'upcoming'): ?>
<div class="grid g-main">
  <section class="card">
    <div class="card-head"><div><h3>Upcoming meetings</h3><p class="xs muted mb0">Planned meetings of your clients and projects, and the ones you are invited to.</p></div><?= $canPlan ? Ui::newButton([], 'btn sm soft') : '' ?></div>
    <?= partial('meetings::partials/upcoming', ['rows' => $upcoming, 'plan' => $canPlan, 'compact' => false]) ?>
  </section>
  <section class="card">
    <div class="card-head"><div><h3>Waiting for notes</h3><p class="xs muted mb0">Held in the last 30 days without a transcript or notes.</p></div><span class="crm-count"><?= count($needsNotes) ?></span></div>
    <?php if (!$needsNotes): ?><p class="small muted mb0">All caught up.</p><?php else: ?>
    <div class="list"><?php foreach ($needsNotes as $r): ?><div class="list-item"><span class="mt-ic"><?= icon('file-text', 'sm') ?></span><div class="grow"><a class="b small" href="<?= e(url('/meetings/event/'.(int) $r['id'])) ?>"><?= e($r['title']) ?></a><div class="meta"><?= e(fdate($r['occurred_at'], 'D d M')) ?>, <?= e(ftime($r['occurred_at'])) ?> · <?= e(Schedule::where($r)) ?></div></div><a class="btn sm soft" href="<?= e(url('/meetings/event/'.(int) $r['id'].'#notes')) ?>"><?= icon('plus', 'sm') ?> Add notes</a></div><?php endforeach ?></div>
    <?php endif ?>
  </section>
</div>

<?php elseif ($tab === 'sort'): ?>
<div class="grid g-main">
  <section class="card">
    <div class="card-head"><div><h3>To sort</h3><p class="xs muted mb0">Notes that arrived without a certain client or project. Check the suggestion and save the log.</p></div><span class="crm-count"><?= count($toSort) ?></span></div>
    <?php if (!$toSort): ?><?= empty_state('Nothing to sort', 'Meetings matched with high confidence are logged automatically. Uploads and e-mails that need a decision appear here.', 'mail-envelope') ?><?php else: ?>
    <div class="mt-list">
      <?php foreach ($toSort as $r): $why = json_decode((string) $r['reasons'], true) ?: []; ?>
      <article class="mt-item">
        <div class="mt-item-h"><span class="mt-ic"><?= icon('message', 'sm') ?></span><div class="grow"><a class="b" href="<?= e(url('/meetings/'.$r['id'])) ?>"><?= e($r['title']) ?></a><div class="xs muted"><?= e($when($r)) ?> · <?= e($src($r)) ?><?= $r['owner_name'] ? ' · '.e($r['owner_name']) : '' ?><?= $r['duration_min'] ? ' · '.(int) $r['duration_min'].' min' : '' ?></div></div><?= Ui::confidence($r['confidence']) ?></div>
        <?php if ($r['client_name'] || $r['project_name'] || $why): ?><div class="mt-suggest"><?= icon('target', 'sm') ?> <span>Suggested: <b><?= e(implode(' › ', array_filter([$r['client_name'], $r['project_name']])) ?: 'nothing yet') ?></b><?= $why ? ' <span class="muted">('.e(implode('; ', $why)).')</span>' : '' ?></span></div><?php endif ?>
        <?php if ($canLog): $plans = $planned[(int) $r['id']] ?? []; $sugg = implode(' › ', array_filter([$r['client_name'], $r['project_name']])); ?>
        <?php if ($plans || $sugg): ?><div class="mt-quick"><span class="xs muted">Quick assign</span>
          <?php foreach ($plans as $pl): ?><form method="post" action="<?= e(url('/meetings/'.$r['id'].'/assign')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="list"><input type="hidden" name="activity_id" value="<?= (int) $pl['id'] ?>"><button class="btn sm primary" title="Attach to this planned meeting"><?= icon('calendar', 'sm') ?> <?= e(str_limit($pl['title'], 32)) ?> · <?= e(fdate($pl['occurred_at'], 'd M')) ?> <?= e(ftime($pl['occurred_at'])) ?></button></form><?php endforeach ?>
          <?php if ($sugg): ?><form method="post" action="<?= e(url('/meetings/'.$r['id'].'/assign')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="list"><input type="hidden" name="client_id" value="<?= (int) $r['client_id'] ?: '' ?>"><input type="hidden" name="project_id" value="<?= (int) $r['project_id'] ?: '' ?>"><button class="btn sm <?= $plans ? 'soft' : 'primary' ?>"><?= icon('check', 'sm') ?> Log on <?= e(str_limit($sugg, 40)) ?></button></form><?php endif ?>
        </div><?php endif ?>
        <form method="post" action="<?= e(url('/meetings/'.$r['id'].'/assign')) ?>" class="mt-assign form" data-mt-assign><?= csrf_field() ?><input type="hidden" name="return" value="list">
          <div class="form-grid"><?= partial('meetings::partials/assign', ['clientOptions' => $clientOptions, 'projectRows' => $projectRows, 'selClient' => $r['client_id'], 'selProject' => $r['project_id'], 'prefix' => 'mt'.$r['id']]) ?></div>
          <div class="flex wrap"><button class="btn sm <?= $plans || $sugg ? 'soft' : 'primary' ?>"><?= icon('check', 'sm') ?> Save log</button><a class="btn sm ghost" href="<?= e(url('/meetings/'.$r['id'])) ?>"><?= icon('eye', 'sm') ?> Open</a></div>
        </form>
        <form method="post" action="<?= e(url('/meetings/'.$r['id'].'/dismiss')) ?>" class="mt-dismiss"><?= csrf_field() ?><button class="btn sm ghost" title="Not a client meeting"><?= icon('x', 'sm') ?> Dismiss</button></form>
        <?php endif ?>
      </article>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </section>
  <section class="card" id="review">
    <div class="card-head"><div><h3>Ready to review</h3><p class="xs muted mb0">New AI summaries in your logs. Check them and turn action items into tasks.</p></div></div>
    <?php if (!$toReview): ?><p class="small muted mb0">No new summaries.</p><?php else: ?>
    <div class="list"><?php foreach ($toReview as $r): ?><a class="list-item" href="<?= e(url('/meetings/'.$r['id'])) ?>"><span class="mt-ic"><?= icon($r['summary_status'] === 'failed' ? 'alert' : 'sparkles', 'sm') ?></span><div class="grow"><b class="small"><?= e($r['title']) ?></b><div class="meta"><?= e(implode(' › ', array_filter([$r['client_name'], $r['project_name']]))) ?> · <?= e($when($r)) ?></div></div><?= Ui::summaryBadge($r['summary_status']) ?></a><?php endforeach ?></div>
    <?php endif ?>
  </section>
</div>

<?php elseif ($tab === 'recent'): ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/meetings')) ?>"><input type="hidden" name="tab" value="recent">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search meeting titles" aria-label="Search meetings">
    <select name="source" data-autosubmit aria-label="Source"><option value="">All sources</option><?php foreach (['upload', 'paste', 'email', 'fireflies'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $source) ?>><?= e(Recap::SOURCES[$s]) ?></option><?php endforeach ?></select>
    <button class="btn ghost"><?= icon('search', 'sm') ?> Filter</button>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No meetings yet', 'Upload a transcript, forward a recap to the notes address or connect Fireflies.', 'calendar-person', '<button type="button" class="btn primary" data-modal="mt-upload">'.icon('upload', 'sm').' Upload transcript</button>') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Meeting</th><th>Client › project</th><th>Source</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r): ?><tr>
      <td><a class="b" href="<?= e(url('/meetings/'.$r['id'])) ?>"><?= e(str_limit($r['title'], 70)) ?></a><div class="xs muted"><?= e($when($r)) ?><?= $r['duration_min'] ? ' · '.(int) $r['duration_min'].' min' : '' ?></div></td>
      <td class="small"><?= e(implode(' › ', array_filter([$r['client_name'], $r['project_name']])) ?: '-') ?></td>
      <td class="small"><?= e($src($r)) ?></td>
      <td><?= $r['status'] === 'linked' ? '<span class="pill pill-mint">Logged</span>' : ($r['status'] === 'dismissed' ? '<span class="pill pill-gray">Dismissed</span>' : '<span class="pill pill-lemon">To sort</span>') ?> <?= Ui::summaryBadge($r['summary_status']) ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</section>

<?php else: ?>
<div class="grid g2">
  <section class="card">
    <div class="card-head"><div class="flex"><span class="mt-ic"><?= icon('zap', 'sm') ?></span><h3>Fireflies</h3></div><?= $conn ? '<span class="pill pill-mint">Connected</span>' : '<span class="pill pill-gray">Not connected</span>' ?></div>
    <p class="small muted">Fireflies' free plan includes an API. Connect your own key and new meetings arrive every 30 minutes (the free plan allows about 50 requests a day), matched to clients and projects like uploads.</p>
    <?php if ($conn): ?>
      <dl class="kv"><dt>Last sync</dt><dd><?= $conn['last_sync_at'] ? e(ago($conn['last_sync_at'])) : 'Not yet' ?></dd><dt>Status</dt><dd><?= e($conn['last_status'] ?: 'Waiting for the first sync') ?></dd><?php if ($conn['backoff_until'] && strtotime($conn['backoff_until']) > time()): ?><dt>Paused until</dt><dd><?= e(fdatetime($conn['backoff_until'])) ?> (rate limited)</dd><?php endif ?></dl>
      <div class="flex wrap mt"><form method="post" action="<?= e(url('/meetings/connections/fireflies/fetch')) ?>"><?= csrf_field() ?><button class="btn sm primary"><?= icon('refresh', 'sm') ?> Fetch now</button></form><form method="post" action="<?= e(url('/meetings/connections/fireflies/delete')) ?>" data-confirm="Disconnect Fireflies? Meetings already imported stay."><?= csrf_field() ?><button class="btn sm ghost"><?= icon('x', 'sm') ?> Disconnect</button></form></div>
    <?php endif ?>
    <form method="post" action="<?= e(url('/meetings/connections/fireflies')) ?>" class="form mt"><?= csrf_field() ?>
      <div class="field"><label for="ff-key"><?= $conn ? 'Replace API key' : 'API key' ?></label><input id="ff-key" type="password" name="api_key" autocomplete="new-password" placeholder="Fireflies › Integrations › Fireflies API" required><?= error_for('api_key') ?><div class="hint">Stored encrypted. Only used to read your transcripts.</div></div>
      <button class="btn sm soft"><?= icon('link', 'sm') ?> <?= $conn ? 'Save new key' : 'Connect Fireflies' ?></button>
    </form>
    <?php if ($manager): ?><p class="xs muted mt mb0"><?= icon('building', 'sm') ?> Company key: <?= $company ? 'set'.(!empty($company['last_status']) ? ', '.e($company['last_status']) : '') : 'not set' ?><?= \Core\Auth::isSuperAdmin() ? ' · <a href="'.e(url('/admin/settings/meetings')).'">Settings › Meeting notes</a>' : '' ?></p><?php endif ?>
  </section>
  <section class="card">
    <div class="card-head"><div class="flex"><span class="mt-ic"><?= icon('mail', 'sm') ?></span><h3>Notes inbox</h3></div><?= $inboxOn ? '<span class="pill pill-mint">On</span>' : '<span class="pill pill-gray">Not set up</span>' ?></div>
    <?php if ($inbox): ?>
      <p class="small muted">Invite Niyanta: add the address as a guest in the meeting invite, or forward / auto-share notes and recaps to it. Use a project or client address to log straight onto it.</p>
      <div class="mt-addr"><code class="mono grow"><?= e($inbox) ?></code><button type="button" class="btn icon sm ghost" data-copy="<?= e($inbox) ?>" title="Copy address" aria-label="Copy address"><?= icon('copy', 'sm') ?></button></div>
      <p class="xs muted">Every project page shows its own address (like <code><?= e((string) Inbox::plusAddress('p', 12)) ?></code>) and every client page one like <code><?= e((string) Inbox::plusAddress('c', 5)) ?></code>.</p>
    <?php else: ?><p class="small muted">A Super Admin sets up the notes mailbox under Settings › Meeting notes. Until then, upload or paste transcripts.</p><?php endif ?>
    <?php if ($manager): ?>
      <?php if ($inboxState): ?><div class="alert <?= !empty($inboxState['ok']) ? 'ok' : 'warn' ?>"><?= icon(!empty($inboxState['ok']) ? 'check-circle' : 'alert') ?><div>Last check <?= e(ago($inboxState['at'] ?? null)) ?>: <?= e($inboxState['status'] ?? '') ?></div></div><?php endif ?>
      <div class="chips mb"><?php foreach (['accepted' => 'mint', 'duplicate' => 'sky', 'dropped' => 'red', 'ignored' => 'gray'] as $o => $c): ?><span class="pill pill-<?= $c ?>"><?= (int) ($stats[$o] ?? 0) ?> <?= e($o) ?></span><?php endforeach ?><span class="xs muted">last 7 days</span></div>
      <?php if ($inboxOn): ?><form method="post" action="<?= e(url('/meetings/inbox/check')) ?>"><?= csrf_field() ?><button class="btn sm soft"><?= icon('refresh', 'sm') ?> Check now</button></form><?php endif ?>
      <?php if ($dropped): ?><details class="mt-drop-log mt"><summary class="small">Recently dropped senders (<?= count($dropped) ?>)</summary><ul class="xs"><?php foreach ($dropped as $d): ?><li><b><?= e($d['from_email'] ?: 'unknown') ?></b> · <?= e(str_limit((string) $d['subject'], 50)) ?> · <?= e($d['reason']) ?> · <?= e(ago($d['created_at'])) ?></li><?php endforeach ?></ul></details><?php endif ?>
    <?php endif ?>
  </section>
  <section class="card">
    <div class="card-head"><div class="flex"><span class="mt-ic"><?= icon('send', 'sm') ?></span><h3>Other meeting tools</h3></div></div>
    <ul class="mt-tools small">
      <li><b>Google Meet + Gemini notes:</b> turn on "Take notes for me"; Gemini e-mails the notes to the organiser. Forward them (or a filter rule) to the notes address, or upload the Doc as .docx.</li>
      <li><b>Zoom AI Companion:</b> share the meeting summary e-mail with the notes address, or upload the .vtt transcript.</li>
      <li><b>Microsoft Teams:</b> download the transcript (.vtt or .docx) and upload it, or forward the recap.</li>
      <li><b>Otter, tl;dv, Tactiq, Read.ai:</b> no free API, so share or forward their recap e-mails to the notes address (their senders are allowed by default), or upload an export.</li>
      <li><b>Fireflies:</b> connect a key above, or invite the notes address so its recap e-mails arrive too (duplicates are merged).</li>
    </ul>
  </section>
  <section class="card">
    <div class="card-head"><div class="flex"><span class="mt-ic"><?= icon('shield', 'sm') ?></span><h3>Privacy</h3></div></div>
    <p class="small mb0"><?= e(Ui::PRIVACY) ?> Only clients and projects you can see are suggested, and only people who can see a client see its meetings.</p>
  </section>
</div>
<?php endif ?>

<dialog class="modal" id="mt-upload" aria-labelledby="mt-upload-title">
  <form method="post" action="<?= e(url('/meetings/upload')) ?>" enctype="multipart/form-data" class="crm"><?= csrf_field() ?>
    <div class="modal-head"><h3 id="mt-upload-title">Upload transcript</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <?= Ui::attachSection(['standalone' => true]) ?>
      <div class="field"><label for="mt-u-title">Title</label><input id="mt-u-title" type="text" name="title" maxlength="190" placeholder="Detected from the notes"></div>
      <div class="form-grid">
        <div class="field full"><label for="mt-u-when">When</label><input id="mt-u-when" type="datetime-local" name="occurred_at"><div class="hint">Detected from the notes when possible.</div></div>
        <?php if ($canLog): ?><?= partial('meetings::partials/assign', ['clientOptions' => $clientOptions, 'projectRows' => $projectRows, 'prefix' => 'mt-u']) ?><?php endif ?>
      </div>
      <p class="xs muted"><?= $canLog ? 'With a client or project the meeting is logged right away; without one it waits in To sort.' : 'The meeting waits in To sort until someone who manages the client logs it.' ?></p>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save meeting</button></div>
    </div>
  </form>
</dialog>
<?= $newDialog ?>
</div>
