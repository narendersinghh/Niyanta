<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Controller, DB, Gate, Modules, Response};

/** Roles & permission matrix (Super Admin only). */
final class RoleController extends Controller {
  public function index(): Response {
    $roles = DB::all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS users FROM roles r ORDER BY r.id');
    $perms = DB::all('SELECT * FROM permissions ORDER BY module, `key`');
    $grants = [];
    foreach (DB::all('SELECT role_id, permission FROM role_permissions') as $g) { $grants[$g['role_id']][$g['permission']] = true; }
    /* Permissions of switched-off features are hidden; their grants are kept through hidden inputs. */
    $byModule = []; $kept = [];
    foreach ($perms as $p) { if (\Core\Features::permEnabled($p['key']) && (!str_starts_with($p['key'], 'ai.') || feature_on('ai'))) { $byModule[$p['module']][] = $p; } else { $kept[] = $p['key']; } }
    return $this->view('admin::roles/index', ['title' => 'Roles & Access', 'roles' => $roles, 'byModule' => $byModule, 'kept' => $kept, 'grants' => $grants, 'modules' => Modules::all(), 'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]); }

  public function store(): Response {
    $d = $this->validate(['name' => 'required|max:100', 'description' => 'nullable|max:255']);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($d['name'])), '_') ?: 'role';
    if (DB::value('SELECT 1 FROM roles WHERE slug=?', [$slug])) { $slug .= '_'.random_int(10, 99); }
    $id = DB::insert('roles', ['slug' => $slug, 'name' => $d['name'], 'description' => $d['description'], 'is_system' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    if ($copy = $this->request->int('copy_from')) { DB::query('INSERT INTO role_permissions (role_id, permission) SELECT ?, permission FROM role_permissions WHERE role_id=?', [$id, $copy]); }
    Gate::flush();
    Audit::log('role.create', 'role', $id, ['name' => $d['name']]);
    $this->flash('Role created.');
    return $this->redirect('/admin/roles'); }

  public function savePermissions(): Response {
    $valid = array_fill_keys(DB::column('SELECT `key` FROM permissions'), true);
    $input = $this->request->arr('grants');
    DB::transaction(function () use ($valid, $input) {
      foreach (DB::all("SELECT id, slug FROM roles WHERE slug<>'super_admin'") as $r) {
        $keys = array_keys(array_intersect_key((array) ($input[$r['id']] ?? []), $valid));
        DB::delete('role_permissions', 'role_id=?', [$r['id']]);
        DB::insertMany('role_permissions', array_map(static fn ($k) => ['role_id' => $r['id'], 'permission' => $k], $keys)); } });
    Gate::flush();
    Audit::log('role.permissions_updated', 'role', null);
    $this->flash('Permissions saved.');
    return $this->redirect('/admin/roles'); }

  public function destroy(int $id): Response {
    $r = $this->findOr404('roles', $id);
    if ($r['is_system']) { $this->abort(422, 'System roles cannot be deleted.'); }
    if (DB::value('SELECT COUNT(*) FROM users WHERE role_id=?', [$id])) { $this->abort(422, 'Reassign users of this role before deleting it.'); }
    DB::delete('roles', 'id=?', [$id]);
    Gate::flush();
    Audit::log('role.delete', 'role', $id, ['name' => $r['name']]);
    $this->flash('Role deleted.');
    return $this->redirect('/admin/roles'); } }
