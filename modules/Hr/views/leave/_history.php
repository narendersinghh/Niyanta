<?php /* History dialog of one leave request: $r (request row with name), $events (LeaveChanges::history rows, oldest first) */ ?>
<dialog class="modal" id="lh-<?= (int) $r['id'] ?>" aria-labelledby="lh-<?= (int) $r['id'] ?>-t">
  <div class="modal-head"><div><h3 id="lh-<?= (int) $r['id'] ?>-t">Leave history</h3><p class="xs muted mb0"><?= e(($r['name'] ?? '') !== '' ? $r['name'].' · ' : '') ?><?= e($r['type_name']) ?> · <?= e(fdate($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? ' - '.e(fdate($r['end_date'])) : '' ?></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body"><ol class="lv-hist">
    <li><span class="lv-hd"></span><div><b class="small">Requested</b> <span class="xs muted"><?= e(fdatetime($r['created_at'])) ?></span></div></li>
    <?php foreach ($events as $ev): ?><li class="lv-h-<?= e($ev['action']) ?>"><span class="lv-hd"></span><div>
      <b class="small"><?= e($ev['label']) ?> by <?= e($ev['actor'] ?? 'a former user') ?></b> <span class="xs muted">on <?= e(fdatetime($ev['created_at'])) ?></span>
      <?php if ($ev['changes']): ?><ul class="lv-chg"><?php foreach ($ev['changes'] as $k => [$from, $to]): ?><li><span class="muted"><?= e($k) ?>:</span> <?php if ((string) $from !== ''): ?><s><?= e(str_limit((string) $from, 80)) ?></s> <?= icon('arrow-right', 'sm') ?> <?php endif ?><b><?= e(str_limit((string) $to, 80) ?: 'none') ?></b></li><?php endforeach ?></ul><?php endif ?>
      <?php if ($ev['note']): ?><div class="hr-note"><?= icon('message', 'sm') ?><div><?= nl2br(e($ev['note'])) ?></div></div><?php endif ?>
    </div></li><?php endforeach ?>
  </ol></div>
</dialog>
