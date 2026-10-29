<?php
use Modules\Crm\Services\{Hooks, Ui};
$fin = can('crm.finance.view');
$manage = can('crm.clients.manage');
$statuses = ['lead' => 'Lead', 'active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'];
$filtered = ($_GET['q'] ?? '') !== '' || !empty($_GET['status']) || !empty($_GET['tag']) || !empty($_GET['manager']);
$more = Ui::more([can('crm.reports.view') ? Ui::link('/crm/reports', 'chart', 'Financial reports') : '', can('crm.quotations.view') ? Ui::link('/quotations', 'file-text', 'Quotations') : '', $fin ? Ui::link('/invoices', 'receipt', 'Invoices') : '']);
$actions = $more.($manage ? '<a class="btn primary" href="'.e(url('/clients/create')).'">'.icon('plus', 'sm').' New client</a>' : '');
$tel = static fn (string $p) => 'tel:'.preg_replace('/[^\d+]/', '', $p);
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => 'Clients', 'subtitle' => 'Every relationship, conversation and payment in one place.', 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <?= Ui::tile('lav', 'briefcase', (string) (int) $k['active'], 'Active clients', '/clients?status=active') ?>
  <?= Ui::tile('lemon', 'target', (string) (int) $k['leads'], 'Leads in pipeline', '/clients?status=lead') ?>
  <?php if ($fin): ?>
  <?= Ui::tile('peach', 'wallet', Hooks::compact((float) $k['outstanding']), 'Outstanding balance', '/invoices?status=overdue', money($k['outstanding'])) ?>
  <?= Ui::tile('mint', 'trend-up', Hooks::compact((float) $k['collected']), 'Collected in '.date('F'), '/invoices', money($k['collected'])) ?>
  <?php else: ?>
  <?= Ui::tile('sky', 'users', (string) (int) $k['total'], 'Total clients', '/clients') ?>
  <?= Ui::tile('pink', 'pause', (string) ((int) $k['total'] - (int) $k['active'] - (int) $k['leads']), 'On hold / inactive') ?>
  <?php endif ?>
</div>
<div class="card crm-list">
  <form class="crm-bar" method="get">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <label class="crm-search"><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, company, email or phone" aria-label="Search clients"></label>
    <select name="status" data-autosubmit aria-label="Status"><option value="">All statuses</option><?php foreach ($statuses as $s => $l): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= e($l) ?></option><?php endforeach ?></select>
    <?php if ($tagOptions): ?><select name="tag" data-autosubmit aria-label="Tag"><option value="">All tags</option><?php foreach ($tagOptions as $tid => $tn): ?><option value="<?= (int) $tid ?>"<?= selected($tid, $_GET['tag'] ?? '') ?>><?= e($tn) ?></option><?php endforeach ?></select><?php endif ?>
    <?php if ($managerOptions): ?><select name="manager" data-autosubmit aria-label="Account manager"><option value="">Any manager</option><?php foreach ($managerOptions as $mid => $mn): ?><option value="<?= (int) $mid ?>"<?= selected($mid, $_GET['manager'] ?? '') ?>><?= e($mn) ?></option><?php endforeach ?></select><?php endif ?>
    <select name="sort" data-autosubmit aria-label="Sort"><option value="">Newest first</option><option value="name"<?= selected('name', $_GET['sort'] ?? '') ?>>Name A–Z</option><option value="oldest"<?= selected('oldest', $_GET['sort'] ?? '') ?>>Oldest first</option></select>
    <?php if ($filtered): ?><a class="crm-clear" href="<?= e(url('/clients?view='.$view)) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
    <div class="tabs" role="group" aria-label="Layout"><a href="<?= e(query_merge(['view' => 'table', 'page' => null])) ?>" class="<?= $view === 'table' ? 'active' : '' ?>" title="Table view" aria-label="Table view"><?= icon('list', 'sm') ?></a><a href="<?= e(query_merge(['view' => 'cards', 'page' => null])) ?>" class="<?= $view === 'cards' ? 'active' : '' ?>" title="Card view" aria-label="Card view"><?= icon('grid', 'sm') ?></a></div>
  </form>
  <?php if (!$p['items']): ?>
    <?= $filtered ? empty_state('No clients match', 'Try a different search term or clear the filters.', 'empty-box', '<a class="btn ghost" href="'.e(url('/clients?view='.$view)).'">'.icon('x', 'sm').' Clear filters</a>') : empty_state('No clients yet', 'Add your first client to start tracking conversations, documents and invoices.', 'clients-handshake', $manage ? '<a class="btn primary" href="'.e(url('/clients/create')).'">'.icon('plus', 'sm').' Add your first client</a>' : '') ?>
  <?php elseif ($view === 'cards'): ?>
  <div class="crm-cards">
    <?php foreach ($p['items'] as $c): $cid = (int) $c['id']; $wa = $c['whatsapp'] ?: $c['phone']; ?>
    <div class="crm-ccard">
      <div class="crm-ccard-top">
        <?= avatar($c['company'] ?: $c['name'], null, 'lg') ?>
        <a class="grow" href="<?= e(url('/clients/'.$cid)) ?>"><b class="truncate"><?= e($c['company'] ?: $c['name']) ?></b><div class="crm-sub truncate"><?= e(implode(' · ', array_filter([$c['company'] ? $c['name'] : null, $c['industry'], $c['city']])) ?: '—') ?></div></a>
        <?= badge($c['status'], $statuses[$c['status']] ?? null) ?>
      </div>
      <div class="chips"><?php foreach (array_slice($tags[$cid] ?? [], 0, 4) as $t): ?><span class="pill pill-<?= e($t['color']) ?>"><?= e($t['name']) ?></span><?php endforeach ?></div>
      <div class="crm-ccard-stats">
        <div><span>Account team</span><?php if (!empty($managers[$cid])): ?><div class="avatars"><?php foreach (array_slice($managers[$cid], 0, 4) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?></div><?php else: ?><b class="small muted">Unassigned</b><?php endif ?></div>
        <?php if ($fin): ?><div class="right"><span>Outstanding</span><b class="<?= !empty($due[$cid]) ? '' : 'muted' ?>"><?= !empty($due[$cid]) ? e(Hooks::compact((float) $due[$cid])) : '—' ?></b></div><?php else: ?><div class="right"><span>Client since</span><b class="small"><?= e(fdate($c['created_at'], 'M Y')) ?></b></div><?php endif ?>
      </div>
      <div class="crm-ccard-foot">
        <div class="crm-qbtns">
          <?php if ($wa): ?><a class="crm-qbtn sm wa" href="<?= e(wa_link($wa)) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp"><?= icon('whatsapp') ?></a><?php endif ?>
          <?php if ($c['phone']): ?><a class="crm-qbtn sm call" href="<?= e($tel($c['phone'])) ?>" title="Call" aria-label="Call"><?= icon('phone') ?></a><?php endif ?>
          <?php if ($c['email']): ?><a class="crm-qbtn sm mail" href="mailto:<?= e($c['email']) ?>" title="Email" aria-label="Email"><?= icon('mail') ?></a><?php endif ?>
        </div>
        <a class="btn sm ghost" href="<?= e(url('/clients/'.$cid)) ?>">Open <?= icon('arrow-right', 'sm') ?></a>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table crm-rt">
    <thead><tr><th>Client</th><th>Contact</th><th>Tags</th><th>Team</th><?php if ($fin): ?><th class="amt">Outstanding</th><?php endif ?><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $c): $cid = (int) $c['id']; ?>
      <tr>
        <td class="rt-main"><a class="crm-cell" href="<?= e(url('/clients/'.$cid)) ?>"><?= avatar($c['company'] ?: $c['name'], null) ?><div class="grow"><b><?= e($c['company'] ?: $c['name']) ?></b><div class="crm-sub"><?= e(implode(' · ', array_filter([$c['company'] ? $c['name'] : null, $c['industry']])) ?: '—') ?></div></div></a></td>
        <td data-l="Contact"><div class="small"><?= e($c['email'] ?: '—') ?></div><?php if ($c['phone']): ?><div class="crm-sub rt-hide"><?= e($c['phone']) ?></div><?php endif ?></td>
        <td class="rt-hide"><div class="chips"><?php foreach (array_slice($tags[$cid] ?? [], 0, 3) as $t): ?><span class="pill pill-<?= e($t['color']) ?>"><?= e($t['name']) ?></span><?php endforeach ?><?php if (count($tags[$cid] ?? []) > 3): ?><span class="pill pill-gray">+<?= count($tags[$cid]) - 3 ?></span><?php endif ?><?= empty($tags[$cid]) ? '<span class="muted">—</span>' : '' ?></div></td>
        <td data-l="Team"><div class="avatars"><?php foreach (array_slice($managers[$cid] ?? [], 0, 4) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?><?php if (empty($managers[$cid])): ?><span class="crm-sub">Unassigned</span><?php endif ?></div></td>
        <?php if ($fin): ?><td class="amt" data-l="Outstanding"><?= !empty($due[$cid]) ? '<b>'.e(money($due[$cid])).'</b>' : '<span class="muted">—</span>' ?></td><?php endif ?>
        <td class="rt-side"><?= badge($c['status'], $statuses[$c['status']] ?? null) ?></td>
        <td class="actions rt-hide"><span class="crm-row-actions"><?php if ($manage): ?><a class="crm-go" href="<?= e(url('/clients/'.$cid.'/edit')) ?>" title="Edit" aria-label="Edit"><?= icon('edit', 'sm') ?></a><?php endif ?><a class="crm-go" href="<?= e(url('/clients/'.$cid)) ?>" title="Open" aria-label="Open"><?= icon('chevron-right', 'sm') ?></a></span></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
</div>
