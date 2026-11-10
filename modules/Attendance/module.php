<?php
use Modules\Attendance\Controllers\{AgentController, AttendanceController, ConnectController, HubController, InstallController, ScreenshotController, SettingsController, TeamNowController, TimeController, TimesheetController};
use Modules\Attendance\Services\{AttendanceService, DesktopApp, DesktopConnect, Monitoring, TimeService, Widgets};

return [
  'key' => 'attendance', 'name' => 'Attendance & Time Tracking', 'icon' => 'clock', 'order' => 40, 'version' => '1.2.0',
  'description' => 'Desktop time tracker (Windows, macOS, Linux): the tracked day is the attendance day, with pause, project switching, idle detection, auto-approved timesheets with manager rejection and consent-based screenshots.',
  'permissions' => [
    'attendance.track' => ['label' => 'Track own time with the desktop app', 'roles' => ['manager', 'hr', 'employee']],
    'attendance.view_all' => ['label' => 'View team attendance & time (Manager: direct reports + project members)', 'roles' => ['manager', 'hr']],
    'attendance.manage' => ['label' => 'Edit attendance records', 'roles' => ['hr']],
    'attendance.timesheets.approve' => ['label' => 'Review timesheets (reject / re-approve team weeks)', 'roles' => ['manager', 'hr']],
    'attendance.screenshots.view' => ['label' => 'View monitoring screenshots of team', 'roles' => []],
  ],
  'nav' => [
    ['label' => 'Time & Attendance', 'icon' => 'timer', 'color' => '#F5A524', 'url' => '/time', 'also' => ['/timesheets', '/attendance'], 'perm' => 'attendance.track', 'section' => 'main', 'order' => 3],
    ['label' => 'Time & Attendance', 'icon' => 'timer', 'color' => '#F5A524', 'url' => '/attendance/team', 'also' => ['/timesheets', '/time'], 'perm' => 'attendance.view_all', 'hide_if' => 'attendance.track', 'section' => 'main', 'order' => 3],
  ],
  'settings_view' => 'attendance::settings',
  'routes' => function (Core\Router $r): void {
    $t = 'attendance.track'; $ts = ['attendance.track', 'attendance.timesheets.approve'];
    $r->get('/attendance', [AttendanceController::class, 'index'], ['attendance.track', 'attendance.view_all']);
    $r->get('/attendance/team', [AttendanceController::class, 'team'], 'attendance.view_all');
    $r->get('/attendance/export', [AttendanceController::class, 'export'], ['perm' => 'attendance.view_all', 'throttle' => [10, 60]]);
    $r->post('/attendance/records', [AttendanceController::class, 'save'], 'attendance.manage');
    $r->get('/attendance/settings', [SettingsController::class, 'edit'], '__super_admin');
    $r->post('/attendance/settings', [SettingsController::class, 'save'], '__super_admin');
    $r->get('/time', [TimeController::class, 'index'], $t);
    $r->get('/time/status', [TimeController::class, 'status'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->get('/time/desktop/download/{file}', [TimeController::class, 'download'], ['perm' => $t, 'throttle' => [30, 60]]);
    /* one-line installs (no browser): signed ?k= key instead of a session, see Services/DesktopInstall.php */
    $inst = ['auth' => false, 'csrf' => false, 'throttle' => [300, 300]];
    $r->get('/time/desktop/install.sh', [InstallController::class, 'shell'], $inst);
    $r->get('/time/desktop/install.ps1', [InstallController::class, 'powershell'], $inst);
    $r->get('/time/desktop/get/{file}', [InstallController::class, 'file'], $inst);
    /* "Sign in with your browser" for the desktop app (deep link niyanta-tracker://connect, see Services/DesktopConnect.php) */
    $r->post('/time/desktop/connect-link', [ConnectController::class, 'link'], ['perm' => $t, 'throttle' => [10, 60]]);
    $r->get('/time/desktop/connect', [ConnectController::class, 'show'], ['perm' => $t, 'throttle' => [30, 60]]);
    $r->post('/time/desktop/connect', [ConnectController::class, 'allow'], ['perm' => $t, 'throttle' => [20, 60]]);
    $r->get('/time/tasks', [TimeController::class, 'tasks'], $t);
    /* top-bar "active members" widget: count refresh (?count=1) and the popup lists, team-scoped */
    $r->get('/time/team/now', [TeamNowController::class, 'index'], ['perm' => ['attendance.view_all', 'attendance.track'], 'throttle' => [60, 60]]);
    $r->post('/time/entries', [TimeController::class, 'storeEntry'], $t);
    $r->post('/time/entries/{id}', [TimeController::class, 'updateEntry'], $t);
    $r->post('/time/entries/{id}/delete', [TimeController::class, 'deleteEntry'], $t);
    $r->post('/time/consent', [TimeController::class, 'consent'], $t);
    $r->post('/time/consent/revoke', [TimeController::class, 'revokeConsent'], $t);
    $r->post('/time/tokens/{id}/revoke', [TimeController::class, 'revokeToken'], $t);
    $r->get('/time/daily', [HubController::class, 'daily'], ['attendance.track', 'attendance.view_all']);
    $r->get('/time/monthly', [HubController::class, 'monthly'], ['attendance.track', 'attendance.view_all']);
    $r->get('/time/screenshots', [ScreenshotController::class, 'index'], ['attendance.track', 'attendance.screenshots.view']);
    $r->get('/time/screenshots/{id}/image', [ScreenshotController::class, 'image'], ['attendance.track', 'attendance.screenshots.view']);
    $r->get('/timesheets', [TimesheetController::class, 'index'], $ts);
    $r->post('/timesheets/resubmit', [TimesheetController::class, 'resubmit'], $t);
    $r->get('/timesheets/approvals', [TimesheetController::class, 'approvals'], 'attendance.timesheets.approve');
    $r->get('/timesheets/user/{user_id}', [TimesheetController::class, 'person'], ['attendance.timesheets.approve', 'attendance.view_all']);
    $r->get('/timesheets/{id}', [TimesheetController::class, 'show'], $ts);
    $r->post('/timesheets/{id}/approve', [TimesheetController::class, 'approve'], 'attendance.timesheets.approve');
    $r->post('/timesheets/{id}/reject', [TimesheetController::class, 'reject'], 'attendance.timesheets.approve');
    /* Niyanta Tracker desktop API (token auth, see AGENT_API.md) */
    $api = ['auth' => false, 'csrf' => false, 'throttle' => [600, 60]];
    $r->post('/api/agent/login', [AgentController::class, 'login'], ['auth' => false, 'csrf' => false, 'throttle' => [300, 300]]);
    $r->post('/api/agent/connect', [AgentController::class, 'connect'], ['auth' => false, 'csrf' => false, 'throttle' => [30, 300]]);
    $r->post('/api/agent/logout', [AgentController::class, 'logout'], $api);
    $r->get('/api/agent/bootstrap', [AgentController::class, 'bootstrap'], $api);
    $r->get('/api/agent/status', [AgentController::class, 'status'], $api);
    $r->get('/api/agent/state', [AgentController::class, 'state'], $api);
    $r->get('/api/agent/tasks', [AgentController::class, 'tasks'], $api);
    $r->post('/api/agent/day/start', [AgentController::class, 'dayStart'], $api);
    $r->post('/api/agent/pause', [AgentController::class, 'pause'], $api);
    $r->post('/api/agent/resume', [AgentController::class, 'resume'], $api);
    $r->post('/api/agent/switch', [AgentController::class, 'switchProject'], $api);
    $r->post('/api/agent/day/end', [AgentController::class, 'dayEnd'], $api);
    $r->post('/api/agent/heartbeat', [AgentController::class, 'heartbeat'], $api);
    $r->post('/api/agent/activity', [AgentController::class, 'activity'], $api);
    $r->post('/api/agent/idle', [AgentController::class, 'idle'], $api);
    $r->post('/api/agent/consent', [AgentController::class, 'consent'], $api);
    $r->post('/api/agent/screenshot', [AgentController::class, 'screenshot'], ['auth' => false, 'csrf' => false, 'throttle' => [240, 60]]);
    $r->post('/api/agent/error', [AgentController::class, 'error'], ['auth' => false, 'csrf' => false, 'throttle' => [60, 60]]);
    $r->get('/api/agent/version', [AgentController::class, 'version'], ['auth' => false, 'csrf' => false, 'throttle' => [30, 60]]);
    $r->get('/api/agent/updates/{file}', [AgentController::class, 'updates'], ['auth' => false, 'csrf' => false, 'throttle' => [60, 60]]);
  },
  'widgets' => [
    ['zone' => 'kpis', 'order' => 30, 'perm' => 'attendance.track', 'feature' => 'attendance', 'render' => [Widgets::class, 'kpi']],
    ['zone' => 'side', 'order' => 5, 'perm' => 'attendance.track', 'feature' => 'attendance', 'render' => [Widgets::class, 'side']],
    ['zone' => 'main', 'order' => 20, 'perm' => 'attendance.view_all', 'feature' => 'attendance', 'render' => [Widgets::class, 'team']],
  ],
  'cron' => [
    'auto_close' => ['feature' => 'attendance', 'daily' => '23:55', 'run' => [AttendanceService::class, 'autoClose']],
    'stale_timers' => ['feature' => 'attendance', 'every' => 5, 'run' => [TimeService::class, 'staleTimers']],
    'screenshot_cleanup' => ['feature' => 'attendance', 'daily' => '03:40', 'run' => [Monitoring::class, 'cleanup']],
    'connect_codes_cleanup' => ['every' => 60, 'run' => [DesktopConnect::class, 'cleanup']],
    'tracker_mirror' => ['feature' => 'attendance', 'every' => 30, 'run' => [DesktopApp::class, 'cron']],
  ],
  /* top bar: own live tracking chip (attendance.track, while tracking) and the team "N active" pill (attendance.view_all), next to Ask Goraiya */
  'topbar' => static function (array $user): string {
    if (!feature_on('attendance')) { return ''; }
    $h = Core\Gate::allows('attendance.track', $user) && ($run = Modules\Attendance\Services\TimeService::running((int) $user['id'])) ? Core\View::partial('attendance::partials/topbar_live', ['run' => $run]) : '';
    return $h.(Core\Gate::any(['attendance.view_all', 'attendance.track'], $user) ? Core\View::partial('attendance::partials/topbar_team', ['active' => TeamNowController::count($user), 'full' => TeamNowController::full($user)]) : ''); },
  'global_scripts' => ['js/tt-live.js' => ['perm' => ['attendance.track', 'attendance.view_all'], 'feature' => 'attendance']],
  'file_access' => [],
];
