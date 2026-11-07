<?php
$q = static fn (array $add) => e(url('/reimbursements').query_merge($add));
$statuses = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected'];
$me = (int) user()['id']; $sa = user()['role_slug'] === 'super_admin';
$payOn = feature_on('payroll'); /* without payroll, approved claims are settled by HR outside the app */
?>
<?= partial('partials/page_head', ['title' => $tab === 'queue' ? 'Reimbursements' : 'My Pay', 'subtitle' => $tab === 'queue' ? 'Review expense claims. Approved claims are paid '.($payOn ? 'with the next payroll run.' : 'out by HR.') : 'Claim back business expenses. Approved claims are paid '.($payOn ? 'with your next payslip.' : 'out by HR.'), 'crumbs' => [[$tab === 'queue' ? 'HR Admin' : 'My HR'], ['Reimbursements']], 'actions' => ($tab === 'queue' ? \Modules\Admin\Services\SettingsSchema::settingsLink('reimbursements') : '').($canReq ? '<button class="btn primary" type="button" data-modal="newClaim">'.icon('plus', 'sm').' New claim</button>' : '')]) ?>
<?= partial('payroll::_mypay', ['active' => $tab, 'queueCount' => (int) $queue['n']]) ?>
<div class="grid g3 mb">
  <?php if ($tab === 'queue'): ?>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $queue['n'] ?></div><div class="lbl">Claims awaiting review</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('receipt') ?></span><div><div class="num pay-num"><?= e(money($queue['amt'])) ?></div><div class="lbl">Pending amount</div></div></div>
  <a class="tile bg-lav" href="<?= e(url(can('payroll.manage') ? '/payroll' : '/reimbursements?tab=queue&status=approved')) ?>"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e(money($mine['approved'])) ?></div><div class="lbl">My approved claims, to be paid</div></div></a>
  <?php else: ?>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num pay-num"><?= e(money($mine['pending'])) ?></div><div class="lbl">Awaiting approval</div><div class="pay-sub"><?= (int) $mine['pending_n'] ?> claim<?= (int) $mine['pending_n'] === 1 ? '' : 's' ?></div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num pay-num"><?= e(money($mine['approved'])) ?></div><div class="lbl">Approved</div><div class="pay-sub"><?= $payOn ? 'Paid with your next payslip' : 'Paid out by HR' ?></div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e(money($mine['paid'])) ?></div><div class="lbl">Reimbursed in <?= date('Y') ?></div></div></div>
  <?php endif ?>
</div>
<div class="card">
  <form class="pay-toolbar" method="get">
    <div class="chips"><?php foreach ($statuses as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= $q(['status' => $k, 'page' => 1, 'tab' => $tab]) ?>"><?= e($l) ?></a><?php endforeach ?></div>
    <?php if ($tab === 'queue'): ?><input type="hidden" name="tab" value="queue"><input type="hidden" name="status" value="<?= e($status) ?>"><label class="pay-search pay-push"><span class="sr">Search</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee or title"></label><?php endif ?>
  </form>
  <?php if (!$p['items']): ?>
    <?= $tab === 'queue' ? empty_state('Nothing to review', 'All claims have been processed. Nice work!', 'celebrate') : empty_state('No claims here', 'Spent money on work travel, software or client meetings? Submit a claim and get it back'.($payOn ? ' with your salary.' : '.'), 'invoice-paper', $canReq ? '<button class="btn primary" type="button" data-modal="newClaim">'.icon('plus', 'sm').' New claim</button>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><?php if ($tab === 'queue'): ?><th>Employee</th><?php endif ?><th>Claim</th><th>Expense date</th><th class="num">Amount</th><th>Receipt</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r): $own = (int) $r['user_id'] === $me; ?>
      <tr>
        <?php if ($tab === 'queue'): ?><td><div class="pay-who"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b class="small"><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['employee_code'] ?? '') ?></div></div></div></td><?php endif ?>
        <td class="pay-claim"><b class="small"><?= e($r['title']) ?></b><?php if ($r['description']): ?><div class="xs muted"><?= e(str_limit($r['description'], 80)) ?></div><?php endif ?><div class="xs muted">Submitted <?= e(ago($r['created_at'])) ?></div></td>
        <td class="small nowrap"><?= e(fdate($r['expense_date'])) ?></td>
        <td class="num"><b><?= e(money($r['amount'])) ?></b></td>
        <td class="small"><?php foreach ($files[(int) $r['id']] ?? [] as $f): ?><a class="chip" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 'sm') ?> <?= e(str_limit($f['original_name'], 18)) ?></a><?php endforeach ?><?= empty($files[(int) $r['id']]) ? '<span class="muted">-</span>' : '' ?></td>
        <td><?= badge($r['status']) ?><?php if ($r['reviewer']): ?><div class="xs muted"><?= e($r['reviewer']) ?> · <?= e(fdate($r['reviewed_at'])) ?></div><?php endif ?><?php if ($r['review_note']): ?><div class="xs muted">“<?= e(str_limit($r['review_note'], 60)) ?>”</div><?php endif ?><?php if ($r['period_month'] && $payOn): ?><div class="xs muted">Payroll <?= e(date('M Y', strtotime($r['period_month']))) ?></div><?php endif ?></td>
        <td class="actions">
          <?php if ($tab === 'queue' && $r['status'] === 'pending' && (!$own || $sa)): ?><button class="btn sm primary" type="button" data-modal="rv-<?= (int) $r['id'] ?>"><?= icon('check', 'sm') ?> Review</button>
          <?php elseif ($tab === 'queue' && $r['status'] === 'pending'): ?><span class="xs muted">Your own claim</span>
          <?php elseif ($tab === 'mine' && $r['status'] === 'pending'): ?><form method="post" action="<?= e(url('/reimbursements/'.$r['id'].'/delete')) ?>" data-confirm="Withdraw this claim? The receipt is deleted too."><?= csrf_field() ?><button class="btn sm ghost"><?= icon('x', 'sm') ?> Withdraw</button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php if ($tab === 'queue'): foreach ($p['items'] as $r): if ($r['status'] !== 'pending' || ((int) $r['user_id'] === $me && !$sa)) { continue; } ?>
  <dialog class="modal" id="rv-<?= (int) $r['id'] ?>"><div class="modal-head"><h3>Review claim</h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/reimbursements/'.$r['id'].'/approve')) ?>"><?= csrf_field() ?>
      <div class="pay-who"><?= avatar($r['name'], $r['avatar']) ?><div class="grow"><b><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['title']) ?> · <?= e(fdate($r['expense_date'])) ?></div></div><b><?= e(money($r['amount'])) ?></b></div>
      <?php if ($r['description']): ?><p class="small mb0"><?= nl2br(e($r['description'])) ?></p><?php endif ?>
      <div class="chips"><?php foreach ($files[(int) $r['id']] ?? [] as $f): ?><a class="chip" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 'sm') ?> <?= e($f['original_name']) ?></a><?php endforeach ?><?= empty($files[(int) $r['id']]) ? '<span class="pill pill-lemon">No receipt attached</span>' : '' ?></div>
      <div class="field"><label for="rvn-<?= (int) $r['id'] ?>">Note</label><textarea id="rvn-<?= (int) $r['id'] ?>" name="note" rows="3" maxlength="500" placeholder="Required when rejecting"></textarea></div>
      <div class="form-actions"><button class="btn danger" formaction="<?= e(url('/reimbursements/'.$r['id'].'/reject')) ?>"><?= icon('x', 'sm') ?> Reject</button><button class="btn primary"><?= icon('check', 'sm') ?> Approve</button></div>
    </form></dialog>
  <?php endforeach; endif ?>
  <?php endif ?>
</div>
<?php if ($canReq): ?>
<dialog class="modal" id="newClaim"<?= errors() && old('title', null) !== null ? ' data-open' : '' ?>>
  <div class="modal-head"><h3>New reimbursement claim</h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/reimbursements')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="field"><label for="c-title">What was it for? *</label><input id="c-title" type="text" name="title" value="<?= e(old('title')) ?>" maxlength="190" placeholder="e.g. Client meeting travel" required><?= error_for('title') ?></div>
    <div class="form-grid">
      <div class="field"><label for="c-amount">Amount *</label><input id="c-amount" type="number" name="amount" step="0.01" min="0.01" value="<?= e(old('amount')) ?>" required><?= error_for('amount') ?></div>
      <div class="field"><label for="c-date">Expense date *</label><input id="c-date" type="date" name="expense_date" value="<?= e(old('expense_date', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>" required><?= error_for('expense_date') ?></div>
    </div>
    <div class="field"><label for="c-desc">Details</label><textarea id="c-desc" name="description" maxlength="2000" placeholder="Who, where and why, helps approvers decide faster."><?= e(old('description')) ?></textarea><?= error_for('description') ?></div>
    <div class="field"><label for="c-receipt">Receipt</label><input id="c-receipt" class="pay-file" type="file" name="receipt" accept=".pdf,.png,.jpg,.jpeg,.webp"><div class="hint">A clear photo or PDF (PNG, JPG, WEBP or PDF) speeds up approval.</div><?= error_for('file') ?></div>
    <div class="form-actions"><button class="btn ghost" type="button" data-close>Cancel</button><button class="btn primary"><?= icon('send', 'sm') ?> Submit claim</button></div>
  </form>
</dialog>
<?php endif ?>
