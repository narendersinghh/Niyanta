<?php
namespace Modules\Admin\Services;
use Core\{Audit, DB, Features, Pdf, Settings, Storage};

/**
 * "Import demo data": a coherent sample Indian digital agency across all enabled bundles.
 * Every row with an id is recorded in demo_records(table_name, record_id); link rows without a single id
 * (project members, client managers/tags, task dependencies, leave balances, acknowledgements) are removed by
 * the ON DELETE CASCADE of their recorded parents. Delete removes exactly the recorded rows (children first)
 * inside one transaction, plus generated files on disk, real data is never touched.
 */
final class DemoData {
  public const DOMAIN = 'demo.niyanta.test';
  public const PASSWORD = 'Demo@12345';
  /** Delete order (children before parents). Primary key column when not "id". */
  private const ORDER = ['notifications', 'task_comments', 'time_entries', 'timesheets', 'attendance', 'work_reports', 'payslips', 'payroll_runs', 'reimbursements', 'pay_components', 'compensations', 'leave_requests', 'holidays',
    'files', 'company_documents', 'payments', 'invoice_items', 'invoices', 'quotation_items', 'quotations', 'client_activities', 'client_contacts', 'change_requests', 'tasks', 'milestones', 'projects', 'clients', 'tags', 'employee_profiles', 'users'];
  private const PK = ['employee_profiles' => 'user_id'];
  /** table => [label, icon, colour] for the Demo data page. */
  public const LABELS = ['users' => ['Users', 'users', 'lav'], 'employee_profiles' => ['Employee profiles', 'id-card', 'mint'], 'holidays' => ['Holidays', 'umbrella', 'sky'], 'leave_requests' => ['Leave requests', 'calendar', 'lemon'],
    'company_documents' => ['Company documents', 'book', 'pink'], 'files' => ['Files', 'paperclip', 'sky'], 'clients' => ['Clients', 'briefcase', 'peach'], 'client_contacts' => ['Contacts', 'user', 'peach'], 'client_activities' => ['Journey entries', 'history', 'peach'],
    'tags' => ['Tags', 'tag', 'lav'], 'quotations' => ['Quotations', 'file-text', 'lemon'], 'quotation_items' => ['Quotation lines', 'list', 'lemon'], 'invoices' => ['Invoices', 'receipt', 'mint'], 'invoice_items' => ['Invoice lines', 'list', 'mint'], 'payments' => ['Payments', 'wallet', 'mint'],
    'projects' => ['Projects', 'folder', 'lav'], 'milestones' => ['Milestones', 'flag', 'lav'], 'tasks' => ['Tasks', 'check-square', 'sky'], 'task_comments' => ['Task comments', 'message', 'sky'], 'change_requests' => ['Change requests', 'git', 'pink'], 'work_reports' => ['Work reports', 'clipboard', 'lemon'],
    'attendance' => ['Attendance days', 'clock', 'mint'], 'time_entries' => ['Time entries', 'timer', 'mint'], 'timesheets' => ['Timesheets', 'calendar', 'mint'], 'compensations' => ['Compensations', 'wallet', 'pink'], 'pay_components' => ['Pay components', 'sliders', 'pink'],
    'payroll_runs' => ['Payroll runs', 'rupee', 'pink'], 'payslips' => ['Payslips', 'file-text', 'pink'], 'reimbursements' => ['Reimbursements', 'receipt', 'lemon'], 'notifications' => ['Notifications', 'bell', 'sky']];

  /** table => feature it belongs to (rows of switched-off features are not listed). */
  public const FEATURE = ['employee_profiles' => 'employees', 'holidays' => 'leave', 'leave_requests' => 'leave', 'company_documents' => 'company_docs', 'clients' => 'clients', 'client_contacts' => 'clients', 'client_activities' => 'clients', 'tags' => 'clients',
    'quotations' => 'quotations', 'quotation_items' => 'quotations', 'invoices' => 'invoices', 'invoice_items' => 'invoices', 'payments' => 'invoices', 'projects' => 'projects', 'milestones' => 'projects', 'tasks' => 'projects', 'task_comments' => 'projects',
    'change_requests' => 'projects', 'work_reports' => 'work_reports', 'attendance' => 'attendance', 'time_entries' => 'attendance', 'timesheets' => 'attendance', 'compensations' => 'payroll', 'pay_components' => 'payroll', 'payroll_runs' => 'payroll', 'payslips' => 'payroll', 'reimbursements' => 'reimbursements'];

  private array $rec = [];
  private array $files = [];
  private string $now;
  private int $by;

  public static function has(string $table): bool {
    try { return (bool) DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]); }
    catch (\Throwable) { return false; } }

  /** Recorded demo rows per table (empty when nothing is imported). */
  public static function counts(): array {
    if (!self::has('demo_records')) { return []; }
    $c = DB::pairs('SELECT table_name, COUNT(*) FROM demo_records GROUP BY table_name');
    $out = [];
    foreach (array_keys(self::LABELS) as $t) { if (!empty($c[$t])) { $out[$t] = (int) $c[$t]; } }
    return $out + array_map('intval', array_diff_key($c, $out)); }

  public static function exists(): bool { return self::has('demo_records') && (bool) DB::value('SELECT 1 FROM demo_records LIMIT 1'); }

  /** Import the dataset. Returns counts per table. Refuses while demo data exists. */
  public static function import(int $byUserId): array {
    if (!self::has('demo_records')) { throw new \DomainException('The demo_records table is missing. Run pending migrations first.'); }
    if (self::exists()) { throw new \DomainException('Demo data is already loaded. Delete it before importing again.'); }
    if (DB::value('SELECT 1 FROM users WHERE email LIKE ? LIMIT 1', ['%@'.self::DOMAIN])) { throw new \DomainException('Users with @'.self::DOMAIN.' emails already exist. Remove them first.'); }
    $self = new self();
    $self->by = $byUserId;
    $self->now = date('Y-m-d H:i:s');
    @set_time_limit(120);
    mt_srand(20261029);
    try { DB::transaction(static fn () => $self->build()); }
    catch (\Throwable $e) { foreach ($self->files as $f) { @unlink($f); } throw $e; }
    $counts = self::counts();
    Settings::set('demo.imported', ['at' => $self->now, 'by' => $byUserId, 'rows' => array_sum($counts)]);
    Audit::log('demo.import', 'demo', null, ['rows' => array_sum($counts)]);
    return $counts; }

  /** Delete every recorded demo row (children first) and generated files. Returns counts per table. */
  public static function delete(): array {
    if (!self::has('demo_records')) { return []; }
    $ids = [];
    foreach (DB::all('SELECT table_name, record_id FROM demo_records') as $r) { $ids[$r['table_name']][] = (int) $r['record_id']; }
    if (!$ids) { return []; }
    $paths = isset($ids['files']) ? self::chunked('SELECT disk_path FROM files WHERE id IN', $ids['files']) : [];
    $order = [...self::ORDER, ...array_diff(array_keys($ids), self::ORDER)];
    $counts = DB::transaction(static function () use ($ids, $order): array {
      $out = [];
      if (!empty($ids['clients']) && self::has('invoices')) {
        $inv = array_values(array_unique([...($ids['invoices'] ?? []), ...array_map('intval', self::chunked('SELECT id FROM invoices WHERE client_id IN', $ids['clients']))]));
        foreach (array_chunk($inv, 500) as $c) { $out['payments'] = ($out['payments'] ?? 0) + DB::delete('payments', 'invoice_id IN '.DB::in($c), $c); }
        $ids['invoices'] = $inv;
        if (self::has('quotations')) { $ids['quotations'] = array_values(array_unique([...($ids['quotations'] ?? []), ...array_map('intval', self::chunked('SELECT id FROM quotations WHERE client_id IN', $ids['clients']))])); } }
      foreach ($order as $t) {
        if (empty($ids[$t]) || !self::has($t)) { continue; }
        $pk = DB::id(self::PK[$t] ?? 'id'); $n = 0;
        foreach (array_chunk($ids[$t], 500) as $chunk) { $n += DB::delete($t, "$pk IN ".DB::in($chunk), $chunk); }
        $out[$t] = ($out[$t] ?? 0) + $n; }
      DB::query('DELETE FROM demo_records');
      return $out; });
    foreach ($paths as $p) { @unlink($f = Storage::path((string) $p)); for ($d = dirname($f); str_contains($d, '/hr/demo'); $d = dirname($d)) { if (!@rmdir($d)) { break; } } }
    Settings::set('demo.imported', null);
    Audit::log('demo.delete', 'demo', null, ['rows' => array_sum($counts)]);
    return $counts; }

  private static function chunked(string $sql, array $ids): array {
    $out = [];
    foreach (array_chunk($ids, 500) as $c) { array_push($out, ...DB::column($sql.' '.DB::in($c), $c)); }
    return $out; }

  private function add(string $table, array $row): int { $id = DB::insert($table, $row); $this->rec[$table][] = $id; return $id; }
  /** Record ids of child rows inserted in bulk, found through their (demo) parents. */
  private function collect(string $table, string $fk, array $parents): void {
    if ($parents) { $this->rec[$table] = array_merge($this->rec[$table] ?? [], array_map('intval', self::chunked('SELECT id FROM '.DB::id($table).' WHERE '.DB::id($fk).' IN', $parents))); } }
  private static function d(int $offset, string $base = 'today'): string { return date('Y-m-d', strtotime("$offset days", strtotime($base))); }
  private static function on(string $feature, string $table): bool { return Features::enabled($feature) && self::has($table); }

  private function build(): void {
    $U = $this->users();
    $year = (int) date('Y');
    if (self::on('employees', 'employee_profiles')) { $this->profiles($U); }
    $hol = self::on('leave', 'holidays') ? $this->holidays($year) : [];
    if (self::on('leave', 'leave_requests')) { $this->leave($U, $year); }
    if (self::on('company_docs', 'company_documents')) { $this->documents($U); }
    $C = self::on('clients', 'clients') ? $this->clients($U) : [];
    $P = self::on('projects', 'projects') ? $this->projects($U, $C) : ['projects' => [], 'tasks' => []];
    if ($C) { $this->finance($U, $C, $P['projects']); }
    if (self::on('work_reports', 'work_reports')) { $this->reports($U, $P['projects']); }
    if (self::on('attendance', 'attendance')) { $this->attendance($U, $P, $hol); }
    if (self::on('payroll', 'compensations')) { $this->payroll($U); }
    if (self::on('reimbursements', 'reimbursements')) { $this->reimbursements($U); }
    $this->notifications($U);
    $uids = array_values($U);
    foreach (['attendance', 'time_entries', 'timesheets', 'work_reports', 'compensations', 'pay_components', 'reimbursements', 'leave_requests', 'payslips', 'notifications'] as $t) { if (self::has($t) && !isset($this->rec[$t])) { $this->collect($t, 'user_id', $uids); } }
    $rows = [];
    foreach ($this->rec as $t => $ids) { foreach (array_unique($ids) as $id) { $rows[] = ['table_name' => $t, 'record_id' => $id, 'created_at' => $this->now]; } }
    DB::insertMany('demo_records', $rows); }

  /** ~8 people across roles with reporting managers. Returns key => user id. */
  private function users(): array {
    $roles = DB::pairs('SELECT slug, id FROM roles');
    $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    $people = [
      'aarav' => ['Aarav Mehta', 'manager', 'Delivery Head', 'Delivery', null, -900],
      'priya' => ['Priya Nair', 'hr', 'HR Manager', 'People & Culture', 'aarav', -760],
      'rohan' => ['Rohan Kapoor', 'manager', 'Account Manager', 'Client Services', 'aarav', -620],
      'ananya' => ['Ananya Iyer', 'employee', 'UI/UX Designer', 'Design', 'aarav', -540],
      'vikram' => ['Vikram Singh', 'employee', 'Full-stack Developer', 'Engineering', 'aarav', -480],
      'sneha' => ['Sneha Reddy', 'employee', 'Frontend Developer', 'Engineering', 'aarav', -300],
      'karan' => ['Karan Joshi', 'employee', 'SEO & Content Lead', 'Marketing', 'rohan', -260],
      'meera' => ['Meera Pillai', 'employee', 'Content Writer (part-time)', 'Marketing', 'rohan', -150]];
    $ids = []; $i = 0;
    foreach ($people as $k => [$name, $role, $title, $dept, $mgr, $joined]) {
      $i++;
      $ids[$k] = $this->add('users', ['role_id' => (int) ($roles[$role] ?? $roles['employee']), 'name' => $name, 'email' => $k.'@'.self::DOMAIN, 'phone' => '+91 98'.str_pad((string) (45000000 + $i * 1234567 % 9999999), 8, '0', STR_PAD_LEFT), 'password_hash' => $hash, 'status' => 'active',
        'designation' => $title, 'department' => $dept, 'manager_id' => $mgr ? $ids[$mgr] : null, 'employee_code' => 'DEMO-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'joined_on' => self::d($joined), 'created_at' => $this->now, 'updated_at' => $this->now]); }
    return $ids; }

  private function profiles(array $U): void {
    $cols = DB::column('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?', ['employee_profiles']);
    $p = ['aarav' => ['full_time', null, null, '1986-04-12', 'Male', 'Andheri West, Mumbai'], 'priya' => ['full_time', null, null, '1990-08-03', 'Female', 'Kakkanad, Kochi'], 'rohan' => ['full_time', null, null, '1989-11-21', 'Male', 'Bandra East, Mumbai'],
      'ananya' => ['full_time', '9.00', null, '1995-02-17', 'Female', 'Indiranagar, Bengaluru'], 'vikram' => ['full_time', null, '6,7', '1993-06-30', 'Male', 'Sector 62, Noida'], 'sneha' => ['full_time', '9.00', null, '1997-12-09', 'Female', 'Gachibowli, Hyderabad'],
      'karan' => ['full_time', null, '7', '1994-09-14', 'Male', 'Kothrud, Pune'], 'meera' => ['part_time', '6.00', '6,7', '1998-01-25', 'Female', 'Adyar, Chennai']];
    $banks = ['HDFC Bank', 'ICICI Bank', 'State Bank of India', 'Axis Bank', 'Kotak Mahindra Bank'];
    $i = 0;
    foreach ($p as $k => [$type, $hours, $off, $dob, $gender, $addr]) {
      $row = ['user_id' => $U[$k], 'employment_type' => $type, 'date_of_birth' => $dob, 'gender' => $gender, 'personal_email' => $k.'.personal@'.self::DOMAIN, 'address' => $addr, 'emergency_name' => 'Family contact', 'emergency_phone' => '+91 99'.str_pad((string) (1000000 + $i * 7311), 8, '0', STR_PAD_LEFT),
        'bank_name' => $banks[$i % 5], 'bank_ifsc' => strtoupper(substr(str_replace(' ', '', $banks[$i % 5]), 0, 4)).'0'.str_pad((string) (1200 + $i * 37), 6, '0', STR_PAD_LEFT), 'probation_end' => self::d(-60 + $i * 40), 'notes' => 'Demo employee', 'updated_at' => $this->now];
      if (in_array('work_hours_per_day', $cols, true)) { $row['work_hours_per_day'] = $hours; $row['weekly_off_days'] = $off; }
      DB::insert('employee_profiles', $row);
      $this->rec['employee_profiles'][] = $U[$k]; $i++; } }

  /** 5 fixed holidays + 15 optional festival days for the year (existing ones are left alone). */
  private function holidays(int $y): array {
    $fixed = [2025 => ['01-26' => 'Republic Day', '03-14' => 'Holi', '08-15' => 'Independence Day', '08-27' => 'Ganesh Chaturthi', '10-20' => 'Diwali'], 2026 => ['01-26' => 'Republic Day', '03-04' => 'Holi', '08-15' => 'Independence Day', '09-14' => 'Ganesh Chaturthi', '11-08' => 'Diwali'],
      2027 => ['01-26' => 'Republic Day', '03-22' => 'Holi', '08-15' => 'Independence Day', '09-04' => 'Ganesh Chaturthi', '10-29' => 'Diwali']][$y] ?? ['01-26' => 'Republic Day', '03-04' => 'Holi', '08-15' => 'Independence Day', '09-14' => 'Ganesh Chaturthi', '11-08' => 'Diwali'];
    $optional = ['01-14' => 'Makar Sankranti / Pongal', '01-23' => 'Vasant Panchami', '02-15' => 'Maha Shivaratri', '03-19' => 'Ugadi / Gudi Padwa', '03-21' => 'Eid ul-Fitr', '03-26' => 'Ram Navami', '04-03' => 'Good Friday', '04-14' => 'Baisakhi / Ambedkar Jayanti',
      '05-01' => 'Buddha Purnima', '05-27' => 'Eid al-Adha (Bakrid)', '08-26' => 'Onam', '08-28' => 'Raksha Bandhan', '09-04' => 'Janmashtami', '10-20' => 'Dussehra', '11-24' => 'Guru Nanak Jayanti', '12-25' => 'Christmas'];
    $existing = DB::pairs('SELECT CONCAT(holiday_date, "|", name), id FROM holidays WHERE holiday_date BETWEEN ? AND ?', ["$y-01-01", "$y-12-31"]);
    $out = [];
    foreach ([[$fixed, 0], [array_slice($optional, 0, 15, true), 1]] as [$list, $opt]) {
      foreach ($list as $md => $name) {
        $date = "$y-$md";
        if (!$opt) { $out[$date] = $name; }
        if (isset($existing["$date|$name"])) { continue; }
        $this->add('holidays', ['holiday_date' => $date, 'name' => $name, 'optional' => $opt]); } }
    return $out; }

  private function leave(array $U, int $y): void {
    $types = DB::all('SELECT id, code, annual_quota FROM leave_types WHERE active=1 ORDER BY paid DESC, id');
    if (!$types) { return; }
    $byCode = array_column($types, null, 'code');
    $oh = (int) ($byCode['OH']['id'] ?? $types[0]['id']);
    $lop = (int) ($byCode['LOP']['id'] ?? (int) DB::value("SELECT id FROM leave_types WHERE code='LOP'") ?: $oh);
    $req = [
      ['ananya', $oh, "$y-10-20", "$y-10-20", 0, 1, 'Dussehra with family in Mysuru', 'approved', 'aarav'],
      ['sneha', $oh, self::d(-49), self::d(-48), 0, 2, 'Cousin\'s wedding in Vijayawada', 'approved', 'aarav'],
      ['vikram', $oh, "$y-11-24", "$y-11-24", 0, 1, 'Guru Nanak Jayanti', 'pending', null],
      ['karan', $lop, self::d(-34), self::d(-34), 0, 1, 'Personal work', 'approved', 'rohan'],
      ['meera', $oh, self::d(9), self::d(9), 1, 0.5, 'Doctor\'s appointment (afternoon)', 'pending', null],
      ['rohan', $oh, "$y-12-24", "$y-12-25", 0, 1, 'Christmas break', 'pending', null],
      ['priya', $oh, self::d(-12), self::d(-12), 0, 1, 'Onam visit', 'rejected', 'aarav']];
    $used = [];
    foreach ($req as [$u, $type, $from, $to, $half, $days, $reason, $status, $rev]) {
      if ($to < $from) { $to = $from; }
      $this->add('leave_requests', ['user_id' => $U[$u], 'leave_type_id' => $type, 'start_date' => $from, 'end_date' => $to, 'half_day' => $half, 'days' => $days, 'reason' => $reason, 'status' => $status, 'reviewed_by' => $rev ? $U[$rev] : null,
        'reviewed_at' => $rev ? $this->now : null, 'review_note' => $status === 'rejected' ? 'Overlaps payroll close, please pick another day.' : null, 'created_at' => date('Y-m-d H:i:s', strtotime($from.' -6 days'))]);
      if ($status === 'approved') { $used[$U[$u]][$type] = ($used[$U[$u]][$type] ?? 0) + $days; } }
    if (self::has('leave_balances')) {
      $rows = [];
      foreach ($U as $uid) { foreach ($types as $t) { $rows[] = ['user_id' => $uid, 'leave_type_id' => (int) $t['id'], 'year' => $y, 'allocated' => (float) $t['annual_quota'], 'used' => (float) ($used[$uid][(int) $t['id']] ?? 0)]; } }
      DB::insertMany('leave_balances', $rows); }
    if (self::has('employee_leave_quotas')) { DB::insertMany('employee_leave_quotas', [['user_id' => $U['priya'], 'leave_type_id' => $oh, 'annual_quota' => 18]]); } }

  private function documents(array $U): void {
    $docs = [['Employee Handbook 2026', 'handbook', 'Who we are, how we work, code of conduct and benefits.', 1, ['Welcome to the team! This handbook explains how our agency works day to day.', 'Working hours: 9:30 to 18:30 with a one-hour lunch break. Core collaboration hours are 11:00 to 17:00.', 'Communication: use the project channels for work discussions and keep clients in the loop through the client journey.', 'Code of conduct: be respectful, protect client confidentiality and report concerns to HR.']],
      ['Leave & Holiday Policy', 'policy', 'Fixed company holidays, optional holidays and how to apply for leave.', 1, ['We observe 5 fixed holidays: Republic Day, Holi, Independence Day, Ganesh Chaturthi and Diwali.', 'Every employee also gets 15 optional holidays per year for festivals, personal or medical reasons.', 'Apply for leave in Niyanta at least 3 working days in advance; your reporting manager approves it.', 'Unused optional holidays do not carry forward.']],
      ['Reimbursement Claim Guidelines', 'forms', 'What can be claimed and how to submit receipts.', 0, ['Claim travel, client meals and approved software within 30 days of the expense.', 'Attach a clear photo or PDF of the receipt to every claim.', 'Approved claims are paid with the next salary.']]];
    $dir = 'hr/demo/'.date('Y/m');
    $abs = Storage::root().'/'.$dir;
    if (!is_dir($abs)) { @mkdir($abs, 0775, true); }
    $hasFiles = is_dir($abs) && is_writable($abs);
    $acks = [];
    foreach ($docs as $i => [$title, $cat, $desc, $ack, $lines]) {
      $id = $this->add('company_documents', ['title' => $title, 'category' => $cat, 'description' => $desc, 'requires_ack' => $ack, 'created_by' => $U['priya'], 'created_at' => date('Y-m-d H:i:s', strtotime('-'.(40 - $i * 10).' days')), 'updated_at' => $this->now]);
      if ($hasFiles) {
        $pdf = new Pdf($title);
        $pdf->rect(0, 0, $pdf->width(), 90, '#5B4CF5')->font(true, 20)->text(40, 52, $title, '#ffffff')->font(false, 10)->text(40, 72, 'Demo company document · '.date('F Y'), '#E6E3FF');
        $y = 130.0;
        foreach ($lines as $n => $l) { $pdf->font(true, 11)->text(40, $y, ($n + 1).'.', '#5B4CF5'); $y = $pdf->font(false, 11)->paragraph(60, $y, 480, $l, '#333333', 1.5) + 10; }
        $pdf->font(false, 9)->text(40, 800, 'Sample content generated by Niyanta demo data. Safe to delete.', '#888888');
        $bin = $pdf->output();
        $name = bin2hex(random_bytes(16)).'.pdf';
        if (file_put_contents("$abs/$name", $bin) !== false) {
          $this->files[] = "$abs/$name";
          $fid = $this->add('files', ['module' => 'hr', 'entity_type' => 'company_doc', 'entity_id' => $id, 'category' => 'company_doc', 'disk_path' => "$dir/$name", 'original_name' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)).'.pdf', 'mime' => 'application/pdf', 'size' => strlen($bin), 'uploaded_by' => $U['priya'], 'created_at' => $this->now]);
          DB::update('company_documents', ['file_id' => $fid], 'id=?', [$id]); } }
      if ($ack && self::has('company_document_acks')) { foreach (['aarav', 'rohan', 'ananya', 'vikram', 'karan'] as $j => $u) { if ($j < 5 - $i * 2) { $acks[] = ['document_id' => $id, 'user_id' => $U[$u], 'acknowledged_at' => date('Y-m-d H:i:s', strtotime('-'.(20 - $j).' days'))]; } } } }
    DB::insertMany('company_document_acks', $acks); }

  /** 6 clients with contacts, managers, tags and journey. Returns key => [id, name]. */
  private function clients(array $U): array {
    $tagDefs = ['Retainer' => 'mint', 'Priority' => 'peach', 'E-commerce' => 'sky', 'New lead' => 'lav', 'Monthly SEO' => 'lemon'];
    $have = array_change_key_case(DB::pairs('SELECT name, id FROM tags WHERE name IN '.DB::in(array_keys($tagDefs)), array_keys($tagDefs)));
    $tags = [];
    foreach ($tagDefs as $n => $c) { $tags[$n] = $have[strtolower($n)] ?? $this->add('tags', ['name' => $n, 'color' => $c]); }
    $list = [
      'zenith' => ['Rajesh Khanna', 'Zenith Realty Pvt Ltd', 'Real estate', 'Mumbai', 'Maharashtra', 'active', 'Referral', ['rohan'], ['Priority', 'Retainer'], [['Rajesh Khanna', 'Director'], ['Pooja Shah', 'Marketing Manager']]],
      'saffron' => ['Kavya Rao', 'Saffron Spice Foods', 'FMCG / D2C', 'Bengaluru', 'Karnataka', 'active', 'Website', ['rohan', 'aarav'], ['E-commerce', 'Priority'], [['Kavya Rao', 'Founder & CEO'], ['Manoj Kumar', 'Operations Head']]],
      'greenleaf' => ['Amit Deshpande', 'GreenLeaf Organics', 'Agriculture', 'Pune', 'Maharashtra', 'active', 'LinkedIn', ['rohan'], ['Monthly SEO', 'Retainer'], [['Amit Deshpande', 'Co-founder']]],
      'nimbus' => ['Farah Khan', 'Nimbus EdTech', 'Education', 'Hyderabad', 'Telangana', 'lead', 'Event', ['aarav'], ['New lead'], [['Farah Khan', 'Product Head'], ['Arjun Varma', 'CTO']]],
      'kaveri' => ['Suresh Gounder', 'Kaveri Textiles', 'Manufacturing', 'Coimbatore', 'Tamil Nadu', 'on_hold', 'Cold outreach', ['rohan'], [], [['Suresh Gounder', 'Managing Partner']]],
      'bluepeak' => ['Neha Bansal', 'BluePeak Fintech', 'Financial services', 'Gurugram', 'Haryana', 'active', 'Referral', ['aarav', 'rohan'], ['Priority'], [['Neha Bansal', 'VP Marketing'], ['Rahul Sethi', 'Brand Manager']]]];
    $C = []; $contacts = []; $mgr = []; $ct = []; $i = 0;
    foreach ($list as $k => [$name, $company, $industry, $city, $state, $status, $source, $mgrs, $tg, $people]) {
      $i++;
      $slug = preg_replace('/[^a-z]/', '', strtolower(explode(' ', $company)[0]));
      $id = $this->add('clients', ['name' => str_replace(' Pvt Ltd', '', $company), 'company' => $company, 'email' => 'hello@'.$slug.'.example', 'phone' => '+91 80'.str_pad((string) (40000000 + $i * 1357911 % 9999999), 8, '0', STR_PAD_LEFT), 'whatsapp' => '+91 97'.str_pad((string) (20000000 + $i * 246813 % 9999999), 8, '0', STR_PAD_LEFT),
        'website' => 'https://'.$slug.'.example', 'industry' => $industry, 'address' => 'Office '.(100 + $i * 7).', Business Park', 'city' => $city, 'state' => $state, 'country' => 'India', 'postal_code' => (string) (400000 + $i * 10101), 'tax_id' => '27AAB'.strtoupper(substr($slug, 0, 3)).str_pad((string) $i, 4, '0', STR_PAD_LEFT).'1Z'.$i,
        'status' => $status, 'source' => $source, 'notes' => 'Demo client.', 'currency' => 'INR', 'created_by' => $U['rohan'], 'created_at' => date('Y-m-d H:i:s', strtotime('-'.(200 - $i * 20).' days')), 'updated_at' => $this->now]);
      $C[$k] = [$id, $company];
      foreach ($people as $j => [$pn, $pd]) { $contacts[] = ['client_id' => $id, 'name' => $pn, 'designation' => $pd, 'email' => strtolower(explode(' ', $pn)[0]).'@'.$slug.'.example', 'phone' => '+91 90'.str_pad((string) (10000000 + ($i * 10 + $j) * 97531 % 9999999), 8, '0', STR_PAD_LEFT), 'is_primary' => $j === 0 ? 1 : 0]; }
      foreach ($mgrs as $m) { $mgr[] = ['client_id' => $id, 'user_id' => $U[$m]]; }
      foreach ($tg as $t) { $ct[] = ['client_id' => $id, 'tag_id' => (int) $tags[$t]]; } }
    DB::insertMany('client_contacts', $contacts);
    DB::insertMany('client_managers', $mgr);
    DB::insertMany('client_tags', $ct);
    $this->collect('client_contacts', 'client_id', array_column($C, 0));
    $j = [
      ['zenith', 'meeting', 'Kick-off meeting at Zenith HQ', 'Agreed on scope: new website with property listings, enquiry forms and CRM integration.', -48, null, 'rohan'],
      ['zenith', 'requirement', 'Shared sitemap and listing fields', 'Client wants filters for BHK, budget and locality.', -40, null, 'aarav'],
      ['zenith', 'follow_up', 'Review homepage design with Pooja', 'Walk through the hero section and listing cards.', -3, 2, 'rohan'],
      ['saffron', 'call', 'Discovery call with Kavya', 'Needs a D2C store with subscriptions for spice boxes.', -70, null, 'rohan'],
      ['saffron', 'email', 'Sent proposal and quotation', 'Quotation shared for storefront build and payment integration.', -62, null, 'rohan'],
      ['saffron', 'payment', 'Advance received', '40% advance received via bank transfer.', -55, null, 'rohan'],
      ['saffron', 'follow_up', 'Confirm Shiprocket integration scope', 'Client to share API credentials.', -1, 0, 'aarav'],
      ['greenleaf', 'report', 'September SEO report shared', 'Organic sessions up 18% month on month; 6 keywords on page one.', -27, null, 'karan'],
      ['greenleaf', 'discussion', 'Content calendar for November', 'Focus on winter vegetables and recipe content.', -6, null, 'karan'],
      ['nimbus', 'meeting', 'Intro meeting at TechSparks', 'Interested in a learning app for Class 8–10 students.', -15, null, 'aarav'],
      ['nimbus', 'follow_up', 'Send discovery workshop proposal', 'Two-day workshop, hourly billing.', -2, 3, 'aarav'],
      ['kaveri', 'call', 'Project paused by client', 'Budget review until next quarter.', -35, null, 'rohan'],
      ['kaveri', 'note', 'Internal: reconnect in January', 'Good fit for catalogue + B2B portal.', -35, null, 'rohan'],
      ['bluepeak', 'meeting', 'Brand refresh presentation', 'Client approved logo direction B and colour palette.', -95, null, 'aarav'],
      ['bluepeak', 'email', 'Final invoice sent', 'Brand guidelines and collateral delivered.', -32, null, 'rohan'],
      ['bluepeak', 'follow_up', 'Payment reminder for final invoice', 'Invoice is overdue, call Neha.', -5, -1, 'rohan']];
    $rows = [];
    foreach ($j as [$c, $type, $title, $body, $at, $fu, $u]) {
      $occ = date('Y-m-d H:i:s', strtotime("$at days 11:30"));
      $rows[] = ['client_id' => $C[$c][0], 'project_id' => null, 'type' => $type, 'title' => $title, 'body' => $body, 'is_internal' => str_starts_with($title, 'Internal') ? 1 : 0, 'occurred_at' => $occ, 'follow_up_at' => $fu === null ? null : date('Y-m-d H:i:s', strtotime("$fu days 10:30")), 'follow_up_done' => 0, 'user_id' => $U[$u], 'created_at' => $occ]; }
    DB::insertMany('client_activities', $rows);
    $this->collect('client_activities', 'client_id', array_column($C, 0));
    return $C; }

  /** 5 projects, members, milestones, ~40 tasks with dependencies and comments, change requests. */
  private function projects(array $U, array $C): array {
    $cid = static fn (?string $k) => $k && isset($C[$k]) ? $C[$k][0] : null;
    $defs = [
      'zrw' => ['Zenith Realty Website Revamp', 'DEMO-ZRW', 'zenith', 'active', 'high', -45, 30, 450000, 'fixed', null, 320, 'lav', 'aarav', 55, ['ananya' => 'Design lead', 'vikram' => 'Backend', 'sneha' => 'Frontend', 'rohan' => 'Account'],
        [['Discovery & sitemap', 'completed', -38], ['UI design', 'completed', -14], ['Development', 'in_progress', 18], ['Launch', 'pending', 30]]],
      'ssd' => ['Saffron Spice D2C Store', 'DEMO-SSD', 'saffron', 'active', 'urgent', -60, 20, 600000, 'fixed', null, 400, 'peach', 'aarav', 62, ['vikram' => 'Tech lead', 'sneha' => 'Frontend', 'ananya' => 'UX', 'rohan' => 'Account'],
        [['UX & catalogue', 'completed', -35], ['Storefront build', 'in_progress', 6], ['Payments & go-live', 'pending', 20]]],
      'gls' => ['GreenLeaf SEO Retainer', 'DEMO-GLS', 'greenleaf', 'active', 'medium', -120, 65, 360000, 'retainer', 1200, 240, 'mint', 'rohan', 40, ['karan' => 'SEO lead', 'meera' => 'Writer'],
        [['Technical audit', 'completed', -90], ['Content sprint, October', 'in_progress', 2]]],
      'nla' => ['Nimbus Learning App, Discovery', 'DEMO-NLA', 'nimbus', 'planning', 'medium', 7, 40, null, 'hourly', 2000, 120, 'sky', 'aarav', 0, ['ananya' => 'UX research', 'vikram' => 'Architecture'],
        [['Discovery workshop', 'pending', 14]]],
      'bpb' => ['BluePeak Brand Refresh', 'DEMO-BPB', 'bluepeak', 'completed', 'high', -140, -35, 250000, 'fixed', null, 180, 'pink', 'aarav', 100, ['ananya' => 'Brand designer', 'meera' => 'Copy', 'karan' => 'Launch content'],
        [['Brand guidelines', 'completed', -70], ['Collateral & launch kit', 'completed', -38]]]];
    $P = []; $M = []; $members = [];
    foreach ($defs as $k => [$name, $code, $client, $status, $prio, $start, $deadline, $budget, $billing, $rate, $est, $color, $mgr, $progress, $mem, $ms]) {
      $id = $this->add('projects', ['client_id' => $cid($client), 'name' => $name, 'code' => $code, 'description' => 'Demo project for '.($C[$client][1] ?? 'internal team').'.', 'scope' => 'See milestones and tasks.', 'deliverables' => implode(', ', array_column($ms, 0)), 'status' => $status, 'priority' => $prio,
        'start_date' => self::d($start), 'deadline' => self::d($deadline), 'budget' => $budget, 'billing_type' => $billing, 'hourly_rate' => $rate, 'estimated_hours' => $est, 'color' => $color, 'manager_id' => $U[$mgr], 'progress' => $progress, 'created_by' => $U['aarav'], 'created_at' => date('Y-m-d H:i:s', strtotime(($start - 5).' days')), 'updated_at' => $this->now]);
      $P[$k] = ['id' => $id, 'client' => $cid($client), 'members' => array_keys($mem), 'name' => $name, 'billing' => $billing];
      foreach ($mem + [$mgr => 'Manager'] as $u => $role) { $members[$id.'-'.$U[$u]] = ['project_id' => $id, 'user_id' => $U[$u], 'role' => $role]; }
      foreach ($ms as $pos => [$t, $st, $due]) { $M[$k][] = $this->add('milestones', ['project_id' => $id, 'title' => $t, 'description' => null, 'due_date' => self::d($due), 'amount' => $budget ? round($budget / count($ms), 2) : null, 'status' => $st, 'completed_at' => $st === 'completed' ? date('Y-m-d H:i:s', strtotime(($due - 1).' days 17:00')) : null, 'position' => $pos]); } }
    DB::insertMany('project_members', array_values($members));
    $T = [
      'zrw' => [['Finalise sitemap & IA', 'done', 'high', 'ananya', 0, -40, 8], ['Wireframes, home, listing, detail', 'done', 'high', 'ananya', 1, -30, 16], ['Homepage visual design', 'done', 'high', 'ananya', 1, -18, 14, [1]], ['Listing & filter UI design', 'done', 'medium', 'ananya', 1, -15, 12, [1]],
        ['Set up Laravel project & CI', 'done', 'medium', 'vikram', 2, -12, 6], ['Property listing API with filters', 'in_progress', 'high', 'vikram', 2, 4, 24, [4]], ['Build homepage (responsive)', 'in_progress', 'high', 'sneha', 2, 3, 18, [2]], ['Listing page with BHK/budget filters', 'todo', 'high', 'sneha', 2, 10, 20, [3, 5]],
        ['Enquiry form → CRM webhook', 'todo', 'medium', 'vikram', 2, 14, 10, [5]], ['Content upload & SEO meta', 'todo', 'low', 'karan', 3, 24, 8], ['UAT with client', 'todo', 'high', 'rohan', 3, 27, 6, [6, 7]], ['Go-live & DNS cut-over', 'todo', 'urgent', 'vikram', 3, 30, 4, [10]]],
      'ssd' => [['Catalogue structure & variants', 'done', 'high', 'ananya', 0, -40, 10], ['Checkout UX flows', 'done', 'high', 'ananya', 0, -36, 12], ['Theme setup & design tokens', 'done', 'medium', 'sneha', 1, -20, 10], ['Product listing & quick view', 'review', 'high', 'sneha', 1, -2, 16, [2]],
        ['Subscription box logic', 'in_progress', 'urgent', 'vikram', 1, 1, 22], ['Cart & coupon engine', 'in_progress', 'high', 'vikram', 1, 5, 18, [3]], ['Razorpay integration', 'todo', 'urgent', 'vikram', 2, 12, 12, [5]], ['Shiprocket shipping integration', 'todo', 'high', 'vikram', 2, 15, 10, [5]],
        ['Order emails & WhatsApp updates', 'todo', 'medium', 'sneha', 2, 16, 8], ['Performance & Lighthouse pass', 'todo', 'medium', 'sneha', 2, 18, 6], ['Launch checklist', 'todo', 'high', 'aarav', 2, 20, 4, [6, 7]]],
      'gls' => [['Technical SEO audit', 'done', 'high', 'karan', 0, -95, 10], ['Fix crawl errors & sitemap', 'done', 'medium', 'karan', 0, -85, 6], ['Keyword research, winter range', 'done', 'medium', 'karan', 1, -20, 6], ['Blog: 10 winter vegetables to grow', 'review', 'medium', 'meera', 1, -1, 4, [2]],
        ['Blog: Organic farming myths', 'in_progress', 'low', 'meera', 1, 3, 4, [2]], ['Backlink outreach, 20 sites', 'in_progress', 'medium', 'karan', 1, 6, 8], ['October performance report', 'todo', 'medium', 'karan', 1, 2, 3]],
      'nla' => [['Prepare discovery workshop deck', 'todo', 'medium', 'ananya', 0, 10, 8], ['Competitor teardown, 5 apps', 'todo', 'low', 'ananya', 0, 12, 6], ['Draft technical architecture', 'todo', 'medium', 'vikram', 0, 14, 8, [0]]],
      'bpb' => [['Logo concepts (3 routes)', 'done', 'high', 'ananya', 0, -110, 20], ['Brand guidelines PDF', 'done', 'high', 'ananya', 0, -72, 16, [0]], ['Website copy refresh', 'done', 'medium', 'meera', 1, -55, 10], ['Social launch kit', 'done', 'medium', 'karan', 1, -40, 8, [1]], ['Stationery & pitch deck', 'done', 'low', 'ananya', 1, -38, 10, [1]]]];
    $tasks = []; $deps = []; $taskIds = [];
    foreach ($T as $k => $list) {
      $ids = [];
      foreach ($list as $pos => $t) {
        [$title, $st, $prio, $who, $msi, $due, $est] = $t;
        $ids[$pos] = $this->add('tasks', ['project_id' => $P[$k]['id'], 'parent_id' => null, 'milestone_id' => $M[$k][$msi] ?? null, 'title' => $title, 'description' => 'Demo task.', 'status' => $st, 'priority' => $prio, 'assignee_id' => $U[$who], 'start_date' => self::d($due - max(2, (int) ceil($est / 4))),
          'due_date' => self::d($due), 'estimated_hours' => $est, 'billable' => $P[$k]['billing'] === 'non_billable' ? 0 : 1, 'position' => $pos, 'completed_at' => $st === 'done' ? date('Y-m-d H:i:s', strtotime(($due - 1).' days 16:00')) : null, 'created_by' => $U['aarav'], 'created_at' => date('Y-m-d H:i:s', strtotime(($due - 20).' days')), 'updated_at' => $this->now]);
        $tasks[] = ['id' => $ids[$pos], 'project' => $k, 'user' => $who, 'status' => $st, 'title' => $title];
        foreach ($t[7] ?? [] as $dep) { if (isset($ids[$dep])) { $deps[] = ['task_id' => $ids[$pos], 'depends_on_id' => $ids[$dep]]; } } }
      $taskIds[$k] = $ids; }
    DB::insertMany('task_dependencies', $deps);
    $lines = ['Uploaded the first draft, feedback welcome.', 'Client asked for a darker hero image.', 'Blocked until API credentials arrive.', 'Looks good, moving to review.', 'Can we split this into two smaller tasks?', 'Pushed to staging: please check on mobile.', 'Fixed the spacing issues from review.', 'Discussed with Rohan, priority confirmed for this week.'];
    $comments = [];
    foreach ($tasks as $i => $t) {
      if ($t['status'] === 'todo' && $i % 3) { continue; }
      foreach (range(0, $i % 2) as $n) { $comments[] = ['task_id' => $t['id'], 'user_id' => $U[$n ? 'aarav' : $t['user']], 'body' => $lines[($i + $n * 3) % count($lines)], 'created_at' => date('Y-m-d H:i:s', strtotime('-'.(($i % 9) + 1 - $n).' days 15:'.str_pad((string) ($i * 7 % 60), 2, '0', STR_PAD_LEFT)))]; } }
    DB::insertMany('task_comments', $comments);
    $this->collect('task_comments', 'task_id', array_column($tasks, 'id'));
    $this->add('change_requests', ['project_id' => $P['zrw']['id'], 'title' => 'Add a blog section', 'description' => 'Client wants a news & insights blog for SEO.', 'requested_by' => 'Pooja Shah', 'requested_on' => self::d(-9), 'cost_impact' => 45000, 'hours_impact' => 30, 'deadline_impact_days' => 7, 'status' => 'approved', 'decided_by' => $U['aarav'], 'decided_at' => date('Y-m-d H:i:s', strtotime('-7 days')), 'created_by' => $U['rohan'], 'created_at' => date('Y-m-d H:i:s', strtotime('-9 days'))]);
    $this->add('change_requests', ['project_id' => $P['ssd']['id'], 'title' => 'Integrate Shiprocket instead of Delhivery', 'description' => 'Switch courier aggregator; includes COD reconciliation.', 'requested_by' => 'Manoj Kumar', 'requested_on' => self::d(-2), 'cost_impact' => 30000, 'hours_impact' => 16, 'deadline_impact_days' => 3, 'status' => 'pending', 'created_by' => $U['rohan'], 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
    return ['projects' => $P, 'tasks' => $tasks]; }

  /** Quotations and invoices with items, partial payments and overdue invoices. */
  private function finance(array $U, array $C, array $P): void {
    $tax = (float) Settings::get('crm.default_tax_rate', 18);
    $pid = static fn (?string $k) => $k && isset($P[$k]) ? $P[$k]['id'] : null;
    $mk = function (string $table, array $items) use ($tax): array {
      $rows = []; $sub = 0.0; $t = 0.0;
      foreach ($items as $i => [$desc, $q, $price]) { $amt = round($q * $price, 2); $sub += $amt; $t += $amt * $tax / 100; $rows[] = ['description' => $desc, 'quantity' => $q, 'unit_price' => $price, 'tax_rate' => $tax, 'amount' => $amt, 'position' => $i]; }
      return [$rows, ['subtotal' => round($sub, 2), 'tax_total' => round($t, 2), 'discount' => 0, 'total' => round($sub + $t, 2)]]; };
    $Q = [];
    if (Features::enabled('quotations') && self::has('quotations')) {
      $qs = [
        ['zenith', 'zrw', 'Website revamp, design & development', -52, 'accepted', [['UX research & sitemap', 1, 60000], ['UI design (12 templates)', 1, 140000], ['Development & CMS', 1, 220000], ['QA & launch support', 1, 30000]]],
        ['saffron', 'ssd', 'D2C store with subscriptions', -66, 'accepted', [['Storefront design', 1, 150000], ['Store development', 1, 330000], ['Payment & shipping integrations', 1, 120000]]],
        ['nimbus', 'nla', 'Learning app discovery workshop', -2, 'sent', [['Discovery workshop (2 days)', 16, 2000], ['Research & architecture report', 40, 2000]]],
        ['kaveri', null, 'Catalogue website & B2B enquiry portal', -40, 'draft', [['Catalogue website', 1, 180000], ['B2B enquiry portal', 1, 120000]]],
        ['zenith', null, 'Local SEO add-on (6 months)', -20, 'rejected', [['Local SEO retainer', 6, 25000]]]];
      foreach ($qs as $n => [$c, $p, $title, $at, $st, $items]) {
        [$rows, $tot] = $mk('quotation_items', $items);
        $Q[$n] = $this->add('quotations', ['client_id' => $C[$c][0], 'project_id' => $pid($p), 'number' => 'DEMO-QT-'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), 'title' => $title, 'issue_date' => self::d($at), 'valid_until' => self::d($at + 30), 'status' => $st, 'currency' => 'INR'] + $tot + ['notes' => 'Demo quotation.', 'terms' => (string) Settings::get('crm.quotation_terms', 'Valid for 30 days.'), 'created_by' => $U['rohan'], 'created_at' => date('Y-m-d H:i:s', strtotime("$at days")), 'updated_at' => $this->now]);
        DB::insertMany('quotation_items', array_map(static fn ($r) => ['quotation_id' => $Q[$n]] + $r, $rows)); }
      $this->collect('quotation_items', 'quotation_id', array_values($Q)); }
    if (!Features::enabled('invoices') || !self::has('invoices')) { return; }
    $inv = [
      ['zenith', 'zrw', 0, 'Website revamp, 40% advance', -50, 15, [['Advance: website revamp (40%)', 1, 180000]], [[1.0, -44, 'bank_transfer']]],
      ['zenith', 'zrw', null, 'Website revamp, design milestone', -12, 15, [['UI design milestone (30%)', 1, 135000]], [[0.5, -4, 'upi']]],
      ['saffron', 'ssd', 1, 'D2C store, 40% advance', -60, 10, [['Advance: D2C store (40%)', 1, 240000]], [[1.0, -55, 'bank_transfer']]],
      ['saffron', 'ssd', null, 'D2C store, UX & catalogue milestone', -8, 15, [['UX & catalogue milestone (20%)', 1, 120000]], []],
      ['greenleaf', 'gls', null, 'SEO retainer, August', -88, 15, [['Monthly SEO retainer', 1, 60000]], [[1.0, -75, 'upi']]],
      ['greenleaf', 'gls', null, 'SEO retainer, September', -58, 15, [['Monthly SEO retainer', 1, 60000]], [[0.4, -30, 'upi']]],
      ['greenleaf', 'gls', null, 'SEO retainer, October', -3, 15, [['Monthly SEO retainer', 1, 60000], ['Additional blog posts', 4, 3500]], []],
      ['bluepeak', 'bpb', null, 'Brand refresh, final invoice', -50, 15, [['Brand guidelines & collateral (final 50%)', 1, 125000]], []]];
    $I = []; $pays = [];
    foreach ($inv as $n => [$c, $p, $qi, $title, $at, $dueIn, $items, $pp]) {
      [$rows, $tot] = $mk('invoice_items', $items);
      $paid = round(array_sum(array_map(static fn ($x) => $tot['total'] * $x[0], $pp)), 2);
      $due = self::d($at + $dueIn);
      $status = $paid >= $tot['total'] ? 'paid' : ($due < date('Y-m-d') ? 'overdue' : ($paid > 0 ? 'partial' : 'sent'));
      $I[$n] = $this->add('invoices', ['client_id' => $C[$c][0], 'project_id' => $pid($p), 'quotation_id' => $qi !== null ? ($Q[$qi] ?? null) : null, 'number' => 'DEMO-INV-'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), 'title' => $title, 'issue_date' => self::d($at), 'due_date' => $due, 'status' => $status, 'currency' => 'INR']
        + $tot + ['amount_paid' => $paid, 'notes' => 'Demo invoice.', 'terms' => (string) Settings::get('crm.invoice_terms', 'Payment due within 15 days.'), 'created_by' => $U['rohan'], 'created_at' => date('Y-m-d H:i:s', strtotime("$at days")), 'updated_at' => $this->now]);
      DB::insertMany('invoice_items', array_map(static fn ($r) => ['invoice_id' => $I[$n]] + $r, $rows));
      foreach ($pp as $j => [$share, $on, $method]) { $pays[] = ['invoice_id' => $I[$n], 'client_id' => $C[$c][0], 'amount' => round($tot['total'] * $share, 2), 'paid_on' => self::d($on), 'method' => $method, 'reference' => 'DEMO-'.strtoupper(substr($method, 0, 3)).'-'.(1000 + $n * 10 + $j), 'note' => null, 'recorded_by' => $U['rohan'], 'created_at' => date('Y-m-d H:i:s', strtotime("$on days"))]; } }
    if (isset($Q[0], $I[0])) { DB::update('quotations', ['invoice_id' => $I[0]], 'id=?', [$Q[0]]); }
    if (isset($Q[1], $I[2])) { DB::update('quotations', ['invoice_id' => $I[2]], 'id=?', [$Q[1]]); }
    DB::insertMany('payments', $pays);
    $this->collect('invoice_items', 'invoice_id', array_values($I));
    $this->collect('payments', 'invoice_id', array_values($I)); }

  private function reports(array $U, array $P): void {
    $rows = [];
    $mon = date('Y-m-d', strtotime('monday this week'));
    foreach (['vikram' => 'ssd', 'sneha' => 'zrw', 'karan' => 'gls'] as $u => $p) {
      for ($i = 1; $i <= 4; $i++) {
        $day = self::d(-$i);
        if ((int) date('N', strtotime($day)) >= 6) { continue; }
        $rows[] = ['user_id' => $U[$u], 'type' => 'daily', 'period_start' => $day, 'period_end' => $day, 'project_id' => $P[$p]['id'] ?? null, 'summary' => 'Worked on '.($P[$p]['name'] ?? 'client work').'.', 'accomplishments' => 'Closed review comments; pushed changes to staging.', 'blockers' => $i === 2 ? 'Waiting for client assets.' : null,
          'next_steps' => 'Continue with the next task on the board.', 'hours' => 8, 'status' => $i > 2 ? 'approved' : 'submitted', 'reviewed_by' => $i > 2 ? $U['aarav'] : null, 'reviewed_at' => $i > 2 ? $this->now : null, 'review_note' => null, 'created_at' => $day.' 18:40:00']; } }
    foreach (['ananya' => 'zrw', 'rohan' => 'ssd'] as $u => $p) {
      foreach ([1, 2] as $w) {
        $start = self::d(-7 * $w, $mon);
        $rows[] = ['user_id' => $U[$u], 'type' => 'weekly', 'period_start' => $start, 'period_end' => self::d(4, $start), 'project_id' => $P[$p]['id'] ?? null, 'summary' => 'Weekly update for '.($P[$p]['name'] ?? 'projects').'.', 'accomplishments' => 'Milestone tasks moved to review; client feedback incorporated.', 'blockers' => null,
          'next_steps' => 'Plan next sprint with the team.', 'hours' => 40, 'status' => $w === 2 ? 'approved' : 'submitted', 'reviewed_by' => $w === 2 ? $U['aarav'] : null, 'reviewed_at' => $w === 2 ? $this->now : null, 'review_note' => $w === 2 ? 'Great progress.' : null, 'created_at' => self::d(4, $start).' 19:00:00']; } }
    DB::insertMany('work_reports', $rows); }

  /** Attendance and time entries for the last 3 weeks; older weeks approved, last week submitted, this week draft. */
  private function attendance(array $U, array $P, array $holidays): void {
    $offs = ['vikram' => [6, 7], 'karan' => [7], 'meera' => [6, 7]];
    $company = array_map('intval', array_filter(explode(',', (string) Settings::get('general.weekend_days', '6,7'))));
    $hours = ['ananya' => 9, 'sneha' => 9, 'meera' => 6];
    $leave = self::has('leave_requests') ? DB::all("SELECT user_id, start_date, end_date FROM leave_requests WHERE status='approved' AND user_id IN ".DB::in(array_values($U)), array_values($U)) : [];
    $onLeave = static function (int $uid, string $d) use ($leave): bool { foreach ($leave as $l) { if ((int) $l['user_id'] === $uid && $l['start_date'] <= $d && $l['end_date'] >= $d) { return true; } } return false; };
    $byUser = [];
    foreach ($P['tasks'] as $t) { if (isset($P['projects'][$t['project']])) { $byUser[$t['user']][] = $t; } }
    $mon = date('Y-m-d', strtotime('monday this week'));
    $hasTs = self::has('timesheets'); $hasTe = self::has('time_entries');
    $hasDiscard = $hasTe && (bool) DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='discarded_seconds'");
    $ts = [];
    foreach ($U as $k => $uid) {
      if ($hasTs) {
        foreach ([2, 1, 0] as $w) {
          $ws = self::d(-7 * $w, $mon);
          $rej = $w === 1 && $k === 'meera';
          $st = $rej ? 'rejected' : 'approved';
          $ts[$k][$ws] = $this->add('timesheets', ['user_id' => $uid, 'week_start' => $ws, 'total_seconds' => 0, 'billable_seconds' => 0, 'status' => $st, 'submitted_at' => self::d(5, $ws).' 18:00:00', 'reviewed_by' => $rej ? $U['aarav'] : null, 'reviewed_at' => $rej ? self::d(7, $ws).' 10:00:00' : null, 'review_note' => $rej ? 'Please split Friday into the correct projects and resubmit.' : null]); } } }
    $att = []; $entries = []; $tot = [];
    $today = date('Y-m-d'); $live = (int) date('G') >= 10;
    for ($i = 21; $i >= 0; $i--) {
      $day = self::d(-$i);
      if ($i === 0 && !$live) { break; }
      $dow = (int) date('N', strtotime($day));
      $week = date('Y-m-d', strtotime('monday this week', strtotime($day)));
      foreach ($U as $k => $uid) {
        if (in_array($dow, $offs[$k] ?? $company, true) || isset($holidays[$day]) || $onLeave($uid, $day)) { continue; }
        if (mt_rand(1, 100) <= 4 && $i > 0) { continue; }
        $h = $hours[$k] ?? 8;
        $in = strtotime("$day 09:".str_pad((string) mt_rand(5, 50), 2, '0', STR_PAD_LEFT));
        $break = mt_rand(35, 60) * 60;
        $half = mt_rand(1, 100) <= 4;
        $work = (int) (($half ? $h / 2 : $h) * 3600 + mt_rand(-20, 35) * 60);
        $out = $in + $work + $break;
        $open = $day === $today && in_array($k, ['ananya', 'vikram', 'sneha', 'karan'], true);
        if ($open) { $out = min($out, time()); $work = max(0, $out - $in - $break); }
        $att[] = ['user_id' => $uid, 'work_date' => $day, 'clock_in' => date('Y-m-d H:i:s', $in), 'clock_out' => $open ? null : date('Y-m-d H:i:s', $out), 'break_seconds' => $break, 'worked_seconds' => $open ? 0 : $work, 'status' => $half ? 'half_day' : 'present', 'note' => null, 'ip_in' => '10.0.0.'.(10 + $i), 'ip_out' => $open ? null : '10.0.0.'.(10 + $i), 'edited_by' => null];
        if (!$hasTe || empty($byUser[$k]) || $open) { continue; }
        $mine = $byUser[$k];
        $n = min(3, count($mine)); $start = $in; $left = $work;
        for ($j = 0; $j < $n && $left > 900; $j++) {
          $t = $mine[($i + $j) % count($mine)];
          $dur = $j === $n - 1 ? $left : (int) ($left * mt_rand(30, 55) / 100);
          $wk = $week; $tsId = $ts[$k][$wk] ?? null;
          $status = $wk === self::d(-7, $mon) && $k === 'meera' ? 'rejected' : 'approved';
          $row = ['user_id' => $uid, 'project_id' => $P['projects'][$t['project']]['id'], 'task_id' => $t['id'], 'description' => $t['title'], 'started_at' => date('Y-m-d H:i:s', $start), 'ended_at' => date('Y-m-d H:i:s', $start + $dur), 'duration_seconds' => $dur, 'idle_seconds' => mt_rand(0, 6) * 60,
            'last_heartbeat_at' => date('Y-m-d H:i:s', $start + $dur), 'source' => $j % 2 ? 'manual' : 'timer', 'billable' => 1, 'is_running' => 0, 'status' => $status, 'timesheet_id' => $tsId, 'created_at' => date('Y-m-d H:i:s', $start)];
          if ($hasDiscard) { $row['discarded_seconds'] = 0; }
          $entries[] = $row;
          if ($tsId) { $tot[$tsId] = ($tot[$tsId] ?? 0) + $dur; }
          $start += $dur + ($j === 0 ? $break : 300); $left -= $dur; } } }
    DB::insertMany('attendance', $att);
    if ($entries) { DB::insertMany('time_entries', $entries); }
    foreach ($tot as $id => $s) { DB::update('timesheets', ['total_seconds' => $s, 'billable_seconds' => $s], 'id=?', [$id]); } }

  /** Monthly + hourly compensation, personal pay components and a draft run for last month (skipped if one exists). */
  private function payroll(array $U): void {
    $pay = ['aarav' => 180000, 'priya' => 95000, 'rohan' => 120000, 'ananya' => 85000, 'vikram' => 110000, 'sneha' => 75000, 'karan' => 60000, 'meera' => 650];
    $comps = [];
    foreach ($pay as $k => $amt) {
      $comps[$k] = ['user_id' => $U[$k], 'pay_type' => $k === 'meera' ? 'hourly' : 'monthly', 'amount' => $amt, 'effective_from' => self::d(-150), 'note' => 'Demo compensation', 'created_by' => $U['priya'], 'created_at' => $this->now];
      $comps[$k]['id'] = $this->add('compensations', $comps[$k]); }
    $pc = [];
    if (self::has('pay_components')) {
      foreach ($U as $k => $uid) {
        if ($k === 'meera') { continue; }
        foreach ([['House Rent Allowance', 'allowance', 'percent', 20], ['Internet allowance', 'allowance', 'fixed', 1500], ['Provident Fund', 'deduction', 'percent', 12], ['Professional Tax', 'deduction', 'fixed', 200]] as [$n, $kind, $calc, $v]) {
          $row = ['user_id' => $uid, 'name' => $n, 'kind' => $kind, 'calc' => $calc, 'value' => $v, 'active' => 1, 'created_at' => $this->now];
          $row['id'] = $this->add('pay_components', $row);
          $pc[$k][] = $row; } } }
    $period = date('Y-m-01', strtotime('first day of last month'));
    if (!self::has('payroll_runs') || DB::value('SELECT 1 FROM payroll_runs WHERE period_month=?', [$period])) { return; }
    $svc = 'Modules\\Payroll\\Services\\PayrollService';
    $to = date('Y-m-t', strtotime($period));
    $wd = class_exists($svc) && method_exists($svc, 'workingDays') ? (float) $svc::workingDays($period, $to) : 22.0;
    $run = $this->add('payroll_runs', ['period_month' => $period, 'status' => 'draft', 'working_days' => $wd, 'total_net' => 0, 'notes' => 'Demo payroll run, regenerate after real compensation is added.', 'created_by' => $U['priya'], 'created_at' => $this->now]);
    if (!class_exists($svc) || !method_exists($svc, 'compute') || !self::has('payslips')) { return; }
    $total = 0.0;
    foreach ($comps as $k => $c) {
      $s = $svc::compute($c, $wd, $k === 'karan' ? 1.0 : 0.0, $k === 'meera' ? 92.5 : 0.0, $pc[$k] ?? [], 0.0);
      $this->add('payslips', ['run_id' => $run, 'user_id' => $c['user_id'], 'pay_type' => $s['pay_type'], 'rate' => $s['rate'], 'working_days' => $s['working_days'], 'paid_days' => $s['paid_days'], 'unpaid_leave_days' => $s['unpaid_leave_days'], 'worked_hours' => $s['worked_hours'], 'base_pay' => $s['base_pay'],
        'earnings' => json_encode($s['earnings'], JSON_UNESCAPED_UNICODE), 'deductions' => json_encode($s['deductions'], JSON_UNESCAPED_UNICODE), 'reimbursement_total' => $s['reimbursement_total'], 'gross' => $s['gross'], 'total_deductions' => $s['total_deductions'], 'net' => $s['net'], 'created_at' => $this->now]);
      $total += $s['net']; }
    DB::update('payroll_runs', ['total_net' => round($total, 2)], 'id=?', [$run]); }

  private function reimbursements(array $U): void {
    $rows = [['vikram', 'Cab to Saffron Spice office', 840, -6, 'pending', null], ['rohan', 'Client lunch, Zenith Realty', 3250, -18, 'approved', 'priya'], ['ananya', 'Figma professional (annual)', 12000, -40, 'paid', 'priya'],
      ['karan', 'SEO tool subscription (Ahrefs Lite)', 8300, -10, 'pending', null], ['sneha', 'Mobile testing devices rental', 4500, -25, 'rejected', 'priya'], ['meera', 'Co-working day pass', 600, -3, 'pending', null]];
    foreach ($rows as [$u, $t, $amt, $at, $st, $rev]) {
      $this->add('reimbursements', ['user_id' => $U[$u], 'title' => $t, 'amount' => $amt, 'expense_date' => self::d($at), 'description' => 'Demo claim.', 'status' => $st, 'payslip_id' => null, 'reviewed_by' => $rev ? $U[$rev] : null, 'reviewed_at' => $rev ? date('Y-m-d H:i:s', strtotime(($at + 2).' days')) : null,
        'review_note' => $st === 'rejected' ? 'Please use the company devices instead.' : null, 'created_at' => date('Y-m-d H:i:s', strtotime(($at + 1).' days'))]); } }

  private function notifications(array $U): void {
    /* Each sample notification is created only when the page it links to is switched on. */
    $n = [['aarav', 'Timesheets approved automatically', 'Last week\'s timesheets were approved automatically. Reject any that need changes.', '/timesheets/approvals', 'timer', null], ['aarav', 'Leave request from Vikram Singh', 'Optional holiday on Guru Nanak Jayanti.', '/hr/leave/approvals', 'umbrella', null],
      ['rohan', '1 overdue invoice needs attention', 'BluePeak Fintech, Brand refresh final invoice.', '/invoices?status=overdue', 'receipt', null], ['ananya', 'New comment on "Homepage visual design"', 'Aarav: Looks good, moving to review.', '/tasks', 'message', -1],
      ['vikram', 'Task due tomorrow: Subscription box logic', 'Saffron Spice D2C Store', '/tasks', 'clock', null], ['priya', 'Reimbursement claims pending', '3 claims are waiting for review.', '/reimbursements', 'wallet', null],
      ['karan', 'Your leave was approved', 'Personal work, approved by Rohan Kapoor.', '/hr/leave', 'check', -30], ['sneha', 'Please acknowledge: Leave & Holiday Policy', 'A company document needs your acknowledgement.', '/hr/me', 'book', null]];
    foreach ($n as $i => [$u, $title, $body, $url, $icon, $read]) {
      if (!route_on($url) || ($icon === 'book' && !Features::enabled('company_docs'))) { continue; }
      $this->add('notifications', ['user_id' => $U[$u], 'title' => $title, 'body' => $body, 'url' => $url, 'icon' => $icon, 'read_at' => $read === null ? null : date('Y-m-d H:i:s', strtotime("$read days")), 'created_at' => date('Y-m-d H:i:s', strtotime('-'.($i * 3 + 1).' hours'))]); }
    $this->add('notifications', ['user_id' => $this->by, 'title' => 'Demo data is ready', 'body' => 'Sign in as any demo user with the shared password, or explore as yourself. Remove it from Settings › Demo data.', 'url' => '/admin/settings/demo', 'icon' => 'sparkles', 'read_at' => null, 'created_at' => $this->now]); } }
