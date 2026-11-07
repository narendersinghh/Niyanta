<?php
/* Client logs organised by project: internal projects (no client) can keep a log too, so client_id becomes nullable (still cascading with the client);
   project_id gets an index and a foreign key (SET NULL; logs of client-less projects are removed with the project by ProjectController::destroy). */
return ['up' => static function (PDO $db): void {
  $db->exec('ALTER TABLE client_activities DROP FOREIGN KEY fk_ca_client');
  $db->exec('ALTER TABLE client_activities MODIFY client_id INT UNSIGNED NULL');
  $db->exec('ALTER TABLE client_activities ADD CONSTRAINT fk_ca_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE');
  $db->exec('UPDATE client_activities a LEFT JOIN projects p ON p.id=a.project_id SET a.project_id=NULL WHERE a.project_id IS NOT NULL AND p.id IS NULL');
  $db->exec('ALTER TABLE client_activities ADD KEY idx_ca_project (project_id, occurred_at), ADD CONSTRAINT fk_ca_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL'); },
'down' => static function (PDO $db): void {
  $db->exec('DELETE FROM client_activities WHERE client_id IS NULL');
  $db->exec('ALTER TABLE client_activities DROP FOREIGN KEY fk_ca_project');
  $db->exec('ALTER TABLE client_activities DROP KEY idx_ca_project');
  $db->exec('ALTER TABLE client_activities DROP FOREIGN KEY fk_ca_client');
  $db->exec('ALTER TABLE client_activities MODIFY client_id INT UNSIGNED NOT NULL');
  $db->exec('ALTER TABLE client_activities ADD CONSTRAINT fk_ca_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE'); }];
