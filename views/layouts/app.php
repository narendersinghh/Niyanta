<?php
use Core\{DB, Modules, Request};
$u = user();
$path = Request::current()->path();
$nav = Modules::nav();
$unread = \Core\Controllers\NotificationController::unread((int) $u['id']);
$isActive = static function (string $url) use ($path): bool { $u = parse_url($url, PHP_URL_PATH) ?: '/'; return $u === '/' ? $path === '/' : ($path === $u || str_starts_with($path, rtrim($u, '/').'/')); };
/* global_scripts/global_styles values: a permission (string|list) or ['perm' => ..., 'feature' => ...]. */
$allowed = static function (mixed $rule): bool {
  $perm = is_array($rule) && !array_is_list($rule) ? ($rule['perm'] ?? null) : $rule;
  $feat = is_array($rule) && !array_is_list($rule) ? ($rule['feature'] ?? null) : null;
  return (!$perm || \Core\Gate::any((array) $perm)) && (!$feat || array_filter((array) $feat, 'feature_on')); };
$scripts = $scripts ?? [];
foreach (Modules::hooks('global_scripts') as $gs) { foreach ($gs as $js => $rule) { if ($allowed($rule)) { $scripts[] = $js; } } }
$scripts = array_values(array_unique($scripts));
$styles = $styles ?? [];
foreach (Modules::hooks('global_styles') as $gs) { foreach ($gs as $css => $rule) { if ($allowed($rule)) { $styles[] = $css; } } }
$searchHint = implode(', ', array_keys(array_filter(['clients' => feature_on('clients'), 'projects' => feature_on('projects'), 'tasks' => feature_on('projects')])));
$styles = array_values(array_unique($styles));
/* Module layout slots: 'topbar' (buttons before the theme switch) and 'body_end' (drawers, dialogs). Each is fn(array $user): string. */
$slot = static function (string $name) use ($u): string { $h = ''; foreach (Modules::hooks($name) as $mod => $fn) { try { $h .= (string) $fn($u); } catch (\Throwable $e) { error_log("Slot $name/$mod: ".$e->getMessage()); } } return $h; };
echo partial('partials/head', get_defined_vars());
?>
<body>
<div class="app" id="app">
<aside class="sidebar" aria-label="Main navigation">
  <a class="brand" href="<?= e(url('/')) ?>">
    <span class="brand-logo"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
    <span><b><?= e(app_name()) ?></b><small>Run your agency with ease</small></span>
  </a>
  <div class="company-chip" title="<?= e(company_name()) ?>">
    <span class="company-logo"><?php if ($clogo = company_logo_url()): ?><img src="<?= e($clogo) ?>" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr(company_name(), 0, 1))) ?><?php endif ?></span>
    <span class="truncate"><small>Workspace</small><b class="truncate"><?= e(company_name()) ?></b></span>
  </div>
  <nav class="nav" data-nav>
    <a href="<?= e(url('/')) ?>" class="<?= $path === '/' ? 'active' : '' ?>"><span class="ni" style="--c:#7C5CFF"><?= icon('home') ?></span> Dashboard</a>
    <?php /* icon colours: keep each item's own colour unless it matches the item above (features switched off can make neighbours repeat); then take the next brand colour that differs from both neighbours */
    $pal = ['#7C5CFF', '#12B5A0', '#F5A524', '#FF6F59', '#2E9BF6', '#F0508A']; $fam = static fn (string $c): string => strtoupper($c) === '#5B4CF5' ? '#7C5CFF' : strtoupper($c);
    $flat = []; foreach ($nav as $sec => $items) { foreach ($items as $i => $n) { $flat[] = [$sec, $i, $fam($n['color'] ?? '#7C5CFF')]; } }
    $navColor = []; $prev = '#7C5CFF';
    foreach ($flat as $k => [$sec, $i, $c]) {
      if ($c === $prev) { $next = $flat[$k + 1][2] ?? ''; $at = (int) array_search($c, $pal, true); for ($j = 1; $j < 6; $j++) { $cand = $pal[($at + $j) % 6]; if ($cand !== $prev && $cand !== $next) { $c = $cand; break; } } }
      $navColor[$sec.'.'.$i] = $prev = $c; } ?>
    <?php foreach ($nav as $sec => $items): [$label] = Modules::SECTIONS[$sec]; if ($sec === 'clients' && !feature_on('clients')) { $label = 'Billing'; }
      $on = static fn (array $n): bool => $isActive($n['url']) || (bool) array_filter($n['also'] ?? [], $isActive);
      $hasActive = (bool) array_filter($items, $on);
      $links = '';
      foreach ($items as $i => $n) { $links .= '<a href="'.e(url($n['url'])).'" class="'.($on($n) ? 'active' : '').'"><span class="ni" style="--c:'.e($navColor[$sec.'.'.$i] ?? $n['color']).'">'.icon($n['icon'] ?? 'grid').'</span> '.e($n['label']).'</a>'; } ?>
      <?php if ($label): ?><div class="nav-group"><?= e($label) ?></div><?php endif ?><?= $links ?>
    <?php endforeach ?>
  </nav>
</aside>
<div class="overlay" data-nav-close></div>
<div class="main">
  <header class="topbar">
    <button class="icon-btn menu-toggle" data-nav-toggle aria-label="Open menu"><?= icon('menu') ?></button>
    <form class="search" action="<?= e(url('/search')) ?>" method="get" role="search"><?= icon('search', 'sm') ?><input type="search" name="q" placeholder="<?= e($searchHint ? 'Search '.$searchHint.'…' : 'Search…') ?>" value="<?= e($_GET['q'] ?? '') ?>" data-search aria-label="Search"><span class="kbd">/</span></form>
    <div class="top-actions"><?= $slot('topbar') ?>
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
          <a href="<?= e(url(profile_url())) ?>"><?= icon('user') ?> My profile</a>
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
<?= $slot('body_end') ?>
<?= partial('partials/toasts') ?>
</body>
</html>
