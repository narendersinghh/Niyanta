<?php
use Modules\Admin\Controllers\AuditController;
$colorFor = static fn (string $a) => match (true) { str_starts_with($a, 'auth.failed'), str_starts_with($a, 'access.denied'), str_ends_with($a, 'delete'), str_contains($a, 'failed') => 'red', str_starts_with($a, 'auth.') => 'sky', str_starts_with($a, 'settings.'), str_starts_with($a, 'security.') => 'lav', str_starts_with($a, 'backup.'), str_starts_with($a, 'update.') => 'mint', str_ends_with($a, 'create') => 'lemon', default => 'gray' };
$hasFilter = (bool) array_filter($f);
?>
<?= partial('partials/page_head', ['title' => 'Audit log', 'subtitle' => 'Who did what, when and from where. '.(($ad = (int) (setting('retention.audit_days') ?? setting('security.audit_retention_days', 365))) ? 'Entries older than '.$ad.' days are pruned nightly (Settings › Data retention).' : 'Entries are kept forever (Settings › Data retention).'), 'crumbs' => [['System'], ['Audit log']],
  'actions' => '<a class="btn ghost" href="'.e(url('/admin/audit').query_merge(['export' => 'csv', 'page' => null])).'">'.icon('download', 'sm').' Export CSV</a>']) ?>
<div class="grid g4 mb">
  <div class="tile dark"><span class="tile-ic"><?= icon('history') ?></span><div><div class="num"><?= number_format((int) $p['total']) ?></div><div class="lbl"><?= $hasFilter ? 'Matching entries' : 'Total entries' ?></div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('activity') ?></span><div><div class="num"><?= (int) $today ?></div><div class="lbl">Events today</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('log-in') ?></span><div><div class="num"><?= (int) ($week['auth.login'] ?? 0) ?></div><div class="lbl">Sign-ins (7 days)</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('shield') ?></span><div><div class="num"><?= (int) ($week['auth.failed'] ?? 0) + (int) ($week['access.denied'] ?? 0) ?></div><div class="lbl">Failed sign-ins & denials (7 days)</div></div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <select name="user" aria-label="User"><option value="">All users</option><?php foreach ($users as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($id, $f['user'] ?: '') ?>><?= e($n) ?></option><?php endforeach ?></select>
    <input type="text" name="action" list="audit-prefixes" value="<?= e($f['action']) ?>" placeholder="Action starts with… (e.g. auth.)" aria-label="Action prefix"><datalist id="audit-prefixes"><?php foreach ($prefixes as $px): ?><option value="<?= e($px) ?>."><?php endforeach ?></datalist>
    <select name="entity" aria-label="Entity"><option value="">Any entity</option><?php foreach ($entities as $en): ?><option<?= selected($en, $f['entity']) ?>><?= e($en) ?></option><?php endforeach ?></select>
    <input type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date"><input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date">
    <button class="btn sm primary"><?= icon('filter', 'sm') ?> Filter</button><?php if ($hasFilter): ?><a class="btn sm ghost" href="<?= e(url('/admin/audit')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No audit entries', $hasFilter ? 'Nothing matches these filters.' : 'Activity will appear here.', 'security-shield') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($p['items'] as $a): $meta = AuditController::meta($a['meta']); ?><tr>
      <td class="nowrap small"><?= e(fdatetime($a['created_at'])) ?><div class="xs muted"><?= e(ago($a['created_at'])) ?></div></td>
      <td><?php if ($a['user_name']): ?><div class="flex"><?= avatar($a['user_name'], null, 'sm') ?><div><div class="small b"><?= e($a['user_name']) ?></div><div class="xs muted"><?= e($a['user_email']) ?></div></div></div><?php else: ?><span class="muted small"><?= $a['user_id'] ? 'User #'.(int) $a['user_id'] : 'System' ?></span><?php endif ?></td>
      <td><span class="pill pill-<?= $colorFor($a['action']) ?> act"><?= e($a['action']) ?></span></td>
      <td class="small nowrap"><?= $a['entity_type'] ? e($a['entity_type']).($a['entity_id'] ? ' <span class="muted">#'.(int) $a['entity_id'].'</span>' : '') : '<span class="muted">—</span>' ?></td>
      <td><?php if ($meta): ?><div class="meta-kv"><?php foreach (array_slice($meta, 0, 6, true) as $k => $v): ?><span title="<?= e($k.': '.$v) ?>"><i><?= e($k) ?></i><?= e(str_limit($v, 60)) ?></span><?php endforeach ?><?= count($meta) > 6 ? '<span>+'.(count($meta) - 6).'</span>' : '' ?></div><?php else: ?><span class="muted">—</span><?php endif ?></td>
      <td class="xs muted mono" title="<?= e($a['user_agent'] ?? '') ?>"><?= e($a['ip'] ?? '') ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?><?php endif ?>
</div>
