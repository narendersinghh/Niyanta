<?php $id = $c['id']; $v = static fn (string $k, $d = '') => old($k, $c[$k] ?? $d); $oldMgr = old('managers', null); $sel = array_map('intval', is_array($oldMgr) ? $oldMgr : $selected); ?>
<?= partial('partials/page_head', ['title' => $id ? 'Edit client' : 'New client', 'subtitle' => $id ? 'Update profile details, tags and the account team.' : 'Capture the essentials — you can add contacts, documents and journey entries next.', 'crumbs' => [['Clients', '/clients'], [$id ? ($c['company'] ?: $c['name']) : 'New', $id ? '/clients/'.$id : null], ...($id ? [['Edit']] : [])]]) ?>
<form class="grid g-side" method="post" action="<?= e(url($id ? '/clients/'.$id : '/clients')) ?>"><?= csrf_field() ?>
  <div class="stack">
    <div class="card form">
      <div class="card-head"><h3><?= icon('user') ?> Contact & company</h3></div>
      <div class="form-grid">
        <div class="field"><label>Client / contact name *</label><input type="text" name="name" value="<?= e($v('name')) ?>" required maxlength="150" autofocus><?= error_for('name') ?></div>
        <div class="field"><label>Company</label><input type="text" name="company" value="<?= e($v('company')) ?>" maxlength="190"><?= error_for('company') ?></div>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($v('email')) ?>" maxlength="190"><?= error_for('email') ?></div>
        <div class="field"><label>Phone</label><input type="tel" name="phone" value="<?= e($v('phone')) ?>" placeholder="+91 98765 43210"><?= error_for('phone') ?></div>
        <div class="field"><label>WhatsApp</label><input type="tel" name="whatsapp" value="<?= e($v('whatsapp')) ?>" placeholder="Leave blank to use phone"><?= error_for('whatsapp') ?></div>
        <div class="field"><label>Website</label><input type="text" name="website" value="<?= e($v('website')) ?>" placeholder="example.com"><?= error_for('website') ?></div>
        <div class="field"><label>Industry</label><input type="text" name="industry" value="<?= e($v('industry')) ?>" maxlength="120" list="crm-industries"><datalist id="crm-industries"><?php foreach (['Retail', 'E-commerce', 'Healthcare', 'Education', 'Real estate', 'Hospitality', 'Finance', 'Technology', 'Manufacturing', 'Media', 'Non-profit'] as $i): ?><option value="<?= e($i) ?>"><?php endforeach ?></datalist><?= error_for('industry') ?></div>
        <div class="field"><label>Tax ID / GSTIN</label><input type="text" name="tax_id" value="<?= e($v('tax_id')) ?>" maxlength="60"><?= error_for('tax_id') ?></div>
      </div>
    </div>
    <div class="card form">
      <div class="card-head"><h3><?= icon('map-pin') ?> Billing address</h3></div>
      <div class="form-grid">
        <div class="field full"><label>Street address</label><textarea name="address" rows="2" maxlength="1000"><?= e($v('address')) ?></textarea><?= error_for('address') ?></div>
        <div class="field"><label>City</label><input type="text" name="city" value="<?= e($v('city')) ?>" maxlength="100"></div>
        <div class="field"><label>State</label><input type="text" name="state" value="<?= e($v('state')) ?>" maxlength="100"></div>
        <div class="field"><label>Country</label><input type="text" name="country" value="<?= e($v('country')) ?>" maxlength="100"></div>
        <div class="field"><label>Postal code</label><input type="text" name="postal_code" value="<?= e($v('postal_code')) ?>" maxlength="20"><?= error_for('postal_code') ?></div>
      </div>
    </div>
    <div class="card form">
      <div class="card-head"><h3><?= icon('file-text') ?> Notes</h3></div>
      <div class="field"><textarea name="notes" rows="4" maxlength="5000" placeholder="Background, preferences, how they like to be contacted…"><?= e($v('notes')) ?></textarea><?= error_for('notes') ?></div>
      <div class="form-actions"><a class="btn ghost" href="<?= e(url($id ? '/clients/'.$id : '/clients')) ?>">Cancel</a><button class="btn primary"><?= icon('check', 'sm') ?> <?= $id ? 'Save changes' : 'Create client' ?></button></div>
    </div>
  </div>
  <div class="stack">
    <div class="card form">
      <h3>Pipeline</h3>
      <div class="field"><label>Status</label><select name="status"><?php foreach (['lead' => 'Lead', 'active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'] as $s => $l): ?><option value="<?= $s ?>"<?= selected($s, $v('status', 'lead')) ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('status') ?></div>
      <div class="field"><label>Source</label><input type="text" name="source" value="<?= e($v('source')) ?>" maxlength="100" list="crm-sources" placeholder="Referral, website…"><datalist id="crm-sources"><?php foreach (['Referral', 'Website', 'Instagram', 'LinkedIn', 'Google Ads', 'Cold outreach', 'Event', 'Existing client'] as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist></div>
      <div class="field"><label>Billing currency</label><input type="text" name="currency" value="<?= e($v('currency')) ?>" maxlength="3" placeholder="INR"><?= error_for('currency') ?></div>
      <div class="field"><label>Tags</label><input type="text" name="tags" value="<?= e(old('tags', $tagStr)) ?>" placeholder="retainer, priority, seo"><div class="hint">Comma separated. New tags are created automatically.</div><?= error_for('tags') ?></div>
    </div>
    <div class="card form">
      <h3>Account team</h3>
      <p class="xs muted mb0">Assigned managers can see this client, its documents and invoices.</p>
      <div class="crm-picklist">
        <?php foreach ($candidates as $uid => $un): ?>
        <label class="crm-pick"><input type="checkbox" name="managers[]" value="<?= (int) $uid ?>"<?= checked(in_array((int) $uid, $sel, true)) ?>><?= avatar($un, null, 'sm') ?><span class="grow"><?= e($un) ?></span><?= (int) $uid === (int) user()['id'] ? '<span class="pill pill-lav">You</span>' : '' ?></label>
        <?php endforeach ?>
        <?php if (!$candidates): ?><p class="small muted">No eligible managers yet.</p><?php endif ?>
      </div>
    </div>
    <div class="card soft-mint" style="border:0"><?= illus('clients-handshake') ?><h4 class="mt">Tip</h4><p class="small mb0">After saving, log meetings and calls in the <b>Journey</b> tab so the whole team knows where the relationship stands.</p></div>
  </div>
</form>
