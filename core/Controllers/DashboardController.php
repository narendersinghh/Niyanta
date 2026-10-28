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
        try { $html = ($w['render'])($u); }
        catch (\Throwable $e) { error_log("Widget $mod failed: ".$e->getMessage()); continue; }
        if ($html !== '' && $html !== null) { $widgets[$w['zone'] ?? 'main'][] = ['order' => $w['order'] ?? 50, 'html' => $html]; } } }
    foreach ($widgets as &$z) { usort($z, static fn ($a, $b) => $a['order'] <=> $b['order']); }
    $styles = array_values(array_filter(array_map(static fn ($k) => is_file(NY_ROOT."/assets/css/$k.css") ? "css/$k.css" : null, array_keys(Modules::hooks('widgets')))));
    $scripts = array_values(array_filter(array_map(static fn ($k) => is_file(NY_ROOT."/assets/js/$k.js") ? "js/$k.js" : null, array_keys(Modules::hooks('widgets')))));
    return $this->view('dashboard/index', ['title' => 'Dashboard', 'widgets' => $widgets, 'styles' => $styles, 'scripts' => $scripts]); } }
