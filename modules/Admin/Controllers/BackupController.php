<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Auth, Controller, DB, HttpException, Request, Response};
use Modules\Admin\Services\{BackupService, SettingsSchema};

/** Backups (Super Admin only): create, download, delete and restore with typed confirmation. */
final class BackupController extends Controller {
  public function __construct(Request $request) {
    parent::__construct($request);
    if (!Auth::isSuperAdmin()) { throw new HttpException(403, 'Only a Super Admin can manage backups.'); } }

  public function index(): Response {
    $rows = DB::all('SELECT b.*, u.name AS by_name FROM backups b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 100');
    foreach ($rows as &$r) { $r['exists'] = $r['status'] === 'success' && is_file(BackupService::path($r)); }
    unset($r);
    $free = @disk_free_space(BackupService::dir());
    return $this->view('admin::backups/index', ['title' => 'Backups', 'rows' => $rows, 'fields' => SettingsSchema::fields('backups'), 'tabs' => SettingsSchema::tabs(), 'tab' => 'backups', 'zip' => class_exists(\ZipArchive::class), 'free' => $free === false ? null : (int) $free,
      'total' => array_sum(array_map(static fn ($r) => $r['exists'] ? (int) $r['size'] : 0, $rows)), 'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]); }

  public function create(): Response {
    $d = $this->validate(['type' => 'required|in:database,full', 'note' => 'nullable|max:150']);
    if ($d['type'] === 'full' && !class_exists(\ZipArchive::class)) { $this->abort(422, 'The PHP zip extension is required for full backups.'); }
    $b = BackupService::create($d['type'], $d['note'] ?: 'Manual backup', $this->uid());
    $b['status'] === 'success' ? $this->flash('Backup created: '.$b['filename'].' ('.BackupService::size((int) $b['size']).').') : $this->flash('Backup failed: '.$b['note'], 'error');
    return $this->redirect('/admin/backups'); }

  private function find(int $id): array {
    $b = $this->findOr404('backups', $id);
    if ($b['status'] !== 'success' || !is_file(BackupService::path($b))) { $this->abort(404, 'Backup file not found.'); }
    return $b; }

  public function download(int $id): Response {
    $b = $this->find($id);
    Audit::log('backup.download', 'backup', $id, ['file' => $b['filename']]);
    return Response::file(BackupService::path($b), $b['filename'], $b['type'] === 'full' ? 'application/zip' : 'application/gzip'); }

  public function destroy(int $id): Response {
    $b = $this->findOr404('backups', $id);
    if ($b['status'] === 'running' && strtotime($b['created_at']) > time() - 3600) { $this->abort(422, 'This backup is still running.'); }
    BackupService::delete($b);
    Audit::log('backup.delete', 'backup', $id, ['file' => $b['filename']]);
    $this->flash('Backup deleted.');
    return $this->redirect('/admin/backups'); }

  public function restore(int $id): Response {
    $b = $this->find($id);
    if ($this->request->str('confirm') !== 'RESTORE') { $this->flash('Type RESTORE in capitals to confirm the restore.', 'error'); return $this->redirect('/admin/backups'); }
    $r = BackupService::restore($b, $this->uid());
    if ($r['ok']) { $this->flash('Database restored from '.$b['filename'].' ('.$r['statements'].' statements). Safety backup: '.$r['safety_file'].'.'); }
    else { $this->flash('Restore failed: '.mb_substr((string) $r['error'], 0, 250).($r['safety_id'] ? ' A safety backup was kept ('.($r['safety_file'] ?? '').') — restore it if data looks wrong.' : ''), 'error'); }
    return $this->redirect('/admin/backups'); } }
