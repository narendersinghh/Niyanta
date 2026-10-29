<?php use Modules\Projects\Services\ProjectService as PS; ?>
<section class="card pj"><?= $css ?>
  <div class="card-head"><h3>Project progress</h3><a class="btn sm ghost" href="<?= e(url('/projects')) ?>">All <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$projects): ?><?= empty_state('No active projects', 'Active projects you belong to show up here.', 'kanban-board') ?><?php else: ?>
  <div>
    <?php foreach ($projects as $p): [$cd, $tone] = PS::countdown($p['deadline'], $p['status']); ?>
    <a class="wproj ac-<?= e($p['color']) ?>" href="<?= e(url('/projects/'.$p['id'])) ?>">
      <div class="grow"><b><?= e($p['name']) ?></b><div class="flex between xs"><span class="muted truncate"><?= e($p['client'] ?: 'Internal') ?></span><span class="<?= $tone === 'red' ? 'late-text' : 'muted' ?> nowrap"><?= e($cd) ?></span></div><div class="progress"><span style="width:<?= (int) $p['progress'] ?>%"></span></div></div>
      <span class="pct"><?= (int) $p['progress'] ?>%</span>
    </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</section>
