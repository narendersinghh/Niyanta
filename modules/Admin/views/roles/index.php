<?= partial('partials/page_head', ['title' => 'Roles & Access', 'subtitle' => 'Granular permissions are enforced on every route on the server. Super Admin always has full access.', 'crumbs' => [['System'], ['Roles']], 'actions' => '<button class="btn primary" data-modal="new-role">'.icon('plus', 'sm').' New role</button>']) ?>
<div class="grid g4 mb">
<?php $colors = ['dark', 'lav', 'mint', 'lemon', 'peach', 'sky', 'pink']; foreach ($roles as $i => $r): ?>
  <div class="tile <?= $i === 0 ? 'dark' : 'bg-'.$colors[$i % 7] ?>"><div class="flex between"><span class="tile-ic"><?= icon($i === 0 ? 'shield' : 'key') ?></span><?php if (!$r['is_system'] && !$r['users']): ?><form method="post" action="<?= e(url('/admin/roles/'.$r['id'].'/delete')) ?>" data-confirm="Delete this role?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?></div><div><div class="b" style="font-size:1.1rem"><?= e($r['name']) ?></div><div class="lbl"><?= (int) $r['users'] ?> users · <?= e(str_limit($r['description'], 40)) ?></div></div></div>
<?php endforeach ?>
</div>
<form method="post" action="<?= e(url('/admin/roles/permissions')) ?>" class="card"><?= csrf_field() ?>
  <div class="card-head"><h3>Permission matrix</h3><button class="btn primary"><?= icon('check', 'sm') ?> Save permissions</button></div>
  <div class="table-wrap"><table class="table perm-table">
    <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($byModule as $mod => $perms): ?>
      <tr><td colspan="<?= count($roles) + 1 ?>" style="background:var(--surface-2)"><b><?= e($modules[$mod]['name'] ?? ucfirst($mod)) ?></b> <?= \Core\Modules::enabled($mod) ? '' : badge('inactive', 'Module disabled') ?></td></tr>
      <?php foreach ($perms as $p): ?><tr><td><?= e($p['label']) ?><div class="xs muted mono"><?= e($p['key']) ?></div></td>
        <?php foreach ($roles as $r): ?><td><?php if ($r['slug'] === 'super_admin'): ?><?= icon('check-circle', 'sm') ?><?php else: ?><input type="checkbox" class="check" name="grants[<?= $r['id'] ?>][<?= e($p['key']) ?>]" value="1"<?= checked(isset($grants[$r['id']][$p['key']])) ?> aria-label="<?= e($r['name'].': '.$p['label']) ?>"><?php endif ?></td><?php endforeach ?>
      </tr><?php endforeach ?>
    <?php endforeach ?>
    </tbody>
  </table></div>
</form>
<dialog class="modal" id="new-role"><div class="modal-head"><h3>New role</h3><button class="btn icon sm ghost" data-close><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url('/admin/roles')) ?>"><?= csrf_field() ?>
  <div class="field"><label>Name</label><input name="name" required></div>
  <div class="field"><label>Description</label><input name="description"></div>
  <div class="field"><label>Copy permissions from</label><select name="copy_from"><option value="">Start empty</option><?php foreach ($roles as $r): if ($r['slug'] === 'super_admin') { continue; } ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach ?></select></div>
  <div class="form-actions"><button class="btn primary">Create role</button></div>
</form></dialog>
