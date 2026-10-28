<?php
$fin = can('crm.finance.view');
$statuses = ['lead' => 'Lead', 'active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'];
$actions = '<div class="tabs"><a href="'.e(query_merge(['view' => 'table', 'page' => null])).'" class="'.($view === 'table' ? 'active' : '').'" title="Table view">'.icon('list', 'sm').'</a><a href="'.e(query_merge(['view' => 'cards', 'page' => null])).'" class="'.($view === 'cards' ? 'active' : '').'" title="Card view">'.icon('grid', 'sm').'</a></div>';
if (can('crm.clients.manage')) { $actions .= '<a class="btn primary" href="'.e(url('/clients/create')).'">'.icon('plus', 'sm').' New client</a>'; }
$filtered = ($_GET['q'] ?? '') !== '' || !empty($_GET['status']) || !empty($_GET['tag']) || !empty($_GET['manager']);
?>
<?= partial('partials/page_head', ['title' => 'Clients', 'subtitle' => 'Every relationship, conversation and rupee in one place.', 'crumbs' => [['Clients']], 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <a class="tile bg-lav" href="<?= e(url('/clients?status=active')) ?>"><span class="tile-ic"><?= icon('briefcase') ?></span><div><div class="num"><?= (int) $k['active'] ?></div><div class="lbl">Active clients</div></div><?= illus('clients-handshake', 'corner') ?></a>
  <a class="tile bg-lemon" href="<?= e(url('/clients?status=lead')) ?>"><span class="tile-ic"><?= icon('target') ?></span><div><div class="num"><?= (int) $k['leads'] ?></div><div class="lbl">Leads in pipeline</div></div></a>
  <?php if ($fin): ?>
  <a class="tile dark" href="<?= e(url('/invoices?status=overdue')) ?>"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num"><?= e(\Modules\Crm\Services\Hooks::compact($k['outstanding'])) ?></div><div class="lbl">Outstanding</div></div></a>
  <a class="tile bg-mint" href="<?= e(url('/invoices')) ?>"><span class="tile-ic"><?= icon('trend-up') ?></span><div><div class="num"><?= e(\Modules\Crm\Services\Hooks::compact($k['collected'])) ?></div><div class="lbl">Collected in <?= e(date('F')) ?></div></div></a>
  <?php else: ?>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $k['total'] ?></div><div class="lbl">Total clients</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('pause') ?></span><div><div class="num"><?= (int) $k['total'] - (int) $k['active'] - (int) $k['leads'] ?></div><div class="lbl">On hold / inactive</div></div></div>
  <?php endif ?>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, company, email, phone" class="grow">
    <select name="status" data-autosubmit><option value="">Any status</option><?php foreach ($statuses as $s => $l): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= e($l) ?></option><?php endforeach ?></select>
    <?php if ($tagOptions): ?><select name="tag" data-autosubmit><option value="">Any tag</option><?php foreach ($tagOptions as $tid => $tn): ?><option value="<?= (int) $tid ?>"<?= selected($tid, $_GET['tag'] ?? '') ?>><?= e($tn) ?></option><?php endforeach ?></select><?php endif ?>
    <select name="manager" data-autosubmit><option value="">Any manager</option><?php foreach ($managerOptions as $mid => $mn): ?><option value="<?= (int) $mid ?>"<?= selected($mid, $_GET['manager'] ?? '') ?>><?= e($mn) ?></option><?php endforeach ?></select>
    <select name="sort" data-autosubmit><option value="">Newest first</option><option value="name"<?= selected('name', $_GET['sort'] ?? '') ?>>Name A–Z</option><option value="oldest"<?= selected('oldest', $_GET['sort'] ?? '') ?>>Oldest first</option></select>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <?php if ($filtered): ?><a class="btn sm soft" href="<?= e(url('/clients?view='.$view)) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?>
    <?= $filtered ? empty_state('No clients match', 'Try a different search or clear the filters.', 'empty-box') : empty_state('No clients yet', 'Add your first client to start tracking conversations, documents and invoices.', 'clients-handshake', can('crm.clients.manage') ? '<a class="btn primary mt" href="'.e(url('/clients/create')).'">'.icon('plus', 'sm').' Add client</a>' : '') ?>
  <?php elseif ($view === 'cards'): ?>
  <div class="crm-cards">
    <?php foreach ($p['items'] as $c): $cid = (int) $c['id']; ?>
    <div class="crm-ccard">
      <div class="flex between"><a class="flex" href="<?= e(url('/clients/'.$cid)) ?>"><?= avatar($c['company'] ?: $c['name'], null, 'lg') ?><div class="grow"><b class="crm-cname"><?= e($c['company'] ?: $c['name']) ?></b><div class="xs muted"><?= e($c['company'] ? $c['name'] : ($c['industry'] ?: '—')) ?></div></div></a><?= badge($c['status']) ?></div>
      <div class="chips mt"><?php foreach ($tags[$cid] ?? [] as $t): ?><span class="pill pill-<?= e($t['color']) ?>"><?= e($t['name']) ?></span><?php endforeach ?><?php if ($c['industry'] && $c['company']): ?><span class="pill pill-gray"><?= e($c['industry']) ?></span><?php endif ?></div>
      <div class="crm-ccard-foot">
        <div class="avatars"><?php foreach (array_slice($managers[$cid] ?? [], 0, 4) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?></div>
        <?php if ($fin && !empty($due[$cid])): ?><span class="xs"><span class="muted">Due</span> <b><?= e(money($due[$cid])) ?></b></span><?php endif ?>
        <div class="flex crm-qa">
          <?php if ($c['phone']): ?><a class="btn icon sm ghost" href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['phone'])) ?>" title="Call"><?= icon('phone', 'sm') ?></a><?php endif ?>
          <?php if ($c['whatsapp'] || $c['phone']): ?><a class="btn icon sm ghost" href="<?= e(wa_link($c['whatsapp'] ?: $c['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp"><?= icon('whatsapp', 'sm') ?></a><?php endif ?>
          <?php if ($c['email']): ?><a class="btn icon sm ghost" href="mailto:<?= e($c['email']) ?>" title="Email"><?= icon('mail', 'sm') ?></a><?php endif ?>
        </div>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Client</th><th>Contact</th><th>Tags</th><th>Managers</th><?php if ($fin): ?><th class="right">Outstanding</th><?php endif ?><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $c): $cid = (int) $c['id']; ?>
      <tr>
        <td><a class="flex" href="<?= e(url('/clients/'.$cid)) ?>"><?= avatar($c['company'] ?: $c['name'], null, 'sm') ?><div><b class="row-link"><?= e($c['company'] ?: $c['name']) ?></b><div class="xs muted"><?= e($c['company'] ? $c['name'] : '') ?><?= $c['company'] && $c['industry'] ? ' · ' : '' ?><?= e($c['industry'] ?? '') ?></div></div></a></td>
        <td class="small"><?= e($c['email'] ?: '—') ?><div class="xs muted"><?= e($c['phone'] ?? '') ?></div></td>
        <td><div class="chips"><?php foreach (array_slice($tags[$cid] ?? [], 0, 3) as $t): ?><span class="pill pill-<?= e($t['color']) ?>"><?= e($t['name']) ?></span><?php endforeach ?><?php if (count($tags[$cid] ?? []) > 3): ?><span class="pill pill-gray">+<?= count($tags[$cid]) - 3 ?></span><?php endif ?></div></td>
        <td><div class="avatars"><?php foreach (array_slice($managers[$cid] ?? [], 0, 4) as $m): ?><?= avatar($m['name'], $m['avatar'], 'sm') ?><?php endforeach ?><?php if (empty($managers[$cid])): ?><span class="xs muted">Unassigned</span><?php endif ?></div></td>
        <?php if ($fin): ?><td class="right nowrap"><?= !empty($due[$cid]) ? '<b>'.e(money($due[$cid])).'</b>' : '<span class="muted">—</span>' ?></td><?php endif ?>
        <td><?= badge($c['status'], $statuses[$c['status']] ?? null) ?></td>
        <td class="actions"><a class="btn icon sm ghost" href="<?= e(url('/clients/'.$cid)) ?>" title="Open"><?= icon('eye', 'sm') ?></a><?php if (can('crm.clients.manage')): ?> <a class="btn icon sm ghost" href="<?= e(url('/clients/'.$cid.'/edit')) ?>" title="Edit"><?= icon('edit', 'sm') ?></a><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
