<?php
use Modules\Crm\Controllers\JourneyController;
$cid = (int) $c['id'];
$label = $c['company'] ?: $c['name'];
$manage = can('crm.clients.manage');
$finManage = can('crm.finance.manage');
$wa = $c['whatsapp'] ?: $c['phone'];
$types = JourneyController::TYPES;
$statuses = ['lead' => 'Lead', 'active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'];
$errs = errors();
$base = '/clients/'.$cid;
$openFu = count($followUps);
$journeyTotal = array_sum($typeCounts);
?>
<div class="crumbs"><a href="<?= e(url('/clients')) ?>">Clients</a><span>/</span><span><?= e($label) ?></span></div>
<div class="card crm-hero mb">
  <div class="crm-hero-main">
    <?= avatar($label, null, 'xl') ?>
    <div class="grow">
      <div class="flex wrap"><h1 class="mb0"><?= e($label) ?></h1><?= badge($c['status'], $statuses[$c['status']] ?? null) ?></div>
      <p class="muted mb0"><?= e(implode(' · ', array_filter([$c['company'] ? $c['name'] : null, $c['industry'], trim(($c['city'] ?? '').($c['city'] && $c['country'] ? ', ' : '').($c['country'] ?? '')) ?: null, $c['source'] ? 'via '.$c['source'] : null]))) ?></p>
      <?php if ($tags): ?><div class="chips mt"><?php foreach ($tags as $t): ?><a class="pill pill-<?= e($t['color']) ?>" href="<?= e(url('/clients?tag='.(int) $t['id'])) ?>"><?= icon('tag', 'sm') ?> <?= e($t['name']) ?></a><?php endforeach ?></div><?php endif ?>
      <div class="quick mt">
        <?php if ($wa): ?><a class="bg-mint" href="<?= e(wa_link($wa, 'Hi '.$c['name'].', ')) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'sm') ?> WhatsApp</a><?php endif ?>
        <?php if ($c['phone']): ?><a class="bg-sky" href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['phone'])) ?>"><?= icon('phone', 'sm') ?> Call</a><?php endif ?>
        <?php if ($c['email']): ?><a class="bg-lav" href="mailto:<?= e($c['email']) ?>"><?= icon('mail', 'sm') ?> Email</a><?php endif ?>
        <?php if ($c['website']): ?><a class="bg-lemon" href="<?= e($c['website']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('globe', 'sm') ?> Website</a><?php endif ?>
      </div>
    </div>
  </div>
  <div class="crm-hero-side">
    <?= illus('clients-handshake', 'crm-hero-illus') ?>
    <div class="flex wrap" style="justify-content:flex-end">
      <?php if ($manage): ?><button type="button" class="btn primary sm" data-modal="activity-modal" data-activity-new><?= icon('plus', 'sm') ?> Log activity</button><a class="btn ghost sm" href="<?= e(url($base.'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit</a><?php endif ?>
      <details class="dd"><summary class="btn icon sm ghost" aria-label="More actions"><?= icon('more', 'sm') ?></summary><div class="dd-menu">
        <?php if ($finManage): ?><a href="<?= e(url('/invoices/create?client_id='.$cid)) ?>"><?= icon('receipt', 'sm') ?> New invoice</a><a href="<?= e(url('/quotations/create?client_id='.$cid)) ?>"><?= icon('file-text', 'sm') ?> New quotation</a><?php endif ?>
        <?php if (can('crm.documents.manage')): ?><a href="#documents" data-tab-link="documents"><?= icon('upload', 'sm') ?> Upload document</a><?php endif ?>
        <?php if (can('crm.clients.delete')): ?><form method="post" action="<?= e(url($base.'/delete')) ?>" data-confirm="Delete this client with all contacts, journey, documents and quotations? This cannot be undone."><?= csrf_field() ?><button class="crm-danger"><?= icon('trash', 'sm') ?> Delete client</button></form><?php endif ?>
      </div></details>
    </div>
  </div>
</div>
<?php if ($errs): ?><div class="alert error"><?= icon('alert') ?><div><?php foreach ($errs as $er): ?><div><?= e($er) ?></div><?php endforeach ?></div></div><?php endif ?>
<div class="grid g4 mb">
  <?php if ($fin): ?>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('receipt') ?></span><div><div class="num crm-num"><?= e(\Modules\Crm\Services\Hooks::compact((float) $sum['invoiced'])) ?></div><div class="lbl">Invoiced</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num crm-num"><?= e(\Modules\Crm\Services\Hooks::compact((float) $sum['paid'])) ?></div><div class="lbl">Paid</div></div></div>
  <div class="tile dark"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num crm-num"><?= e(\Modules\Crm\Services\Hooks::compact((float) $sum['outstanding'])) ?></div><div class="lbl">Outstanding<?= (int) $sum['overdue'] ? ' · '.(int) $sum['overdue'].' overdue' : '' ?></div></div></div>
  <?php else: ?>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= count($contacts) ?></div><div class="lbl">Contacts</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('activity') ?></span><div><div class="num"><?= (int) $journeyTotal ?></div><div class="lbl">Journey entries</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('paperclip') ?></span><div><div class="num"><?= count($docs) ?></div><div class="lbl">Documents</div></div></div>
  <?php endif ?>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= $openFu ?></div><div class="lbl">Open follow-ups</div></div></div>
</div>
<div class="tabs mb" data-tabs="client">
  <button type="button" data-tab="overview"><?= icon('grid', 'sm') ?> Overview</button>
  <button type="button" data-tab="journey"><?= icon('activity', 'sm') ?> Journey <span class="crm-count"><?= (int) $journeyTotal ?></span></button>
  <button type="button" data-tab="documents"><?= icon('paperclip', 'sm') ?> Documents <span class="crm-count"><?= count($docs) ?></span></button>
  <?php if ($fin): ?><button type="button" data-tab="finance"><?= icon('wallet', 'sm') ?> Finance</button><?php endif ?>
</div>

<!-- Overview -->
<div data-panel="overview" data-group="client" class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Key details</h3><?php if ($manage): ?><a class="btn sm ghost" href="<?= e(url($base.'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit</a><?php endif ?></div>
      <dl class="kv">
        <dt>Primary contact</dt><dd><?= e($c['name']) ?></dd>
        <dt>Email</dt><dd><?= $c['email'] ? '<a href="mailto:'.e($c['email']).'">'.e($c['email']).'</a>' : '—' ?></dd>
        <dt>Phone</dt><dd><?= e($c['phone'] ?: '—') ?><?= $c['whatsapp'] && $c['whatsapp'] !== $c['phone'] ? ' <span class="xs muted">· WhatsApp '.e($c['whatsapp']).'</span>' : '' ?></dd>
        <dt>Website</dt><dd><?= $c['website'] ? '<a href="'.e($c['website']).'" target="_blank" rel="noopener noreferrer">'.e(preg_replace('#^https?://#', '', $c['website'])).'</a>' : '—' ?></dd>
        <dt>Industry</dt><dd><?= e($c['industry'] ?: '—') ?></dd>
        <dt>Address</dt><dd><?= nl2br(e(implode("\n", array_filter([$c['address'], trim(implode(', ', array_filter([$c['city'], $c['state'], $c['postal_code']]))), $c['country']])) ?: '—')) ?></dd>
        <dt>Tax ID</dt><dd><?= e($c['tax_id'] ?: '—') ?></dd>
        <dt>Currency</dt><dd><?= e($c['currency'] ?: setting('general.currency_code', 'INR')) ?></dd>
        <dt>Client since</dt><dd><?= e(fdate($c['created_at'])) ?></dd>
      </dl>
    </div>
    <div class="card">
      <div class="card-head"><h3>Contacts</h3><?php if ($manage): ?><button type="button" class="btn sm soft" data-modal="contact-modal" data-contact-new><?= icon('user-plus', 'sm') ?> Add contact</button><?php endif ?></div>
      <?php if (!$contacts): ?><p class="muted small mb0">No additional contacts yet. Add the people you work with — finance, marketing, the founder.</p><?php else: ?>
      <div class="list"><?php foreach ($contacts as $ct): ?>
        <div class="list-item">
          <?= avatar($ct['name'], null, 'sm') ?>
          <div class="grow"><b><?= e($ct['name']) ?></b> <?= $ct['is_primary'] ? '<span class="pill pill-mint">Primary</span>' : '' ?><div class="meta"><?= e(implode(' · ', array_filter([$ct['designation'], $ct['email'], $ct['phone']]))) ?></div></div>
          <div class="flex">
            <?php if ($ct['phone']): ?><a class="btn icon sm ghost" href="<?= e(wa_link($ct['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp"><?= icon('whatsapp', 'sm') ?></a><a class="btn icon sm ghost" href="tel:<?= e(preg_replace('/[^\d+]/', '', $ct['phone'])) ?>" title="Call"><?= icon('phone', 'sm') ?></a><?php endif ?>
            <?php if ($ct['email']): ?><a class="btn icon sm ghost" href="mailto:<?= e($ct['email']) ?>" title="Email"><?= icon('mail', 'sm') ?></a><?php endif ?>
            <?php if ($manage): ?>
            <button type="button" class="btn icon sm ghost" title="Edit" data-modal="contact-modal" data-contact="<?= e(json_encode(['action' => url($base.'/contacts/'.$ct['id']), 'name' => $ct['name'], 'designation' => $ct['designation'], 'email' => $ct['email'], 'phone' => $ct['phone'], 'is_primary' => (int) $ct['is_primary']])) ?>"><?= icon('edit', 'sm') ?></button>
            <form method="post" action="<?= e(url($base.'/contacts/'.$ct['id'].'/delete')) ?>" data-confirm="Remove this contact?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Remove"><?= icon('trash', 'sm') ?></button></form>
            <?php endif ?>
          </div>
        </div>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php if ($projOn): ?>
    <div class="card">
      <div class="card-head"><h3>Projects</h3><span class="pill pill-gray"><?= count($projects) ?></span></div>
      <?php if (!$projects): ?><p class="muted small mb0">No projects for this client yet.</p><?php else: ?>
      <div class="list"><?php foreach ($projects as $pr): ?>
        <a class="list-item" href="<?= e(url('/projects/'.$pr['id'])) ?>">
          <span class="notif-ic"><?= icon('folder', 'sm') ?></span>
          <div class="grow"><b><?= e($pr['name']) ?></b><div class="meta"><?= e(implode(' · ', array_filter([$pr['code'], $pr['deadline'] ? 'Due '.fdate($pr['deadline']) : null, $fin && $pr['budget'] ? 'Budget '.money($pr['budget']) : null]))) ?></div><div class="progress mt crm-mini-progress"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div></div>
          <?= badge($pr['status']) ?>
        </a>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Account team</h3><?php if ($manage): ?><a class="btn sm ghost" href="<?= e(url($base.'/edit')) ?>"><?= icon('user-plus', 'sm') ?></a><?php endif ?></div>
      <?php if (!$managers): ?><p class="muted small mb0">No managers assigned. Only admins can see this client.</p><?php else: ?>
      <div class="list"><?php foreach ($managers as $m): ?><div class="list-item"><?= avatar($m['name'], $m['avatar'], 'sm') ?><div class="grow"><b><?= e($m['name']) ?></b><div class="meta"><?= e($m['designation'] ?: $m['email']) ?></div></div></div><?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php if ($fin): $inv = (float) $sum['invoiced']; $pct = $inv > 0 ? min(100, (int) round((float) $sum['paid'] / $inv * 100)) : 0; ?>
    <div class="card soft-lav crm-borderless">
      <div class="card-head"><h3>Financial summary</h3><div class="ring" style="--v:<?= $pct ?>" data-label="<?= $pct ?>%"></div></div>
      <div class="totals crm-totals-full">
        <span class="muted">Invoiced</span><b><?= e(money($sum['invoiced'])) ?></b>
        <span class="muted">Paid</span><b class="crm-pos"><?= e(money($sum['paid'])) ?></b>
        <span class="grand">Outstanding</span><span class="grand"><?= e(money($sum['outstanding'])) ?></span>
      </div>
      <?php if ($finManage): ?><a class="btn sm mt" href="<?= e(url('/invoices/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> New invoice</a><?php endif ?>
    </div>
    <?php endif ?>
    <div class="card">
      <div class="card-head"><h3>Upcoming follow-ups</h3><span class="pill pill-lemon"><?= $openFu ?></span></div>
      <?php if (!$followUps): ?><p class="muted small mb0">Nothing scheduled. Add a follow-up date when logging an activity.</p><?php else: ?>
      <div class="list"><?php foreach ($followUps as $f): $late = strtotime($f['follow_up_at']) < time(); ?>
        <div class="list-item"><span class="notif-ic <?= $late ? 'soft-peach' : 'soft-lemon' ?>"><?= icon('clock', 'sm') ?></span><div class="grow"><b class="small"><?= e($f['title']) ?></b><div class="meta <?= $late ? 'crm-late' : '' ?>"><?= e(fdatetime($f['follow_up_at'])) ?><?= $late ? ' · overdue' : '' ?></div></div>
        <?php if ($manage): ?><form method="post" action="<?= e(url($base.'/activities/'.$f['id'].'/done')) ?>"><?= csrf_field() ?><button class="btn icon sm mint" title="Mark done"><?= icon('check', 'sm') ?></button></form><?php endif ?></div>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php if ($c['notes']): ?><div class="card"><h3>Notes</h3><p class="small mb0"><?= nl2br(e($c['notes'])) ?></p></div><?php endif ?>
  </div>
</div>

<!-- Journey -->
<div data-panel="journey" data-group="client" class="hide">
  <div class="card">
    <div class="card-head"><div><h3>Client journey</h3><p class="xs muted mb0">Every touchpoint, newest first. Striped cards are internal-only notes.</p></div><?php if ($manage): ?><button type="button" class="btn primary sm" data-modal="activity-modal" data-activity-new><?= icon('plus', 'sm') ?> Add entry</button><?php endif ?></div>
    <div class="chips mb">
      <a class="chip <?= $jt === '' ? 'on' : '' ?>" href="<?= e(url($base.'?jt=#journey')) ?>">All <span class="crm-count"><?= (int) $journeyTotal ?></span></a>
      <?php foreach ($types as $tk => [$tl, $ti]): if (empty($typeCounts[$tk])) { continue; } ?><a class="chip <?= $jt === $tk ? 'on' : '' ?>" href="<?= e(url($base.'?jt='.$tk.'#journey')) ?>"><?= icon($ti, 'sm') ?> <?= e($tl) ?> <span class="crm-count"><?= (int) $typeCounts[$tk] ?></span></a><?php endforeach ?>
    </div>
    <?php if (!$acts): ?>
      <?= empty_state('The journey starts here', 'Log meetings, calls, requirements and follow-ups to build a shared history with this client.', 'calendar-person', $manage ? '<button type="button" class="btn primary mt" data-modal="activity-modal" data-activity-new>'.icon('plus', 'sm').' Add first entry</button>' : '') ?>
    <?php else: ?>
    <div class="timeline crm-timeline">
      <?php $lastDay = null; foreach ($acts as $a): [$tl, $ti, $tc] = $types[$a['type']] ?? $types['other']; $day = date('Y-m-d', strtotime($a['occurred_at'])); ?>
      <?php if ($day !== $lastDay): $lastDay = $day; ?><div class="crm-day"><?= e($day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : fdate($day, 'l, d M Y'))) ?></div><?php endif ?>
      <div class="tl-item">
        <span class="tl-dot bg-<?= e($tc) ?>"><?= icon($ti) ?></span>
        <div class="tl-card <?= $a['is_internal'] ? 'internal' : '' ?>">
          <div class="flex between wrap">
            <div class="flex wrap"><b><?= e($a['title']) ?></b><span class="pill pill-<?= e($tc) ?>"><?= e($tl) ?></span><?= $a['is_internal'] ? '<span class="pill pill-gray">'.icon('lock', 'sm').' Internal</span>' : '' ?></div>
            <div class="flex">
              <span class="xs muted nowrap" title="<?= e(fdatetime($a['occurred_at'])) ?>"><?= e(ftime($a['occurred_at'])) ?></span>
              <?php if ($manage): ?>
              <button type="button" class="btn icon sm ghost" title="Edit" data-modal="activity-modal" data-activity="<?= e(json_encode(['action' => url($base.'/activities/'.$a['id']), 'type' => $a['type'], 'title' => $a['title'], 'body' => $a['body'], 'occurred_at' => substr(str_replace(' ', 'T', $a['occurred_at']), 0, 16), 'follow_up_at' => $a['follow_up_at'] ? substr(str_replace(' ', 'T', $a['follow_up_at']), 0, 16) : '', 'is_internal' => (int) $a['is_internal'], 'project_id' => $a['project_id']])) ?>"><?= icon('edit', 'sm') ?></button>
              <form method="post" action="<?= e(url($base.'/activities/'.$a['id'].'/delete')) ?>" data-confirm="Delete this journey entry?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete"><?= icon('trash', 'sm') ?></button></form>
              <?php endif ?>
            </div>
          </div>
          <?php if ($a['body']): ?><p class="small mt mb0 crm-body"><?= nl2br(e($a['body'])) ?></p><?php endif ?>
          <div class="flex wrap xs muted mt">
            <span class="flex"><?= avatar($a['user_name'] ?? 'System', $a['user_avatar'] ?? null, 'sm') ?><?= e($a['user_name'] ?? 'System') ?></span>
            <?php if ($a['project_name']): ?><a class="pill pill-sky" href="<?= e(url('/projects/'.(int) $a['project_id'])) ?>"><?= icon('folder', 'sm') ?> <?= e($a['project_name']) ?></a><?php endif ?>
            <?php if ($a['follow_up_at']): $late = !$a['follow_up_done'] && strtotime($a['follow_up_at']) < time(); ?>
              <span class="pill <?= $a['follow_up_done'] ? 'pill-mint' : ($late ? 'pill-red' : 'pill-lemon') ?>"><?= icon($a['follow_up_done'] ? 'check' : 'clock', 'sm') ?> Follow-up <?= e(fdatetime($a['follow_up_at'])) ?><?= $a['follow_up_done'] ? ' · done' : '' ?></span>
              <?php if (!$a['follow_up_done'] && $manage): ?><form method="post" action="<?= e(url($base.'/activities/'.$a['id'].'/done')) ?>"><?= csrf_field() ?><button class="btn sm mint"><?= icon('check', 'sm') ?> Mark done</button></form><?php endif ?>
            <?php endif ?>
          </div>
        </div>
      </div>
      <?php endforeach ?>
    </div>
    <div class="flex between mt">
      <?php if ($jp > 1): ?><a class="btn sm ghost" href="<?= e(url($base.'?jt='.rawurlencode($jt).'&jp='.($jp - 1).'#journey')) ?>"><?= icon('chevron-left', 'sm') ?> Newer</a><?php else: ?><span></span><?php endif ?>
      <?php if ($more): ?><a class="btn sm ghost" href="<?= e(url($base.'?jt='.rawurlencode($jt).'&jp='.($jp + 1).'#journey')) ?>">Older <?= icon('chevron-right', 'sm') ?></a><?php endif ?>
    </div>
    <?php endif ?>
  </div>
</div>

<!-- Documents -->
<div data-panel="documents" data-group="client" class="hide">
  <div class="grid g-side">
    <div class="card">
      <div class="card-head"><h3>Documents</h3><span class="pill pill-gray"><?= count($docs) ?> files</span></div>
      <?php if (!$docs): ?><?= empty_state('No documents yet', 'Contracts, briefs, brand guidelines and reports shared with this client live here.', 'invoice-paper') ?><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
        <tbody><?php foreach ($docs as $f): $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION)); ?>
          <tr>
            <td><a class="flex" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><span class="crm-ext crm-ext-<?= e(in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg', 'zip'], true) ? $ext : 'other') ?>"><?= e(strtoupper(mb_substr($ext, 0, 4)) ?: 'FILE') ?></span><b class="row-link"><?= e(str_limit($f['original_name'], 60)) ?></b></a></td>
            <td class="small muted nowrap"><?= e($f['size'] >= 1048576 ? number_format($f['size'] / 1048576, 1).' MB' : max(1, (int) round($f['size'] / 1024)).' KB') ?></td>
            <td class="small"><?= e($f['uploader'] ?? '—') ?><div class="xs muted"><?= e(fdatetime($f['created_at'])) ?></div></td>
            <td class="actions"><a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" title="Download"><?= icon('download', 'sm') ?></a>
            <?php if (can('crm.documents.manage')): ?><form method="post" action="<?= e(url('/files/'.$f['id'].'/delete')) ?>" data-confirm="Delete this document permanently?" style="display:inline"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?></td>
          </tr>
        <?php endforeach ?></tbody>
      </table></div>
      <?php endif ?>
    </div>
    <?php if (can('crm.documents.manage')): ?>
    <form class="card form soft-sky crm-borderless" method="post" action="<?= e(url($base.'/documents')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <h3><?= icon('upload') ?> Upload</h3>
      <label class="crm-drop"><input type="file" name="file" required accept=".<?= e(implode(',.', \Core\Storage::DOCS)) ?>"><span><?= icon('cloud', 'lg') ?><b>Choose a file</b><small class="muted">PDF, Office, images or ZIP · max <?= (int) setting('storage.max_upload_mb', 10) ?> MB</small></span></label>
      <?= error_for('file') ?>
      <button class="btn primary"><?= icon('upload', 'sm') ?> Upload document</button>
    </form>
    <?php endif ?>
  </div>
</div>

<?php if ($fin): ?>
<!-- Finance -->
<div data-panel="finance" data-group="client" class="hide stack">
  <div class="card">
    <div class="card-head"><h3>Invoices</h3><?php if ($finManage): ?><a class="btn sm primary" href="<?= e(url('/invoices/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> New invoice</a><?php endif ?></div>
    <?php if (!$invoices): ?><p class="muted small mb0">No invoices yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Number</th><th>Issued</th><th>Due</th><th class="right">Total</th><th class="right">Balance</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($invoices as $i): ?><tr><td><a class="row-link" href="<?= e(url('/invoices/'.$i['id'])) ?>"><?= e($i['number']) ?></a><div class="xs muted"><?= e(str_limit($i['title'] ?? '', 40)) ?></div></td><td class="small"><?= e(fdate($i['issue_date'])) ?></td><td class="small"><?= e(fdate($i['due_date'])) ?></td><td class="right nowrap"><?= e(money($i['total'])) ?></td><td class="right nowrap"><b><?= e(money($i['status'] === 'cancelled' ? 0 : $i['total'] - $i['amount_paid'])) ?></b></td><td><?= badge($i['status']) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
  </div>
  <div class="grid g2">
    <div class="card">
      <div class="card-head"><h3>Quotations</h3><?php if ($finManage): ?><a class="btn sm soft" href="<?= e(url('/quotations/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> New</a><?php endif ?></div>
      <?php if (!$quotes): ?><p class="muted small mb0">No quotations yet.</p><?php else: ?>
      <div class="list"><?php foreach ($quotes as $q): ?><a class="list-item" href="<?= e(url('/quotations/'.$q['id'])) ?>"><span class="notif-ic soft-lav"><?= icon('file-text', 'sm') ?></span><div class="grow"><b><?= e($q['number']) ?></b><div class="meta"><?= e(fdate($q['issue_date'])) ?><?= $q['title'] ? ' · '.e(str_limit($q['title'], 30)) : '' ?></div></div><b class="small nowrap"><?= e(money($q['total'])) ?></b><?= badge($q['status']) ?></a><?php endforeach ?></div>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Payments</h3></div>
      <?php if (!$payments): ?><p class="muted small mb0">No payments received yet.</p><?php else: ?>
      <div class="list"><?php foreach ($payments as $py): ?><a class="list-item" href="<?= e(url('/invoices/'.$py['invoice_id'])) ?>"><span class="notif-ic soft-mint"><?= icon('wallet', 'sm') ?></span><div class="grow"><b class="crm-pos"><?= e(money($py['amount'])) ?></b><div class="meta"><?= e(fdate($py['paid_on'])) ?> · <?= e(\Modules\Crm\Services\Finance::METHODS[$py['method']] ?? $py['method']) ?> · <?= e($py['number']) ?></div></div></a><?php endforeach ?></div>
      <?php endif ?>
    </div>
  </div>
</div>
<?php endif ?>

<?php if ($manage): ?>
<dialog class="modal" id="activity-modal">
  <form method="post" action="<?= e(url($base.'/activities')) ?>" data-new-action="<?= e(url($base.'/activities')) ?>" data-activity-form><?= csrf_field() ?>
    <div class="modal-head"><h3 data-modal-title data-new="Add journey entry" data-edit="Edit journey entry">Add journey entry</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <div class="crm-typepick">
        <?php foreach ($types as $tk => [$tl, $ti, $tc]): if ($tk === 'payment' || $tk === 'assignment') { continue; } ?><label class="crm-type crm-type-<?= e($tc) ?>"><input type="radio" name="type" value="<?= e($tk) ?>"<?= checked($tk === 'meeting') ?>><span><?= icon($ti, 'sm') ?> <?= e($tl) ?></span></label><?php endforeach ?>
        <label class="crm-type crm-type-sky hide" data-system-type><input type="radio" name="type" value="assignment"><span><?= icon('user-plus', 'sm') ?> Team assignment</span></label>
        <label class="crm-type crm-type-mint hide" data-system-type><input type="radio" name="type" value="payment"><span><?= icon('wallet', 'sm') ?> Payment</span></label>
      </div>
      <div class="field"><label>Title *</label><input type="text" name="title" required maxlength="190" placeholder="e.g. Kick-off meeting for Q3 campaign"></div>
      <div class="form-grid">
        <div class="field"><label>When *</label><input type="datetime-local" name="occurred_at" required value="<?= e(date('Y-m-d\TH:i')) ?>" data-default="<?= e(date('Y-m-d\TH:i')) ?>"></div>
        <div class="field"><label>Follow-up on</label><input type="datetime-local" name="follow_up_at"><div class="hint">Managers get a reminder on the day.</div></div>
        <?php if ($projectOptions): ?><div class="field full"><label>Related project</label><select name="project_id"><option value="">None</option><?php foreach ($projectOptions as $pid => $pn): ?><option value="<?= (int) $pid ?>"><?= e($pn) ?></option><?php endforeach ?></select></div><?php endif ?>
      </div>
      <div class="field"><label>Details</label><textarea name="body" rows="4" maxlength="5000" placeholder="What was discussed, decisions, next steps…"></textarea></div>
      <label class="flex"><span class="switch"><input type="checkbox" name="is_internal" value="1"><span></span></span><span><b class="small">Internal only</b><span class="xs muted"> — team-only context, shown with a striped background</span></span></label>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save entry</button></div>
    </div>
  </form>
</dialog>
<dialog class="modal" id="contact-modal">
  <form method="post" action="<?= e(url($base.'/contacts')) ?>" data-new-action="<?= e(url($base.'/contacts')) ?>" data-contact-form><?= csrf_field() ?>
    <div class="modal-head"><h3 data-modal-title data-new="Add contact" data-edit="Edit contact">Add contact</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <div class="form-grid">
        <div class="field"><label>Name *</label><input type="text" name="name" required maxlength="150"></div>
        <div class="field"><label>Designation</label><input type="text" name="designation" maxlength="120" placeholder="Marketing head"></div>
        <div class="field"><label>Email</label><input type="email" name="email" maxlength="190"></div>
        <div class="field"><label>Phone</label><input type="tel" name="phone"></div>
      </div>
      <label class="check"><input type="checkbox" name="is_primary" value="1"> Primary contact</label>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save contact</button></div>
    </div>
  </form>
</dialog>
<?php endif ?>
