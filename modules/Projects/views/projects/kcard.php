<?php use Modules\Projects\Services\ProjectService as PS; $sub = (int) $t['sub_total']; ?>
<article class="kcard p-<?= e($t['priority']) ?><?= $t['editable'] ? '' : ' locked' ?>" data-id="<?= (int) $t['id'] ?>"<?= $t['editable'] ? ' draggable="true"' : '' ?>>
  <div class="kc-top"><span class="prio"><?= e(PS::PRIORITIES[$t['priority']]) ?></span><?php if ($t['open_deps']): ?><span class="blocked" title="Waiting on <?= (int) $t['open_deps'] ?> unfinished dependenc<?= $t['open_deps'] > 1 ? 'ies' : 'y' ?>"><?= icon('lock', 'sm') ?> Blocked</span><?php endif ?></div>
  <a class="kc-title" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a>
  <?php if ($sub || $t['comments']): ?><div class="kc-meta"><?php if ($sub): ?><span title="Subtasks"><?= icon('check-square', 'sm') ?> <?= (int) $t['sub_done'] ?>/<?= $sub ?><span class="progress"><span style="width:<?= (int) round($t['sub_done'] / $sub * 100) ?>%"></span></span></span><?php endif ?><?php if ($t['comments']): ?><span title="Comments"><?= icon('message', 'sm') ?> <?= (int) $t['comments'] ?></span><?php endif ?></div><?php endif ?>
  <div class="kc-foot"><?= PS::dueChip($t['due_date'], $t['status'], false) ?: '<span></span>' ?><?= $t['assignee'] ? avatar($t['assignee'], $t['avatar'], 'sm') : '<span class="avatar sm empty-av" title="Unassigned">?</span>' ?></div>
</article>
