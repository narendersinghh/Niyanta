<div class="card">
  <div class="card-head"><h3><?= icon('book') ?> Please read</h3><span class="pill pill-lemon"><?= count($docs) ?></span></div>
  <p class="small muted">These company documents need your acknowledgement.</p>
  <div class="list"><?php foreach ($docs as $d): [$l, $ic, $c] = $cats[$d['category']] ?? $cats['other']; ?>
    <a class="list-item" href="<?= e(url('/hr/documents?show=pending#doc-'.$d['id'])) ?>"><span class="pill pill-<?= e($c) ?>"><?= icon($ic, 'sm') ?></span><div class="grow"><b class="small"><?= e($d['title']) ?></b><div class="meta"><?= e($l) ?> · <?= e(ago($d['at'])) ?></div></div><?= icon('chevron-right', 'sm') ?></a>
  <?php endforeach ?></div>
</div>
