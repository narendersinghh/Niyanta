<?= partial('partials/page_head', ['title' => 'Legacy checklist items', 'subtitle' => 'Steps of the earlier simple onboarding and offboarding checklists.', 'crumbs' => [['HR Admin'], ['Employees', '/hr/employees'], ['Checklist templates']]]) ?>
<div class="alert warn"><?= icon('info', 'sm') ?><div class="grow"><b>Onboarding and exit now run from templates with document uploads, signatures and reviews.</b> These simple checklist items are kept only for checklists created earlier.</div><a class="btn sm primary" href="<?= e(url('/hr/process-templates')) ?>">Open templates</a></div>
<div class="grid g2 hr-top">
  <?php foreach (['onboarding' => ['Onboarding', 'rocket', 'mint', 'Day-one essentials for new joiners'], 'offboarding' => ['Offboarding', 'log-out', 'peach', 'A clean, friendly exit']] as $type => [$label, $ic, $col, $sub]): $list = $items[$type]; ?>
  <div class="card" id="<?= $type ?>">
    <div class="hr-side-head"><span class="hr-ic <?= $col ?>"><?= icon($ic) ?></span><div class="grow"><h3 class="mb0"><?= e($label) ?></h3><div class="xs muted"><?= e($sub) ?></div></div><span class="pill pill-<?= $col ?>"><?= count($list) ?> steps</span></div>
    <?php if (!$list): ?><p class="muted small center mt">No steps yet. Add the first one below.</p><?php endif ?>
    <ol class="hr-tpl">
      <?php foreach ($list as $i => $t): ?>
      <li>
        <span class="hr-num"><?= $i + 1 ?></span><span class="grow"><?= e($t['title']) ?></span>
        <form method="post" action="<?= e(url('/hr/checklists/'.$t['id'].'/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn icon sm ghost" title="Move up"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-down', 'sm hr-flip') ?></button></form>
        <form method="post" action="<?= e(url('/hr/checklists/'.$t['id'].'/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn icon sm ghost" title="Move down"<?= $i === count($list) - 1 ? ' disabled' : '' ?>><?= icon('chevron-down', 'sm') ?></button></form>
        <form method="post" action="<?= e(url('/hr/checklists/'.$t['id'].'/delete')) ?>" data-confirm="Remove this step from the template?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Remove" aria-label="Remove step"><?= icon('trash', 'sm') ?></button></form>
      </li>
      <?php endforeach ?>
    </ol>
    <form class="flex mt" method="post" action="<?= e(url('/hr/checklists')) ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
      <label class="sr" for="tpl-<?= $type ?>">New step</label><input id="tpl-<?= $type ?>" type="text" name="title" maxlength="190" placeholder="Add a step, e.g. Share welcome kit" required class="grow"><button class="btn primary"><?= icon('plus', 'sm') ?> Add</button>
    </form>
  </div>
  <?php endforeach ?>
</div>

