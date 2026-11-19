<?php
use Core\{App, DB, Mailer};
use Modules\Attendance\Services\TimeService;

/** Regressions from production logs: unsigned arithmetic in the timer SQL, the default mail sender from cron. */
return [
  'timer seconds: discarded idle larger than the elapsed time never breaks a query (BIGINT UNSIGNED out of range)' => function () {
    $u = make_user('employee', 'robust@unit.test', ['name' => 'Zz Robust']);
    DB::insert('time_entries', ['user_id' => $u, 'started_at' => date('Y-m-d H:i:s', time() - 60), 'last_heartbeat_at' => date('Y-m-d H:i:s'), 'is_running' => 1, 'discarded_seconds' => 600, 'source' => 'agent', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    assert_eq(0, TimeService::secondsForUser($u, date('Y-m-d'), date('Y-m-d')), 'counts as 0, no SQL error');
    DB::update('users', ['status' => 'inactive'], 'id=?', [$u]); },

  'mail sender: no-reply@ the site domain from app_url, never localhost (cron has no HTTP_HOST)' => function () {
    $host = $_SERVER['HTTP_HOST'] ?? null; unset($_SERVER['HTTP_HOST']);
    $d = Mailer::domain();
    assert_true($d !== '' && $d !== 'localhost', 'a real domain: '.$d);
    $url = (string) App::config('app_url', '');
    if ($url !== '' && ($h = parse_url($url, PHP_URL_HOST)) && $h !== 'localhost' && !filter_var($h, FILTER_VALIDATE_IP)) { assert_eq(preg_replace('/^www\./', '', $h), $d, 'from app_url'); }
    if ($host !== null) { $_SERVER['HTTP_HOST'] = $host; } },
];
