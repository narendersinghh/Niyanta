<?php /* Tab heading: $tabs, $tab, optional $color, $actions */ [$label, $ic, $desc] = $tabs[$tab]; ?>
<div class="card tab-head"><span class="bubble bg-<?= e($color ?? 'lav') ?>"><?= icon($ic, 'lg') ?></span><div class="grow"><h2><?= e($label) ?></h2><p><?= e($desc) ?></p></div><?= $actions ?? '' ?></div>
