<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Response};

/** Onboarding / offboarding checklist templates. */
final class ChecklistController extends Controller {
  public function index(): Response {
    $items = ['onboarding' => [], 'offboarding' => []];
    foreach (DB::all('SELECT * FROM checklist_templates ORDER BY type, position, id') as $t) { $items[$t['type']][] = $t; }
    return $this->view('hr::checklists/index', ['title' => 'Checklist templates', 'items' => $items, 'styles' => ['css/hr.css']]); }

  public function store(): Response {
    $d = $this->validate(['type' => 'required|in:onboarding,offboarding', 'title' => 'required|max:190']);
    $d['position'] = (int) DB::value('SELECT COALESCE(MAX(position),0)+1 FROM checklist_templates WHERE type=?', [$d['type']]);
    $id = DB::insert('checklist_templates', $d);
    Audit::log('hr.template.create', 'checklist_template', $id, $d);
    $this->flash('Item added.');
    return $this->redirect('/hr/checklists'); }

  public function move(int $id): Response {
    $t = $this->findOr404('checklist_templates', $id);
    $dir = $this->request->str('dir') === 'up' ? -1 : 1;
    DB::transaction(static function () use ($t, $dir): void {
      $ids = array_map('intval', DB::column('SELECT id FROM checklist_templates WHERE type=? ORDER BY position, id FOR UPDATE', [$t['type']]));
      $i = array_search((int) $t['id'], $ids, true);
      $j = $i + $dir;
      if ($i === false || $j < 0 || $j >= count($ids)) { return; }
      [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
      foreach ($ids as $pos => $tid) { DB::update('checklist_templates', ['position' => $pos + 1], 'id=?', [$tid]); } });
    Audit::log('hr.template.reorder', 'checklist_template', $id, ['dir' => $dir < 0 ? 'up' : 'down']);
    return $this->redirect('/hr/checklists#'.$t['type']); }

  public function destroy(int $id): Response {
    $t = $this->findOr404('checklist_templates', $id);
    DB::delete('checklist_templates', 'id=?', [$id]);
    Audit::log('hr.template.delete', 'checklist_template', $id, ['title' => $t['title'], 'type' => $t['type']]);
    $this->flash('Item removed. Existing employee checklists are not affected.');
    return $this->redirect('/hr/checklists'); } }
