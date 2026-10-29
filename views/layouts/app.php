<?php
use Core\{DB, Modules, Request};
$u = user();
$path = Request::current()->path();
$nav = Modules::nav();
$unread = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL', [$u['id']]);
$logo = setting('branding.logo');
$appName = (string) setting('branding.app_name', 'Niyanta');
$isActive = static function (string $url) use ($path): bool { $u = parse_url($url, PHP_URL_PATH) ?: '/'; return $u === '/' ? $path === '/' : ($path === $u || str_starts_with($path, rtrim($u, '/').'/')); };
$scripts = $scripts ?? [];
foreach (Modules::hooks('global_scripts') as $gs) { foreach ($gs as $js => $perm) { if (!$perm || \Core\Gate::any((array) $perm)) { $scripts[] = $js; } } }
$scripts = array_values(array_unique($scripts));
echo partial('partials/head', get_defined_vars());
?>
<body>
<div class="app" id="app">
<aside class="sidebar" aria-label="Main navigation">
  <a class="brand" href="<?= e(url('/')) ?>">
    <span class="brand-logo"><?php if ($logo): ?><img src="<?= e(url('assets/uploads/branding/'.basename((string) $logo))) ?>" alt=""><?php elseif (is_file(NY_ROOT.'/assets/img/logo.svg')): ?><img src="<?= e(asset('img/logo.svg')) ?>" alt=""><?php else: ?><?= e(mb_substr($appName, 0, 1)) ?><?php endif ?></span>
    <span><b><?= e($appName) ?></b><small><?= e(setting('branding.tagline', 'Run your agency with ease')) ?></small></span>
  </a>
  <nav class="nav" data-nav>
    <a href="<?= e(url('/')) ?>" class="<?= $path === '/' ? 'active' : '' ?>"><span class="ni" style="--c:#7C5CFF"><?= icon('home') ?></span> Dashboard</a>
    <?php foreach ($nav as $sec => $items): [$label, $collapsible] = Modules::SECTIONS[$sec];
      $hasActive = (bool) array_filter($items, static fn ($n) => $isActive($n['url']));
      $links = '';
      foreach ($items as $n) { $links .= '<a href="'.e(url($n['url'])).'" class="'.($isActive($n['url']) ? 'active' : '').'"><span class="ni" style="--c:'.e($n['color']).'">'.icon($n['icon'] ?? 'grid').'</span> '.e($n['label']).'</a>'; } ?>
      <?php if ($collapsible): ?><details class="nav-sec" data-sec="<?= e($sec) ?>"<?= $hasActive ? ' open data-active' : '' ?>><summary><?= e($label) ?><span class="sec-count"><?= count($items) ?></span><?= icon('chevron-down') ?></summary><?= $links ?></details>
      <?php else: ?><?php if ($label): ?><div class="nav-group"><?= e($label) ?></div><?php endif ?><?= $links ?><?php endif ?>
    <?php endforeach ?>
  </nav>
  <div class="side-card mt2">
    <?= illus('side-card') ?>
    <b>Need a hand?</b>
    <p class="xs mb0">Press <span class="kbd">/</span> to search anything you have access to.</p>
  </div>
</aside>
<div class="overlay" data-nav-close></div>
<div class="main">
  <header class="topbar">
    <button class="icon-btn menu-toggle" data-nav-toggle aria-label="Open menu"><?= icon('menu') ?></button>
    <form class="search" action="<?= e(url('/search')) ?>" method="get" role="search"><?= icon('search', 'sm') ?><input type="search" name="q" placeholder="Search clients, projects, tasks…" value="<?= e($_GET['q'] ?? '') ?>" data-search aria-label="Search"><span class="kbd">/</span></form>
    <div class="top-actions">
      <div class="theme-switch" role="group" aria-label="Theme">
        <button type="button" data-theme-set="light" title="Light"><?= icon('sun', 'sm') ?></button><button type="button" data-theme-set="system" title="System"><?= icon('monitor', 'sm') ?></button><button type="button" data-theme-set="dark" title="Dark"><?= icon('moon', 'sm') ?></button>
      </div>
      <details class="dd" data-notif>
        <summary class="icon-btn" aria-label="Notifications"><?= icon('bell') ?><?php if ($unread): ?><span class="badge-dot" data-notif-dot></span><?php endif ?></summary>
        <div class="dd-menu wide">
          <div class="flex between" style="padding:6px 8px 8px"><b>Notifications</b><a class="xs muted" href="<?= e(url('/notifications')) ?>">View all</a></div>
          <div data-notif-list><div class="muted small" style="padding:10px">Loading…</div></div>
        </div>
      </details>
      <details class="dd">
        <summary class="user-chip"><?= avatar($u['name'], $u['avatar'], 'sm') ?><span class="uname"><?= e(explode(' ', $u['name'])[0]) ?></span><?= icon('chevron-down', 'sm') ?></summary>
        <div class="dd-menu">
          <div style="padding:8px 12px"><b><?= e($u['name']) ?></b><div class="xs muted"><?= e($u['role_name']) ?></div></div>
          <a href="<?= e(url('/account')) ?>"><?= icon('user') ?> My account</a>
          <?php if ($u['role_slug'] === 'super_admin'): ?><a href="<?= e(url('/admin/settings')) ?>"><?= icon('settings') ?> Settings</a><?php endif ?>
          <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('log-out') ?> Sign out</button></form>
        </div>
      </details>
    </div>
  </header>
  <main class="content" id="content">
    <?= $content ?>
  </main>
</div>
</div>
<?= partial('partials/toasts') ?>
</body>
</html>
