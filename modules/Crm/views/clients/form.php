<?php
$id = $c['id'];
$v = static fn (string $k, $d = '') => old($k, $c[$k] ?? $d);
$oldMgr = old('managers', null);
$sel = array_map('intval', is_array($oldMgr) ? $oldMgr : $selected);
$label = $id ? ($c['company'] ?: $c['name']) : '';
$back = $id ? '/clients/'.$id : '/clients';
$statusOpts = ['lead' => ['Lead', 'lav'], 'active' => ['Active', 'mint'], 'on_hold' => ['On hold', 'lemon'], 'inactive' => ['Inactive', 'pink']];
$sec = static fn (string $icon, string $color, string $title, string $hint) => '<div class="crm-sec-h"><span class="crm-ic crm-c-'.$color.'">'.icon($icon, 'sm').'</span><div class="grow"><h3>'.e($title).'</h3><p>'.e($hint).'</p></div></div>';
?>
<div class="crm">
<?= partial('partials/page_head', ['title' => $id ? 'Edit '.$label : 'New client', 'subtitle' => $id ? 'Update profile details, tags and the account team.' : 'Capture the essentials, contacts, documents and journey entries come next.', 'crumbs' => [['Clients', '/clients'], ...($id ? [[$label, '/clients/'.$id], ['Edit']] : [['New client']])]]) ?>
<form method="post" action="<?= e(url($id ? '/clients/'.$id : '/clients')) ?>"><?= csrf_field() ?>
<div class="grid g-side">
  <div class="stack">
    <section class="card form">
      <?= $sec('user', 'lav', 'Contact & company', 'Who you work with. The company name is shown everywhere when set.') ?>
      <div class="form-grid">
        <div class="field"><label for="f-name">Contact name *</label><input id="f-name" type="text" name="name" value="<?= e($v('name')) ?>" required maxlength="150" autofocus placeholder="Priya Nair"><?= error_for('name') ?></div>
        <div class="field"><label for="f-company">Company</label><input id="f-company" type="text" name="company" value="<?= e($v('company')) ?>" maxlength="190" placeholder="Sunrise Bakery Pvt Ltd"><?= error_for('company') ?></div>
        <div class="field"><label for="f-email">Email</label><input id="f-email" type="email" name="email" value="<?= e($v('email')) ?>" maxlength="190" placeholder="name@company.com"><?php if (feature_on('invoices')): ?><div class="hint">Invoices are e-mailed here by default.</div><?php endif ?><?= error_for('email') ?></div>
        <div class="field"><label for="f-phone">Phone</label><input id="f-phone" type="tel" name="phone" value="<?= e($v('phone')) ?>" placeholder="+91 98765 43210"><?= error_for('phone') ?></div>
        <div class="field"><label for="f-wa">WhatsApp</label><input id="f-wa" type="tel" name="whatsapp" value="<?= e($v('whatsapp')) ?>" placeholder="Same as phone"><div class="hint">Leave blank to use the phone number.</div><?= error_for('whatsapp') ?></div>
        <div class="field"><label for="f-web">Website</label><input id="f-web" type="text" name="website" value="<?= e($v('website')) ?>" placeholder="example.com"><?= error_for('website') ?></div>
        <div class="field"><label for="f-ind">Industry</label><input id="f-ind" type="text" name="industry" value="<?= e($v('industry')) ?>" maxlength="120" list="crm-industries" placeholder="Hospitality"><datalist id="crm-industries"><?php foreach (['Retail', 'E-commerce', 'Healthcare', 'Education', 'Real estate', 'Hospitality', 'Finance', 'Technology', 'Manufacturing', 'Media', 'Non-profit'] as $i): ?><option value="<?= e($i) ?>"><?php endforeach ?></datalist><?= error_for('industry') ?></div>
        <div class="field"><label for="f-tax">Tax ID / GSTIN</label><input id="f-tax" type="text" name="tax_id" value="<?= e($v('tax_id')) ?>" maxlength="60" placeholder="29ABCDE1234F1Z5"><?php if (feature_on('quotations') || feature_on('invoices')): ?><div class="hint">Printed on <?= e(\Modules\Admin\Services\SettingsSchema::join([feature_on('quotations') ? 'quotations' : null, feature_on('invoices') ? 'invoices' : null])) ?>.</div><?php endif ?><?= error_for('tax_id') ?></div>
      </div>
    </section>
    <section class="card form">
      <?= $sec('map-pin', 'sky', 'Billing address', 'Appears in the “Bill to” block of every document.') ?>
      <div class="form-grid">
        <div class="field full"><label for="f-addr">Street address</label><textarea id="f-addr" name="address" rows="2" maxlength="1000" placeholder="Building, street, area"><?= e($v('address')) ?></textarea><?= error_for('address') ?></div>
        <div class="field"><label for="f-city">City</label><input id="f-city" type="text" name="city" value="<?= e($v('city')) ?>" maxlength="100"></div>
        <div class="field"><label for="f-state">State</label><input id="f-state" type="text" name="state" value="<?= e($v('state')) ?>" maxlength="100"></div>
        <div class="field"><label for="f-country">Country</label><input id="f-country" type="text" name="country" value="<?= e($v('country')) ?>" maxlength="100"></div>
        <div class="field"><label for="f-pc">Postal code</label><input id="f-pc" type="text" name="postal_code" value="<?= e($v('postal_code')) ?>" maxlength="20"><?= error_for('postal_code') ?></div>
      </div>
    </section>
    <section class="card form">
      <?= $sec('file-text', 'lemon', 'Internal notes', 'Only your team sees these, background, preferences, how they like to be contacted.') ?>
      <div class="field"><textarea name="notes" rows="4" maxlength="5000" aria-label="Notes" placeholder="Prefers WhatsApp over e-mail. Decision maker is the founder."><?= e($v('notes')) ?></textarea><?= error_for('notes') ?></div>
    </section>
  </div>
  <div class="stack">
    <section class="card form">
      <?= $sec('target', 'peach', 'Pipeline', 'Where this relationship stands.') ?>
      <div class="field"><label>Status</label><div class="crm-status-pick"><?php foreach ($statusOpts as $s => [$l, $col]): ?><label class="crm-c-<?= $col ?>"><input type="radio" name="status" value="<?= $s ?>"<?= checked($s === $v('status', 'lead')) ?>><span><i></i><?= e($l) ?></span></label><?php endforeach ?></div><?= error_for('status') ?></div>
      <div class="field"><label for="f-src">Source</label><input id="f-src" type="text" name="source" value="<?= e($v('source')) ?>" maxlength="100" list="crm-sources" placeholder="Referral, website…"><datalist id="crm-sources"><?php foreach (['Referral', 'Website', 'Instagram', 'LinkedIn', 'Google Ads', 'Cold outreach', 'Event', 'Existing client'] as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist></div>
      <div class="form-grid">
        <div class="field"><label for="f-cur">Currency</label><input id="f-cur" type="text" name="currency" value="<?= e($v('currency')) ?>" maxlength="3" placeholder="INR"><?= error_for('currency') ?></div>
        <div class="field"><label for="f-tags">Tags</label><input id="f-tags" type="text" name="tags" value="<?= e(old('tags', $tagStr)) ?>" placeholder="retainer, seo"><?= error_for('tags') ?></div>
      </div>
      <div class="hint">Tags are comma separated; new ones are created automatically.</div>
    </section>
    <section class="card form">
      <?= $sec('users', 'mint', 'Account team', 'Assigned managers can see this client, its documents'.(feature_on('invoices') ? ' and invoices' : '').'.') ?>
      <div class="crm-picklist">
        <?php foreach ($candidates as $uid => $un): ?>
        <label class="crm-pick"><input type="checkbox" name="managers[]" value="<?= (int) $uid ?>"<?= checked(in_array((int) $uid, $sel, true)) ?>><?= avatar($un, null, 'sm') ?><span class="grow"><?= e($un) ?></span><?= (int) $uid === (int) user()['id'] ? '<span class="pill pill-lav">You</span>' : '' ?></label>
        <?php endforeach ?>
        <?php if (!$candidates): ?><p class="small muted mb0">No eligible managers yet.</p><?php endif ?>
      </div>
    </section>
    <?php if (!$id): ?><div class="card crm-tip"><?= illus('clients-handshake') ?><p class="small mb0">After saving, log meetings and calls in the <b>Journey</b> tab so the whole team knows where the relationship stands.</p></div><?php endif ?>
  </div>
</div>
<div class="crm-actionbar">
  <span class="crm-ab-info"><?= $id ? 'Editing <b>'.e($label).'</b>' : 'Fields marked * are required' ?></span>
  <a class="btn ghost" href="<?= e(url($back)) ?>">Cancel</a>
  <button type="submit" class="btn primary"><?= icon('check', 'sm') ?> <?= $id ? 'Save changes' : 'Create client' ?></button>
</div>
</form>
</div>
