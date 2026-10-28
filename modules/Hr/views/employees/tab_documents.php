<?php $canManage = can('hr.documents.manage'); $grouped = []; foreach ($docs as $f) { $grouped[$f['category']][] = $f; } ?>
<div class="grid <?= $canManage ? 'g-side' : '' ?>">
  <div class="card">
    <div class="card-head"><h3><?= icon('shield') ?> Documents</h3><span class="pill pill-gray"><?= count($docs) ?> files</span></div>
    <?php if (!$docs): ?><?= empty_state('No documents yet', $canManage ? 'Upload ID proofs and signed agreements using the form.' : 'HR has not uploaded any documents for you yet.', 'invoice-paper') ?><?php else: ?>
    <?php foreach ($cats as $ck => $cl): if (empty($grouped[$ck])) { continue; } ?>
      <h4 class="mt"><?= e($cl) ?></h4>
      <div class="list"><?php foreach ($grouped[$ck] as $f): ?>
        <div class="list-item">
          <span class="hr-doc-ic soft-<?= $ck === 'id_proof' ? 'sky' : ($ck === 'agreement' ? 'lav' : 'lemon') ?>"><?= icon('file-text', 'sm') ?></span>
          <div class="grow"><b class="truncate"><?= e($f['original_name']) ?></b><div class="meta"><?= e($f['size'] >= 1048576 ? round($f['size'] / 1048576, 1).' MB' : ($f['size'] >= 1024 ? round($f['size'] / 1024).' KB' : $f['size'].' B')) ?> · <?= fdatetime($f['created_at']) ?><?= $f['uploader'] ? ' · by '.e($f['uploader']) : '' ?></div></div>
          <a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'].'/view')) ?>" target="_blank" rel="noopener" title="View"><?= icon('eye', 'sm') ?></a>
          <a class="btn icon sm ghost" href="<?= e(url('/files/'.$f['id'])) ?>" title="Download"><?= icon('download', 'sm') ?></a>
          <?php if ($canManage): ?><form method="post" action="<?= e(url('/files/'.$f['id'].'/delete')) ?>" data-confirm="Delete this document permanently?"><?= csrf_field() ?><button class="btn icon sm danger" title="Delete"><?= icon('trash', 'sm') ?></button></form><?php endif ?>
        </div>
      <?php endforeach ?></div>
    <?php endforeach ?>
    <?php endif ?>
  </div>
  <?php if ($canManage): ?>
  <form class="card form" method="post" enctype="multipart/form-data" action="<?= e(url('/hr/employees/'.$emp['id'].'/documents')) ?>"><?= csrf_field() ?>
    <?= illus('security-shield', 'hr-form-illus') ?>
    <h3 class="mb0">Secure upload</h3>
    <p class="small muted mb0">Files are stored privately and only HR and <?= e(explode(' ', $emp['name'])[0]) ?> can open them. PDF, images or Word up to <?= (int) setting('storage.max_upload_mb', 10) ?> MB.</p>
    <div class="field"><label>Category *</label><select name="category" required><?php foreach ($cats as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, old('category')) ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('category') ?></div>
    <div class="field"><label>File *</label><input type="file" class="hr-file" name="file" required accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"><?= error_for('file') ?></div>
    <div class="form-actions"><button class="btn primary"><?= icon('upload', 'sm') ?> Upload</button></div>
  </form>
  <?php endif ?>
</div>
