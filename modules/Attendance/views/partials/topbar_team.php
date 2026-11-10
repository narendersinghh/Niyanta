<?php /* Team "N active" pill in the top bar. Managers / HR (attendance.view_all): their team with times and projects; employees: their own team, names and status only. The popup loads from /time/team/now when opened; tt-live.js refreshes the count every 2 minutes while the tab is visible. $active, $full */
$n = (int) $active; ?>
<details class="dd tm-now" data-team-now>
  <summary class="tm-pill" aria-label="<?= $n ?> team member<?= $n === 1 ? '' : 's' ?> active now. Show who is working" title="Team members tracking right now"><span class="tm-dot<?= $n ? ' on' : '' ?>" aria-hidden="true"></span><b data-tm-count><?= $n ?></b><span class="tm-lbl">active</span></summary>
  <div class="dd-menu wide tm-pop" data-tm-pop aria-live="polite"><p class="tm-empty">Loading...</p></div>
</details>
