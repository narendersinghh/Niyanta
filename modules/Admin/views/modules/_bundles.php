<?php use Core\{DB, Features, Modules};
$raw = DB::pairs("SELECT `key`, enabled FROM modules WHERE `key` LIKE 'feature:%'");
$bundles = Features::bundles(); $ai = Modules::exists('ai') && class_exists(\Modules\Ai\Services\Usage::class); ?>
<div class="feature-bundles">
<?php foreach ($bundles as $bk => $b): $bon = Features::bundleEnabled($bk); ?>
  <section class="card bundle-card<?= $bon ? '' : ' is-off' ?>" style="--bc:<?= e($b['color']) ?>">
    <header class="bundle-head">
      <span class="bundle-ic"><?= icon($b['icon'], 'lg') ?></span>
      <div class="grow"><h3><?= e($b['name']) ?></h3><p class="muted small mb0"><?= e($b['description']) ?></p></div>
      <form method="post" action="<?= e(url('/admin/modules/bundle:'.$bk)) ?>" data-confirm="<?= $bon ? 'Turn off the entire '.e($b['name']).' bundle? Everyone loses access until it is switched back on.' : 'Turn on '.e($b['name']).'?' ?>"><?= csrf_field() ?><label class="switch lg" title="<?= $bon ? 'Turn off bundle' : 'Turn on bundle' ?>"><input type="checkbox" data-autosubmit<?= checked($bon) ?>><span></span></label></form>
    </header>
    <div class="feature-list">
    <?php foreach ($b['features'] as $fk => $f): $fon = (int) ($raw['feature:'.$fk] ?? 1) === 1; ?>
      <div class="feature-row<?= $fon && $bon ? '' : ' is-off' ?>">
        <span class="feature-ic"><?= icon($f['icon']) ?></span>
        <div class="grow"><b><?= e($f['name']) ?></b><div class="xs muted"><?= e($f['description']) ?></div></div>
        <form method="post" action="<?= e(url('/admin/modules/feature:'.$fk)) ?>"><?= csrf_field() ?><label class="switch" title="<?= $fon ? 'Turn off' : 'Turn on' ?>"><input type="checkbox" data-autosubmit<?= checked($fon) ?><?= $bon ? '' : ' disabled' ?>><span></span></label></form>
      </div>
    <?php endforeach ?>
    </div>
    <?php if (!$bon): ?><p class="xs muted mt mb0"><?= icon('info', 'sm') ?> Bundle is off, individual switches are kept and apply again when you turn it back on.</p><?php endif ?>
  </section>
<?php endforeach ?>
</div>
<?php if ($ai): $aiOn = \Modules\Ai\Services\Usage::enabled(); $aiReady = \Modules\Ai\Services\Assistant::status()[0]; ?>
<section class="card bundle-card mt2<?= $aiOn ? '' : ' is-off' ?>" style="--bc:#F0508A">
  <header class="bundle-head">
    <span class="bundle-ic"><?= icon('sparkles', 'lg') ?></span>
    <div class="grow"><h3>AI Assistant</h3><p class="muted small mb0">Goraiya, a chat drawer that answers questions from workspace data within each person's own access. <?php if ($aiOn): ?><?= !$aiReady ? '<b>Add a model</b> in ' : 'Models, budgets and privacy in ' ?><a href="<?= e(url('/ai/settings')) ?>">AI assistant settings</a>.<?php else: ?>Turn it on to choose models, budgets and privacy.<?php endif ?></p></div>
    <form method="post" action="<?= e(url('/ai/settings/toggle')) ?>" data-confirm="<?= $aiOn ? 'Turn the AI assistant off for everyone? Conversations are kept.' : 'Turn the AI assistant on?' ?>"><?= csrf_field() ?><label class="switch lg" title="<?= $aiOn ? 'Turn off' : 'Turn on' ?>"><input type="checkbox" data-autosubmit<?= checked($aiOn) ?> aria-label="AI Assistant"><span></span></label></form>
  </header>
</section>
<?php endif ?>
