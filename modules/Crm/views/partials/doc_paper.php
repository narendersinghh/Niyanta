<?php
/* On-screen document preview matching the PDF (always rendered as light "paper"). Expects $kind, $doc, $items, $client. */
use Modules\Crm\Services\Ui;
$isInv = $kind === 'invoice';
$bal = $isInv ? max(0, (float) $doc['total'] - (float) $doc['amount_paid']) : 0;
$app = company_name();
$from = array_filter([(string) setting('company.address', ''), (string) setting('company.email', ''), (string) setting('company.phone', ''), ($t = (string) setting('company.tax_id', '')) !== '' ? 'Tax ID: '.$t : '']);
$stamp = ['paid' => 'Paid', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled', 'draft' => 'Draft', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'expired' => 'Expired'][$doc['status']] ?? null;
?>
<div class="crm-stage">
<article class="crm-paper" aria-label="<?= $isInv ? 'Invoice' : 'Quotation' ?> preview">
  <header class="crm-paper-band">
    <div class="flex"><span class="crm-paper-logo"><?= e(mb_strtoupper(mb_substr($app, 0, 1))) ?></span><div><b><?= e($app) ?></b><?php if ($tag = (string) setting('branding.tagline', '')): ?><small><?= e($tag) ?></small><?php endif ?></div></div>
    <div class="right"><div class="crm-paper-kind"><?= $isInv ? 'INVOICE' : 'QUOTATION' ?></div><div class="crm-paper-no"># <?= e($doc['number']) ?></div></div>
  </header>
  <div class="crm-paper-accent"></div>
  <?php if ($stamp): ?><div class="crm-stamp <?= e($doc['status']) ?>"><?= e(strtoupper($stamp)) ?></div><?php endif ?>
  <div class="crm-paper-body">
    <div class="crm-paper-parties">
      <div>
        <div class="crm-label">Bill to</div>
        <b class="crm-paper-client"><?= e($client['company'] ?: $client['name']) ?></b>
        <div class="crm-paper-lines"><?php foreach (array_filter([$client['company'] ? 'Attn: '.$client['name'] : null, $client['address'], trim(implode(', ', array_filter([$client['city'], $client['state'], $client['postal_code']]))), $client['country'], $client['email'], $client['phone'], $client['tax_id'] ? 'Tax ID: '.$client['tax_id'] : null]) as $l): ?><div><?= e($l) ?></div><?php endforeach ?></div>
      </div>
      <div>
        <div class="crm-label">From</div>
        <b class="crm-paper-client"><?= e($app) ?></b>
        <div class="crm-paper-lines"><?php foreach ($from as $l): ?><div><?= nl2br(e($l)) ?></div><?php endforeach ?></div>
      </div>
      <div>
        <div class="crm-label">Details</div>
        <dl class="crm-paper-meta">
          <dt>Issue date</dt><dd><?= e(fdate($doc['issue_date'])) ?></dd>
          <?php if ($isInv): ?><dt>Due date</dt><dd><?= e(fdate($doc['due_date'])) ?></dd><?php else: ?><dt>Valid until</dt><dd><?= e(fdate($doc['valid_until'])) ?></dd><?php endif ?>
          <?php if (!empty($doc['project_name'])): ?><dt>Project</dt><dd><?= can('projects.view') ? '<a href="'.e(url('/projects/'.(int) $doc['project_id'])).'">'.e($doc['project_name']).'</a>' : e($doc['project_name']) ?></dd><?php endif ?>
          <dt>Currency</dt><dd><?= e($doc['currency']) ?></dd>
        </dl>
      </div>
    </div>
    <?php if ($doc['title']): ?><h3 class="crm-paper-title"><?= e($doc['title']) ?></h3><?php endif ?>
    <table class="crm-paper-table">
      <thead><tr><th class="pt-opt">#</th><th>Description</th><th class="amt">Qty</th><th class="amt">Rate</th><th class="amt pt-opt">Tax</th><th class="amt">Amount</th></tr></thead>
      <tbody><?php foreach ($items as $n => $it): ?><tr><td class="pt-opt" style="color:var(--muted)"><?= $n + 1 ?></td><td><?= e($it['description']) ?></td><td class="amt"><?= e(Ui::qty($it['quantity'])) ?></td><td class="amt"><?= e(money($it['unit_price'], false)) ?></td><td class="amt pt-opt"><?= e(Ui::qty($it['tax_rate'])) ?>%</td><td class="amt"><?= e(money($it['amount'], false)) ?></td></tr><?php endforeach ?></tbody>
    </table>
    <div class="crm-paper-foot">
      <div class="crm-paper-notes">
        <?php if ($doc['notes']): ?><div class="crm-label">Notes</div><p><?= nl2br(e($doc['notes'])) ?></p><?php endif ?>
        <?php if ($doc['terms']): ?><div class="crm-label">Terms & conditions</div><p><?= nl2br(e($doc['terms'])) ?></p><?php endif ?>
      </div>
      <div class="crm-paper-totals">
        <span>Subtotal</span><span><?= e(money($doc['subtotal'])) ?></span>
        <span>Tax</span><span><?= e(money($doc['tax_total'])) ?></span>
        <?php if ((float) $doc['discount'] > 0): ?><span>Discount</span><span>− <?= e(money($doc['discount'])) ?></span><?php endif ?>
        <div class="pt-grand"><span>Total</span><span><?= e(money($doc['total'])) ?></span></div>
        <?php if ($isInv): ?><span>Amount paid</span><span style="color:#0A8573"><?= e(money($doc['amount_paid'])) ?></span>
        <div class="pt-due <?= $bal > 0 ? '' : 'ok' ?>"><span>Balance due</span><span><?= e(money($doc['status'] === 'cancelled' ? 0 : $bal)) ?></span></div><?php endif ?>
      </div>
    </div>
    <div class="crm-paper-sign"><span>Thank you for your business.</span><span><?= e($app) ?></span></div>
  </div>
</article>
</div>
