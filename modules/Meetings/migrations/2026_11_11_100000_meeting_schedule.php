<?php
/* Planned meetings: a meeting is still a client log entry (client_activities type meeting). ends_at holds the planned end, meeting_status is
   'scheduled' (created with New meeting, notes not attached yet), 'cancelled' or NULL (a plain log entry). meeting_attendees lists team members
   (notified when added) and client contacts. */
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
return ['up' => [
"ALTER TABLE client_activities ADD COLUMN ends_at DATETIME NULL AFTER occurred_at, ADD COLUMN meeting_status VARCHAR(12) NULL AFTER ends_at, ADD KEY idx_ca_meet (type, occurred_at)",
"CREATE TABLE meeting_attendees (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, activity_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  UNIQUE KEY uq_ma_user (activity_id, user_id), UNIQUE KEY uq_ma_contact (activity_id, contact_id), KEY idx_ma_user (user_id),
  CONSTRAINT fk_ma_act FOREIGN KEY (activity_id) REFERENCES client_activities(id) ON DELETE CASCADE, CONSTRAINT fk_ma_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_ma_contact FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE CASCADE)$t",
], 'down' => ['DROP TABLE IF EXISTS meeting_attendees', 'ALTER TABLE client_activities DROP KEY idx_ca_meet, DROP COLUMN ends_at, DROP COLUMN meeting_status']];
