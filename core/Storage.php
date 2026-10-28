<?php
namespace Core;

/** Private file storage outside the web root's reach (storage/ is denied by .htaccess). */
final class Storage {
  public const DOCS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'zip'];
  public const IMAGES = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
  private const MIMES = ['pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'gif' => ['image/gif'], 'svg' => ['image/svg+xml', 'text/xml', 'text/plain'], 'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'], 'zip' => ['application/zip'],
    'doc' => ['application/msword', 'application/octet-stream'], 'xls' => ['application/vnd.ms-excel', 'application/octet-stream'], 'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']];

  public static function root(): string { return NY_ROOT.'/storage/uploads'; }
  public static function path(string $rel): string {
    $rel = str_replace(['..', '\\'], '', $rel);
    return self::root().'/'.ltrim($rel, '/'); }

  /** Validate & move an uploaded file. Returns [rel_path, original_name, mime, size]. */
  public static function store(array $file, string $dir, array $allowed = self::DOCS, ?int $maxBytes = null): array {
    $maxBytes ??= (int) Settings::get('storage.max_upload_mb', 10) * 1048576;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') { throw new ValidationException(['file' => 'Upload failed. Please try again.']); }
    if ($file['size'] > $maxBytes) { throw new ValidationException(['file' => 'File is too large (max '.round($maxBytes / 1048576).' MB).']); }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) { throw new ValidationException(['file' => 'File type not allowed. Allowed: '.implode(', ', $allowed)]); }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    if (isset(self::MIMES[$ext]) && !in_array($mime, self::MIMES[$ext], true)) { throw new ValidationException(['file' => 'File content does not match its extension.']); }
    $dir = trim(preg_replace('/[^a-z0-9_\/-]/i', '', $dir), '/').'/'.date('Y/m');
    $abs = self::root().'/'.$dir;
    if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) { throw new \RuntimeException('Cannot create upload directory'); }
    $name = bin2hex(random_bytes(16)).'.'.$ext;
    $ok = PHP_SAPI === 'cli' ? rename($file['tmp_name'], "$abs/$name") : move_uploaded_file($file['tmp_name'], "$abs/$name");
    if (!$ok) { throw new \RuntimeException('Could not save uploaded file'); }
    return ['path' => "$dir/$name", 'original_name' => mb_substr(basename((string) $file['name']), 0, 190), 'mime' => $mime, 'size' => (int) $file['size']]; }

  /** Store an upload and register it in the files table against an entity. */
  public static function attach(array $file, string $module, string $type, int $entityId, string $category = 'general', array $allowed = self::DOCS): int {
    $s = self::store($file, $module, $allowed);
    $id = DB::insert('files', ['module' => $module, 'entity_type' => $type, 'entity_id' => $entityId, 'category' => $category, 'disk_path' => $s['path'], 'original_name' => $s['original_name'], 'mime' => $s['mime'], 'size' => $s['size'], 'uploaded_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('file.upload', $type, $entityId, ['file_id' => $id, 'name' => $s['original_name']]);
    return $id; }

  public static function files(string $type, int $entityId, ?string $category = null): array {
    $sql = 'SELECT f.*, u.name AS uploader FROM files f LEFT JOIN users u ON u.id=f.uploaded_by WHERE f.entity_type=? AND f.entity_id=?';
    $p = [$type, $entityId];
    if ($category) { $sql .= ' AND f.category=?'; $p[] = $category; }
    return DB::all($sql.' ORDER BY f.id DESC', $p); }

  public static function remove(int $fileId): void {
    $f = DB::find('files', $fileId);
    if (!$f) { return; }
    @unlink(self::path($f['disk_path']));
    DB::delete('files', 'id=?', [$fileId]);
    Audit::log('file.delete', $f['entity_type'], (int) $f['entity_id'], ['file_id' => $fileId, 'name' => $f['original_name']]); }

  public static function dirSize(string $dir): int {
    $s = 0;
    if (!is_dir($dir)) { return 0; }
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) { $s += $f->getSize(); }
    return $s; } }
