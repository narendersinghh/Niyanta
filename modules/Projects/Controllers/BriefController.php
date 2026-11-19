<?php
namespace Modules\Projects\Controllers;
use Core\{Controller, DB, Response, ValidationException};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectBrief as Brief};

/** Project brief: save from the editor (JSON or form post) and restore a revision. Same rule as editing project details (PA::canManageProject). */
final class BriefController extends Controller {
  private function editable(int $id): void {
    if (!DB::value('SELECT 1 FROM projects WHERE id=?', [$id]) || !PA::canSee($this->user(), $id)) { $this->abort(404, 'Project not found.'); }
    if (!PA::canManageProject($this->user(), $id)) { $this->abort(403, 'You cannot edit this brief.'); } }

  public function save(int $id): Response {
    $this->editable($id);
    $html = $this->request->input('body');
    if (!is_string($html)) { throw new ValidationException(['body' => 'Nothing to save.']); }
    $cur = Brief::get($id);
    $base = $this->request->str('version');
    if ($cur && !$this->request->int('force') && $base !== (string) (int) $cur['version']) {
      $msg = ($cur['updated_name'] ?? 'Someone').' saved this brief '.ago($cur['updated_at']).', after you opened it.';
      if ($this->request->wantsJson()) { return $this->json(['error' => $msg, 'conflict' => true], 409); }
      $this->flash($msg.' Open the brief again to see their changes.', 'error');
      return $this->redirect('/projects/'.$id.'?tab=brief'); }
    try { $r = Brief::save($id, $html, $this->uid()); }
    catch (\LengthException $e) { throw new ValidationException(['body' => $e->getMessage()]); }
    if ($this->request->wantsJson()) {
      $b = Brief::get($id);
      return $this->json(['ok' => true, 'changed' => $r['changed'], 'version' => (int) ($b['version'] ?? 0), 'html' => $r['html'], 'by' => $b['updated_name'] ?? '', 'at' => $b['updated_at'] ?? null, 'ago' => ago($b['updated_at'] ?? null)]); }
    $this->flash($r['changed'] ? 'Brief saved.' : 'No changes to save.');
    return $this->redirect('/projects/'.$id.'?tab=brief'); }

  public function restore(int $id, int $rev_id): Response {
    $this->editable($id);
    $rev = Brief::revision($id, $rev_id) ?? $this->abort(404, 'Version not found.');
    $r = Brief::save($id, $rev['body_html'], $this->uid(), 'restore', (int) $rev['id']);
    $this->flash($r['changed'] ? 'Version from '.fdatetime($rev['created_at']).' restored.' : 'This version is already the current brief.');
    return $this->redirect('/projects/'.$id.'?tab=brief'); } }
