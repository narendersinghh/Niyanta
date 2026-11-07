<?php
use Core\Features;
use Modules\Admin\Services\SettingsSchema;

/** Every feature of core/features.php (key => definition). */
function sf_features(): array { $o = []; foreach (Features::bundles() as $b) { $o += $b['features']; } return $o; }

/** Every schema field per tab while all features are on: [tab => fields]. */
function sf_fields(): array { $o = []; foreach (array_keys(SettingsSchema::tabs()) as $t) { $o[$t] = SettingsSchema::fields($t); } return $o; }

return [
  'settings audit: every feature-specific setting carries its feature (field key or a tab mapped only to it); feature keys and tab maps are valid' => function () {
    $bad = [];
    $known = array_keys(sf_features()) + [999 => 'ai'];
    foreach (sf_fields() as $tab => $fields) {
      foreach ($fields as $f) {
        foreach ((array) ($f['feature'] ?? []) as $x) { if (!in_array($x, $known, true)) { $bad[] = "$tab/{$f['key']}: unknown feature $x"; } }
        $own = SettingsSchema::keyFeature($f['key']);
        if ($own === null) { continue; }
        $eff = SettingsSchema::fieldFeatures($tab, $f);
        if ($eff === null || array_values(array_unique($eff)) !== [$own]) { $bad[] = "$tab/{$f['key']}: shown for ".($eff ? implode('|', $eff) : 'always').", expected only $own"; } } }
    foreach (SettingsSchema::TAB_FEATURES as $tab => $fs) {
      assert_true(!SettingsSchema::tabOn($tab) || isset(SettingsSchema::tabs()[$tab]), "TAB_FEATURES tab $tab exists");
      foreach ($fs as $x) { if (!in_array($x, $known, true)) { $bad[] = "TAB_FEATURES[$tab]: unknown feature $x"; } } }
    foreach (SettingsSchema::KEY_FEATURES as $p => $x) { if (!in_array($x, $known, true)) { $bad[] = "KEY_FEATURES[$p]: unknown feature $x"; } }
    assert_true(!$bad, "settings not linked to their feature:\n   ".implode("\n   ", $bad));
    assert_eq('payroll', SettingsSchema::keyFeature('payroll.flex_mode')); assert_eq('leave', SettingsSchema::keyFeature('hr.leave.shortage_cover')); assert_eq(null, SettingsSchema::keyFeature('hr.work_hours_per_day'), 'shared work schedule');
    assert_eq('employees', SettingsSchema::tabFor('employees')); assert_eq('leave', SettingsSchema::tabFor('leave'), 'most specific tab'); assert_eq('payroll', SettingsSchema::tabFor('reimbursements')); assert_eq(null, SettingsSchema::tabFor('todos'));
    assert_eq(['employees'], SettingsSchema::tabSwitches('employees'), 'the work schedule tab switches only the directory'); assert_eq(['payroll', 'reimbursements'], SettingsSchema::tabSwitches('payroll'));
    foreach (sf_features() as $k => $f) { assert_true(!empty($f['url']) && str_starts_with($f['url'], '/'), "feature $k has a page url"); } },

  'switching each feature off hides its settings (and tabs mapped only to it); switching back restores them' => function () {
    $all = sf_fields();
    foreach (array_unique(array_diff(array_values(SettingsSchema::KEY_FEATURES), ['ai'])) as $fk) {
      $mine = [];
      foreach ($all as $tab => $fields) { foreach ($fields as $f) { if (SettingsSchema::keyFeature($f['key']) === $fk) { $mine[] = "$tab/{$f['key']}"; } } }
      Features::set('feature:'.$fk, false);
      try {
        foreach (SettingsSchema::TAB_FEATURES as $tab => $fs) { if ($fs === [$fk]) { assert_true(!SettingsSchema::tabOn($tab) && !isset(SettingsSchema::tabs()[$tab]), "tab $tab hidden with $fk off"); } }
        foreach (array_keys(SettingsSchema::tabs()) as $tab) { foreach (SettingsSchema::fields($tab) as $f) { assert_true(SettingsSchema::keyFeature($f['key']) !== $fk, "$tab/{$f['key']} hidden while $fk is off"); } } }
      finally { Features::set('feature:'.$fk, true); }
      foreach ($mine as $m) { [$tab, $key] = explode('/', $m, 2); assert_true(in_array($key, array_column(SettingsSchema::fields($tab), 'key'), true), "$m back when $fk is on"); } } },

  'Settings › Features: a confirmed switch, page link and settings link for every feature; each settings section has its feature switch' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    $html = (string) http('GET', '/admin/settings/features')->body();
    foreach (sf_features() as $k => $f) {
      assert_true(str_contains($html, '/admin/modules/feature:'.$k), "switch for $k");
      assert_true(str_contains($html, 'href="'.url($f['url']).'"'), "page link for $k");
      if ($u = SettingsSchema::settingsUrl($k)) { assert_true(str_contains($html, 'href="'.url($u).'"'), "settings link for $k"); } }
    assert_true(substr_count($html, 'data-feature-switch') >= count(sf_features()) + count(Features::bundles()), 'every switch asks for confirmation');
    foreach (array_keys(SettingsSchema::TAB_FEATURES) as $tab) {
      if (isset(\Modules\Admin\Controllers\SettingsController::PAGES[$tab])) { continue; }
      $page = (string) http('GET', '/admin/settings/'.$tab)->body();
      foreach (SettingsSchema::tabSwitches($tab) as $fk) { assert_true(str_contains($page, '/admin/modules/feature:'.$fk) && str_contains($page, 'data-feature-switch'), "settings/$tab switches $fk"); } }
    assert_true(str_contains((string) http('GET', '/admin/settings/leave')->body(), 'This feature is on'), 'single-feature section says so');
    assert_true(str_contains((string) http('GET', '/admin/settings/payroll')->body(), 'Reimbursements is on'), 'multi-feature section names each'); },

  'feature switch from its settings section: back to the section, or to Settings › Features when the section disappears; audit-logged' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    $r = http('POST', '/admin/modules/feature:leave', ['return' => '/admin/settings/leave']);
    assert_true(!feature_on('leave'), 'leave off');
    assert_true(str_ends_with($r->headers()['Location'] ?? '', '/admin/settings/features#feature-leave'), 'section gone: to Features ('.($r->headers()['Location'] ?? '').')');
    $r = http('POST', '/admin/modules/feature:leave', ['return' => '/admin/settings/leave']);
    assert_true(feature_on('leave') && str_ends_with($r->headers()['Location'] ?? '', '/admin/settings/leave'), 'back on: stays on the section');
    $r = http('POST', '/admin/modules/feature:reimbursements', ['return' => '/admin/settings/payroll']);
    try { assert_true(str_ends_with($r->headers()['Location'] ?? '', '/admin/settings/payroll'), 'payroll section still exists'); $p = (string) http('GET', '/admin/settings/payroll')->body(); assert_true(str_contains($p, 'Reimbursements is off'), 'shows the off feature to turn it back on'); }
    finally { http('POST', '/admin/modules/feature:reimbursements', ['return' => '/admin/settings/payroll']); }
    assert_true(feature_on('reimbursements'));
    $r = http('POST', '/admin/modules/feature:payroll', ['return' => '//evil.example/x']);
    try { assert_true(!str_contains($r->headers()['Location'] ?? '', 'evil'), 'return must be a settings path'); }
    finally { if (!feature_on('payroll')) { http('POST', '/admin/modules/feature:payroll'); } }
    assert_true(feature_on('payroll'));
    assert_true((int) \Core\DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='feature.disable' AND meta LIKE '%feature:leave%'") >= 1, 'audit'); },

  'payroll feature pages link to their settings for Super Admins only' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    assert_true(str_contains((string) http('GET', '/payroll')->body(), 'href="'.url('/admin/settings/payroll').'"'), 'payroll page links to settings');
    assert_true(str_contains((string) http('GET', '/reimbursements?tab=queue')->body(), 'href="'.url('/admin/settings/payroll').'"'), 'claims queue links to settings');
    act_as(make_user('hr', 'hr@settingsfeature.test'));
    assert_true(!str_contains((string) http('GET', '/payroll')->body(), url('/admin/settings/payroll')), 'not for HR'); },
];
