<?php
// Time regularisation per employee, stored with each salary revision: NULL = company setting (payroll.grace_minutes, 90 by default), 0 = off, N = up to N minutes.
return ['up' => ['ALTER TABLE compensations ADD COLUMN grace_minutes SMALLINT UNSIGNED NULL AFTER amount'], 'down' => ['ALTER TABLE compensations DROP COLUMN grace_minutes']];
