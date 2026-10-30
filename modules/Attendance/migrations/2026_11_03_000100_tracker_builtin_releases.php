<?php
// Desktop tracker downloads now come only from the official GitHub releases (niyanta.json "repository", tags tracker-v*),
// mirrored by the scheduler: remove the old installer upload list, the repository setting and the old uploaded files
// (version folders in storage/desktop without the mirror's manifest.json).
return ['up' => static function (\PDO $pdo): void {
  $pdo->exec("DELETE FROM settings WHERE `key` IN ('tracker.uploads','tracker.github_repo','tracker.github_in_tests')");
  foreach (glob(NY_ROOT.'/storage/desktop/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (is_file($dir.'/manifest.json')) { continue; }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir); } }, 'down' => []];
