<?php
$q = static fn (array $add) => e(url('/reimbursements').query_merge($add));
$statuses = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected'];
?>
<?= partial('partials/page_head', ['title' => 'Reimbursements', 'subtitle' => 'Claim back business expenses. Approved claims are paid with your next payslip.', 'crumbs' => [['People'], ['Reimbursements']], 'actions' => $canReq ? '<button class="btn primary" type="button" data-modal="newClaim">'.icon('plus', 'sm').' New claim</button>' : '']) ?>
<?php if ($canReq && $canApp): ?><nav class="tabs dark-tabs pay-tabs"><a href="<?= e(url('/reimbursements?tab=mine')) ?>" class="<?= $tab === 'mine' ? 'active' : '' ?>"><?= icon('user', 'sm') ?> My claims</a><a href="<?= e(url('/reimbursements?tab=queue')) ?>" class="<?= $tab === 'queue' ? 'active' : '' ?>"><?= icon('check-square', 'sm') ?> Approvals<?= (int) $queue['n'] ? ' <span class="pill pill-peach">'.(int) $queue['n'].'</span>' : '' ?></a></nav><?php endif ?>
<div class="grid g4 mb">
  <?php if ($tab === 'queue'): ?>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $queue['n'] ?></div><div class="lbl">Claims awaiting review</div></div></div>
  <div class="tile bg-peach pay-tile"><span class="tile-ic"><?= icon('receipt') ?></span><div><div class="num pay-num"><?= e(money($queue['amt'])) ?></div><div class="lbl">Pending amount</div></div><?= illus('invoice-paper', 'corner') ?></div>
  <?php else: ?>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num pay-num"><?= e(money($mine['pending'])) ?></div><div class="lbl">Awaiting approval · <?= (int) $mine['pending_n'] ?></div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num pay-num"><?= e(money($mine['approved'])) ?></div><div class="lbl">Approved, paid with next payslip</div></div></div>
  <?php endif ?>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e(money($mine['paid'])) ?></div><div class="lbl">Reimbursed to me in <?= date('Y') ?></div></div></div>
  <div class="tile dark"><span class="tile-ic"><?= icon('info') ?></span><div><div class="lbl" style="opacity:.85">Attach a clear receipt (PDF or image). Claims are reviewed by HR and added to the next payroll run.</div></div></div>
</div>
<div class="card">
  <div class="filters">
    <div class="chips"><?php foreach ($statuses as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= $q(['status' => $k, 'page' => 1, 'tab' => $tab]) ?>"><?= e($l) ?></a><?php endforeach ?></div>
    <?php if ($tab === 'queue'): ?><form method="get" class="flex"><input type="hidden" name="tab" value="queue"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee or title"></form><?php endif ?>
  </div>
  <?php if (!$p['items']): ?>
    <?= $tab === 'queue' ? empty_state('Nothing to review', 'All claims have been processed. Nice work!', 'celebrate') : empty_state('No claims here', 'Spent money on work travel, software or client meetings? Submit a claim and get it back with your salary.', 'invoice-paper', $canReq ? '<button class="btn primary" type="button" data-modal="newClaim">'.icon('plus', 'sm').' New claim</button>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><?php if ($tab === 'queue'): ?><th>Employee</th><?php endif ?><th>Claim</th><th>Expense date</th><th class="r">Amount</th><th>Receipt</th><th>Status</th><th><?= $tab === 'queue' ? 'Review' : '' ?></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r): $own = (int) $r['user_id'] === (int) user()['id']; ?>
      <tr>
        <?php if ($tab === 'queue'): ?><td><div class="flex"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['employee_code'] ?? '') ?></div></div></div></td><?php endif ?>
        <td><b><?= e($r['title']) ?></b><?php if ($r['description']): ?><div class="xs muted"><?= e(str_limit($r['description'], 90)) ?></div><?php endif ?><div class="xs muted">Submitted <?= e(ago($r['created_at'])) ?></div></td>
        <td class="small nowrap"><?= e(fdate($r['expense_date'])) ?></td>
        <td class="r nowrap"><b><?= e(money($r['amount'])) ?></b></td>
        <td class="small"><?php foreach ($files[(int) $r['id']] ?? [] as $f): ?><a class="chip" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 'sm') ?> <?= e(str_limit($f['original_name'], 18)) ?></a><?php endforeach ?><?= empty($files[(int) $r['id']]) ? '<span class="muted">—</span>' : '' ?></td>
        <td><?= badge($r['status']) ?><?php if ($r['reviewer']): ?><div class="xs muted"><?= e($r['reviewer']) ?> · <?= e(fdate($r['reviewed_at'])) ?></div><?php endif ?><?php if ($r['review_note']): ?><div class="xs muted">“<?= e(str_limit($r['review_note'], 60)) ?>”</div><?php endif ?><?php if ($r['period_month']): ?><div class="xs muted">Payroll <?= e(date('M Y', strtotime($r['period_month']))) ?></div><?php endif ?></td>
        <td class="actions">
          <?php if ($tab === 'queue' && $r['status'] === 'pending' && (!$own || user()['role_slug'] === 'super_admin')): ?>
            <form class="pay-review" method="post" action="<?= e(url('/reimbursements/'.$r['id'].'/approve')) ?>"><?= csrf_field() ?><input type="text" name="note" maxlength="500" placeholder="Note (required to reject)" aria-label="Review note"><button class="btn sm mint" title="Approve"><?= icon('check', 'sm') ?> Approve</button><button class="btn sm danger" formaction="<?= e(url('/reimbursements/'.$r['id'].'/reject')) ?>" title="Reject"><?= icon('x', 'sm') ?></button></form>
          <?php elseif ($tab === 'queue' && $r['status'] === 'pending'): ?><span class="xs muted">Your own claim</span>
          <?php elseif ($tab === 'mine' && $r['status'] === 'pending'): ?>
            <form method="post" action="<?= e(url('/reimbursements/'.$r['id'].'/delete')) ?>" data-confirm="Withdraw this claim?"><?= csrf_field() ?><button class="btn sm ghost"><?= icon('trash', 'sm') ?> Withdraw</button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
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
    <div class="field"><label for="c-desc">Details</label><textarea id="c-desc" name="description" maxlength="2000" placeholder="Who, where and why — helps approvers decide faster."><?= e(old('description')) ?></textarea><?= error_for('description') ?></div>
    <div class="field"><label for="c-receipt">Receipt</label><input id="c-receipt" type="file" name="receipt" accept=".pdf,.png,.jpg,.jpeg,.webp"><div class="hint">PDF, PNG, JPG or WEBP.</div><?= error_for('file') ?></div>
    <div class="form-actions"><button class="btn ghost" type="button" data-close>Cancel</button><button class="btn primary"><?= icon('send', 'sm') ?> Submit claim</button></div>
  </form>
</dialog>
<?php endif ?>
