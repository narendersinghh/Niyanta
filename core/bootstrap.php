<?php
declare(strict_types=1);
define('NY_ROOT', dirname(__DIR__));
define('NY_START', microtime(true));
require __DIR__.'/version.php';
spl_autoload_register(static function (string $c): void {
  if (str_starts_with($c, 'Core\\')) { $f = __DIR__.'/'.str_replace('\\', '/', substr($c, 5)).'.php'; }
  elseif (str_starts_with($c, 'Modules\\')) { $f = NY_ROOT.'/modules/'.str_replace('\\', '/', substr($c, 8)).'.php'; }
  else { return; }
  if (is_file($f)) { require $f; } });
require __DIR__.'/helpers.php';
