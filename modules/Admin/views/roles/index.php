<?php $colors = ['primary', 'lav', 'mint', 'lemon', 'peach', 'sky', 'pink']; $editable = array_filter($roles, static fn ($r) => $r['slug'] !== 'super_admin'); ?>
<?= partial('partials/page_head', ['title' => 'Users & access', 'subtitle' => 'Roles decide what people may do. Permissions are enforced on the server for every page. Super Admin always has full access.', 'crumbs' => [['System'], ['Users & access', '/admin/users'], ['Roles & permissions']], 'actions' => '<button class="btn primary" data-modal="new-role">'.icon('plus', 'sm').' New role</button>']) ?>
<?= partial('admin::users/_tabs', ['active' => 'roles']) ?>
<div class="role-cards mb">
<?php foreach ($roles as $i => $r): ?>
  <div class="card role-card"><span class="ic-chip" style="--c:var(--<?= $colors[$i % 7] ?>)"><?= icon($r['slug'] === 'super_admin' ? 'shield' : 'key') ?></span><div class="grow"><b><?= e($r['name']) ?></b><div class="xs muted"><?= (int) $r['users'] ?> user<?= (int) $r['users'] === 1 ? '' : 's' ?><?= $r['is_system'] ? ' · system role' : '' ?></div></div>
    <?php if (!$r['is_system'] && !$r['users']): ?><form method="post" action="<?= e(url('/admin/roles/'.$r['id'].'/delete')) ?>" data-confirm="Delete the role <?= e($r['name']) ?>?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete role" aria-label="Delete role <?= e($r['name']) ?>"><?= icon('trash', 'sm') ?></button></form><?php endif ?></div>
<?php endforeach ?>
</div>
<form method="post" action="<?= e(url('/admin/roles/permissions')) ?>" class="set-form" data-dirty-watch><?= csrf_field() ?>
  <div class="card"><div class="card-head"><div><h3>Permission matrix</h3><p class="xs muted mb0">Tick what each role may do. Disabled features stay blocked regardless of these grants.<?= $kept ? ' Permissions of switched-off features are hidden and keep their current grants.' : '' ?></p></div></div>
  <div class="table-wrap"><table class="table perm-table">
    <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($byModule as $mod => $perms): if (!empty($modules[$mod]['labs']) && !\Core\Modules::enabled($mod)) { continue; } ?>
      <tr class="mod-row"><td colspan="<?= count($roles) + 1 ?>"><?= e($modules[$mod]['name'] ?? ucfirst($mod)) ?> <?= \Core\Modules::enabled($mod) ? '' : badge('inactive', 'Off') ?></td></tr>
      <?php foreach ($perms as $p): ?><tr><td><?= e($p['label']) ?><div class="xs muted mono"><?= e($p['key']) ?></div></td>
        <?php foreach ($roles as $r): ?><td><?php if ($r['slug'] === 'super_admin'): ?><span class="muted" title="Always allowed"><?= icon('check-circle', 'sm') ?></span><?php else: ?><input type="checkbox" name="grants[<?= $r['id'] ?>][<?= e($p['key']) ?>]" value="1"<?= checked(isset($grants[$r['id']][$p['key']])) ?> aria-label="<?= e($r['name'].': '.$p['label']) ?>"><?php endif ?></td><?php endforeach ?>
      </tr><?php endforeach ?>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <?php foreach ($byModule as $mod => $perms): if (empty($modules[$mod]['labs']) || \Core\Modules::enabled($mod)) { continue; } foreach ($perms as $p): foreach ($roles as $r): if (isset($grants[$r['id']][$p['key']]) && $r['slug'] !== 'super_admin'): ?><input type="hidden" name="grants[<?= $r['id'] ?>][<?= e($p['key']) ?>]" value="1"><?php endif; endforeach; endforeach; endforeach ?>
  <?php foreach ($kept as $k): foreach ($roles as $r): if (isset($grants[$r['id']][$k]) && $r['slug'] !== 'super_admin'): ?><input type="hidden" name="grants[<?= $r['id'] ?>][<?= e($k) ?>]" value="1"><?php endif; endforeach; endforeach ?></div>
  <div class="save-bar"><span class="sb-note"><span class="sb-dot"></span><span data-dirty-text>All changes saved</span></span><span class="xs muted sb-sub"><?= count($editable) ?> editable roles · users get new rights on their next request</span><button class="btn primary"><?= icon('check', 'sm') ?> Save permissions</button></div>
</form>
<dialog class="modal" id="new-role"><div class="modal-head"><h3>New role</h3><button class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url('/admin/roles')) ?>"><?= csrf_field() ?>
  <div class="field"><label for="nr-name">Name</label><input id="nr-name" name="name" required maxlength="100" placeholder="e.g. Team Lead"></div>
  <div class="field"><label for="nr-desc">Description</label><input id="nr-desc" name="description" maxlength="255"></div>
  <div class="field"><label for="nr-copy">Copy permissions from</label><select id="nr-copy" name="copy_from"><option value="">Start empty</option><?php foreach ($editable as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach ?></select></div>
  <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary">Create role</button></div>
</form></dialog>
