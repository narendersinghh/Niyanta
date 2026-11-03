<?php
// Hours-based pay breakdown on payslips: payable hours (after the flexible hours cap), overtime, the month's hourly rate and the flex mode used.
return ['up' => ["ALTER TABLE payslips ADD COLUMN payable_hours DECIMAL(8,2) NULL AFTER expected_hours, ADD COLUMN overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER payable_hours, ADD COLUMN overtime_pay DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER overtime_hours, ADD COLUMN hour_rate DECIMAL(14,4) NULL AFTER overtime_pay, ADD COLUMN flex_mode VARCHAR(10) NULL AFTER hour_rate"],
  'down' => ['ALTER TABLE payslips DROP COLUMN flex_mode, DROP COLUMN hour_rate, DROP COLUMN overtime_pay, DROP COLUMN overtime_hours, DROP COLUMN payable_hours']];
