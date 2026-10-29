<?php $me = (int) user()['id']; $filtered = ($_GET['q'] ?? '') !== '' || ($_GET['department'] ?? '') !== ''; ?>
<?= partial('partials/page_head', ['title' => 'Team directory', 'subtitle' => 'Find a teammate and get in touch in one click.', 'crumbs' => [['My HR'], ['Team directory']], 'actions' => '<a class="btn ghost" href="'.e(url('/hr/me')).'">'.icon('user', 'sm').' My profile</a>']) ?>
<form class="card hr-toolbar mb" method="get">
  <label class="hr-search"><span class="sr">Search people</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search people, roles, teams"></label>
  <select name="department" data-autosubmit aria-label="Department"><option value="">All departments</option><?php foreach ($departments as $d): ?><option<?= selected($d, $_GET['department'] ?? '') ?>><?= e($d) ?></option><?php endforeach ?></select>
  <?php if ($filtered): ?><a class="btn sm ghost" href="<?= e(url('/hr/employees')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  <span class="small muted hr-push"><?= (int) $p['total'] ?> of <?= (int) $total ?> people</span>
</form>
<?php if (!$p['items']): ?><div class="card"><?= empty_state('Nobody matches that search', 'Try another name or department.', 'people-hr', '<a class="btn ghost" href="'.e(url('/hr/employees')).'">Show everyone</a>') ?></div><?php else: ?>
<div class="hr-people">
  <?php foreach ($p['items'] as $u): $own = (int) $u['id'] === $me; ?>
  <div class="card hr-person">
    <?php if (isset($out[(int) $u['id']]) && feature_on('leave')): ?><span class="pill pill-peach hr-out"><?= icon('plane', 'sm') ?> On leave</span><?php endif ?>
    <?= avatar($u['name'], $u['avatar'], 'lg') ?>
    <h4><?php if ($own): ?><a href="<?= e(url('/hr/employees/'.$u['id'])) ?>"><?= e($u['name']) ?></a> <span class="pill pill-lav">You</span><?php else: ?><?= e($u['name']) ?><?php endif ?></h4>
    <div class="small muted"><?= e($u['designation'] ?: 'Team member') ?><?= $u['department'] ? ' · '.e($u['department']) : '' ?></div>
    <div class="hr-contact">
      <a class="btn sm soft" href="mailto:<?= e($u['email']) ?>" title="<?= e($u['email']) ?>"><?= icon('mail', 'sm') ?> Email</a>
      <?php if ($u['phone']): ?><a class="btn sm ghost" href="tel:<?= e(preg_replace('/[^\d+]/', '', $u['phone'])) ?>" title="<?= e($u['phone']) ?>" aria-label="Call <?= e($u['name']) ?>"><?= icon('phone', 'sm') ?></a><a class="btn sm ghost" href="<?= e(wa_link($u['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp" aria-label="WhatsApp <?= e($u['name']) ?>"><?= icon('whatsapp', 'sm') ?></a><?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</div>
<?php if ($p['pages'] > 1): ?><div class="card mt"><?= pager($p) ?></div><?php endif ?>
<?php endif ?>
