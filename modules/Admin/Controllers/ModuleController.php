<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Controller, Modules, Response};

/** Enable/disable modules. Data is never deleted; dependants are warned about, not broken. */
final class ModuleController extends Controller {
  public function index(): Response { return $this->view('admin::modules/index', ['title' => 'Modules', 'modules' => Modules::all()]); }

  public function toggle(string $key): Response {
    $m = Modules::get($key) ?? $this->abort(404, 'Unknown module.');
    if (!empty($m['core'])) { $this->abort(422, 'Core modules cannot be disabled.'); }
    $on = !Modules::enabled($key);
    if ($on) { foreach ($m['requires'] as $req) { if (!Modules::enabled($req)) { $this->flash('Enable '.(Modules::get($req)['name'] ?? $req).' first — '.$m['name'].' depends on it.', 'error'); return $this->back(); } } }
    Modules::setEnabled($key, $on);
    Audit::log($on ? 'module.enable' : 'module.disable', 'module', null, ['module' => $key]);
    $dependants = array_keys(array_filter(Modules::all(), static fn ($x) => in_array($key, $x['requires'], true) && Modules::enabled($x['key'])));
    $this->flash($m['name'].($on ? ' enabled.' : ' disabled. Its data is kept and will return when re-enabled.').($dependants && !$on ? ' Note: '.implode(', ', $dependants).' depends on it and will run with reduced features.' : ''));
    return $this->back(); } }
