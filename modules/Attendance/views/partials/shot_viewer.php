<?php /* Whole-day screenshot gallery (attendance.js): a full-screen lightbox over every capture of one person's day, whatever the slot. Any [data-shot-at="n"]
   element (slot cards, activity chart bars) opens it at item n. Previous / next buttons, arrow keys, Home / End, Esc and swipe move through the day; clicking
   the image only toggles fit / actual size. The filmstrip below is grouped by hour and loads its thumbnails lazily. The list is sent once, in data-day.
   $gallery (HubController::gallery items), $who (person's name), $date */
if (empty($gallery)) { return; } ?>
<dialog class="sv" id="shot-viewer" aria-labelledby="sv-title" data-day="<?= e(json_encode($gallery)) ?>">
  <header class="sv-top">
    <div class="sv-ttl"><h2 id="sv-title"><?= e($who) ?></h2><span><?= e(date('D', strtotime($date)).', '.fdate($date)) ?><b class="sv-count" data-sv-count></b></span></div>
    <div class="sv-tools">
      <button type="button" class="sv-btn" data-sv-zoom aria-pressed="false" title="Show at actual size"><span class="sv-z">1:1</span><span class="sv-bl" data-sv-zl>Actual size</span></button>
      <a class="sv-btn" data-sv-link target="_blank" rel="noopener" title="Open the original in a new tab"><?= icon('external', 'sm') ?><span class="sv-bl">Open</span></a>
      <a class="sv-btn" data-sv-dl download title="Download this screenshot"><?= icon('download', 'sm') ?><span class="sv-bl">Download</span></a>
      <button type="button" class="sv-btn sv-x" data-sv-close aria-label="Close gallery" title="Close (Esc)"><?= icon('x') ?></button>
    </div>
  </header>
  <div class="sv-stage" data-sv-stage>
    <button type="button" class="sv-nav prev" data-sv-step="-1" aria-label="Previous screenshot" title="Previous (Left arrow)"><?= icon('chevron-left') ?></button>
    <figure class="sv-fig"><img data-sv-img alt="" draggable="false"><span class="sv-spin" aria-hidden="true"></span></figure>
    <button type="button" class="sv-nav next" data-sv-step="1" aria-label="Next screenshot" title="Next (Right arrow)"><?= icon('chevron-right') ?></button>
  </div>
  <div class="sv-cap" data-sv-cap aria-live="polite" aria-atomic="true"></div>
  <nav class="sv-strip" data-sv-strip aria-label="All screenshots of the day"></nav>
</dialog>
