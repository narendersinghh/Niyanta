<?= partial('partials/page_head', ['title' => 'Modules', 'subtitle' => 'Turn features on or off for everyone. Disabling a module hides and blocks it but never deletes its data.', 'crumbs' => [['System'], ['Modules']]]) ?>
<div class="grid g3">
<?php $c = ['lav', 'mint', 'lemon', 'peach', 'sky', 'pink']; $i = 0; foreach ($modules as $k => $m): $on = \Core\Modules::enabled($k); ?>
  <div class="card">
    <div class="flex between mb"><span class="tile-ic bg-<?= $c[$i++ % 6] ?>" style="width:48px;height:48px;border-radius:16px;display:grid;place-items:center"><?= icon($m['icon'] ?? 'puzzle', 'lg') ?></span>
      <?php if (!empty($m['core'])): ?><?= badge('active', 'Core') ?><?php else: ?>
      <form method="post" action="<?= e(url('/admin/modules/'.$k)) ?>" data-confirm="<?= $on ? 'Disable '.e($m['name']).'? Users will lose access until re-enabled. Data is kept.' : 'Enable '.e($m['name']).'?' ?>"><?= csrf_field() ?><label class="switch" title="<?= $on ? 'Disable' : 'Enable' ?>"><input type="checkbox" data-autosubmit<?= checked($on) ?>><span></span></label></form><?php endif ?>
    </div>
    <h3><?= e($m['name']) ?></h3>
    <p class="small muted"><?= e($m['description'] ?? '') ?></p>
    <div class="flex wrap small"><span class="pill pill-gray">v<?= e($m['version'] ?? '1.0') ?></span><span class="pill pill-gray"><?= count($m['permissions']) ?> permissions</span><?php foreach ($m['requires'] as $r): ?><span class="pill pill-lemon">needs <?= e($r) ?></span><?php endforeach ?></div>
  </div>
<?php endforeach ?>
</div>
