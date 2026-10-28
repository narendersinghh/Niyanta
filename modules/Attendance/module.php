<?php
use Modules\Attendance\Controllers\{AgentController, AttendanceController, ScreenshotController, SettingsController, TimeController, TimesheetController};
use Modules\Attendance\Services\{AttendanceService, Monitoring, TimeService, Widgets};

return [
  'key' => 'attendance', 'name' => 'Attendance & Time Tracking', 'icon' => 'clock', 'order' => 40, 'version' => '1.0.0',
  'description' => 'Clock-in/out, breaks, timer & manual time entries, idle detection, timesheets, approvals and consent-based screenshots.',
  'permissions' => [
    'attendance.track' => ['label' => 'Clock in/out & track own time', 'roles' => ['manager', 'hr', 'employee']],
    'attendance.view_all' => ['label' => 'View team attendance & time (Manager: direct reports + project members)', 'roles' => ['manager', 'hr']],
    'attendance.manage' => ['label' => 'Edit attendance records', 'roles' => ['hr']],
    'attendance.timesheets.approve' => ['label' => 'Approve timesheets', 'roles' => ['manager', 'hr']],
    'attendance.screenshots.view' => ['label' => 'View monitoring screenshots of team', 'roles' => []],
  ],
  'nav' => [
    ['label' => 'Time Tracker', 'icon' => 'timer', 'url' => '/time', 'perm' => 'attendance.track', 'group' => 'Work', 'order' => 3],
    ['label' => 'Attendance', 'icon' => 'clock', 'url' => '/attendance', 'perm' => ['attendance.track', 'attendance.view_all'], 'group' => 'People', 'order' => 2],
    ['label' => 'Timesheets', 'icon' => 'calendar', 'url' => '/timesheets', 'perm' => ['attendance.track', 'attendance.timesheets.approve'], 'group' => 'Work', 'order' => 5],
  ],
  'global_scripts' => ['js/attendance-bg.js' => 'attendance.track'],
  'settings_view' => 'attendance::settings',
  'routes' => function (Core\Router $r): void {
    $t = 'attendance.track'; $ts = ['attendance.track', 'attendance.timesheets.approve'];
    $r->get('/attendance', [AttendanceController::class, 'index'], ['attendance.track', 'attendance.view_all']);
    $r->post('/attendance/clock-in', [AttendanceController::class, 'clockIn'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->post('/attendance/break/start', [AttendanceController::class, 'startBreak'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->post('/attendance/break/end', [AttendanceController::class, 'endBreak'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->post('/attendance/clock-out', [AttendanceController::class, 'clockOut'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->get('/attendance/team', [AttendanceController::class, 'team'], 'attendance.view_all');
    $r->get('/attendance/export', [AttendanceController::class, 'export'], ['perm' => 'attendance.view_all', 'throttle' => [10, 60]]);
    $r->post('/attendance/records', [AttendanceController::class, 'save'], 'attendance.manage');
    $r->get('/attendance/settings', [SettingsController::class, 'edit'], '__super_admin');
    $r->post('/attendance/settings', [SettingsController::class, 'save'], '__super_admin');
    $r->get('/time', [TimeController::class, 'index'], $t);
    $r->post('/time/start', [TimeController::class, 'start'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->post('/time/stop', [TimeController::class, 'stop'], ['perm' => $t, 'throttle' => [120, 60]]);
    $r->post('/time/heartbeat', [TimeController::class, 'heartbeat'], ['perm' => $t, 'throttle' => [600, 60]]);
    $r->post('/time/idle', [TimeController::class, 'idle'], ['perm' => $t, 'throttle' => [300, 60]]);
    $r->get('/time/tasks', [TimeController::class, 'tasks'], $t);
    $r->post('/time/entries', [TimeController::class, 'storeEntry'], $t);
    $r->post('/time/entries/{id}', [TimeController::class, 'updateEntry'], $t);
    $r->post('/time/entries/{id}/delete', [TimeController::class, 'deleteEntry'], $t);
    $r->post('/time/consent', [TimeController::class, 'consent'], $t);
    $r->post('/time/consent/revoke', [TimeController::class, 'revokeConsent'], $t);
    $r->post('/time/tokens', [TimeController::class, 'createToken'], ['perm' => $t, 'throttle' => [10, 300]]);
    $r->post('/time/tokens/{id}/revoke', [TimeController::class, 'revokeToken'], $t);
    $r->post('/time/screenshots', [ScreenshotController::class, 'upload'], ['perm' => $t, 'throttle' => [200, 60]]);
    $r->get('/time/screenshots', [ScreenshotController::class, 'index'], ['attendance.track', 'attendance.screenshots.view']);
    $r->get('/time/screenshots/{id}/image', [ScreenshotController::class, 'image'], ['attendance.track', 'attendance.screenshots.view']);
    $r->get('/timesheets', [TimesheetController::class, 'index'], $ts);
    $r->post('/timesheets/submit', [TimesheetController::class, 'submit'], $t);
    $r->get('/timesheets/approvals', [TimesheetController::class, 'approvals'], 'attendance.timesheets.approve');
    $r->get('/timesheets/{id}', [TimesheetController::class, 'show'], $ts);
    $r->post('/timesheets/{id}/approve', [TimesheetController::class, 'approve'], 'attendance.timesheets.approve');
    $r->post('/timesheets/{id}/reject', [TimesheetController::class, 'reject'], 'attendance.timesheets.approve');
    $api = ['auth' => false, 'csrf' => false, 'throttle' => [600, 60]];
    $r->get('/api/agent/status', [AgentController::class, 'status'], $api);
    $r->post('/api/agent/start', [AgentController::class, 'start'], $api);
    $r->post('/api/agent/stop', [AgentController::class, 'stop'], $api);
    $r->post('/api/agent/heartbeat', [AgentController::class, 'heartbeat'], $api);
    $r->post('/api/agent/screenshot', [AgentController::class, 'screenshot'], ['auth' => false, 'csrf' => false, 'throttle' => [200, 60]]);
  },
  'widgets' => [
    ['zone' => 'kpis', 'order' => 30, 'perm' => 'attendance.track', 'render' => [Widgets::class, 'kpi']],
    ['zone' => 'side', 'order' => 5, 'perm' => 'attendance.track', 'render' => [Widgets::class, 'side']],
    ['zone' => 'main', 'order' => 20, 'perm' => 'attendance.view_all', 'render' => [Widgets::class, 'team']],
  ],
  'cron' => [
    'auto_close' => ['daily' => '23:55', 'run' => [AttendanceService::class, 'autoClose']],
    'stale_timers' => ['every' => 5, 'run' => [TimeService::class, 'staleTimers']],
    'screenshot_cleanup' => ['daily' => '03:40', 'run' => [Monitoring::class, 'cleanup']],
  ],
  'file_access' => [],
];
