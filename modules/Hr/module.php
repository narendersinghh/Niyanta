<?php
use Core\{DB, Gate, View};
use Modules\Hr\Controllers\{EmployeeController, ChecklistController, CompanyDocController, LeaveController, HolidayController};
use Modules\Hr\Services\{HrAccess, LeaveService};
use Modules\Hr\Controllers\{ProcessController, ProcessTemplateController};
use Modules\Hr\Services\ProcessService;

return [
  'key' => 'hr', 'name' => 'HR Management', 'icon' => 'users', 'order' => 30, 'version' => '1.0.0',
  'description' => 'Employee profiles & work schedules, onboarding/offboarding, secure documents, leave balances & approvals, holidays and company documents.',
  'permissions' => [
    'hr.directory.view' => ['label' => 'View team directory', 'roles' => ['manager', 'hr', 'employee']],
    'hr.employees.view' => ['label' => 'View employee HR records', 'roles' => ['hr']],
    'hr.employees.manage' => ['label' => 'Edit employee records & checklists', 'roles' => ['hr']],
    'hr.documents.manage' => ['label' => 'Upload & view ID proofs and agreements', 'roles' => ['hr']],
    'hr.leave.request' => ['label' => 'Request leave', 'roles' => ['manager', 'hr', 'employee']],
    'hr.leave.approve' => ['label' => 'Approve leave (HR: all, Manager: direct reports)', 'roles' => ['manager', 'hr']],
    'hr.company_docs.view' => ['label' => 'View company documents', 'roles' => ['manager', 'hr', 'employee']],
    'hr.company_docs.manage' => ['label' => 'Publish & manage company documents', 'roles' => ['hr']],
    'hr.leave.manage' => ['label' => 'Manage leave types, balances & holidays', 'roles' => ['hr']],
    'hr.employees.review_team' => ['label' => 'Review onboarding & exit of direct reports (files, manager tasks, deletion requests)', 'roles' => ['manager']],
  ],
  'nav' => [
    ['label' => 'Leave', 'icon' => 'umbrella', 'color' => '#12B5A0', 'url' => '/hr/leave', 'also' => ['/hr/holidays'], 'perm' => 'hr.leave.request', 'section' => 'me', 'order' => 3],
    ['label' => 'Holidays', 'icon' => 'calendar', 'color' => '#FF6F59', 'url' => '/hr/holidays', 'perm' => ['hr.leave.request', 'hr.leave.manage'], 'hide_if' => 'hr.leave.request', 'section' => 'me', 'order' => 4],
    ['label' => 'Company Docs', 'icon' => 'book', 'color' => '#2E9BF6', 'url' => '/hr/documents', 'perm' => 'hr.company_docs.view', 'section' => 'me', 'order' => 7],
    ['label' => 'Team Directory', 'icon' => 'users', 'color' => '#F5A524', 'url' => '/hr/employees', 'perm' => 'hr.directory.view', 'hide_if' => 'hr.employees.view', 'section' => 'me', 'order' => 8],
    ['label' => 'Employees', 'icon' => 'id-card', 'color' => '#12B5A0', 'url' => '/hr/employees', 'also' => ['/hr/processes', '/hr/process-templates'], 'perm' => 'hr.employees.view', 'section' => 'hr', 'order' => 1],
    /* managers who only review their team's onboarding & exit (no employee directory admin) keep a direct link */
    ['label' => 'Onboarding & exit', 'icon' => 'clipboard', 'color' => '#5B4CF5', 'url' => '/hr/processes', 'also' => ['/hr/process-templates'], 'perm' => ['hr.employees.manage', 'hr.employees.review_team'], 'hide_if' => 'hr.employees.view', 'section' => 'hr', 'order' => 2],
    ['label' => 'Leave Approvals', 'icon' => 'check-circle', 'color' => '#F0508A', 'url' => '/hr/leave/approvals', 'perm' => 'hr.leave.approve', 'hide_if' => 'hr.leave.request', 'section' => 'hr', 'order' => 3],
  ],
  'routes' => function (Core\Router $r): void {
    $self = ['hr.employees.view', 'hr.directory.view', 'hr.leave.request'];
    $leave = ['hr.leave.request', 'hr.leave.approve'];
    $r->get('/hr/employees', [EmployeeController::class, 'index'], ['hr.employees.view', 'hr.directory.view']);
    $r->get('/hr/me', [EmployeeController::class, 'me'], ['perm' => $self, 'feature' => 'employees']);
    $r->get('/hr/employees/{id}', [EmployeeController::class, 'show'], ['perm' => $self, 'feature' => 'employees']);
    $r->get('/hr/employees/{id}/edit', [EmployeeController::class, 'edit'], 'hr.employees.manage');
    $r->post('/hr/employees/{id}', [EmployeeController::class, 'update'], 'hr.employees.manage');
    $r->post('/hr/employees/{id}/self', [EmployeeController::class, 'updateSelf'], ['perm' => $self, 'feature' => 'employees']);
    $r->post('/hr/employees/{id}/schedule', [EmployeeController::class, 'updateSchedule'], 'hr.employees.manage');
    $r->post('/hr/employees/{id}/reveal', [EmployeeController::class, 'reveal'], ['perm' => 'hr.employees.manage', 'throttle' => [30, 300]]);
    $r->post('/hr/employees/{id}/documents', [EmployeeController::class, 'upload'], ['perm' => 'hr.documents.manage', 'throttle' => [40, 300]]);
    $r->post('/hr/employees/{id}/checklists/{type}', [EmployeeController::class, 'generateChecklist'], 'hr.employees.manage');
    $r->post('/hr/employees/{id}/checklists/{type}/delete', [EmployeeController::class, 'deleteChecklist'], 'hr.employees.manage');
    $r->post('/hr/checklist-items/{id}/toggle', [EmployeeController::class, 'toggleItem'], 'hr.employees.manage');
    $r->post('/hr/employees/{id}/offboard', [EmployeeController::class, 'offboard'], 'hr.employees.manage');
    $r->post('/hr/employees/{id}/deactivate', [EmployeeController::class, 'deactivate'], 'hr.employees.manage');
    $r->get('/hr/checklists', [ChecklistController::class, 'index'], 'hr.employees.manage');
    $r->post('/hr/checklists', [ChecklistController::class, 'store'], 'hr.employees.manage');
    $r->post('/hr/checklists/{id}/move', [ChecklistController::class, 'move'], 'hr.employees.manage');
    $r->post('/hr/checklists/{id}/delete', [ChecklistController::class, 'destroy'], 'hr.employees.manage');
    $r->get('/hr/leave', [LeaveController::class, 'index'], $leave);
    $r->get('/hr/leave/preview', [LeaveController::class, 'preview'], 'hr.leave.request');
    $r->post('/hr/leave', [LeaveController::class, 'store'], ['perm' => 'hr.leave.request', 'throttle' => [20, 300]]);
    $r->post('/hr/leave/{id}/cancel', [LeaveController::class, 'cancel'], 'hr.leave.request');
    $r->get('/hr/leave/approvals', [LeaveController::class, 'approvals'], 'hr.leave.approve');
    $r->post('/hr/leave/{id}/review', [LeaveController::class, 'review'], 'hr.leave.approve');
    $r->get('/hr/leave/calendar', [LeaveController::class, 'calendar'], $leave);
    $r->get('/hr/leave/balances', [LeaveController::class, 'balances'], 'hr.leave.manage');
    $r->post('/hr/leave/balances/allocate', [LeaveController::class, 'allocate'], 'hr.leave.manage');
    $r->post('/hr/leave/balances/adjust', [LeaveController::class, 'adjust'], 'hr.leave.manage');
    $r->get('/hr/leave/types', [LeaveController::class, 'types'], 'hr.leave.manage');
    $r->post('/hr/leave/optional-quota', [LeaveController::class, 'optionalQuota'], 'hr.leave.manage');
    $r->post('/hr/leave/types', [LeaveController::class, 'storeType'], 'hr.leave.manage');
    $r->post('/hr/leave/types/{id}', [LeaveController::class, 'updateType'], 'hr.leave.manage');
    $r->post('/hr/leave/types/{id}/delete', [LeaveController::class, 'destroyType'], 'hr.leave.manage');
    $r->get('/hr/leave/ledger', [LeaveController::class, 'ledger'], ['hr.leave.request', 'hr.leave.manage']);
    $r->post('/hr/leave/balances/accrue', [LeaveController::class, 'accrue'], 'hr.leave.manage');
    $r->post('/hr/leave/balances/carry', [LeaveController::class, 'carry'], 'hr.leave.manage');
    $r->get('/hr/holidays', [HolidayController::class, 'index'], ['perm' => ['hr.directory.view', 'hr.leave.manage', 'hr.leave.request'], 'feature' => 'leave']);
    $r->post('/hr/holidays', [HolidayController::class, 'store'], 'hr.leave.manage');
    $r->post('/hr/holidays/{id}', [HolidayController::class, 'update'], 'hr.leave.manage');
    $r->post('/hr/holidays/{id}/delete', [HolidayController::class, 'destroy'], 'hr.leave.manage');
    $r->post('/hr/holidays/next-year', [HolidayController::class, 'nextYear'], 'hr.leave.manage');
    $r->post('/hr/holidays/ai-fix', [HolidayController::class, 'aiFix'], ['perm' => 'hr.leave.manage', 'throttle' => [10, 300]]);
    $r->post('/hr/holidays/ai-apply', [HolidayController::class, 'aiApply'], 'hr.leave.manage');
    $r->get('/hr/documents', [CompanyDocController::class, 'index'], 'hr.company_docs.view');
    $r->post('/hr/documents', [CompanyDocController::class, 'store'], ['perm' => 'hr.company_docs.manage', 'throttle' => [30, 300]]);
    $r->post('/hr/documents/{id}', [CompanyDocController::class, 'update'], ['perm' => 'hr.company_docs.manage', 'throttle' => [30, 300]]);
    $r->post('/hr/documents/{id}/delete', [CompanyDocController::class, 'destroy'], 'hr.company_docs.manage');
    $proc = ['perm' => ['hr.directory.view', 'hr.employees.view', 'hr.employees.manage', 'hr.employees.review_team'], 'feature' => 'employees'];
    $files = ['hr.directory.view', 'hr.employees.view', 'hr.employees.manage', 'hr.employees.review_team', 'hr.company_docs.view', 'hr.company_docs.manage'];
    $mod = ['hr.employees.manage', 'hr.employees.review_team', 'hr.company_docs.manage'];
    $r->get('/hr/processes', [ProcessController::class, 'index'], ['hr.employees.manage', 'hr.employees.review_team', 'hr.employees.view']);
    $r->get('/hr/processes/{id}', [ProcessController::class, 'show'], $proc);
    $r->post('/hr/employees/{id}/process/{kind}', [ProcessController::class, 'start'], 'hr.employees.manage');
    $r->post('/hr/processes/{id}/status', [ProcessController::class, 'transition'], 'hr.employees.manage');
    $r->post('/hr/processes/{id}/remove', [ProcessController::class, 'remove'], 'hr.employees.manage');
    $r->post('/hr/processes/{id}/exit', [ProcessController::class, 'updateExit'], 'hr.employees.manage');
    $r->post('/hr/processes/{id}/tasks', [ProcessController::class, 'addTask'], 'hr.employees.manage');
    $r->post('/hr/process-tasks/{id}', [ProcessController::class, 'updateTask'], 'hr.employees.manage');
    $r->post('/hr/process-tasks/{id}/delete', [ProcessController::class, 'destroyTask'], 'hr.employees.manage');
    $r->post('/hr/process-tasks/{id}/upload', [ProcessController::class, 'upload'], $proc + ['throttle' => [30, 300]]);
    $r->post('/hr/process-tasks/{id}/ack', [ProcessController::class, 'acknowledge'], $proc);
    $r->post('/hr/process-tasks/{id}/toggle', [ProcessController::class, 'toggle'], $proc);
    $r->post('/hr/process-tasks/{id}/skip', [ProcessController::class, 'skip'], 'hr.employees.manage');
    $r->post('/hr/uploads/{id}/review', [ProcessController::class, 'review'], $mod);
    $r->post('/hr/uploads/{id}/delete', [ProcessController::class, 'deleteUpload'], $files);
    $r->post('/hr/uploads/{id}/delete-request', [ProcessController::class, 'requestDeletion'], ['perm' => $files, 'throttle' => [20, 300]]);
    $r->post('/hr/delete-requests/{id}/decide', [ProcessController::class, 'decide'], $mod);
    $r->post('/hr/documents/{id}/sign', [CompanyDocController::class, 'sign'], ['perm' => 'hr.company_docs.view', 'throttle' => [20, 300]]);
    $r->get('/hr/process-templates', [ProcessTemplateController::class, 'index'], 'hr.employees.manage');
    $r->post('/hr/process-templates', [ProcessTemplateController::class, 'store'], 'hr.employees.manage');
    $r->post('/hr/process-templates/{id}', [ProcessTemplateController::class, 'update'], 'hr.employees.manage');
    $r->post('/hr/process-templates/{id}/delete', [ProcessTemplateController::class, 'destroy'], 'hr.employees.manage');
    $r->post('/hr/process-templates/{id}/tasks', [ProcessTemplateController::class, 'storeTask'], 'hr.employees.manage');
    $r->post('/hr/template-tasks/{id}', [ProcessTemplateController::class, 'updateTask'], 'hr.employees.manage');
    $r->post('/hr/template-tasks/{id}/delete', [ProcessTemplateController::class, 'destroyTask'], 'hr.employees.manage');
    $r->post('/hr/template-tasks/{id}/move', [ProcessTemplateController::class, 'moveTask'], 'hr.employees.manage');
    $r->post('/hr/documents/{id}/ack', [CompanyDocController::class, 'acknowledge'], 'hr.company_docs.view'); },
  'widgets' => [
    ['zone' => 'main', 'order' => 5, 'perm' => ['hr.directory.view', 'hr.employees.view', 'hr.leave.request'], 'feature' => 'employees', 'render' => static function (array $u): string {
      $procs = ProcessService::openFor((int) $u['id']);
      return $procs ? View::partial('hr::widgets/my_process', ['procs' => $procs]) : ''; }],
    ['zone' => 'side', 'order' => 12, 'perm' => ['hr.employees.manage', 'hr.employees.review_team'], 'feature' => 'employees', 'render' => static function (array $u): string {
      $c = ProcessService::reviewCounts();
      return array_sum($c) ? View::partial('hr::widgets/process_review', ['c' => $c]) : ''; }],
    ['zone' => 'kpis', 'order' => 30, 'perm' => 'hr.leave.request', 'feature' => 'leave', 'render' => static function (array $u): string {
      $b = array_filter(LeaveService::balances((int) $u['id'], (int) date('Y')), static fn ($r) => (int) $r['paid'] === 1);
      $avail = array_sum(array_map(static fn ($r) => max(0, (float) $r['available']), $b));
      $alloc = array_sum(array_column($b, 'allocated'));
      return View::partial('hr::widgets/balance_tile', ['avail' => $avail, 'alloc' => (float) $alloc]); }],
    ['zone' => 'main', 'order' => 25, 'perm' => 'hr.leave.approve', 'feature' => 'leave', 'render' => static function (array $u): string {
      [$w, $p] = HrAccess::reviewScope();
      $sql = "SELECT lr.id, lr.start_date, lr.end_date, lr.days, lr.half_day, u.name, u.avatar, t.name AS type_name, t.color FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.status='pending' AND $w";
      $count = (int) DB::value("SELECT COUNT(*) FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.status='pending' AND $w", $p);
      return View::partial('hr::widgets/approvals', ['count' => $count, 'rows' => $count ? DB::all($sql.' ORDER BY lr.start_date LIMIT 5', $p) : []]); }],
    ['zone' => 'side', 'order' => 20, 'perm' => ['hr.directory.view', 'hr.leave.request'], 'feature' => 'leave', 'render' => static function (array $u): string {
      $today = date('Y-m-d');
      return View::partial('hr::widgets/out_today', ['out' => LeaveService::onLeaveOn($today, 6), 'holidays' => DB::all('SELECT holiday_date, name, optional FROM holidays WHERE holiday_date>=? ORDER BY holiday_date LIMIT 4', [$today])]); }],
    ['zone' => 'side', 'order' => 15, 'perm' => 'hr.company_docs.view', 'feature' => 'company_docs', 'render' => static function (array $u): string {
      $docs = CompanyDocController::pendingFor((int) $u['id']);
      return $docs ? View::partial('hr::widgets/docs_ack', ['docs' => $docs, 'cats' => CompanyDocController::CATS]) : ''; }],
  ],
  'search' => static function (string $q, array $u): array {
    $full = Gate::allows('hr.employees.view', $u);
    if (!$full && !Gate::allows('hr.directory.view', $u)) { return []; }
    $like = DB::like($q);
    $rows = DB::all('SELECT id, name, email, designation, department, employee_code, status FROM users WHERE (name LIKE ? OR email LIKE ? OR employee_code LIKE ?)'.($full ? '' : " AND status='active'").' ORDER BY status=\'active\' DESC, name LIMIT 8', [$like, $like, $like]);
    $out = [];
    foreach ($rows as $r) {
      $own = (int) $r['id'] === (int) $u['id'];
      $meta = implode(' · ', array_filter($full ? [$r['employee_code'], $r['designation'], $r['department'], $r['status'] !== 'active' ? ucfirst($r['status']) : null] : [$r['designation'], $r['department'], $r['email']]));
      $out[] = ['title' => $r['name'], 'meta' => $meta, 'icon' => 'id-card', 'url' => $full || $own ? '/hr/employees/'.$r['id'] : '/hr/employees?q='.rawurlencode($r['name'])]; }
    return $out ? ['Employees' => $out] : []; },
  'cron' => [
    'hr_process_reminders' => ['feature' => 'employees', 'daily' => '09:10', 'run' => [ProcessService::class, 'reminders']],
    'hr_exit_deactivate' => ['feature' => 'employees', 'daily' => '23:50', 'run' => [ProcessService::class, 'autoDeactivate']],
    'leave_year_rollover' => ['feature' => 'leave', 'monthly' => '1 00:20', 'run' => [LeaveService::class, 'yearRollover']],
    'leave_accrual' => ['feature' => 'leave', 'daily' => '00:35', 'run' => [LeaveService::class, 'accrualCron']],
    'holidays_next_year' => ['feature' => 'leave', 'daily' => '01:15', 'run' => [Modules\Hr\Services\HolidayService::class, 'cronNextYear']],
  ],
  'file_access' => [
    'employee' => static fn (array $user, int $entityId, array $file, string $mode = 'view'): bool => $mode === 'delete' ? Gate::allows('hr.documents.manage', $user) : HrAccess::canViewDocuments($entityId, $user),
    'company_doc' => static fn (array $user, int $entityId, array $file, string $mode = 'view'): bool => $mode === 'delete' ? Gate::allows('hr.company_docs.manage', $user) : Gate::allows('hr.company_docs.view', $user),
    'hr_upload' => static fn (array $user, int $entityId, array $file, string $mode = 'view'): bool => $mode !== 'delete' && ProcessService::canViewUpload($entityId, $user),
  ],
];
