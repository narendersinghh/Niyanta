<?php
return [
  'key' => 'projects', 'name' => 'Project & Work Management', 'icon' => 'folder', 'order' => 20, 'version' => '1.0.0',
  'description' => 'Projects, milestones, tasks & subtasks, Kanban, dependencies, comments, attachments, change requests and work reports.',
  'permissions' => [
    'projects.view' => ['label' => 'View projects they are a member of', 'roles' => ['manager', 'employee']],
    'projects.view_all' => ['label' => 'View all projects', 'roles' => []],
    'projects.manage' => ['label' => 'Create & edit projects, members, milestones', 'roles' => ['manager']],
    'projects.delete' => ['label' => 'Delete projects', 'roles' => []],
    'projects.tasks.manage' => ['label' => 'Create, assign & edit any task in visible projects', 'roles' => ['manager']],
    'projects.tasks.update_own' => ['label' => 'Update status, comment & attach on own tasks', 'roles' => ['employee', 'manager']],
    'projects.change_requests.manage' => ['label' => 'Log & decide change requests', 'roles' => ['manager']],
    'projects.reports.submit' => ['label' => 'Submit daily/weekly work reports', 'roles' => ['manager', 'hr', 'employee']],
    'projects.reports.approve' => ['label' => 'Review & approve work reports', 'roles' => ['manager']],
  ],
  'nav' => [
    ['label' => 'Projects', 'icon' => 'folder', 'url' => '/projects', 'perm' => 'projects.view', 'group' => 'Work', 'order' => 1],
    ['label' => 'My Tasks', 'icon' => 'check-square', 'url' => '/tasks', 'perm' => ['projects.view'], 'group' => 'Work', 'order' => 2],
    ['label' => 'Work Reports', 'icon' => 'clipboard', 'url' => '/work-reports', 'perm' => ['projects.reports.submit', 'projects.reports.approve'], 'group' => 'Work', 'order' => 4],
  ],
];
