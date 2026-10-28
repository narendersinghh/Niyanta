<?php
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
];
