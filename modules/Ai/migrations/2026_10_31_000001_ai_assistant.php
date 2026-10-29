<?php
/**
 * AI assistant (chat drawer): conversations, messages and configurable models.
 * Moves the old single-provider settings (ai.provider + ai.<provider>_key + ai.model_<provider>) into ai_models rows,
 * switches the module itself on (the assistant is now controlled by the ai.enabled setting) and lets employees chat.
 */
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return [
  'up' => static function (PDO $pdo) use ($t): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_models (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL, preset VARCHAR(30) NOT NULL DEFAULT 'custom', provider VARCHAR(30) NOT NULL DEFAULT 'openai_compatible', base_url VARCHAR(255) NULL, api_key_enc TEXT NULL, model VARCHAR(150) NOT NULL, max_tokens INT UNSIGNED NULL, temperature DECIMAL(3,2) NULL, is_default TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NULL, KEY idx_aim_default (active, is_default))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_conversations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL DEFAULT 'New chat', model_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY idx_aic_user (user_id, updated_at), CONSTRAINT fk_aic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id INT UNSIGNED NOT NULL, role ENUM('user','assistant','tool') NOT NULL, content MEDIUMTEXT NULL, tool_name VARCHAR(60) NULL, tool_payload JSON NULL, tokens_in INT UNSIGNED NOT NULL DEFAULT 0, tokens_out INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, KEY idx_aimsg_conv (conversation_id, id), CONSTRAINT fk_aimsg_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE)$t");
    $now = date('Y-m-d H:i:s');
    $set = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'ai.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!(int) $pdo->query('SELECT COUNT(*) FROM ai_models')->fetchColumn()) {
      $old = ['anthropic' => ['Claude', 'anthropic', 'anthropic', 'https://api.anthropic.com', 'claude-opus-5-5'], 'openai' => ['ChatGPT', 'openai', 'openai_compatible', 'https://api.openai.com/v1', 'gpt-5-mini'], 'openrouter' => ['OpenRouter', 'openrouter', 'openai_compatible', 'https://openrouter.ai/api/v1', 'openai/gpt-5-mini']];
      $current = (string) ($set['ai.provider'] ?? 'anthropic');
      $ins = $pdo->prepare('INSERT INTO ai_models (label, preset, provider, base_url, api_key_enc, model, max_tokens, is_default, active, created_at) VALUES (?,?,?,?,?,?,?,?,1,?)');
      $hasDefault = false;
      foreach ($old as $p => [$label, $preset, $type, $url, $model]) {
        $key = $set['ai.'.$p.'_key'] ?? null;
        if ($key === null || $key === '') { continue; }
        $def = $p === $current && !$hasDefault;
        $hasDefault = $hasDefault || $def;
        $ins->execute([$label, $preset, $type, $url, $key, trim((string) ($set['ai.model_'.$p] ?? '')) ?: $model, (int) ($set['ai.max_output_tokens'] ?? 0) ?: null, (int) $def, $now]); }
      if (!$hasDefault) { $pdo->exec('UPDATE ai_models SET is_default=1 ORDER BY id LIMIT 1'); } }
    // Keys now live encrypted on their ai_models row only.
    $pdo->exec("UPDATE settings SET `value`=NULL WHERE `key` IN ('ai.openai_key','ai.anthropic_key','ai.openrouter_key')");
    $pdo->exec("INSERT INTO modules (`key`, enabled, updated_at) VALUES ('ai', 1, '$now') ON DUPLICATE KEY UPDATE enabled=1, updated_at=VALUES(updated_at)");
    $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'ai.use' FROM roles WHERE slug IN ('manager','hr','employee')");
    $pdo->exec("DELETE FROM role_permissions WHERE permission='ai.search'");
    $pdo->exec("DELETE FROM permissions WHERE `key`='ai.search'");
    try { \Core\Cache::forget('modules_state'); \Core\Cache::forget('settings'); foreach ($pdo->query('SELECT id FROM roles')->fetchAll(PDO::FETCH_COLUMN) as $id) { \Core\Cache::forget('perms_role_'.$id); } }
    catch (\Throwable) {} },
  'down' => ['DROP TABLE IF EXISTS ai_messages, ai_conversations, ai_models'],
];
