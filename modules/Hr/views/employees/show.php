<?php use Modules\Hr\Services\LeaveService as LS;
$id = (int) $emp['id'];
$manage = can('hr.employees.manage');
$tenure = $emp['joined_on'] ? (new DateTime($emp['joined_on']))->diff(new DateTime('today')) : null;
$actions = (can('admin.users.manage') && ($emp['role_slug'] !== 'super_admin' || user()['role_slug'] === 'super_admin') ? '<a class="btn ghost" href="'.e(url('/admin/users/'.$id.'/edit')).'">'.icon('settings', 'sm').' Account & role</a>' : '').($manage ? '<a class="btn primary" href="'.e(url('/hr/employees/'.$id.'/edit')).'">'.icon('edit', 'sm').' Edit HR profile</a>' : '');
$crumbs = can('hr.employees.view') ? [['HR Admin'], ['Employees', '/hr/employees'], [$emp['name']]] : [['My HR'], ['My profile']];
$icons = ['overview' => 'user', 'schedule' => 'clock', 'leave' => 'umbrella', 'documents' => 'shield', 'checklists' => 'clipboard'];
?>
<?= partial('partials/page_head', ['title' => $self ? 'My profile' : $emp['name'], 'subtitle' => $self ? 'Your HR record, work schedule and leave in one place.' : trim(($emp['designation'] ?: 'Team member').($emp['department'] ? ' · '.$emp['department'] : '')), 'crumbs' => $crumbs, 'actions' => $actions]) ?>
<div class="card hr-banner mb">
  <div class="hr-banner-bg"></div>
  <div class="hr-banner-body">
    <?= avatar($emp['name'], $emp['avatar'], 'xl') ?>
    <div class="hr-banner-id">
      <h2><?= e($emp['name']) ?></h2>
      <div class="muted small"><?= e(trim(($emp['designation'] ?: 'Team member').($emp['department'] ? ' · '.$emp['department'] : ''))) ?></div>
      <div class="chips mt">
        <?= badge($emp['status']) ?>
        <span class="pill pill-lav"><?= e($types[$prof['employment_type'] ?? 'full_time'] ?? 'Full-time') ?></span>
        <?php if ($onLeave): ?><span class="pill pill-peach"><?= icon('plane', 'sm') ?> On leave today</span><?php endif ?>
        <?php if (!empty($prof['probation_end']) && $prof['probation_end'] >= date('Y-m-d')): ?><span class="pill pill-lemon">Probation until <?= fdate($prof['probation_end']) ?></span><?php endif ?>
        <?php if (!empty($prof['exit_date'])): ?><span class="pill pill-red">Exit <?= fdate($prof['exit_date']) ?></span><?php endif ?>
      </div>
    </div>
    <div class="hr-contact">
      <a class="btn sm soft" href="mailto:<?= e($emp['email']) ?>"><?= icon('mail', 'sm') ?> Email</a>
      <?php if ($emp['phone']): ?><a class="btn sm ghost" href="tel:<?= e(preg_replace('/[^\d+]/', '', $emp['phone'])) ?>"><?= icon('phone', 'sm') ?> Call</a><a class="btn sm ghost" href="<?= e(wa_link($emp['phone'])) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'sm') ?> WhatsApp</a><?php endif ?>
    </div>
  </div>
  <div class="hr-facts">
    <div><span class="xs">Employee code</span><b><?= e($emp['employee_code'] ?: '—') ?></b></div>
    <div><span class="xs">Joined</span><b><?= fdate($emp['joined_on']) ?><?= $tenure && !$tenure->invert ? ' <span class="small muted">· '.e($tenure->y || $tenure->m ? ($tenure->y ? $tenure->y.'y ' : '').$tenure->m.'m' : $tenure->d.'d').'</span>' : '' ?></b></div>
    <div><span class="xs">Reports to</span><b><?= e($emp['manager_name'] ?? '—') ?></b></div>
    <div><span class="xs">Work week</span><b><?= e(LS::num($schedule['hours_per_day'])) ?> h/day · off <?= e(LS::daysLabel($schedule['off_days'])) ?></b></div>
  </div>
</div>
<nav class="tabs mb hr-tabs" aria-label="Profile sections">
  <?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/hr/employees/'.$id).'?tab='.$k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($icons[$k] ?? 'file', 'sm') ?> <?= e($l) ?></a><?php endforeach ?>
</nav>
<?= partial('hr::employees/tab_'.$tab, get_defined_vars()) ?>
