<div class="install">
  <div class="card hero mb"><div><span class="pill pill-lav">Step 1 of 2</span><h1 class="mt">Welcome to Niyanta</h1><p class="muted">Let's check your server and connect the database. This takes about two minutes.</p></div><?= illus('install-builder') ?></div>
  <div class="grid g2">
    <div class="card">
      <h3>Server requirements</h3>
      <?php foreach ($reqs as [$label, $pass, $required]): ?>
        <div class="req"><span><?= e($label) ?><?= $required ? '' : ' <span class="xs muted">(recommended)</span>' ?></span><?= $pass ? badge('success', 'OK') : badge($required ? 'failed' : 'pending', $required ? 'Missing' : 'Optional') ?></div>
      <?php endforeach ?>
    </div>
    <div class="card">
      <h3>Database (MySQL / MariaDB)</h3>
      <?php if (!$ok): ?><div class="alert error"><?= icon('alert') ?> Fix the required items before continuing.</div><?php endif ?>
      <form class="form" method="post" action="<?= e(url('/install/database')) ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field"><label>Host</label><input name="host" value="<?= e($db['host'] ?? 'localhost') ?>" required></div>
          <div class="field"><label>Port</label><input type="number" name="port" value="<?= e($db['port'] ?? 3306) ?>" required></div>
          <div class="field full"><label>Database name</label><input name="name" value="<?= e($db['name'] ?? '') ?>" required></div>
          <div class="field"><label>Username</label><input name="user" value="<?= e($db['user'] ?? '') ?>" required autocomplete="off"></div>
          <div class="field"><label>Password</label><input type="password" name="pass" autocomplete="new-password"></div>
        </div>
        <p class="xs muted">Create an empty database and user in your hosting panel (cPanel → MySQL Databases) first.</p>
        <div class="form-actions"><button class="btn primary" <?= $ok ? '' : 'disabled' ?>>Test & continue <?= icon('arrow-right', 'sm') ?></button></div>
      </form>
    </div>
  </div>
</div>
