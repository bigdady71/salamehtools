<?php
require_once __DIR__ . '/auth.php';

/**
 * Lightweight session-backed flash messaging helpers.
 */
function flash(string $type, string $message, array $options = []): void
{
    if (!isset($_SESSION['flashes']) || !is_array($_SESSION['flashes'])) {
        $_SESSION['flashes'] = [];
    }

    $type = in_array($type, ['success', 'error', 'info', 'warning'], true) ? $type : 'info';
    $_SESSION['flashes'][] = [
        'type' => $type,
        'message' => $message,
        'title' => isset($options['title']) ? (string)$options['title'] : null,
        'lines' => isset($options['lines']) && is_array($options['lines']) ? array_values(array_filter(array_map('strval', $options['lines']))) : [],
        'list' => isset($options['list']) && is_array($options['list']) ? array_values(array_filter(array_map('strval', $options['list']))) : [],
        'dismissible' => !empty($options['dismissible']),
    ];
}

/**
 * Returns and clears queued flash messages for the current request.
 *
 * @return array<int, array{type:string,message:string,title:?string,lines:array<int,string>,list:array<int,string>,dismissible:bool}>
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
