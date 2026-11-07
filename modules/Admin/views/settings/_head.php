<?php /* Settings page head: $tabs, $tab, optional $actions html. Tabs that configure features get an on/off switch per feature (tabSwitches) and a link to the feature's page. */
use Modules\Admin\Services\SettingsSchema;
[$label, $ic, $desc, $group, $c] = $tabs[$tab]; ?>
<?= partial('partials/page_head', ['title' => $label, 'subtitle' => $desc, 'crumbs' => [['Settings', '/admin/settings'], [$group]], 'actions' => $actions ?? '']) ?>
<?php if ($sw = SettingsSchema::tabSwitches($tab)): ?>
<div class="card feat-strip" aria-label="Feature switches">
<?php foreach ($sw as $fk): $f = \Core\Features::find($fk); if (!$f) { continue; } $on = feature_on($fk); $page = SettingsSchema::featureUrl($fk); ?>
  <form method="post" action="<?= e(url('/admin/modules/feature:'.$fk)) ?>" class="feat-switch<?= $on ? '' : ' is-off' ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e('/admin/settings/'.$tab) ?>">
    <span class="feature-ic"><?= icon($f['icon'], 'sm') ?></span>
    <span class="grow"><b><?= $on ? (count($sw) > 1 ? e($f['name']).' is on' : 'This feature is on') : e($f['name']).' is off' ?></b><span class="xs muted"><?= count($sw) > 1 || !$on ? '' : e($f['name']).' · ' ?><?= $on ? 'Turning it off hides it for everyone; data is kept.' : 'Its settings below apply again when you turn it on.' ?></span></span>
    <?php if ($on && $page): ?><a class="btn sm ghost" href="<?= e(url($page)) ?>"><?= icon('external', 'sm') ?> Open</a><?php endif ?>
    <label class="switch" title="<?= $on ? 'Turn off' : 'Turn on' ?>"><input type="checkbox" data-feature-switch data-confirm-on="<?= e('Turn on '.$f['name'].' for everyone?') ?>" data-confirm-off="<?= e('Turn off '.$f['name'].' for everyone? It disappears from menus and its pages are blocked. Data is kept and returns when you switch it back on.') ?>" aria-label="<?= e($f['name']) ?>"<?= checked($on) ?>><span></span></label>
  </form>
<?php endforeach ?>
</div>
<?php endif ?>
