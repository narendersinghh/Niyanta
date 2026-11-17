<?php
namespace Core\Controllers;
use Core\{Controller, Modules, Response};

/** Role-aware dashboard composed from enabled modules' widgets. */
final class DashboardController extends Controller {
  public function index(): Response {
    $u = $this->user();
    $widgets = ['kpis' => [], 'main' => [], 'side' => []];
    foreach (Modules::hooks('widgets') as $mod => $list) {
      foreach ($list as $w) {
        if (!empty($w['perm']) && !\Core\Gate::any((array) $w['perm'])) { continue; }
        if (!empty($w['feature']) && !array_filter((array) $w['feature'], 'feature_on')) { continue; }
        try { $html = ($w['render'])($u); }
        catch (\Throwable $e) { error_log("Widget $mod failed: ".$e->getMessage()); continue; }
        if ($html !== '' && $html !== null) { $widgets[$w['zone'] ?? 'main'][] = ['order' => $w['order'] ?? 50, 'html' => $html]; } } }
    foreach ($widgets as &$z) { usort($z, static fn ($a, $b) => $a['order'] <=> $b['order']); }
    $styles = array_values(array_filter(array_map(static fn ($k) => is_file(NY_ROOT."/assets/css/$k.css") ? "css/$k.css" : null, array_keys(Modules::hooks('widgets')))));
    $scripts = array_values(array_filter(array_map(static fn ($k) => is_file(NY_ROOT."/assets/js/$k.js") ? "js/$k.js" : null, array_keys(Modules::hooks('widgets')))));
    /* widgets that also link their own stylesheet or script (for pages without these head assets) would load it a second time */
    $head = array_map('asset', [...$styles, ...$scripts]);
    foreach ($widgets as &$z) { foreach ($z as &$w) { $w['html'] = preg_replace_callback('#<link rel="stylesheet" href="([^"]+)">|<script src="([^"]+)" defer></script>#', static fn ($m) => in_array(html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? '')), $head, true) ? '' : $m[0], (string) $w['html']); } unset($w); }
    unset($z);
    return $this->view('dashboard/index', ['title' => 'Dashboard', 'widgets' => $widgets, 'styles' => $styles, 'scripts' => $scripts]); } }
