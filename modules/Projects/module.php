<?php
use Modules\Projects\Controllers\{ProjectController, TaskController, MilestoneController, ChangeRequestController, WorkReportController};
use Modules\Projects\Services\{ProjectAccess, ProjectService, Widgets};

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
    ['label' => 'My Tasks', 'icon' => 'check-square', 'color' => '#12B5A0', 'url' => '/tasks', 'perm' => 'projects.view', 'hide_if' => 'todos.use', 'section' => 'main', 'order' => 2],
    ['label' => 'Projects', 'icon' => 'folder', 'color' => '#7C5CFF', 'url' => '/projects', 'perm' => 'projects.view', 'section' => 'work', 'order' => 1],
    ['label' => 'Work Reports', 'icon' => 'clipboard', 'color' => '#F0508A', 'url' => '/work-reports', 'perm' => ['projects.reports.submit', 'projects.reports.approve'], 'section' => 'work', 'order' => 4],
  ],
  'routes' => function (Core\Router $r): void {
    $view = ['projects.view', 'projects.view_all'];
    $edit = ['projects.tasks.manage', 'projects.tasks.update_own'];
    $wr = ['projects.reports.submit', 'projects.reports.approve'];
    $r->get('/projects', [ProjectController::class, 'index'], $view);
    $r->get('/projects/create', [ProjectController::class, 'create'], 'projects.manage');
    $r->post('/projects', [ProjectController::class, 'store'], 'projects.manage');
    $r->get('/projects/{id}', [ProjectController::class, 'show'], $view);
    $r->get('/projects/{id}/edit', [ProjectController::class, 'edit'], 'projects.manage');
    $r->post('/projects/{id}', [ProjectController::class, 'update'], 'projects.manage');
    $r->post('/projects/{id}/delete', [ProjectController::class, 'destroy'], 'projects.delete');
    $r->post('/projects/{id}/files', [ProjectController::class, 'upload'], $view);
    $r->post('/projects/{id}/tasks', [TaskController::class, 'store'], 'projects.tasks.manage');
    $r->post('/projects/{id}/milestones', [MilestoneController::class, 'store'], 'projects.manage');
    $r->post('/milestones/{id}', [MilestoneController::class, 'update'], 'projects.manage');
    $r->post('/milestones/{id}/complete', [MilestoneController::class, 'complete'], 'projects.manage');
    $r->post('/milestones/{id}/delete', [MilestoneController::class, 'destroy'], 'projects.manage');
    $r->post('/projects/{id}/change-requests', [ChangeRequestController::class, 'store'], 'projects.change_requests.manage');
    $r->post('/change-requests/{id}/status', [ChangeRequestController::class, 'decide'], 'projects.change_requests.manage');
    $r->post('/change-requests/{id}/delete', [ChangeRequestController::class, 'destroy'], 'projects.change_requests.manage');
    $r->get('/tasks', [TaskController::class, 'mine'], $view);
    $r->get('/tasks/{id}', [TaskController::class, 'show'], $view);
    $r->post('/tasks/{id}', [TaskController::class, 'update'], 'projects.tasks.manage');
    $r->post('/tasks/{id}/status', [TaskController::class, 'status'], $edit);
    $r->post('/tasks/{id}/move', [TaskController::class, 'move'], $edit);
    $r->post('/tasks/{id}/delete', [TaskController::class, 'destroy'], 'projects.tasks.manage');
    $r->post('/tasks/{id}/comments', [TaskController::class, 'comment'], $edit);
    $r->post('/tasks/{id}/comments/{comment_id}/delete', [TaskController::class, 'deleteComment'], $edit);
    $r->post('/tasks/{id}/subtasks', [TaskController::class, 'subtask'], $edit);
    $r->post('/tasks/{id}/dependencies', [TaskController::class, 'addDependency'], 'projects.tasks.manage');
    $r->post('/tasks/{id}/dependencies/{dep_id}/delete', [TaskController::class, 'removeDependency'], 'projects.tasks.manage');
    $r->post('/tasks/{id}/files', [TaskController::class, 'upload'], $edit);
    $r->get('/work-reports', [WorkReportController::class, 'index'], $wr);
    $r->get('/work-reports/create', [WorkReportController::class, 'create'], 'projects.reports.submit');
    $r->get('/work-reports/hours', [WorkReportController::class, 'hours'], 'projects.reports.submit');
    $r->post('/work-reports', [WorkReportController::class, 'store'], 'projects.reports.submit');
    $r->get('/work-reports/{id}', [WorkReportController::class, 'show'], $wr);
    $r->get('/work-reports/{id}/edit', [WorkReportController::class, 'edit'], 'projects.reports.submit');
    $r->post('/work-reports/{id}', [WorkReportController::class, 'update'], 'projects.reports.submit');
    $r->post('/work-reports/{id}/delete', [WorkReportController::class, 'destroy'], 'projects.reports.submit');
    $r->post('/work-reports/{id}/review', [WorkReportController::class, 'review'], 'projects.reports.approve');
  },
  'widgets' => [
    ['zone' => 'kpis', 'order' => 20, 'perm' => ['projects.view', 'projects.view_all'], 'feature' => 'projects', 'render' => [Widgets::class, 'openTasks']],
    ['zone' => 'kpis', 'order' => 21, 'perm' => ['projects.view', 'projects.view_all'], 'feature' => 'projects', 'render' => [Widgets::class, 'dueThisWeek']],
    ['zone' => 'kpis', 'order' => 22, 'perm' => ['projects.view', 'projects.view_all'], 'feature' => 'projects', 'render' => [Widgets::class, 'activeProjects']],
    ['zone' => 'main', 'order' => 20, 'perm' => ['projects.view', 'projects.view_all'], 'feature' => 'projects', 'render' => [Widgets::class, 'myTasks']],
    ['zone' => 'side', 'order' => 20, 'perm' => ['projects.view', 'projects.view_all'], 'feature' => 'projects', 'render' => [Widgets::class, 'projectProgress']],
  ],
  'search' => [ProjectService::class, 'search'],
  'cron' => [
    'deadline_reminders' => ['feature' => 'projects', 'daily' => '09:00', 'run' => [ProjectService::class, 'deadlineReminders']],
  ],
  'file_access' => [
    'project' => static fn (array $user, int $id, array $file, string $mode = 'view'): bool => ProjectAccess::canSee($user, $id) && ($mode !== 'delete' || ProjectAccess::canManageProject($user, $id) || (int) $file['uploaded_by'] === (int) $user['id']),
    'task' => static function (array $user, int $id, array $file, string $mode = 'view'): bool {
      $t = Core\DB::first('SELECT id, project_id, assignee_id FROM tasks WHERE id=?', [$id]);
      if (!$t || !ProjectAccess::canSee($user, (int) $t['project_id'])) { return false; }
      return $mode !== 'delete' || ProjectAccess::canEditTask($user, $t) && (ProjectAccess::canManageTasks($user, (int) $t['project_id']) || (int) $file['uploaded_by'] === (int) $user['id']); },
  ],
];
