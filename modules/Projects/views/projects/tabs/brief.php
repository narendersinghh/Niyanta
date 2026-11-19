<?php
/* Brief tab: one rich document per project (read / edit / an old version) with the project's documents and the version history beside it. */
$canEdit = $canManage;
$html = (string) ($brief['body_html'] ?? '');
$size = static fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
$ext = static fn (string $n) => strtolower(pathinfo($n, PATHINFO_EXTENSION) ?: 'file');
$kind = static fn (string $x) => match (true) { $x === 'pdf' => 'x-pdf', in_array($x, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => 'x-img', in_array($x, ['doc', 'docx', 'txt', 'ppt', 'pptx'], true) => 'x-doc', in_array($x, ['xls', 'xlsx', 'csv'], true) => 'x-sheet', $x === 'zip' => 'x-zip', default => '' };
$base = '/projects/'.$id.'?tab=brief';
$edited = $brief ? 'Last edited'.($brief['updated_name'] ? ' by '.$brief['updated_name'] : '').', '.ago($brief['updated_at']) : 'Not written yet';
$maxMb = (int) setting('storage.max_upload_mb', 10);
/* toolbar: [command, label, glyph or svg path, shortcut] ; '|' separates groups */
$svg = static fn (string $d) => '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="'.$d.'"/></svg>';
$tools = [
  ['block:p', 'Paragraph', '<span class="rte-g">P</span>', ''], ['block:h2', 'Heading 2', '<span class="rte-g">H2</span>', ''], ['block:h3', 'Heading 3', '<span class="rte-g">H3</span>', ''], '|',
  ['bold', 'Bold', '<span class="rte-g"><b>B</b></span>', 'Ctrl+B'], ['italic', 'Italic', '<span class="rte-g"><i>I</i></span>', 'Ctrl+I'], ['underline', 'Underline', '<span class="rte-g"><u>U</u></span>', 'Ctrl+U'], ['strikeThrough', 'Strikethrough', '<span class="rte-g"><s>S</s></span>', ''], '|',
  ['ul', 'Bulleted list', $svg('M8 5h9M8 10h9M8 15h9M3.5 5h.01M3.5 10h.01M3.5 15h.01'), ''], ['ol', 'Numbered list', $svg('M9 5h8M9 10h8M9 15h8M3 3.5h1.2v3.5M2.8 12.2c.4-.6 2-.7 2 .3 0 .8-2 1.6-2 2.8h2.2'), ''], ['check', 'Checklist', $svg('M2.5 3.5h4v4h-4zM2.5 12.5h4v4h-4zM3.4 14.4l.9.9 1.6-1.8M10 5.5h7.5M10 14.5h7.5'), 'Ctrl+Enter toggles'], '|',
  ['quote', 'Quote', $svg('M4 6h4.5v4.5H6.2L4.5 14M11.5 6H16v4.5h-2.3L12 14'), ''], ['code', 'Code block', $svg('M7 5.5 2.5 10 7 14.5M13 5.5l4.5 4.5-4.5 4.5'), ''], ['link', 'Link', $svg('M8.5 11.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5l-1 1M11.5 8.5a3.5 3.5 0 0 0-5 0L4 11a3.5 3.5 0 0 0 5 5l1-1'), 'Ctrl+K'],
  ['image', 'Insert image', $svg('M3 4h14v12H3zM3 13l4-4 3 3 2-2 5 5M12.5 7.5h.01'), ''], ['hr', 'Horizontal line', $svg('M2.5 10h15M6 5.5h8M6 14.5h8'), ''], ['table', 'Insert table', $svg('M3 4h14v12H3zM3 8h14M3 12h14M10 4v12'), ''], '|',
  ['clear', 'Clear formatting', $svg('M5 4.5h10M10 4.5 7.5 15.5M3.5 3.5l13 13'), ''], ['undo', 'Undo', $svg('M7 4 3 8l4 4M3 8h8.5a4.5 4.5 0 0 1 0 9H9'), 'Ctrl+Z'], ['redo', 'Redo', $svg('M13 4l4 4-4 4M17 8H8.5a4.5 4.5 0 0 0 0 9H11'), 'Ctrl+Y'],
];
?>
<div class="grid g-main brief">
  <div class="stack">
    <section class="card brief-card">
      <div class="card-head brief-head">
        <div class="sec-head mb0"><span class="sec-ic"><?= icon('book') ?></span><div><h3>Project brief</h3><div class="xs muted" data-rte-meta><?= e($edited) ?></div></div></div>
        <?php if ($briefMode === 'read' && $canEdit && !$brief && $briefPrefill !== ''): ?><a class="btn sm soft" href="<?= e(url($base.'&mode=edit')) ?>"><?= icon('edit', 'sm') ?> Start the brief</a><?php endif ?>
      </div>
      <?php if ($briefMode === 'rev'): ?>
        <div class="alert info"><?= icon('history') ?><div><b>Version of <?= e(fdatetime($briefRev['created_at'])) ?></b> by <?= e($briefRev['user_name'] ?? 'a removed user') ?>.<?= $briefRev['action'] === 'restore' ? ' (a restore)' : '' ?> This is how the brief looked then.</div></div>
        <div class="rte-doc brief-doc"><?= $briefRev['body_html'] !== '' ? $briefRev['body_html'] : '<p class="muted">This version was empty.</p>' ?></div>
        <div class="form-actions brief-rev-acts">
          <a class="btn ghost" href="<?= e(url($base)) ?>"><?= icon('arrow-left', 'sm') ?> Back to the current brief</a>
          <?php if ($canEdit): ?><form method="post" action="<?= e(url('/projects/'.$id.'/brief/revisions/'.(int) $briefRev['id'].'/restore')) ?>" data-confirm="Restore this version? The current brief stays in History."><?= csrf_field() ?><button class="btn primary"><?= icon('refresh', 'sm') ?> Restore this version</button></form><?php endif ?>
        </div>
      <?php elseif ($briefMode === 'edit'): ?>
        <form class="rte" method="post" action="<?= e(url('/projects/'.$id.'/brief')) ?>" data-rte data-save="<?= e('projects/'.$id.'/brief') ?>" data-upload="<?= e('projects/'.$id.'/files') ?>" data-key="ny-brief-<?= $id ?>" data-version="<?= e((string) ($brief['version'] ?? '')) ?>" data-max="<?= $maxMb * 1048576 ?>"<?= $brief ? '' : ' data-new' ?>><?= csrf_field() ?>
          <div class="rte-draft" data-rte-draft hidden><?= icon('info', 'sm') ?><span class="grow">Unsaved changes from <b data-rte-draft-time></b> are kept in this browser.</span><button type="button" class="btn sm soft" data-rte-draft-use>Restore them</button><button type="button" class="btn sm ghost" data-rte-draft-drop>Discard</button></div>
          <?php if (!$brief && $briefPrefill !== ''): ?><div class="rte-note"><?= icon('sparkles', 'sm') ?> Started from the project's description, scope and deliverables. Edit it and Save to create the brief.</div><?php endif ?>
          <div class="rte-bar" role="toolbar" aria-label="Formatting" aria-controls="rte-area">
            <?php foreach ($tools as $t): ?><?php if ($t === '|'): ?><span class="rte-sep" aria-hidden="true"></span><?php else: [$cmd, $label, $glyph, $kbd] = $t; ?><button type="button" class="rte-b" data-cmd="<?= e($cmd) ?>" aria-label="<?= e($label) ?>" title="<?= e($label.($kbd ? ' ('.$kbd.')' : '')) ?>"<?= in_array($cmd, ['image', 'hr', 'table', 'clear', 'undo', 'redo', 'link'], true) ? '' : ' aria-pressed="false"' ?> tabindex="-1"><?= $glyph ?></button><?php endif ?><?php endforeach ?>
            <span class="rte-tbl" data-rte-table hidden><span class="rte-sep" aria-hidden="true"></span><button type="button" class="rte-b rte-t" data-cmd="row+" aria-label="Add row below" title="Add row below" tabindex="-1">+ Row</button><button type="button" class="rte-b rte-t" data-cmd="col+" aria-label="Add column right" title="Add column right" tabindex="-1">+ Col</button><button type="button" class="rte-b rte-t" data-cmd="row-" aria-label="Delete row" title="Delete row" tabindex="-1">&minus; Row</button><button type="button" class="rte-b rte-t" data-cmd="col-" aria-label="Delete column" title="Delete column" tabindex="-1">&minus; Col</button></span>
          </div>
          <div id="rte-area" class="rte-doc rte-area" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Project brief" spellcheck="true" data-rte-area data-placeholder="Write the scope, requirements, access notes, links and anything the team should know. Paste from Word or Google Docs, drop images here."><?= $brief ? $html : $briefPrefill ?></div>
          <input type="file" data-rte-file hidden multiple accept="image/png,image/jpeg,image/webp,image/gif">
          <textarea name="body" hidden data-rte-body></textarea><input type="hidden" name="version" value="<?= e((string) ($brief['version'] ?? '')) ?>" data-rte-version>
          <div class="rte-foot">
            <span class="rte-status" data-rte-status role="status" aria-live="polite"><?= $brief ? 'Saved' : 'Not saved yet' ?></span>
            <span class="grow"></span>
            <span class="xs muted rte-hint">Ctrl+S saves</span>
            <a class="btn ghost" href="<?= e(url($base)) ?>" data-rte-close>Close</a>
            <button class="btn primary" data-rte-save><?= icon('check', 'sm') ?> Save</button>
          </div>
        </form>
      <?php elseif ($html !== ''): ?>
        <div class="rte-doc brief-doc"><?= $html ?></div>
      <?php elseif ($briefPrefill !== ''): ?>
        <div class="alert info"><?= icon('info') ?><div>No brief yet. Below are the description, scope and deliverables from the project details<?= $canEdit ? '; <a href="'.e(url($base.'&mode=edit')).'">start the brief</a> to build on them.' : '.' ?></div></div>
        <div class="rte-doc brief-doc"><?= $briefPrefill ?></div>
      <?php else: ?>
        <?= empty_state('No brief yet', 'One place for the scope, requirements, access notes, links and anything the team should know about this project.', 'empty-box', $canEdit ? '<a class="btn primary mt" href="'.e(url($base.'&mode=edit')).'">'.icon('edit', 'sm').' Start the brief</a>' : '') ?>
      <?php endif ?>
    </section>
  </div>
  <aside class="stack">
    <section class="card brief-side">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('folder-open') ?></span><h3>Documents</h3></div><span class="pill pill-gray" data-brief-doc-n><?= count($files) ?></span></div>
      <div class="brief-docs" data-brief-docs>
        <?php foreach ($files as $f): $x = $ext($f['original_name']); ?>
        <div class="bdoc"><span class="file-ext <?= $kind($x) ?>"><?= e(mb_strtoupper(mb_substr($x, 0, 4))) ?></span><div class="grow"><a class="b truncate db" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><div class="xs muted truncate"><?= e($size((int) $f['size'])) ?> · <?= e($f['uploader'] ?? 'Unknown') ?> · <?= e(ago($f['created_at'])) ?></div></div><a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" aria-label="Download <?= e($f['original_name']) ?>" title="Download"><?= icon('download', 'sm') ?></a></div>
        <?php endforeach ?>
      </div>
      <?php if (!$files): ?><p class="muted small mb0" data-brief-docs-empty>Contracts, requirement docs and designs you upload here sit next to the brief.</p><?php endif ?>
      <div class="brief-side-acts"><?php if ($canUpload): ?><button type="button" class="btn sm soft" data-modal="file-new"><?= icon('upload', 'sm') ?> Upload</button><?php endif ?><a class="btn sm ghost" href="<?= e(url('/projects/'.$id.'?tab=files')) ?>">All files <?= icon('arrow-right', 'sm') ?></a></div>
    </section>
    <section class="card brief-side">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('history') ?></span><h3>History</h3></div><span class="pill pill-gray"><?= count($briefRevisions) ?></span></div>
      <?php if (!$briefRevisions): ?><p class="muted small mb0">Every save is kept here (the last <?= \Modules\Projects\Services\ProjectBrief::KEEP ?> versions), so you can look back or restore.</p><?php else: ?>
      <ol class="brief-hist">
        <?php foreach ($briefRevisions as $i => $r): $on = $briefRev && (int) $briefRev['id'] === (int) $r['id']; ?>
        <li class="<?= $on ? 'on' : '' ?>"><a href="<?= e(url($base.'&rev='.(int) $r['id'])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= avatar($r['user_name'] ?? '?', null, 'sm') ?><span class="grow"><b class="truncate db"><?= e($r['user_name'] ?? 'Removed user') ?></b><span class="xs muted" title="<?= e(fdatetime($r['created_at'])) ?>"><?= e(ago($r['created_at'])) ?><?= $r['action'] === 'restore' ? ' · restored' : '' ?></span></span><?php if ($i === 0): ?><span class="pill pill-mint">Current</span><?php endif ?></a></li>
        <?php endforeach ?>
      </ol>
      <?php endif ?>
    </section>
  </aside>
</div>
<?php if ($canUpload): ?>
<dialog class="modal" id="file-new" aria-labelledby="file-new-title">
  <div class="modal-head"><h3 id="file-new-title">Upload a document</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/projects/'.$id.'/files')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="return" value="brief">
    <label class="dropzone"><?= icon('upload', 'lg') ?><span class="b">Choose a file</span><span class="xs muted">PDF, Office, images, ZIP · max <?= $maxMb ?> MB</span><input type="file" name="file" required data-file-label></label>
    <div class="field"><label for="file-cat">Category</label><select id="file-cat" name="category"><?php foreach (['brief' => 'Brief', 'general' => 'General', 'design' => 'Design', 'contract' => 'Contract', 'deliverable' => 'Deliverable'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select><div class="hint">Everyone on the project can see and download it. It also appears in Files.</div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('upload', 'sm') ?> Upload</button></div>
  </form>
</dialog>
<?php endif ?>
