<div class="card">
  <div class="card-head"><h3><?= icon('clipboard') ?> Onboarding &amp; exit</h3></div>
  <div class="list">
    <?php if ($c['review']): ?><a class="list-item" href="<?= e(url('/hr/processes?view=review')) ?>"><span class="pill pill-lemon"><?= icon('eye', 'sm') ?></span><div class="grow"><b class="small"><?= (int) $c['review'] ?> file<?= $c['review'] === 1 ? '' : 's' ?> to review</b><div class="meta">Uploaded by employees</div></div><?= icon('chevron-right', 'sm') ?></a><?php endif ?>
    <?php if ($c['requests']): ?><a class="list-item" href="<?= e(url('/hr/processes?view=requests')) ?>"><span class="pill pill-red"><?= icon('trash', 'sm') ?></span><div class="grow"><b class="small"><?= (int) $c['requests'] ?> deletion request<?= $c['requests'] === 1 ? '' : 's' ?></b><div class="meta">Employees asking to remove a file</div></div><?= icon('chevron-right', 'sm') ?></a><?php endif ?>
    <?php if ($c['due']): ?><a class="list-item" href="<?= e(url('/hr/processes?view=due')) ?>"><span class="pill pill-peach"><?= icon('clock', 'sm') ?></span><div class="grow"><b class="small"><?= (int) $c['due'] ?> task<?= $c['due'] === 1 ? '' : 's' ?> due today or overdue</b><div class="meta">HR, IT and manager tasks</div></div><?= icon('chevron-right', 'sm') ?></a><?php endif ?>
  </div>
</div>
