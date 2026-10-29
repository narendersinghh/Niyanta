<?php
$size = static fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
$ext = static fn (string $n) => strtolower(pathinfo($n, PATHINFO_EXTENSION) ?: 'file');
$kind = static fn (string $x) => match (true) { $x === 'pdf' => 'x-pdf', in_array($x, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true) => 'x-img', in_array($x, ['doc', 'docx', 'txt', 'rtf', 'odt', 'ppt', 'pptx'], true) => 'x-doc', in_array($x, ['xls', 'xlsx', 'csv', 'ods'], true) => 'x-sheet', in_array($x, ['zip', 'rar', '7z', 'gz'], true) => 'x-zip', default => '' };
$me = (int) user()['id'];
$cats = ['general' => 'General', 'brief' => 'Brief', 'design' => 'Design', 'contract' => 'Contract', 'deliverable' => 'Deliverable'];
$row = static function (array $f, string $meta, string $actions) use ($size, $ext, $kind): string {
  $x = $ext($f['original_name']);
  return '<div class="file-item"><span class="file-ext '.$kind($x).'">'.e(mb_strtoupper(mb_substr($x, 0, 4))).'</span><div class="grow"><a class="b truncate" href="'.e(url('/files/'.$f['id'].'/view')).'" target="_blank" rel="noopener">'.e($f['original_name']).'</a><div class="xs muted">'.$meta.'</div></div><span class="xs muted num-r">'.e($size((int) $f['size'])).'</span><a class="btn icon sm ghost" href="'.e(url('/files/'.$f['id'])).'" aria-label="Download '.e($f['original_name']).'" title="Download">'.icon('download', 'sm').'</a>'.$actions.'</div>'; };
?>
<div class="grid g2">
  <section class="card">
    <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('folder-open') ?></span><h3>Project files</h3></div><span class="pill pill-gray"><?= count($files) ?></span></div>
    <?php if (!$files): ?><?= empty_state('No files yet', 'Briefs, contracts, designs and deliverables for the whole team live here.', 'empty-box', $canUpload ? '<button type="button" class="btn primary mt" data-modal="file-new">'.icon('upload', 'sm').' Upload file</button>' : '') ?><?php else: ?>
    <div class="file-list">
      <?php foreach ($files as $f): ?>
      <?= $row($f, '<span class="pill pill-gray">'.e($cats[$f['category']] ?? ucfirst((string) $f['category'])).'</span> '.e($f['uploader'] ?? 'Unknown').' · '.e(ago($f['created_at'])), $canManage || (int) $f['uploaded_by'] === $me ? '<form method="post" action="'.e(url('/files/'.$f['id'].'/delete')).'" data-confirm="Delete this file?">'.csrf_field().'<button class="btn icon sm ghost" aria-label="Delete '.e($f['original_name']).'" title="Delete">'.icon('trash', 'sm').'</button></form>' : '') ?>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </section>
  <section class="card">
    <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('paperclip') ?></span><h3>Task attachments</h3></div><span class="pill pill-gray"><?= count($taskFiles) ?></span></div>
    <?php if (!$taskFiles): ?><p class="muted small mb0">Files attached to individual tasks appear here.</p><?php else: ?>
    <div class="file-list">
      <?php foreach ($taskFiles as $f): ?><?= $row($f, 'on <a href="'.e(url('/tasks/'.$f['task_id'])).'">'.e(str_limit($f['task_title'], 50)).'</a> · '.e(ago($f['created_at'])), '') ?><?php endforeach ?>
    </div>
    <?php endif ?>
  </section>
</div>
<?php if ($canUpload): ?>
<dialog class="modal" id="file-new" aria-labelledby="file-new-title">
  <div class="modal-head"><h3 id="file-new-title">Upload a file</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/projects/'.$pr['id'].'/files')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
    <label class="dropzone"><?= icon('upload', 'lg') ?><span class="b">Choose a file</span><span class="xs muted">PDF, Office, images, ZIP · max <?= (int) setting('storage.max_upload_mb', 10) ?> MB</span><input type="file" name="file" required data-file-label></label>
    <div class="field"><label for="file-cat">Category</label><select id="file-cat" name="category"><?php foreach ($cats as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select><div class="hint">Everyone on the project can see and download it.</div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('upload', 'sm') ?> Upload</button></div>
  </form>
</dialog>
<?php endif ?>
