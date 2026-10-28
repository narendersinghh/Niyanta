<?php
use Modules\Crm\Services\Finance;
$id = (int) $doc['id'];
$m = can('crm.finance.manage');
$bal = max(0, round((float) $doc['total'] - (float) $doc['amount_paid'], 2));
$st = $doc['status'];
$pct = (float) $doc['total'] > 0 ? min(100, (int) round((float) $doc['amount_paid'] / (float) $doc['total'] * 100)) : 0;
$days = $doc['due_date'] ? (int) floor((strtotime(date('Y-m-d')) - strtotime($doc['due_date'])) / 86400) : 0;
$actions = '<a class="btn ghost" href="'.e(url('/invoices/'.$id.'/pdf')).'">'.icon('download', 'sm').' PDF</a>';
if ($m && !in_array($st, ['paid', 'cancelled'], true)) { $actions .= '<a class="btn ghost" href="'.e(url('/invoices/'.$id.'/edit')).'">'.icon('edit', 'sm').' Edit</a>'; }
if ($m && $st !== 'cancelled') { $actions .= '<button type="button" class="btn soft" data-modal="email-modal">'.icon('send', 'sm').' Email</button>'; }
if ($m && $st === 'draft') { $actions .= '<form method="post" action="'.e(url('/invoices/'.$id.'/send')).'">'.csrf_field().'<button class="btn ghost">'.icon('check', 'sm').' Mark sent</button></form>'; }
if ($m && $bal > 0 && $st !== 'cancelled') { $actions .= '<button type="button" class="btn primary" data-modal="pay-modal">'.icon('wallet', 'sm').' Record payment</button>'; }
if ($m) {
  $more = '';
  if ($st !== 'cancelled' && (float) $doc['amount_paid'] <= 0) { $more .= '<form method="post" action="'.e(url('/invoices/'.$id.'/cancel')).'" data-confirm="Cancel this invoice? It will no longer count as outstanding.">'.csrf_field().'<button>'.icon('x', 'sm').' Cancel invoice</button></form>'; }
  if (in_array($st, ['draft', 'cancelled'], true) && !$payments) { $more .= '<form method="post" action="'.e(url('/invoices/'.$id.'/delete')).'" data-confirm="Delete this invoice permanently?">'.csrf_field().'<button class="crm-danger">'.icon('trash', 'sm').' Delete</button></form>'; }
  if ($more) { $actions .= '<details class="dd"><summary class="btn icon ghost" aria-label="More">'.icon('more', 'sm').'</summary><div class="dd-menu">'.$more.'</div></details>'; } }
?>
<?= partial('partials/page_head', ['title' => 'Invoice '.$doc['number'], 'subtitle' => ($doc['client_company'] ?: $doc['client_name']).($doc['title'] ? ' · '.$doc['title'] : ''), 'crumbs' => [['Invoices', '/invoices'], [$doc['number']]], 'actions' => $actions]) ?>
<?php if ($st === 'overdue'): ?><div class="alert error"><?= icon('alert') ?><div>This invoice is <b><?= $days ?> day<?= $days === 1 ? '' : 's' ?> overdue</b> with <?= e(money($bal)) ?> outstanding.</div></div>
<?php elseif ($st === 'draft'): ?><div class="alert info"><?= icon('info') ?><div>This is a <b>draft</b>. Mark it as sent (or e-mail it) once it is ready — drafts are not counted as outstanding.</div></div>
<?php elseif ($st === 'cancelled'): ?><div class="alert warn"><?= icon('info') ?><div>This invoice was cancelled.</div></div><?php endif ?>
<div class="grid g-side">
  <div><?= partial('crm::partials/doc_paper', ['kind' => 'invoice', 'doc' => $doc, 'items' => $items, 'client' => $client]) ?></div>
  <div class="stack">
    <div class="card crm-balance <?= $bal > 0 && $st !== 'cancelled' ? '' : 'paid' ?>">
      <div class="flex between"><span class="lbl">Balance due</span><?= badge($st) ?></div>
      <div class="crm-big"><?= e(money($st === 'cancelled' ? 0 : $bal)) ?></div>
      <div class="progress mt"><span style="width:<?= $pct ?>%"></span></div>
      <div class="flex between xs mt"><span><?= e(money($doc['amount_paid'])) ?> paid</span><span>of <?= e(money($doc['total'])) ?></span></div>
      <?php if ($doc['due_date'] && $bal > 0 && $st !== 'cancelled'): ?><div class="xs mt"><?= icon('calendar', 'sm') ?> <?= $days > 0 ? e($days.' days past due') : ($days === 0 ? 'Due today' : 'Due in '.e(-$days).' days') ?> · <?= e(fdate($doc['due_date'])) ?></div><?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Payments</h3><?php if ($m && $bal > 0 && $st !== 'cancelled'): ?><button type="button" class="btn sm mint" data-modal="pay-modal"><?= icon('plus', 'sm') ?> Add</button><?php endif ?></div>
      <?php if (!$payments): ?><div class="empty crm-empty-sm"><?= illus('money-wallet', 'empty-illus') ?><p class="small muted mb0">No payments recorded yet.</p></div><?php else: ?>
      <div class="list"><?php foreach ($payments as $py): ?>
        <div class="list-item"><span class="notif-ic soft-mint"><?= icon('wallet', 'sm') ?></span>
          <div class="grow"><b class="crm-pos"><?= e(money($py['amount'])) ?></b><div class="meta"><?= e(fdate($py['paid_on'])) ?> · <?= e(Finance::METHODS[$py['method']] ?? $py['method']) ?><?= $py['reference'] ? ' · '.e($py['reference']) : '' ?></div><?php if ($py['note']): ?><div class="xs muted"><?= e($py['note']) ?></div><?php endif ?><div class="xs muted">by <?= e($py['recorder'] ?? '—') ?></div></div>
          <?php if ($m): ?><form method="post" action="<?= e(url('/invoices/'.$id.'/payments/'.$py['id'].'/delete')) ?>" data-confirm="Remove this payment? The invoice balance will be recalculated."><?= csrf_field() ?><button class="btn icon sm ghost" title="Remove"><?= icon('trash', 'sm') ?></button></form><?php endif ?>
        </div>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Client</h3></div>
      <a class="flex" href="<?= e(url('/clients/'.(int) $client['id'])) ?>"><?= avatar($client['company'] ?: $client['name'], null) ?><div class="grow"><b><?= e($client['company'] ?: $client['name']) ?></b><div class="xs muted"><?= e($client['email'] ?: $client['phone'] ?: '') ?></div></div><?= icon('chevron-right', 'sm') ?></a>
      <?php if ($quote): ?><hr><div class="small">Created from quotation <a class="b" href="<?= e(url('/quotations/'.$quote['id'])) ?>"><?= e($quote['number']) ?></a></div><?php endif ?>
      <hr><dl class="kv small"><dt>Created</dt><dd><?= e(fdatetime($doc['created_at'])) ?></dd><dt>Updated</dt><dd><?= e(fdatetime($doc['updated_at'])) ?></dd><?php if ($doc['last_reminder_at']): ?><dt>Last reminder</dt><dd><?= e(fdatetime($doc['last_reminder_at'])) ?></dd><?php endif ?></dl>
    </div>
  </div>
</div>
<?php if ($m): ?>
<dialog class="modal" id="pay-modal">
  <form method="post" action="<?= e(url('/invoices/'.$id.'/payments')) ?>"><?= csrf_field() ?>
    <div class="modal-head"><h3>Record payment</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <div class="alert ok mb0"><?= icon('wallet') ?><div>Balance due on <?= e($doc['number']) ?>: <b><?= e(money($bal)) ?></b>. Partial payments are welcome.</div></div>
      <div class="form-grid">
        <div class="field"><label>Amount *</label><input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($bal, 2, '.', '')) ?>" value="<?= e(number_format($bal, 2, '.', '')) ?>" required><?= error_for('amount') ?></div>
        <div class="field"><label>Paid on *</label><input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required><?= error_for('paid_on') ?></div>
        <div class="field"><label>Method</label><select name="method"><?php foreach (Finance::METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label>Reference</label><input type="text" name="reference" maxlength="120" placeholder="UTR / cheque no."></div>
        <div class="field full"><label>Note</label><input type="text" name="note" maxlength="500"></div>
      </div>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save payment</button></div>
    </div>
  </form>
</dialog>
<dialog class="modal" id="email-modal">
  <form method="post" action="<?= e(url('/invoices/'.$id.'/email')) ?>"><?= csrf_field() ?>
    <div class="modal-head"><h3>Email invoice</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <p class="small muted mb0">The client receives a branded e-mail with a secure link to download the PDF.<?= $st === 'draft' ? ' The invoice will be marked as sent.' : '' ?></p>
      <div class="field"><label>To *</label><input type="email" name="to" value="<?= e($contactEmail ?? '') ?>" required maxlength="190"><?= error_for('to') ?></div>
      <div class="field"><label>Message</label><textarea name="message" rows="4" maxlength="2000" placeholder="Hi, please find this month's invoice. Let us know if you have any questions."></textarea></div>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('send', 'sm') ?> Queue e-mail</button></div>
    </div>
  </form>
</dialog>
<?php endif ?>
