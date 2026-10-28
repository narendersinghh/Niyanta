<?php /* Usage: partial('partials/page_head', ['title'=>..., 'subtitle'=>..., 'crumbs'=>[[label,url],...], 'actions'=>html]) */ ?>
<div class="page-head">
  <div>
    <?php if (!empty($crumbs)): ?><div class="crumbs"><?php foreach ($crumbs as $i => $c): ?><?= $i ? '<span>/</span>' : '' ?><?php if (!empty($c[1])): ?><a href="<?= e(url($c[1])) ?>"><?= e($c[0]) ?></a><?php else: ?><span><?= e($c[0]) ?></span><?php endif ?><?php endforeach ?></div><?php endif ?>
    <h1><?= e($title) ?></h1>
    <?php if (!empty($subtitle)): ?><p><?= e($subtitle) ?></p><?php endif ?>
  </div>
  <?php if (!empty($actions)): ?><div class="flex wrap"><?= $actions ?></div><?php endif ?>
</div>
