<div class="card"><?= $css ?>
  <div class="card-head"><h3>Project progress</h3><a class="xs muted" href="<?= e(url('/projects')) ?>">All projects</a></div>
  <?php if (!$projects): ?><?= empty_state('No active projects', '', 'kanban-board') ?><?php else: ?>
  <div class="list">
    <?php foreach ($projects as $p): $late = $p['deadline'] && $p['deadline'] < date('Y-m-d'); ?>
    <a class="list-item wproj ac-<?= e($p['color']) ?>" href="<?= e(url('/projects/'.$p['id'])) ?>">
      <div class="ring sm" style="--v:<?= (int) $p['progress'] ?>" data-label="<?= (int) $p['progress'] ?>%"></div>
      <div class="grow"><b class="truncate"><?= e($p['name']) ?></b><div class="meta truncate"><?= e($p['client'] ?: 'Internal') ?> · <span class="<?= $late ? 'late-text' : '' ?>"><?= $p['deadline'] ? 'due '.e(fdate($p['deadline'], 'd M')) : 'no deadline' ?></span></div></div>
    </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
