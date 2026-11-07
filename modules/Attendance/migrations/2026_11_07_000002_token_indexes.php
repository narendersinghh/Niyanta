<?php
// Device token look-ups by owner and idle expiry; time entries by person and end time (day summaries, screenshot matching).
$has = static fn (\PDO $db, string $t, string $n): bool => (bool) $db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='$t' AND index_name='$n' LIMIT 1")->fetchColumn();
$idx = ['agent_tokens' => ['idx_at_active' => 'user_id, revoked_at, last_used_at'], 'time_entries' => ['idx_te_user_ended' => 'user_id, ended_at']];
return [
  'up' => static function (\PDO $db) use ($has, $idx): void { foreach ($idx as $t => $l) { foreach ($l as $n => $c) { if (!$has($db, $t, $n)) { $db->exec("ALTER TABLE `$t` ADD KEY `$n` ($c)"); } } } },
  'down' => static function (\PDO $db) use ($has, $idx): void { foreach ($idx as $t => $l) { foreach (array_keys($l) as $n) { if ($has($db, $t, $n)) { $db->exec("ALTER TABLE `$t` DROP KEY `$n`"); } } } }];
