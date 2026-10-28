<?php
/* On-screen document preview matching the PDF. Expects $kind, $doc, $items, $client. */
$isInv = $kind === 'invoice';
$num = static fn ($x) => str_contains((string) $x, '.') ? rtrim(rtrim((string) $x, '0'), '.') : (string) $x;
$bal = $isInv ? max(0, (float) $doc['total'] - (float) $doc['amount_paid']) : 0;
?>
<div class="crm-paper">
  <div class="crm-paper-band">
    <div class="flex"><span class="crm-paper-logo"><?= e(mb_strtoupper(mb_substr((string) setting('branding.app_name', 'Niyanta'), 0, 1))) ?></span><div><b><?= e(setting('branding.app_name', 'Niyanta')) ?></b><small><?= e(setting('branding.tagline', '')) ?></small></div></div>
    <div class="right"><div class="crm-paper-kind"><?= $isInv ? 'INVOICE' : 'QUOTATION' ?></div><div class="small"># <?= e($doc['number']) ?></div></div>
  </div>
  <div class="crm-paper-body">
    <div class="grid g2">
      <div>
        <div class="crm-label">Bill to</div>
        <b class="crm-paper-client"><?= e($client['company'] ?: $client['name']) ?></b>
        <div class="small muted crm-lines">
          <?php foreach (array_filter([$client['company'] ? 'Attn: '.$client['name'] : null, $client['address'], trim(implode(', ', array_filter([$client['city'], $client['state'], $client['postal_code']]))), $client['country'], $client['email'], $client['phone'], $client['tax_id'] ? 'Tax ID: '.$client['tax_id'] : null]) as $l): ?><div><?= e($l) ?></div><?php endforeach ?>
        </div>
      </div>
      <dl class="kv crm-paper-meta">
        <dt>Issue date</dt><dd><?= e(fdate($doc['issue_date'])) ?></dd>
        <?php if ($isInv): ?><dt>Due date</dt><dd><?= e(fdate($doc['due_date'])) ?></dd><?php else: ?><dt>Valid until</dt><dd><?= e(fdate($doc['valid_until'])) ?></dd><?php endif ?>
        <?php if (!empty($doc['project_name'])): ?><dt>Project</dt><dd><a href="<?= e(url('/projects/'.(int) $doc['project_id'])) ?>"><?= e($doc['project_name']) ?></a></dd><?php endif ?>
        <dt>Currency</dt><dd><?= e($doc['currency']) ?></dd>
      </dl>
    </div>
    <?php if ($doc['title']): ?><h3 class="mt"><?= e($doc['title']) ?></h3><?php endif ?>
    <div class="table-wrap mt"><table class="table crm-paper-table">
      <thead><tr><th>#</th><th>Description</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Tax</th><th class="right">Amount</th></tr></thead>
      <tbody><?php foreach ($items as $n => $it): ?><tr><td class="muted"><?= $n + 1 ?></td><td><?= e($it['description']) ?></td><td class="right"><?= e($num($it['quantity'])) ?></td><td class="right nowrap"><?= e(money($it['unit_price'], false)) ?></td><td class="right muted"><?= e($num($it['tax_rate'])) ?>%</td><td class="right nowrap b"><?= e(money($it['amount'], false)) ?></td></tr><?php endforeach ?></tbody>
    </table></div>
    <div class="crm-paper-foot">
      <div class="grow small">
        <?php if ($doc['notes']): ?><div class="crm-label">Notes</div><p class="muted"><?= nl2br(e($doc['notes'])) ?></p><?php endif ?>
        <?php if ($doc['terms']): ?><div class="crm-label">Terms & conditions</div><p class="muted mb0"><?= nl2br(e($doc['terms'])) ?></p><?php endif ?>
      </div>
      <div class="totals">
        <span class="muted">Subtotal</span><span class="right"><?= e(money($doc['subtotal'])) ?></span>
        <span class="muted">Tax</span><span class="right"><?= e(money($doc['tax_total'])) ?></span>
        <?php if ((float) $doc['discount'] > 0): ?><span class="muted">Discount</span><span class="right">− <?= e(money($doc['discount'])) ?></span><?php endif ?>
        <span class="grand">Total</span><span class="grand right"><?= e(money($doc['total'])) ?></span>
        <?php if ($isInv): ?><span class="muted">Paid</span><span class="right crm-pos"><?= e(money($doc['amount_paid'])) ?></span><span class="b">Balance due</span><span class="b right <?= $bal > 0 ? 'crm-late' : 'crm-pos' ?>"><?= e(money($bal)) ?></span><?php endif ?>
      </div>
    </div>
  </div>
</div>
