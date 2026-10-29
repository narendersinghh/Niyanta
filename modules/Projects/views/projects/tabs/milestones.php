<?php
use Modules\Projects\Services\ProjectService as PS;
$msForm = static function (?array $m) use ($pr): string {
  $mid = $m['id'] ?? null; $v = static fn ($k, $d = '') => $m[$k] ?? $d; $p = 'ms'.($mid ?? 'n').'-';
  ob_start(); ?>
  <form class="modal-body form" method="post" action="<?= e(url($mid ? '/milestones/'.$mid : '/projects/'.$pr['id'].'/milestones')) ?>"><?= csrf_field() ?>
    <div class="field"><label for="<?= $p ?>title">Title *</label><input id="<?= $p ?>title" type="text" name="title" required maxlength="190" value="<?= e($v('title')) ?>" placeholder="e.g. Design sign-off"></div>
    <div class="field"><label for="<?= $p ?>desc">Description</label><textarea id="<?= $p ?>desc" name="description" rows="3" placeholder="What has to be true for this milestone to be complete?"><?= e($v('description')) ?></textarea></div>
    <div class="form-grid g3">
      <div class="field"><label for="<?= $p ?>due">Due date</label><input id="<?= $p ?>due" type="date" name="due_date" value="<?= e($v('due_date')) ?>"></div>
      <div class="field"><label for="<?= $p ?>amt">Amount (<?= e(currency_symbol()) ?>)</label><input id="<?= $p ?>amt" type="number" step="0.01" min="0" name="amount" value="<?= e($v('amount')) ?>"><div class="hint">Payment stage value, if any.</div></div>
      <div class="field"><label for="<?= $p ?>st">Status</label><select id="<?= $p ?>st" name="status"><?php foreach (['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $v('status', 'pending')) ?>><?= $l ?></option><?php endforeach ?></select></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save milestone</button></div>
  </form>
  <?php return (string) ob_get_clean(); };
$today = date('Y-m-d');
$late = count(array_filter($milestones, static fn ($m) => $m['status'] !== 'completed' && $m['due_date'] && $m['due_date'] < $today));
$value = array_sum(array_map(static fn ($m) => (float) $m['amount'], $milestones));
$valueDone = array_sum(array_map(static fn ($m) => $m['status'] === 'completed' ? (float) $m['amount'] : 0, $milestones));
$nextDue = array_values(array_filter($milestones, static fn ($m) => $m['status'] !== 'completed' && $m['due_date']))[0] ?? null;
?>
<div class="grid g4 mb">
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= $nums['ms_done'] ?>/<?= $nums['ms'] ?></div><div class="lbl">Milestones completed</div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('flag') ?></span><div><div class="num num-sm"><?= $nextDue ? e(fdate($nextDue['due_date'], 'd M')) : '-' ?></div><div class="lbl"><?= $nextDue ? 'Next: '.e(str_limit($nextDue['title'], 28)) : 'No upcoming milestone' ?></div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= $late ?></div><div class="lbl">Overdue</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num num-sm"><?= $value ? e(money($valueDone)) : '-' ?></div><div class="lbl"><?= $value ? 'Earned of '.e(money($value)) : 'No payment stages' ?></div></div></div>
</div>
<div class="card">
  <div class="card-head"><div><h3>Milestones</h3><p class="xs muted mb0">Checkpoints and payment stages with their task progress.</p></div></div>
  <?php if (!$milestones): ?><?= empty_state('No milestones yet', 'Break the project into meaningful checkpoints, design sign-off, beta, launch.', 'rocket', $canManage ? '<button type="button" class="btn primary mt" data-modal="ms-new">'.icon('plus', 'sm').' Add milestone</button>' : '') ?><?php else: ?>
  <div class="ms-list">
    <?php foreach ($milestones as $i => $m): $pct = $m['total'] ? (int) round($m['done'] / $m['total'] * 100) : ($m['status'] === 'completed' ? 100 : 0); $isDone = $m['status'] === 'completed'; $mlate = !$isDone && $m['due_date'] && $m['due_date'] < $today; $open = (int) $m['total'] - (int) $m['done']; ?>
    <div class="ms-item<?= $isDone ? ' is-done' : '' ?>">
      <span class="ms-mark<?= $isDone ? ' done' : ($mlate ? ' late' : '') ?>"><?= $isDone ? icon('check') : $i + 1 ?></span>
      <div class="grow">
        <div class="flex between wrap"><b><?= e($m['title']) ?></b><span class="flex"><?= $mlate ? badge('overdue', 'Overdue') : badge($m['status']) ?><?php if ($m['amount'] !== null): ?><span class="pill pill-lav"><?= e(money($m['amount'])) ?></span><?php endif ?></span></div>
        <?php if ($m['description']): ?><p class="small muted mb0 mt-s"><?= nl2br(e($m['description'])) ?></p><?php endif ?>
        <div class="ms-meta"><?= $m['due_date'] ? PS::dueChip($m['due_date'], $isDone ? 'done' : 'todo') : '<span class="due none">'.icon('calendar', 'sm').' No date</span>' ?><div class="progress"><span style="width:<?= $pct ?>%"></span></div><span class="xs muted nowrap"><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> tasks · <?= $pct ?>%</span></div>
      </div>
      <?php if ($canManage): ?>
      <div class="ms-acts">
        <form method="post" action="<?= e(url('/milestones/'.$m['id'].'/complete')) ?>"<?= !$isDone && $open ? ' data-confirm="'.e("$open task(s) are still open. Mark this milestone complete anyway?").'"' : '' ?>><?= csrf_field() ?><?php if (!$isDone && $open): ?><input type="hidden" name="force" value="1"><?php endif ?><button class="btn sm <?= $isDone ? 'ghost' : 'soft' ?>"><?= icon($isDone ? 'refresh' : 'check', 'sm') ?> <?= $isDone ? 'Reopen' : 'Complete' ?></button></form>
        <details class="dd"><summary class="btn icon sm ghost" aria-label="More actions for <?= e($m['title']) ?>"><?= icon('more', 'sm') ?></summary><div class="dd-menu">
          <button type="button" data-modal="ms-<?= (int) $m['id'] ?>"><?= icon('edit', 'sm') ?> Edit milestone</button>
          <div class="sep"></div>
          <form method="post" action="<?= e(url('/milestones/'.$m['id'].'/delete')) ?>" data-confirm="Delete this milestone? Its tasks stay in the project."><?= csrf_field() ?><button class="danger-text"><?= icon('trash', 'sm') ?> Delete</button></form>
        </div></details>
      </div>
      <dialog class="modal" id="ms-<?= (int) $m['id'] ?>" aria-label="Edit milestone"><div class="modal-head"><h3>Edit milestone</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><?= $msForm($m) ?></dialog>
      <?php endif ?>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php if ($canManage): ?><dialog class="modal" id="ms-new" aria-label="New milestone"><div class="modal-head"><h3>New milestone</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><?= $msForm(null) ?></dialog><?php endif ?>
