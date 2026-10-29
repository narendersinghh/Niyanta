<?php
// Simple leave model: fixed holidays + Optional Holiday (OH) pool + Unpaid. Legacy Casual/Sick/Earned leave
// types are removed; their requests move to OH (history kept) and OH balances are recalculated.
return ['up' => [
"UPDATE leave_requests lr JOIN leave_types old ON old.id=lr.leave_type_id AND old.code IN ('CL','SL','EL') JOIN leave_types oh ON oh.code='OH' SET lr.leave_type_id=oh.id",
"INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) SELECT DISTINCT lr.user_id, oh.id, YEAR(lr.start_date), oh.annual_quota, 0 FROM leave_requests lr JOIN leave_types oh ON oh.code='OH' AND lr.leave_type_id=oh.id",
"UPDATE leave_balances lb JOIN leave_types oh ON oh.id=lb.leave_type_id AND oh.code='OH' SET lb.used=(SELECT COALESCE(SUM(r.days),0) FROM leave_requests r WHERE r.user_id=lb.user_id AND r.leave_type_id=lb.leave_type_id AND r.status='approved' AND YEAR(r.start_date)=lb.year)",
"DELETE FROM leave_types WHERE code IN ('CL','SL','EL')",
], 'down' => []];
