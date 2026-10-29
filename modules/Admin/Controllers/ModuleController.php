<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Controller, Features, Modules, Response};

/** Bundles & features on/off. Data is never deleted; disabled features are hidden and blocked for everyone. */
final class ModuleController extends Controller {
  public function index(): Response { return $this->view('admin::modules/index', ['title' => 'Features', 'bundles' => Features::bundles(), 'labs' => array_filter(Modules::all(), static fn ($m) => !empty($m['labs']))]); }

  public function toggle(string $key): Response {
    [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');
    if ($kind === 'bundle' && isset(Features::bundles()[$name])) { $on = !Features::bundleEnabled($name); $label = Features::bundles()[$name]['name']; }
    elseif ($kind === 'feature' && ($f = Features::find($name))) { $on = !(int) (\Core\DB::value('SELECT enabled FROM modules WHERE `key`=?', [$key]) ?? 1); $label = $f['name']; }
    elseif ($kind === 'module' && ($m = Modules::get($name)) && !empty($m['labs'])) { $on = !Modules::enabled($name); Modules::setEnabled($name, $on); $label = $m['name']; }
    else { $this->abort(404, 'Unknown feature.'); }
    if ($kind !== 'module') { Features::set($key, $on); }
    Audit::log($on ? 'feature.enable' : 'feature.disable', 'feature', null, ['key' => $key]);
    $this->flash($label.($on ? ' turned on.' : ' turned off. Its data is kept and returns when switched back on.'));
    return $this->back(); } }
