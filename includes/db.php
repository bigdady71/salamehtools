<?php
if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;

        // fix: add "/" after __DIR__
        $config = require __DIR__ . '/../config/db.php';

        $pdo = new PDO($config['dsn'], $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }
}
