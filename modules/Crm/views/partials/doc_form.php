<?php
/* Shared quotation / invoice editor. Expects $kind ('invoice'|'quotation'), $doc, $items, $clients, $projects, $taxRate. */
$isInv = $kind === 'invoice';
$id = $doc['id'];
$path = $isInv ? '/invoices' : '/quotations';
$v = static fn (string $k, $d = '') => old($k, $doc[$k] ?? $d);
$oldItems = old('items', null);
$rows = is_array($oldItems) ? array_values(array_filter($oldItems, 'is_array')) : $items;
$endKey = $isInv ? 'due_date' : 'valid_until';
$noun = $isInv ? 'invoice' : 'quotation';
$row = static fn (string $i, array $it = []) => '<tr>'
  .'<td><input type="text" name="items['.$i.'][description]" value="'.e($it['description'] ?? '').'" placeholder="Service or deliverable" maxlength="500" aria-label="Description"></td>'
  .'<td><input type="number" name="items['.$i.'][quantity]" data-f="quantity" value="'.e(isset($it['quantity']) ? (str_contains((string) $it['quantity'], '.') ? rtrim(rtrim((string) $it['quantity'], '0'), '.') : (string) $it['quantity']) : '1').'" min="0.01" step="0.01" aria-label="Quantity"></td>'
  .'<td><input type="number" name="items['.$i.'][unit_price]" data-f="unit_price" value="'.e($it['unit_price'] ?? '').'" min="0" step="0.01" placeholder="0.00" aria-label="Unit price"></td>'
  .'<td><input type="number" name="items['.$i.'][tax_rate]" data-f="tax_rate" value="'.e($it['tax_rate'] ?? '').'" min="0" max="100" step="0.01" aria-label="Tax rate"></td>'
  .'<td class="right nowrap b" data-f="amount">0.00</td>'
  .'<td><button type="button" class="btn icon sm ghost" data-remove-line title="Remove line">'.icon('trash', 'sm').'</button></td></tr>';
?>
<?= partial('partials/page_head', ['title' => $id ? 'Edit '.$noun.' '.$doc['number'] : 'New '.$noun, 'subtitle' => $isInv ? 'Totals are recalculated on the server when you save.' : 'Send a clear, itemised proposal. Accepted quotations convert to invoices in one click.', 'crumbs' => [[$isInv ? 'Invoices' : 'Quotations', $path], [$id ? $doc['number'] : 'New', $id ? $path.'/'.$id : null], ...($id ? [['Edit']] : [])]]) ?>
<form method="post" action="<?= e(url($id ? $path.'/'.$id : $path)) ?>" class="grid g-side crm-docform"><?= csrf_field() ?>
  <div class="stack">
    <div class="card form">
      <div class="card-head"><h3><?= icon('briefcase') ?> Client & details</h3><?php if ($id && $isInv && (float) $doc['amount_paid'] > 0): ?><span class="pill pill-lemon"><?= e(money($doc['amount_paid'])) ?> already paid</span><?php endif ?></div>
      <?php if (!$clients): ?><div class="alert warn"><?= icon('alert') ?><div>You have no active clients yet. <a class="b" href="<?= e(url('/clients/create')) ?>">Create a client</a> first.</div></div><?php endif ?>
      <div class="form-grid">
        <div class="field"><label>Client *</label><select name="client_id" required data-client-select><option value="">Select a client…</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $v('client_id')) ?>><?= e($cn) ?></option><?php endforeach ?></select><?= error_for('client_id') ?></div>
        <div class="field"><label>Project</label><select name="project_id" data-project-select><option value="">No project</option><?php foreach ($projects as $pid => $pr): ?><option value="<?= (int) $pid ?>" data-client="<?= (int) $pr['client_id'] ?>"<?= selected($pid, $v('project_id')) ?>><?= e($pr['name']) ?><?= $pr['code'] ? ' ('.e($pr['code']).')' : '' ?></option><?php endforeach ?></select><?= error_for('project_id') ?></div>
        <div class="field full"><label>Title</label><input type="text" name="title" value="<?= e($v('title')) ?>" maxlength="190" placeholder="<?= $isInv ? 'e.g. Social media retainer — October' : 'e.g. Website redesign proposal' ?>"><?= error_for('title') ?></div>
        <div class="field"><label>Issue date *</label><input type="date" name="issue_date" value="<?= e($v('issue_date')) ?>" required><?= error_for('issue_date') ?></div>
        <div class="field"><label><?= $isInv ? 'Due date' : 'Valid until' ?></label><input type="date" name="<?= $endKey ?>" value="<?= e($v($endKey)) ?>"><?= error_for($endKey) ?></div>
        <div class="field"><label>Currency</label><input type="text" name="currency" value="<?= e($v('currency')) ?>" maxlength="3" placeholder="INR"><?= error_for('currency') ?></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3><?= icon('list') ?> Line items</h3><button type="button" class="btn sm soft" data-add-line><?= icon('plus', 'sm') ?> Add line</button></div>
      <?= error_for('items') ?>
      <div class="table-wrap">
        <table class="table line-items crm-items" data-line-items>
          <thead><tr><th>Description</th><th style="width:90px">Qty</th><th style="width:140px">Unit price</th><th style="width:96px">Tax %</th><th class="right" style="width:130px">Amount</th><th style="width:46px"></th></tr></thead>
          <tbody><?php foreach ($rows as $i => $it): ?><?= $row((string) $i, $it) ?><?php endforeach ?></tbody>
        </table>
        <template><?= $row('__i__', ['tax_rate' => $taxRate ?: '']) ?></template>
      </div>
      <button type="button" class="btn sm ghost mt" data-add-line><?= icon('plus', 'sm') ?> Add another line</button>
    </div>
    <div class="card form">
      <div class="card-head"><h3><?= icon('file-text') ?> Notes & terms</h3></div>
      <div class="form-grid">
        <div class="field"><label>Notes for the client</label><textarea name="notes" rows="4" maxlength="5000" placeholder="Thank you for your business!"><?= e($v('notes')) ?></textarea><?= error_for('notes') ?></div>
        <div class="field"><label>Terms & conditions</label><textarea name="terms" rows="4" maxlength="5000" placeholder="<?= $isInv ? 'Payment due within 15 days by bank transfer / UPI.' : '50% advance, balance on delivery.' ?>"><?= e($v('terms')) ?></textarea><?= error_for('terms') ?></div>
      </div>
    </div>
  </div>
  <div class="stack">
    <div class="card crm-sticky">
      <h3>Summary</h3>
      <div class="totals crm-totals-full mt">
        <span class="muted">Subtotal</span><span data-total="subtotal">0.00</span>
        <span class="muted">Tax</span><span data-total="tax">0.00</span>
        <span class="muted">Discount</span><span>− <span data-total="discount">0.00</span></span>
        <span class="grand">Total</span><span class="grand"><?= e(currency_symbol()) ?><span data-total="total">0.00</span></span>
      </div>
      <div class="field mt"><label>Discount (amount)</label><input type="number" name="discount" value="<?= e($v('discount', 0)) ?>" min="0" step="0.01"><?= error_for('discount') ?></div>
      <div class="form-actions mt"><a class="btn ghost" href="<?= e(url($id ? $path.'/'.$id : $path)) ?>">Cancel</a><button class="btn primary"><?= icon('check', 'sm') ?> <?= $id ? 'Save changes' : 'Create '.$noun ?></button></div>
      <p class="xs muted mt mb0"><?= $id ? 'Number '.e($doc['number']).' stays the same.' : 'A number like '.e(\Modules\Crm\Services\Finance::prefix($kind)).'00001 is assigned automatically.' ?></p>
    </div>
    <div class="card soft-lemon crm-borderless"><?= illus($isInv ? 'invoice-paper' : 'money-wallet') ?><p class="small mb0 mt">Default tax rate is <b><?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?>%</b>. Override it per line — leave 0 for exempt services.</p></div>
  </div>
</form>
