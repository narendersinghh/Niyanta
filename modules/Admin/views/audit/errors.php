<?php $srcC = ['server' => ['lav', 'server'], 'browser' => ['sky', 'globe'], 'desktop' => ['peach', 'monitor']]; ?>
<?= partial('partials/page_head', ['title' => 'Error logs', 'subtitle' => 'Errors from the server, browsers and the desktop tracker. Repeats are grouped; resolved errors reopen if they happen again.', 'crumbs' => [['System'], ['Audit log', '/admin/audit'], ['Error logs']],
  'actions' => user()['role_slug'] === 'super_admin' ? '<form method="post" action="'.e(url('/admin/audit/errors/clear')).'" data-confirm="Permanently delete all resolved errors?">'.csrf_field().'<button class="btn ghost">'.icon('trash', 'sm').' Clear resolved</button></form>' : '']) ?>
<?= partial('admin::audit/_tabs', ['active' => 'errors']) ?>
<div class="grid g4 mb">
  <div class="tile dark"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) $stats['open'] ?></div><div class="lbl">Open errors</div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('server') ?></span><div><div class="num"><?= (int) $stats['server'] ?></div><div class="lbl">Server</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('globe') ?></span><div><div class="num"><?= (int) $stats['browser'] ?></div><div class="lbl">Browser</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('monitor') ?></span><div><div class="num"><?= (int) $stats['desktop'] ?></div><div class="lbl">Desktop tracker</div></div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search message, file or page">
    <select name="source" data-autosubmit><option value="">All sources</option><?php foreach ($srcC as $k => $_): ?><option value="<?= $k ?>"<?= selected($k, $f['source']) ?>><?= ucfirst($k) ?></option><?php endforeach ?></select>
    <select name="state" data-autosubmit><option value="open"<?= selected('open', $f['state']) ?>>Open</option><option value="resolved"<?= selected('resolved', $f['state']) ?>>Resolved</option><option value="all"<?= selected('all', $f['state']) ?>>All</option></select>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
  </form>
  <?php if (!$p['items']): ?><?= empty_state($f['state'] === 'open' ? 'No open errors' : 'Nothing here', 'When something goes wrong in the app or the desktop tracker it shows up here.', 'celebrate') ?><?php else: ?>
  <div class="err-list">
  <?php foreach ($p['items'] as $r): [$c, $ic] = $srcC[$r['source']] ?? ['gray', 'alert']; $ctx = json_decode((string) $r['context'], true) ?: []; ?>
    <details class="err-item">
      <summary>
        <span class="err-ic" style="--c:var(--<?= $c ?>)"><?= icon($ic, 'sm') ?></span>
        <div class="grow"><b class="err-msg"><?= e($r['message']) ?></b><div class="xs muted"><?= e(ucfirst($r['source'])) ?> · <?= e($r['level']) ?><?= $r['file'] ? ' · '.e($r['file']).($r['line'] ? ':'.(int) $r['line'] : '') : '' ?></div></div>
        <div class="right nowrap"><span class="pill pill-<?= $r['occurrences'] > 9 ? 'red' : 'gray' ?>"><?= number_format((int) $r['occurrences']) ?>×</span><div class="xs muted mt"><?= e(ago($r['last_seen'])) ?></div></div>
      </summary>
      <div class="err-body">
        <dl class="kv small"><dt>First seen</dt><dd><?= e(fdatetime($r['first_seen'])) ?></dd><dt>Last seen</dt><dd><?= e(fdatetime($r['last_seen'])) ?></dd><dt>Page / request</dt><dd class="mono"><?= e($r['url'] ?: '-') ?></dd><dt>User</dt><dd><?= e($r['user_name'] ?: 'Guest / system') ?></dd><dt>Version</dt><dd><?= e($r['app_version'] ?: '-') ?></dd><dt>Client</dt><dd class="xs muted"><?= e($r['user_agent'] ?: '-') ?> · <?= e($r['ip'] ?: '-') ?></dd></dl>
        <?php foreach (['trace' => 'Stack trace', 'stack' => 'Stack trace'] as $k => $lbl): if (!empty($ctx[$k])): ?><pre class="err-pre"><?= e($ctx[$k]) ?></pre><?php endif; endforeach ?>
        <?php $extra = array_diff_key($ctx, ['trace' => 1, 'stack' => 1]); if ($extra): ?><pre class="err-pre"><?= e(json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif ?>
        <?php if (!$r['resolved_at']): ?><form method="post" action="<?= e(url('/admin/audit/errors/'.$r['id'].'/resolve')) ?>"><?= csrf_field() ?><button class="btn sm mint"><?= icon('check', 'sm') ?> Mark resolved</button></form><?php else: ?><span class="pill pill-mint">Resolved <?= e(ago($r['resolved_at'])) ?></span><?php endif ?>
      </div>
    </details>
  <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
