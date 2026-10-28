<?php
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
    ['label' => 'Payroll', 'icon' => 'wallet', 'url' => '/payroll', 'perm' => 'payroll.manage', 'group' => 'Finance', 'order' => 5],
    ['label' => 'My Payslips', 'icon' => 'file-text', 'url' => '/payslips', 'perm' => 'payroll.view_own', 'group' => 'People', 'order' => 5],
    ['label' => 'Reimbursements', 'icon' => 'receipt', 'url' => '/reimbursements', 'perm' => ['payroll.reimbursements.request', 'payroll.reimbursements.approve'], 'group' => 'People', 'order' => 6],
  ],
];
