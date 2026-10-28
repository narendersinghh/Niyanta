<?php
$msForm = static function (?array $m) use ($pr): string {
  $id = $m['id'] ?? null; $v = static fn ($k, $d = '') => $m[$k] ?? $d;
  ob_start(); ?>
  <form class="modal-body form" method="post" action="<?= e(url($id ? '/milestones/'.$id : '/projects/'.$pr['id'].'/milestones')) ?>"><?= csrf_field() ?>
    <div class="field"><label>Title *</label><input type="text" name="title" required maxlength="190" value="<?= e($v('title')) ?>" placeholder="e.g. Design sign-off"></div>
    <div class="field"><label>Description</label><textarea name="description" rows="3"><?= e($v('description')) ?></textarea></div>
    <div class="form-grid g3">
      <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= e($v('due_date')) ?>"></div>
      <div class="field"><label>Amount (<?= e(currency_symbol()) ?>)</label><input type="number" step="0.01" min="0" name="amount" value="<?= e($v('amount')) ?>"></div>
      <div class="field"><label>Status</label><select name="status"><?php foreach (['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $v('status', 'pending')) ?>><?= $l ?></option><?php endforeach ?></select></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save milestone</button></div>
  </form>
  <?php return (string) ob_get_clean(); };
?>
<div class="card">
  <div class="card-head"><div><h3>Milestones</h3><p class="xs muted mb0">Checkpoints, payment stages and their task progress.</p></div><?php if ($canManage): ?><button type="button" class="btn primary sm" data-modal="ms-new"><?= icon('plus', 'sm') ?> Add milestone</button><?php endif ?></div>
  <?php if (!$milestones): ?><?= empty_state('No milestones yet', 'Break the project into meaningful checkpoints.', 'calendar-person', $canManage ? '<button type="button" class="btn primary mt" data-modal="ms-new">'.icon('plus', 'sm').' Add milestone</button>' : '') ?><?php else: ?>
  <div class="ms-list">
    <?php foreach ($milestones as $i => $m): $pct = $m['total'] ? (int) round($m['done'] / $m['total'] * 100) : ($m['status'] === 'completed' ? 100 : 0); $mlate = $m['status'] !== 'completed' && $m['due_date'] && $m['due_date'] < date('Y-m-d'); $open = (int) $m['total'] - (int) $m['done']; ?>
    <div class="ms-item<?= $m['status'] === 'completed' ? ' is-done' : '' ?>">
      <div class="ms-num <?= $m['status'] === 'completed' ? 'bg-mint' : 'bg-'.e(['lav', 'sky', 'peach', 'lemon', 'pink'][$i % 5]) ?>"><?= $m['status'] === 'completed' ? icon('check') : $i + 1 ?></div>
      <div class="grow">
        <div class="flex between wrap"><b><?= e($m['title']) ?></b><span class="flex"><?= badge($mlate ? 'overdue' : $m['status'], $mlate ? 'Overdue' : null) ?><?php if ($m['amount'] !== null): ?><span class="pill pill-lav"><?= e(money($m['amount'])) ?></span><?php endif ?></span></div>
        <?php if ($m['description']): ?><p class="small muted mb0"><?= nl2br(e($m['description'])) ?></p><?php endif ?>
        <div class="flex mt-s"><span class="due<?= $mlate ? ' late' : '' ?>"><?= icon('calendar', 'sm') ?> <?= e(fdate($m['due_date'])) ?></span><div class="progress grow"><span style="width:<?= $pct ?>%"></span></div><span class="xs muted nowrap"><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> tasks</span></div>
      </div>
      <?php if ($canManage): ?>
      <div class="flex">
        <form method="post" action="<?= e(url('/milestones/'.$m['id'].'/complete')) ?>"<?= $m['status'] !== 'completed' && $open ? ' data-confirm="'.e("$open task(s) are still open. Mark this milestone complete anyway?").'"' : '' ?>><?= csrf_field() ?><?php if ($m['status'] !== 'completed' && $open): ?><input type="hidden" name="force" value="1"><?php endif ?><button class="btn sm <?= $m['status'] === 'completed' ? 'ghost' : 'mint' ?>"><?= icon($m['status'] === 'completed' ? 'refresh' : 'check', 'sm') ?> <?= $m['status'] === 'completed' ? 'Reopen' : 'Complete' ?></button></form>
        <details class="dd"><summary class="btn icon sm ghost" aria-label="More"><?= icon('more', 'sm') ?></summary><div class="dd-menu">
          <button type="button" data-modal="ms-<?= (int) $m['id'] ?>"><?= icon('edit', 'sm') ?> Edit</button>
          <form method="post" action="<?= e(url('/milestones/'.$m['id'].'/delete')) ?>" data-confirm="Delete this milestone? Its tasks stay in the project."><?= csrf_field() ?><button class="danger-text"><?= icon('trash', 'sm') ?> Delete</button></form>
        </div></details>
      </div>
      <dialog class="modal" id="ms-<?= (int) $m['id'] ?>"><div class="modal-head"><h3>Edit milestone</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><?= $msForm($m) ?></dialog>
      <?php endif ?>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php if ($canManage): ?><dialog class="modal" id="ms-new"><div class="modal-head"><h3>New milestone</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><?= $msForm(null) ?></dialog><?php endif ?>
