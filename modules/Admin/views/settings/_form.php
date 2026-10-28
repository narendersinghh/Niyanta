<?php
/* Generic schema form: $tab, $fields, optional $inner (html before the actions), $multipart, $title, $submit */
$oldS = old('s', []); $oldS = is_array($oldS) ? $oldS : []; $errs = errors(); $section = null; $open = false;
$val = static fn (array $f) => array_key_exists($f['key'], $oldS) && (is_array($oldS[$f['key']]) === ($f['type'] === 'days')) ? $oldS[$f['key']] : \Modules\Admin\Services\SettingsSchema::value($f);
$id = static fn (string $k) => 'f-'.str_replace('.', '-', $k);
?>
<form class="card form" method="post" action="<?= e(url('/admin/settings/'.$tab)) ?>"<?= !empty($multipart) ? ' enctype="multipart/form-data"' : '' ?>><?= csrf_field() ?>
<?php if (!empty($title)): ?><div class="card-head"><h3><?= e($title) ?></h3></div><?php endif ?>
<?php foreach ($fields as $f): $k = $f['key']; $v = $val($f); $name = 's['.$k.']'; ?>
<?php if (($f['section'] ?? $section) !== $section || !$open): $section = $f['section'] ?? $section; ?><?= $open ? '</div></div>' : '' ?><div class="set-section"<?= !empty($f['show']) ? ' data-show-when="'.e($f['show']).'"' : '' ?>><?php if ($section): ?><h4><?= e($section) ?></h4><?php endif ?><div class="form-grid"><?php $open = true; endif ?>
  <div class="field<?= !empty($f['span']) || $f['type'] === 'textarea' ? ' full' : '' ?><?= isset($errs[$k]) ? ' has-error' : '' ?>"<?= !empty($f['show']) ? ' data-show-when="'.e($f['show']).'"' : '' ?>>
  <?php if ($f['type'] !== 'toggle'): ?><label for="<?= e($id($k)) ?>"><?= e($f['label']) ?></label><?php endif ?>
  <?php switch ($f['type']):
    case 'select': ?><select id="<?= e($id($k)) ?>" name="<?= e($name) ?>"<?= !empty($f['preview']) ? ' data-preview="'.e($f['preview']).'"' : '' ?><?= $k === 'email.driver' ? ' data-driver' : '' ?>><?php foreach ($f['options'] as $ok => $ol): ?><option value="<?= e($ok) ?>"<?= selected($ok, $v) ?>><?= e($ol) ?></option><?php endforeach ?></select><?php break;
    case 'toggle': ?><label class="switch-row" for="<?= e($id($k)) ?>"><span class="switch"><input type="checkbox" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" value="1"<?= checked(in_array((string) $v, ['1', 'on', 'true'], true)) ?>><span></span></span><span class="t"><?= e($f['label']) ?></span></label><?php break;
    case 'textarea': ?><textarea id="<?= e($id($k)) ?>" name="<?= e($name) ?>" rows="3"><?= e($v) ?></textarea><?php break;
    case 'color': ?><div class="color-field"><input type="color" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? $v : '#000000') ?>" data-var="<?= e($f['var'] ?? '') ?>"><code data-hex><?= e($v) ?></code></div><?php break;
    case 'range': ?><div class="range-field"><input type="range" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" min="<?= (int) $f['min'] ?>" max="<?= (int) $f['max'] ?>" step="1" value="<?= (int) $v ?>" data-var="<?= e($f['var'] ?? '') ?>" data-unit="<?= e($f['unit'] ?? '') ?>"><output><?= (int) $v ?><?= e($f['unit'] ?? '') ?></output></div><?php break;
    case 'secret': $set = (string) \Core\Settings::get($k, '') !== ''; ?><input type="password" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" autocomplete="new-password" placeholder="<?= $set ? '•••••••• saved — leave blank to keep' : 'Not set' ?>"><?php if ($set): ?><div class="flex between"><span class="secret-set"><?= icon('lock', 'sm') ?> Stored encrypted</span><label class="check xs"><input type="checkbox" name="clear[<?= e($k) ?>]" value="1"> Remove</label></div><?php endif ?><?php break;
    case 'days': $sel = is_array($v) ? $v : array_filter(explode(',', (string) $v)); ?><div class="day-picks" role="group" aria-label="<?= e($f['label']) ?>"><?php foreach (\Modules\Admin\Services\SettingsSchema::DAYS as $dk => $dl): ?><label><input type="checkbox" name="s[<?= e($k) ?>][]" value="<?= e($dk) ?>"<?= checked(in_array((string) $dk, array_map('strval', (array) $sel), true)) ?>><span><?= e($dl) ?></span></label><?php endforeach ?></div><?php break;
    default: ?><input type="<?= e(in_array($f['type'], ['email', 'number', 'time'], true) ? $f['type'] : 'text') ?>" id="<?= e($id($k)) ?>" name="<?= e($name) ?>" value="<?= e($v) ?>"<?= isset($f['min']) ? ' min="'.(int) $f['min'].'"' : '' ?><?= isset($f['max']) ? ' max="'.(int) $f['max'].'"' : '' ?><?= isset($f['step']) ? ' step="'.e($f['step']).'"' : '' ?><?= !empty($f['preview']) ? ' data-preview="'.e($f['preview']).'"' : '' ?><?= !empty($f['placeholder']) ? ' placeholder="'.e($f['placeholder']).'"' : '' ?><?= !empty($f['autocomplete']) ? ' autocomplete="'.e($f['autocomplete']).'"' : '' ?><?= str_contains($f['rules'] ?? '', 'required') ? ' required' : '' ?>><?php endswitch ?>
  <?php if (!empty($f['hint'])): ?><div class="hint"><?= e($f['hint']) ?></div><?php endif ?><?= error_for($k) ?>
  </div>
<?php endforeach ?>
<?= $open ? '</div></div>' : '' ?>
<?= $inner ?? '' ?>
<div class="form-actions"><button type="submit" class="btn primary"><?= icon('check', 'sm') ?> <?= e($submit ?? 'Save changes') ?></button></div>
</form>
