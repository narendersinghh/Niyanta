<?php
namespace Core;

/** Private file storage outside the web root's reach (storage/ is denied by .htaccess). */
final class Storage {
  public const DOCS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'zip'];
  public const IMAGES = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
  private const MIMES = ['pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'gif' => ['image/gif'], 'svg' => ['image/svg+xml', 'text/xml', 'text/plain'], 'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'], 'zip' => ['application/zip'],
    'doc' => ['application/msword', 'application/octet-stream'], 'xls' => ['application/vnd.ms-excel', 'application/octet-stream'], 'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']];

  /** Never stored whatever a caller allows: anything a web server or browser could execute or render as a page. */
  public const DENIED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'inc', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'swf', 'jar', 'htaccess', 'ini'];

  public static function root(): string { return NY_ROOT.'/storage/uploads'; }
  public static function path(string $rel): string {
    $rel = str_replace(['..', '\\', "\0"], '', $rel);
    return self::root().'/'.ltrim($rel, '/'); }

  /**
   * Re-encode a raster image with GD when available (strips embedded scripts, EXIF and polyglot payloads) and cap its longer
   * side at $max pixels. Returns false when GD is missing or the file is not a decodable image. Animated GIFs lose animation.
   */
  public static function reencodeImage(string $path, int $max = 1024, int $quality = 85): bool {
    if (!function_exists('imagecreatefromstring')) { return false; }
    $raw = @file_get_contents($path);
    $img = $raw !== false && $raw !== '' ? @imagecreatefromstring($raw) : false;
    if (!$img) { return false; }
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, $max / max(1, max($w, $h)));
    $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $out = imagecreatetruecolor($nw, $nh);
    if ($ext !== 'jpg' && $ext !== 'jpeg') { imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127)); }
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = $path.'.tmp';
    $ok = match ($ext) { 'png' => imagepng($out, $tmp, 6), 'gif' => imagegif($out, $tmp), 'webp' => function_exists('imagewebp') ? imagewebp($out, $tmp, $quality) : imagepng($out, $tmp, 6), default => imagejpeg($out, $tmp, $quality) };
    imagedestroy($img); imagedestroy($out);
    if ($ok && is_file($tmp) && filesize($tmp) > 0) { return rename($tmp, $path); }
    @unlink($tmp);
    return false; }

  /** Validate & move an uploaded file. Returns [rel_path, original_name, mime, size]. */
  public static function store(array $file, string $dir, array $allowed = self::DOCS, ?int $maxBytes = null): array {
    $maxBytes ??= (int) Settings::get('storage.max_upload_mb', 10) * 1048576;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') { throw new ValidationException(['file' => 'Upload failed. Please try again.']); }
    if ($file['size'] > $maxBytes) { throw new ValidationException(['file' => 'File is too large (max '.round($maxBytes / 1048576).' MB).']); }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true) || in_array($ext, self::DENIED, true)) { throw new ValidationException(['file' => 'File type not allowed. Allowed: '.implode(', ', array_diff($allowed, self::DENIED))]); }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    if (isset(self::MIMES[$ext]) && !in_array($mime, self::MIMES[$ext], true)) { throw new ValidationException(['file' => 'File content does not match its extension.']); }
    if (in_array($ext, self::IMAGES, true) && !@getimagesize($file['tmp_name'])) { throw new ValidationException(['file' => 'The image could not be read.']); }
    if (in_array($mime, ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/x-httpd-php', 'text/x-php', 'application/x-sh', 'text/x-shellscript'], true) || ($ext === 'txt' || $ext === 'csv') && preg_match('/<\?php|<script\b|<html\b/i', (string) file_get_contents($file['tmp_name'], false, null, 0, 65536))) { throw new ValidationException(['file' => 'This file looks like a script or web page and cannot be uploaded.']); }
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
