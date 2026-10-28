<?= partial('partials/page_head', ['title' => 'Checklist templates', 'subtitle' => 'Standard steps copied to each employee when you start their onboarding or offboarding.', 'crumbs' => [['Employees', '/hr/employees'], ['Checklist templates']]]) ?>
<div class="grid g2">
  <?php foreach (['onboarding' => ['Onboarding', 'rocket', 'mint'], 'offboarding' => ['Offboarding', 'coffee-break', 'peach']] as $type => [$label, $ill, $col]): $list = $items[$type]; ?>
  <div class="card" id="<?= $type ?>">
    <div class="hr-tpl-head soft-<?= $col ?>"><div><h3 class="mb0"><?= e($label) ?></h3><p class="small mb0"><?= count($list) ?> steps</p></div><?= illus($ill) ?></div>
    <?php if (!$list): ?><p class="muted small center mt">No steps yet. Add the first one below.</p><?php endif ?>
    <ol class="hr-tpl">
      <?php foreach ($list as $i => $t): ?>
      <li>
        <span class="hr-num"><?= $i + 1 ?></span><span class="grow"><?= e($t['title']) ?></span>
        <form method="post" action="<?= e(url('/hr/checklists/'.$t['id'].'/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn icon sm ghost" title="Move up"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('chevron-down', 'sm hr-flip') ?></button></form>
        <form method="post" action="<?= e(url('/hr/checklists/'.$t['id'].'/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn icon sm ghost" title="Move down"<?= $i === count($list) - 1 ? ' disabled' : '' ?>><?= icon('chevron-down', 'sm') ?></button></form>
        <form method="post" action="<?= e(url('/hr/checklists/'.$t['id'].'/delete')) ?>" data-confirm="Remove this step from the template?"><?= csrf_field() ?><button class="btn icon sm danger" title="Remove"><?= icon('trash', 'sm') ?></button></form>
      </li>
      <?php endforeach ?>
    </ol>
    <form class="flex mt" method="post" action="<?= e(url('/hr/checklists')) ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
      <input type="text" name="title" maxlength="190" placeholder="Add a step, e.g. Share welcome kit" required class="grow"><button class="btn primary"><?= icon('plus', 'sm') ?> Add</button>
    </form>
  </div>
  <?php endforeach ?>
</div>
<div class="alert info mt"><?= icon('info', 'sm') ?> Changes apply to checklists generated from now on. Checklists already started for employees keep their own copy.</div>
