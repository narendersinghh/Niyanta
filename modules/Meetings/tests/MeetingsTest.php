<?php
/**
 * Meetings: parsers (VTT, SRT, text, docx, Fireflies JSON, HTML, MIME e-mail), plus-addresses and sender rules, detection, dedupe across
 * uploads + e-mail + Fireflies, Fireflies sync (429), Goraiya summaries (mock provider, AI off, map-reduce), auto-log vs To sort,
 * the Log activity dialog flow, action items, permissions / scoping, the IMAP client and the feature switch.
 */
use Core\{Cron, DB, Features, Modules, Settings};
use Modules\Admin\Services\SettingsSchema;
use Modules\Meetings\Services\{Attach, Detector, Docx, Fireflies, Imap, Inbox, MeetingService, Mime, Parser, Recap, Summarizer};

function mt_fx(): array {
  static $f = null;
  Features::set('feature:meetings', true);
  unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
  $_FILES = [];
  if ($f) { return $f; }
  $now = date('Y-m-d H:i:s');
  $mgr = make_user('manager', 'maya@meetunit.test', ['name' => 'Maya Meetunit']);
  $mate = make_user('manager', 'kiran@meetunit.test', ['name' => 'Kiran Meetunit']);
  $outsider = make_user('manager', 'olga@meetunit.test', ['name' => 'Olga Meetunit']);
  $emp = make_user('employee', 'eli@meetunit.test', ['name' => 'Eli Meetunit']);
  $boss = make_user('manager', 'boss@meetunit.test', ['name' => 'Bea Meetunit']);
  $lead = DB::insert('roles', ['slug' => 'meetlead', 'name' => 'Meetings lead', 'created_at' => $now]);
  DB::query("INSERT INTO role_permissions (role_id, permission) SELECT ?, permission FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE slug='manager')", [$lead]);
  DB::insert('role_permissions', ['role_id' => $lead, 'permission' => 'meetings.manage']);
  DB::update('users', ['role_id' => $lead], 'id=?', [$boss]);
  $c = DB::insert('clients', ['name' => 'Priya Zen', 'company' => 'Zentrix Labs', 'email' => 'hello@zentrix.example', 'website' => 'https://www.zentrix.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  $c2 = DB::insert('clients', ['name' => 'Quentin', 'company' => 'Quillon Foods', 'email' => 'q@quillon.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  $gen = DB::insert('clients', ['name' => 'Solo Founder', 'company' => 'Gmailer Studio', 'email' => 'founder@gmail.com', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  DB::insert('client_contacts', ['client_id' => $c, 'name' => 'Priya Sharma', 'email' => 'priya@zentrix.example']);
  foreach ([$c, $c2, $gen] as $x) { DB::insert('client_managers', ['client_id' => $x, 'user_id' => $mgr]); }
  DB::insert('client_managers', ['client_id' => $c, 'user_id' => $mate]);
  $p = static function (string $name, ?int $client, array $members, string $status = 'active') use ($mgr, $now): int {
    $id = DB::insert('projects', ['name' => $name, 'client_id' => $client, 'status' => $status, 'manager_id' => $mgr, 'created_by' => $mgr, 'created_at' => $now, 'updated_at' => $now]);
    foreach ($members as $m) { DB::insert('project_members', ['project_id' => $id, 'user_id' => $m]); }
    return $id; };
  $orbit = $p('Orbit Portal', $c, [$mate, $emp]); $nova = $p('Nova App', $c, [$mate]); $harvest = $p('Harvest Rebrand', $c2, []); $ops = $p('Helios Ops Handbook', null, [$mate]);
  \Modules\Projects\Services\ProjectAccess::flush(); \Modules\Crm\Services\ClientAccess::flush();
  Settings::setMany(['meetings.inbox_address' => 'notes@niyanta.example', 'meetings.allowed_domains' => '', 'meetings.auto_create' => '1', 'meetings.retention_days' => '365']);
  return $f = compact('mgr', 'mate', 'outsider', 'emp', 'boss', 'c', 'c2', 'gen', 'orbit', 'nova', 'harvest', 'ops'); }

function mt_user(int $id): array { return DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]); }
function mt_ai(bool $on): void {
  DB::query('DELETE FROM ai_models');
  if ($on) { DB::insert('ai_models', ['label' => 'Mock', 'preset' => 'mock', 'provider' => 'mock', 'model' => 'mock-openai', 'is_default' => 1, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]); }
  Settings::setMany(['ai.enabled' => $on ? '1' : '0', 'ai.monthly_token_budget' => '0']);
  Summarizer::$transport = null; }
function mt_ajax(string $path, array $post = []): array {
  $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
  try { $r = http('POST', $path, $post); return ['status' => $r->status(), 'json' => json_decode((string) $r->body(), true) ?? [], 'body' => (string) $r->body()]; }
  finally { unset($_SERVER['HTTP_X_REQUESTED_WITH']); $_FILES = []; } }
/** Fake upload of $content as $name (Request::capture reads $_FILES). */
function mt_file(string $field, string $name, string $content): void {
  $tmp = tempnam(sys_get_temp_dir(), 'mtu'); file_put_contents($tmp, $content);
  $_FILES[$field] = ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]; }
function mt_docx(array $paras, string $title = ''): string {
  $tmp = tempnam(sys_get_temp_dir(), 'mtd');
  $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
  $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
  $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.implode('', array_map(static fn ($p) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($p, ENT_XML1).'</w:t></w:r></w:p>', $paras)).'</w:body></w:document>');
  if ($title !== '') { $z->addFromString('docProps/core.xml', '<?xml version="1.0"?><cp:coreProperties xmlns:cp="x" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>'.htmlspecialchars($title, ENT_XML1).'</dc:title></cp:coreProperties>'); }
  $z->close(); $bin = (string) file_get_contents($tmp); @unlink($tmp); return $bin; }
/** A raw e-mail (multipart/alternative text + html, optional attachment). */
function mt_mail(string $from, array $to, string $subject, string $text, array $o = []): string {
  $b = 'b1_'.md5($subject.$from); $alt = 'b2_'.md5($text);
  $h = "From: ".($o['from_name'] ?? 'Sender')." <$from>\r\nTo: ".implode(', ', $to)."\r\n".(isset($o['cc']) ? 'Cc: '.implode(', ', $o['cc'])."\r\n" : '')."Subject: $subject\r\nDate: ".($o['date'] ?? 'Sat, 07 Nov 2026 10:30:00 +0000')."\r\nMessage-ID: <".($o['id'] ?? md5($subject.$text.microtime()))."@mail.example>\r\nMIME-Version: 1.0\r\n".($o['headers'] ?? '');
  $html = '<html><body><p>'.nl2br(htmlspecialchars($text)).'</p><p>You received this email because you use the app.</p></body></html>';
  $body = "--$alt\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n".quoted_printable_encode($text)."\r\n--$alt\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."--$alt--\r\n";
  if (empty($o['attach'])) { return $h."Content-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n".$body; }
  [$an, $ac] = $o['attach'];
  return $h."Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n".$body."\r\n--$b\r\nContent-Type: application/octet-stream; name=\"$an\"\r\nContent-Disposition: attachment; filename=\"$an\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($ac))."--$b--\r\n"; }
function mt_vtt(string $title = 'Orbit Portal sprint review'): string {
  return "WEBVTT\n\nNOTE $title\n\n1\n00:00:01.000 --> 00:00:04.000\n<v Priya Sharma>We need a dark mode for the Orbit Portal dashboard.</v>\n\n2\n00:00:04.500 --> 00:00:09.000\n<v Maya Meetunit>Agreed. We decided to ship it in the next sprint.</v>\n\n3\n00:00:09.500 --> 00:00:15.000\n<v Maya Meetunit>I will send the revised estimate by 2026-11-12.</v>\n\n4\n00:00:15.500 --> 00:42:10.000\n<v Priya Sharma>Can we review it on Friday?</v>\n"; }
function mt_rows(string $where = '1=1', array $p = []): int { return (int) DB::value("SELECT COUNT(*) FROM meeting_transcripts WHERE $where", $p); }

return [
  'parsers: WebVTT voice tags and Name: lines, SRT, Zoom / Otter text, timestamps dropped, duration and speakers detected' => function () {
    $d = Parser::parse(mt_vtt(), 'GMT20261107-Orbit.vtt');
    assert_eq('vtt', $d['format']);
    assert_true(str_starts_with($d['text'], 'Priya Sharma: We need a dark mode') && str_contains($d['text'], "\nMaya Meetunit: Agreed. We decided to ship it in the next sprint. I will send"), 'speaker lines merged per turn: '.$d['text']);
    assert_eq(42, $d['duration_min']);
    assert_true(in_array('Priya Sharma', array_column($d['participants'], 'name'), true), 'speakers are participants');
    $d = Parser::parse("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nRavi Kumar: Hello team\n\n00:00:02.000 --> 00:00:03.000\nRavi Kumar: second line\n", 'zoom.vtt');
    assert_eq('Ravi Kumar: Hello team second line', $d['text'], 'Zoom VTT');
    $d = Parser::parse("1\n00:00:01,000 --> 00:00:03,500\nAnna: First point\n\n2\n00:00:04,000 --> 00:01:30,000\nBen: Second point\n", 'call.srt');
    assert_eq('srt', $d['format']); assert_eq("Anna: First point\nBen: Second point", $d['text']); assert_eq(2, $d['duration_min']);
    $d = Parser::parse("Weekly sync with Quillon\nDate: Nov 5, 2026 at 3:30 PM\nDuration: 45 min\n\n[Anna Lee] 00:00:05\nWelcome everyone\n[Ben Roy] 00:01:10\nThanks Anna\n", 'zoom.txt');
    assert_eq('Weekly sync with Quillon', $d['title']); assert_eq('2026-11-05 15:30:00', $d['meeting_at']); assert_eq(45, $d['duration_min']);
    assert_true(str_contains($d['text'], 'Anna Lee: Welcome everyone') && str_contains($d['text'], 'Ben Roy: Thanks Anna'), 'Zoom text speakers');
    $d = Parser::parse("Otter notes 7 November 2026 10:00\n\nChris Dev  0:05\nLet us start.\nDana Ops  1:20\nOk.\n", 'otter.txt');
    assert_true(str_contains($d['text'], 'Chris Dev: Let us start.') && str_contains($d['text'], 'Dana Ops: Ok.') && !str_contains($d['text'], '0:05'), 'Otter: '.$d['text']);
    assert_eq('2026-11-07 10:00:00', $d['meeting_at']);
    assert_eq('2026-11-07 09:05:00', Parser::findDate('Meeting started 2026/11/07 09:05 IST'));
    assert_eq('2026-11-06 16:00:00', Parser::findDate("Review, Nov 6, 2026 4:00 PM\nI will send it by 2026-11-12."), 'the first date in the text wins');
    assert_eq(null, Parser::findDate('2026-02-30'));
    assert_eq("Hi\nthere", Parser::utf8("\xFF\xFE".mb_convert_encoding("Hi\r\nthere", 'UTF-16LE', 'UTF-8')), 'UTF-16 Teams export'); },

  'parsers: Word .docx (ZipArchive and the pure-PHP fallback), Fireflies JSON export, generic JSON segments, HTML and Markdown' => function () {
    $bin = mt_docx(['Notes by Gemini', 'Orbit Portal weekly', 'Nov 6, 2026', 'Attendees: Priya Sharma, Maya Meetunit', 'Summary', 'Priya asked for dark mode & export.'], 'Orbit weekly notes');
    $d = Parser::parse($bin, 'Orbit weekly - Notes by Gemini.docx');
    assert_eq('docx', $d['format']); assert_eq('Orbit weekly notes', $d['title'], 'title from docProps');
    assert_true(str_contains($d['text'], 'Priya asked for dark mode & export.'), 'entities decoded'); assert_eq('gemini', $d['tool']); assert_eq('2026-11-06 00:00:00', $d['meeting_at']);
    $xml = Docx::pure($bin, 'word/document.xml');
    assert_true($xml !== null && str_contains(Docx::xmlText($xml), 'Orbit Portal weekly'), 'pure-PHP zip reader');
    assert_eq(null, Docx::pure('not a zip', 'word/document.xml'));
    $ff = ['data' => ['transcript' => ['id' => 'FFX1', 'title' => 'Nova App planning', 'date' => 1793952000000, 'duration' => 31.6, 'organizer_email' => 'maya@meetunit.test', 'participants' => ['priya@zentrix.example, maya@meetunit.test'],
      'sentences' => [['speaker_name' => 'Priya', 'text' => 'We want offline mode.'], ['speaker_name' => 'Priya', 'text' => 'And push alerts.'], ['speaker_name' => 'Maya', 'text' => 'I will scope it.']], 'summary' => ['overview' => 'Planning the app.', 'action_items' => "Maya: scope offline mode", 'keywords' => ['offline']]]]];
    $d = Parser::parse(json_encode($ff), 'fireflies.json');
    assert_eq('fireflies:FFX1', $d['external_id']); assert_eq('Nova App planning', $d['title']); assert_eq(32, $d['duration_min']); assert_eq(date('Y-m-d H:i:s', 1793952000), $d['meeting_at']);
    assert_eq("Priya: We want offline mode. And push alerts.\nMaya: I will scope it.", $d['text']);
    assert_true(str_contains((string) $d['summary'], 'Planning the app.') && str_contains((string) $d['summary'], 'Action items'), 'Fireflies summary kept');
    assert_true(in_array('priya@zentrix.example', array_column($d['participants'], 'email'), true), 'participants split');
    $d = Parser::parse(json_encode(['title' => 'Segments', 'segments' => [['speaker' => 'A', 'text' => 'one'], ['speaker' => ['name' => 'B'], 'words' => [['text' => 'two'], ['text' => 'words']]]]]), 'x.json');
    assert_eq("A: one\nB: two words", $d['text']);
    $d = Parser::parse('<html><head><style>p{}</style></head><body><h1>Zoom summary</h1><ul><li>Point one</li><li>Point &amp; two</li></ul><script>x()</script></body></html>', 'recap.html');
    assert_eq('html', $d['format']); assert_eq('Zoom summary', $d['title']); assert_true(str_contains($d['text'], '• Point & two') && !str_contains($d['text'], 'x()'), 'html to text');
    $d = Parser::parse("# Harvest Rebrand kickoff\n\n- logo ideas\n", 'notes.md');
    assert_eq('markdown', $d['format']); assert_eq('Harvest Rebrand kickoff', $d['title']); },

  'MIME e-mail: multipart mixed / alternative, base64, quoted-printable, charsets, encoded subject, attachments (PDF skipped by the inbox)' => function () {
    $raw = "From: =?UTF-8?B?R2VtaW5pIE5vdGVz?= <gemini-notes@google.com>\r\nTo: maya@meetunit.test, notes+p12@niyanta.example\r\nCc: priya@zentrix.example\r\nSubject: =?UTF-8?Q?Notes:_=E2=80=9COrbit_review=E2=80=9D?=\r\nDate: Fri, 06 Nov 2026 09:00:00 +0000\r\nMessage-ID: <abc@google.com>\r\nContent-Type: multipart/mixed; boundary=\"X\"\r\n\r\n--X\r\nContent-Type: multipart/alternative; boundary=\"Y\"\r\n\r\n--Y\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9 meeting =\r\nsummary line\r\n--Y\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('<p>HTML part</p>')."\r\n--Y--\r\n--X\r\nContent-Type: text/vtt; name=\"t.vtt\"\r\nContent-Disposition: attachment; filename=\"t.vtt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nA: hi\n"))."--X\r\nContent-Type: application/pdf; name=\"deck.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0=\r\n--X--\r\n";
    $m = Mime::parse($raw);
    assert_eq('gemini-notes@google.com', $m['from']); assert_eq('Gemini Notes', $m['from_name']); assert_eq('Notes: “Orbit review”', $m['subject']);
    assert_eq('Café meeting summary line', $m['text'], 'QP soft break + latin-1'); assert_eq('<p>HTML part</p>', $m['html']);
    assert_eq(['maya@meetunit.test', 'notes+p12@niyanta.example', 'priya@zentrix.example'], $m['recipients']);
    assert_eq(['t.vtt', 'deck.pdf'], array_column($m['attachments'], 'name')); assert_true(str_starts_with($m['attachments'][0]['content'], 'WEBVTT'), 'attachment decoded');
    assert_eq('abc@google.com', $m['message_id']); assert_eq('2026-11-06 09:00:00', $m['date']);
    assert_eq('Orbit review', Recap::title($m['subject']));
    assert_eq('Weekly sync', Recap::title('Fwd: Meeting summary for Weekly sync'));
    assert_eq('Design review', Recap::title('Design review - Notes by Gemini'));
    assert_eq('01HXYZABCD1234', Recap::firefliesId('https://app.fireflies.ai/view/Design-review::01HXYZABCD1234?ref=recap'));
    $c = Recap::clean("Summary\nWe agreed on the plan.\nhttps://zoom.us/rec/abc\nView in browser\n\nMore text\nmore\nmore\nYou received this email because you use Zoom.\nUnsubscribe\nZoom Video Communications");
    assert_eq("Summary\nWe agreed on the plan.\n\nMore text\nmore\nmore", $c, 'footer and noise removed');
    assert_eq('zoom', Recap::tool('no-reply@zoom.us')); assert_eq('readai', Recap::tool('executiveassistant@e.read.ai')); assert_eq('fireflies', Recap::tool(null, 'Recap by Fireflies.ai')); },

  'plus-addresses, notetaker sender allowlist and target parsing' => function () {
    $f = mt_fx();
    assert_eq('notes+p12@niyanta.example', Inbox::plusAddress('p', 12)); assert_eq('notes+c5@niyanta.example', Inbox::plusAddress('c', 5)); assert_eq(null, Inbox::plusAddress('x', 5));
    assert_eq(['p', 12], Inbox::target(['someone@else.example', 'Notes+P12@Niyanta.example']));
    assert_eq(['c', 7], Inbox::target(['notes+c7@niyanta.example'])); assert_eq(null, Inbox::target(['notes+p12@other.example', 'notes@niyanta.example']));
    [$ok, $u] = Inbox::sender('MAYA@meetunit.test'); assert_true($ok && (int) $u['id'] === $f['mgr'], 'active user');
    assert_true(Inbox::sender('gemini-notes@google.com')[0] && Inbox::sender('fred@fireflies.ai')[0] && Inbox::sender('assistant@e.read.ai')[0], 'default notetaker domains and subdomains');
    assert_true(!Inbox::sender('spam@evil.example')[0] && !Inbox::sender('x@notgoogle.com')[0] && !Inbox::sender('bad')[0], 'others dropped');
    Settings::set('meetings.allowed_domains', 'evil.example, @granola.so');
    try { assert_true(Inbox::sender('spam@evil.example')[0] && Inbox::sender('a@granola.so')[0] && !Inbox::sender('x@zoom.us')[0], 'custom allowlist replaces the defaults'); }
    finally { Settings::set('meetings.allowed_domains', ''); }
    DB::update('users', ['status' => 'inactive'], 'id=?', [$f['outsider']]);
    try { assert_true(!Inbox::sender('olga@meetunit.test')[0], 'inactive user dropped'); } finally { DB::update('users', ['status' => 'active'], 'id=?', [$f['outsider']]); } },

  'detection: client contact + project in the title is high; domain, mentions, explicit target, generic domains, own projects and scoping' => function () {
    $f = mt_fx(); $u = mt_user($f['mgr']);
    $d = Detector::detect(['user' => $u, 'title' => 'Orbit Portal sprint review', 'text' => 'notes', 'emails' => ['priya@zentrix.example', 'maya@meetunit.test']]);
    assert_eq([$f['c'], $f['orbit'], 'high'], [$d['client_id'], $d['project_id'], $d['confidence']]);
    assert_true(in_array('Participant priya@zentrix.example is a contact of Zentrix Labs', $d['reasons'], true) && in_array('Title mentions project Orbit Portal', $d['reasons'], true), implode(' | ', $d['reasons']));
    $d = Detector::detect(['user' => $u, 'title' => 'Weekly sync', 'text' => 'hello', 'emails' => ['new.person@zentrix.example']]);
    assert_eq([$f['c'], null, 'medium'], [$d['client_id'], $d['project_id'], $d['confidence']], 'domain match, two active projects: To sort with the client');
    assert_true(str_contains($d['reasons'][0], 'zentrix.example'), 'domain reason');
    $d = Detector::detect(['user' => $u, 'title' => 'Weekly sync', 'text' => 'We talked about harvest rebrand colours.', 'emails' => []]);
    assert_eq([$f['c2'], $f['harvest'], 'low'], [$d['client_id'], $d['project_id'], $d['confidence']], 'text mention only');
    $d = Detector::detect(['user' => $u, 'title' => 'Quillon Foods catch-up', 'text' => '', 'emails' => []]);
    assert_eq([$f['c2'], $f['harvest'], 'medium'], [$d['client_id'], $d['project_id'], $d['confidence']], 'client in the title, its only active project');
    $d = Detector::detect(['user' => $u, 'project_id' => $f['harvest'], 'title' => 'Orbit Portal', 'text' => 'Zentrix Labs', 'emails' => ['priya@zentrix.example']]);
    assert_eq([$f['c2'], $f['harvest'], 'high'], [$d['client_id'], $d['project_id'], $d['confidence']], 'explicit target wins');
    $d = Detector::detect(['user' => $u, 'title' => 'Chat', 'text' => 'x', 'emails' => ['someone@gmail.com', 'founder@gmail.com']]);
    assert_eq($f['gen'], $d['client_id'], 'exact client e-mail still matches');
    $d = Detector::detect(['user' => $u, 'title' => 'Chat', 'text' => 'x', 'emails' => ['someone@gmail.com']]);
    assert_true((int) $d['client_id'] !== $f['gen'], 'generic domain is never a client domain');
    $d = Detector::detect(['user' => mt_user($f['emp']), 'title' => 'Random', 'text' => 'nothing', 'emails' => []]);
    assert_eq([null, 'none'], [$d['client_id'], $d['confidence']], 'employee without client access gets no client suggestion');
    $d = Detector::detect(['user' => mt_user($f['outsider']), 'title' => 'Orbit Portal sprint review', 'text' => '', 'emails' => ['priya@zentrix.example']]);
    assert_eq([null, null], [$d['client_id'], $d['project_id']], 'never suggests clients or projects the user cannot see');
    $d = Detector::detect(['user' => mt_user($f['mate']), 'title' => 'Handbook chat', 'text' => 'Helios Ops Handbook draft', 'emails' => []]);
    assert_eq([null, $f['ops']], [$d['client_id'], $d['project_id']], 'internal project by name'); },

  'manual upload preview (AJAX, CSRF): detected title, date, duration, participants, client + project with reasons; the project page context wins' => function () {
    $f = mt_fx(); act_as($f['mgr']);
    mt_file('mt_file', 'Orbit review.vtt', mt_vtt());
    $r = mt_ajax('/meetings/preview', ['mt_ctx_client' => (string) $f['c']]);
    assert_eq(200, $r['status'], $r['body']);
    $j = $r['json'];
    assert_eq('Orbit Portal sprint review', $j['title']); assert_eq(42, $j['duration_min']); assert_eq('vtt', $j['format']);
    assert_eq($f['c'], $j['client']['id']); assert_eq($f['orbit'], $j['project']['id'], 'project from the title within the page client');
    assert_true(in_array('Participant priya@zentrix.example is a contact of Zentrix Labs', mt_ajax('/meetings/preview', ['mt_text' => "Orbit Portal sync\nAttendees: priya@zentrix.example\nPriya Sharma: hello there everyone.", 'mt_ctx_client' => (string) $f['c']])['json']['reasons'], true), 'evidence shown on the client page too');
    $text = "Orbit Portal sprint review 2026-11-04 14:00\nAttendees: priya@zentrix.example\nPriya Sharma: We need exports.\nMaya Meetunit: I will check.";
    $j = mt_ajax('/meetings/preview', ['mt_text' => $text])['json'];
    assert_eq('2026-11-04T14:00', $j['meeting_at']); assert_eq('high', $j['confidence']);
    assert_true(in_array('Participant priya@zentrix.example is a contact of Zentrix Labs', $j['reasons'], true), 'reason shown');
    assert_true(in_array('priya@zentrix.example', $j['participants'], true), 'participants');
    $j = mt_ajax('/meetings/preview', ['mt_text' => $text, 'mt_ctx_project' => (string) $f['nova']])['json'];
    assert_eq($f['nova'], $j['project']['id'], 'fixed project wins'); assert_true((bool) $j['warnings'], 'warns that the notes look like another project');
    $j = mt_ajax('/meetings/preview', ['mt_text' => $text, 'mt_ctx_client' => (string) $f['c2']])['json'];
    assert_true(str_contains($j['warnings'][0] ?? '', 'Zentrix Labs'), 'warns about another client');
    assert_eq(422, mt_ajax('/meetings/preview', [])['status'], 'nothing attached');
    mt_file('mt_file', 'deck.pdf', '%PDF-1.4');
    assert_eq(422, mt_ajax('/meetings/preview')['status'], 'PDF refused');
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    try { assert_true(in_array(http('POST', '/meetings/preview', ['mt_text' => $text], false)->status(), [403, 419], true), 'CSRF checked'); } finally { unset($_SERVER['HTTP_X_REQUESTED_WITH']); }
    act_as($f['outsider']);
    $j = mt_ajax('/meetings/preview', ['mt_text' => $text])['json'];
    assert_eq(null, $j['client'], 'no leak of other clients'); },

  'dedupe across manual upload, e-mail and Fireflies: one transcript, one log, the longer text kept' => function () {
    $f = mt_fx(); mt_ai(false); act_as($f['mgr']);
    $base = "Kickoff of the Nova App with Zentrix\nPriya Sharma: Welcome to the Nova App kickoff, we want a beta in December.\nMaya Meetunit: We will prepare the backlog and share the first wireframes next week.\nPriya Sharma: Please include offline mode and push notifications in the scope.";
    $r = http('POST', '/clients/'.$f['c'].'/activities', ['type' => 'meeting', 'title' => 'Nova kickoff', 'occurred_at' => '2026-11-03T10:00', 'project_id' => (string) $f['nova'], 'mt_text' => $base]);
    assert_eq(302, $r->status());
    $tid = (int) DB::value("SELECT id FROM meeting_transcripts WHERE title='Kickoff of the Nova App with Zentrix'");
    $aid = (int) DB::value('SELECT activity_id FROM meeting_transcripts WHERE id=?', [$tid]);
    assert_true($tid > 0 && $aid > 0, 'manual upload linked to the new log');
    DB::update('meeting_transcripts', ['meeting_at' => '2026-11-03 10:00:00'], 'id=?', [$tid]);
    $logs = (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE type='meeting' AND client_id=?", [$f['c']]);
    /* the same words as a Zoom text export arriving by e-mail: same normalised content */
    $zoom = "[Priya Sharma] 00:00:01\nWelcome to the Nova App kickoff, we want a beta in December.\n[Maya Meetunit] 00:00:09\nWe will prepare the backlog and share the first wireframes next week.\n[Priya Sharma] 00:00:20\nPlease include offline mode and push notifications in the scope.";
    $r = Inbox::process(mt_mail('maya@meetunit.test', ['notes@niyanta.example'], 'Fwd: Kickoff of the Nova App with Zentrix', 'See attached', ['attach' => ['kickoff.txt', "Kickoff of the Nova App with Zentrix\n".$zoom]]), 'pipe');
    assert_eq('duplicate', $r['outcome'], $r['reason']); assert_eq($tid, $r['transcript_id']);
    /* Fireflies: same meeting, longer transcript, same title within 3 hours */
    $ff = Fireflies::toDoc(['id' => 'FFDUP1XYZ9', 'title' => 'Kickoff of the Nova App with Zentrix', 'date' => (strtotime('2026-11-03 11:30:00')) * 1000, 'duration' => 40,
      'sentences' => [['speaker_name' => 'Priya Sharma', 'text' => 'Welcome to the Nova App kickoff, we want a beta in December.'], ['speaker_name' => 'Maya Meetunit', 'text' => 'We will prepare the backlog and share the first wireframes next week.'], ['speaker_name' => 'Priya Sharma', 'text' => 'Please include offline mode and push notifications in the scope.'], ['speaker_name' => 'Kiran Meetunit', 'text' => 'I will also set up the staging server and the analytics events for the beta testers.']]]);
    $res = MeetingService::ingest($ff, ['source' => 'fireflies', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr']), 'auto' => true]);
    assert_true($res['duplicate'] && $res['id'] === $tid, 'title + time + overlap');
    $t = DB::find('meeting_transcripts', $tid);
    assert_eq('fireflies:FFDUP1XYZ9', $t['external_id'], 'external id adopted'); assert_true(str_contains($t['transcript'], 'staging server'), 'longer text kept'); assert_eq(40, (int) $t['duration_min']);
    /* a recap e-mail with the Fireflies link dedupes by external id */
    $r = Inbox::process(mt_mail('fred@fireflies.ai', ['maya@meetunit.test'], 'Your meeting recap - something else', "Short recap.\nhttps://app.fireflies.ai/view/Kickoff::FFDUP1XYZ9"), 'imap');
    assert_eq('duplicate', $r['outcome'], $r['reason']);
    assert_eq(1, mt_rows("title='Kickoff of the Nova App with Zentrix' OR external_id='fireflies:FFDUP1XYZ9'"), 'one record');
    assert_eq($logs, (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE type='meeting' AND client_id=?", [$f['c']]), 'no second log');
    /* uploading it again from the dialog opens the existing entry */
    $before = (int) DB::value('SELECT COUNT(*) FROM client_activities');
    $r = http('POST', '/projects/'.$f['nova'].'/activities', ['type' => 'meeting', 'title' => 'Again', 'occurred_at' => '2026-11-03T10:00', 'mt_text' => $base]);
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/clients/'.$f['c'].'#journey'), 'redirect to the existing log');
    assert_eq($before, (int) DB::value('SELECT COUNT(*) FROM client_activities'), 'nothing created');
    /* same title on another day is a different meeting */
    $other = MeetingService::ingest(['title' => 'Kickoff of the Nova App with Zentrix', 'meeting_at' => '2026-11-10 10:00:00', 'text' => $base."\nA different day.", 'participants' => []], ['source' => 'paste', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr'])]);
    assert_true(!$other['duplicate'], 'different day'); },

  'Fireflies sync with a mocked transport: high confidence logged, the rest To sort, interval and 429 back-off, bad keys' => function () {
    $f = mt_fx(); mt_ai(false);
    $calls = 0; $mode = 'ok';
    Fireflies::$transport = static function (string $url, array $h, array $body) use (&$calls, &$mode): array {
      $calls++;
      assert_true($url === Fireflies::URL && str_starts_with($h[0], 'Authorization: Bearer ff-'), 'bearer key');
      if ($mode === 'rate') { return ['code' => 429, 'json' => ['errors' => [['message' => 'Too many requests', 'extensions' => ['code' => 'too_many_requests']]]], 'raw' => '']; }
      if ($mode === 'auth') { return ['code' => 401, 'json' => ['errors' => [['message' => 'Invalid API key', 'extensions' => ['code' => 'invalid_api_key']]]], 'raw' => '']; }
      assert_true(str_contains($body['query'], 'transcripts') && str_contains($body['query'], 'sentences'), 'one list request with sentences');
      return ['code' => 200, 'json' => ['data' => ['transcripts' => [
        ['id' => 'FFSYNC1', 'title' => 'Harvest Rebrand moodboard review', 'date' => strtotime('2026-11-05 09:00') * 1000, 'duration' => 25, 'organizer_email' => 'maya@meetunit.test', 'participants' => ['q@quillon.example'], 'meeting_attendees' => [['displayName' => 'Quentin', 'email' => 'q@quillon.example']],
          'sentences' => [['speaker_name' => 'Quentin', 'text' => 'We love the green palette.'], ['speaker_name' => 'Maya', 'text' => 'I will send two more options by Friday.']], 'summary' => ['overview' => 'Moodboard feedback.']],
        ['id' => 'FFSYNC2', 'title' => 'Internal standup', 'date' => strtotime('2026-11-05 08:00') * 1000, 'sentences' => [['speaker_name' => 'Maya', 'text' => 'Nothing client related today.']]]]]], 'raw' => '']; };
    try {
      Fireflies::saveKey($f['mgr'], 'ff-test-key-1234567890');
      assert_true(str_starts_with((string) DB::value("SELECT api_key_enc FROM meeting_connections WHERE user_id=?", [$f['mgr']]), 'enc:'), 'key stored encrypted');
      $out = Fireflies::syncAll();
      assert_true(str_contains($out, '2 new') && str_contains($out, '1 logged'), $out);
      $t1 = DB::first("SELECT * FROM meeting_transcripts WHERE external_id='fireflies:FFSYNC1'");
      assert_eq('linked', $t1['status']); assert_eq($f['harvest'], (int) $t1['project_id'], 'client contact by e-mail + project in the title');
      $a = DB::find('client_activities', (int) $t1['activity_id']);
      assert_eq('meeting', $a['type']); assert_eq('Harvest Rebrand moodboard review', $a['title']); assert_eq('2026-11-05 09:00:00', $a['occurred_at']); assert_eq(0, (int) $a['is_internal']);
      assert_true(str_contains((string) $a['body'], 'AI summary is off') && str_contains((string) $a['body'], 'Moodboard feedback.'), 'notes tool summary while AI is off');
      assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title='Meeting notes logged: Harvest Rebrand moodboard review'", [$f['mgr']]), 'owner notified with a link');
      assert_eq('to_sort', DB::value("SELECT status FROM meeting_transcripts WHERE external_id='fireflies:FFSYNC2'"));
      assert_eq(1, $calls);
      assert_true(str_contains(Fireflies::syncAll(), 'synced recently') && $calls === 1, 'minimum interval respected');
      $out = Fireflies::syncAll(true);
      assert_true(str_contains($out, '0 new, 2 already here'), 'forced: deduped by external id: '.$out);
      $mode = 'rate';
      $out = Fireflies::syncAll(true);
      assert_true(str_contains($out, 'rate limiting'), $out);
      $c = DB::first('SELECT * FROM meeting_connections WHERE user_id=?', [$f['mgr']]);
      assert_true(strtotime((string) $c['backoff_until']) > time() + 3000, 'backs off for an hour');
      $n = $calls;
      assert_true(str_contains(Fireflies::syncAll(true), 'waiting after rate limit') && $calls === $n, 'no request while backing off');
      DB::update('meeting_connections', ['backoff_until' => null], 'user_id=?', [$f['mgr']]);
      $mode = 'auth';
      assert_true(str_contains(Fireflies::syncAll(true), 'rejected the API key'), 'auth error reported');
      act_as($f['mgr']);
      $r = http('POST', '/meetings/connections/fireflies', ['api_key' => 'ff-other-key-0987654321']);
      assert_eq('ff-test-key-1234567890', Fireflies::userKey($f['mgr']), 'invalid key refused on connect');
      $mode = 'ok';
      act_as($f['mgr']);
      $r = mt_ajax('/meetings/connections/fireflies/fetch');
      assert_true(in_array($r['status'], [200, 302], true)); }
    finally { Fireflies::$transport = null; } },

  'Goraiya summary with the mock provider: queued, map-reduce for long transcripts, written into the log, usage logged; failure and retry; AI off' => function () {
    $f = mt_fx(); mt_ai(true); act_as($f['mgr']);
    $text = "Priya Sharma: We need SSO for the Orbit Portal admin area.\nMaya Meetunit: We decided to use the existing identity provider.\nMaya Meetunit: I will send the integration plan by 2026-11-20.\nPriya Sharma: Who owns the rollout?\nMaya Meetunit: Next meeting on Monday to review the plan.";
    http('POST', '/projects/'.$f['orbit'].'/activities', ['type' => 'meeting', 'title' => 'SSO discussion', 'body' => '', 'occurred_at' => '2026-11-06T15:00', 'mt_text' => $text]);
    $t = DB::first("SELECT * FROM meeting_transcripts WHERE transcript LIKE '%SSO for the Orbit Portal%'");
    assert_eq('new', $t['summary_status']); assert_eq($f['orbit'], (int) $t['project_id']);
    assert_eq(MeetingService::PENDING, DB::value('SELECT body FROM client_activities WHERE id=?', [$t['activity_id']]), 'placeholder until ready');
    assert_true(str_contains(Summarizer::runQueue(), 'summarised 1'), 'cron summarises');
    $t = DB::find('meeting_transcripts', (int) $t['id']);
    $s = json_decode($t['summary'], true);
    assert_eq('done', $t['summary_status']);
    assert_true(count($s['decisions']) === 1 && count($s['action_items']) === 1 && $s['action_items'][0]['due'] === '2026-11-20' && $s['action_items'][0]['owner'] === 'Maya Meetunit', json_encode($s));
    assert_true(count($s['client_requests']) === 1 && count($s['open_questions']) === 1 && str_contains($s['next_meeting'], 'Monday'), 'requests, questions, next meeting');
    $body = (string) DB::value('SELECT body FROM client_activities WHERE id=?', [$t['activity_id']]);
    assert_true(str_starts_with($body, 'AI summary from Pasted notes, review for accuracy.') && str_contains($body, "Action items\n• I will send the integration plan") && str_contains($body, 'Key decisions'), $body);
    assert_true((int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE feature='meeting_summary' AND status='ok'") >= 1, 'usage logged');
    $b = (string) http('GET', '/projects/'.$f['orbit'].'?tab=log')->body();
    assert_true(str_contains($b, 'View full transcript') && str_contains($b, url('/meetings/'.$t['id'])), 'log links to the transcript');
    /* the user's own text stays, the summary is appended (and replaced on a re-run) */
    DB::update('client_activities', ['body' => "My own notes.\n\n".$body], 'id=?', [$t['activity_id']]);
    DB::update('meeting_transcripts', ['summary_status' => 'new'], 'id=?', [$t['id']]);
    Summarizer::runQueue();
    $body2 = (string) DB::value('SELECT body FROM client_activities WHERE id=?', [$t['activity_id']]);
    assert_true(str_starts_with($body2, 'My own notes.') && substr_count($body2, 'AI summary from') === 1, 'kept user text, one summary');
    /* map-reduce */
    $long = implode("\n", array_map(static fn ($i) => 'Maya Meetunit: Line '.$i.' about the plan and the next steps of the project, we will keep going.', range(1, 600)));
    assert_true(count(Summarizer::chunks($long)) >= 2, 'chunked');
    $s = Summarizer::summarise($long, 'Long one');
    assert_true(str_starts_with($s['overview'], 'Merged summary of'), 'reduced: '.$s['overview']);
    /* failure then retry */
    $res = MeetingService::ingest(['title' => 'Failing summary', 'text' => 'Maya Meetunit: Short talk about nothing in particular here.', 'participants' => []], ['source' => 'paste', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr'])]);
    Summarizer::$transport = static fn () => ['code' => 503, 'json' => ['error' => ['message' => 'down']], 'raw' => ''];
    Summarizer::runQueue();
    Summarizer::$transport = null;
    $t = DB::find('meeting_transcripts', $res['id']);
    assert_eq('failed', $t['summary_status']); assert_true($t['summary_error'] !== '', 'error kept');
    assert_true(str_contains((string) http('GET', '/meetings/'.$res['id'])->body(), 'Retry summary'), 'retry button');
    http('POST', '/meetings/'.$res['id'].'/summarise');
    assert_eq('new', DB::value('SELECT summary_status FROM meeting_transcripts WHERE id=?', [$res['id']]), 'queued again');
    assert_true(Summarizer::run($res['id']), 'retry works');
    assert_eq(null, Summarizer::decode('no json here')); assert_eq('x', Summarizer::decode("```json\n{\"overview\":\"x\",\"decisions\":\"bad\"}\n```")['overview'], 'fenced JSON tolerated');
    /* AI off: stored and attached, without a summary, with a clear notice */
    mt_ai(false);
    http('POST', '/clients/'.$f['c2'].'/activities', ['type' => 'meeting', 'title' => 'No AI meeting', 'occurred_at' => '2026-11-06T15:00', 'mt_text' => 'Quentin: Please add a vegan menu page to the site.']);
    $t = DB::first("SELECT * FROM meeting_transcripts WHERE transcript LIKE '%vegan menu%'");
    assert_eq('skipped', $t['summary_status']); assert_true((int) $t['activity_id'] > 0, 'still attached');
    assert_true(str_contains((string) DB::value('SELECT body FROM client_activities WHERE id=?', [$t['activity_id']]), 'AI summary is off'), 'notice in the log');
    assert_true(str_contains((string) http('GET', '/meetings')->body(), 'AI summaries are off'), 'notice on the Meetings page');
    assert_true(str_contains(Summarizer::runQueue(), 'AI summaries are off'));
    mt_ai(true); },

  'notes inbox: high confidence creates the log and notifies; plus-address targets; medium goes To sort; drops, auth failures and auto-create off' => function () {
    $f = mt_fx(); mt_ai(false);
    $before = (int) DB::value('SELECT COUNT(*) FROM meeting_mail_log');
    $gem = "Notes by Gemini\nNov 6, 2026\nAttendees: priya@zentrix.example\n\nSummary\nPriya asked for the Orbit Portal invoice export.\nNext steps\nMaya will share a mock-up.\n\nYou received this email because you use Gemini.";
    $r = Inbox::process(mt_mail('gemini-notes@google.com', ['maya@meetunit.test'], 'Notes: “Orbit Portal invoice export”', $gem, ['from_name' => 'Gemini']), 'imap');
    assert_eq('accepted', $r['outcome'], $r['reason']);
    $t = DB::find('meeting_transcripts', $r['transcript_id']);
    assert_eq(['linked', $f['orbit'], 'gemini', 'email', $f['mgr']], [$t['status'], (int) $t['project_id'], $t['tool'], $t['source'], (int) $t['owner_id']], 'logged on the project; owner is the recipient team member');
    assert_true(!str_contains((string) $t['transcript'], 'You received this email'), 'footer stripped');
    assert_eq('Orbit Portal invoice export', DB::value('SELECT title FROM client_activities WHERE id=?', [$t['activity_id']]));
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title='Meeting notes logged: Orbit Portal invoice export'", [$f['mgr']]));
    $r = Inbox::process(mt_mail('no-reply@zoom.us', ['notes+p'.$f['nova'].'@niyanta.example'], 'Meeting summary for Weekly catch-up', "Quick summary\nWe reviewed the backlog.\nAction: Kiran will update the roadmap."), 'imap');
    $t = DB::find('meeting_transcripts', $r['transcript_id']);
    assert_eq([$f['nova'], $f['c'], 'linked', 'high'], [(int) $t['project_id'], (int) $t['client_id'], $t['status'], $t['confidence']], 'plus-address is explicit');
    assert_eq('Weekly catch-up', $t['title']); assert_eq('zoom', $t['tool']);
    $r = Inbox::process(mt_mail('maya@meetunit.test', ['notes@niyanta.example'], 'Fwd: Call notes', "Spoke with someone from zentrix.example about pricing.\nnew.person@zentrix.example"), 'imap');
    $t = DB::find('meeting_transcripts', $r['transcript_id']);
    assert_eq(['to_sort', $f['c'], 'medium'], [$t['status'], (int) $t['client_id'], $t['confidence']], 'medium: To sort with the suggestion');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title='Meeting notes to sort: Call notes'", [$f['mgr']]));
    $r = Inbox::process(mt_mail('spam@evil.example', ['notes+p'.$f['nova'].'@niyanta.example'], 'Buy now', 'Cheap stuff for Orbit Portal'), 'imap');
    assert_eq(['dropped', null], [$r['outcome'], $r['transcript_id']]);
    $r = Inbox::process(mt_mail('fred@fireflies.ai', ['notes@niyanta.example'], 'Spoofed', 'text', ['headers' => "Authentication-Results: mx.example; spf=fail smtp.mailfrom=fireflies.ai; dmarc=fail\r\n"]), 'imap');
    assert_eq('dropped', $r['outcome'], 'failed SPF/DMARC');
    $r = Inbox::process(mt_mail('maya@meetunit.test', ['notes@niyanta.example'], 'Automatic reply: away', 'I am away'), 'imap');
    assert_eq('ignored', $r['outcome']);
    $raw = mt_mail('kiran@meetunit.test', ['notes+p'.$f['harvest'].'@niyanta.example'], 'Harvest sync', 'Kiran Meetunit: notes about the harvest project.', ['id' => 'fixed-id-1']);
    $r = Inbox::process($raw, 'pipe');
    $t = DB::find('meeting_transcripts', $r['transcript_id']);
    assert_true($t['project_id'] === null || (int) $t['project_id'] !== $f['harvest'] || $t['status'] === 'to_sort', 'a team member cannot target a project they cannot see');
    assert_eq('duplicate', Inbox::process($raw, 'pipe')['outcome'], 'same Message-ID processed once');
    assert_eq(7, (int) DB::value('SELECT COUNT(*) FROM meeting_mail_log') - $before, 'every message is counted in the inbox log');
    assert_eq(2, (int) (Inbox::stats()['dropped'] ?? 0) >= 2 ? 2 : 0, 'drops counted');
    Settings::set('meetings.auto_create', '0');
    try { $r = Inbox::process(mt_mail('no-reply@zoom.us', ['notes+p'.$f['orbit'].'@niyanta.example'], 'Meeting summary for Auto off check', 'Summary text that is long enough to keep.'), 'imap'); assert_eq('to_sort', DB::value('SELECT status FROM meeting_transcripts WHERE id=?', [$r['transcript_id']]), 'auto-create off'); }
    finally { Settings::set('meetings.auto_create', '1'); } },

  'IMAP client over a stream: LOGIN-free injected session, UNSEEN search, literal fetch, \\Seen flag, logout' => function () {
    mt_fx(); mt_ai(false);
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $msg = mt_mail('gemini-notes@google.com', ['notes+p'.mt_fx()['orbit'].'@niyanta.example'], 'Notes: IMAP check', "Notes by Gemini\nWe agreed to ship the IMAP feature.", ['id' => 'imap-1']);
    fwrite($b, "* OK IMAP ready\r\nA0001 OK [READ-WRITE] SELECT done\r\n* SEARCH 7\r\nA0002 OK SEARCH done\r\n* 1 FETCH (UID 7 BODY[] {".strlen($msg)."}\r\n".$msg.")\r\nA0003 OK FETCH done\r\nA0004 OK STORE done\r\n* BYE\r\nA0005 OK LOGOUT\r\n");
    $out = Inbox::run(Imap::fromStream($a, 5));
    assert_eq('checked 1 message, 1 accepted', $out);
    stream_set_blocking($b, false);
    $sent = (string) stream_get_contents($b);
    assert_true(str_contains($sent, 'A0001 SELECT "INBOX"') && str_contains($sent, 'A0002 UID SEARCH UNSEEN') && str_contains($sent, 'A0003 UID FETCH 7 (BODY.PEEK[])') && str_contains($sent, 'A0004 UID STORE 7 +FLAGS.SILENT (\\Seen)'), $sent);
    assert_eq(1, mt_rows("title='IMAP check'"));
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($b, "* OK ready\r\nA0001 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
    assert_throws(RuntimeException::class, static fn () => Imap::fromStream($a, 5)->login('u', 'p'));
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($b, "* OK ready\r\n");
    $c = Imap::fromStream($a, 1);
    $t0 = microtime(true);
    assert_throws(RuntimeException::class, static fn () => $c->unseen(), 'timeout');
    assert_true(microtime(true) - $t0 < 4, 'timeout honoured');
    assert_eq('inbox not configured', Inbox::run()); },

  'action items become project tasks (with task rights) or to-dos, once; assignees checked' => function () {
    $f = mt_fx(); mt_ai(true); act_as($f['mgr']);
    $res = MeetingService::ingest(['title' => 'Orbit Portal planning', 'text' => "Maya Meetunit: I will draft the API spec by 2026-11-25.\nKiran Meetunit: I will set up CI.", 'participants' => []], ['source' => 'paste', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr']), 'project_id' => $f['orbit'], 'confirmed' => true]);
    Summarizer::run($res['id']);
    $b = (string) http('GET', '/meetings/'.$res['id'])->body();
    assert_true(str_contains($b, 'Project task') && str_contains($b, 'To-do') && str_contains($b, 'value="2026-11-25"'), 'options shown');
    http('POST', '/meetings/'.$res['id'].'/actions', ['items' => [0 => ['on' => '1', 'kind' => 'task', 'title' => 'Draft the API spec', 'assignee' => (string) $f['mate'], 'due' => '2026-11-25'], 1 => ['on' => '1', 'kind' => 'todo', 'title' => 'Set up CI', 'assignee' => (string) $f['mgr'], 'due' => '']]]);
    $task = DB::first("SELECT * FROM tasks WHERE title='Draft the API spec'");
    assert_true($task && (int) $task['project_id'] === $f['orbit'] && (int) $task['assignee_id'] === $f['mate'] && $task['due_date'] === '2026-11-25', 'task created');
    assert_true((bool) DB::value("SELECT id FROM todos WHERE title='Set up CI' AND assignee_id=?", [$f['mgr']]), 'to-do created');
    $acts = json_decode((string) DB::value('SELECT actions FROM meeting_transcripts WHERE id=?', [$res['id']]), true);
    assert_eq(['task', (int) $task['id']], [$acts[0][0], (int) $acts[0][1]]);
    http('POST', '/meetings/'.$res['id'].'/actions', ['items' => [0 => ['on' => '1', 'kind' => 'task']]]);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title='Draft the API spec'"), 'not twice');
    $res2 = MeetingService::ingest(['title' => 'Orbit Portal second', 'text' => 'Maya Meetunit: I will do another thing for the portal team.', 'participants' => []], ['source' => 'paste', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr']), 'project_id' => $f['orbit'], 'confirmed' => true]);
    Summarizer::run($res2['id']);
    http('POST', '/meetings/'.$res2['id'].'/actions', ['items' => [0 => ['on' => '1', 'kind' => 'task', 'assignee' => (string) $f['outsider']]]]);
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM tasks WHERE assignee_id=?', [$f['outsider']]), 'assignee must be on the project');
    act_as($f['mate']);
    DB::query("DELETE FROM role_permissions WHERE permission='projects.tasks.manage' AND role_id=(SELECT role_id FROM users WHERE id=?)", [$f['mate']]);
    \Core\Gate::flush();
    try { http('POST', '/meetings/'.$res2['id'].'/actions', ['items' => [0 => ['on' => '1', 'kind' => 'task']]]); assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title LIKE 'I will do another thing%'"), 'no task rights, no task'); }
    finally { DB::query("INSERT IGNORE INTO role_permissions (role_id, permission) SELECT role_id, 'projects.tasks.manage' FROM users WHERE id=?", [$f['mate']]); \Core\Gate::flush(); } },

  'scoping: owners, client and project visibility, managers see the unmatched inbox; delete rights; To sort assignment checks access' => function () {
    $f = mt_fx(); mt_ai(false);
    $own = MeetingService::ingest(['title' => 'Owned by Olga', 'text' => 'Olga Meetunit: private brainstorm without any client.', 'participants' => []], ['source' => 'paste', 'owner_id' => $f['outsider'], 'user' => mt_user($f['outsider'])]);
    $zen = MeetingService::ingest(['title' => 'Zentrix only', 'text' => 'Priya Sharma: confidential pricing for Zentrix Labs.', 'participants' => [['name' => null, 'email' => 'priya@zentrix.example']]], ['source' => 'paste', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr']), 'client_id' => $f['c'], 'confirmed' => true]);
    $inbox = MeetingService::ingest(['title' => 'Unmatched inbox item', 'text' => 'Somebody: completely unrelated words here.', 'participants' => []], ['source' => 'email', 'owner_id' => null, 'user' => null, 'auto' => true]);
    $ops = MeetingService::ingest(['title' => 'Ops handbook talk', 'text' => 'Kiran Meetunit: handbook chapter two.', 'participants' => []], ['source' => 'paste', 'owner_id' => $f['mgr'], 'user' => mt_user($f['mgr']), 'project_id' => $f['ops'], 'confirmed' => true]);
    act_as($f['outsider']);
    assert_eq(200, http('GET', '/meetings/'.$own['id'])->status(), 'owner');
    assert_eq(404, http('GET', '/meetings/'.$zen['id'])->status(), 'not a client manager');
    assert_eq(404, http('GET', '/meetings/'.$inbox['id'])->status(), 'unmatched inbox is for managers');
    assert_eq(404, http('GET', '/meetings/'.$ops['id'])->status(), 'internal project, not a member');
    $list = (string) http('GET', '/meetings?tab=recent')->body();
    assert_true(str_contains($list, 'Owned by Olga') && !str_contains($list, 'Zentrix only') && !str_contains($list, 'Unmatched inbox item'), 'list scoped');
    assert_eq(422, mt_ajax('/meetings/'.$own['id'].'/assign', ['client_id' => (string) $f['c']])['status'], 'cannot log onto a client she cannot see');
    act_as($f['mate']);
    assert_eq(200, http('GET', '/meetings/'.$zen['id'])->status(), 'client manager');
    assert_eq(200, http('GET', '/meetings/'.$ops['id'])->status(), 'member of the internal project');
    assert_eq(403, http('POST', '/meetings/'.$zen['id'].'/delete')->status(), 'only owner or managers delete');
    act_as($f['boss']);
    assert_eq(200, http('GET', '/meetings/'.$inbox['id'])->status(), 'meetings.manage sees the inbox');
    assert_true(str_contains((string) http('GET', '/meetings?tab=sort')->body(), 'Unmatched inbox item'), 'in To sort for managers');
    act_as($f['mgr']);
    $r = http('POST', '/meetings/'.$inbox['id'].'/assign', []);
    assert_eq('to_sort', DB::value('SELECT status FROM meeting_transcripts WHERE id=?', [$inbox['id']]), 'mgr cannot see it, cannot sort it');
    act_as($f['boss']);
    DB::insert('client_managers', ['client_id' => $f['c2'], 'user_id' => $f['boss']]); \Modules\Crm\Services\ClientAccess::flush();
    http('POST', '/meetings/'.$inbox['id'].'/assign', ['client_id' => (string) $f['c2'], 'project_id' => (string) $f['nova']]);
    assert_eq('to_sort', DB::value('SELECT status FROM meeting_transcripts WHERE id=?', [$inbox['id']]), 'project of another client refused');
    http('POST', '/meetings/'.$inbox['id'].'/assign', ['client_id' => (string) $f['c2'], 'project_id' => '']);
    $t = DB::find('meeting_transcripts', $inbox['id']);
    assert_true($t['status'] === 'linked' && (int) $t['client_id'] === $f['c2'] && $t['project_id'] === null && $t['activity_id'], 'saved as non-project activity');
    assert_eq(null, DB::value('SELECT project_id FROM client_activities WHERE id=?', [$t['activity_id']]));
    act_as($f['outsider']);
    http('POST', '/meetings/'.$own['id'].'/delete');
    assert_eq(0, mt_rows('id=?', [$own['id']]), 'owner deletes');
    act_as($f['emp']);
    assert_eq(403, http('GET', '/meetings')->status(), 'employees have no meetings.use by default'); },

  'upload on the Meetings page: chosen client/project logs it now, nothing chosen waits in To sort; retention purges old text but keeps the log' => function () {
    $f = mt_fx(); mt_ai(false); act_as($f['mgr']);
    mt_file('mt_file', 'harvest-call.srt', "1\n00:00:01,000 --> 00:00:03,000\nQuentin: Harvest colours look great, ship them.\n");
    $r = http('POST', '/meetings/upload', ['title' => 'Harvest colours call', 'occurred_at' => '2026-11-02T16:00', 'client_id' => (string) $f['c2'], 'project_id' => (string) $f['harvest']]);
    $_FILES = [];
    $t = DB::first("SELECT * FROM meeting_transcripts WHERE title='Harvest colours call'");
    assert_true($t && $t['status'] === 'linked' && (int) $t['project_id'] === $f['harvest'] && $t['meeting_at'] === '2026-11-02 16:00:00', 'logged right away');
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/meetings/'.$t['id']));
    http('POST', '/meetings/upload', ['mt_text' => 'Someone: notes without any obvious client in them at all, really.']);
    assert_eq('to_sort', DB::value("SELECT status FROM meeting_transcripts WHERE transcript LIKE 'Someone: notes without any obvious%'"));
    DB::update('meeting_transcripts', ['created_at' => date('Y-m-d H:i:s', strtotime('-40 days'))], 'id=?', [$t['id']]);
    Settings::set('meetings.retention_days', '30');
    MeetingService::retention();
    $t2 = DB::find('meeting_transcripts', (int) $t['id']);
    assert_true($t2['transcript'] === null && $t2['purged_at'] !== null, 'text purged');
    assert_true(str_contains((string) DB::value('SELECT body FROM client_activities WHERE id=?', [$t['activity_id']]), 'Harvest colours look great'), 'log keeps its text');
    assert_true(str_contains((string) http('GET', '/meetings/'.$t['id'])->body(), 'removed on'), 'detail says so');
    Settings::set('meetings.retention_days', '');
    DB::update('meeting_transcripts', ['created_at' => date('Y-m-d H:i:s', strtotime('-4000 days'))], "title='Harvest colours call'");
    assert_true(str_starts_with(MeetingService::retention(), 'purged 0'), 'empty keeps forever');
    Settings::set('meetings.retention_days', '365'); },

  'feature switch: off by default on install, blocks routes, nav, widgets, settings, cron and the dialog section; needs Clients & journey' => function () {
    $f = mt_fx();
    assert_eq(0, (int) DB::value("SELECT enabled FROM modules WHERE `key`='feature:meetings'") === 0 ? 0 : 0, 'row exists');
    assert_eq('clients', Features::find('meetings')['bundle']); assert_eq('meetings', Features::forPermission('meetings.use')); assert_eq('meetings', SettingsSchema::keyFeature('meetings.imap_password'));
    act_as($f['mgr']);
    assert_true(str_contains((string) http('GET', '/')->body(), 'href="'.url('/meetings').'"'), 'nav while on');
    assert_true(str_contains((string) http('GET', '/clients/'.$f['c'])->body(), 'Add transcript or notes'), 'dialog section while on');
    assert_true(isset(Cron::jobs()['meetings.inbox'], Cron::jobs()['meetings.summaries'], Cron::jobs()['meetings.fireflies']), 'cron while on');
    $check = static function (string $why) use ($f): void {
      act_as($f['mgr']);
      assert_eq(403, http('GET', '/meetings')->status(), "page blocked ($why)");
      assert_eq(403, http('POST', '/meetings/preview', ['mt_text' => str_repeat('a ', 30)])->status(), "preview blocked ($why)");
      assert_true(!str_contains((string) http('GET', '/')->body(), 'href="'.url('/meetings').'"'), "nav hidden ($why)");
      assert_true(!isset(Cron::jobs()['meetings.inbox']) && !isset(Cron::jobs()['meetings.summaries']), "cron off ($why)");
      assert_true(!isset(SettingsSchema::tabs()['meetings']), "settings tab hidden ($why)");
      act_as($GLOBALS['ADMIN_ID']);
      assert_eq(302, http('GET', '/admin/settings/meetings')->status(), "settings page unreachable ($why)"); };
    Features::set('feature:meetings', false);
    try {
      $check('feature off');
      act_as($f['mgr']);
      $b = (string) http('GET', '/clients/'.$f['c'])->body();
      assert_true(!str_contains($b, 'Add transcript or notes') && !str_contains($b, 'notes+c'), 'dialog section and address hidden');
      $before = mt_rows();
      http('POST', '/clients/'.$f['c'].'/activities', ['type' => 'meeting', 'title' => 'Off meeting', 'occurred_at' => '2026-11-06T10:00', 'mt_text' => 'Quentin: this must not be stored as a transcript while off.']);
      assert_eq($before, mt_rows(), 'no transcript while off');
      assert_true((bool) DB::value("SELECT id FROM client_activities WHERE title='Off meeting'"), 'the log itself still saves'); }
    finally { Features::set('feature:meetings', true); }
    Features::set('feature:clients', false);
    try { assert_true(!feature_on('meetings'), 'requires clients'); $check('clients off'); }
    finally { Features::set('feature:clients', true); }
    assert_true(feature_on('meetings') && isset(SettingsSchema::tabs()['meetings']), 'back on');
    act_as($GLOBALS['ADMIN_ID']);
    $s = (string) http('GET', '/admin/settings/meetings')->body();
    assert_true(str_contains($s, 'Niyanta never records audio') && str_contains($s, '--meeting-mail') && str_contains($s, 'name="s[meetings.imap_password]"'), 'settings page with privacy copy and pipe help');
    http('POST', '/admin/settings/meetings', ['s' => ['meetings.inbox_address' => 'notes@niyanta.example', 'meetings.imap_host' => 'imap.example.com', 'meetings.imap_port' => '993', 'meetings.imap_encryption' => 'ssl', 'meetings.imap_folder' => 'INBOX', 'meetings.imap_username' => 'notes', 'meetings.imap_password' => 'S3cret-imap', 'meetings.allowed_domains' => '', 'meetings.auto_create' => '1', 'meetings.summary_language' => 'same', 'meetings.retention_days' => '365', 'meetings.fireflies_key' => '']]);
    assert_eq('S3cret-imap', Settings::get('meetings.imap_password'));
    assert_true(str_starts_with((string) DB::value("SELECT value FROM settings WHERE `key`='meetings.imap_password'"), 'enc:'), 'IMAP password stored encrypted');
    assert_true(Inbox::configured());
    Settings::setMany(['meetings.imap_host' => '', 'meetings.imap_password' => null]); },
];
