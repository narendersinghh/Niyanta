<?php
/* Project brief: one rich text document per project (scope, requirements, links, notes) written in the in-house editor and sanitized by Core\Html on save.
   project_brief_revisions keeps the last 20 saved versions per project (ProjectBrief::save prunes older ones) for History and Restore. */
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"CREATE TABLE IF NOT EXISTS project_briefs (project_id INT UNSIGNED NOT NULL PRIMARY KEY, body_html MEDIUMTEXT NOT NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_pb_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, CONSTRAINT fk_pb_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL)$t",
"CREATE TABLE IF NOT EXISTS project_brief_revisions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id INT UNSIGNED NOT NULL, body_html MEDIUMTEXT NOT NULL, user_id INT UNSIGNED NULL, action VARCHAR(10) NOT NULL DEFAULT 'save', restored_from INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_pbr_project (project_id, id), CONSTRAINT fk_pbr_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, CONSTRAINT fk_pbr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL)$t",
], 'down' => ['DROP TABLE IF EXISTS project_brief_revisions, project_briefs']];
