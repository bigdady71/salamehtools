<?php
return [
  'dsn'  => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=salamaehtools;charset=utf8mb4',
  'user' => getenv('DB_USER') ?: 'root',
  'pass' => getenv('DB_PASS') ?: '',
];
