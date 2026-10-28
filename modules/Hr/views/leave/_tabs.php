<?php $t = [];
if (can('hr.leave.request')) { $t['index'] = ['My leave', '/hr/leave']; }
if (can('hr.leave.approve')) { $t['approvals'] = ['Approvals', '/hr/leave/approvals']; }
$t['calendar'] = ['Team calendar', '/hr/leave/calendar'];
if (can('hr.leave.manage')) { $t['balances'] = ['Balances', '/hr/leave/balances']; $t['types'] = ['Leave types', '/hr/leave/types']; } ?>
<nav class="tabs mb hr-tabs"><?php foreach ($t as $k => [$l, $u]): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(url($u)) ?>"><?= e($l) ?><?php if ($k === 'approvals' && $pendingCount): ?> <span class="hr-count"><?= (int) $pendingCount ?></span><?php endif ?></a><?php endforeach ?><a href="<?= e(url('/hr/holidays')) ?>">Holidays</a></nav>
