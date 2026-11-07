<?php
// Editable draft payslips: calculation inputs (calc), HR edits (manual lines, line and hours overrides), shortage covered by paid leave;
// runs record when employees were notified and the last reopen. Runs published before this change count as notified at finalize.
return ['up' => [
  "ALTER TABLE payslips ADD COLUMN calc JSON NULL AFTER net, ADD COLUMN edits JSON NULL AFTER calc, ADD COLUMN shortage_cover JSON NULL AFTER edits, ADD COLUMN edited_by INT UNSIGNED NULL AFTER shortage_cover, ADD COLUMN edited_at DATETIME NULL AFTER edited_by",
  "ALTER TABLE payroll_runs ADD COLUMN notified_at DATETIME NULL AFTER finalized_at, ADD COLUMN reopened_by INT UNSIGNED NULL AFTER notified_at, ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by",
  "UPDATE payroll_runs SET notified_at=finalized_at WHERE status IN ('finalized','paid') AND notified_at IS NULL"],
  'down' => ['ALTER TABLE payroll_runs DROP COLUMN reopened_at, DROP COLUMN reopened_by, DROP COLUMN notified_at', 'ALTER TABLE payslips DROP COLUMN edited_at, DROP COLUMN edited_by, DROP COLUMN shortage_cover, DROP COLUMN edits, DROP COLUMN calc']];
