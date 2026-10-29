<?php
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
// Employee-specific schedule (NULL = company default from settings)
"ALTER TABLE employee_profiles ADD COLUMN work_hours_per_day DECIMAL(4,2) NULL AFTER employment_type, ADD COLUMN weekly_off_days VARCHAR(20) NULL AFTER work_hours_per_day",
// Employee-specific yearly leave quota per leave type (overrides leave_types.annual_quota)
"CREATE TABLE employee_leave_quotas (user_id INT UNSIGNED NOT NULL, leave_type_id INT UNSIGNED NOT NULL, annual_quota DECIMAL(5,1) NOT NULL, PRIMARY KEY (user_id, leave_type_id), CONSTRAINT fk_elq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_elq_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE)$t",
// Simple holiday model: fixed company holidays + a pool of optional days usable for festivals, personal or medical reasons
"INSERT INTO leave_types (name, code, annual_quota, paid, color, active) SELECT 'Optional Holiday', 'OH', 15, 1, 'sky', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM leave_types WHERE code='OH')",
"UPDATE leave_types lt SET active=0 WHERE lt.code IN ('CL','SL','EL') AND NOT EXISTS (SELECT 1 FROM leave_requests lr WHERE lr.leave_type_id=lt.id)",
// Company-wide documents (policies, handbook, forms) — file stored in core `files` (entity_type 'company_doc')
"CREATE TABLE company_documents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, category VARCHAR(40) NOT NULL DEFAULT 'policy', description TEXT NULL, file_id INT UNSIGNED NULL, requires_ack TINYINT(1) NOT NULL DEFAULT 0, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, KEY idx_cd_cat (category))$t",
"CREATE TABLE company_document_acks (document_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, acknowledged_at DATETIME NOT NULL, PRIMARY KEY (document_id, user_id), CONSTRAINT fk_cda_doc FOREIGN KEY (document_id) REFERENCES company_documents(id) ON DELETE CASCADE, CONSTRAINT fk_cda_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$t",
], 'down' => ['DROP TABLE IF EXISTS company_document_acks, company_documents, employee_leave_quotas', "DELETE FROM leave_types WHERE code='OH'", 'ALTER TABLE employee_profiles DROP COLUMN work_hours_per_day, DROP COLUMN weekly_off_days']];
