<?php
use Modules\Crm\Controllers\{ClientController, JourneyController, QuotationController, InvoiceController, ReportController};
use Modules\Crm\Services\{ClientAccess, Hooks};

return [
  'key' => 'crm', 'name' => 'Client Management', 'icon' => 'briefcase', 'order' => 10, 'version' => '1.0.0',
  'description' => 'Client profiles, contacts, client journey, documents, quotations, invoices, payments and financial reports.',
  'permissions' => [
    'crm.clients.view' => ['label' => 'View assigned clients', 'roles' => ['manager']],
    'crm.clients.view_all' => ['label' => 'View all clients (not only assigned)', 'roles' => []],
    'crm.clients.manage' => ['label' => 'Create & edit clients, contacts, journey', 'roles' => ['manager']],
    'crm.clients.delete' => ['label' => 'Delete clients', 'roles' => []],
    'crm.documents.manage' => ['label' => 'Upload & delete client documents', 'roles' => ['manager']],
    'crm.quotations.view' => ['label' => 'View quotations', 'roles' => ['manager']],
    'crm.quotations.manage' => ['label' => 'Create & edit quotations', 'roles' => ['manager']],
    'crm.finance.view' => ['label' => 'View invoices & payments', 'roles' => ['manager']],
    'crm.finance.manage' => ['label' => 'Create invoices & record payments', 'roles' => ['manager']],
    'crm.reports.view' => ['label' => 'View client & project financial reports', 'roles' => ['manager']],
  ],
  'nav' => [
    ['label' => 'Clients', 'icon' => 'briefcase', 'color' => '#FF6F59', 'url' => '/clients', 'perm' => 'crm.clients.view', 'section' => 'clients', 'order' => 1],
    ['label' => 'Quotations', 'icon' => 'file-text', 'color' => '#F5A524', 'url' => '/quotations', 'perm' => 'crm.quotations.view', 'section' => 'clients', 'order' => 2],
    ['label' => 'Invoices', 'icon' => 'receipt', 'color' => '#12B5A0', 'url' => '/invoices', 'perm' => 'crm.finance.view', 'section' => 'clients', 'order' => 3],
    ['label' => 'Financial Reports', 'icon' => 'chart', 'color' => '#2E9BF6', 'url' => '/crm/reports', 'perm' => 'crm.reports.view', 'section' => 'clients', 'order' => 4],
  ],
  'routes' => function (Core\Router $r): void {
    $v = 'crm.clients.view'; $m = 'crm.clients.manage'; $fv = 'crm.finance.view'; $fm = 'crm.finance.manage';
    $r->get('/clients', [ClientController::class, 'index'], $v);
    $r->get('/clients/create', [ClientController::class, 'create'], $m);
    $r->post('/clients', [ClientController::class, 'store'], $m);
    $r->get('/clients/{id}', [ClientController::class, 'show'], $v);
    $r->get('/clients/{id}/edit', [ClientController::class, 'edit'], $m);
    $r->post('/clients/{id}', [ClientController::class, 'update'], $m);
    $r->post('/clients/{id}/delete', [ClientController::class, 'destroy'], 'crm.clients.delete');
    $r->post('/clients/{id}/contacts', [JourneyController::class, 'saveContact'], $m);
    $r->post('/clients/{id}/contacts/{contact_id}', [JourneyController::class, 'saveContact'], $m);
    $r->post('/clients/{id}/contacts/{contact_id}/delete', [JourneyController::class, 'deleteContact'], $m);
    $r->post('/clients/{id}/activities', [JourneyController::class, 'saveActivity'], $m);
    $r->post('/clients/{id}/activities/{activity_id}', [JourneyController::class, 'saveActivity'], $m);
    $r->post('/clients/{id}/activities/{activity_id}/delete', [JourneyController::class, 'deleteActivity'], $m);
    $r->post('/clients/{id}/activities/{activity_id}/done', [JourneyController::class, 'followUpDone'], $m);
    $r->post('/clients/{id}/documents', [JourneyController::class, 'upload'], 'crm.documents.manage');
    $r->get('/quotations', [QuotationController::class, 'index'], 'crm.quotations.view');
    $r->get('/quotations/create', [QuotationController::class, 'create'], 'crm.quotations.manage');
    $r->post('/quotations', [QuotationController::class, 'store'], 'crm.quotations.manage');
    $r->get('/quotations/{id}', [QuotationController::class, 'show'], 'crm.quotations.view');
    $r->get('/quotations/{id}/edit', [QuotationController::class, 'edit'], 'crm.quotations.manage');
    $r->get('/quotations/{id}/pdf', [QuotationController::class, 'pdf'], 'crm.quotations.view');
    $r->post('/quotations/{id}', [QuotationController::class, 'update'], 'crm.quotations.manage');
    $r->post('/quotations/{id}/status', [QuotationController::class, 'status'], 'crm.quotations.manage');
    $r->post('/quotations/{id}/convert', [QuotationController::class, 'convert'], 'crm.quotations.manage');
    $r->post('/quotations/{id}/delete', [QuotationController::class, 'destroy'], 'crm.quotations.manage');
    $r->get('/invoices', [InvoiceController::class, 'index'], $fv);
    $r->get('/invoices/create', [InvoiceController::class, 'create'], $fm);
    $r->post('/invoices', [InvoiceController::class, 'store'], $fm);
    $r->get('/invoices/{id}', [InvoiceController::class, 'show'], $fv);
    $r->get('/invoices/{id}/edit', [InvoiceController::class, 'edit'], $fm);
    $r->get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'], $fv);
    $r->post('/invoices/{id}', [InvoiceController::class, 'update'], $fm);
    $r->post('/invoices/{id}/send', [InvoiceController::class, 'markSent'], $fm);
    $r->post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel'], $fm);
    $r->post('/invoices/{id}/delete', [InvoiceController::class, 'destroy'], $fm);
    $r->post('/invoices/{id}/email', [InvoiceController::class, 'email'], ['perm' => $fm, 'throttle' => [10, 300]]);
    $r->post('/invoices/{id}/payments', [InvoiceController::class, 'recordPayment'], $fm);
    $r->post('/invoices/{id}/payments/{payment_id}/delete', [InvoiceController::class, 'deletePayment'], $fm);
    // Signed, read-only PDF link e-mailed to the client (no login); the HMAC token is the authorisation.
    $r->get('/shared/invoice/{id}/{token}', [InvoiceController::class, 'sharedPdf'], ['auth' => false, 'throttle' => [30, 300]]);
    $r->get('/crm/reports', [ReportController::class, 'index'], 'crm.reports.view');
    $r->get('/crm/reports/outstanding.csv', [ReportController::class, 'outstandingCsv'], 'crm.reports.view');
  },
  'widgets' => [
    ['zone' => 'kpis', 'order' => 10, 'perm' => 'crm.clients.view', 'render' => fn (array $u): string => Hooks::activeClientsTile($u)],
    ['zone' => 'kpis', 'order' => 11, 'perm' => 'crm.finance.view', 'render' => fn (array $u): string => Hooks::outstandingTile($u)],
    ['zone' => 'main', 'order' => 20, 'perm' => 'crm.clients.view', 'render' => fn (array $u): string => Hooks::followUpsWidget($u)],
    ['zone' => 'side', 'order' => 20, 'perm' => 'crm.finance.view', 'render' => fn (array $u): string => Hooks::overdueWidget($u)],
  ],
  'search' => fn (string $q, array $u): array => Hooks::search($q, $u),
  'cron' => [
    'mark_overdue' => ['daily' => '00:20', 'run' => fn (): string => Hooks::markOverdue()],
    'payment_reminders' => ['daily' => '09:10', 'run' => fn (): string => Hooks::reminders()],
  ],
  'file_access' => [
    'client' => fn (array $u, int $id, array $file, string $mode = 'view'): bool => ClientAccess::canSee($u, $id) && Core\Gate::allows($mode === 'delete' ? 'crm.documents.manage' : 'crm.clients.view', $u),
  ],
];
