<?php
use Core\{DB, Html};
use Modules\Projects\Services\{ProjectAccess, ProjectBrief};

/** Project brief: sanitizer (XSS), Brief tab read / edit, save permissions, prefill, revisions (max 20) and restore, audit, overview excerpt. */
function pb_fx(): array {
  static $f = null;
  if ($f) { return $f; }
  $now = date('Y-m-d H:i:s');
  $mgr = make_user('manager', 'mgr@brief.test', ['name' => 'Zz Brief Manager']); $emp = make_user('employee', 'emp@brief.test', ['name' => 'Zz Brief Member']);
  $out = make_user('employee', 'out@brief.test', ['name' => 'Zz Brief Outsider']); $mgr2 = make_user('manager', 'mgr2@brief.test', ['name' => 'Zz Brief Other Manager']);
  $p = DB::insert('projects', ['name' => 'Brief Portal', 'status' => 'active', 'manager_id' => $mgr, 'created_by' => $mgr, 'created_at' => $now, 'updated_at' => $now,
    'description' => "Customer portal rebuild.\n\nPhase one only.", 'scope' => "- Login and SSO\n- Billing pages\n- Admin reports", 'deliverables' => "Design system\nStaging site"]);
  DB::insert('project_members', ['project_id' => $p, 'user_id' => $emp]);
  $p2 = DB::insert('projects', ['name' => 'Brief Other', 'status' => 'active', 'manager_id' => $mgr2, 'created_by' => $mgr2, 'created_at' => $now, 'updated_at' => $now]);
  $file = static fn (int $pid) => DB::insert('files', ['module' => 'projects', 'entity_type' => 'project', 'entity_id' => $pid, 'category' => 'brief', 'disk_path' => 'projects/x.png', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 10, 'uploaded_by' => $mgr, 'created_at' => $now]);
  $f1 = $file($p); $f2 = $file($p2);
  ProjectAccess::flush();
  return $f = compact('mgr', 'emp', 'out', 'mgr2', 'p', 'p2', 'f1', 'f2'); }

function pb_json(string $path, array $post): Core\Response {
  $_SERVER['HTTP_ACCEPT'] = 'application/json';
  try { return http('POST', $path, $post); } finally { unset($_SERVER['HTTP_ACCEPT']); } }

return [
  'sanitizer strips XSS payloads: script, on* handlers, javascript:/data:/vbscript: URLs, style, iframe, svg, forms, external and data: images' => function () {
    $c = static fn (string $h) => Html::clean($h);
    $bad = [
      '<p>a<script>alert(1)</script>b</p>' => '<p>ab</p>',
      '<img src=x onerror=alert(1)>' => '',
      '<p onclick="alert(1)" onmouseover=alert(2)>hi</p>' => '<p>hi</p>',
      '<a href="javascript:alert(1)">x</a>' => '<p>x</p>',
      '<a href=" JaVaScRiPt:alert(1)">x</a>' => '<p>x</p>',
      '<a href="java&#x09;script:alert(1)">x</a>' => '<p>x</p>',
      '<a href="vbscript:msgbox(1)">x</a>' => '<p>x</p>',
      '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>' => '<p>x</p>',
      '<a href="//evil.example/x">x</a>' => '<p>x</p>',
      '<p style="background:url(javascript:alert(1))">s</p><style>p{}</style>' => '<p>s</p>',
      '<iframe src="https://evil.example"></iframe><p>t</p>' => '<p>t</p>',
      '<svg><script>alert(1)</script><a xlink:href="javascript:alert(1)">x</a></svg><p>t</p>' => '<p>t</p>',
      '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>' => '',
      '<form action="https://evil.example"><input name="pw"><button>Go</button></form>' => '',
      '<img src="data:image/png;base64,iVBORw0KGgo=">' => '',
      '<img src="https://evil.example/files/1/view">' => '',
      '<object data="x.swf"><p>fallback</p></object><p>t</p>' => '<p>t</p>',
      '<embed src="x.swf">' => '',
      '<p><!-- <script>alert(1)</script> -->c</p>' => '<p>c</p>',
      '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>' => '',
    ];
    foreach ($bad as $in => $exp) { assert_eq($exp, $c($in), 'clean '.$in); }
    foreach (['<scr<script>ipt>alert(1)</script>', '"><img src=x onerror=alert(1)//', '<details open ontoggle=alert(1)>', '<a href="https://ok.example" onfocus="alert(1)" style="x">ok</a>'] as $in) {
      $o = $c($in);
      assert_true(!preg_match('/<script|onerror|ontoggle|onfocus|style=|javascript:/i', $o), 'no executable markup in '.$o); }
    assert_eq('<p>&lt;b&gt;not a tag&lt;/b&gt; &amp; 5 &gt; 3</p>', $c('<p>&lt;b&gt;not a tag&lt;/b&gt; &amp; 5 &gt; 3</p>'), 'escaped text stays escaped');
    assert_throws(\LengthException::class, fn () => $c(str_repeat('a', Html::MAX + 1)), 'size limit'); },

  'sanitizer keeps structure: headings, inline marks, lists, checklist, quote, code, table spans, safe links with rel/target, app file images' => function () {
    $in = '<h1>Title</h1><h3>Sub</h3><p><strong>b</strong> <em>i</em> <u>u</u> <s>s</s> <code>c</code></p><ul data-checklist><li data-checked="true">done</li><li data-checked="maybe">todo</li></ul><ol><li>one</li></ol>'
      .'<blockquote><p>q</p></blockquote><pre>line 1'."\n".'  line 2</pre><hr><table><thead><tr><th colspan="2" class="x">H</th></tr></thead><tbody><tr><td rowspan="99">a</td><td>b</td></tr></tbody></table>'
      .'<p><a href="https://example.com/a?b=1&amp;c=2" target="_self" rel="opener">site</a> <a href="mailto:team@example.com">mail</a> <img src="'.url('/files/42/view').'" alt="Shot" width="9"></p>';
    $o = Html::clean($in);
    foreach (['<h2>Title</h2>', '<h3>Sub</h3>', '<strong>b</strong>', '<em>i</em>', '<u>u</u>', '<s>s</s>', '<code>c</code>', '<ul data-checklist><li data-checked="true">done</li><li data-checked="false">todo</li></ul>', '<ol><li>one</li></ol>',
      '<blockquote><p>q</p></blockquote>', "<pre>line 1\n  line 2</pre>", '<hr>', '<th colspan="2">H</th>', '<td>a</td>', '<a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer" target="_blank">site</a>',
      '<a href="mailto:team@example.com" rel="noopener noreferrer" target="_blank">mail</a>', '<img src="'.url('/files/42/view').'" alt="Shot">'] as $part) { assert_true(str_contains($o, $part), 'keeps '.$part.' in '.$o); }
    assert_true(!str_contains($o, 'class=') && !str_contains($o, 'width='), 'drops other attributes');
    assert_eq('<p>Bold</p><p>a</p><p>b</p>', Html::clean('<b style="font-weight:normal" id="docs-internal-guid-1"><p dir="ltr"><span style="font-weight:700">Bold</span></p></b><div>a</div><div>b</div>'), 'Docs wrapper and divs');
    assert_eq('<p>loose <strong>text</strong></p><h2>x</h2>', Html::clean('loose <strong>text</strong><h2>x</h2>'), 'loose inline content gets a paragraph');
    assert_eq('<p>x</p>', Html::clean('<p><br></p><p>x</p><p>&nbsp;</p>'), 'edge empty paragraphs trimmed');
    assert_eq('', Html::clean('<p><br></p>'), 'empty document');
    assert_eq("A\nb c\nd", Html::text('<h2>A</h2><p>b c</p><ul><li>d</li></ul>'), 'plain text'); },

  'Brief tab: members read, project editors get the editor, outsiders 404; Brief sits right after Overview' => function () {
    $f = pb_fx();
    act_as($f['emp']);
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief&mode=edit')->body();
    assert_true(str_contains($b, 'Project brief') && !str_contains($b, 'contenteditable') && !str_contains($b, 'Edit brief') && !str_contains($b, 'Start the brief'), 'member: read only even with mode=edit');
    assert_true((bool) preg_match('#\?tab=overview"[^>]*>.*?Overview</a><a href="[^"]*\?tab=brief"#s', $b), 'Brief tab right after Overview');
    assert_true(str_contains($b, 'js/editor.js') && str_contains($b, 'css/editor.css'), 'editor assets on the brief tab');
    assert_true(!str_contains((string) http('GET', '/projects/'.$f['p'].'?tab=overview')->body(), 'js/editor.js'), 'not loaded on other tabs');
    act_as($f['mgr']);
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief&mode=edit')->body();
    assert_true(str_contains($b, 'contenteditable="true"') && str_contains($b, 'role="toolbar"') && str_contains($b, 'data-cmd="check"') && str_contains($b, 'aria-pressed="false"'), 'editor with toolbar');
    foreach ([$f['out'], $f['mgr2']] as $u) { act_as($u); assert_eq(404, http('GET', '/projects/'.$f['p'].'?tab=brief')->status(), 'outsider 404'); } },

  'empty brief is prefilled from description, scope and deliverables (editor and read view); old fields stay on the form' => function () {
    $f = pb_fx();
    DB::delete('project_briefs', 'project_id=?', [$f['p']]);
    $h = ProjectBrief::prefill(DB::find('projects', $f['p']));
    assert_eq('<h2>Overview</h2><p>Customer portal rebuild.</p><p>Phase one only.</p><h2>Scope</h2><ul><li>Login and SSO</li><li>Billing pages</li><li>Admin reports</li></ul><h2>Deliverables</h2><ul><li>Design system</li><li>Staging site</li></ul>', $h);
    act_as($f['mgr']);
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief&mode=edit')->body();
    assert_true(str_contains($b, $h) && str_contains($b, 'data-new') && str_contains($b, 'Started from the project'), 'editor prefilled');
    assert_true(str_contains((string) http('GET', '/projects/'.$f['p'].'/edit')->body(), 'name="scope"'), 'scope field still on the form');
    act_as($f['emp']);
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief')->body();
    assert_true(str_contains($b, 'No brief yet') && str_contains($b, '<li>Login and SSO</li>'), 'read view shows the project details');
    assert_eq('<h2>Overview</h2><p>a &amp; &lt;b&gt;</p>', ProjectBrief::prefill(['description' => 'a & <b>']), 'escaped'); },

  'save: editors save sanitized HTML (JSON and form), members 403, outsiders 404, audit-logged; overview shows an excerpt with Open brief' => function () {
    $f = pb_fx();
    DB::delete('project_briefs', 'project_id=?', [$f['p']]); DB::delete('project_brief_revisions', 'project_id=?', [$f['p']]);
    act_as($f['emp']);
    assert_eq(403, pb_json('/projects/'.$f['p'].'/brief', ['body' => '<p>x</p>'])->status(), 'member cannot save');
    act_as($f['out']);
    assert_eq(404, pb_json('/projects/'.$f['p'].'/brief', ['body' => '<p>x</p>'])->status(), 'outsider 404');
    act_as($f['mgr2']);
    assert_eq(404, pb_json('/projects/'.$f['p'].'/brief', ['body' => '<p>x</p>'])->status(), 'manager outside the project 404');
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM project_briefs WHERE project_id=?', [$f['p']]));
    act_as($f['mgr']);
    $r = pb_json('/projects/'.$f['p'].'/brief', ['body' => '<h2>Scope</h2><p onclick="x()">Build the <b>portal</b><script>alert(1)</script></p><img src="'.url('/files/'.$f['f1'].'/view').'"><img src="'.url('/files/'.$f['f2'].'/view').'"><p><a href="'.url('/files/'.$f['f2']).'">other</a></p>', 'version' => '']);
    assert_eq(200, $r->status(), (string) $r->body());
    $j = json_decode((string) $r->body(), true);
    $exp = '<h2>Scope</h2><p>Build the <b>portal</b></p><p><img src="'.url('/files/'.$f['f1'].'/view').'" alt=""></p><p>other</p>';
    assert_true($j['ok'] && $j['changed'] && $j['html'] === $exp && $j['by'] === 'Zz Brief Manager', 'json: '.$r->body());
    assert_eq($exp, DB::value('SELECT body_html FROM project_briefs WHERE project_id=?', [$f['p']]), 'stored sanitized; images of other projects removed');
    $a = DB::first("SELECT * FROM audit_logs WHERE action='projects.brief.save' AND entity_id=? ORDER BY id DESC", [$f['p']]);
    assert_true($a && (int) $a['user_id'] === $f['mgr'] && (int) json_decode($a['meta'], true)['revision'] === (int) $j['version'], 'audit');
    $r = http('POST', '/projects/'.$f['p'].'/brief', ['body' => $exp, 'version' => (string) $j['version']]);
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/projects/'.$f['p'].'?tab=brief'), 'form post redirects back');
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM project_brief_revisions WHERE project_id=?', [$f['p']]), 'unchanged content adds no revision');
    assert_eq(422, pb_json('/projects/'.$f['p'].'/brief', ['body' => str_repeat('x', Html::MAX + 1), 'version' => (string) $j['version']])->status(), 'too large');
    $r = pb_json('/projects/'.$f['p'].'/brief', ['body' => '<p>stale</p>', 'version' => '0']);
    assert_true($r->status() === 409 && json_decode((string) $r->body(), true)['conflict'], 'stale version conflicts');
    assert_eq(200, pb_json('/projects/'.$f['p'].'/brief', ['body' => '<p>Mine wins</p><p>'.str_repeat('Long scope text. ', 40).'</p>', 'version' => '1', 'force' => '1'])->status(), 'force overwrites');
    act_as($f['emp']);
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief')->body();
    assert_true(str_contains($b, '<p>Mine wins</p>') && str_contains($b, 'Last edited by Zz Brief Manager, just now') && str_contains($b, 'class="brief-hist"'), 'read view with last edited and history');
    $b = (string) http('GET', '/projects/'.$f['p'])->body();
    assert_true(str_contains($b, 'class="brief-ex"') && str_contains($b, 'Mine wins Long scope text.') && str_contains($b, 'Open brief') && str_contains($b, '…'), 'overview excerpt'); },

  'revisions: only the last 20 are kept; view an old version; restore saves it as a new revision (audit); members cannot restore' => function () {
    $f = pb_fx();
    act_as($f['mgr']);
    DB::delete('project_briefs', 'project_id=?', [$f['p']]); DB::delete('project_brief_revisions', 'project_id=?', [$f['p']]);
    for ($i = 1; $i <= 23; $i++) { ProjectBrief::save($f['p'], '<p>Version '.$i.'</p>', $f['mgr']); }
    $revs = ProjectBrief::revisions($f['p']);
    assert_eq(20, count($revs), 'capped'); assert_eq(20, (int) DB::value('SELECT COUNT(*) FROM project_brief_revisions WHERE project_id=?', [$f['p']]));
    $old = DB::first('SELECT id, body_html FROM project_brief_revisions WHERE project_id=? ORDER BY id LIMIT 1', [$f['p']]);
    assert_eq('<p>Version 4</p>', $old['body_html'], 'oldest kept is version 4');
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief&rev='.$old['id'])->body();
    assert_true(str_contains($b, '<p>Version 4</p>') && str_contains($b, 'Restore this version') && str_contains($b, '/brief/revisions/'.$old['id'].'/restore'), 'view old version');
    act_as($f['emp']);
    $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief&rev='.$old['id'])->body();
    assert_true(str_contains($b, '<p>Version 4</p>') && !str_contains($b, 'Restore this version'), 'members view history without restore');
    assert_eq(403, http('POST', '/projects/'.$f['p'].'/brief/revisions/'.$old['id'].'/restore')->status(), 'member cannot restore');
    act_as($f['mgr2']);
    assert_eq(404, http('POST', '/projects/'.$f['p'].'/brief/revisions/'.$old['id'].'/restore')->status(), 'outsider 404');
    act_as($f['mgr']);
    assert_eq(404, http('POST', '/projects/'.$f['p2'].'/brief/revisions/'.$old['id'].'/restore')->status(), 'revision of another project');
    http('POST', '/projects/'.$f['p'].'/brief/revisions/'.$old['id'].'/restore');
    assert_eq('<p>Version 4</p>', DB::value('SELECT body_html FROM project_briefs WHERE project_id=?', [$f['p']]), 'restored');
    $top = DB::first('SELECT * FROM project_brief_revisions WHERE project_id=? ORDER BY id DESC LIMIT 1', [$f['p']]);
    assert_true($top['action'] === 'restore' && (int) $top['restored_from'] === (int) $old['id'], 'restore is a new revision');
    assert_eq(20, (int) DB::value('SELECT COUNT(*) FROM project_brief_revisions WHERE project_id=?', [$f['p']]), 'still capped');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='projects.brief.restore' AND entity_id=?", [$f['p']]), 'restore audited'); },

  'editor uploads: project file upload answers JSON (image url / download) for the editor; the form upload can return to the Brief tab' => function () {
    $f = pb_fx(); act_as($f['mgr']);
    $mk = static function (): void { $tmp = tempnam(sys_get_temp_dir(), 'pbf'); file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"); $_FILES = ['file' => ['name' => 'spec.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]; };
    $mk();
    try {
      $r = pb_json('/projects/'.$f['p'].'/files', ['category' => 'brief']);
      $j = json_decode((string) $r->body(), true);
      assert_true($r->status() === 200 && $j['name'] === 'spec.pdf' && $j['image'] === false && $j['download'] === url('/files/'.$j['id']) && $j['url'] === url('/files/'.$j['id'].'/view'), 'json: '.$r->body());
      assert_eq('brief', DB::value('SELECT category FROM files WHERE id=?', [$j['id']]));
      $mk();
      $r = http('POST', '/projects/'.$f['p'].'/files', ['category' => 'brief', 'return' => 'brief']);
      assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '?tab=brief'), 'back to the brief');
      assert_true(str_contains(Html::clean('<p><a href="'.url('/files/'.$j['id']).'">spec</a></p>', ProjectBrief::fileGuard($f['p'])), 'href="'.url('/files/'.$j['id']).'"'), 'link to own project file kept');
      $b = (string) http('GET', '/projects/'.$f['p'].'?tab=brief')->body();
      assert_true(str_contains($b, 'data-brief-docs') && str_contains($b, 'spec.pdf') && str_contains($b, 'name="return" value="brief"'), 'documents card lists project files with upload'); }
    finally { $_FILES = []; foreach (DB::column("SELECT id FROM files WHERE entity_type='project' AND entity_id=? AND original_name='spec.pdf'", [$f['p']]) as $id) { Core\Storage::remove((int) $id); } } },
];
