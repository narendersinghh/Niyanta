<?php
namespace Modules\Attendance\Services;
use Core\DB;
use Modules\Attendance\Controllers\HubController;

/**
 * Per-device split of one person's day (time hub Day timesheet and Screenshots). A device is the agent token that tracked the work
 * (time_entries / screenshots / activity_slots.agent_token_id; a screenshot or slot without one falls back to its entry's token).
 * Work without a token (web timer, manual entries) is grouped as key 0, "Manual". The split shows only when 2+ devices tracked that day.
 */
final class Devices {
  /**
   * The day's devices, most tracked time first, Manual last: key => [key, param, name, os, secs, shots, act (% or null)].
   * Time comes from the day's loaded entries ($entries from BaseController::day, so the device totals add up to the day total);
   * screenshots and activity from one grouped query. Empty unless at least two devices tracked something.
   */
  public static function day(int $uid, string $date, array $entries): array {
    $range = [$uid, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00'];
    $sm = HubController::meetingFlag('screenshots') ? 's.meeting=0' : '1'; $am = HubController::meetingFlag('activity_slots') ? 'a.meeting=0' : '1';
    $d = [];
    foreach ($entries as $e) { $k = (int) ($e['agent_token_id'] ?? 0); $d[$k]['secs'] = ($d[$k]['secs'] ?? 0) + (int) $e['dur']; }
    foreach (DB::all("SELECT 'sh' AS w, COALESCE(s.agent_token_id, te.agent_token_id, 0) AS k, COUNT(*) AS n, SUM(CASE WHEN $sm THEN s.activity_pct END) AS a, COUNT(CASE WHEN $sm AND s.activity_pct IS NOT NULL THEN 1 END) AS s FROM screenshots s LEFT JOIN time_entries te ON te.id=s.time_entry_id WHERE s.user_id=? AND s.taken_at>=? AND s.taken_at<? GROUP BY k
      UNION ALL SELECT 'sl', COALESCE(a.agent_token_id, te.agent_token_id, 0), COUNT(*), SUM(CASE WHEN $am THEN LEAST(a.active_seconds, a.slot_seconds) END), SUM(CASE WHEN $am THEN a.slot_seconds END) FROM activity_slots a LEFT JOIN time_entries te ON te.id=a.time_entry_id WHERE a.user_id=? AND a.slot_start>=? AND a.slot_start<? GROUP BY 2", [...$range, ...$range]) as $r) {
      $k = (int) $r['k'];
      if ($r['w'] === 'sh') { $d[$k]['shots'] = (int) $r['n']; $d[$k]['pa'] = (int) $r['a']; $d[$k]['pn'] = (int) $r['s']; }
      else { $d[$k]['sa'] = (int) $r['a']; $d[$k]['ss'] = (int) $r['s']; } }
    $ids = array_values(array_filter(array_keys($d)));
    if (count($ids) < 2) { return []; }
    $tok = DB::all('SELECT id, name, device_os FROM agent_tokens WHERE user_id=? AND id IN '.DB::in($ids), [$uid, ...$ids]);
    $tok = array_column($tok, null, 'id');
    $names = array_count_values(array_map(static fn ($t) => mb_strtolower($t['name']), $tok));
    $out = [];
    foreach ($d as $k => $x) {
      $t = $tok[$k] ?? null;
      $name = $k === 0 ? 'Manual' : ($t ? $t['name'] : 'Device '.$k);
      if ($t && $names[mb_strtolower($t['name'])] > 1) { $name .= $t['device_os'] ? ' ('.$t['device_os'].')' : ' #'.$k; }
      $act = !empty($x['ss']) ? (int) round(100 * $x['sa'] / $x['ss']) : (!empty($x['pn']) ? (int) round($x['pa'] / $x['pn']) : null);
      $out[$k] = ['key' => $k, 'param' => $k === 0 ? 'manual' : (string) $k, 'name' => $name, 'os' => $t['device_os'] ?? null, 'secs' => (int) ($x['secs'] ?? 0), 'shots' => (int) ($x['shots'] ?? 0), 'act' => $act]; }
    uasort($out, static fn ($a, $b) => [$a['key'] === 0, -$a['secs'], -$a['shots'], $a['key']] <=> [$b['key'] === 0, -$b['secs'], -$b['shots'], $b['key']]);
    return $out; }

  /** The device picked by the ?device= value ('manual' or a token id), or null when it is not one of the day's devices. */
  public static function pick(array $devices, mixed $param): ?array {
    foreach ($devices as $d) { if ($param !== null && $param !== '' && (string) $param === $d['param']) { return $d; } }
    return null; }

  /** Whether a time entry row (agent_token_id) belongs to device key $k (0: no token, Manual). */
  public static function owns(array $row, int $k): bool { return (int) ($row['agent_token_id'] ?? 0) === $k; } }
