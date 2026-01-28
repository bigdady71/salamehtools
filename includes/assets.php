<?php

declare(strict_types=1);

function asset_url(string $path): string
{
    $normalized = '/' . ltrim($path, '/');
    $root = dirname(__DIR__);
    $fsPath = $root . $normalized;
    if (is_file($fsPath)) {
        return $normalized . '?v=' . filemtime($fsPath);
    }

    return $normalized;
}
