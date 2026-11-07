<?php
/* One uploaded file with its review state and the actions the viewer may take. $up (hr_uploads row + uploader, reviewer, size), $req (pending deletion request|null), $return (url to come back to) */
use Modules\Hr\Services\ProcessService as PS;
$me = (int) user()['id']; $uid = (int) $up['id']; $mod = PS::canModerateUpload($up); $selfDel = PS::canSelfDelete($up); $own = (int) $up['user_id'] === $me;
$st = ['pending' => ['Waiting for review', 'lemon'], 'approved' => ['Approved', 'mint'], 'rejected' => ['Rejected', 'red']][$up['status']] ?? ['', 'gray'];
$until = date('Y-m-d H:i:s', strtotime($up['created_at']) + PS::selfDeleteMinutes() * 60);
$x = strtolower(pathinfo((string) $up['original_name'], PATHINFO_EXTENSION));
$ret = '<input type="hidden" name="return" value="'.e($return ?? '').'">';
?>
<div class="hr-up st-<?= e($up['status']) ?>" id="upload-<?= $uid ?>">
  <span class="hr-ft hr-ft-<?= $x === 'pdf' ? 'pdf' : (in_array($x, ['doc', 'docx'], true) ? 'doc' : 'img') ?>" aria-hidden="true"><?= e($x !== '' ? strtoupper(substr($x, 0, 4)) : 'FILE') ?></span>
  <div class="grow hr-up-main">
    <?php if ($up['file_id']): ?><a class="b small" href="<?= e(url('/files/'.$up['file_id'].'/view')) ?>" target="_blank" rel="noopener"><?= e($up['original_name']) ?></a><?php else: ?><span class="b small"><?= e($up['original_name'] ?: 'File removed') ?></span><?php endif ?>
    <div class="xs muted"><?= $up['purpose'] === 'provided' ? 'Added by '.e($up['uploader'] ?? 'HR') : 'Uploaded by '.e($own && (int) $up['uploaded_by'] === $me ? 'you' : ($up['uploader'] ?? '-')) ?> · <?= e(fdatetime($up['created_at'])) ?><?= !empty($up['size']) ? ' · '.e($up['size'] >= 1048576 ? round($up['size'] / 1048576, 1).' MB' : max(1, (int) round($up['size'] / 1024)).' KB') : '' ?></div>
    <?php if ($up['purpose'] === 'submission' && $up['status'] !== 'pending' && $up['reviewer']): ?><div class="xs muted"><?= e($st[0]) ?> by <?= e($up['reviewer']) ?> · <?= e(fdatetime($up['reviewed_at'])) ?></div><?php endif ?>
    <?php if ($up['review_note']): ?><div class="hr-up-note<?= $up['status'] === 'rejected' ? ' bad' : '' ?>"><?= icon('message', 'sm') ?> <?= e($up['review_note']) ?></div><?php endif ?>
    <?php if ($req): ?><div class="hr-up-note warn"><?= icon('trash', 'sm') ?> Deletion requested<?= $req['requester'] ?? null ? ' by '.e($req['requester']) : '' ?>: <?= e($req['reason']) ?></div><?php endif ?>
    <?php if ($selfDel && !$mod): ?><div class="xs muted">You can delete this file until <?= e(ftime($until)) ?>.</div><?php endif ?>
  </div>
  <?php if ($up['purpose'] === 'submission'): ?><span class="pill pill-<?= e($st[1]) ?>"><?= e($st[0]) ?></span><?php endif ?>
  <div class="hr-up-act">
    <?php if ($up['file_id']): ?><a class="btn icon sm ghost" href="<?= e(url('/files/'.$up['file_id'])) ?>" title="Download" aria-label="Download <?= e($up['original_name']) ?>"><?= icon('download', 'sm') ?></a><?php endif ?>
    <?php if ($mod && $up['purpose'] === 'submission' && $up['status'] === 'pending'): ?>
      <form method="post" action="<?= e(url('/hr/uploads/'.$uid.'/review')) ?>"><?= csrf_field().$ret ?><input type="hidden" name="decision" value="approve"><button class="btn sm mint"><?= icon('check', 'sm') ?> Approve</button></form>
      <details class="hr-pop"><summary class="btn sm ghost"><?= icon('x', 'sm') ?> Reject</summary><form class="hr-pop-body" method="post" action="<?= e(url('/hr/uploads/'.$uid.'/review')) ?>"><?= csrf_field().$ret ?><input type="hidden" name="decision" value="reject"><label class="small b" for="rj-<?= $uid ?>">What should the employee fix?</label><textarea id="rj-<?= $uid ?>" name="note" rows="2" maxlength="500" required placeholder="e.g. The photo is blurred, please upload a clear scan."></textarea><button class="btn sm danger">Reject &amp; ask again</button></form></details>
    <?php endif ?>
    <?php if ($req && $mod): ?>
      <form method="post" action="<?= e(url('/hr/delete-requests/'.$req['id'].'/decide')) ?>" data-confirm="Delete <?= e($up['original_name']) ?> as requested?"><?= csrf_field().$ret ?><input type="hidden" name="decision" value="approve"><button class="btn sm danger"><?= icon('trash', 'sm') ?> Delete as asked</button></form>
      <form method="post" action="<?= e(url('/hr/delete-requests/'.$req['id'].'/decide')) ?>"><?= csrf_field().$ret ?><input type="hidden" name="decision" value="reject"><button class="btn sm ghost">Keep file</button></form>
    <?php elseif ($mod || $selfDel): ?>
      <form method="post" action="<?= e(url('/hr/uploads/'.$uid.'/delete')) ?>" data-confirm="Delete <?= e($up['original_name']) ?>? This cannot be undone."><?= csrf_field().$ret ?><button class="btn icon sm ghost" title="Delete" aria-label="Delete <?= e($up['original_name']) ?>"><?= icon('trash', 'sm') ?></button></form>
    <?php elseif ($own && $up['purpose'] === 'submission' && !$req): ?>
      <details class="hr-pop"><summary class="btn sm ghost"><?= icon('trash', 'sm') ?> Request deletion</summary><form class="hr-pop-body" method="post" action="<?= e(url('/hr/uploads/'.$uid.'/delete-request')) ?>"><?= csrf_field().$ret ?><label class="small b" for="dr-<?= $uid ?>">Why should HR delete it?</label><textarea id="dr-<?= $uid ?>" name="reason" rows="2" maxlength="500" required placeholder="e.g. I uploaded the wrong file."></textarea><button class="btn sm primary">Send request</button></form></details>
    <?php endif ?>
  </div>
</div>
