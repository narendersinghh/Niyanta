<?php
use Modules\Admin\Services\SettingsSchema;
$logo = setting('branding.logo'); $fav = setting('branding.favicon');
$v = static fn (string $k) => SettingsSchema::value(['key' => $k]);
$pvStyle = '--primary:'.$v('appearance.primary_color').';--sidebar:'.$v('appearance.sidebar_color').';--lav:'.$v('appearance.accent_1').';--mint:'.$v('appearance.accent_2').';--lemon:'.$v('appearance.accent_3').';--peach:'.$v('appearance.accent_4').';--fs:'.(int) $v('appearance.font_size').'px;--r:'.(int) $v('appearance.radius').'px';
$font = (string) setting('appearance.font_family', 'Plus Jakarta Sans');
$thumb = static fn (?string $f, string $ic) => $f ? '<img src="'.e(url('assets/uploads/branding/'.basename($f))).'" alt="">' : icon($ic, 'lg');
$custom = SettingsSchema::customTheme();
$uploads = '<section class="card set-card"><header class="set-card-head"><h3>Company logo</h3><p>Shown next to your company name under the Niyanta brand in the sidebar and on the sign-in page. The favicon sets the browser tab icon.</p></header><div class="form-grid">'
  .'<div class="field full'.(errors()['logo'] ?? null ? ' has-error' : '').'"><label for="f-logo">Company logo</label><div class="upload-row"><span class="thumb">'.$thumb($logo, 'image').'</span><div class="grow"><input type="file" id="f-logo" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-logo-input><div class="hint">PNG, JPG or WebP, up to 1 MB. Square works best.</div>'.($logo ? '<label class="check xs mt"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>' : '').'</div></div>'.error_for('logo').'</div>'
  .'<div class="field full'.(errors()['favicon'] ?? null ? ' has-error' : '').'"><label for="f-favicon">Favicon</label><div class="upload-row"><span class="thumb">'.$thumb($fav, 'star').'</span><div class="grow"><input type="file" id="f-favicon" name="favicon" accept=".png,.ico,image/png,image/x-icon"><div class="hint">PNG or ICO, up to 256 KB.</div>'.($fav ? '<label class="check xs mt"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>' : '').'</div></div>'.error_for('favicon').'</div>'
  .'</div></section>';
$reset = '<form method="post" action="'.e(url('/admin/settings/appearance/reset')).'" data-confirm="Remove all custom colours, font size and corner radius and use the default theme?">'.csrf_field().'<button class="btn ghost"'.($custom ? '' : ' disabled title="Already using the default theme"').'>'.icon('refresh', 'sm').' Reset to default theme</button></form>';
?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'appearance', 'actions' => $reset]) ?>
<?php if ($custom): ?><div class="alert info"><?= icon('palette', 'sm') ?><div>You are using custom colours. Colours left at their default follow the built-in palette, use <b>Reset to default theme</b> to drop every override.</div></div><?php endif ?>
<div class="appear-grid">
  <?= partial('admin::settings/_form', ['tab' => 'appearance', 'fields' => $fields, 'sections' => $sections, 'multipart' => true, 'inner' => $uploads, 'submit' => 'Save appearance']) ?>
  <aside class="card pv-card-wrap"><div class="card-head"><h3>Live preview</h3><span class="pill pill-lav" data-pv-state>Saved</span></div>
    <div class="pv" data-preview-box style="<?= e($pvStyle) ?>;--font:'<?= e(preg_replace('/[^\w ]/', '', $font)) ?>',system-ui,sans-serif">
      <div class="pv-side"><div class="pv-brand"><i data-pv-logo><?= $logo ? '<img src="'.e(url('assets/uploads/branding/'.basename((string) $logo))).'" alt="">' : e(mb_substr(company_name(), 0, 1)) ?></i><span class="truncate"><b data-pv="name"><?= e(company_name()) ?></b><small>on Niyanta</small></span></div>
        <span class="pv-nav on">Dashboard</span><span class="pv-nav">Clients</span><span class="pv-nav">Projects</span><span class="pv-nav">Team</span></div>
      <div class="pv-body"><h5>Good morning</h5>
        <div class="pv-tiles"><div class="pv-tile" style="--a:var(--lav)"><span>Projects</span><b>12</b></div><div class="pv-tile" style="--a:var(--mint)"><span>Tasks done</span><b>48</b></div><div class="pv-tile" style="--a:var(--lemon)"><span>Hours</span><b>32h</b></div><div class="pv-tile" style="--a:var(--peach)"><span>Invoices</span><b>7</b></div></div>
        <div class="pv-card"><span>Website redesign</span><span class="pv-btn">Open</span></div></div>
    </div>
    <p class="xs muted mt mb0">Updates as you edit. Saving applies it for everyone.</p>
    <button type="button" class="btn ghost sm mt" data-reset-colors="<?= e(json_encode(array_intersect_key(SettingsSchema::DEFAULTS, array_flip(SettingsSchema::THEME_KEYS)))) ?>"><?= icon('refresh', 'sm') ?> Preview default colours</button>
  </aside>
</div>
