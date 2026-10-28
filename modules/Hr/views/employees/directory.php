<?php $me = (int) user()['id']; ?>
<?= partial('partials/page_head', ['title' => 'Team directory', 'subtitle' => 'Find a teammate and get in touch in one click.', 'crumbs' => [['People'], ['Directory']], 'actions' => can('hr.leave.request') ? '<a class="btn ghost" href="'.e(url('/hr/me')).'">'.icon('user', 'sm').' My HR profile</a>' : '']) ?>
<div class="card hero mb hr-dir-hero">
  <div>
    <span class="pill pill-lav"><?= icon('users', 'sm') ?> <?= (int) $total ?> people</span>
    <h2 class="mt">Say hello to your team</h2>
    <p class="muted mb0">Search by name, role or department. Contact details are shared for work purposes only.</p>
    <form class="filters mt mb0" method="get">
      <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search people, roles, teams">
      <select name="department" data-autosubmit><option value="">All departments</option><?php foreach ($departments as $d): ?><option<?= selected($d, $_GET['department'] ?? '') ?>><?= e($d) ?></option><?php endforeach ?></select>
      <button class="btn primary sm"><?= icon('search', 'sm') ?> Search</button>
    </form>
  </div>
  <?= illus('team') ?>
</div>
<?php if (!$p['items']): ?><div class="card"><?= empty_state('Nobody matches that search', 'Try another name or department.', 'team') ?></div><?php else: ?>
<div class="hr-people">
  <?php foreach ($p['items'] as $u): $own = (int) $u['id'] === $me; ?>
  <div class="card hr-person">
    <?php if (isset($out[(int) $u['id']])): ?><span class="pill pill-peach hr-out"><?= icon('plane', 'sm') ?> On leave</span><?php endif ?>
    <?= avatar($u['name'], $u['avatar'], 'lg') ?>
    <h4 class="mt"><?php if ($own): ?><a href="<?= e(url('/hr/employees/'.$u['id'])) ?>"><?= e($u['name']) ?></a> <span class="pill pill-lav">You</span><?php else: ?><?= e($u['name']) ?><?php endif ?></h4>
    <div class="small muted"><?= e($u['designation'] ?: 'Team member') ?></div>
    <?php if ($u['department']): ?><span class="pill pill-gray mt"><?= e($u['department']) ?></span><?php endif ?>
    <div class="quick hr-quick">
      <a class="soft-lav" href="mailto:<?= e($u['email']) ?>" title="<?= e($u['email']) ?>"><?= icon('mail', 'sm') ?> Email</a>
      <?php if ($u['phone']): ?><a class="soft-sky" href="tel:<?= e(preg_replace('/[^\d+]/', '', $u['phone'])) ?>" title="<?= e($u['phone']) ?>"><?= icon('phone', 'sm') ?> Call</a><a class="soft-mint" href="<?= e(wa_link($u['phone'])) ?>" target="_blank" rel="noopener" title="WhatsApp"><?= icon('whatsapp', 'sm') ?></a><?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</div>
<div class="card mt"><?= pager($p) ?: '<div class="small muted center">Showing all '.(int) $p['total'].' people</div>' ?></div>
<?php endif ?>
