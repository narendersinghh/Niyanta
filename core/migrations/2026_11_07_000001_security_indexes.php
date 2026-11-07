<?php
// Indexes for the core queries that grow with use: reset-token pruning, retention jobs, the audit filters and user lists (module tables get theirs in their own migrations).
$add = static function (\PDO $db, string $table, string $name, string $cols): void {
  if (!$db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='$table' AND index_name='$name' LIMIT 1")->fetchColumn()) { $db->exec("ALTER TABLE `$table` ADD KEY `$name` ($cols)"); } };
$drop = static function (\PDO $db, string $table, string $name): void {
  if ($db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='$table' AND index_name='$name' LIMIT 1")->fetchColumn()) { $db->exec("ALTER TABLE `$table` DROP KEY `$name`"); } };
$idx = ['password_resets' => ['idx_pr_expires' => 'expires_at'], 'audit_logs' => ['idx_audit_action_created' => 'action, created_at'], 'notifications' => ['idx_notif_read' => 'read_at'], 'mail_queue' => ['idx_mq_sent' => 'status, sent_at'], 'users' => ['idx_users_status_name' => 'status, name'], 'files' => ['idx_files_uploader' => 'uploaded_by']];
return [
  'up' => static function (\PDO $db) use ($add, $idx): void { foreach ($idx as $t => $list) { foreach ($list as $n => $c) { $add($db, $t, $n, $c); } } },
  'down' => static function (\PDO $db) use ($drop, $idx): void { foreach ($idx as $t => $list) { foreach (array_keys($list) as $n) { $drop($db, $t, $n); } } }];
