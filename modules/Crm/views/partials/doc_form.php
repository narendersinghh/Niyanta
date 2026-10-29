<?php
/* Shared quotation / invoice editor. Expects $kind ('invoice'|'quotation'), $doc, $items, $clients, $projects, $taxRate. */
use Modules\Crm\Services\{Finance, Ui};
$isInv = $kind === 'invoice';
$id = $doc['id'];
$path = $isInv ? '/invoices' : '/quotations';
$v = static fn (string $k, $d = '') => old($k, $doc[$k] ?? $d);
$oldItems = old('items', null);
$rows = is_array($oldItems) ? array_values(array_filter($oldItems, 'is_array')) : $items;
$endKey = $isInv ? 'due_date' : 'valid_until';
$noun = $isInv ? 'invoice' : 'quotation';
$sym = currency_symbol();
$row = static fn (string $i, array $it = []) => '<tr>'
  .'<td class="li-desc" data-l="Description"><input type="text" name="items['.$i.'][description]" value="'.e($it['description'] ?? '').'" placeholder="Service or deliverable" maxlength="500" aria-label="Description"></td>'
  .'<td data-l="Qty"><input type="number" name="items['.$i.'][quantity]" data-f="quantity" value="'.e(isset($it['quantity']) ? Ui::qty($it['quantity']) : '1').'" min="0.01" step="0.01" inputmode="decimal" aria-label="Quantity"></td>'
  .'<td data-l="Rate"><input type="number" name="items['.$i.'][unit_price]" data-f="unit_price" value="'.e($it['unit_price'] ?? '').'" min="0" step="0.01" inputmode="decimal" placeholder="0.00" aria-label="Unit price"></td>'
  .'<td data-l="Tax %"><input type="number" name="items['.$i.'][tax_rate]" data-f="tax_rate" value="'.e($it['tax_rate'] ?? '').'" min="0" max="100" step="0.01" inputmode="decimal" placeholder="0" aria-label="Tax rate"></td>'
  .'<td class="li-amt" data-l="Amount" data-f="amount">0.00</td>'
  .'<td class="li-rm"><button type="button" class="btn icon sm ghost" data-remove-line title="Remove line" aria-label="Remove line">'.icon('trash', 'sm').'</button></td></tr>';
$sec = static fn (string $icon, string $color, string $title, string $hint, string $extra = '') => '<div class="crm-sec-h"><span class="crm-ic crm-c-'.$color.'">'.icon($icon, 'sm').'</span><div class="grow"><h3>'.e($title).'</h3><p>'.e($hint).'</p></div>'.$extra.'</div>';
$terms = $isInv ? (string) setting('crm.invoice_terms', '') : (string) setting('crm.quotation_terms', '');
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => $id ? 'Edit '.$noun.' '.$doc['number'] : 'New '.$noun, 'subtitle' => $isInv ? 'Itemise the work, set a due date and send it. Totals are recalculated securely when you save.' : 'Send a clear, itemised proposal.'.(feature_on('invoices') ? ' Accepted quotations convert to invoices in one click.' : ''), 'crumbs' => [[$isInv ? 'Invoices' : 'Quotations', $path], ...($id ? [[$doc['number'], $path.'/'.$id], ['Edit']] : [['New '.$noun]])]]) ?>
<form method="post" action="<?= e(url($id ? $path.'/'.$id : $path)) ?>" class="crm-docform"><?= csrf_field() ?>
<div class="grid g-side">
  <div class="stack">
    <section class="card form">
      <?= $sec('briefcase', 'lav', 'Client & details', 'Who this '.$noun.' is for and when it is '.($isInv ? 'due' : 'valid').'.', $id && $isInv && (float) $doc['amount_paid'] > 0 ? '<span class="pill pill-lemon">'.e(money($doc['amount_paid'])).' already paid</span>' : '') ?>
      <?php if (!$clients): ?><div class="alert warn"><?= icon('alert') ?><div>You have no active clients yet.<?php if (can('crm.clients.manage')): ?> <a class="b" href="<?= e(url('/clients/create')) ?>">Create a client</a> first.<?php endif ?></div></div><?php endif ?>
      <div class="form-grid">
        <div class="field"><label for="d-client">Client *</label><select id="d-client" name="client_id" required data-client-select><option value="">Select a client…</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $v('client_id')) ?>><?= e($cn) ?></option><?php endforeach ?></select><?= error_for('client_id') ?></div>
        <?php if (feature_on('projects')): ?><div class="field"><label for="d-proj">Project</label><select id="d-proj" name="project_id" data-project-select><option value="">No project</option><?php foreach ($projects as $pid => $pr): ?><option value="<?= (int) $pid ?>" data-client="<?= (int) $pr['client_id'] ?>"<?= selected($pid, $v('project_id')) ?>><?= e($pr['name']) ?><?= $pr['code'] ? ' ('.e($pr['code']).')' : '' ?></option><?php endforeach ?></select><div class="hint">Optional, links it to project financials.</div><?= error_for('project_id') ?></div><?php endif ?>
        <div class="field full"><label for="d-title">Title</label><input id="d-title" type="text" name="title" value="<?= e($v('title')) ?>" maxlength="190" placeholder="<?= $isInv ? 'e.g. Social media retainer, October' : 'e.g. Website redesign proposal' ?>"><?= error_for('title') ?></div>
        <div class="field"><label for="d-issue">Issue date *</label><input id="d-issue" type="date" name="issue_date" value="<?= e($v('issue_date')) ?>" required><?= error_for('issue_date') ?></div>
        <div class="field"><label for="d-end"><?= $isInv ? 'Due date' : 'Valid until' ?></label><input id="d-end" type="date" name="<?= $endKey ?>" value="<?= e($v($endKey)) ?>"><?php if ($isInv): ?><div class="hint">Default: <?= (int) max(0, (int) setting('crm.invoice_due_days', 15)) ?> days after issue.</div><?php endif ?><?= error_for($endKey) ?></div>
        <div class="field"><label for="d-cur">Currency</label><input id="d-cur" type="text" name="currency" value="<?= e($v('currency')) ?>" maxlength="3" placeholder="INR"><?= error_for('currency') ?></div>
      </div>
    </section>
    <section class="card">
      <?= $sec('list', 'mint', 'Line items', 'Quantity × rate, with tax per line. Default tax rate: '.rtrim(rtrim(number_format($taxRate, 2), '0'), '.').'%.') ?>
      <?= error_for('items') ?>
      <table class="table crm-items" data-line-items>
        <thead><tr><th>Description</th><th style="width:92px">Qty</th><th style="width:140px">Rate</th><th style="width:92px">Tax %</th><th class="amt" style="width:130px">Amount</th><th style="width:44px"><span class="sr">Remove</span></th></tr></thead>
        <tbody><?php foreach ($rows as $i => $it): ?><?= $row((string) $i, $it) ?><?php endforeach ?></tbody>
      </table>
      <template><?= $row('__i__', ['tax_rate' => $taxRate ?: '']) ?></template>
      <button type="button" class="crm-addline" data-add-line><?= icon('plus', 'sm') ?> Add line item</button>
    </section>
    <section class="card form">
      <?= $sec('file-text', 'lemon', 'Notes & terms', 'Printed at the bottom of the '.$noun.' and its PDF.') ?>
      <div class="form-grid">
        <div class="field"><label for="d-notes">Notes for the client</label><textarea id="d-notes" name="notes" rows="4" maxlength="5000" placeholder="Thank you for your business!"><?= e($v('notes')) ?></textarea><?= error_for('notes') ?></div>
        <div class="field"><label for="d-terms">Terms & conditions</label><textarea id="d-terms" name="terms" rows="4" maxlength="5000" placeholder="<?= $isInv ? 'Payment due within 15 days by bank transfer / UPI.' : '50% advance, balance on delivery.' ?>"><?= e($v('terms')) ?></textarea><div class="hint"><?= $terms !== '' ? 'Pre-filled from your default terms in Settings.' : 'Tip: set default terms in Settings to pre-fill this.' ?></div><?= error_for('terms') ?></div>
      </div>
    </section>
  </div>
  <div class="stack">
    <section class="card crm-sum">
      <div class="crm-sec-h"><span class="crm-ic crm-c-peach"><?= icon('receipt', 'sm') ?></span><div class="grow"><h3>Summary</h3><p><?= $id ? 'Number '.e($doc['number']).' stays the same.' : 'Numbered automatically ('.e(Finance::prefix($kind)).'00001…)' ?></p></div></div>
      <div class="crm-sum-rows">
        <span class="muted">Subtotal</span><span><?= e($sym) ?><span data-total="subtotal">0.00</span></span>
        <span class="muted">Tax</span><span><?= e($sym) ?><span data-total="tax">0.00</span></span>
        <span class="muted">Discount</span><span>− <?= e($sym) ?><span data-total="discount">0.00</span></span>
      </div>
      <div class="crm-sum-total"><span>Total</span><b><?= e($sym) ?><span data-total="total">0.00</span></b></div>
      <div class="field"><label for="d-disc">Discount (amount)</label><input id="d-disc" type="number" name="discount" value="<?= e($v('discount', 0)) ?>" min="0" step="0.01" inputmode="decimal"><div class="hint">A flat amount taken off after tax.</div><?= error_for('discount') ?></div>
    </section>
  </div>
</div>
<div class="crm-actionbar">
  <span class="crm-ab-info">Total <b><?= e($sym) ?><span data-total="total">0.00</span></b></span>
  <a class="btn ghost" href="<?= e(url($id ? $path.'/'.$id : $path)) ?>">Cancel</a>
  <button type="submit" class="btn primary"><?= icon('check', 'sm') ?> <?= $id ? 'Save changes' : ($isInv ? 'Create draft invoice' : 'Create quotation') ?></button>
</div>
</form>
</div>
