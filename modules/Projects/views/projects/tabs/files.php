<?php
$canUpload = can('projects.manage') || can('projects.tasks.manage') || can('projects.tasks.update_own');
$size = static fn (int $b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB';
$ext = static fn (string $n) => strtoupper(pathinfo($n, PATHINFO_EXTENSION) ?: 'FILE');
$me = (int) user()['id'];
?>
<div class="grid g-side">
  <div class="card">
    <div class="card-head"><h3>Project files</h3><span class="pill pill-gray"><?= count($files) ?></span></div>
    <?php if (!$files): ?><?= empty_state('No files yet', 'Briefs, contracts, designs and deliverables live here.', 'empty-box') ?><?php else: ?>
    <div class="file-list">
      <?php foreach ($files as $f): ?>
      <div class="file-item">
        <span class="file-ext"><?= e(mb_substr($ext($f['original_name']), 0, 4)) ?></span>
        <div class="grow"><a class="b truncate" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><div class="xs muted"><?= e(ucfirst($f['category'])) ?> · <?= e($size((int) $f['size'])) ?> · <?= e($f['uploader'] ?? 'Unknown') ?> · <?= e(ago($f['created_at'])) ?></div></div>
        <a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" aria-label="Download"><?= icon('download', 'sm') ?></a>
        <?php if ($canManage || (int) $f['uploaded_by'] === $me): ?><form method="post" action="<?= e(url('/files/'.$f['id'].'/delete')) ?>" data-confirm="Delete this file?"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
    <?php if ($taskFiles): ?>
    <h4 class="mt2">Task attachments</h4>
    <div class="file-list">
      <?php foreach ($taskFiles as $f): ?>
      <div class="file-item"><span class="file-ext soft"><?= e(mb_substr($ext($f['original_name']), 0, 4)) ?></span><div class="grow"><a class="b truncate" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><div class="xs muted">on <a href="<?= e(url('/tasks/'.$f['task_id'])) ?>"><?= e($f['task_title']) ?></a> · <?= e($size((int) $f['size'])) ?> · <?= e(ago($f['created_at'])) ?></div></div><a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" aria-label="Download"><?= icon('download', 'sm') ?></a></div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
  <?php if ($canUpload): ?>
  <div class="card">
    <h3>Upload</h3>
    <form class="form" method="post" action="<?= e(url('/projects/'.$pr['id'].'/files')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <label class="dropzone"><?= icon('upload', 'lg') ?><span class="b">Choose a file</span><span class="xs muted">PDF, Office, images, ZIP · max <?= (int) setting('storage.max_upload_mb', 10) ?> MB</span><input type="file" name="file" required data-file-label></label>
      <div class="field"><label>Category</label><select name="category"><?php foreach (['general' => 'General', 'brief' => 'Brief', 'design' => 'Design', 'contract' => 'Contract', 'deliverable' => 'Deliverable'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select></div>
      <button class="btn primary"><?= icon('upload', 'sm') ?> Upload</button>
    </form>
  </div>
  <?php endif ?>
</div>
