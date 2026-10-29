<?php
// Monthly salary from tracked time: expected hours of the month (NULL when the payslip was prorated by days).
return ['up' => ["ALTER TABLE payslips ADD COLUMN expected_hours DECIMAL(8,2) NULL AFTER worked_hours"], 'down' => ['ALTER TABLE payslips DROP COLUMN expected_hours']];
