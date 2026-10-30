<?php /* Users & access tabs: $active ('users'|'roles') */ if ((user()['role_slug'] ?? '') === 'super_admin'): ?>
<nav class="tabs mb" aria-label="Users and access"><a class="<?= $active === 'users' ? 'active' : '' ?>" href="<?= e(url('/admin/users')) ?>"<?= $active === 'users' ? ' aria-current="page"' : '' ?>><?= icon('users', 'sm') ?> People</a><a class="<?= $active === 'roles' ? 'active' : '' ?>" href="<?= e(url('/admin/roles')) ?>"<?= $active === 'roles' ? ' aria-current="page"' : '' ?>><?= icon('key', 'sm') ?> Roles &amp; permissions</a></nav>
<?php endif ?>
