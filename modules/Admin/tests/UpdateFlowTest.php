<?php
use Core\{DB, Settings};
use Modules\Admin\Services\UpdateService;

/** Updates run after the response (no 504 on slow hosts): the start, the guard, the status endpoint the page polls; the pre-update backup is database-only. */
return [
  'updates: apply needs a check first; begin creates a running row; the page polls its JSON status' => function () {
    $admin = (int) DB::value("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='super_admin' LIMIT 1");
    act_as($admin);
    Settings::set('updates.last_check', []);
    $before = (int) DB::value('SELECT COUNT(*) FROM update_logs');
    http('POST', '/admin/updates/apply', ['confirm' => 'UPDATE']);
    assert_eq($before, (int) DB::value('SELECT COUNT(*) FROM update_logs'), 'nothing started without a check');
    $id = UpdateService::begin('release', '9.9.9', 'v9.9.9', $admin);
    $j = json_decode(http('GET', '/admin/updates/'.$id.'?format=json')->body(), true);
    assert_true($j['status'] === 'running' && !$j['finished'] && str_contains($j['log'], 'continues in the background'), 'running with a log');
    $page = http('GET', '/admin/updates/'.$id)->body();
    assert_true(str_contains($page, 'data-update-poll') && str_contains($page, 'refreshes by itself'), 'the page follows it');
    DB::update('update_logs', ['status' => 'success', 'finished_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    assert_true(!str_contains(http('GET', '/admin/updates/'.$id)->body(), 'data-update-poll'), 'no polling once finished');
    DB::update('update_logs', ['status' => 'running', 'finished_at' => null, 'started_at' => date('Y-m-d H:i:s', time() - 3600)], 'id=?', [$id]);
    assert_true(str_contains(http('GET', '/admin/updates/'.$id)->body(), 'probably stopped'), 'a stuck update is explained');
    DB::delete('update_logs', 'id=?', [$id]);
    assert_true(str_contains((string) file_get_contents(NY_ROOT.'/modules/Admin/Services/UpdateService.php'), "BackupService::create('database'"), 'pre-update backup is database only'); },
];
