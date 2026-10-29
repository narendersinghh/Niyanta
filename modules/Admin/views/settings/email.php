<?php
$err = \Core\Session::getFlash('mail_error');
$driver = (string) setting('email.driver', 'mail');
$test = '<form method="post" action="'.e(url('/admin/settings/email/test')).'">'.csrf_field().'<button class="btn ghost" title="Sends to '.e(user()['email']).' using the saved settings">'.icon('send', 'sm').' Send test email</button></form>';
?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'email', 'actions' => $test]) ?>
<?php if ($err): ?><div class="alert error"><?= icon('alert', 'sm') ?><div><b>The test email could not be sent.</b><div class="mono xs mail-err"><?= e($err) ?></div><div class="xs">Check host, port and encryption first (587 = STARTTLS, 465 = SSL/TLS), then the username and password/key. See the troubleshooting tips below.</div></div></div><?php endif ?>
<?= partial('admin::settings/_form', ['tab' => 'email', 'fields' => $fields, 'sections' => $sections, 'submit' => 'Save email settings']) ?>
<div class="grid g2">
  <div class="card info-card"><span class="ic-chip" style="--c:var(--mint)"><?= icon('shield') ?></span><div class="grow"><h4>Land in the inbox, not spam</h4>
    <ul class="steps-list small prose"><li>Send from an address on <b>your own domain</b>, verified with the provider.</li><li>Add the provider's <b>SPF</b> include and <b>DKIM</b> records to your DNS, plus a <b>DMARC</b> record (start with <code>p=none</code>).</li><li>Avoid free addresses (gmail.com, yahoo.com) as the From address with third-party SMTP.</li></ul></div></div>
  <div class="card info-card"><span class="ic-chip" style="--c:var(--peach)"><?= icon('info') ?></span><div class="grow"><h4>Troubleshooting</h4>
    <ul class="steps-list small prose"><li><b>Connect failed / timed out</b>: many shared hosts block outgoing ports 25/465/587 — ask your host to open them, or use their own SMTP.</li><li><b>535 authentication failed</b>: wrong username or key; for Gmail use an app password, for SendGrid the username is <code>apikey</code>.</li><li><b>550 sender not allowed</b>: verify the From address/domain with the provider.</li></ul>
    <p class="xs muted mb0">Full guide with every provider: <code>docs/EMAIL.md</code>. Emails are queued and sent by the scheduler every minute<?= $driver === 'mail' ? '' : ' over SMTP' ?>.</p></div></div>
</div>
