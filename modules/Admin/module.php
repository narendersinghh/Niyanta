<?php
use Modules\Admin\Controllers\{UserController, RoleController, ModuleController, SettingsController, AuditController, BackupController, UpdateController, CronAdminController, DemoController};

return [
  'key' => 'admin', 'name' => 'System Administration', 'icon' => 'settings', 'core' => true, 'order' => 90, 'version' => '1.0.0',
  'description' => 'Users, roles & permissions, features, settings, branding, security, data retention, demo data, backups, updates and cron.',
  'permissions' => [
    'admin.users.view' => ['label' => 'View users', 'roles' => ['hr']],
    'admin.users.manage' => ['label' => 'Create & edit users (non-admin roles)', 'roles' => ['hr']],
    'admin.audit.view' => ['label' => 'View audit log', 'roles' => []],
  ],
  'nav' => [
    ['label' => 'Users & Access', 'icon' => 'users', 'color' => '#2E9BF6', 'url' => '/admin/users', 'also' => ['/admin/roles'], 'perm' => 'admin.users.view', 'section' => 'system', 'order' => 1],
    ['label' => 'Settings', 'icon' => 'sliders', 'color' => '#7C5CFF', 'url' => '/admin/settings', 'perm' => '__super_admin', 'section' => 'system', 'order' => 4],
    ['label' => 'Audit Log', 'icon' => 'history', 'color' => '#FF6F59', 'url' => '/admin/audit', 'perm' => 'admin.audit.view', 'section' => 'system', 'order' => 5],
  ],
  'routes' => function (Core\Router $r): void {
    $sa = ['perm' => '__super_admin'];
    $r->get('/admin/users', [UserController::class, 'index'], 'admin.users.view');
    $r->get('/admin/users/create', [UserController::class, 'create'], 'admin.users.manage');
    $r->post('/admin/users', [UserController::class, 'store'], 'admin.users.manage');
    $r->get('/admin/users/{id}/edit', [UserController::class, 'edit'], 'admin.users.manage');
    $r->post('/admin/users/{id}', [UserController::class, 'update'], 'admin.users.manage');
    $r->post('/admin/users/{id}/status', [UserController::class, 'toggleStatus'], 'admin.users.manage');
    $r->post('/admin/users/{id}/sessions', [UserController::class, 'revokeSessions'], 'admin.users.manage');
    $r->post('/admin/users/{id}/delete', [UserController::class, 'destroy'], '__super_admin');
    $r->get('/admin/roles', [RoleController::class, 'index'], $sa);
    $r->post('/admin/roles', [RoleController::class, 'store'], $sa);
    $r->post('/admin/roles/permissions', [RoleController::class, 'savePermissions'], $sa);
    $r->post('/admin/roles/{id}/delete', [RoleController::class, 'destroy'], $sa);
    $r->get('/admin/modules', [ModuleController::class, 'index'], $sa);
    $r->post('/admin/modules/{key}', [ModuleController::class, 'toggle'], $sa);
    $r->get('/admin/settings', [SettingsController::class, 'index'], $sa);
    $r->get('/admin/settings/{tab}', [SettingsController::class, 'index'], $sa);
    $r->post('/admin/settings/{tab}', [SettingsController::class, 'save'], $sa);
    $r->post('/admin/settings/email/test', [SettingsController::class, 'testEmail'], $sa + ['throttle' => [10, 600]]);
    $r->post('/admin/settings/security/logout-all', [SettingsController::class, 'logoutAll'], $sa);
    $r->post('/admin/settings/appearance/reset', [SettingsController::class, 'resetTheme'], $sa);
    $r->post('/admin/settings/retention/run', [SettingsController::class, 'runCleanup'], $sa + ['throttle' => [10, 600]]);
    $r->post('/admin/settings/demo/import', [DemoController::class, 'import'], $sa + ['throttle' => [5, 600]]);
    $r->post('/admin/settings/demo/delete', [DemoController::class, 'delete'], $sa + ['throttle' => [5, 600]]);
    $r->get('/admin/audit', [AuditController::class, 'index'], 'admin.audit.view');
    $r->get('/admin/audit/errors', [Modules\Admin\Controllers\ErrorLogController::class, 'index'], 'admin.audit.view');
    $r->post('/admin/audit/errors/{id}/resolve', [Modules\Admin\Controllers\ErrorLogController::class, 'resolve'], 'admin.audit.view');
    $r->post('/admin/audit/errors/clear', [Modules\Admin\Controllers\ErrorLogController::class, 'clear'], '__super_admin');
    $r->get('/admin/backups', [BackupController::class, 'index'], $sa);
    $r->post('/admin/backups', [BackupController::class, 'create'], $sa);
    $r->get('/admin/backups/{id}/download', [BackupController::class, 'download'], $sa);
    $r->post('/admin/backups/{id}/delete', [BackupController::class, 'destroy'], $sa);
    $r->post('/admin/backups/{id}/restore', [BackupController::class, 'restore'], $sa + ['throttle' => [3, 600]]);
    $r->get('/admin/updates', [UpdateController::class, 'index'], $sa);
    $r->post('/admin/updates/check', [UpdateController::class, 'check'], $sa);
    $r->post('/admin/updates/apply', [UpdateController::class, 'apply'], $sa);
    $r->post('/admin/updates/migrate', [UpdateController::class, 'migrate'], $sa);
    $r->post('/admin/updates/desktop-refresh', [UpdateController::class, 'desktopRefresh'], $sa + ['throttle' => [6, 600]]);
    $r->post('/admin/updates/upload', [UpdateController::class, 'upload'], $sa + ['throttle' => [5, 600]]);
    $r->get('/admin/updates/{id}', [UpdateController::class, 'show'], $sa);
    $r->get('/admin/cron', [CronAdminController::class, 'index'], $sa);
    $r->post('/admin/cron/run', [CronAdminController::class, 'run'], $sa);
    $r->post('/admin/cron/token', [CronAdminController::class, 'regenerateToken'], $sa);
  },
  'cron' => [
    'backup' => ['daily' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $bt = (string) Core\Settings::get('backups.time', '02:30')) ? $bt : '02:30', 'run' => [Modules\Admin\Services\BackupService::class, 'scheduled']],
  ],
];
