<?php
require_once __DIR__ . '/auth.php';

/**
 * Lightweight session-backed flash messaging helpers.
 */
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flashes']) || !is_array($_SESSION['flashes'])) {
        $_SESSION['flashes'] = [];
    }

    $type = in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info';
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

/**
 * Returns and clears queued flash messages for the current request.
 *
 * @return array<int, array{type:string,message:string}>
 */
function consume_flashes(): array
{
    if (!isset($_SESSION['flashes']) || !is_array($_SESSION['flashes'])) {
        return [];
    }

    $flashes = $_SESSION['flashes'];
    unset($_SESSION['flashes']);

    return $flashes;
}
