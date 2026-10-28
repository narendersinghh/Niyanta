<?php
namespace Core\Controllers;
use Core\{Audit, Auth, Controller, DB, Modules, Response, Storage};

/** Serves private files only after the owning module's access callback approves. */
final class FileController extends Controller {
  private function authorized(int $id, string $mode = 'view'): array {
    $f = DB::find('files', $id) ?? $this->abort(404, 'File not found.');
    $m = Modules::get($f['module']);
    if (!$m || !Modules::enabled($f['module'])) { $this->abort(403, 'This module is disabled.'); }
    $cb = $m['file_access'][$f['entity_type']] ?? null;
    if (!Auth::isSuperAdmin() && (!$cb || !$cb(Auth::user(), (int) $f['entity_id'], $f, $mode))) { Audit::log('file.denied', $f['entity_type'], (int) $f['entity_id'], ['file_id' => $id]); $this->abort(403, 'You cannot access this file.'); }
    return $f; }

  public function download(int $id): Response {
    $f = $this->authorized($id);
    Audit::log('file.download', $f['entity_type'], (int) $f['entity_id'], ['file_id' => $id]);
    return Response::file(Storage::path($f['disk_path']), $f['original_name'], $f['mime']); }

  public function inline(int $id): Response {
    $f = $this->authorized($id);
    $safe = in_array($f['mime'], ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
    return Response::file(Storage::path($f['disk_path']), $f['original_name'], $f['mime'], $safe); }

  public function delete(int $id): Response {
    $this->authorized($id, 'delete');
    Storage::remove($id);
    $this->flash('File deleted.');
    return $this->back(); } }
