<?php $t = [];
if (can('hr.leave.request')) { $t['index'] = ['My leave', '/hr/leave', 'umbrella']; }
if (can('hr.leave.approve')) { $t['approvals'] = ['Approvals', '/hr/leave/approvals', 'check-circle']; }
$t['calendar'] = ['Team calendar', '/hr/leave/calendar', 'calendar'];
$t['holidays'] = ['Holidays', '/hr/holidays', 'gift'];
if (can('hr.leave.manage')) { $t['balances'] = ['Balances', '/hr/leave/balances', 'pie']; $t['types'] = ['Leave types', '/hr/leave/types', 'sliders']; } ?>
<nav class="tabs mb hr-tabs" aria-label="Leave sections"><?php foreach ($t as $k => [$l, $u, $ic]): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url($u)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?><?php if ($k === 'approvals' && !empty($pendingCount)): ?> <span class="hr-count"><?= (int) $pendingCount ?></span><?php endif ?></a><?php endforeach ?></nav>
