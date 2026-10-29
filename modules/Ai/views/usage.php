<?php
$max = max(1, ...array_values($months));
$opts = [];
for ($i = 0; $i < 12; $i++) { $k = date('Y-m', strtotime(date('Y-m-01').' -'.$i.' months')); $opts[$k] = date('F Y', strtotime($k.'-01')); }
$pct = $budget ? min(100, (int) round($tot['tokens'] * 100 / $budget)) : 0;
?>
<?= partial('partials/page_head', ['title' => 'AI usage', 'subtitle' => 'Tokens, requests and errors across the workspace.', 'crumbs' => [['System'], ['AI usage']], 'actions' => '<form method="get"><select name="month" data-autosubmit aria-label="Month">'.implode('', array_map(static fn ($k, $l) => '<option value="'.e($k).'"'.selected($k, $month).'>'.e($l).'</option>', array_keys($opts), $opts)).'</select></form>'.(user()['role_slug'] === 'super_admin' ? '<a class="btn ghost" href="'.e(url('/ai/settings')).'">'.icon('settings', 'sm').' Settings</a>' : '')]) ?>
<div class="grid g4 mb">
  <div class="tile dark"><span class="tile-ic"><?= icon('cpu') ?></span><div><div class="num"><?= e(number_format((int) $tot['tokens'])) ?></div><div class="lbl">Tokens used<?= $budget && $month === date('Y-m') ? ' · '.$pct.'% of budget' : '' ?></div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('sparkles') ?></span><div><div class="num"><?= (int) $tot['ok'] ?></div><div class="lbl">Successful requests</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) $tot['errors'] ?></div><div class="lbl">Errors</div></div></div>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('lock') ?></span><div><div class="num"><?= (int) $tot['blocked'] ?></div><div class="lbl">Blocked (budget / limits)</div></div></div>
</div>
<div class="grid g-main mb">
  <div class="card"><div class="card-head"><h3>Tokens per month</h3><span class="xs muted">Input <?= e(number_format((int) $tot['input'])) ?> · Output <?= e(number_format((int) $tot['output'])) ?></span></div>
    <div class="bars"><?php foreach ($months as $k => $v): ?><div class="bar<?= $k === $month ? ' hi' : '' ?>"><i style="height:<?= max(3, (int) round($v * 100 / $max)) ?>%" data-v="<?= e($v >= 1000 ? round($v / 1000, 1).'k' : (string) $v) ?>"></i><small><?= e(date('M', strtotime($k.'-01'))) ?></small></div><?php endforeach ?></div>
  </div>
  <div class="card"><div class="card-head"><h3>By model</h3></div>
    <?php if (!$byModel): ?><p class="muted small">No successful requests this month.</p><?php else: ?><div class="list"><?php foreach ($byModel as $r): ?><div class="list-item"><span class="notif-ic soft-sky"><?= icon('bot', 'sm') ?></span><div class="grow"><b class="small"><?= e($r['model']) ?></b><div class="meta"><?= e($r['provider']) ?> · <?= (int) $r['requests'] ?> requests</div></div><span class="small b"><?= e(number_format((int) $r['input'] + (int) $r['output'])) ?></span></div><?php endforeach ?></div><?php endif ?>
  </div>
</div>
<div class="grid g2 mb">
  <div class="card"><div class="card-head"><h3>By user</h3><span class="pill pill-gray"><?= (int) $tot['users'] ?></span></div>
    <?php if (!$byUser): ?><?= empty_state('No usage yet', 'Requests will appear here.', 'reports-chart') ?><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>User</th><th class="right">Requests</th><th class="right">Tokens</th><th class="right">Errors</th><th class="right">Blocked</th></tr></thead><tbody>
    <?php foreach ($byUser as $r): ?><tr><td><div class="flex"><?= avatar($r['name'] ?? 'System', null, 'sm') ?><b class="small"><?= e($r['name'] ?? 'System') ?></b></div></td><td class="right"><?= (int) $r['requests'] ?></td><td class="right"><?= e(number_format((int) $r['tokens'])) ?></td><td class="right"><?= (int) $r['errors'] ?></td><td class="right"><?= (int) $r['blocked'] ?></td></tr><?php endforeach ?>
    </tbody></table></div><?php endif ?>
  </div>
  <div class="card"><div class="card-head"><h3>By feature</h3></div>
    <?php if (!$byFeature): ?><?= empty_state('No usage yet', 'Requests will appear here.', 'reports-chart') ?><?php else: $fmax = max(1, ...array_map(static fn ($r) => (int) $r['tokens'], $byFeature)); ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Feature</th><th class="right">Requests</th><th>Tokens</th><th class="right">Errors</th></tr></thead><tbody>
    <?php foreach ($byFeature as $r): ?><tr><td><b class="small"><?= e(ucwords(str_replace('_', ' ', $r['feature']))) ?></b></td><td class="right"><?= (int) $r['requests'] ?></td><td style="min-width:140px"><div class="flex"><div class="progress grow"><span style="width:<?= (int) round((int) $r['tokens'] * 100 / $fmax) ?>%"></span></div><span class="xs"><?= e(number_format((int) $r['tokens'])) ?></span></div></td><td class="right"><?= (int) $r['errors'] + (int) $r['blocked'] ?></td></tr><?php endforeach ?>
    </tbody></table></div><?php endif ?>
  </div>
</div>
<div class="card"><div class="card-head"><h3>Errors &amp; blocked requests</h3><span class="xs muted">Latest 25 this month</span></div>
  <?php if (!$errors): ?><?= empty_state('All clear', 'No failed or blocked requests this month.', 'celebrate') ?><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>User</th><th>Feature</th><th>Provider</th><th>Status</th><th>Detail</th></tr></thead><tbody>
  <?php foreach ($errors as $r): ?><tr><td class="small nowrap"><?= e(fdatetime($r['created_at'])) ?></td><td class="small"><?= e($r['name'] ?? 'System') ?></td><td class="small"><?= e(str_replace('_', ' ', $r['feature'])) ?></td><td class="small"><?= e($r['provider']) ?><div class="xs muted"><?= e($r['model']) ?></div></td><td><?= badge($r['status'] === 'error' ? 'failed' : 'pending', ucfirst($r['status'])) ?></td><td class="xs muted" style="max-width:360px;overflow-wrap:anywhere"><?= e($r['error'] ?? '') ?></td></tr><?php endforeach ?>
  </tbody></table></div><?php endif ?>
</div>
