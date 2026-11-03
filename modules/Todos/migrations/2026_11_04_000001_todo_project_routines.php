<?php
/* One task form everywhere: to-dos get start date, estimate and a stage; routines may belong to a project (occurrences become project tasks, linked in todo_series_tasks). */
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"ALTER TABLE todo_series MODIFY title VARCHAR(255) NOT NULL, MODIFY priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium', MODIFY assignee_id INT UNSIGNED NULL, ADD project_id INT UNSIGNED NULL AFTER description, ADD milestone_id INT UNSIGNED NULL AFTER project_id, ADD status ENUM('todo','in_progress','review') NOT NULL DEFAULT 'todo' AFTER priority, ADD estimate_hours DECIMAL(8,2) NULL AFTER status, ADD billable TINYINT(1) NOT NULL DEFAULT 0 AFTER estimate_hours, ADD KEY idx_ts_project (project_id), ADD CONSTRAINT fk_ts_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE, ADD CONSTRAINT fk_ts_milestone FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE SET NULL",
"ALTER TABLE todos MODIFY title VARCHAR(255) NOT NULL, MODIFY priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium', ADD stage ENUM('todo','in_progress','review') NULL AFTER status, ADD start_date DATE NULL AFTER due_date, ADD estimate_hours DECIMAL(8,2) NULL AFTER start_date",
"CREATE TABLE todo_series_tasks (series_id INT UNSIGNED NOT NULL, occurrence DATE NOT NULL, task_id INT UNSIGNED NULL, created_at DATETIME NULL, PRIMARY KEY (series_id, occurrence), KEY idx_tst_task (task_id), CONSTRAINT fk_tst_series FOREIGN KEY (series_id) REFERENCES todo_series(id) ON DELETE CASCADE, CONSTRAINT fk_tst_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL)$t",
], 'down' => [
'DROP TABLE IF EXISTS todo_series_tasks',
"UPDATE todos SET priority='high' WHERE priority='urgent'",
"ALTER TABLE todos DROP COLUMN stage, DROP COLUMN start_date, DROP COLUMN estimate_hours, MODIFY priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium'",
'DELETE FROM todo_series WHERE project_id IS NOT NULL OR assignee_id IS NULL',
"UPDATE todo_series SET priority='high' WHERE priority='urgent'",
"ALTER TABLE todo_series DROP FOREIGN KEY fk_ts_project, DROP FOREIGN KEY fk_ts_milestone, DROP KEY idx_ts_project, DROP COLUMN project_id, DROP COLUMN milestone_id, DROP COLUMN status, DROP COLUMN estimate_hours, DROP COLUMN billable, MODIFY assignee_id INT UNSIGNED NOT NULL, MODIFY priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium'",
]];
