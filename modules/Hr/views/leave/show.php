<?php use Modules\Hr\Services\{LeaveChanges as LC, LeaveService as LS};
/* One leave request: who, what, balance, team overlap (approvers), timeline and the actions the viewer may take. */
$id = (int) $r['id']; $st = $r['status']; $cxl = $st === 'approved' && $r['cancel_requested_at'];
$future = $r['start_date'] > $today;
$after = null; $balText = 'Unpaid, no balance needed';
if ((int) $r['paid']) {
  if (!$bal || !$bal['allocated_row']) { $balText = 'No '.$r['type_name'].' balance for '.$year; }
  else { $left = (float) $bal['allocated'] - (float) $bal['used']; $after = $left - (float) $r['days']; $morePending = (float) $bal['pending'] - ($st === 'pending' ? (float) $r['days'] : 0);
    $balText = $st === 'pending' ? LS::num($left).' day(s) left, '.($after < 0 ? 'not enough to approve' : LS::num($after).' after approval').($morePending > 0 ? ', '.LS::num($morePending).' more pending' : '') : LS::num($left).' day(s) of '.$r['type_name'].' left in '.$year; } }
$data = LC::dialogData($r, $st === 'pending' ? $balText : '');
$icons = ['apply' => 'send', 'approve' => 'check', 'reject' => 'x', 'edit' => 'edit', 'revoke' => 'trash', 'cancel' => 'x', 'withdraw' => 'x', 'cancel_request' => 'message', 'cancel_approve' => 'check', 'cancel_decline' => 'x', 'on_behalf' => 'plus'];
$back = $own ? ['/hr/leave', 'My leave'] : ['/hr/leave/approvals', 'Leave approvals'];
$actions = '<a class="btn ghost" href="'.e(url($back[0])).'">'.icon('arrow-left', 'sm').' '.e($back[1]).'</a>';
?>
<?= partial('partials/page_head', ['title' => $own ? 'My leave request' : 'Leave request of '.$r['name'], 'subtitle' => $r['type_name'].' · '.LC::range($r['start_date'], $r['end_date'], (bool) $r['half_day']).' · '.LS::num($r['days']).' day(s)', 'crumbs' => $own ? [['My HR'], ['Leave', '/hr/leave'], ['Request']] : [['HR Admin'], ['Leave approvals', '/hr/leave/approvals'], [$r['name']]], 'actions' => $actions]) ?>
<?= partial('hr::leave/_tabs', ['tab' => $own ? 'index' : 'approvals', 'pendingCount' => $pendingCount]) ?>
<div class="grid g-side hr-top">
  <div class="stack">
    <div class="card lv-detail lv-st-<?= e($st) ?>">
      <div class="lv-dhead">
        <div class="hr-who"><?= avatar($r['name'], $r['avatar']) ?><div><b><?= e($r['name']) ?></b><div class="xs muted"><?= e(trim(($r['designation'] ?? '').($r['department'] ? ' · '.$r['department'] : ''), ' ·') ?: 'Employee') ?><?= $r['manager'] ? ' · reports to '.e($r['manager']) : '' ?></div></div></div>
        <div class="lv-dstatus"><?= badge($st) ?><?= $cxl ? ' <span class="pill pill-peach">Cancel requested</span>' : '' ?></div>
      </div>
      <dl class="lv-facts">
        <div><dt>Leave type</dt><dd><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?= (int) $r['paid'] ? '' : ' <span class="xs muted">unpaid</span>' ?></dd></div>
        <div><dt>Dates</dt><dd><b><?= e(LC::range($r['start_date'], $r['end_date'])) ?></b><?= (int) $r['half_day'] ? ' <span class="pill pill-gray">Half day</span>' : '' ?><div class="xs muted"><?= e(fdate($r['start_date'], 'l')) ?><?= $r['end_date'] !== $r['start_date'] ? ' to '.e(fdate($r['end_date'], 'l')) : '' ?></div></dd></div>
        <div><dt>Working days</dt><dd><b><?= e(LS::num($r['days'])) ?></b> <span class="xs muted">weekly offs and fixed holidays not counted</span></dd></div>
        <div><dt>Applied</dt><dd><?= e(fdatetime($r['created_at'])) ?><?= $r['creator'] && (int) $r['created_by'] !== (int) $r['user_id'] ? '<div class="xs muted">booked by '.e($r['creator']).'</div>' : '' ?></dd></div>
        <div><dt>Balance</dt><dd class="<?= $after !== null && $after < 0 && $st === 'pending' ? 'lv-warn' : '' ?>"><?= e($balText) ?></dd></div>
        <div><dt>Approver</dt><dd><?php if ($r['reviewer']): ?><?= e($r['reviewer']) ?> <span class="xs muted"><?= e(fdatetime($r['reviewed_at'])) ?></span><?php elseif ($st === 'pending'): ?><span class="muted">Waiting for <?= e($r['manager'] ?? 'HR') ?></span><?php else: ?><span class="muted">-</span><?php endif ?></dd></div>
      </dl>
      <?php if ($r['reason']): ?><div class="hr-note"><?= icon('message', 'sm') ?><div><span class="xs muted">Reason</span><div><?= nl2br(e($r['reason'])) ?></div></div></div><?php endif ?>
      <?php if ($r['review_note'] && in_array($st, ['approved', 'rejected'], true)): ?><div class="lv-verdict lv-d-<?= e($st) ?>"><?= icon($st === 'rejected' ? 'x' : 'check', 'sm') ?><div><span class="xs"><?= $st === 'rejected' ? 'Reason for rejecting' : 'Approver\'s note' ?><?= $r['reviewer'] ? ' from '.e($r['reviewer']) : '' ?></span><div><?= nl2br(e($r['review_note'])) ?></div></div></div><?php endif ?>
      <?php if ($cxl): ?><div class="lv-verdict lv-d-cancelled"><?= icon('x', 'sm') ?><div><span class="xs">Cancellation requested <?= e(ago($r['cancel_requested_at'])) ?></span><div><?= nl2br(e((string) $r['cancel_reason'])) ?></div></div></div><?php endif ?>
      <div class="lv-dacts">
        <?php if ($approver && $st === 'pending'): ?>
          <form method="post" action="<?= e(url('/hr/leave/'.$id.'/review')) ?>"<?= $after !== null && $after < 0 ? ' data-confirm="'.e($balText.'. Approve anyway? It will fail if the balance is short.').'"' : '' ?>><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button class="btn primary"><?= icon('check', 'sm') ?> Approve</button></form>
          <button type="button" class="btn ghost" data-modal="lv-review" data-lv-act="<?= e(url('/hr/leave/'.$id.'/review')) ?>" data-lv="<?= e($data) ?>"><?= icon('message', 'sm') ?> Approve with a note</button>
          <button type="button" class="btn danger-soft" data-modal="lv-review" data-lv-act="<?= e(url('/hr/leave/'.$id.'/review')) ?>" data-lv="<?= e($data) ?>"><?= icon('x', 'sm') ?> Reject</button>
        <?php endif ?>
        <?php if ($approver && $cxl): ?><button type="button" class="btn primary" data-modal="lv-cxl" data-lv-act="<?= e(url('/hr/leave/'.$id.'/cancellation')) ?>" data-lv="<?= e($data) ?>"><?= icon('check', 'sm') ?> Decide cancellation</button><?php endif ?>
        <?php if ($approver && in_array($st, ['pending', 'approved'], true)): ?><button type="button" class="btn ghost" data-modal="lv-edit" data-lv-edit="<?= e(url('/hr/leave/'.$id.'/edit')) ?>" data-lv="<?= e($data) ?>"><?= icon('edit', 'sm') ?> Edit</button><?php endif ?>
        <?php if ($approver && $st === 'approved'): ?><button type="button" class="btn ghost" data-modal="lv-revoke" data-lv-act="<?= e(url('/hr/leave/'.$id.'/revoke')) ?>" data-lv="<?= e($data) ?>"><?= icon('trash', 'sm') ?> Revoke</button><?php endif ?>
        <?php if ($own && ($st === 'pending' || ($st === 'approved' && !(int) $r['needs_approval'] && $future))): ?><form method="post" action="<?= e(url('/hr/leave/'.$id.'/cancel')) ?>" data-confirm="<?= $st === 'pending' ? 'Withdraw this leave request?' : 'Cancel this leave? The days go back to your balance.' ?>"><?= csrf_field() ?><button class="btn ghost"><?= icon('x', 'sm') ?> <?= $st === 'pending' ? 'Withdraw request' : 'Cancel leave' ?></button></form>
        <?php elseif ($own && $st === 'approved' && $future && !$cxl): ?><button type="button" class="btn ghost" data-modal="lv-askcxl" data-lv-act="<?= e(url('/hr/leave/'.$id.'/cancel-request')) ?>" data-lv="<?= e($data) ?>"><?= icon('x', 'sm') ?> Request cancellation</button>
        <?php elseif ($own && $st === 'approved' && !$future): ?><span class="xs muted">This leave has started. Ask HR if it needs a correction.</span><?php endif ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3><?= icon('history') ?> Timeline</h3><span class="small muted"><?= count($timeline) ?> step<?= count($timeline) === 1 ? '' : 's' ?></span></div>
      <ol class="lv-hist lv-tl">
        <?php foreach ($timeline as $ev): ?><li class="lv-h-<?= e($ev['action']) ?>"><span class="lv-hd"><?= icon($icons[$ev['action']] ?? 'check', 'sm') ?></span><div>
          <b class="small"><?= e($ev['label']) ?><?= $ev['actor'] ? ' by '.e($ev['actor']) : '' ?></b> <span class="xs muted"><?= $ev['at'] ? e(fdatetime($ev['at'])) : '' ?></span>
          <?php if ($ev['changes']): ?><ul class="lv-chg"><?php foreach ($ev['changes'] as $k => [$from, $to]): ?><li><span class="muted"><?= e($k) ?>:</span> <?php if ((string) $from !== ''): ?><s><?= e(str_limit((string) $from, 80)) ?></s> <?= icon('arrow-right', 'sm') ?> <?php endif ?><b><?= e(str_limit((string) $to, 80) ?: 'none') ?></b></li><?php endforeach ?></ul><?php endif ?>
          <?php if ($ev['note']): ?><div class="hr-note"><?= icon('message', 'sm') ?><div><?= nl2br(e($ev['note'])) ?></div></div><?php endif ?>
        </div></li><?php endforeach ?>
        <?php if ($st === 'pending'): ?><li class="lv-h-wait"><span class="lv-hd"><?= icon('clock', 'sm') ?></span><div><b class="small">Waiting for a decision</b> <span class="xs muted"><?= e($r['manager'] ? $r['manager'].' or HR' : 'HR') ?></span></div></li><?php endif ?>
      </ol>
    </div>
  </div>
  <div class="stack">
    <?php if ($approver): ?>
    <div class="card">
      <div class="card-head"><h4><?= icon('users', 'sm') ?> Team out on these dates</h4><a class="xs muted" href="<?= e(url('/hr/leave/calendar?month='.substr($r['start_date'], 0, 7))) ?>">Calendar <?= icon('arrow-right', 'sm') ?></a></div>
      <?php if (!$overlaps): ?><p class="small muted mb0">No one in <?= e($r['name']) ?>'s team (same manager or department) is on leave or has asked for leave on these dates.</p>
      <?php else: ?><div class="list"><?php foreach ($overlaps as $o): ?><div class="list-item"><?= avatar($o['name'], null, 'sm') ?><div class="grow"><b class="small"><?= e($o['name']) ?></b><div class="meta"><?= e($o['type_name']) ?> · <?= e(LC::range($o['start_date'], $o['end_date'], (bool) $o['half_day'])) ?></div></div><?= badge($o['status']) ?></div><?php endforeach ?></div><?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h4><?= icon('pie', 'sm') ?> <?= e(explode(' ', $r['name'])[0]) ?>'s balances <?= (int) $year ?></h4><?php if (can('hr.leave.manage')): ?><a class="xs muted" href="<?= e(url('/hr/leave/ledger?user='.$r['user_id'].'&year='.$year)) ?>">Ledger <?= icon('arrow-right', 'sm') ?></a><?php endif ?></div>
      <div class="list"><?php foreach ($balances as $b): if (!(int) $b['paid'] && !(float) $b['used'] && !(float) $b['pending']) { continue; } ?><div class="list-item<?= (int) $b['id'] === (int) $r['leave_type_id'] ? ' lv-cur' : '' ?>"><span class="pill pill-<?= e($b['color']) ?>"><?= e($b['code']) ?></span><div class="grow"><b class="small"><?= e($b['name']) ?></b><div class="meta"><?= (int) $b['paid'] ? ($b['allocated_row'] ? e(LS::num($b['used'])).' used of '.e(LS::num($b['allocated'])).((float) $b['pending'] ? ' · '.e(LS::num($b['pending'])).' pending' : '') : 'Not allocated') : e(LS::num($b['used'])).' unpaid day(s) taken' ?></div></div><?php if ((int) $b['paid'] && $b['allocated_row']): ?><b><?= e(LS::num((float) $b['allocated'] - (float) $b['used'])) ?></b><span class="xs muted">left</span><?php endif ?></div><?php endforeach ?></div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-head"><h4><?= icon('pie', 'sm') ?> My balances <?= (int) $year ?></h4><a class="xs muted" href="<?= e(url('/hr/leave/ledger?year='.$year)) ?>">My ledger <?= icon('arrow-right', 'sm') ?></a></div>
      <div class="list"><?php foreach ($balances as $b): if (!(int) $b['paid'] && !(float) $b['used'] && !(float) $b['pending']) { continue; } ?><div class="list-item<?= (int) $b['id'] === (int) $r['leave_type_id'] ? ' lv-cur' : '' ?>"><span class="pill pill-<?= e($b['color']) ?>"><?= e($b['code']) ?></span><div class="grow"><b class="small"><?= e($b['name']) ?></b><div class="meta"><?= (int) $b['paid'] ? ($b['allocated_row'] ? e(LS::num($b['used'])).' used of '.e(LS::num($b['allocated'])).((float) $b['pending'] ? ' · '.e(LS::num($b['pending'])).' pending' : '') : 'Not allocated') : e(LS::num($b['used'])).' unpaid day(s) taken' ?></div></div><?php if ((int) $b['paid'] && $b['allocated_row']): ?><b><?= e(LS::num((float) $b['allocated'] - (float) $b['used'])) ?></b><span class="xs muted">left</span><?php endif ?></div><?php endforeach ?></div>
    </div>
    <?php endif ?>
  </div>
</div>
<?= partial('hr::leave/_dialogs', ['types' => $types, 'approver' => $approver, 'mine' => $own, 'manager' => $r['manager']]) ?>
