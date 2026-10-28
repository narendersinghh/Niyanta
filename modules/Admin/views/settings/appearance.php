<?php
$logo = setting('branding.logo'); $fav = setting('branding.favicon');
$v = static fn (string $k) => \Modules\Admin\Services\SettingsSchema::value(['key' => $k]);
$pvStyle = '--primary:'.$v('appearance.primary_color').';--sidebar:'.$v('appearance.sidebar_color').';--lav:'.$v('appearance.accent_1').';--mint:'.$v('appearance.accent_2').';--lemon:'.$v('appearance.accent_3').';--peach:'.$v('appearance.accent_4').';--fs:'.(int) $v('appearance.font_size').'px;--r:'.(int) $v('appearance.radius').'px';
$font = (string) setting('appearance.font_family', 'Plus Jakarta Sans');
$thumb = static fn (?string $f, string $ic) => $f ? '<img src="'.e(url('assets/uploads/branding/'.basename($f))).'" alt="">' : icon($ic, 'lg');
$uploads = '<div class="set-section"><h4>Logo & favicon</h4><div class="form-grid">'
  .'<div class="field full'.(errors()['logo'] ?? null ? ' has-error' : '').'"><label for="f-logo">Logo</label><div class="upload-row"><span class="thumb">'.$thumb($logo, 'image').'</span><div class="grow"><input type="file" id="f-logo" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-logo-input><div class="hint">PNG, JPG or WebP, up to 1 MB. Square works best.</div>'.($logo ? '<label class="check xs mt"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>' : '').'</div></div>'.error_for('logo').'</div>'
  .'<div class="field full'.(errors()['favicon'] ?? null ? ' has-error' : '').'"><label for="f-favicon">Favicon</label><div class="upload-row"><span class="thumb">'.$thumb($fav, 'star').'</span><div class="grow"><input type="file" id="f-favicon" name="favicon" accept=".png,.ico,image/png,image/x-icon"><div class="hint">PNG or ICO, up to 256 KB (32×32 or 64×64).</div>'.($fav ? '<label class="check xs mt"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>' : '').'</div></div>'.error_for('favicon').'</div>'
  .'</div></div>';
?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'appearance', 'color' => 'pink']) ?>
<div class="grid appear-grid">
  <?= partial('admin::settings/_form', ['tab' => 'appearance', 'fields' => $fields, 'multipart' => true, 'inner' => $uploads, 'submit' => 'Save appearance']) ?>
  <div class="card pv-card-wrap"><div class="card-head"><h3>Live preview</h3><span class="pill pill-lav">Unsaved</span></div>
    <div class="pv" data-preview-box style="<?= e($pvStyle) ?>;--font:'<?= e(preg_replace('/[^\w ]/', '', $font)) ?>',system-ui,sans-serif">
      <div class="pv-side"><div class="pv-brand"><i data-pv-logo><?= $logo ? '<img src="'.e(url('assets/uploads/branding/'.basename((string) $logo))).'" alt="">' : e(mb_substr((string) setting('branding.app_name', 'N'), 0, 1)) ?></i><span class="truncate"><b data-pv="name"><?= e(setting('branding.app_name', 'Niyanta')) ?></b><small data-pv="tagline"><?= e(setting('branding.tagline', '')) ?></small></span></div>
        <span class="pv-nav on">Dashboard</span><span class="pv-nav">Clients</span><span class="pv-nav">Projects</span><span class="pv-nav">Team</span></div>
      <div class="pv-body"><h5>Good morning 👋</h5>
        <div class="pv-tiles"><div class="pv-tile" style="background:var(--lav)"><span>Projects</span><b>12</b></div><div class="pv-tile" style="background:var(--mint)"><span>Tasks done</span><b>48</b></div><div class="pv-tile" style="background:var(--lemon)"><span>Hours</span><b>32h</b></div><div class="pv-tile" style="background:var(--peach)"><span>Invoices</span><b>7</b></div></div>
        <div class="pv-card"><span>Website redesign</span><span class="pv-btn">Open</span></div></div>
    </div>
    <p class="xs muted mt mb0">The preview updates as you change colours, font, size and corners. Save to apply for everyone.</p>
    <div class="flex mt"><button type="button" class="btn ghost sm" data-reset-colors="<?= e(json_encode(array_intersect_key(\Modules\Admin\Services\SettingsSchema::DEFAULTS, array_flip(['appearance.primary_color', 'appearance.sidebar_color', 'appearance.accent_1', 'appearance.accent_2', 'appearance.accent_3', 'appearance.accent_4', 'appearance.font_size', 'appearance.radius'])))) ?>"><?= icon('refresh', 'sm') ?> Reset to defaults</button></div>
  </div>
</div>
