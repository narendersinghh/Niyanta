<?php
// Draft runs created by the monthly scheduler (payroll.auto_draft) are marked so the run list and run page can say so.
return ['up' => ['ALTER TABLE payroll_runs ADD COLUMN auto_created TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by'], 'down' => ['ALTER TABLE payroll_runs DROP COLUMN auto_created']];
