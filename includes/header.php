<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Salameh Tools';
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <?php require_once __DIR__ . '/assets.php'; ?>
    <link rel="stylesheet" href="<?= asset_url('/css/app.css') ?>">
</head>
<body class="theme-light">
<header class="card" role="banner" style="margin:1.5rem auto;max-width:1200px;">
    <h1 style="margin:0;font-size:1.8rem;">Salameh Tools</h1>
</header>
