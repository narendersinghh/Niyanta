<?php
/* Schema form: $tab, $fields, optional $sections (title => helper), $before/$inner (html inside the form), $multipart, $submit, $bar (false = inline actions instead of the sticky save bar), $title (single-card title) */
use Modules\Admin\Services\{MailPresets, SettingsSchema};
$oldS = old('s', []); $oldS = is_array($oldS) ? $oldS : []; $errs = errors(); $sections ??= []; $bar ??= true;
$val = static fn (array $f) => array_key_exists($f['key'], $oldS) && (is_array($oldS[$f['key']]) === ($f['type'] === 'days')) ? $oldS[$f['key']] : SettingsSchema::value($f);
$id = static fn (string $k) => 'f-'.str_replace('.', '-', $k);
$groups = []; $cur = $title ?? '';
foreach ($fields as $f) { $cur = $f['section'] ?? $cur; $groups[$cur][] = $f; }
?>
<form class="set-form" method="post" action="<?= e(url('/admin/settings/'.$tab)) ?>"<?= !empty($multipart) ? ' enctype="multipart/form-data"' : '' ?> data-dirty-watch><?= csrf_field() ?>
<?= $before ?? '' ?>
<?php foreach ($groups as $section => $list): $show = $list[0]['show'] ?? null; ?>
<section class="card set-card"<?= $show ? ' data-show-when="'.e($show).'"' : '' ?>>
  <?php if ($section !== ''): ?><header class="set-card-head"><h3><?= e($section) ?></h3><?php if (!empty($sections[$section])): ?><p><?= e($sections[$section]) ?></p><?php endif ?></header><?php endif ?>
  <div class="form-grid">
  <?php foreach ($list as $f): $k = $f['key']; $v = $val($f); $name = 's['.$k.']'; ?>
  <div class="field<?= !empty($f['span']) || in_array($f['type'], ['textarea', 'provider'], true) ? ' full' : '' ?><?= isset($errs[$k]) ? ' has-error' : '' ?>">
  <?php if (!in_array($f['type'], ['toggle', 'provider'], true)): ?><label for="<?= e($id($k)) ?>"><?= e($f['label']) ?></label><?php endif ?>
  <?php switch ($f['type']):
    case 'select': ?><select id="<?= e($id($k)) ?>" name="<?= e($name) ?>"<?= !empty($f['preview']) ? ' data-preview="'.e($f['preview']).'"' : '' ?>><?php foreach ($f['options'] as $ok => $ol): ?><option value="<?= e($ok) ?>"<?= selected($ok, $v) ?>><?= e($ol) ?></option><?php endforeach ?></select><?php break;
    case 'provider': $presets = MailPresets::all(); ?>
      <div class="prov-grid" role="radiogroup" aria-label="<?= e($f['label']) ?>" data-presets="<?= e(json_encode(MailPresets::forJs())) ?>"><?php foreach ($presets as $pk => $p): ?><label class="prov"><input type="radio" name="<?= e($name) ?>" value="<?= e($pk) ?>"<?= checked((string) $v === $pk) ?> data-provider><span class="prov-mark"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span><span class="prov-txt"><b><?= e($p['name']) ?></b><small><?= e($p['tag']) ?></small></span><?= icon('check-circle', 'sm') ?></label><?php endforeach ?></div>
      <?php foreach ($presets as $pk => $p): ?><div class="prov-help<?= (string) $v === $pk ? '' : ' hide' ?>" data-prov-help="<?= e($pk) ?>">
        <div class="prov-help-head"><b><?= e($p['name']) ?></b><?php if ($p['host']): ?><span class="mono xs"><?= e($p['host']) ?>:<?= (int) $p['port'] ?> · <?= $p['enc'] === 'ssl' ? 'SSL/TLS' : 'STARTTLS' ?></span><?php endif ?><?php if ($p['docs']): ?><a class="xs" href="<?= e($p['docs']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external', 'sm') ?> Provider docs</a><?php endif ?></div>
        <ol class="steps-list prose small"><?php foreach ($p['steps'] as $st): ?><li><?= $st /* static, trusted markup from MailPresets */ ?></li><?php endforeach ?></ol>
        <?php if ($p['user_hint']): ?><dl class="prov-creds"><dt>Username</dt><dd><?= e($p['user_hint']) ?></dd><dt>Password</dt><dd><?= e($p['pass_hint']) ?></dd></dl><?php endif ?></div><?php endforeach ?><?php break;
    case 'toggle': ?><label class="switch-row" for="<?= e($id($k)) ?>"><span class="switch"><input type="checkbox" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" value="1"<?= checked(in_array((string) $v, ['1', 'on', 'true'], true)) ?>><span></span></span><span class="t"><?= e($f['label']) ?></span></label><?php break;
    case 'textarea': ?><textarea id="<?= e($id($k)) ?>" name="<?= e($name) ?>" rows="3"<?= !empty($f['placeholder']) ? ' placeholder="'.e($f['placeholder']).'"' : '' ?>><?= e($v) ?></textarea><?php break;
    case 'color': $hex = preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? strtolower((string) $v) : '#000000'; $def = SettingsSchema::DEFAULTS[$k] ?? ''; ?><div class="color-field<?= $hex === $def ? ' is-default' : '' ?>"><input type="color" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" value="<?= e($hex) ?>" data-var="<?= e($f['var'] ?? '') ?>" data-default="<?= e($def) ?>"><code data-hex><?= e($hex) ?></code><span class="def-tag">Default</span></div><?php break;
    case 'range': ?><div class="range-field"><input type="range" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" min="<?= (int) $f['min'] ?>" max="<?= (int) $f['max'] ?>" step="1" value="<?= (int) $v ?>" data-var="<?= e($f['var'] ?? '') ?>" data-unit="<?= e($f['unit'] ?? '') ?>"><output><?= (int) $v ?><?= e($f['unit'] ?? '') ?></output></div><?php break;
    case 'secret': $set = (string) \Core\Settings::get($k, '') !== ''; ?><input type="password" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" autocomplete="new-password" placeholder="<?= $set ? '•••••••• saved — leave blank to keep' : 'Not set' ?>"><?php if ($set): ?><div class="flex between"><span class="secret-set"><?= icon('lock', 'sm') ?> Stored encrypted</span><label class="check xs"><input type="checkbox" name="clear[<?= e($k) ?>]" value="1"> Remove</label></div><?php endif ?><?php break;
    case 'days': $sel = is_array($v) ? $v : array_filter(explode(',', (string) $v)); ?><div class="day-picks" role="group" aria-label="<?= e($f['label']) ?>"><?php foreach (SettingsSchema::DAYS as $dk => $dl): ?><label><input type="checkbox" name="s[<?= e($k) ?>][]" value="<?= e($dk) ?>"<?= checked(in_array((string) $dk, array_map('strval', (array) $sel), true)) ?>><span><?= e($dl) ?></span></label><?php endforeach ?></div><?php break;
    default: $input = '<input type="'.e(in_array($f['type'], ['email', 'number', 'time'], true) ? $f['type'] : 'text').'" id="'.e($id($k)).'" name="'.e($name).'" value="'.e($v).'"'.(isset($f['min']) ? ' min="'.(int) $f['min'].'"' : '').(isset($f['max']) ? ' max="'.(int) $f['max'].'"' : '').(isset($f['step']) ? ' step="'.e($f['step']).'"' : '')
      .(!empty($f['preview']) ? ' data-preview="'.e($f['preview']).'"' : '').(!empty($f['placeholder']) ? ' placeholder="'.e($f['placeholder']).'"' : '').(!empty($f['autocomplete']) ? ' autocomplete="'.e($f['autocomplete']).'"' : '').($k === 'email.smtp_username' ? ' data-smtp-user' : '').(str_contains($f['rules'] ?? '', 'required') ? ' required' : '').'>'; ?>
      <?= !empty($f['unit']) ? '<div class="input-unit">'.$input.'<span>'.e($f['unit']).'</span></div>' : $input ?><?php endswitch ?>
  <?php if (!empty($f['hint'])): ?><div class="hint"><?= e($f['hint']) ?></div><?php elseif ($k === 'email.smtp_username' || $k === 'email.smtp_password'): ?><div class="hint" data-cred-hint="<?= $k === 'email.smtp_username' ? 'uh' : 'ph' ?>"></div><?php endif ?><?= error_for($k) ?>
  </div>
  <?php endforeach ?>
  </div>
</section>
<?php endforeach ?>
<?= $inner ?? '' ?>
<?php if ($bar): ?>
<div class="save-bar"><span class="sb-note"><span class="sb-dot"></span><span data-dirty-text>All changes saved</span></span><span class="xs muted sb-sub">Applies to everyone · audit-logged</span><button type="submit" class="btn primary"><?= icon('check', 'sm') ?> <?= e($submit ?? 'Save changes') ?></button></div>
<?php else: ?>
<div class="form-actions"><button type="submit" class="btn primary sm"><?= icon('check', 'sm') ?> <?= e($submit ?? 'Save') ?></button></div>
<?php endif ?>
</form>
