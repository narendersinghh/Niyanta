<?php
use Modules\Todos\Controllers\TodoController;
use Modules\Todos\Services\Todos;

return [
  'key' => 'todos', 'name' => 'Tasks & Routines', 'icon' => 'check-square', 'order' => 19, 'version' => '1.0.0',
  'description' => 'Lightweight to-dos without a project: assign one-time tasks or daily, weekly and monthly routines to yourself or your team.',
  'permissions' => [
    'todos.use' => ['label' => 'Use tasks: see own tasks, create tasks for yourself, complete them', 'roles' => ['manager', 'hr', 'employee']],
    'todos.assign' => ['label' => 'Assign tasks & routines to direct reports', 'roles' => ['manager']],
    'todos.assign_all' => ['label' => 'Assign tasks & routines to anyone and see everyone\'s tasks', 'roles' => ['hr']],
  ],
  'nav' => [
    ['label' => 'My Tasks', 'icon' => 'check-square', 'color' => '#12B5A0', 'url' => '/todos', 'also' => ['/tasks'], 'perm' => 'todos.use', 'section' => 'main', 'order' => 2],
  ],
  'routes' => function (Core\Router $r): void {
    $any = ['todos.use', 'todos.assign', 'todos.assign_all'];
    $r->get('/todos', [TodoController::class, 'index'], $any);
    $r->post('/todos', [TodoController::class, 'store'], $any);
    $r->post('/todos/{id}', [TodoController::class, 'update'], $any);
    $r->post('/todos/{id}/complete', [TodoController::class, 'complete'], $any);
    $r->post('/todos/{id}/reopen', [TodoController::class, 'reopen'], $any);
    $r->post('/todos/{id}/delete', [TodoController::class, 'destroy'], $any);
    $r->post('/todos/routines/{id}', [TodoController::class, 'updateSeries'], $any);
    $r->post('/todos/routines/{id}/toggle', [TodoController::class, 'toggleSeries'], $any);
    $r->post('/todos/routines/{id}/delete', [TodoController::class, 'destroySeries'], $any); },
  'widgets' => [
    ['zone' => 'side', 'order' => 5, 'perm' => 'todos.use', 'feature' => 'todos', 'render' => [Todos::class, 'widget']],
  ],
  'search' => [Todos::class, 'search'],
  'cron' => [
    'generate' => ['feature' => 'todos', 'daily' => '00:05', 'run' => [Todos::class, 'generateDue']],
  ],
];
