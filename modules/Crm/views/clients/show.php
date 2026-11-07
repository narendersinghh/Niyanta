<?php
use Modules\Crm\Controllers\JourneyController;
use Modules\Crm\Services\{Finance, Hooks, Ui};
$cid = (int) $c['id'];
$label = $c['company'] ?: $c['name'];
$manage = can('crm.clients.manage');
$finManage = $fin && can('crm.finance.manage');
$qManage = $qv && can('crm.quotations.manage');
$docManage = can('crm.documents.manage');
$wa = $c['whatsapp'] ?: $c['phone'];
$types = JourneyController::TYPES;
$statuses = ['lead' => 'Lead', 'active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'];
$errs = errors();
$base = '/clients/'.$cid;
$openFu = count($followUps);
$finTab = $fin || $qv;
$place = trim(($c['city'] ?? '').($c['city'] && $c['country'] ? ', ' : '').($c['country'] ?? ''));
$tel = static fn (string $p) => 'tel:'.preg_replace('/[^\d+]/', '', $p);
$openQuotes = $qv ? array_filter($quotes, static fn ($q) => in_array($q['status'], ['draft', 'sent'], true) && !$q['invoice_id']) : [];
$moreMenu = Ui::more([
  $qManage ? Ui::link('/quotations/create?client_id='.$cid, 'file-text', 'New quotation') : '',
  $finManage ? Ui::link('/invoices/create?client_id='.$cid, 'receipt', 'New invoice') : '',
  $manage ? '<button type="button" data-modal="contact-modal" data-contact-new>'.icon('user-plus', 'sm').' Add contact</button>' : '',
  $docManage ? '<a href="#documents" data-tab-link="documents">'.icon('upload', 'sm').' Upload document</a>' : '',
  can('crm.clients.delete') ? Ui::post($base.'/delete', 'trash', 'Delete client', 'Delete this client with all contacts, journey, documents and quotations? This cannot be undone.', true) : '']);
?>
<div class="crm">
<div class="crumbs"><a href="<?= e(url('/clients')) ?>">Clients</a><span>/</span><span><?= e($label) ?></span></div>
<section class="card crm-prof">
  <div class="crm-prof-cover"></div>
  <div class="crm-prof-main">
    <?= avatar($label, null, 'xl') ?>
    <div class="crm-prof-id">
      <div class="flex wrap"><h1><?= e($label) ?></h1><?= badge($c['status'], $statuses[$c['status']] ?? null) ?></div>
      <div class="crm-sub"><?= e(implode(' · ', array_filter([$c['company'] ? $c['name'] : null, $c['industry'], $place ?: null, $c['source'] ? 'via '.$c['source'] : null])) ?: 'No details yet') ?></div>
    </div>
    <div class="crm-prof-acts">
      <?php if ($manage): ?><a class="btn ghost" href="<?= e(url($base.'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit</a><?php endif ?>
      <?= $moreMenu ?>
      <?php if ($manage): ?><button type="button" class="btn primary" data-modal="activity-modal" data-activity-new><?= icon('plus', 'sm') ?> Log activity</button>
      <?php elseif ($finManage): ?><a class="btn primary" href="<?= e(url('/invoices/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> New invoice</a><?php endif ?>
    </div>
  </div>
  <div class="crm-prof-row">
    <div class="chips"><?php foreach ($tags as $t): ?><a class="pill pill-<?= e($t['color']) ?>" href="<?= e(url('/clients?tag='.(int) $t['id'])) ?>"><?= icon('tag', 'sm') ?> <?= e($t['name']) ?></a><?php endforeach ?><?php if (!$tags): ?><span class="crm-sub">No tags</span><?php endif ?></div>
    <div class="crm-qbtns">
      <?php if ($wa): ?><a class="crm-qbtn wa" href="<?= e(wa_link($wa, 'Hi '.$c['name'].', ')) ?>" target="_blank" rel="noopener" title="WhatsApp <?= e($wa) ?>" aria-label="WhatsApp"><?= icon('whatsapp') ?></a><?php endif ?>
      <?php if ($c['phone']): ?><a class="crm-qbtn call" href="<?= e($tel($c['phone'])) ?>" title="Call <?= e($c['phone']) ?>" aria-label="Call"><?= icon('phone') ?></a><?php endif ?>
      <?php if ($c['email']): ?><a class="crm-qbtn mail" href="mailto:<?= e($c['email']) ?>" title="Email <?= e($c['email']) ?>" aria-label="Email"><?= icon('mail') ?></a><?php endif ?>
      <?php if ($c['website']): ?><a class="crm-qbtn web" href="<?= e($c['website']) ?>" target="_blank" rel="noopener noreferrer" title="Website" aria-label="Website"><?= icon('globe') ?></a><?php endif ?>
    </div>
  </div>
  <div class="crm-metrics">
    <?php if ($fin): ?>
    <div class="crm-metric"><span>Invoiced</span><b title="<?= e(money($sum['invoiced'])) ?>"><?= e(Hooks::compact((float) $sum['invoiced'])) ?></b></div>
    <div class="crm-metric"><span>Paid</span><b class="crm-pos" title="<?= e(money($sum['paid'])) ?>"><?= e(Hooks::compact((float) $sum['paid'])) ?></b></div>
    <div class="crm-metric"><span>Outstanding</span><b class="<?= (int) $sum['overdue'] ? 'crm-neg' : '' ?>" title="<?= e(money($sum['outstanding'])) ?>"><?= e(Hooks::compact((float) $sum['outstanding'])) ?></b><?php if ((int) $sum['overdue']): ?><small><?= (int) $sum['overdue'] ?> overdue</small><?php endif ?></div>
    <?php else: ?>
    <div class="crm-metric"><span>Contacts</span><b><?= count($contacts) + 1 ?></b></div>
    <div class="crm-metric"><span>Documents</span><b><?= count($docs) ?></b></div>
    <?php endif ?>
    <?php if ($qv && !$fin): ?><div class="crm-metric"><span>Open quotations</span><b><?= count($openQuotes) ?></b></div><?php endif ?>
    <div class="crm-metric"><span>Follow-ups</span><b class="<?= $openFu ? '' : 'muted' ?>"><?= $openFu ?></b><small>open</small></div>
    <div class="crm-metric"><span>Client since</span><b><?= e(fdate($c['created_at'], 'M Y')) ?></b></div>
  </div>
</section>
<?php if ($errs): ?><div class="alert error"><?= icon('alert') ?><div><?php foreach ($errs as $er): ?><div><?= e($er) ?></div><?php endforeach ?></div></div><?php endif ?>
<div class="tabs crm-tabs" data-tabs="client">
  <button type="button" data-tab="overview"><?= icon('grid', 'sm') ?> Overview</button>
  <button type="button" data-tab="journey"><?= icon('activity', 'sm') ?> Journey <span class="crm-count"><?= (int) $journeyTotal ?></span></button>
  <button type="button" data-tab="documents"><?= icon('paperclip', 'sm') ?> Documents <span class="crm-count"><?= count($docs) ?></span></button>
  <?php if ($finTab): ?><button type="button" data-tab="finance"><?= icon('wallet', 'sm') ?> <?= $fin ? 'Finance' : 'Quotations' ?></button><?php endif ?>
</div>

<div data-panel="overview" data-group="client" class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Key details</h3><?php if ($manage): ?><a class="btn sm ghost" href="<?= e(url($base.'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit</a><?php endif ?></div>
      <dl class="kv crm-kv">
        <dt>Primary contact</dt><dd><?= e($c['name']) ?></dd>
        <dt>Email</dt><dd><?= $c['email'] ? '<a href="mailto:'.e($c['email']).'">'.e($c['email']).'</a>' : '<span class="muted">-</span>' ?></dd>
        <dt>Phone</dt><dd><?= $c['phone'] ? '<a href="'.e($tel($c['phone'])).'">'.e($c['phone']).'</a>' : '<span class="muted">-</span>' ?><?= $c['whatsapp'] && $c['whatsapp'] !== $c['phone'] ? ' <span class="crm-sub">· WhatsApp '.e($c['whatsapp']).'</span>' : '' ?></dd>
        <dt>Website</dt><dd><?= $c['website'] ? '<a href="'.e($c['website']).'" target="_blank" rel="noopener noreferrer">'.e(preg_replace('#^https?://#', '', $c['website'])).'</a>' : '<span class="muted">-</span>' ?></dd>
        <dt>Industry</dt><dd><?= e($c['industry'] ?: '-') ?></dd>
        <dt>Billing address</dt><dd><?= nl2br(e(implode("\n", array_filter([$c['address'], trim(implode(', ', array_filter([$c['city'], $c['state'], $c['postal_code']]))), $c['country']])) ?: '-')) ?></dd>
        <dt>Tax ID</dt><dd><?= e($c['tax_id'] ?: '-') ?></dd>
        <dt>Currency</dt><dd><?= e($c['currency'] ?: setting('general.currency_code', 'INR')) ?></dd>
      </dl>
    </div>
    <div class="card">
      <div class="card-head"><div><h3>Contacts</h3><p class="xs muted mb0">People you work with at <?= e($label) ?></p></div><?php if ($manage): ?><button type="button" class="btn sm soft" data-modal="contact-modal" data-contact-new><?= icon('user-plus', 'sm') ?> Add contact</button><?php endif ?></div>
      <?php if (!$contacts): ?><div class="list-item"><span class="crm-ic"><?= icon('users', 'sm') ?></span><p class="small muted mb0">No additional contacts yet, add finance, marketing or the founder so the team knows who to reach.</p></div><?php else: ?>
      <div class="list"><?php foreach ($contacts as $ct): ?>
        <div class="list-item">
          <?= avatar($ct['name'], null) ?>
          <div class="grow"><b><?= e($ct['name']) ?></b> <?= $ct['is_primary'] ? '<span class="pill pill-mint">Primary</span>' : '' ?><div class="meta"><?= e(implode(' · ', array_filter([$ct['designation'], $ct['email'], $ct['phone']]))) ?></div></div>
          <div class="flex crm-row-actions">
            <?php if ($ct['phone']): ?><a class="crm-qbtn sm wa" href="<?= e(wa_link($ct['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp"><?= icon('whatsapp') ?></a><a class="crm-qbtn sm call" href="<?= e($tel($ct['phone'])) ?>" title="Call" aria-label="Call"><?= icon('phone') ?></a><?php endif ?>
            <?php if ($ct['email']): ?><a class="crm-qbtn sm mail" href="mailto:<?= e($ct['email']) ?>" title="Email" aria-label="Email"><?= icon('mail') ?></a><?php endif ?>
            <?php if ($manage): ?><?= Ui::more(['<button type="button" data-modal="contact-modal" data-contact="'.e(json_encode(['action' => url($base.'/contacts/'.$ct['id']), 'name' => $ct['name'], 'designation' => $ct['designation'], 'email' => $ct['email'], 'phone' => $ct['phone'], 'is_primary' => (int) $ct['is_primary']])).'">'.icon('edit', 'sm').' Edit contact</button>', Ui::post($base.'/contacts/'.$ct['id'].'/delete', 'trash', 'Remove contact', 'Remove this contact?', true)], 'Contact actions') ?><?php endif ?>
          </div>
        </div>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php if ($projOn): ?>
    <div class="card">
      <div class="card-head"><h3>Projects</h3><span class="crm-count"><?= count($projects) ?></span></div>
      <?php if (!$projects): ?><p class="muted small mb0">No projects for this client yet.</p><?php else: ?>
      <div class="list"><?php foreach ($projects as $pr): ?>
        <a class="list-item" href="<?= e(url('/projects/'.$pr['id'])) ?>">
          <span class="crm-ic crm-c-sky"><?= icon('folder', 'sm') ?></span>
          <div class="grow"><b><?= e($pr['name']) ?></b><div class="meta"><?= e(implode(' · ', array_filter([$pr['code'], $pr['deadline'] ? 'Due '.fdate($pr['deadline']) : null, $fin && $pr['budget'] ? 'Budget '.money($pr['budget']) : null]))) ?></div><div class="progress crm-mini-progress"><span style="width:<?= (int) $pr['progress'] ?>%"></span></div></div>
          <?= badge($pr['status']) ?>
        </a>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>
  <div class="stack">
    <?php if ($fin): $inv = (float) $sum['invoiced']; $pct = $inv > 0 ? min(100, (int) round((float) $sum['paid'] / $inv * 100)) : 0; ?>
    <div class="card spotlight">
      <div class="card-head"><h3>Financial summary</h3><?php if ($finManage): ?><a class="btn sm soft" href="<?= e(url('/invoices/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> Invoice</a><?php endif ?></div>
      <div class="crm-fin-rows"><span>Invoiced</span><span><?= e(money($sum['invoiced'])) ?></span><span>Paid</span><span class="crm-pos"><?= e(money($sum['paid'])) ?></span><span>Outstanding</span><span class="<?= (float) $sum['outstanding'] > 0 ? 'crm-neg' : '' ?>"><?= e(money($sum['outstanding'])) ?></span></div>
      <div class="crm-meter" title="<?= $pct ?>% collected"><span style="width:<?= $pct ?>%"></span></div>
      <div class="crm-legend"><span><i class="crm-ag-mint"></i><?= $pct ?>% collected</span><?php if ((int) $sum['overdue']): ?><span class="crm-late"><?= (int) $sum['overdue'] ?> overdue invoice<?= (int) $sum['overdue'] === 1 ? '' : 's' ?></span><?php endif ?></div>
    </div>
    <?php endif ?>
    <div class="card">
      <div class="card-head"><h3>Upcoming follow-ups</h3><span class="crm-count"><?= $openFu ?></span></div>
      <?php if (!$followUps): ?><div class="list-item"><span class="crm-ic crm-c-lemon"><?= icon('clock', 'sm') ?></span><p class="small muted mb0">Nothing scheduled. Add a follow-up date when logging an activity.</p></div><?php else: ?>
      <div class="list"><?php foreach ($followUps as $f): $late = strtotime($f['follow_up_at']) < time(); ?>
        <div class="list-item"><span class="crm-ic <?= $late ? 'crm-c-red' : 'crm-c-lemon' ?>"><?= icon('clock', 'sm') ?></span><div class="grow"><b class="small"><?= e($f['title']) ?></b><div class="meta <?= $late ? 'crm-late' : '' ?>"><?= e(fdatetime($f['follow_up_at'])) ?><?= $late ? ' · overdue' : '' ?></div></div>
        <?php if ($manage): ?><form method="post" action="<?= e(url($base.'/activities/'.$f['id'].'/done')) ?>"><?= csrf_field() ?><button class="btn icon sm mint" title="Mark done" aria-label="Mark done"><?= icon('check', 'sm') ?></button></form><?php endif ?></div>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Account team</h3><?php if ($manage): ?><a class="btn icon sm ghost" href="<?= e(url($base.'/edit')) ?>" title="Change team" aria-label="Change team"><?= icon('user-plus', 'sm') ?></a><?php endif ?></div>
      <?php if (!$managers): ?><p class="muted small mb0">No managers assigned, only admins can see this client.</p><?php else: ?>
      <div class="list"><?php foreach ($managers as $m): ?><div class="list-item"><?= avatar($m['name'], $m['avatar']) ?><div class="grow"><b><?= e($m['name']) ?></b><div class="meta"><?= e($m['designation'] ?: $m['email']) ?></div></div></div><?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php if ($c['notes']): ?><div class="card"><div class="card-head"><h3>Notes</h3><span class="pill pill-gray"><?= icon('lock', 'sm') ?> Internal</span></div><p class="small mb0"><?= nl2br(e($c['notes'])) ?></p></div><?php endif ?>
  </div>
</div>

<div data-panel="journey" data-group="client" class="hide">
  <div class="card">
    <div class="card-head"><div><h3>Client journey</h3><p class="xs muted mb0">Every touchpoint, newest first<?= $grouped ? ', grouped by project' : '' ?>. Dashed cards are internal-only notes.</p></div><?php if ($manage): ?><button type="button" class="btn primary sm" data-modal="activity-modal" data-activity-new><?= icon('plus', 'sm') ?> Add entry</button><?php endif ?></div>
    <?php if ($manage && class_exists(\Modules\Meetings\Services\Ui::class)): ?><?= \Modules\Meetings\Services\Ui::addressHint('c', $cid) ?><?php endif ?>
    <?php if ($journeyTotal): $jq = static fn (string $t, string $pr) => url($base.'?'.http_build_query(array_filter(['jt' => $t, 'jpr' => $pr], 'strlen')).'#journey'); ?>
    <?php if ($projOn && array_diff(array_map('strval', array_keys($projCounts)), ['none'])): ?>
    <div class="crm-filter-chips crm-proj-chips" aria-label="Filter by project">
      <a class="chip <?= $jpr === '' ? 'on' : '' ?>" href="<?= e($jq($jt, '')) ?>"><?= icon('layers', 'sm') ?> All projects <span class="crm-count"><?= (int) array_sum($projCounts) ?></span></a>
      <?php foreach ($projCounts as $pk => $n): if ($pk === 'none') { continue; } ?><a class="chip <?= $jpr === (string) $pk ? 'on' : '' ?>" href="<?= e($jq($jt, (string) $pk)) ?>"><?= icon('folder', 'sm') ?> <?= e(str_limit($projNames[(int) $pk] ?? 'Project #'.$pk, 40)) ?> <span class="crm-count"><?= (int) $n ?></span></a><?php endforeach ?>
      <?php if (!empty($projCounts['none'])): ?><a class="chip <?= $jpr === 'none' ? 'on' : '' ?>" href="<?= e($jq($jt, 'none')) ?>"><?= icon('briefcase', 'sm') ?> Non-project <span class="crm-count"><?= (int) $projCounts['none'] ?></span></a><?php endif ?>
    </div>
    <?php endif ?>
    <div class="crm-filter-chips" aria-label="Filter by type">
      <a class="chip <?= $jt === '' ? 'on' : '' ?>" href="<?= e($jq('', $jpr)) ?>">All types <span class="crm-count"><?= (int) array_sum($typeCounts) ?></span></a>
      <?php foreach ($types as $tk => [$tl, $ti]): if (empty($typeCounts[$tk])) { continue; } ?><a class="chip <?= $jt === $tk ? 'on' : '' ?>" href="<?= e($jq($tk, $jpr)) ?>"><?= icon($ti, 'sm') ?> <?= e($tl) ?> <span class="crm-count"><?= (int) $typeCounts[$tk] ?></span></a><?php endforeach ?>
    </div>
    <?php endif ?>
    <?php if (!$acts): ?>
      <?= empty_state($jt !== '' || $jpr !== '' ? 'Nothing here yet' : 'The journey starts here', 'Log meetings, calls, requirements and follow-ups to build a shared history with this client.', 'calendar-person', $manage ? '<button type="button" class="btn primary" data-modal="activity-modal" data-activity-new>'.icon('plus', 'sm').' Add first entry</button>' : '') ?>
    <?php else: ?>
    <?= partial('crm::partials/journey_list', ['acts' => $acts, 'base' => $base, 'manage' => $manage, 'grouped' => $grouped, 'showProject' => $projOn, 'projNames' => $projNames, 'projCounts' => $projCounts, 'transcripts' => $transcripts]) ?>
    <?php if ($jp > 1 || $more): $pq = static fn (int $n) => url($base.'?'.http_build_query(array_filter(['jt' => $jt, 'jpr' => $jpr], 'strlen') + ['jp' => $n]).'#journey'); ?><div class="flex between mt">
      <?php if ($jp > 1): ?><a class="btn sm ghost" href="<?= e($pq($jp - 1)) ?>"><?= icon('chevron-left', 'sm') ?> Newer</a><?php else: ?><span></span><?php endif ?>
      <?php if ($more): ?><a class="btn sm ghost" href="<?= e($pq($jp + 1)) ?>">Older <?= icon('chevron-right', 'sm') ?></a><?php endif ?>
    </div><?php endif ?>
    <?php endif ?>
  </div>
</div>

<div data-panel="documents" data-group="client" class="hide">
  <div class="grid <?= $docManage ? 'g-side' : '' ?>">
    <div class="card crm-list">
      <div class="crm-bar"><div class="grow"><h3 class="mb0">Documents</h3><div class="crm-sub">Contracts, briefs, brand guidelines and reports</div></div><span class="crm-count"><?= count($docs) ?></span></div>
      <?php if (!$docs): ?><?= empty_state('No documents yet', 'Contracts, briefs and reports shared with this client will live here.', 'empty-box') ?><?php else: ?>
      <div class="table-wrap"><table class="table crm-rt">
        <thead><tr><th>File</th><th>Uploaded by</th><th class="amt">Size</th><th><span class="sr">Actions</span></th></tr></thead>
        <tbody><?php foreach ($docs as $f): ?>
          <tr>
            <td class="rt-main"><a class="crm-cell" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= Ui::ext($f['original_name']) ?><div class="grow"><b class="truncate"><?= e(str_limit($f['original_name'], 60)) ?></b><div class="crm-sub"><?= e(fdatetime($f['created_at'])) ?></div></div></a></td>
            <td data-l="Uploaded by" class="small"><?= e($f['uploader'] ?? '-') ?></td>
            <td class="amt small muted" data-l="Size"><?= e(Ui::size($f['size'])) ?></td>
            <td class="actions rt-side"><span class="crm-row-actions"><a class="crm-go" href="<?= e(url('/files/'.$f['id'])) ?>" title="Download" aria-label="Download"><?= icon('download', 'sm') ?></a><?php if ($docManage): ?><form method="post" action="<?= e(url('/files/'.$f['id'].'/delete')) ?>" data-confirm="Delete this document permanently?"><?= csrf_field() ?><button class="crm-go" title="Delete" aria-label="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?></span></td>
          </tr>
        <?php endforeach ?></tbody>
      </table></div>
      <?php endif ?>
    </div>
    <?php if ($docManage): ?>
    <form class="card form crm-upload" method="post" action="<?= e(url($base.'/documents')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="crm-sec-h"><span class="crm-ic crm-c-sky"><?= icon('upload', 'sm') ?></span><div class="grow"><h3>Upload</h3><p>Visible to everyone who can see this client.</p></div></div>
      <label class="crm-drop"><input type="file" name="file" required accept=".<?= e(implode(',.', \Core\Storage::DOCS)) ?>"><span><span class="crm-ic solid crm-c-lav"><?= icon('cloud') ?></span><b>Choose a file</b><small class="muted">PDF, Office, images or ZIP · max <?= (int) setting('storage.max_upload_mb', 10) ?> MB</small></span></label>
      <?= error_for('file') ?>
      <button class="btn primary"><?= icon('upload', 'sm') ?> Upload document</button>
    </form>
    <?php endif ?>
  </div>
</div>

<?php if ($finTab): ?>
<div data-panel="finance" data-group="client" class="hide stack">
  <?php if ($fin): ?>
  <div class="card crm-list">
    <div class="crm-bar"><div class="grow"><h3 class="mb0">Invoices</h3><div class="crm-sub"><?= count($invoices) ?> most recent</div></div><?php if ($finManage): ?><a class="btn sm primary" href="<?= e(url('/invoices/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> New invoice</a><?php endif ?></div>
    <?php if (!$invoices): ?><?= empty_state('No invoices yet', 'Bill this client for work delivered'.(feature_on('quotations') ? ', or convert an accepted quotation' : '').'.', 'invoice-paper', $finManage ? '<a class="btn primary" href="'.e(url('/invoices/create?client_id='.$cid)).'">'.icon('plus', 'sm').' Create invoice</a>' : '') ?><?php else: ?>
    <div class="table-wrap"><table class="table crm-rt">
      <thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="amt">Total</th><th class="amt">Balance</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($invoices as $i): $b = $i['status'] === 'cancelled' ? 0 : (float) $i['total'] - (float) $i['amount_paid']; ?><tr>
        <td class="rt-main"><a class="crm-num-link" href="<?= e(url('/invoices/'.$i['id'])) ?>"><?= e($i['number']) ?></a><div class="crm-sub"><?= e(str_limit($i['title'] ?? '', 40) ?: '-') ?></div></td>
        <td class="small" data-l="Issued"><?= e(fdate($i['issue_date'])) ?></td>
        <td class="small <?= $i['status'] === 'overdue' ? 'crm-late' : '' ?>" data-l="Due"><?= e(fdate($i['due_date'])) ?></td>
        <td class="amt" data-l="Total"><?= e(money($i['total'])) ?></td>
        <td class="amt" data-l="Balance"><b class="<?= $b > 0 ? '' : 'muted' ?>"><?= e(money($b)) ?></b></td>
        <td class="rt-side"><?= badge($i['status']) ?></td>
      </tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
  </div>
  <?php endif ?>
  <div class="grid <?= $fin && $qv ? 'g2' : '' ?>">
    <?php if ($qv): ?>
    <div class="card">
      <div class="card-head"><div><h3>Quotations</h3><p class="xs muted mb0"><?= count($openQuotes) ?> awaiting a decision</p></div><?php if ($qManage): ?><a class="btn sm soft" href="<?= e(url('/quotations/create?client_id='.$cid)) ?>"><?= icon('plus', 'sm') ?> New quotation</a><?php endif ?></div>
      <?php if (!$quotes): ?><div class="list-item"><span class="crm-ic crm-c-lemon"><?= icon('file-text', 'sm') ?></span><p class="small muted mb0">No quotations yet. Send an itemised estimate before you start the work.</p></div><?php else: ?>
      <div class="list"><?php foreach ($quotes as $q): ?><a class="list-item" href="<?= e(url('/quotations/'.$q['id'])) ?>"><span class="crm-ic crm-c-lemon"><?= icon('file-text', 'sm') ?></span><div class="grow"><b><?= e($q['number']) ?></b><div class="meta"><?= e(fdate($q['issue_date'])) ?><?= $q['title'] ? ' · '.e(str_limit($q['title'], 30)) : '' ?></div></div><div class="right"><b class="small amt"><?= e(money($q['total'])) ?></b><div><?= badge($q['status']) ?></div></div></a><?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php endif ?>
    <?php if ($fin): ?>
    <div class="card">
      <div class="card-head"><div><h3>Payments received</h3><p class="xs muted mb0"><?= e(money($sum['paid'])) ?> in total</p></div></div>
      <?php if (!$payments): ?><div class="list-item"><span class="crm-ic crm-c-mint"><?= icon('wallet', 'sm') ?></span><p class="small muted mb0">No payments received yet.</p></div><?php else: ?>
      <div class="list"><?php foreach ($payments as $py): ?><a class="list-item" href="<?= e(url('/invoices/'.$py['invoice_id'])) ?>"><span class="crm-ic crm-c-mint"><?= icon('wallet', 'sm') ?></span><div class="grow"><b class="small"><?= e($py['number']) ?></b><div class="meta"><?= e(fdate($py['paid_on'])) ?> · <?= e(Finance::METHODS[$py['method']] ?? $py['method']) ?></div></div><b class="crm-pos amt">+<?= e(money($py['amount'])) ?></b></a><?php endforeach ?></div>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>
</div>
<?php endif ?>

<?php if ($manage): ?>
<?= partial('crm::partials/activity_modal', ['action' => $base.'/activities', 'projectOptions' => $projectOptions, 'clientId' => $cid]) ?>
<dialog class="modal" id="contact-modal">
  <form method="post" action="<?= e(url($base.'/contacts')) ?>" data-new-action="<?= e(url($base.'/contacts')) ?>" data-contact-form><?= csrf_field() ?>
    <div class="modal-head"><h3 data-modal-title data-new="Add contact" data-edit="Edit contact">Add contact</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <div class="form-grid">
        <div class="field"><label for="ct-name">Name *</label><input id="ct-name" type="text" name="name" required maxlength="150"></div>
        <div class="field"><label for="ct-des">Designation</label><input id="ct-des" type="text" name="designation" maxlength="120" placeholder="Marketing head"></div>
        <div class="field"><label for="ct-email">Email</label><input id="ct-email" type="email" name="email" maxlength="190"></div>
        <div class="field"><label for="ct-phone">Phone</label><input id="ct-phone" type="tel" name="phone"></div>
      </div>
      <label class="check"><input type="checkbox" name="is_primary" value="1"> Primary contact</label>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save contact</button></div>
    </div>
  </form>
</dialog>
<?php endif ?>
</div>
