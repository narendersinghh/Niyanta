<?php
$ext = static function (?string $name): array { $x = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
  return match (true) { $x === 'pdf' => ['PDF', 'pdf'], in_array($x, ['doc', 'docx'], true) => ['DOC', 'doc'], in_array($x, ['xls', 'xlsx', 'csv'], true) => [strtoupper($x === 'csv' ? 'CSV' : 'XLS'), 'xls'], in_array($x, ['ppt', 'pptx'], true) => ['PPT', 'ppt'], in_array($x, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) => ['IMG', 'img'], $x === 'zip' => ['ZIP', 'zip'], default => [$x !== '' ? strtoupper(substr($x, 0, 4)) : 'FILE', 'txt'] }; };
$size = static fn ($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : (int) $b.' B');
$q = $_GET['q'] ?? ''; $showPending = ($_GET['show'] ?? '') === 'pending';
$aud = count($audience);
$maxMb = (int) setting('storage.max_upload_mb', 10);
$docForm = static function (?array $d) use ($cats, $maxMb): string { ob_start(); $fid = 'd'.($d['id'] ?? 'new'); ?>
  <div class="field"><label for="<?= $fid ?>-t">Title *</label><input id="<?= $fid ?>-t" type="text" name="title" maxlength="190" value="<?= e($d['title'] ?? old('title')) ?>" placeholder="e.g. Leave & holiday policy 2026" required></div>
  <div class="form-grid">
    <div class="field"><label for="<?= $fid ?>-c">Category *</label><select id="<?= $fid ?>-c" name="category" required><?php foreach ($cats as $k => [$l]): ?><option value="<?= e($k) ?>"<?= selected($k, $d['category'] ?? old('category', 'policy')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="<?= $fid ?>-f"><?= $d ? 'Replace file' : 'File *' ?></label><input id="<?= $fid ?>-f" class="hr-file" type="file" name="file"<?= $d ? '' : ' required' ?>><div class="hint">PDF, Word, Excel, PowerPoint, images or ZIP up to <?= $maxMb ?> MB.<?= $d ? ' Leave empty to keep the current file.' : '' ?></div></div>
  </div>
  <div class="field"><label for="<?= $fid ?>-d">Short description</label><textarea id="<?= $fid ?>-d" name="description" rows="3" maxlength="2000" placeholder="What is it and who should read it?"><?= e($d['description'] ?? old('description')) ?></textarea></div>
  <label class="check"><input type="checkbox" name="requires_ack" value="1"<?= checked($d['requires_ack'] ?? old('requires_ack')) ?>> Requires acknowledgement, everyone confirms “I have read this”</label>
  <?php if ($d): ?><label class="check small"><input type="checkbox" name="reset_acks" value="1"> When replacing the file, ask everyone to acknowledge again</label><?php else: ?><label class="check small"><input type="checkbox" name="notify" value="1" checked> Notify everyone when acknowledgement is required</label><?php endif ?>
<?php return (string) ob_get_clean(); };
?>
<?= partial('partials/page_head', ['title' => 'Company documents', 'subtitle' => 'Policies, the handbook, forms and benefits, always the latest version.', 'crumbs' => [['My HR'], ['Company documents']], 'actions' => $manage ? '<button type="button" class="btn primary" data-modal="doc-new">'.icon('upload', 'sm').' Upload document</button>' : '']) ?>
<?php if ($pending && !$showPending): ?><div class="alert warn"><?= icon('alert', 'sm') ?><div class="grow"><b><?= (int) $pending ?> document<?= $pending === 1 ? '' : 's' ?> need<?= $pending === 1 ? 's' : '' ?> your acknowledgement.</b> Please read and confirm.</div><a class="btn sm ghost" href="<?= e(url('/hr/documents?show=pending')) ?>">Show</a></div><?php endif ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('book') ?></span><div><div class="num"><?= (int) $total ?></div><div class="lbl">Documents published</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('shield') ?></span><div><div class="num"><?= (int) ($counts['policy'] ?? 0) ?></div><div class="lbl">Policies</div></div></div>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('clipboard') ?></span><div><div class="num"><?= (int) ($counts['forms'] ?? 0) ?></div><div class="lbl">Forms & templates</div></div></div>
  <a class="tile bg-peach" href="<?= e(url('/hr/documents?show=pending')) ?>"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) $pending ?></div><div class="lbl">Waiting for my OK</div></div></a>
</div>
<div class="card mb">
  <form class="hr-toolbar mb0" method="get">
    <div class="chips" aria-label="Category">
      <a class="chip<?= !isset($cats[$cat]) && !$showPending ? ' on' : '' ?>" href="<?= e(url('/hr/documents'.($q !== '' ? '?q='.rawurlencode($q) : ''))) ?>">All <span class="n"><?= (int) $total ?></span></a>
      <?php foreach ($cats as $k => [$l, $ic]): ?><a class="chip<?= $cat === $k ? ' on' : '' ?>" href="<?= e(url('/hr/documents?'.http_build_query(array_filter(['category' => $k, 'q' => $q])))) ?>"><?= icon($ic, 'sm') ?> <?= e($l) ?> <span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach ?>
    </div>
    <?php if (isset($cats[$cat])): ?><input type="hidden" name="category" value="<?= e($cat) ?>"><?php endif ?>
    <label class="hr-search hr-push"><span class="sr">Search documents</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search documents"></label>
  </form>
</div>
<?php if (!$docs): ?>
  <div class="card"><?= $q !== '' || isset($cats[$cat]) || $showPending
    ? empty_state($showPending ? 'You are all caught up' : 'No documents match', $showPending ? 'Every document that needs your acknowledgement has been confirmed.' : 'Try another search or category.', $showPending ? 'celebrate' : 'empty-box', '<a class="btn ghost" href="'.e(url('/hr/documents')).'">Show all documents</a>')
    : empty_state('No company documents yet', $manage ? 'Upload your leave policy, employee handbook or reimbursement form so everyone finds the latest version here.' : 'HR has not published any documents yet.', 'company-docs', $manage ? '<button type="button" class="btn primary" data-modal="doc-new">'.icon('upload', 'sm').' Upload first document</button>' : '') ?></div>
<?php else: ?>
<div class="hr-docs">
  <?php foreach ($docs as $d): $id = (int) $d['id']; [$ftLabel, $ftCls] = $ext($d['original_name']); [$catLabel, $catIc, $catCol] = $cats[$d['category']] ?? $cats['other']; $need = (int) $d['requires_ack'] && !$d['my_ack'];
    $acked = $progress[$id] ?? []; $ackN = count($acked); $ackPct = $aud ? (int) round($ackN / $aud * 100) : 0; ?>
  <article class="card hr-doc<?= $need ? ' need' : '' ?>" id="doc-<?= $id ?>">
    <div class="hr-doc-top">
      <span class="hr-ft hr-ft-<?= e($ftCls) ?>" aria-hidden="true"><?= e($ftLabel) ?></span>
      <div class="grow">
        <h3><?= e($d['title']) ?></h3>
        <div class="chips"><span class="pill pill-<?= e($catCol) ?>"><?= icon($catIc, 'sm') ?> <?= e($catLabel) ?></span><?php if ((int) $d['requires_ack']): ?><?= $d['my_ack'] ? '<span class="pill pill-mint">'.icon('check', 'sm').' Read</span>' : '<span class="pill pill-lemon">Please acknowledge</span>' ?><?php endif ?></div>
      </div>
      <?php if ($manage): ?><details class="dd"><summary class="btn icon sm ghost" aria-label="Manage <?= e($d['title']) ?>"><?= icon('more-v', 'sm') ?></summary><div class="dd-menu"><button type="button" data-modal="doc-<?= $id ?>"><?= icon('edit', 'sm') ?> Edit or replace file</button><form method="post" action="<?= e(url('/hr/documents/'.$id.'/delete')) ?>" data-confirm="Delete “<?= e($d['title']) ?>” and its file? Acknowledgements are removed too."><?= csrf_field() ?><button><?= icon('trash', 'sm') ?> Delete</button></form></div></details><?php endif ?>
    </div>
    <?php if ($d['description']): ?><p class="hr-doc-desc"><?= e($d['description']) ?></p><?php endif ?>
    <div class="hr-doc-meta"><span><?= icon('clock', 'sm') ?> Updated <?= e(fdate($d['updated_at'] ?? $d['created_at'])) ?></span><?php if ($d['size']): ?><span><?= icon('file', 'sm') ?> <?= e($size($d['size'])) ?></span><?php endif ?><?php if ($d['author']): ?><span><?= icon('user', 'sm') ?> <?= e($d['author']) ?></span><?php endif ?></div>
    <?php if ($manage && (int) $d['requires_ack']): ?>
      <button type="button" class="hr-doc-ack btn ghost" data-modal="ack-<?= $id ?>" aria-label="Acknowledgement progress"><span class="nowrap b"><?= $ackN ?>/<?= $aud ?></span><span class="progress" role="progressbar" aria-valuenow="<?= $ackPct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $ackPct ?>%"></span></span><span class="xs muted nowrap">acknowledged</span></button>
    <?php endif ?>
    <div class="hr-doc-foot">
      <?php if ($d['file_id']): ?><a class="btn sm soft" href="<?= e(url('/files/'.$d['file_id'].'/view')) ?>" target="_blank" rel="noopener"><?= icon('eye', 'sm') ?> Open</a><a class="btn sm ghost" href="<?= e(url('/files/'.$d['file_id'])) ?>" title="Download <?= e($d['original_name']) ?>" aria-label="Download"><?= icon('download', 'sm') ?></a><?php endif ?>
      <?php if ($need): ?><form method="post" action="<?= e(url('/hr/documents/'.$id.'/ack')) ?>" data-confirm="Confirm that you have read “<?= e($d['title']) ?>”?"><?= csrf_field() ?><button class="btn sm primary"><?= icon('check', 'sm') ?> I have read this</button></form><?php endif ?>
    </div>
  </article>
  <?php endforeach ?>
</div>
<?php endif ?>
<?php if ($manage): ?>
<dialog class="modal" id="doc-new"<?= errors() && old('title', null) !== null ? ' data-open' : '' ?>><div class="modal-head"><h3>Upload company document</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/documents')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><?php if (errors()): ?><div class="alert error mb0"><?= icon('alert', 'sm') ?> <?= e(implode(' ', errors())) ?></div><?php endif ?><?= $docForm(null) ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('upload', 'sm') ?> Publish</button></div></form></dialog>
<?php foreach ($docs as $d): $id = (int) $d['id']; ?>
<dialog class="modal" id="doc-<?= $id ?>"><div class="modal-head"><h3>Edit document</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/documents/'.$id)) ?>" enctype="multipart/form-data"><?= csrf_field() ?><?php if ($d['original_name']): ?><div class="hr-note"><?= icon('paperclip', 'sm') ?><div>Current file: <b><?= e($d['original_name']) ?></b></div></div><?php endif ?><?= $docForm($d) ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save changes</button></div></form></dialog>
<?php if ((int) $d['requires_ack']): $acked = $progress[$id] ?? []; $missing = array_diff_key($audience, $acked); ?>
<dialog class="modal" id="ack-<?= $id ?>"><div class="modal-head"><h3>Acknowledgements</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body">
    <p class="small muted"><b><?= e($d['title']) ?></b> · <?= count($acked) ?> of <?= $aud ?> people have confirmed.</p>
    <div class="grid g2">
      <div><h4 class="small">Not yet (<?= count($missing) ?>)</h4><div class="list hr-ack-list"><?php if (!$missing): ?><p class="small muted">Everyone has read it.</p><?php endif ?><?php foreach ($missing as $uid => $name): ?><div class="list-item"><?= avatar($name, null, 'sm') ?><b class="small grow"><?= e($name) ?></b></div><?php endforeach ?></div></div>
      <div><h4 class="small">Read (<?= count($acked) ?>)</h4><div class="list hr-ack-list"><?php if (!$acked): ?><p class="small muted">Nobody yet.</p><?php endif ?><?php foreach ($acked as $uid => $at): ?><div class="list-item"><?= avatar($audience[$uid] ?? '?', null, 'sm') ?><div class="grow"><b class="small"><?= e($audience[$uid] ?? 'Former user') ?></b><div class="meta"><?= e(fdatetime($at)) ?></div></div></div><?php endforeach ?></div></div>
    </div>
  </div></dialog>
<?php endif ?>
<?php endforeach ?>
<?php endif ?>
