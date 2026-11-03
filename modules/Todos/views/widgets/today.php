<section class="card td td-widget" data-todo-widget><?= $css ?>
  <div class="card-head"><div><h3>My tasks today</h3><p class="xs muted mb0"><?= $total ? $total.' open, due today or overdue' : 'Nothing due today' ?></p></div><a class="btn sm ghost" href="<?= e(url('/todos')) ?>">All <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$rows): ?><div class="td-wempty"><?= icon('check-circle') ?><span class="small muted">You are all caught up for today.</span></div>
  <?php else: foreach ($rows as $t): ?><?= partial('todos::partials/row', ['t' => $t, 'mode' => 'widget', 'uid' => $uid, 'manage' => false, 'return' => '/']) ?><?php endforeach; endif ?>
  <?php if ($total > count($rows)): ?><a class="xs td-more" href="<?= e(url('/todos')) ?>">+<?= $total - count($rows) ?> more on your list</a><?php endif ?>
</section>
