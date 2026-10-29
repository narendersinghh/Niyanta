<?php
namespace Modules\Admin\Controllers;
use Core\{Auth, Controller, HttpException, Request, Response, Session};
use Modules\Admin\Services\DemoData;

/** Demo data (Super Admin only): import a sample agency, delete it again with a typed confirmation. */
final class DemoController extends Controller {
  public function __construct(Request $request) {
    parent::__construct($request);
    if (!Auth::isSuperAdmin()) { throw new HttpException(403, 'Only a Super Admin can manage demo data.'); } }

  public function import(): Response {
    try {
      $c = DemoData::import($this->uid());
      Session::flash('demo_password', DemoData::PASSWORD);
      $this->flash('Demo data imported: '.number_format(array_sum($c)).' records across '.count($c).' tables.'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); }
    catch (\Throwable $e) { error_log('Demo import failed: '.$e); $this->flash('Import failed and was rolled back: '.mb_substr($e->getMessage(), 0, 200), 'error'); }
    return $this->redirect('/admin/settings/demo'); }

  public function delete(): Response {
    if ($this->request->str('confirm') !== 'DELETE') { $this->flash('Type DELETE to confirm removing the demo data.', 'error'); return $this->redirect('/admin/settings/demo'); }
    try { $c = DemoData::delete(); $this->flash($c ? 'Demo data removed: '.number_format(array_sum($c)).' records.' : 'There was no demo data to remove.'); }
    catch (\Throwable $e) { error_log('Demo delete failed: '.$e); $this->flash('Delete failed and was rolled back: '.mb_substr($e->getMessage(), 0, 200), 'error'); }
    return $this->redirect('/admin/settings/demo'); } }
