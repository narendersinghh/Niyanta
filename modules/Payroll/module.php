<?php
use Core\{DB, Gate};
use Modules\Payroll\Controllers\{CompensationController, PayslipController, ReimbursementController, RunController};
use Modules\Payroll\Services\PayrollService;

return [
  'key' => 'payroll', 'name' => 'Payroll & Payments', 'icon' => 'wallet', 'order' => 50, 'version' => '1.0.0', 'requires' => ['hr'],
  'description' => 'Compensation, allowances, deductions, reimbursements, monthly payroll runs and downloadable payslips.',
  'permissions' => [
    'payroll.view_own' => ['label' => 'View own payslips', 'roles' => ['manager', 'hr', 'employee']],
    'payroll.manage' => ['label' => 'Run payroll & view all payslips', 'roles' => ['hr']],
    'payroll.compensation.manage' => ['label' => 'Manage salaries, allowances & deductions', 'roles' => ['hr']],
    'payroll.reimbursements.request' => ['label' => 'Submit reimbursement claims', 'roles' => ['manager', 'hr', 'employee']],
    'payroll.reimbursements.approve' => ['label' => 'Approve reimbursement claims', 'roles' => ['hr']],
  ],
  'nav' => [
    ['label' => 'My Pay', 'icon' => 'file-text', 'color' => '#7C5CFF', 'url' => '/payslips', 'also' => ['/reimbursements'], 'perm' => 'payroll.view_own', 'section' => 'me', 'order' => 5],
    ['label' => 'Reimbursements', 'icon' => 'receipt', 'color' => '#F0508A', 'url' => '/reimbursements', 'perm' => ['payroll.reimbursements.request', 'payroll.reimbursements.approve'], 'hide_if' => 'payroll.view_own', 'section' => 'me', 'order' => 6],
    ['label' => 'Payroll', 'icon' => 'wallet', 'color' => '#7C5CFF', 'url' => '/payroll', 'perm' => 'payroll.manage', 'section' => 'hr', 'order' => 4],
  ],
  'routes' => function (Core\Router $r): void {
    $m = 'payroll.manage'; $c = 'payroll.compensation.manage';
    $r->get('/payroll', [RunController::class, 'index'], ['perm' => [$m, $c]]);
    $r->post('/payroll/runs', [RunController::class, 'store'], $m);
    $r->get('/payroll/runs/{id}', [RunController::class, 'show'], $m);
    $r->post('/payroll/runs/{id}/generate', [RunController::class, 'generate'], $m);
    $r->post('/payroll/runs/{id}/finalize', [RunController::class, 'finalize'], $m);
    $r->post('/payroll/runs/{id}/paid', [RunController::class, 'paid'], $m);
    $r->post('/payroll/runs/{id}/delete', [RunController::class, 'destroy'], $m);
    $r->get('/payroll/runs/{id}/export', [RunController::class, 'export'], ['perm' => $m, 'throttle' => [20, 300]]);
    $r->get('/payroll/employees', [CompensationController::class, 'index'], $c);
    $r->get('/payroll/employees/{id}', [CompensationController::class, 'show'], $c);
    $r->post('/payroll/employees/{id}/compensation', [CompensationController::class, 'storeCompensation'], $c);
    $r->post('/payroll/compensations/{id}/delete', [CompensationController::class, 'destroyCompensation'], $c);
    $r->get('/payroll/components', [CompensationController::class, 'components'], $c);
    $r->post('/payroll/components', [CompensationController::class, 'storeComponent'], $c);
    $r->post('/payroll/components/{id}', [CompensationController::class, 'updateComponent'], $c);
    $r->post('/payroll/components/{id}/delete', [CompensationController::class, 'destroyComponent'], $c);
    $r->get('/payslips', [PayslipController::class, 'index'], ['perm' => ['payroll.view_own', $m]]);
    $r->get('/payslips/{id}', [PayslipController::class, 'show'], ['perm' => ['payroll.view_own', $m]]);
    $r->get('/payslips/{id}/pdf', [PayslipController::class, 'pdf'], ['perm' => ['payroll.view_own', $m]]);
    $r->get('/reimbursements', [ReimbursementController::class, 'index'], ['perm' => ['payroll.reimbursements.request', 'payroll.reimbursements.approve']]);
    $r->post('/reimbursements', [ReimbursementController::class, 'store'], ['perm' => 'payroll.reimbursements.request', 'throttle' => [20, 300]]);
    $r->post('/reimbursements/{id}/approve', [ReimbursementController::class, 'approve'], 'payroll.reimbursements.approve');
    $r->post('/reimbursements/{id}/reject', [ReimbursementController::class, 'reject'], 'payroll.reimbursements.approve');
    $r->post('/reimbursements/{id}/delete', [ReimbursementController::class, 'destroy'], 'payroll.reimbursements.request');
  },
  'widgets' => [
    ['zone' => 'kpis', 'order' => 40, 'perm' => 'payroll.view_own', 'feature' => 'payroll', 'render' => static function (array $u): string {
      $s = DB::first("SELECT s.id, s.net, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.user_id=? AND r.status IN ('finalized','paid') ORDER BY r.period_month DESC LIMIT 1", [$u['id']]);
      return '<a class="tile bg-peach" href="'.e(url($s ? '/payslips/'.$s['id'] : '/payslips')).'"><span class="tile-ic">'.icon('wallet').'</span><div><div class="num">'.e($s ? money($s['net']) : '-').'</div><div class="lbl">Last net pay'.($s ? ' · '.e(date('M Y', strtotime($s['period_month']))) : '').'</div></div>'.'</a>'; }],
    ['zone' => 'side', 'order' => 40, 'perm' => ['payroll.manage', 'payroll.reimbursements.approve'], 'feature' => 'reimbursements', 'render' => static function (array $u): string {
      $rows = DB::all("SELECT r.id, r.title, r.amount, r.created_at, u.name FROM reimbursements r JOIN users u ON u.id=r.user_id WHERE r.status='pending' ORDER BY r.created_at LIMIT 5");
      $n = $rows ? (int) DB::value("SELECT COUNT(*) FROM reimbursements WHERE status='pending'") : 0;
      $link = Gate::allows('payroll.reimbursements.approve', $u) ? url('/reimbursements?tab=queue') : url('/payroll');
      $h = '<div class="card"><div class="card-head"><h3>Pending reimbursements</h3>'.($n ? '<span class="pill pill-peach">'.$n.'</span>' : '').'</div>';
      if (!$rows) { return $h.'<div class="list-item"><span class="avatar bg-mint">'.icon('check', 'sm').'</span><div class="grow"><b class="small">All caught up</b><div class="meta">No claims waiting for review.</div></div></div></div>'; }
      $h .= '<div class="list">';
      foreach ($rows as $r) { $h .= '<a class="list-item" href="'.e($link).'">'.avatar($r['name'], null, 'sm').'<div class="grow"><b class="small truncate">'.e($r['title']).'</b><div class="meta">'.e($r['name']).' · '.e(ago($r['created_at'])).'</div></div><b class="small nowrap">'.e(money($r['amount'])).'</b></a>'; }
      return $h.'</div><a class="btn ghost sm mt" href="'.e($link).'">Review claims '.icon('arrow-right', 'sm').'</a></div>'; }],
  ],
  'search' => static function (string $q, array $u): array {
    if (!Gate::allows('payroll.view_own', $u)) { return []; }
    $rows = DB::all("SELECT s.id, s.net, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.user_id=? AND r.status IN ('finalized','paid') AND (DATE_FORMAT(r.period_month, '%M %Y') LIKE ? OR DATE_FORMAT(r.period_month, '%Y-%m') LIKE ? OR ? LIKE '%payslip%') ORDER BY r.period_month DESC LIMIT 8", [$u['id'], DB::like($q), DB::like($q), strtolower($q)]);
    return ['Payslips' => array_map(static fn ($r) => ['title' => 'Payslip · '.date('F Y', strtotime($r['period_month'])), 'meta' => 'Net '.money($r['net']), 'url' => '/payslips/'.$r['id'], 'icon' => 'file-text'], $rows)]; },
  'cron' => [
    'payroll_reminder' => ['feature' => 'payroll', 'monthly' => '25 10:00', 'run' => [PayrollService::class, 'reminder']],
  ],
  'file_access' => [
    'reimbursement' => static function (array $user, int $id, array $file, string $mode): bool {
      $c = DB::first('SELECT user_id, status FROM reimbursements WHERE id=?', [$id]);
      if (!$c) { return false; }
      $own = (int) $c['user_id'] === (int) $user['id'];
      if ($mode === 'delete') { return $own && $c['status'] === 'pending'; }
      return $own || Gate::allows('payroll.reimbursements.approve', $user) || Gate::allows('payroll.manage', $user); },
  ],
];
