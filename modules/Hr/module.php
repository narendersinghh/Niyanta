<?php
return [
  'key' => 'hr', 'name' => 'HR Management', 'icon' => 'users', 'order' => 30, 'version' => '1.0.0',
  'description' => 'Employee profiles, onboarding/offboarding, secure documents, leave balances & approvals and holidays.',
  'permissions' => [
    'hr.directory.view' => ['label' => 'View team directory', 'roles' => ['manager', 'hr', 'employee']],
    'hr.employees.view' => ['label' => 'View employee HR records', 'roles' => ['hr']],
    'hr.employees.manage' => ['label' => 'Edit employee records & checklists', 'roles' => ['hr']],
    'hr.documents.manage' => ['label' => 'Upload & view ID proofs and agreements', 'roles' => ['hr']],
    'hr.leave.request' => ['label' => 'Request leave', 'roles' => ['manager', 'hr', 'employee']],
    'hr.leave.approve' => ['label' => 'Approve leave (HR: all, Manager: direct reports)', 'roles' => ['manager', 'hr']],
    'hr.leave.manage' => ['label' => 'Manage leave types, balances & holidays', 'roles' => ['hr']],
  ],
  'nav' => [
    ['label' => 'Employees', 'icon' => 'id-card', 'url' => '/hr/employees', 'perm' => ['hr.employees.view', 'hr.directory.view'], 'group' => 'People', 'order' => 1],
    ['label' => 'Leave', 'icon' => 'plane', 'url' => '/hr/leave', 'perm' => ['hr.leave.request', 'hr.leave.approve'], 'group' => 'People', 'order' => 3],
    ['label' => 'Holidays', 'icon' => 'calendar', 'url' => '/hr/holidays', 'perm' => ['hr.directory.view', 'hr.leave.manage'], 'group' => 'People', 'order' => 4],
  ],
];
