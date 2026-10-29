<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'users', 'actions' => '<a class="btn ghost" href="'.e(url('/admin/users')).'">'.icon('users', 'sm').' Manage users</a>']) ?>
<div class="grid g3 mini-stats">
  <a class="tile bg-lav" href="<?= e(url('/admin/users')) ?>"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= array_sum(array_column($roles, 'n')) ?></div><div class="lbl">Active users · <?= count($roles) ?> roles</div></div></a>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('lock') ?></span><div><div class="num"><?= (int) $failed ?></div><div class="lbl">Failed sign-ins (7 days)</div></div></div>
  <a class="tile bg-sky" href="<?= e(url('/admin/audit?action=access.')) ?>"><span class="tile-ic"><?= icon('shield') ?></span><div><div class="num"><?= (int) $denied ?></div><div class="lbl">Access denied (7 days)</div></div></a>
</div>
<?= partial('admin::settings/_form', ['tab' => 'users', 'fields' => $fields, 'sections' => $sections]) ?>
<div class="grid g2">
  <div class="card"><div class="card-head"><h3>Roles</h3><a class="btn ghost sm" href="<?= e(url('/admin/roles')) ?>"><?= icon('key', 'sm') ?> Permissions</a></div>
    <div class="list"><?php foreach ($roles as $r): ?><div class="list-item"><span class="ic-chip sm" style="--c:var(--<?= $r['slug'] === 'super_admin' ? 'primary' : 'mint' ?>)"><?= icon($r['slug'] === 'super_admin' ? 'shield' : 'user', 'sm') ?></span><div class="grow"><b><?= e($r['name']) ?></b></div><span class="pill pill-gray"><?= (int) $r['n'] ?> active</span></div><?php endforeach ?></div>
    <p class="xs muted mt mb0"><?= (int) $permCount ?> granular permissions are enforced on every route.</p></div>
  <div class="card danger-card"><h3><?= icon('log-out') ?> Emergency sign-out</h3><p class="small muted">Immediately ends every session of all <?= (int) $sessions ?> other user(s) on every device, use after a suspected compromise or a password policy change. You stay signed in.</p>
    <form method="post" action="<?= e(url('/admin/settings/security/logout-all')) ?>" data-confirm="Sign out all other users on every device now?"><?= csrf_field() ?><button class="btn danger"><?= icon('log-out', 'sm') ?> Force sign-out all users</button></form></div>
</div>
