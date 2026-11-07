<?php
/* "Add transcript or notes" inside the Log activity dialog (shown for type Meeting) and on the Meetings page upload dialog.
   $ctx: client_id, project_id (fixed), project_name, standalone; $ff (Fireflies connected), $addr (notes address for this page), $ai (summaries on) */
use Modules\Meetings\Services\Ui;
$standalone = !empty($ctx['standalone']);
$accept = '.txt,.vtt,.srt,.docx,.md,.html,.htm,.json';
?>
<section class="mt-attach<?= $standalone ? ' standalone' : '' ?>" data-meeting-attach data-preview="meetings/preview" data-ff="meetings/fireflies/recent"<?= $standalone ? ' data-standalone' : '' ?>>
  <input type="hidden" name="mt_ctx_client" value="<?= (int) ($ctx['client_id'] ?? 0) ?: '' ?>"><input type="hidden" name="mt_ctx_project" value="<?= (int) ($ctx['project_id'] ?? 0) ?: '' ?>">
  <div class="mt-attach-h"><span class="mt-ic"><?= icon('file-text', 'sm') ?></span><div class="grow"><b>Add transcript or notes</b> <?= $standalone ? '' : '<span class="xs muted">optional</span>' ?><p class="xs muted mb0">From any meeting platform: paste, upload an export<?= $ff ? ', pick a Fireflies meeting' : '' ?><?= $addr ? ' or e-mail it' : '' ?>. Niyanta detects the date, people, client and project.</p></div></div>
  <div class="mt-modes" role="tablist" aria-label="How to add the notes">
    <button type="button" class="on" data-mt-mode="paste" role="tab"><?= icon('clipboard', 'sm') ?> Paste</button>
    <button type="button" data-mt-mode="file" role="tab"><?= icon('upload', 'sm') ?> Upload file</button>
    <?php if ($ff): ?><button type="button" data-mt-mode="fireflies" role="tab"><?= icon('zap', 'sm') ?> Fireflies</button><?php endif ?>
    <?php if ($addr): ?><button type="button" data-mt-mode="email" role="tab"><?= icon('mail', 'sm') ?> E-mail</button><?php endif ?>
  </div>
  <div data-mt-panel="paste"><textarea name="mt_text" rows="4" maxlength="400000" aria-label="Transcript or notes" placeholder="Paste the transcript, Gemini or Zoom notes, or your own meeting notes…"></textarea></div>
  <div data-mt-panel="file" class="hide"><label class="mt-drop"><input type="file" name="mt_file" accept="<?= e($accept) ?>"><span><?= icon('cloud') ?><b data-mt-file-label>Choose a transcript file</b><small class="muted">.txt .vtt .srt .docx .md .html .json · Zoom, Teams, Google Meet, Gemini notes, Otter, Fireflies, tl;dv and Tactiq exports · max 8 MB</small></span></label></div>
  <?php if ($ff): ?><div data-mt-panel="fireflies" class="hide"><input type="hidden" name="mt_fireflies_id"><div class="mt-ff" data-mt-ff-list><button type="button" class="btn sm soft" data-mt-ff-load><?= icon('refresh', 'sm') ?> Load recent Fireflies meetings</button></div></div><?php endif ?>
  <?php if ($addr): ?><div data-mt-panel="email" class="hide"><div class="mt-addr"><span class="mt-ic"><?= icon('mail', 'sm') ?></span><div class="grow"><div class="xs muted">Add this address as a guest in the meeting invite, or forward / auto-share the notes or recap to it. Notes arrive as a meeting log within about 5 minutes.</div></div><code class="mono"><?= e($addr) ?></code><button type="button" class="btn icon sm ghost" data-copy="<?= e($addr) ?>" title="Copy address" aria-label="Copy address"><?= icon('copy', 'sm') ?></button></div></div><?php endif ?>
  <div class="mt-detect hide" data-mt-result aria-live="polite"></div>
  <p class="mt-note"><?= icon('shield', 'sm') ?> <span><?= e(Ui::PRIVACY) ?><?= $ai ? '' : ' AI summaries are off right now: notes are attached without a summary.' ?></span></p>
</section>
