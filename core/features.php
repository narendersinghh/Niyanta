<?php
/**
 * Product structure: 3 bundles, each with switchable features. A feature owns permission
 * prefixes; disabling a feature (or its whole bundle) denies those permissions for everyone,
 * hides navigation/widgets and blocks the routes — data is never deleted.
 */
return [
  'people' => ['name' => 'HR & Employee Management', 'icon' => 'users', 'color' => '#12B5A0', 'description' => 'Employees, attendance & time, leave & holidays, payroll, reimbursements and company documents.', 'features' => [
    'employees' => ['name' => 'Employee directory & records', 'module' => 'hr', 'icon' => 'id-card', 'perms' => ['hr.directory', 'hr.employees', 'hr.documents'], 'description' => 'Profiles, secure ID proofs & agreements, onboarding/offboarding.'],
    'attendance' => ['name' => 'Attendance & time tracking', 'module' => 'attendance', 'icon' => 'timer', 'perms' => ['attendance'], 'description' => 'Clock in/out, time tracker, timesheets and activity monitoring.'],
    'leave' => ['name' => 'Leave & holidays', 'module' => 'hr', 'icon' => 'umbrella', 'perms' => ['hr.leave'], 'description' => 'Fixed & optional holidays, leave requests, balances and approvals.'],
    'payroll' => ['name' => 'Salary & payroll', 'module' => 'payroll', 'icon' => 'wallet', 'perms' => ['payroll.view_own', 'payroll.manage', 'payroll.compensation'], 'description' => 'Compensation, payroll runs and payslips.'],
    'reimbursements' => ['name' => 'Reimbursements', 'module' => 'payroll', 'icon' => 'receipt', 'perms' => ['payroll.reimbursements'], 'description' => 'Expense claims with receipts and approvals.'],
    'company_docs' => ['name' => 'Company documents', 'module' => 'hr', 'icon' => 'book', 'perms' => ['hr.company_docs'], 'description' => 'Policies, handbooks and forms shared with the team.']]],
  'work' => ['name' => 'Team & Work Management', 'icon' => 'folder', 'color' => '#5B4CF5', 'description' => 'Projects, tasks, Kanban, milestones, change requests and work reports.', 'features' => [
    'projects' => ['name' => 'Projects & tasks', 'module' => 'projects', 'icon' => 'kanban', 'perms' => ['projects.view', 'projects.view_all', 'projects.manage', 'projects.delete', 'projects.tasks', 'projects.change_requests'], 'description' => 'Projects, milestones, Kanban, tasks, dependencies and change requests.'],
    'work_reports' => ['name' => 'Work reports', 'module' => 'projects', 'icon' => 'clipboard', 'perms' => ['projects.reports'], 'description' => 'Daily and weekly reports with manager approval.']]],
  'clients' => ['name' => 'Client Management', 'icon' => 'briefcase', 'color' => '#FF6F59', 'description' => 'Clients and their journey, quotations, invoices, payments and financial reports.', 'features' => [
    'clients' => ['name' => 'Clients & journey', 'module' => 'crm', 'icon' => 'briefcase', 'perms' => ['crm.clients', 'crm.documents'], 'description' => 'Client profiles, contacts, timeline and documents.'],
    'quotations' => ['name' => 'Quotations', 'module' => 'crm', 'icon' => 'file-text', 'perms' => ['crm.quotations'], 'description' => 'Estimates that convert to invoices.'],
    'invoices' => ['name' => 'Invoices & payments', 'module' => 'crm', 'icon' => 'receipt', 'perms' => ['crm.finance'], 'description' => 'Invoices, partial payments, PDFs and reminders.'],
    'finance_reports' => ['name' => 'Financial reports', 'module' => 'crm', 'icon' => 'chart', 'perms' => ['crm.reports'], 'description' => 'Collections, aging and project financials.']]],
];
