<?php
// Router for PHP's built-in server: php -S 127.0.0.1:8080 tests/dev-router.php (from the project root)
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p !== '/' && is_file(__DIR__.'/..'.$p) && str_starts_with($p, '/assets/')) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__.'/../index.php';
