<?php
// Copy to config/config.php only for manual installs. The web installer generates this file automatically.
return [
  'app_key' => 'base64:CHANGE_ME_GENERATE_WITH_php_-r_echo_base64_encode(random_bytes(32));',
  'app_url' => 'https://app.example.com',
  'debug' => false,
  'timezone' => 'Asia/Kolkata',
  'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'niyanta', 'user' => 'niyanta', 'pass' => 'secret'],
  'installed_at' => null,
];
