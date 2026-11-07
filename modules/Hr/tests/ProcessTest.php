<?php
use Core\{DB, Settings};
use Modules\Hr\Services\ProcessService;

/** Onboarding & exit fixture: HR, a manager with one direct report, an unrelated employee and an unrelated manager. */
function onb_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@onbunit.test'); $mgr = make_user('manager', 'mgr@onbunit.test'); $mgr2 = make_user('manager', 'mgr2@onbunit.test');
  $emp = make_user('employee', 'emp@onbunit.test', ['manager_id' => $mgr, 'joined_on' => '2026-01-05']); $other = make_user('employee', 'other@onbunit.test');
  Settings::set('hr.upload_self_delete_minutes', '20');
  return $f = compact('hr', 'mgr', 'mgr2', 'emp', 'other'); }

/** A small valid PDF upload in $_FILES['file']. */
function onb_pdf(string $name = 'id-proof.pdf'): void {
  $tmp = tempnam(sys_get_temp_dir(), 'onb');
  file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
  $_FILES = ['file' => ['name' => $name, 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]; }

function onb_post(string $path, array $post = [], bool $file = false, string $name = 'id-proof.pdf'): Core\Response {
  if ($file) { onb_pdf($name); }
  $r = http('POST', $path, $post);
  $_FILES = [];
  return $r; }

function onb_proc(int $emp, string $kind = 'onboarding'): ?array { return DB::first("SELECT * FROM hr_processes WHERE user_id=? AND kind=? ORDER BY id DESC LIMIT 1", [$emp, $kind]); }
function onb_task(int $pid, string $title): array { return DB::first('SELECT * FROM hr_process_tasks WHERE process_id=? AND title=?', [$pid, $title]) ?? throw new RuntimeException("task $title missing"); }
function onb_tpl(string $kind): int { return (int) DB::value('SELECT id FROM hr_templates WHERE kind=? AND is_default=1', [$kind]); }

return [
  'default templates are seeded with typed tasks' => function () {
    assert_eq(11, (int) DB::value('SELECT COUNT(*) FROM hr_template_tasks WHERE template_id=?', [onb_tpl('onboarding')]), 'onboarding tasks');
    assert_eq(8, (int) DB::value('SELECT COUNT(*) FROM hr_template_tasks WHERE template_id=?', [onb_tpl('exit')]), 'exit tasks');
    assert_eq('provide', DB::value("SELECT task_type FROM hr_template_tasks WHERE title='Experience / relieving letter'"));
    assert_eq('it', DB::value("SELECT owner FROM hr_template_tasks WHERE title='IT setup'")); },
  'HR starts onboarding once; employee cannot start or manage it' => function () {
    ['hr' => $hr, 'emp' => $emp] = onb_fixture();
    act_as($emp);
    assert_eq(403, http('POST', "/hr/employees/$emp/process/onboarding", ['template_id' => onb_tpl('onboarding')])->status(), 'employee cannot start');
    act_as($hr);
    $r = http('POST', "/hr/employees/$emp/process/onboarding", ['template_id' => onb_tpl('onboarding'), 'start_date' => date('Y-m-d')]);
    assert_eq(302, $r->status(), 'start redirects');
    $p = onb_proc($emp);
    assert_true($p && $p['status'] === 'active', 'process active');
    assert_eq(11, (int) DB::value('SELECT COUNT(*) FROM hr_process_tasks WHERE process_id=?', [$p['id']]), 'tasks copied');
    assert_eq(date('Y-m-d', strtotime('+2 days')), onb_task((int) $p['id'], 'Submit ID proof')['due_date'], 'due date from start date');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND url=?", [$emp, '/hr/processes/'.$p['id']]), 'employee notified');
    http('POST', "/hr/employees/$emp/process/onboarding", ['template_id' => onb_tpl('onboarding')]);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM hr_processes WHERE user_id=? AND kind='onboarding'", [$emp]), 'only one open onboarding');
    act_as($emp);
    assert_eq(403, http('POST', '/hr/processes/'.$p['id'].'/status', ['action' => 'cancel', 'reason' => 'x'])->status(), 'employee cannot cancel');
    assert_eq(200, http('GET', '/hr/processes/'.$p['id'])->status(), 'employee opens own process');
    assert_eq(200, http('GET', '/')->status(), 'dashboard renders with the onboarding card'); },
  'record-level access: own, direct manager, HR only' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'mgr2' => $mgr2, 'emp' => $emp, 'other' => $other] = onb_fixture();
    $pid = (int) onb_proc($emp)['id'];
    act_as($other); assert_eq(403, http('GET', "/hr/processes/$pid")->status(), 'other employee denied');
    act_as($mgr2); assert_eq(403, http('GET', "/hr/processes/$pid")->status(), 'unrelated manager denied');
    act_as($mgr); assert_eq(200, http('GET', "/hr/processes/$pid")->status(), 'direct manager allowed');
    assert_eq(200, http('GET', '/hr/processes')->status(), 'manager list');
    assert_eq(403, http('POST', "/hr/processes/$pid/status", ['action' => 'pause'])->status(), 'manager cannot pause');
    act_as($hr); assert_eq(200, http('GET', "/hr/processes/$pid")->status(), 'HR allowed');
    assert_eq(200, http('GET', "/hr/employees/$emp?tab=checklists")->status(), 'profile tab renders'); },
  'employee upload: 20-minute self-delete window, then HR only' => function () {
    ['emp' => $emp, 'other' => $other, 'mgr' => $mgr] = onb_fixture();
    $pid = (int) onb_proc($emp)['id']; $t = onb_task($pid, 'Submit ID proof');
    act_as($other);
    assert_eq(403, onb_post('/hr/process-tasks/'.$t['id'].'/upload', [], true)->status(), 'other employee cannot upload');
    act_as($emp);
    onb_post('/hr/process-tasks/'.$t['id'].'/upload', [], true);
    $up = DB::first('SELECT * FROM hr_uploads WHERE task_id=?', [$t['id']]);
    assert_true($up && $up['status'] === 'pending' && $up['file_id'], 'upload stored pending with file');
    assert_eq('submitted', onb_task($pid, 'Submit ID proof')['status'], 'task submitted');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE '%uploaded%'", [$mgr]), 'manager notified');
    onb_post('/hr/process-tasks/'.$t['id'].'/upload', ['file' => 'x'], true, 'evil.php');
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM hr_uploads WHERE task_id=?', [$t['id']]), 'disallowed type rejected');
    onb_post('/hr/uploads/'.$up['id'].'/delete');
    assert_true(!DB::find('hr_uploads', (int) $up['id']) && !DB::find('files', (int) $up['file_id']), 'deleted within window');
    assert_eq('pending', onb_task($pid, 'Submit ID proof')['status'], 'task back to pending');
    onb_post('/hr/process-tasks/'.$t['id'].'/upload', [], true);
    $up = DB::first('SELECT * FROM hr_uploads WHERE task_id=?', [$t['id']]);
    DB::update('hr_uploads', ['created_at' => date('Y-m-d H:i:s', strtotime('-25 minutes'))], 'id=?', [$up['id']]);
    assert_eq(403, onb_post('/hr/uploads/'.$up['id'].'/delete')->status(), 'self-delete refused after window');
    assert_true((bool) DB::find('hr_uploads', (int) $up['id']), 'file kept');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='hr.upload.delete'"), 'deletion audit-logged'); },
  'deletion request: employee asks, HR declines or deletes' => function () {
    ['hr' => $hr, 'emp' => $emp, 'other' => $other] = onb_fixture();
    $pid = (int) onb_proc($emp)['id']; $t = onb_task($pid, 'Submit ID proof');
    $up = DB::first('SELECT * FROM hr_uploads WHERE task_id=?', [$t['id']]);
    act_as($other);
    assert_eq(403, onb_post('/hr/uploads/'.$up['id'].'/delete-request', ['reason' => 'mine'])->status(), 'stranger cannot request');
    act_as($emp);
    onb_post('/hr/uploads/'.$up['id'].'/delete-request', ['reason' => '']);
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM hr_delete_requests'), 'reason required');
    onb_post('/hr/uploads/'.$up['id'].'/delete-request', ['reason' => 'Wrong file, this is my old ID']);
    $rq = DB::first("SELECT * FROM hr_delete_requests WHERE upload_id=? AND status='pending'", [$up['id']]);
    assert_true((bool) $rq, 'request pending');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE '%asks to delete%'", [$hr]), 'HR notified');
    onb_post('/hr/uploads/'.$up['id'].'/delete-request', ['reason' => 'again']);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM hr_delete_requests WHERE status='pending'"), 'no duplicate request');
    assert_eq(403, onb_post('/hr/delete-requests/'.$rq['id'].'/decide', ['decision' => 'approve'])->status(), 'employee cannot decide');
    act_as($hr);
    onb_post('/hr/delete-requests/'.$rq['id'].'/decide', ['decision' => 'reject', 'note' => 'This one is fine']);
    assert_eq('rejected', DB::value('SELECT status FROM hr_delete_requests WHERE id=?', [$rq['id']]));
    assert_true((bool) DB::find('hr_uploads', (int) $up['id']), 'kept after decline');
    act_as($emp);
    onb_post('/hr/uploads/'.$up['id'].'/delete-request', ['reason' => 'Really the wrong file']);
    $rq2 = (int) DB::value("SELECT id FROM hr_delete_requests WHERE upload_id=? AND status='pending'", [$up['id']]);
    act_as($hr);
    onb_post("/hr/delete-requests/$rq2/decide", ['decision' => 'approve']);
    assert_true(!DB::find('hr_uploads', (int) $up['id']), 'deleted on approval');
    assert_eq('approved', DB::value('SELECT status FROM hr_delete_requests WHERE id=?', [$rq2]));
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE 'File deleted%'", [$emp]), 'employee told');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='hr.upload.delete_request.approve'"), 'audit-logged'); },
  'review: manager rejects with a comment, employee re-uploads, HR approves' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp, 'other' => $other] = onb_fixture();
    $pid = (int) onb_proc($emp)['id']; $t = onb_task($pid, 'Submit PAN');
    act_as($emp); onb_post('/hr/process-tasks/'.$t['id'].'/upload', [], true, 'pan.pdf');
    $up = DB::first('SELECT * FROM hr_uploads WHERE task_id=?', [$t['id']]);
    assert_eq(403, onb_post('/hr/uploads/'.$up['id'].'/review', ['decision' => 'approve'])->status(), 'no self review');
    act_as($other); assert_eq(403, onb_post('/hr/uploads/'.$up['id'].'/review', ['decision' => 'approve'])->status(), 'employee cannot review');
    act_as($mgr);
    onb_post('/hr/uploads/'.$up['id'].'/review', ['decision' => 'reject']);
    assert_eq('pending', DB::value('SELECT status FROM hr_uploads WHERE id=?', [$up['id']]), 'reject needs a comment');
    onb_post('/hr/uploads/'.$up['id'].'/review', ['decision' => 'reject', 'note' => 'Blurred photo']);
    assert_eq('rejected', DB::value('SELECT status FROM hr_uploads WHERE id=?', [$up['id']]));
    assert_eq('rejected', onb_task($pid, 'Submit PAN')['status'], 'task needs re-upload');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE 'Please upload again%'", [$emp]), 'employee notified');
    act_as($emp); onb_post('/hr/process-tasks/'.$t['id'].'/upload', [], true, 'pan-clear.pdf');
    assert_eq('submitted', onb_task($pid, 'Submit PAN')['status']);
    $up2 = (int) DB::value("SELECT id FROM hr_uploads WHERE task_id=? AND status='pending'", [$t['id']]);
    act_as($hr); onb_post("/hr/uploads/$up2/review", ['decision' => 'approve']);
    assert_eq('done', onb_task($pid, 'Submit PAN')['status'], 'task complete on approval');
    act_as($emp);
    assert_eq(403, onb_post("/hr/uploads/$up2/delete")->status(), 'approved file cannot be self-deleted even inside the window'); },
  'checklist tasks follow their owner; acknowledgement by the employee' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp] = onb_fixture();
    $pid = (int) onb_proc($emp)['id'];
    $it = onb_task($pid, 'IT setup'); $wm = onb_task($pid, 'Welcome meeting'); $ack = onb_task($pid, 'Read & acknowledge company policies');
    act_as($emp);
    assert_eq(403, onb_post('/hr/process-tasks/'.$it['id'].'/toggle')->status(), 'employee cannot tick IT task');
    onb_post('/hr/process-tasks/'.$ack['id'].'/ack');
    assert_eq('done', onb_task($pid, 'Read & acknowledge company policies')['status'], 'acknowledged');
    act_as($mgr); onb_post('/hr/process-tasks/'.$wm['id'].'/toggle');
    assert_eq('done', onb_task($pid, 'Welcome meeting')['status'], 'manager ticks own task');
    assert_eq(403, onb_post('/hr/process-tasks/'.$it['id'].'/toggle')->status(), 'manager cannot tick IT task');
    act_as($hr); onb_post('/hr/process-tasks/'.$it['id'].'/toggle');
    assert_eq('done', onb_task($pid, 'IT setup')['status'], 'HR ticks IT task');
    onb_post('/hr/process-tasks/'.onb_task($pid, 'Previous employment relieving letter')['id'].'/skip');
    assert_eq('skipped', onb_task($pid, 'Previous employment relieving letter')['status'], 'not needed'); },
  'signed company document: download, sign, upload; completes the sign task' => function () {
    ['hr' => $hr, 'emp' => $emp, 'other' => $other] = onb_fixture();
    act_as($hr);
    onb_post('/hr/documents', ['title' => 'NDA 2026', 'category' => 'policy', 'requires_signature' => '1'], true, 'nda.pdf');
    $doc = DB::first("SELECT * FROM company_documents WHERE title='NDA 2026'");
    assert_eq(1, (int) $doc['requires_signature'], 'signature flag saved');
    $pid = (int) onb_proc($emp)['id'];
    onb_post("/hr/processes/$pid/tasks", ['title' => 'Sign the NDA', 'task_type' => 'sign', 'owner' => 'employee', 'company_doc_id' => $doc['id']]);
    $t = onb_task($pid, 'Sign the NDA');
    assert_eq((int) $doc['id'], (int) $t['company_doc_id']);
    act_as($emp);
    onb_post('/hr/documents/'.$doc['id'].'/sign', [], true, 'nda-signed.pdf');
    $up = DB::first('SELECT * FROM hr_uploads WHERE company_doc_id=? AND user_id=?', [$doc['id'], $emp]);
    assert_true($up && $up['status'] === 'pending' && !$up['task_id'], 'signed copy stored for the document');
    assert_eq('submitted', onb_task($pid, 'Sign the NDA')['status'], 'sign task waits for review');
    assert_true(ProcessService::canViewUpload((int) $up['id'], DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$emp])), 'employee sees own signed copy');
    act_as($other);
    assert_eq(403, http('GET', '/files/'.$up['file_id'])->status(), 'others cannot download a signed copy');
    assert_eq(200, http('GET', '/hr/documents')->status(), 'documents page');
    act_as($hr);
    assert_true(str_contains(http('GET', '/hr/documents')->body(), 'Signed copies'), 'HR sees who signed');
    onb_post('/hr/uploads/'.$up['id'].'/review', ['decision' => 'approve']);
    assert_eq('done', onb_task($pid, 'Sign the NDA')['status'], 'approved signature completes the task'); },
  'pause blocks uploads; cancel keeps data; remove only without files; reopen' => function () {
    ['hr' => $hr, 'emp' => $emp, 'other' => $other] = onb_fixture();
    $p = onb_proc($emp); $pid = (int) $p['id']; $t = onb_task($pid, 'Submit address proof');
    act_as($hr); onb_post("/hr/processes/$pid/status", ['action' => 'pause']);
    assert_eq('paused', onb_proc($emp)['status']);
    act_as($emp); onb_post('/hr/process-tasks/'.$t['id'].'/upload', [], true);
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM hr_uploads WHERE task_id=?', [$t['id']]), 'no upload while paused');
    act_as($hr);
    onb_post("/hr/processes/$pid/status", ['action' => 'cancel']);
    assert_eq('paused', onb_proc($emp)['status'], 'cancel needs a reason');
    onb_post("/hr/processes/$pid/status", ['action' => 'cancel', 'reason' => 'Started by mistake']);
    $c = onb_proc($emp);
    assert_eq('cancelled', $c['status']); assert_eq('Started by mistake', $c['cancel_reason']);
    assert_true((int) DB::value('SELECT COUNT(*) FROM hr_uploads h JOIN hr_process_tasks t ON t.id=h.task_id WHERE t.process_id=?', [$pid]) > 0, 'files kept');
    onb_post("/hr/processes/$pid/remove");
    assert_true((bool) DB::find('hr_processes', $pid), 'cannot remove once files exist');
    onb_post("/hr/processes/$pid/status", ['action' => 'reopen']);
    assert_eq('active', onb_proc($emp)['status'], 'reopened');
    onb_post("/hr/employees/$other/process/onboarding", ['template_id' => onb_tpl('onboarding')]);
    $o = onb_proc($other);
    onb_post('/hr/processes/'.$o['id'].'/remove');
    assert_true(!DB::find('hr_processes', (int) $o['id']) && !DB::value('SELECT 1 FROM hr_process_tasks WHERE process_id=?', [$o['id']]), 'removed completely');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='hr.process.remove'"), 'removal audit-logged'); },
  'reminder cron notifies due tasks once' => function () {
    ['emp' => $emp] = onb_fixture();
    $pid = (int) onb_proc($emp)['id'];
    DB::query("UPDATE hr_process_tasks SET due_date=?, reminded_on=NULL WHERE process_id=? AND title='Educational certificates'", [date('Y-m-d', strtotime('+1 day')), $pid]);
    $before = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]);
    ProcessService::reminders();
    assert_true((int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]) > $before, 'employee reminded');
    $mid = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]);
    ProcessService::reminders();
    assert_eq($mid, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'not reminded twice'); },
  'exit: start, HR letter to download, last day moves due dates, auto-deactivate, cancel clears exit date' => function () {
    ['hr' => $hr, 'emp' => $emp, 'other' => $other] = onb_fixture();
    act_as($hr);
    onb_post("/hr/employees/$other/process/exit", ['template_id' => onb_tpl('exit'), 'exit_type' => 'resignation', 'last_working_day' => date('Y-m-d', strtotime('+10 days')), 'deactivate_on_lwd' => '1']);
    $p = onb_proc($other, 'exit'); $pid = (int) $p['id'];
    assert_true($p && (int) $p['deactivate_on_lwd'] === 1, 'exit started with auto-deactivation');
    assert_eq($p['last_working_day'], DB::value('SELECT exit_date FROM employee_profiles WHERE user_id=?', [$other]), 'profile exit date set');
    $ho = onb_task($pid, 'Handover document');
    assert_eq(date('Y-m-d', strtotime('+7 days')), $ho['due_date'], '3 days before the last day');
    onb_post("/hr/processes/$pid/exit", ['exit_type' => 'resignation', 'last_working_day' => date('Y-m-d', strtotime('-1 day')), 'deactivate_on_lwd' => '1']);
    assert_eq(date('Y-m-d', strtotime('-4 days')), onb_task($pid, 'Handover document')['due_date'], 'due dates moved with the last day');
    $letter = onb_task($pid, 'Experience / relieving letter');
    onb_post('/hr/process-tasks/'.$letter['id'].'/upload', ['provide' => '1'], true, 'relieving-letter.pdf');
    assert_eq('done', onb_task($pid, 'Experience / relieving letter')['status'], 'HR letter completes the task');
    $fid = (int) DB::value("SELECT file_id FROM hr_uploads WHERE task_id=? AND purpose='provided'", [$letter['id']]);
    act_as($other);
    assert_eq(200, http('GET', "/files/$fid")->status(), 'employee downloads the letter');
    act_as($emp);
    assert_eq(403, http('GET', "/files/$fid")->status(), 'another employee cannot');
    act_as(null);
    ProcessService::autoDeactivate();
    assert_eq('inactive', DB::value('SELECT status FROM users WHERE id=?', [$other]), 'deactivated after the last working day');
    assert_true((bool) DB::value('SELECT deactivated_at FROM hr_processes WHERE id=?', [$pid]), 'deactivation recorded');
    DB::update('users', ['status' => 'active'], 'id=?', [$other]);
    act_as($hr);
    onb_post("/hr/processes/$pid/status", ['action' => 'complete']);
    assert_eq('completed', DB::value('SELECT status FROM hr_processes WHERE id=?', [$pid]), 'completed');
    onb_post("/hr/employees/$emp/process/exit", ['template_id' => onb_tpl('exit'), 'exit_type' => 'contract_end', 'last_working_day' => date('Y-m-d', strtotime('+40 days')), 'deactivate_on_lwd' => '1']);
    $e = onb_proc($emp, 'exit');
    ProcessService::autoDeactivate();
    assert_eq('active', DB::value('SELECT status FROM users WHERE id=?', [$emp]), 'future last day not deactivated');
    onb_post('/hr/processes/'.$e['id'].'/status', ['action' => 'cancel', 'reason' => 'Contract extended']);
    assert_true(DB::value('SELECT exit_date FROM employee_profiles WHERE user_id=?', [$emp]) === null, 'cancel clears the exit date'); },
  'templates: HR manages tasks, others cannot' => function () {
    ['hr' => $hr, 'emp' => $emp, 'mgr' => $mgr] = onb_fixture();
    act_as($emp); assert_eq(403, http('GET', '/hr/process-templates')->status());
    act_as($mgr); assert_eq(403, http('GET', '/hr/process-templates')->status());
    act_as($hr);
    assert_eq(200, http('GET', '/hr/process-templates')->status());
    $tid = onb_tpl('onboarding');
    onb_post("/hr/process-templates/$tid/tasks", ['title' => 'Passport photo', 'task_type' => 'upload', 'owner' => 'hr', 'due_days' => '1']);
    $t = DB::first("SELECT * FROM hr_template_tasks WHERE title='Passport photo'");
    assert_eq('employee', $t['owner'], 'upload tasks belong to the employee');
    onb_post('/hr/template-tasks/'.$t['id'].'/delete');
    assert_true(!DB::find('hr_template_tasks', (int) $t['id']), 'removed'); },
  'user edit and HR edit link to each other' => function () {
    ['emp' => $emp, 'other' => $other] = onb_fixture();
    act_as($GLOBALS['ADMIN_ID']);
    $a = http('GET', "/admin/users/$emp/edit")->body();
    assert_true(str_contains($a, "/hr/employees/$emp/edit") && str_contains($a, 'HR profile'), 'user edit links to HR profile');
    $h = http('GET', "/hr/employees/$emp/edit")->body();
    assert_true(str_contains($h, "/admin/users/$emp/edit") && str_contains($h, 'Account, role'), 'HR edit links to the account');
    act_as(onb_fixture()['hr']);
    $h = http('GET', "/hr/employees/$other/edit");
    assert_eq(200, $h->status()); },
];
