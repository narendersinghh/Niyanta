<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Auth, Controller, DB, Mailer, Response};

/** User management. HR may manage non-admin users; only a Super Admin can create or edit Super Admins. */
final class UserController extends Controller {
  public function index(): Response {
    $q = $this->request->str('q'); $role = $this->request->int('role'); $status = $this->request->str('status');
    $sql = 'SELECT u.id, u.name, u.email, u.phone, u.status, u.avatar, u.designation, u.department, u.last_login_at, r.name AS role_name, r.slug AS role_slug, m.name AS manager_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN users m ON m.id=u.manager_id WHERE 1=1';
    $p = [];
    if ($q !== '') { $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.employee_code LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q)); }
    if ($role) { $sql .= ' AND u.role_id=?'; $p[] = $role; }
    if (in_array($status, ['active', 'inactive', 'invited'], true)) { $sql .= ' AND u.status=?'; $p[] = $status; }
    $page = DB::paginate($sql.' ORDER BY u.status=\'active\' DESC, u.name', $p, 20);
    $roleStats = DB::all("SELECT r.id, r.name, r.slug, COUNT(u.id) AS n FROM roles r LEFT JOIN users u ON u.role_id=r.id AND u.status<>'inactive' GROUP BY r.id, r.name, r.slug ORDER BY r.id");
    return $this->view('admin::users/index', ['title' => 'Users', 'p' => $page, 'roles' => array_column($roleStats, 'name', 'id'), 'roleStats' => $roleStats, 'f' => ['q' => $q, 'role' => $role, 'status' => $status], 'styles' => ['css/admin.css']]); }

  public function create(): Response { return $this->view('admin::users/form', ['title' => 'Add user', 'u' => ['id' => null, 'status' => 'active', 'role_id' => null, 'manager_id' => null], 'styles' => ['css/admin.css']] + $this->lookups()); }

  public function edit(int $id): Response {
    $u = $this->findOr404('users', $id);
    $this->guardTarget($u);
    return $this->view('admin::users/form', ['title' => 'Edit '.$u['name'], 'u' => $u, 'styles' => ['css/admin.css']] + $this->lookups()); }

  private function lookups(): array {
    $roles = Auth::isSuperAdmin() ? DB::pairs('SELECT id, name FROM roles ORDER BY id') : DB::pairs("SELECT id, name FROM roles WHERE slug<>'super_admin' ORDER BY id");
    return ['roles' => $roles, 'managers' => DB::pairs("SELECT id, name FROM users WHERE status='active' ORDER BY name")]; }

  private function guardTarget(?array $u, ?int $roleId = null): void {
    if (Auth::isSuperAdmin()) { return; }
    $sa = (int) DB::value("SELECT id FROM roles WHERE slug='super_admin'");
    if (($u && (int) $u['role_id'] === $sa) || $roleId === $sa) { $this->abort(403, 'Only a Super Admin can manage Super Admin accounts.'); } }

  private function rules(?int $id): array {
    return ['name' => 'required|max:150', 'email' => 'required|email|max:190|unique:users,email'.($id ? ",$id" : ''), 'phone' => 'nullable|phone', 'role_id' => 'required|integer|exists:roles,id', 'status' => 'required|in:active,inactive',
      'designation' => 'nullable|max:120', 'department' => 'nullable|max:120', 'manager_id' => 'nullable|integer|exists:users,id', 'employee_code' => 'nullable|max:40|unique:users,employee_code'.($id ? ",$id" : ''), 'joined_on' => 'nullable|date']; }

  public function store(): Response {
    $d = $this->validate($this->rules(null), ['role_id' => 'Role', 'manager_id' => 'Reporting manager']);
    $this->guardTarget(null, (int) $d['role_id']);
    $pass = (string) $this->request->input('password', '');
    $invite = $pass === '';
    if (!$invite && ($e = Auth::validatePassword($pass))) { throw new \Core\ValidationException(['password' => $e]); }
    $now = date('Y-m-d H:i:s');
    $id = DB::insert('users', $d + ['password_hash' => password_hash($invite ? bin2hex(random_bytes(24)) : $pass, PASSWORD_DEFAULT), 'created_at' => $now, 'updated_at' => $now]);
    if ($invite) { $this->sendInvite($id); }
    Audit::log('user.create', 'user', $id, ['role_id' => $d['role_id'], 'invited' => $invite]);
    $this->flash($invite ? 'User created and invitation email queued.' : 'User created.');
    return $this->redirect('/admin/users'); }

  public function update(int $id): Response {
    $u = $this->findOr404('users', $id);
    $d = $this->validate($this->rules($id), ['role_id' => 'Role', 'manager_id' => 'Reporting manager']);
    $this->guardTarget($u, (int) $d['role_id']);
    if ($id === Auth::id() && ((int) $d['role_id'] !== (int) $u['role_id'] || $d['status'] !== 'active')) { $this->abort(422, 'You cannot change your own role or deactivate yourself.'); }
    if ((int) $d['manager_id'] === $id) { $d['manager_id'] = null; }
    $pass = (string) $this->request->input('password', '');
    if ($pass !== '') {
      if ($e = Auth::validatePassword($pass)) { throw new \Core\ValidationException(['password' => $e]); }
      $d['password_hash'] = password_hash($pass, PASSWORD_DEFAULT); }
    DB::update('users', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    if ($pass !== '' || $d['status'] !== 'active' || (int) $d['role_id'] !== (int) $u['role_id']) { Auth::bumpSessions($id); }
    Audit::log('user.update', 'user', $id, ['changed' => array_keys(array_diff_assoc(array_map('strval', array_intersect_key($d, $u)), array_map('strval', array_intersect_key($u, $d))))]);
    $this->flash('User updated.');
    return $this->redirect('/admin/users'); }

  public function toggleStatus(int $id): Response {
    $u = $this->findOr404('users', $id);
    $this->guardTarget($u);
    if ($id === Auth::id()) { $this->abort(422, 'You cannot deactivate yourself.'); }
    $new = $u['status'] === 'active' ? 'inactive' : 'active';
    DB::update('users', ['status' => $new], 'id=?', [$id]);
    Auth::bumpSessions($id);
    Audit::log('user.status', 'user', $id, ['status' => $new]);
    $this->flash("User marked $new.");
    return $this->back(); }

  public function revokeSessions(int $id): Response {
    $u = $this->findOr404('users', $id);
    $this->guardTarget($u);
    Auth::bumpSessions($id);
    Audit::log('user.sessions_revoked', 'user', $id);
    $this->flash('All sessions for this user were signed out.');
    return $this->back(); }

  private function sendInvite(int $id): void {
    $u = DB::find('users', $id);
    $token = \Core\Crypto::token(32);
    DB::insert('password_resets', ['user_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400), 'created_at' => date('Y-m-d H:i:s')]);
    $app = setting('branding.app_name', 'Niyanta');
    Mailer::queue($u['email'], "You're invited to $app", Mailer::template("Welcome to $app", '<p>Hi '.e($u['name']).',</p><p>An account has been created for you. Set your password to get started (link valid for 7 days).</p>', absolute_url('/reset-password/'.$token), 'Set my password'), $u['name']); } }
