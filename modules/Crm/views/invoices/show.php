<?php
use Modules\Crm\Services\{Finance, Ui};
$id = (int) $doc['id'];
$m = can('crm.finance.manage');
$st = $doc['status'];
$bal = $st === 'cancelled' ? 0.0 : max(0, round((float) $doc['total'] - (float) $doc['amount_paid'], 2));
$pct = (float) $doc['total'] > 0 ? min(100, (int) round((float) $doc['amount_paid'] / (float) $doc['total'] * 100)) : 0;
$days = Ui::daysPast($doc['due_date']);
$client_label = $doc['client_company'] ?: $doc['client_name'];
$canPay = $m && $bal > 0 && $st !== 'cancelled';
$canEdit = $m && !in_array($st, ['paid', 'cancelled'], true);
$pdf = '<a class="btn ghost" href="'.e(url('/invoices/'.$id.'/pdf')).'">'.icon('download', 'sm').' PDF</a>';
$primary = '';
if ($m && $st === 'draft') { $primary = '<button type="button" class="btn primary" data-modal="email-modal">'.icon('send', 'sm').' Send invoice</button>'; }
elseif ($canPay) { $primary = '<button type="button" class="btn primary" data-modal="pay-modal">'.icon('wallet', 'sm').' Record payment</button>'; }
$more = $m ? Ui::more([
  $canEdit ? Ui::link('/invoices/'.$id.'/edit', 'edit', 'Edit invoice') : '',
  $st !== 'cancelled' && $st !== 'draft' ? '<button type="button" data-modal="email-modal">'.icon('send', 'sm').' Email to client</button>' : '',
  $st === 'draft' ? Ui::post('/invoices/'.$id.'/send', 'check', 'Mark as sent (no e-mail)') : '',
  $canPay && $st === 'draft' ? '<button type="button" data-modal="pay-modal">'.icon('wallet', 'sm').' Record payment</button>' : '',
  $st !== 'cancelled' && (float) $doc['amount_paid'] <= 0 ? Ui::post('/invoices/'.$id.'/cancel', 'x', 'Cancel invoice', 'Cancel this invoice? It will no longer count as outstanding.', true) : '',
  in_array($st, ['draft', 'cancelled'], true) && !$payments ? Ui::post('/invoices/'.$id.'/delete', 'trash', 'Delete invoice', 'Delete this invoice permanently?', true) : '']) : '';
if ($primary === '') { $pdf = str_replace('btn ghost', 'btn primary', $pdf); }
$balClass = match (true) { $st === 'cancelled' => 'is-muted', $st === 'paid' || $bal <= 0 => 'is-paid', $st === 'overdue' => 'is-overdue', default => '' };
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => 'Invoice '.$doc['number'], 'subtitle' => $client_label.($doc['title'] ? ' · '.$doc['title'] : ''), 'crumbs' => [['Invoices', '/invoices'], [$doc['number']]], 'actions' => $pdf.$more.$primary]) ?>
<?php if ($st === 'overdue'): ?><div class="alert error"><?= icon('alert') ?><div>This invoice is <b><?= $days ?> day<?= $days === 1 ? '' : 's' ?> overdue</b> with <?= e(money($bal)) ?> outstanding.<?php if ($m): ?> Send a reminder or record a payment.<?php endif ?></div></div>
<?php elseif ($st === 'draft'): ?><div class="alert info"><?= icon('info') ?><div>This is a <b>draft</b> — it does not count as outstanding until it is sent.</div></div>
<?php elseif ($st === 'cancelled'): ?><div class="alert warn"><?= icon('info') ?><div>This invoice was cancelled and is excluded from all totals.</div></div><?php endif ?>
<div class="grid g-side">
  <div><?= partial('crm::partials/doc_paper', ['kind' => 'invoice', 'doc' => $doc, 'items' => $items, 'client' => $client]) ?></div>
  <div class="stack">
    <div class="card crm-bal <?= $balClass ?>">
      <div class="flex between"><span class="crm-bal-lbl"><?= $bal > 0 ? 'Balance due' : ($st === 'cancelled' ? 'Cancelled' : 'Paid in full') ?></span><?= badge($st) ?></div>
      <div class="crm-big"><?= e(money($bal > 0 ? $bal : ($st === 'cancelled' ? 0 : $doc['total']))) ?></div>
      <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
      <div class="crm-bal-meta"><span><?= e(money($doc['amount_paid'])) ?> paid</span><span>of <?= e(money($doc['total'])) ?></span></div>
      <?php if ($doc['due_date'] && $bal > 0 && $st !== 'cancelled'): ?><span class="crm-bal-due"><?= icon('calendar', 'sm') ?> <?= $days > 0 ? e($days.' day'.($days === 1 ? '' : 's').' past due') : ($days === 0 ? 'Due today' : 'Due in '.e(-$days).' day'.(-$days === 1 ? '' : 's')) ?> · <?= e(fdate($doc['due_date'])) ?></span><?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Payments</h3><?php if ($canPay): ?><button type="button" class="btn sm soft" data-modal="pay-modal"><?= icon('plus', 'sm') ?> Add</button><?php endif ?></div>
      <?php if (!$payments): ?><div class="list-item"><span class="crm-ic crm-c-mint"><?= icon('wallet', 'sm') ?></span><p class="small muted mb0">No payments recorded yet. Partial payments are supported.</p></div><?php else: ?>
      <?php foreach ($payments as $py): ?>
        <div class="crm-pay"><span class="crm-ic crm-c-mint"><?= icon('check', 'sm') ?></span>
          <div class="grow"><div class="flex between"><b class="crm-pos amt">+<?= e(money($py['amount'])) ?></b><span class="xs muted"><?= e(fdate($py['paid_on'])) ?></span></div><div class="crm-sub"><?= e(Finance::METHODS[$py['method']] ?? $py['method']) ?><?= $py['reference'] ? ' · '.e($py['reference']) : '' ?> · by <?= e($py['recorder'] ?? '—') ?></div><?php if ($py['note']): ?><div class="crm-sub"><?= e($py['note']) ?></div><?php endif ?></div>
          <?php if ($m): ?><form method="post" action="<?= e(url('/invoices/'.$id.'/payments/'.$py['id'].'/delete')) ?>" data-confirm="Remove this payment? The invoice balance will be recalculated."><?= csrf_field() ?><button class="crm-go" title="Remove payment" aria-label="Remove payment"><?= icon('trash', 'sm') ?></button></form><?php endif ?>
        </div>
      <?php endforeach ?>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Client</h3></div>
      <?php if (can('crm.clients.view')): ?><a class="crm-party" href="<?= e(url('/clients/'.(int) $client['id'])) ?>"><?php else: ?><div class="crm-party"><?php endif ?>
        <?= avatar($client['company'] ?: $client['name'], null) ?><div class="grow"><b><?= e($client['company'] ?: $client['name']) ?></b><div class="crm-sub"><?= e($client['email'] ?: $client['phone'] ?: '') ?></div></div><?= can('crm.clients.view') ? icon('chevron-right', 'sm').'</a>' : '</div>' ?>
      <dl class="crm-facts">
        <?php if ($quote): ?><dt>From quotation</dt><dd><?= can('crm.quotations.view') ? '<a href="'.e(url('/quotations/'.$quote['id'])).'">'.e($quote['number']).'</a>' : e($quote['number']) ?></dd><?php endif ?>
        <dt>Created</dt><dd><?= e(fdatetime($doc['created_at'])) ?></dd>
        <dt>Updated</dt><dd><?= e(fdatetime($doc['updated_at'])) ?></dd>
        <?php if ($doc['last_reminder_at']): ?><dt>Last reminder</dt><dd><?= e(fdatetime($doc['last_reminder_at'])) ?></dd><?php endif ?>
      </dl>
    </div>
  </div>
</div>
<?php if ($m): ?>
<dialog class="modal" id="pay-modal">
  <form method="post" action="<?= e(url('/invoices/'.$id.'/payments')) ?>"><?= csrf_field() ?>
    <div class="modal-head"><div class="flex"><span class="crm-ic solid crm-c-mint"><?= icon('wallet', 'sm') ?></span><div><h3>Record payment</h3><div class="crm-sub"><?= e($doc['number']) ?> · <?= e($client_label) ?></div></div></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <div class="crm-fin-split"><div><span>Total</span><b><?= e(money($doc['total'])) ?></b></div><div><span>Paid so far</span><b class="crm-pos"><?= e(money($doc['amount_paid'])) ?></b></div><div><span>Balance due</span><b><?= e(money($bal)) ?></b></div></div>
      <div class="form-grid">
        <div class="field"><label for="p-amt">Amount received *</label><input id="p-amt" type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($bal, 2, '.', '')) ?>" value="<?= e(number_format($bal, 2, '.', '')) ?>" required inputmode="decimal"><div class="hint">Enter less than the balance for a partial payment.</div><?= error_for('amount') ?></div>
        <div class="field"><label for="p-date">Paid on *</label><input id="p-date" type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required><?= error_for('paid_on') ?></div>
        <div class="field"><label for="p-method">Method</label><select id="p-method" name="method"><?php foreach (Finance::METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="p-ref">Reference</label><input id="p-ref" type="text" name="reference" maxlength="120" placeholder="UTR / cheque no."></div>
        <div class="field full"><label for="p-note">Note</label><input id="p-note" type="text" name="note" maxlength="500" placeholder="Optional"></div>
      </div>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save payment</button></div>
    </div>
  </form>
</dialog>
<dialog class="modal" id="email-modal">
  <form method="post" action="<?= e(url('/invoices/'.$id.'/email')) ?>"><?= csrf_field() ?>
    <div class="modal-head"><div class="flex"><span class="crm-ic solid crm-c-lav"><?= icon('send', 'sm') ?></span><div><h3>Email invoice</h3><div class="crm-sub"><?= e($doc['number']) ?> · <?= e(money($doc['total'])) ?></div></div></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-body form">
      <p class="small muted mb0">The client receives a branded e-mail with a secure link to download the PDF.<?= $st === 'draft' ? ' The invoice will be marked as sent.' : '' ?></p>
      <div class="field"><label for="e-to">To *</label><input id="e-to" type="email" name="to" value="<?= e($contactEmail ?? '') ?>" required maxlength="190"><?= error_for('to') ?></div>
      <div class="field"><label for="e-msg">Message</label><textarea id="e-msg" name="message" rows="4" maxlength="2000" placeholder="Hi, please find this month's invoice. Let us know if you have any questions."></textarea></div>
      <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('send', 'sm') ?> Send e-mail</button></div>
    </div>
  </form>
</dialog>
<?php endif ?>
</div>
