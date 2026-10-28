<?= partial('partials/page_head', ['title' => 'Attendance settings', 'subtitle' => 'Idle detection, monitoring and retention.', 'crumbs' => [['System'], ['Attendance settings']]]) ?>
<div class="grid g-side">
  <form class="card" method="post" action="<?= e(url('/attendance/settings')) ?>"><?= csrf_field() ?>
    <?= partial('attendance::settings') ?>
    <div class="form-actions mt"><button class="btn primary"><?= icon('check', 'sm') ?> Save settings</button></div>
  </form>
  <div class="card soft-mint" style="border:0"><?= illus('security-shield') ?><h4 class="mt">Transparent by design</h4><p class="small mb0">Screenshots are never taken without an explicit, revocable consent from each person, only while their own timer runs, and are visible to them at all times. Old images are deleted automatically.</p></div>
</div>
