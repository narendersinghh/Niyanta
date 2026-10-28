<?php
$id = (int) $emp['id'];
$manage = can('hr.employees.manage');
$tenure = $emp['joined_on'] ? (new DateTime($emp['joined_on']))->diff(new DateTime('today')) : null;
$actions = ($manage ? '<a class="btn primary" href="'.e(url('/hr/employees/'.$id.'/edit')).'">'.icon('edit', 'sm').' Edit HR profile</a>' : '').(can('admin.users.manage') && ($emp['role_slug'] !== 'super_admin' || user()['role_slug'] === 'super_admin') ? '<a class="btn ghost" href="'.e(url('/admin/users/'.$id.'/edit')).'">'.icon('settings', 'sm').' Account & role</a>' : '');
$crumbs = can('hr.employees.view') || can('hr.directory.view') ? [['Employees', '/hr/employees'], [$emp['name']]] : [['People'], ['My profile']];
?>
<?= partial('partials/page_head', ['title' => $self ? 'My HR profile' : $emp['name'], 'crumbs' => $crumbs, 'actions' => $actions]) ?>
<div class="card hr-profile mb">
  <div class="profile-head">
    <?= avatar($emp['name'], $emp['avatar'], 'xl') ?>
    <div class="grow">
      <h2 class="mb0"><?= e($emp['name']) ?></h2>
      <div class="muted"><?= e(trim(($emp['designation'] ?: 'Team member').($emp['department'] ? ' · '.$emp['department'] : ''))) ?></div>
      <div class="chips mt">
        <?= badge($emp['status']) ?>
        <span class="pill pill-lav"><?= e($types[$prof['employment_type'] ?? 'full_time'] ?? 'Full-time') ?></span>
        <?php if ($onLeave): ?><span class="pill pill-peach"><?= icon('plane', 'sm') ?> On leave today</span><?php endif ?>
        <?php if (!empty($prof['probation_end']) && $prof['probation_end'] >= date('Y-m-d')): ?><span class="pill pill-lemon">Probation until <?= fdate($prof['probation_end']) ?></span><?php endif ?>
        <?php if (!empty($prof['exit_date'])): ?><span class="pill pill-red">Exit <?= fdate($prof['exit_date']) ?></span><?php endif ?>
      </div>
    </div>
    <div class="quick">
      <a class="soft-lav" href="mailto:<?= e($emp['email']) ?>"><?= icon('mail', 'sm') ?> Email</a>
      <?php if ($emp['phone']): ?><a class="soft-sky" href="tel:<?= e(preg_replace('/[^\d+]/', '', $emp['phone'])) ?>"><?= icon('phone', 'sm') ?> Call</a><a class="soft-mint" href="<?= e(wa_link($emp['phone'])) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'sm') ?> WhatsApp</a><?php endif ?>
    </div>
  </div>
  <div class="hr-facts">
    <div><span class="xs muted">Employee code</span><b><?= e($emp['employee_code'] ?: '—') ?></b></div>
    <div><span class="xs muted">Joined</span><b><?= fdate($emp['joined_on']) ?></b></div>
    <div><span class="xs muted">Tenure</span><b><?= $tenure ? e($tenure->invert ? 'Joins soon' : ($tenure->y || $tenure->m ? ($tenure->y ? $tenure->y.'y ' : '').$tenure->m.'m' : $tenure->d.' days')) : '—' ?></b></div>
    <div><span class="xs muted">Reports to</span><b><?= e($emp['manager_name'] ?? '—') ?></b></div>
  </div>
</div>
<nav class="tabs mb">
  <?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/hr/employees/'.$id).'?tab='.$k) ?>"><?= e($l) ?></a><?php endforeach ?>
</nav>
<?= partial('hr::employees/tab_'.$tab, get_defined_vars()) ?>
